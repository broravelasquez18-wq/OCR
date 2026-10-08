<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Models\DocumentoOcr;
use App\Models\Inscrito;
use App\Models\Lote;
use App\Models\Verificacion;
use App\Services\Comparador;
use App\Services\NumeroExtractor;
use Throwable;

final class VerificacionController extends Controller
{
    public function imagen(string $id, string $cara): void
    {
        $stmt = Database::conn()->prepare('SELECT d.ruta_imagen, d.ruta_reverso FROM verificaciones v JOIN documentos_ocr d ON d.id = v.documento_ocr_id AND d.lote_id = v.lote_id WHERE v.id = ?');
        $stmt->execute([(int) $id]);
        $documento = $stmt->fetch();
        $this->mostrarImagen($documento ?: [], $cara);
    }

    public function imagenDocumento(string $id, string $cara): void
    {
        $stmt = Database::conn()->prepare('SELECT ruta_imagen, ruta_reverso FROM documentos_ocr WHERE id = ?');
        $stmt->execute([(int) $id]);
        $this->mostrarImagen($stmt->fetch() ?: [], $cara);
    }

    private function mostrarImagen(array $documento, string $cara): void
    {
        $relative = in_array($cara, ['principal', 'reverso'], true) ? ($documento[$cara === 'principal' ? 'ruta_imagen' : 'ruta_reverso'] ?? null) : null;
        $root = realpath(STORAGE_PATH . '/uploads');
        $path = $relative ? realpath(STORAGE_PATH . '/' . $relative) : false;
        if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            http_response_code(404);
            return;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/bmp'], true)) {
            http_response_code(415);
            return;
        }
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
    }

    public function asociarImagen(string $id): void
    {
        \App\Core\Auth::start();
        if (empty($_SESSION['asociar_imagen_token']) || !hash_equals($_SESSION['asociar_imagen_token'], $this->input('token', '') ?? '')) {
            $this->json(['ok' => false, 'error' => 'Actualiza la página e intenta nuevamente.'], 403);
            return;
        }
        $pdo = Database::conn();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM documentos_ocr WHERE id = ? FOR UPDATE');
            $stmt->execute([(int) $id]);
            $documento = $stmt->fetch();
            $stmt = $pdo->prepare('SELECT * FROM verificaciones WHERE id = ? FOR UPDATE');
            $stmt->execute([(int) $this->input('verificacion_id', '0')]);
            $fila = $stmt->fetch();
            if (!$documento || !$fila || (int) $documento['lote_id'] !== (int) $fila['lote_id'] || $fila['confirmado'] || $fila['documento_ocr_id'] !== null) {
                throw new \InvalidArgumentException('Selecciona una persona sin imagen, no confirmada y del mismo lote.');
            }
            $stmt = $pdo->prepare('SELECT id FROM verificaciones WHERE documento_ocr_id = ?');
            $stmt->execute([(int) $id]);
            if ($stmt->fetch()) throw new \InvalidArgumentException('La imagen ya fue asociada. Actualiza la página.');
            $stmt = $pdo->prepare('UPDATE verificaciones SET documento_ocr_id = ?, corregido_manual = 1, estado = "corregido" WHERE id = ?');
            $stmt->execute([(int) $id, (int) $fila['id']]);
            $pdo->commit();
            if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
                $this->json(['ok' => true, 'verificacion_id' => (int) $fila['id'], 'estado' => 'corregido']);
                return;
            }
            $this->redirect('/lotes/' . (int) $fila['lote_id'] . '/verificacion');
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $this->json(['ok' => false, 'error' => $error instanceof \InvalidArgumentException ? $error->getMessage() : 'No se pudo asociar la imagen.'], 409);
        }
    }

    public function reabrir(string $id): void
    {
        $ver = Verificacion::buscar((int) $id);
        if (!$ver) {
            $this->json(['ok' => false, 'error' => 'verificación inexistente'], 404);
            return;
        }
        $pdo = Database::conn();
        try {
            $pdo->beginTransaction();
            Verificacion::reabrir((int) $id, (int) $ver['lote_id']);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log((string) $error);
            $this->json(['ok' => false, 'error' => 'No se pudo habilitar la edición. Inténtalo nuevamente.'], 500);
            return;
        }
        $this->json(['ok' => true]);
    }

    /**
     * Regenera el cruce inscritos<->documentos y redirige a la tabla.
     */
    public function comparar(string $id): void
    {
        $loteId = (int) $id;
        if (!Lote::buscar($loteId)) {
            $this->json(['ok' => false, 'error' => 'lote inexistente'], 404);
            return;
        }
        (new Comparador())->comparar($loteId);
        $this->redirect('/lotes/' . $loteId . '/verificacion');
    }

    /**
     * Tabla editable de verificacion.
     */
    public function tabla(string $id): void
    {
        \App\Core\Auth::start();
        $_SESSION['asociar_imagen_token'] ??= bin2hex(random_bytes(32));
        $loteId = (int) $id;
        $lote = Lote::buscar($loteId);
        if (!$lote) {
            http_response_code(404);
            echo 'Lote no encontrado.';
            return;
        }

        // Si aun no hay filas de verificacion, generarlas al vuelo.
        $filas = Verificacion::porLoteDetallado($loteId);
        if (Inscrito::contarPorLote($loteId) > 0) {
            (new Comparador())->comparar($loteId);
            $filas = Verificacion::porLoteDetallado($loteId);
        }

        $this->view('verificacion/tabla', [
            'titulo'         => 'Verificacion - ' . $this->e($lote['codigo_ficha']),
            'lote'           => $lote,
            'filas'          => \App\Services\Conciliador::preparar($filas),
            'sobrantes'      => DocumentoOcr::sobrantes($loteId),
            'resumen'        => Verificacion::resumen($loteId),
            'todasConfirmadas' => Verificacion::todasConfirmadas($loteId),
        ]);
    }

    /**
     * Guarda una edicion manual del numero (AJAX). Recalcula el estado:
     * si el numero editado coincide con el inscrito -> "corregido".
     */
    public function guardarFila(string $id): void
    {
        $verId = (int) $id;
        $ver = Verificacion::buscar($verId);
        if (!$ver) {
            $this->json(['ok' => false, 'error' => 'verificacion inexistente'], 404);
            return;
        }
        if ((int) $ver['confirmado'] === 1) {
            $this->json(['ok' => false, 'error' => 'la fila ya esta confirmada'], 409);
            return;
        }

        try {
            $datos = $this->datosEdicion($ver);
        } catch (\InvalidArgumentException $error) {
            $this->json(['ok' => false, 'error' => $error->getMessage()], 422);
            return;
        }

        Verificacion::actualizarFila(
            $verId,
            $datos['estado'],
            $datos['corregido'],
            $this->input('observacion', $ver['observacion']),
            $ver['documento_ocr_id'] !== null ? (int) $ver['documento_ocr_id'] : null,
            $datos['fecha'],
            $datos['numero'],
            $datos['nombres'],
            $datos['apellidos'],
            $datos['fecha_manual']
        );

        $this->json([
            'ok'      => true,
            'estado'  => $datos['estado'],
            'numero'  => $datos['numero'],
            'fecha'   => $datos['fecha'],
            'edad'    => \App\Services\Edad::calcular($datos['fecha']),
        ]);
    }

    /**
     * Confirma una fila (AJAX): la persiste definitivamente.
     */
    public function confirmar(string $id): void
    {
        $verId = (int) $id;
        $ver = Verificacion::buscar($verId);
        if (!$ver) {
            $this->json(['ok' => false, 'error' => 'verificacion inexistente'], 404);
            return;
        }

        if ((int) $ver['confirmado'] === 1) {
            $this->json(['ok' => false, 'error' => 'la fila ya esta confirmada'], 409);
            return;
        }
        try {
            $datos = $this->datosEdicion($ver);
        } catch (\InvalidArgumentException $error) {
            $this->json(['ok' => false, 'error' => $error->getMessage()], 422);
            return;
        }

        $pdo = Database::conn();
        try {
            $pdo->beginTransaction();
            Verificacion::confirmar($verId, $datos['numero'], $datos['estado'], null, $datos['fecha'], $datos['nombres'], $datos['apellidos'], $datos['fecha_manual'], $datos['corregido']);
            $stmt = $pdo->prepare('UPDATE verificaciones SET observacion = ? WHERE id = ?');
            $stmt->execute([$this->input('observacion', $ver['observacion']), $verId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->json(['ok' => false, 'error' => 'no se pudo confirmar: ' . $e->getMessage()], 500);
            return;
        }

        $this->json([
            'ok'               => true,
            'estado'           => $datos['estado'],
            'todasConfirmadas' => Verificacion::todasConfirmadas((int) $ver['lote_id']),
        ]);
    }

    /**
     * Cierra el lote: solo si TODAS las filas estan confirmadas.
     */
    public function cerrar(string $id): void
    {
        $loteId = (int) $id;
        $lote = Lote::buscar($loteId);
        if (!$lote) {
            $this->json(['ok' => false, 'error' => 'lote inexistente'], 404);
            return;
        }
        if (!Verificacion::todasConfirmadas($loteId)) {
            $this->json(['ok' => false, 'error' => 'faltan filas por confirmar'], 409);
            return;
        }

        Lote::cambiarEstado($loteId, 'cerrado');
        $this->json(['ok' => true, 'estado' => 'cerrado']);
    }

    /**
     * Valida y normaliza una fecha 'YYYY-MM-DD' (la que envia <input type="date">).
     * Devuelve la fecha ISO si es una fecha real, o null si viene vacia/invalida.
     */
    private function datosEdicion(array $ver): array
    {
        $numero = NumeroExtractor::normalizar($this->input('numero', $ver['numero_confirmado'] ?? $ver['numero_leido'] ?? ''));
        if (strlen($numero) > 20) {
            throw new \InvalidArgumentException('La identificación admite hasta 20 dígitos.');
        }
        $fechaActual = (int) $ver['fecha_manual'] === 1 ? $ver['fecha_nacimiento'] : ($ver['fecha_nacimiento'] ?? $ver['fecha_ocr']);
        $fecha = $this->fechaValida($this->input('fecha', $fechaActual ?? ''));
        $nombres = $this->input('nombres', $ver['nombres_manual'] ?? $ver['nombres_ocr'] ?? '');
        $apellidos = $this->input('apellidos', $ver['apellidos_manual'] ?? $ver['apellidos_ocr'] ?? '');
        foreach ([$nombres, $apellidos] as $valor) {
            if (mb_strlen((string) $valor, 'UTF-8') > 150) {
                throw new \InvalidArgumentException('Nombres y apellidos admiten hasta 150 caracteres cada uno.');
            }
        }
        $corregido = $numero !== (string) ($ver['numero_leido'] ?? '');
        $estado = $numero === '' ? 'falta_documento' : ($numero === $ver['numero_lista'] ? ($corregido ? 'corregido' : 'ok') : 'no_coincide');
        return [
            'numero' => $numero,
            'fecha' => $fecha,
            'fecha_manual' => $fecha !== ($ver['fecha_ocr'] ?? null),
            'nombres' => $nombres === ($ver['nombres_ocr'] ?? null) ? null : $nombres,
            'apellidos' => $apellidos === ($ver['apellidos_ocr'] ?? null) ? null : $apellidos,
            'estado' => $estado,
            'corregido' => $corregido,
        ];
    }

    private function fechaValida(mixed $valor): ?string
    {
        $s = trim((string) ($valor ?? ''));
        if ($s === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        // Rechaza fechas imposibles (p.ej. 2024-02-31 se "corregiria" solo).
        if ($d === false || $d->format('Y-m-d') !== $s || \App\Services\Edad::calcular($s) === null) {
            throw new \InvalidArgumentException('La fecha de nacimiento debe ser válida y no puede ser futura.');
        }
        return $s;
    }

    /**
     * Informe resumen del lote.
     */
    public function informe(string $id): void
    {
        $loteId = (int) $id;
        $lote = Lote::buscar($loteId);
        if (!$lote) {
            http_response_code(404);
            echo 'Lote no encontrado.';
            return;
        }

        $this->view('verificacion/informe', [
            'titulo'    => 'Informe - ' . $this->e($lote['codigo_ficha']),
            'lote'      => $lote,
            'resumen'   => Verificacion::resumen($loteId),
            'filas'     => Verificacion::porLoteDetallado($loteId),
            'sobrantes' => DocumentoOcr::sobrantes($loteId),
        ]);
    }
}
