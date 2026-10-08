<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\DocumentoOcr;
use App\Models\Inscrito;
use App\Models\Lote;
use App\Services\ImportadorXls;
use RuntimeException;

final class LoteController extends Controller
{
    public function index(): void
    {
        $this->view('lote/index', [
            'titulo' => 'Lotes de verificacion',
            'lotes'  => Lote::listar(),
        ]);
    }

    public function formSubirLista(): void
    {
        $this->view('lote/subir_lista', [
            'titulo' => 'Importar y comparar',
            'error'  => null,
        ]);
    }

    public function importar(): void
    {
        $file = $_FILES['lista'] ?? null;

        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->errorImportacion('Selecciona un archivo .xls valido.');
            return;
        }

        $nombreOriginal = (string) ($file['name'] ?? 'lista.xls');
        $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xls', 'xlsx'], true)) {
            $this->errorImportacion('El archivo debe ser .xls (reporte del SENA).');
            return;
        }

        // Mover a un temporal dentro de storage para procesarlo.
        $tmpDest = STORAGE_PATH . '/uploads/lista_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $tmpDest)) {
            $this->errorImportacion('No se pudo guardar el archivo subido.');
            return;
        }

        try {
            $importador = new ImportadorXls();
            $res = $importador->importar($tmpDest, $nombreOriginal, null);
        } catch (RuntimeException $e) {
            @unlink($tmpDest);
            $this->errorImportacion('No se pudo importar: ' . $e->getMessage());
            return;
        }

        // La lista ya esta en la BD; no necesitamos conservar el .xls.
        @unlink($tmpDest);

        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            $this->json(['ok' => true, 'lote_id' => (int) $res['lote_id']]);
            return;
        }
        $this->redirect('/lotes/' . $res['lote_id']);
    }

    private function errorImportacion(string $mensaje): void
    {
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            $this->json(['ok' => false, 'error' => $mensaje], 422);
            return;
        }
        $this->view('lote/subir_lista', ['titulo' => 'Importar y comparar', 'error' => $mensaje]);
    }

    public function ver(string $id): void
    {
        $loteId = (int) $id;
        $lote = Lote::buscar($loteId);
        if (!$lote) {
            http_response_code(404);
            echo 'Lote no encontrado.';
            return;
        }

        $this->view('lote/ver', [
            'titulo'         => 'Lote ' . $this->e($lote['codigo_ficha']),
            'lote'           => $lote,
            'inscritos'      => Inscrito::porLote($loteId),
            'totalDocumentos'=> DocumentoOcr::contarPorLote($loteId),
        ]);
    }

    public function eliminar(string $id): void
    {
        $loteId = (int) $id;
        $lote = Lote::buscar($loteId);
        if (!$lote) {
            http_response_code(404);
            echo 'Lote no encontrado.';
            return;
        }

        $rutas = Lote::eliminar($loteId);

        // Borrar las imagenes asociadas, solo si viven dentro de storage/uploads.
        $root = realpath(STORAGE_PATH . '/uploads');
        foreach ($rutas as $relativa) {
            $path = realpath(STORAGE_PATH . '/' . $relativa);
            if ($root && $path && str_starts_with($path, $root) && is_file($path)) {
                @unlink($path);
            }
        }

        $this->redirect('/lotes');
    }

    public function formSubirDocumentos(string $id): void
    {
        $loteId = (int) $id;
        $lote = Lote::buscar($loteId);
        if (!$lote) {
            http_response_code(404);
            echo 'Lote no encontrado.';
            return;
        }

        $this->view('lote/subir_documentos', [
            'titulo'          => 'Subir documentos - ' . $this->e($lote['codigo_ficha']),
            'lote'            => $lote,
            'totalInscritos'  => Inscrito::contarPorLote($loteId),
            'totalDocumentos' => DocumentoOcr::contarPorLote($loteId),
        ]);
    }
}
