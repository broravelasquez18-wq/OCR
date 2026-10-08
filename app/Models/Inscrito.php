<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Inscrito
{
    /**
     * Inserta un inscrito. Ignora duplicados por (lote_id, numero_documento).
     *
     * @return bool true si inserto, false si ya existia
     */
    public static function crear(
        int $loteId,
        string $tipoDocumento,
        string $numeroDocumento,
        string $nombre,
        ?string $estadoSena
    ): bool {
        $sql = 'INSERT IGNORE INTO inscritos (lote_id, tipo_documento, numero_documento, nombre, estado_sena)
                VALUES (:lote, :tipo, :numero, :nombre, :estado)';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([
            'lote'   => $loteId,
            'tipo'   => $tipoDocumento,
            'numero' => $numeroDocumento,
            'nombre' => $nombre,
            'estado' => $estadoSena,
        ]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<array<string,mixed>> */
    public static function porLote(int $loteId): array
    {
        $stmt = Database::conn()->prepare(
            "SELECT i.*, EXISTS(SELECT 1 FROM autocompletados a WHERE a.destino = 'inscrito' AND a.destino_id = i.id AND a.lote_id = i.lote_id) AS nombre_autocompletado FROM inscritos i WHERE i.lote_id = :lote ORDER BY i.nombre"
        );
        $stmt->execute(['lote' => $loteId]);
        return $stmt->fetchAll();
    }

    public static function contarPorLote(int $loteId): int
    {
        $stmt = Database::conn()->prepare('SELECT COUNT(*) FROM inscritos WHERE lote_id = :lote');
        $stmt->execute(['lote' => $loteId]);
        return (int) $stmt->fetchColumn();
    }
}
