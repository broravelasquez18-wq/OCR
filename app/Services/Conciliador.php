<?php
declare(strict_types=1);
namespace App\Services;

/** Prepara ajustes reversibles para la verificación, sin alterar Excel ni OCR. */
final class Conciliador
{
    public static function preparar(array $filas): array
    {
        $numeros = []; $nombres = [];
        foreach ($filas as $fila) {
            $numeros[(string)$fila['numero_documento']] = ($numeros[(string)$fila['numero_documento']] ?? 0) + 1;
            $nombre = Identidad::nombre((string)$fila['nombre']);
            $nombres[$nombre] = ($nombres[$nombre] ?? 0) + 1;
        }
        foreach ($filas as &$fila) {
            $fila['conciliacion'] = ['valores'=>[], 'detalles'=>[]];
            if (!empty($fila['confirmado']) || !empty($fila['corregido_manual'])
                || ($fila['numero_confirmado'] ?? null) !== null
                || ($fila['nombres_manual'] ?? null) !== null || ($fila['apellidos_manual'] ?? null) !== null
                || empty($fila['documento_ocr_id'])) continue;
            $lista = (string)$fila['numero_documento'];
            $ocr = (string)($fila['numero_leido'] ?? '');
            if ($numeros[$lista] !== 1 || $nombres[Identidad::nombre((string)$fila['nombre'])] !== 1) continue;
            if (str_contains((string)($fila['texto_crudo'] ?? ''), '[REVISION OCR]')) continue;
            $doc = ['nombres'=>$fila['nombres_ocr'] ?? '', 'apellidos'=>$fila['apellidos_ocr'] ?? ''];
            if (!$doc['nombres'] || !$doc['apellidos']) continue;
            $mismoNombre = Identidad::nombreDocumento($doc) === Identidad::nombre((string)$fila['nombre']);
            if ($ocr !== '' && $ocr === $lista && Identidad::nombresCercanos((string)$fila['nombre'], $doc)) {
                $ajuste = self::alinearNombres((string)$fila['nombre'], $doc);
                if ($ajuste) {
                    $fila['conciliacion']['valores'] = $ajuste;
                    $fila['conciliacion']['detalles'][] = $mismoNombre
                        ? 'Formato de nombres ajustado con el Excel; el número y las palabras coinciden.'
                        : 'Ajuste propuesto del nombre OCR con el Excel: coincide el número y solo difiere una letra. Comprueba la imagen antes de confirmar.';
                }
            } elseif ($mismoNombre && $ocr !== '' && ctype_digit($ocr) && ctype_digit($lista)
                && min(strlen($ocr), strlen($lista)) >= 6 && levenshtein($ocr, $lista) === 1) {
                $zona = (string)($fila['zona_fuente'] ?? '');
                if ($zona === 'pdf417' && (float)($fila['confianza'] ?? 0) >= 90) {
                    $fila['conciliacion']['detalles'][] = 'Posible corrección de la lista: el PDF417 devuelve ' . $ocr
                        . ' y el Excel contiene ' . $lista . '. Los nombres coinciden. Verifica ambos; el Excel original se conserva.';
                } elseif (!in_array($zona, ['pdf417', 'mrz', 'codigo_barras'], true)
                    && isset($fila['confianza']) && (float)$fila['confianza'] < 55) {
                    $fila['conciliacion']['valores']['numero'] = $lista;
                    $fila['conciliacion']['detalles'][] = 'Número del Excel propuesto en el campo editable: coinciden los nombres y el OCR de baja confianza difiere en un dígito. Verifica la imagen; aún no está confirmado.';
                } else {
                    $fila['conciliacion']['detalles'][] = 'Los nombres coinciden, pero hay un dígito distinto. No hay evidencia suficiente para elegir entre Excel y PDF; se conservan ambos valores.';
                }
            }
        }
        unset($fila);
        return $filas;
    }

    private static function alinearNombres(string $lista, array $doc): array
    {
        $tokens = preg_split('/[^\p{L}]+/u', mb_strtoupper(trim($lista)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $campos = []; $pendientes = [];
        foreach (['nombres','apellidos'] as $campo) {
            $palabras = preg_split('/\s+/u', mb_strtoupper(trim($doc[$campo])), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $campos[$campo] = $palabras;
            foreach ($palabras as $i => $palabra) {
                $coincide = null;
                foreach ($tokens as $j => $token) {
                    if (Identidad::nombre($palabra) === Identidad::nombre($token)) { $coincide = $j; break; }
                }
                if ($coincide === null) $pendientes[] = [$campo, $i, $palabra];
                else { $campos[$campo][$i] = $tokens[$coincide]; unset($tokens[$coincide]); }
            }
        }
        if ($pendientes || $tokens) {
            if (count($pendientes) !== 1 || count($tokens) !== 1) return [];
            [$campo, $i, $palabra] = $pendientes[0];
            $token = reset($tokens);
            if (min(mb_strlen($palabra), mb_strlen($token)) < 4
                || levenshtein(Identidad::nombre($palabra), Identidad::nombre($token)) !== 1) return [];
            $campos[$campo][$i] = $token;
        }
        $ajustes = [];
        foreach ($campos as $campo => $palabras) {
            $valor = implode(' ', $palabras);
            if ($valor !== $doc[$campo]) $ajustes[$campo] = $valor;
        }
        return $ajustes;
    }
}
