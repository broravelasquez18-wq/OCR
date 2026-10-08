<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class DocumentoOcr
{
    /**
     * @param array{origen:string, ruta_imagen:string, pagina:?int, numero_leido:?string,
     *              zona_fuente:?string, confianza:?float, fecha_nacimiento?:?string,
     *              fecha_zona?:?string, texto_crudo:?string} $data
     */
    public static function crear(int $loteId, array $data): int
    {
        $sql = 'INSERT INTO documentos_ocr
                    (lote_id, origen, ruta_imagen, pagina, numero_leido, zona_fuente, confianza,
                     fecha_nacimiento, fecha_zona, texto_crudo, nombres, apellidos, ruta_reverso, pagina_reverso)
                VALUES
                    (:lote, :origen, :ruta, :pagina, :numero, :zona, :confianza,
                     :fnac, :fzona, :texto, :nombres, :apellidos, :reverso, :pagina_reverso)';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([
            'lote'      => $loteId,
            'origen'    => $data['origen'],
            'ruta'      => $data['ruta_imagen'],
            'reverso'   => $data['ruta_reverso'] ?? null,
            'pagina_reverso' => $data['pagina_reverso'] ?? null,
            'pagina'    => $data['pagina'],
            'numero'    => $data['numero_leido'],
            'nombres'   => $data['nombres'] ?? null,
            'apellidos' => $data['apellidos'] ?? null,
            'zona'      => $data['zona_fuente'],
            'confianza' => $data['confianza'],
            'fnac'      => $data['fecha_nacimiento'] ?? null,
            'fzona'     => $data['fecha_zona'] ?? null,
            'texto'     => $data['texto_crudo'],
        ]);
        return (int) Database::conn()->lastInsertId();
    }

    /**
     * Actualiza los campos de lectura OCR de un documento ya existente
     * (reprocesamiento). No toca rutas, origen ni paginas.
     */
    public static function actualizarLectura(int $id, array $data): void
    {
        $sql = 'UPDATE documentos_ocr SET
                    numero_leido = :numero, zona_fuente = :zona, confianza = :confianza,
                    nombres = :nombres, apellidos = :apellidos, nombre_completo = NULL,
                    fecha_nacimiento = :fnac, fecha_zona = :fzona, texto_crudo = :texto
                WHERE id = :id';
        Database::conn()->prepare($sql)->execute([
            'numero'    => $data['numero_leido'],
            'zona'      => $data['zona_fuente'],
            'confianza' => $data['confianza'],
            'nombres'   => $data['nombres'] ?? null,
            'apellidos' => $data['apellidos'] ?? null,
            'fnac'      => $data['fecha_nacimiento'] ?? null,
            'fzona'     => $data['fecha_zona'] ?? null,
            'texto'     => $data['texto_crudo'],
            'id'        => $id,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function porLote(int $loteId): array
    {
        $stmt = Database::conn()->prepare(
            'SELECT d.* FROM documentos_ocr d WHERE d.lote_id = :lote AND NOT EXISTS (SELECT 1 FROM documentos_ocr p WHERE p.lote_id=d.lote_id AND p.ruta_reverso=d.ruta_imagen AND p.id<>d.id) ORDER BY d.procesado_en'
        );
        $stmt->execute(['lote' => $loteId]);
        return $stmt->fetchAll();
    }

    public static function contarPorLote(int $loteId): int
    {
        $stmt = Database::conn()->prepare('SELECT COUNT(*) FROM documentos_ocr d WHERE d.lote_id = :lote AND NOT EXISTS (SELECT 1 FROM documentos_ocr p WHERE p.lote_id=d.lote_id AND p.ruta_reverso=d.ruta_imagen AND p.id<>d.id)');
        $stmt->execute(['lote' => $loteId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Documentos "sobrantes": numero leido que no esta en la lista de inscritos.
     *
     * @return list<array<string,mixed>>
     */
    public static function sobrantes(int $loteId): array
    {
        $sql = 'SELECT d.* FROM documentos_ocr d
                WHERE d.lote_id = :lote AND NOT EXISTS (SELECT 1 FROM documentos_ocr p WHERE p.lote_id=d.lote_id AND p.ruta_reverso=d.ruta_imagen AND p.id<>d.id) AND NOT EXISTS (
                    SELECT 1 FROM verificaciones v WHERE v.documento_ocr_id = d.id
                ) ORDER BY d.pagina, d.id';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute(['lote' => $loteId]);
        return $stmt->fetchAll();
    }
}
