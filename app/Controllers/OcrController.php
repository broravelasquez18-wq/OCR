<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\DocumentoOcr;
use App\Models\Lote;
use App\Services\OcrService;
use Throwable;

/**
 * Endpoint AJAX: recibe una imagen, la guarda, corre el OCR y registra el
 * resultado en documentos_ocr. Responde JSON.
 */
final class OcrController extends Controller
{
    /** Tipos MIME de imagen aceptados. */
    private const MIME_OK = ['image/jpeg', 'image/png', 'image/webp', 'image/bmp'];

    public function finalizar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $lote = (int)$this->input('lote_id', '0');
        $actual = Lote::buscar($lote);
        if (!$actual || $actual['estado'] === 'cerrado') {
            $this->json(['ok'=>false,'error'=>'El lote no está disponible para comparar.'],422); return;
        }
        $resumen = (new \App\Services\Comparador())->comparar($lote);
        $this->json(['ok'=>true,'resumen'=>$resumen]);
    }

    public function agruparPdf(): void
    {
        $lote = (int)$this->input('lote_id', '0');
        $ids = json_decode((string)$this->input('documentos', '[]'), true);
        if (!Lote::buscar($lote) || !is_array($ids) || count($ids) > 1000 || array_filter($ids, fn($id)=>!is_int($id))) {
            $this->json(['ok'=>false,'error'=>'Carga PDF inválida.'],422); return;
        }
        $pares = \App\Services\AgrupadorPdf::agrupar($lote, $ids);
        $this->json(['ok'=>true,'pares'=>$pares,'documentos'=>DocumentoOcr::contarPorLote($lote)]);
    }

    public function preparar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        try {
            set_time_limit(30);
            (new OcrService())->preparar();
            $this->json(['ok' => true]);
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'error' => 'El motor se iniciará al procesar el primer documento.']);
        }
    }

    public function procesar(): void
    {
        $inicio = microtime(true);
        // Liberar cualquier sesión abierta antes del trabajo costoso.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        // --- Validar entrada ---
        $loteId = (int) ($this->input('lote_id', '0'));
        $origen = (string) $this->input('origen', 'foto');
        $paginaRaw = $this->input('pagina');
        $pagina = ($paginaRaw !== null && $paginaRaw !== '') ? (int) $paginaRaw : null;

        if (!in_array($origen, ['pdf', 'foto', 'camara'], true)) {
            $this->json(['ok' => false, 'error' => 'origen invalido'], 422);
            return;
        }

        if ($loteId <= 0 || !Lote::buscar($loteId)) {
            $this->json(['ok' => false, 'error' => 'lote inexistente'], 422);
            return;
        }

        $file = $_FILES['imagen'] ?? null;
        $reverso = $_FILES['imagen_reverso'] ?? null;
        $paginaReverso = $this->input('pagina_reverso');
        $paginaReverso = $paginaReverso !== null && $paginaReverso !== '' ? (int) $paginaReverso : null;
        $mimeReverso = null;
        if ($reverso !== null) {
            if (($reverso['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $this->json(['ok' => false, 'error' => 'No se recibió correctamente el reverso. No se guardó la cédula.'], 422);
                return;
            }
            $mimeReverso = (new \finfo(FILEINFO_MIME_TYPE))->file($reverso['tmp_name']);
            if (!in_array($mimeReverso, self::MIME_OK, true)) {
                $this->json(['ok' => false, 'error' => 'El reverso no es una imagen soportada.'], 422);
                return;
            }
        }
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->json(['ok' => false, 'error' => 'no se recibio imagen'], 422);
            return;
        }

        // --- Validar que sea imagen real ---
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (finfo_file($finfo, $file['tmp_name']) ?: '') : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        if (!in_array($mime, self::MIME_OK, true)) {
            $this->json(['ok' => false, 'error' => 'el archivo no es una imagen soportada (' . $mime . ')'], 422);
            return;
        }

        // --- Guardar en storage/uploads (fuera del webroot) ---
        $ext = match ($mime) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/bmp'  => 'bmp',
            default      => 'jpg',
        };
        $nombre = sprintf('lote%d_%s.%s', $loteId, bin2hex(random_bytes(8)), $ext);
        $rutaAbs = STORAGE_PATH . '/uploads/' . $nombre;

        if (!move_uploaded_file($file['tmp_name'], $rutaAbs)) {
            $this->json(['ok' => false, 'error' => 'no se pudo guardar la imagen'], 500);
            return;
        }

        $rutaReverso = null;
        if ($reverso !== null) {
            $extReverso = match ($mimeReverso) {
                'image/png' => 'png', 'image/webp' => 'webp', 'image/bmp' => 'bmp', default => 'jpg',
            };
            $rutaReverso = 'uploads/' . sprintf('lote%d_%s_reverso.%s', $loteId, bin2hex(random_bytes(8)), $extReverso);
            if (!move_uploaded_file($reverso['tmp_name'], STORAGE_PATH . '/' . $rutaReverso)) {
                @unlink($rutaAbs);
                $this->json(['ok' => false, 'error' => 'No se pudo guardar el reverso. No se guardó la cédula.'], 500);
                return;
            }
        }

        $ocrInicio = microtime(true);
        // --- OCR ---
        try {
            set_time_limit(30);
            $ocr = new OcrService($inicio + 25.0);
            $res = $rutaReverso !== null
                ? $ocr->procesarCaras($rutaAbs, STORAGE_PATH . '/' . $rutaReverso)
                : $ocr->procesar($rutaAbs);
        } catch (Throwable $e) {
            $aviso = 'No se pudo asociar la lectura OCR. Revisa las imágenes guardadas: ' . $e->getMessage();
            $res = [
                'numero' => null, 'nombres' => null, 'apellidos' => null,
                'fecha_nacimiento' => null, 'fecha_zona' => null, 'edad' => null,
                'zona' => 'desconocida', 'confianza' => 0,
                'texto_crudo' => '[REVISION OCR] ' . $aviso,
                'error' => $aviso,
            ];
        }

        $ocrFin = microtime(true);
        // --- Persistir en documentos_ocr ---
        // Ruta relativa a storage/uploads (no exponer rutas absolutas).
        $docId = DocumentoOcr::crear($loteId, [
            'origen'           => $origen,
            'ruta_imagen'      => 'uploads/' . $nombre,
            'ruta_reverso'     => $rutaReverso,
            'pagina_reverso'   => $rutaReverso !== null ? $paginaReverso : null,
            'pagina'           => $pagina,
            'numero_leido'     => $res['numero'],
            'nombres'          => $res['nombres'],
            'apellidos'        => $res['apellidos'],
            'zona_fuente'      => $res['zona'],
            'confianza'        => $res['confianza'],
            'fecha_nacimiento' => $res['fecha_nacimiento'],
            'fecha_zona'       => $res['fecha_zona'],
            'texto_crudo'      => $res['texto_crudo'],
        ]);

        $this->json([
            'ok'               => true,
            'documento_id'     => $docId,
            'numero'           => $res['numero'],
            'nombres'          => $res['nombres'],
            'apellidos'        => $res['apellidos'],
            'edad'             => $res['edad'],
            'zona'             => $res['zona'],
            'confianza'        => $res['confianza'],
            'fecha_nacimiento' => $res['fecha_nacimiento'],
            'fecha_zona'       => $res['fecha_zona'],
            'pagina'           => $pagina,
            'ocr_error'        => $res['error'] ?? null,
            'caras'            => $rutaReverso !== null ? 2 : 1,
            'pagina_reverso'   => $rutaReverso !== null ? $paginaReverso : null,
            'duracion_ms'      => (int) round((microtime(true) - $inicio) * 1000),
            'tiempos_ms'       => [
                'preparacion' => (int) round(($ocrInicio - $inicio) * 1000),
                'motor' => (int) round(($ocrFin - $ocrInicio) * 1000),
                'guardado' => (int) round((microtime(true) - $ocrFin) * 1000),
                'detalle_motor' => \App\Services\OcrService::metricas(),
            ],
        ]);
    }
}
