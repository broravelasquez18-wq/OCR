<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inscrito;
use App\Models\Lote;
use App\Core\Database;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
use RuntimeException;

/**
 * Lee el reporte de inscripcion del SENA (.xls viejo, BIFF) y crea el lote
 * con sus inscritos normalizados.
 *
 * Estructura tipica del archivo:
 *   - Filas de cabecera sueltas: "Codigo Ficha: 1234567", "Programa de Formacion: ..."
 *   - Una fila de encabezados de tabla: Identificacion | Nombre | Estado
 *   - Debajo, una fila por inscrito, con Identificacion tipo "CC - 1110487315".
 */
final class ImportadorXls
{
    /**
     * Lee y estructura el .xls SIN tocar la base de datos (testeable en aislamiento).
     *
     * @return array{codigo_ficha:string, programa:?string, inscritos:list<array{tipo:string,numero:string,nombre:string,estado:?string}>}
     */
    public function parsear(string $rutaArchivo): array
    {
        if (!is_file($rutaArchivo)) {
            throw new RuntimeException('El archivo no existe: ' . $rutaArchivo);
        }

        // Forzar el lector Xls (BIFF), no Xlsx.
        $reader = new XlsReader();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($rutaArchivo);
        $sheet = $spreadsheet->getActiveSheet();

        // Matriz completa como array indexado desde 1 (filas y columnas).
        $rows = $sheet->toArray(null, true, true, true);
        if (empty($rows)) {
            throw new RuntimeException('El archivo esta vacio.');
        }

        $codigoFicha = $this->buscarValor($rows, ['codigo ficha', 'codigo de ficha', 'ficha', 'no. ficha']);
        $programa    = $this->buscarValor($rows, ['programa de formacion', 'programa']);

        // Detectar la fila de encabezados de la tabla (celda que empieza por "Identificaci").
        $headerRowIndex = null;
        $colIdent = $colNombre = $colEstado = null;

        foreach ($rows as $rowIndex => $cols) {
            foreach ($cols as $colLetter => $value) {
                $norm = $this->norm((string) $value);
                // El SENA titula la columna "Identificacion" (a veces "Tipo y Numero
                // de Identificacion"); basta con que la celda contenga "identificaci".
                if (str_contains($norm, 'identificaci')) {
                    $headerRowIndex = $rowIndex;
                    $colIdent = $colLetter;
                }
            }
            if ($headerRowIndex === $rowIndex) {
                // En la misma fila localizar Nombre y Estado.
                foreach ($cols as $colLetter => $value) {
                    $norm = $this->norm((string) $value);
                    if ($colNombre === null && str_contains($norm, 'nombre')) {
                        $colNombre = $colLetter;
                    }
                    if ($colEstado === null && str_contains($norm, 'estado')) {
                        $colEstado = $colLetter;
                    }
                }
                break;
            }
        }

        if ($headerRowIndex === null || $colIdent === null) {
            throw new RuntimeException(
                'No se encontro la fila de encabezados (no hay celda que empiece por "Identificaci").'
            );
        }

        if ($codigoFicha === null || $codigoFicha === '') {
            // Fallback: no romper la importacion si no aparecio la ficha en cabecera.
            $codigoFicha = 'SIN-FICHA';
        }

        // Recorrer inscritos debajo del encabezado.
        $inscritos = [];
        foreach ($rows as $rowIndex => $cols) {
            if ($rowIndex <= $headerRowIndex) {
                continue;
            }

            $rawIdent = trim((string) ($cols[$colIdent] ?? ''));
            $nombre   = $colNombre !== null ? trim((string) ($cols[$colNombre] ?? '')) : '';
            $estado   = $colEstado !== null ? trim((string) ($cols[$colEstado] ?? '')) : null;

            // Saltar filas vacias o de relleno/subtotales.
            if ($rawIdent === '' && $nombre === '') {
                continue;
            }

            $sep = NumeroExtractor::separarTipoYNumero($rawIdent);
            // Los documentos colombianos tienen 6+ digitos; asi descartamos pies de
            // pagina y subtotales tipo "Total: 3 aprendices" que dejarian numero "3".
            if (strlen($sep['numero']) < 5) {
                continue;
            }

            $inscritos[] = [
                'tipo'   => $sep['tipo'],
                'numero' => $sep['numero'],
                'nombre' => $nombre !== '' ? $nombre : '(sin nombre)',
                'estado' => $estado ?: null,
            ];
        }

        return [
            'codigo_ficha' => $codigoFicha,
            'programa'     => $programa,
            'inscritos'    => $inscritos,
        ];
    }

