<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DocumentoOcr;
use App\Models\Inscrito;
use App\Models\Verificacion;

/**
 * Cruza los inscritos de un lote contra los documentos leidos por OCR.
 * El cruce es por numero normalizado, NUNCA por orden.
 *
 * Estados:
 *   ok              -> hay un documento cuyo numero_leido == inscrito.numero_documento
 *   no_coincide     -> hay un documento emparejado tentativamente (numero cercano)
 *                      pero el numero no cuadra
 *   falta_documento -> no hay ningun documento para ese inscrito
 */
final class Comparador
{
    /** Distancia de edicion maxima para considerar un documento "cercano". */
    private const MAX_DISTANCIA = 2;

    /**
     * Regenera las filas de verificacion del lote. No pisa filas ya confirmadas.
     *
     * @return array{ok:int, no_coincide:int, falta_documento:int, total:int}
     */
    public function comparar(int $loteId): array
    {
        Autocompletador::completar($loteId);
        $inscritos = Inscrito::porLote($loteId);
        $documentos = DocumentoOcr::porLote($loteId);
        $filas = $this->resolver($inscritos, $documentos, Verificacion::porLoteDetallado($loteId));
        Verificacion::upsertLote($loteId, $filas);
        $resumen = Verificacion::resumen($loteId);
        return ['ok' => $resumen['ok'], 'no_coincide' => $resumen['no_coincide'], 'falta_documento' => $resumen['falta_documento'], 'total' => $resumen['total']];
    }

    public function resolver(array $inscritos, array $documentos, array $existentes = []): array
    {
        $usados = [];
        $asignados = [];
        foreach ($existentes as $fila) {
            if ($fila['confirmado'] || $fila['corregido_manual'] || $fila['numero_confirmado'] !== null) {
                $asignados[(int) $fila['inscrito_id']] = ['inscrito_id' => (int) $fila['inscrito_id'], 'documento_ocr_id' => $fila['documento_ocr_id'], 'estado' => $fila['estado']];
                if ($fila['documento_ocr_id'] !== null) $usados[(int) $fila['documento_ocr_id']] = true;
            }
        }

        // Indice numero_leido -> MEJOR doc con ese numero (para match exacto).
        // Cuando hay varios escaneos del mismo numero (p.ej. tras reprocesar el PDF),
        // preferimos el que trae fecha de nacimiento, luego mayor confianza, luego el
        // mas reciente. Asi el informe muestra la fecha en vez de un escaneo viejo sin ella.
        $porNumero = [];   // num => doc (array) elegido
        foreach ($documentos as $d) {
            $num = (string) $d['numero_leido'];
            if ($num === '' || isset($usados[(int) $d['id']])) continue;
            if (!isset($porNumero[$num]) || $this->esMejor($d, $porNumero[$num])) {
                $porNumero[$num] = $d;
            }
        }

        $nombres = [];
        $numeros = [];
        foreach ($inscritos as $ins) {
            $nombres[$this->nombreNormalizado($ins['nombre'])][] = (int) $ins['id'];
            $numeros[(string) $ins['numero_documento']][] = (int) $ins['id'];
        }

        foreach ($inscritos as $ins) {
            $numDoc = (string) $ins['numero_documento'];
            $inscritoId = (int) $ins['id'];
            if (isset($asignados[$inscritoId])) continue;

            // El número exacto propone la imagen; solo nombres concordantes
            // y lectura sin avisos permiten marcarla como coincidencia.
            if (isset($porNumero[$numDoc]) && count($numeros[$numDoc]) === 1) {
                $elegido = $porNumero[$numDoc];
                foreach ($documentos as $candidato) {
                    if ((string)$candidato['numero_leido'] !== $numDoc || isset($usados[(int)$candidato['id']])) continue;
                    $seguro = Identidad::motivoRevision($ins['nombre'], $candidato) === null;
                    $actualSeguro = Identidad::motivoRevision($ins['nombre'], $elegido) === null;
                    if (($seguro && !$actualSeguro) || ($seguro === $actualSeguro && $this->esMejor($candidato, $elegido))) $elegido = $candidato;
                }
                $docId = (int) $elegido['id'];
                $usados[$docId] = true;
                $estado = empty($ins['nombre_autocompletado']) && Identidad::motivoRevision($ins['nombre'], $elegido) === null ? 'ok' : 'no_coincide';
                $asignados[$inscritoId] = ['inscrito_id' => $inscritoId, 'documento_ocr_id' => $docId, 'estado' => $estado];
            }
        }

        foreach ($inscritos as $ins) {
            $inscritoId = (int) $ins['id'];
            if (isset($asignados[$inscritoId])) continue;
            $nombre = $this->nombreNormalizado($ins['nombre']);
            if ($nombre === '' || count($nombres[$nombre]) !== 1) continue;
            $candidatos = [];
            foreach ($documentos as $doc) {
                if (isset($usados[(int) $doc['id']]) || empty($doc['nombres']) || empty($doc['apellidos'])) continue;
                if (isset($numeros[(string) $doc['numero_leido']])) continue;
                if ($this->nombreNormalizado($doc['nombres'] . ' ' . $doc['apellidos']) === $nombre) $candidatos[] = $doc;
            }
            // Escaneos repetidos con igual nombre y número son una propuesta.
            // Números distintos para el mismo nombre conservan la ambigüedad.
            $identificaciones = array_unique(array_map(static fn(array $d): string => (string)$d['numero_leido'], $candidatos));
            if ($candidatos && count($identificaciones) === 1) {
                $elegido = $candidatos[0];
                foreach ($candidatos as $candidato) {
                    if ($this->esMejor($candidato, $elegido)) $elegido = $candidato;
                }
                $docId = (int) $elegido['id'];
                $usados[$docId] = true;
                $asignados[$inscritoId] = ['inscrito_id' => $inscritoId, 'documento_ocr_id' => $docId, 'estado' => 'no_coincide'];
            }
        }

        foreach ($documentos as $doc) {
            if (isset($numeros[(string) $doc['numero_leido']])) $usados[(int) $doc['id']] = true;
        }
        $propuestas = [];
        foreach ($inscritos as $ins) {
            $inscritoId = (int) $ins['id'];
            if (isset($asignados[$inscritoId])) continue;
            $numDoc = (string) $ins['numero_documento'];

            // 2) Match tentativo por cercania (numero leido similar, doc aun libre).
            $docCercano = $this->docMasCercano($numDoc, $ins['nombre'], array_values($porNumero), $usados);
            if ($docCercano !== null) {
                $propuestas[$docCercano][] = $inscritoId;
            }
        }
        foreach ($propuestas as $docId => $personas) {
            if (count($personas) !== 1) continue;
            $asignados[$personas[0]] = ['inscrito_id' => $personas[0], 'documento_ocr_id' => $docId, 'estado' => 'no_coincide'];
        }
        $filas = [];
        foreach ($inscritos as $ins) {
            $inscritoId = (int) $ins['id'];
            $filas[] = $asignados[$inscritoId] ?? ['inscrito_id' => $inscritoId, 'documento_ocr_id' => null, 'estado' => 'falta_documento'];
        }
        return $filas;
    }

