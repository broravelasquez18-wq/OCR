<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Lote
{
    public static function buscarPorFicha(string $codigoFicha): ?array
    {
        $stmt = Database::conn()->prepare('SELECT * FROM lotes_verificacion WHERE TRIM(codigo_ficha) = :ficha ORDER BY id LIMIT 1');
        $stmt->execute(['ficha' => trim($codigoFicha)]);
        return $stmt->fetch() ?: null;
    }

    public static function crear(string $codigoFicha, ?string $programa, ?string $origenLista, ?int $creadoPor): int
    {
        $sql = 'INSERT INTO lotes_verificacion (codigo_ficha, programa, origen_lista, creado_por)
                VALUES (:ficha, :programa, :origen, :creado_por)';
        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([
            'ficha'      => $codigoFicha,
            'programa'   => $programa,
            'origen'     => $origenLista,
            'creado_por' => $creadoPor,
        ]);
        return (int) Database::conn()->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public static function buscar(int $id): ?array
    {
        $stmt = Database::conn()->prepare('SELECT * FROM lotes_verificacion WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public static function listar(): array
    {
        $sql = 'SELECT l.*,
                       (SELECT COUNT(*) FROM inscritos i WHERE i.lote_id = l.id) AS total_inscritos,
                       (SELECT COUNT(*) FROM verificaciones v WHERE v.lote_id = l.id AND v.confirmado = 1) AS total_confirmados
                FROM lotes_verificacion l
                ORDER BY l.creado_en DESC';
        return Database::conn()->query($sql, PDO::FETCH_ASSOC)->fetchAll();
    }

    public static function cambiarEstado(int $id, string $estado): void
    {
        $stmt = Database::conn()->prepare('UPDATE lotes_verificacion SET estado = :estado WHERE id = :id');
        $stmt->execute(['estado' => $estado, 'id' => $id]);
    }

    /**
     * Elimina un lote y, por CASCADE, sus inscritos, documentos y verificaciones.
     * Devuelve las rutas (relativas a STORAGE) de las imagenes asociadas para que
     * el controlador pueda borrarlas del disco.
     *
     * @return list<string>
     */
    public static function eliminar(int $id): array
    {
        $pdo = Database::conn();
        $stmt = $pdo->prepare('SELECT ruta_imagen, ruta_reverso FROM documentos_ocr WHERE lote_id = :id');
        $stmt->execute(['id' => $id]);
        $rutas = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            foreach (['ruta_imagen', 'ruta_reverso'] as $columna) {
                if (!empty($fila[$columna])) {
                    $rutas[] = (string) $fila[$columna];
                }
            }
        }
        $pdo->prepare('DELETE FROM lotes_verificacion WHERE id = :id')->execute(['id' => $id]);
        return $rutas;
    }
}