    /**
     * Parsea y persiste: crea el lote e inserta los inscritos.
     *
     * @return array{lote_id:int, insertados:int, duplicados:int, total_filas:int, codigo_ficha:string, programa:?string}
     */
    public function importar(string $rutaArchivo, ?string $origenLista, ?int $creadoPor): array
    {
        $datos = $this->parsear($rutaArchivo);
        $datos['codigo_ficha'] = trim($datos['codigo_ficha']);
        if ($datos['codigo_ficha'] === '' || $datos['codigo_ficha'] === 'SIN-FICHA') {
            throw new RuntimeException('No se encontró el código de ficha. Revisa el encabezado del reporte antes de importar.');
        }
        if (mb_strlen($datos['codigo_ficha']) > 20) {
            throw new RuntimeException('El código de ficha supera los 20 caracteres permitidos.');
        }
        $pdo = Database::conn();
        $lock = 'ocr_import_' . substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
        $stmt = $pdo->prepare('SELECT GET_LOCK(:nombre, 10)');
        $stmt->execute(['nombre' => $lock]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Hay otra importación en proceso. Espera unos segundos y vuelve a intentar.');
        }
        $insertados = 0;
        $duplicados = 0;
        try {
            $pdo->beginTransaction();
            $existente = Lote::buscarPorFicha($datos['codigo_ficha']);
            if ($existente) {
                throw new RuntimeException('La ficha ' . $datos['codigo_ficha'] . ' ya tiene el lote #' . $existente['id'] . '. No se creó otro lote. Abre el existente desde Mis lotes.');
            }
            $loteId = Lote::crear($datos['codigo_ficha'], $datos['programa'], $origenLista, $creadoPor);
            foreach ($datos['inscritos'] as $ins) {
                $ok = Inscrito::crear($loteId, $ins['tipo'], $ins['numero'], $ins['nombre'], $ins['estado']);
                $ok ? $insertados++ : $duplicados++;
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        } finally {
            $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:nombre)');
            $stmt->execute(['nombre' => $lock]);
        }

        return [
            'lote_id'      => $loteId,
            'insertados'   => $insertados,
            'duplicados'   => $duplicados,
            'total_filas'  => count($datos['inscritos']),
            'codigo_ficha' => $datos['codigo_ficha'],
            'programa'     => $datos['programa'],
        ];
    }

    /**
     * Busca en cualquier celda un valor de cabecera tipo "Etiqueta: valor" o
     * "Etiqueta" seguido del valor en la celda de al lado.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param list<string>                    $etiquetas
     */
    private function buscarValor(array $rows, array $etiquetas): ?string
    {
        foreach ($rows as $cols) {
            $colLetters = array_keys($cols);
            foreach ($cols as $i => $value) {
                $texto = trim((string) $value);
                if ($texto === '') {
                    continue;
                }
                $norm = $this->norm($texto);

                foreach ($etiquetas as $etq) {
                    if (str_contains($norm, $etq)) {
                        // Caso "Etiqueta: valor" en la misma celda.
                        if (str_contains($texto, ':')) {
                            $parte = trim((string) substr($texto, (int) strpos($texto, ':') + 1));
                            if ($parte !== '') {
                                return $parte;
                            }
                        }
                        // Caso valor en la celda contigua a la derecha.
                        $pos = array_search($i, $colLetters, true);
                        if ($pos !== false && isset($colLetters[$pos + 1])) {
                            $sig = trim((string) ($cols[$colLetters[$pos + 1]] ?? ''));
                            if ($sig !== '') {
                                return $sig;
                            }
                        }
                    }
                }
            }
        }
        return null;
    }

    /**
     * Normaliza texto para comparar: minusculas, sin acentos, sin espacios extra.
     */
    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u'];
        $s = strtr($s, $map);
        return preg_replace('/\s+/', ' ', $s) ?? $s;
    }
}
