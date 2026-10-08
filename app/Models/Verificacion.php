<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Verificacion
{
    public static function reabrir(int $id, int $loteId): void
    {
        $pdo = Database::conn();
        $stmt = $pdo->prepare('UPDATE lotes_verificacion SET estado = "en_proceso" WHERE id = :lote');
        $stmt->execute(['lote' => $loteId]);
        $stmt = $pdo->prepare('UPDATE verificaciones SET confirmado = 0, verificado_por = NULL, verificado_en = NULL WHERE id = :id AND lote_id = :lote');
        $stmt->execute(['id' => $id, 'lote' => $loteId]);
    }

    /**
     * Regenera las filas de verificacion de un lote (upsert por inscrito).
     * No pisa filas ya confirmadas manualmente.
     *
     * @param list<array{inscrito_id:int, documento_ocr_id:?int, estado:string}> $filas
     */
    public static function upsertLote(int $loteId, array $filas): void
    {
        $sql = 'INSERT INTO verificaciones (lote_id, inscrito_id, documento_ocr_id, estado)
                VALUES (:lote, :inscrito, :doc, :estado)
                ON DUPLICATE KEY UPDATE
                    documento_ocr_id = IF(confirmado = 1 OR corregido_manual = 1 OR numero_confirmado IS NOT NULL, documento_ocr_id, VALUES(documento_ocr_id)),
                    estado           = IF(confirmado = 1 OR corregido_manual = 1 OR numero_confirmado IS NOT NULL, estado, VALUES(estado))';
        $stmt = Database::conn()->prepare($sql);
        foreach ($filas as $f) {
            $stmt->execute([
                'lote'     => $loteId,
                'inscrito' => $f['inscrito_id'],
                'doc'      => $f['documento_ocr_id'],
                'estado'   => $f['estado'],
            ]);
        }
    }

    /**
     * Filas de verificacion con datos del inscrito y del documento, para la tabla editable.
     *
     * @return list<array<string,mixed>>
     */
    public static function porLoteDetallado(int $loteId): array
    {
        $sql = 'SELECT
                    v.id, v.inscrito_id, v.documento_ocr_id, v.numero_confirmado,
                    v.nombres_manual, v.apellidos_manual,
                    v.estado, v.corregido_manual, v.observacion, v.confirmado,
                    v.verificado_en, v.fecha_nacimiento AS fecha_confirmada, v.fecha_manual,
                    i.nombre, i.numero_documento, i.tipo_documento,
                    d.numero_leido, d.nombres AS nombres_ocr, d.apellidos AS apellidos_ocr, d.nombre_completo,
                    EXISTS(SELECT 1 FROM autocompletados a WHERE a.destino = "inscrito" AND a.destino_id = i.id AND a.lote_id = i.lote_id) AS nombre_autocompletado,
                    d.zona_fuente, d.confianza, d.ruta_imagen, d.ruta_reverso, d.pagina, d.pagina_reverso, d.origen,
                    d.fecha_nacimiento, d.fecha_zona, d.texto_crudo
                FROM verificaciones v
                JOIN inscritos i      ON i.id = v.inscrito_id
                LEFT JOIN documentos_ocr d ON d.id = v.documento_ocr_id
                WHERE v.lote_id = :lote
                ORDER BY
                    FIELD(v.estado, "no_coincide", "falta_documento", "corregido", "ok"),
                    i.nombre';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute(['lote' => $loteId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function buscar(int $id): ?array
    {
        $stmt = Database::conn()->prepare('SELECT v.*, i.numero_documento AS numero_lista,
            d.numero_leido, d.fecha_nacimiento AS fecha_ocr, d.nombres AS nombres_ocr, d.apellidos AS apellidos_ocr
            FROM verificaciones v JOIN inscritos i ON i.id = v.inscrito_id
            LEFT JOIN documentos_ocr d ON d.id = v.documento_ocr_id WHERE v.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Guarda una edicion manual del numero (sin confirmar todavia).
     */
    public static function actualizarFila(
        int $id,
        string $estado,
        bool $corregidoManual,
        ?string $observacion,
        ?int $documentoOcrId,
        ?string $fechaNacimiento = null,
        ?string $numero = null,
        ?string $nombres = null,
        ?string $apellidos = null,
        bool $fechaManual = true
    ): void {
        $sql = 'UPDATE verificaciones
                SET estado = :estado,
                    corregido_manual = :corr,
                    observacion = :obs,
                    documento_ocr_id = :doc,
                    fecha_nacimiento = :fecha,
                    fecha_manual = :fecha_manual,
                    numero_confirmado = :numero,
                    nombres_manual = :nombres,
                    apellidos_manual = :apellidos
                WHERE id = :id AND confirmado = 0';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([
            'estado'       => $estado,
            'corr'         => $corregidoManual ? 1 : 0,
            'obs'          => $observacion,
            'doc'          => $documentoOcrId,
            'fecha'        => $fechaNacimiento,
            'fecha_manual' => $fechaManual ? 1 : 0,
            'numero'       => $numero,
            'nombres'      => $nombres,
            'apellidos'    => $apellidos,
            'id'           => $id,
        ]);
    }

    /**
     * Confirma la fila: fija numero_confirmado, confirmado=1, auditoria.
     */
    public static function confirmar(
        int $id,
        string $numeroConfirmado,
        string $estado,
        ?int $verificadoPor,
        ?string $fechaNacimiento = null,
        ?string $nombres = null,
        ?string $apellidos = null,
        bool $fechaManual = true,
        bool $corregidoManual = false
    ): void {
        $sql = 'UPDATE verificaciones
                SET numero_confirmado = :num,
                    estado = :estado,
                    fecha_nacimiento = :fecha,
                    fecha_manual = :fecha_manual,
                    nombres_manual = :nombres,
                    apellidos_manual = :apellidos,
                    corregido_manual = :corregido,
                    confirmado = 1,
                    verificado_por = :por,
                    verificado_en = NOW()
                WHERE id = :id AND confirmado = 0';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([
            'num'          => $numeroConfirmado,
            'estado'       => $estado,
            'fecha'        => $fechaNacimiento,
            'fecha_manual' => $fechaManual ? 1 : 0,
            'nombres'      => $nombres,
            'apellidos'    => $apellidos,
            'corregido'    => $corregidoManual ? 1 : 0,
            'por'          => $verificadoPor,
            'id'           => $id,
        ]);
    }

    /**
     * @return array{total:int, confirmados:int, ok:int, corregido:int, no_coincide:int, falta_documento:int}
     */
    public static function resumen(int $loteId): array
    {
        $sql = 'SELECT
                    COUNT(*) AS total,
                    SUM(confirmado = 1) AS confirmados,
                    SUM(estado = "ok") AS ok,
                    SUM(estado = "corregido") AS corregido,
                    SUM(estado = "no_coincide") AS no_coincide,
                    SUM(estado = "falta_documento") AS falta_documento
                FROM verificaciones WHERE lote_id = :lote';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute(['lote' => $loteId]);
        $r = $stmt->fetch() ?: [];
        return [
            'total'           => (int) ($r['total'] ?? 0),
            'confirmados'     => (int) ($r['confirmados'] ?? 0),
            'ok'              => (int) ($r['ok'] ?? 0),
            'corregido'       => (int) ($r['corregido'] ?? 0),
            'no_coincide'     => (int) ($r['no_coincide'] ?? 0),
            'falta_documento' => (int) ($r['falta_documento'] ?? 0),
        ];
    }

    /**
     * True si todas las filas del lote estan confirmadas (y hay al menos una).
     */
    public static function todasConfirmadas(int $loteId): bool
    {
        $stmt = Database::conn()->prepare(
            'SELECT COUNT(*) AS total, SUM(confirmado = 1) AS conf
             FROM verificaciones WHERE lote_id = :lote'
        );
        $stmt->execute(['lote' => $loteId]);
        $r = $stmt->fetch();
        $total = (int) ($r['total'] ?? 0);
        $conf = (int) ($r['conf'] ?? 0);
        return $total > 0 && $total === $conf;
    }
}
