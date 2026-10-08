<?php
declare(strict_types=1);
namespace App\Services;

/** Reglas conservadoras de cruce; la semejanza nunca confirma una identidad. */
final class Identidad
{
    public static function nombre(string $valor): string
    {
        $valor = strtr(mb_strtoupper(trim($valor)), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U']);
        $valor = preg_replace('/[^\p{L}\s]/u', ' ', $valor) ?? '';
        $tokens = preg_split('/\s+/u', trim($valor), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($tokens, SORT_STRING);
        return implode(' ', $tokens);
    }

    public static function nombreDocumento(array $doc): string
    {
        if (empty($doc['nombres']) || empty($doc['apellidos'])) return '';
        return self::nombre($doc['nombres'] . ' ' . $doc['apellidos']);
    }

    public static function motivoRevision(string $nombreLista, array $doc): ?string
    {
        if (str_contains((string)($doc['texto_crudo'] ?? ''), '[PAR PROPUESTO OCR]')) {
            return 'El sistema propone estas páginas como frente y reverso, pero sus números difieren en un dígito. Se conservaron los datos del frente; revisa ambas imágenes antes de confirmar.';
        }
        if (str_contains((string)($doc['texto_crudo'] ?? ''), '[REVERSO NO ASOCIADO]')) {
            return 'Se identificó el frente, pero el número leído en el reverso es distinto. Revisa ambas caras y completa los datos faltantes antes de confirmar.';
        }
        if (str_contains((string)($doc['texto_crudo'] ?? ''), '[DATOS COMPLETADOS]')) {
            return 'Se completaron datos automáticamente al comparar la lista con el documento. No constituyen una segunda lectura independiente; verifica la imagen antes de confirmar.';
        }
        if (!empty($doc['error']) || str_contains((string)($doc['texto_crudo'] ?? ''), '[REVISION OCR]')) {
            return 'El OCR reportó una lectura dudosa; verifica la imagen antes de confirmar.';
        }
        $nombre = self::nombreDocumento($doc);
        if ($nombre === '' || self::nombre($nombreLista) === '') {
            return 'El número coincide, pero faltan nombres o apellidos para respaldar la identidad.';
        }
        if ($nombre !== self::nombre($nombreLista)) {
            return 'El número coincide, pero los nombres y apellidos difieren del Excel. Revisa que sea la misma persona.';
        }
        if (!isset($doc['confianza']) || (float)$doc['confianza'] < 55) {
            return 'El número y el nombre coinciden, pero la lectura tiene confianza baja o desconocida. Verifica la imagen.';
        }
        return null;
    }

    public static function nombresCercanos(string $lista, array $doc): bool
    {
        $a = self::nombre($lista);
        $b = self::nombreDocumento($doc);
        if ($a === '' || $b === '') return false;
        $ta = explode(' ', $a); $tb = explode(' ', $b);
        if (count($ta) < 2 || count($ta) !== count($tb)) return false;
        if ($a === $b) return true;
        // Exigir la mayoría de palabras idénticas; solo una palabra puede
        // diferir, por un carácter, y no puede ser una inicial o abreviatura.
        $faltan = $tb;
        $distintos = [];
        foreach ($ta as $token) {
            $indice = array_search($token, $faltan, true);
            if ($indice === false) $distintos[] = $token;
            else unset($faltan[$indice]);
        }
        $faltan = array_values($faltan);
        return count($distintos) === 1 && count($faltan) === 1
            && mb_strlen($distintos[0]) >= 4 && mb_strlen($faltan[0]) >= 4
            && levenshtein($distintos[0], $faltan[0]) === 1;
    }
}