    private function nombreNormalizado(string $nombre): string
    {
        return Identidad::nombre($nombre);
    }

    /**
     * ¿El documento candidato es "mejor" que el actual para representar un numero?
     * Criterio: tener fecha de nacimiento > mayor confianza > mas reciente (id mayor).
     *
     * @param array<string,mixed> $cand
     * @param array<string,mixed> $actual
     */
    private function esMejor(array $cand, array $actual): bool
    {
        $candFecha   = ($cand['fecha_nacimiento'] ?? null) !== null;
        $actualFecha = ($actual['fecha_nacimiento'] ?? null) !== null;
        if ($candFecha !== $actualFecha) {
            return $candFecha;
        }

        $candConf   = (float) ($cand['confianza'] ?? 0);
        $actualConf = (float) ($actual['confianza'] ?? 0);
        if ($candConf !== $actualConf) {
            return $candConf > $actualConf;
        }

        return (int) $cand['id'] > (int) $actual['id'];
    }

    /**
     * Devuelve el id del documento libre con numero mas cercano (dentro del umbral),
     * o null si ninguno esta suficientemente cerca.
     *
     * @param list<array<string,mixed>> $documentos
     * @param array<int,bool>           $usados
     */
    private function docMasCercano(string $numDoc, string $nombre, array $documentos, array $usados): ?int
    {
        $mejorId = null;
        $mejorDist = PHP_INT_MAX;
        $empate = false;

        foreach ($documentos as $d) {
            $id = (int) $d['id'];
            if (isset($usados[$id])) {
                continue;
            }
            $numLeido = (string) $d['numero_leido'];
            if (!Identidad::nombresCercanos($nombre, $d)) continue;

            // Solo tiene sentido comparar numeros de longitud parecida.
            if (abs(strlen($numLeido) - strlen($numDoc)) > self::MAX_DISTANCIA) {
                continue;
            }

            $dist = levenshtein($numDoc, $numLeido);
            if ($dist > 0 && $dist <= self::MAX_DISTANCIA && $dist < $mejorDist) {
                $mejorDist = $dist;
                $mejorId = $id;
                $empate = false;
            } elseif ($dist > 0 && $dist === $mejorDist) {
                $empate = true;
            }
        }

        return $empate ? null : $mejorId;
    }
}
