<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Estima DONDE esta probablemente el error de una fila de verificacion:
 * en la lista importada (Excel) o en la lectura OCR.
 *
 * Se apoya en tres senales que ya tenemos:
 *   - confianza  : media de confianza que reporto Tesseract (0-100).
 *   - zona_fuente: de donde se leyo el numero. MRZ y codigo de barras son
 *                  fiables; "frontal" (numero impreso) y "desconocida" no tanto.
 *   - distancia  : cuantos digitos difieren entre el numero de la lista y el leido.
 *
 * Heuristica (no es certeza, es una pista para el operador):
 *   - OCR fiable (MRZ/barras) + buena confianza + difieren pocos digitos
 *       -> el numero leido es de fiar; probablemente el Excel tiene el numero mal.
 *   - Confianza baja o zona poco fiable
 *       -> probablemente el OCR leyo mal; verificar contra la imagen.
 *   - Diferencia grande con buena lectura
 *       -> puede ser una cedula que no corresponde a ese inscrito.
 */
final class Diagnostico
{
    /** Confianza por debajo de la cual una lectura OCR se considera dudosa. */
    public const UMBRAL_CONFIANZA = 55.0;

    /** Zonas de lectura consideradas fiables. */
    private const ZONAS_FIABLES = ['mrz', 'codigo_barras'];

    /**
     * @param array<string,mixed>       $fila      fila de Verificacion::porLoteDetallado
     * @param list<array<string,mixed>> $sobrantes documentos leidos sin inscrito en la lista
     * @return array{origen:string, etiqueta:string, detalle:string}
     *   origen: 'excel' | 'ocr' | 'documento' | 'ninguno'
     */
    public static function analizar(array $fila, array $sobrantes = []): array
    {
        if (!empty($fila['nombre_autocompletado']) && empty($fila['confirmado'])) {
            return self::r('documento', 'Se completaron o corrigieron datos de la lista automáticamente desde el PDF. Verifica la imagen antes de confirmar.');
        }
        if (preg_match_all('/\[REVISION OCR\] ([^\r\n]+)/u', (string) ($fila['texto_crudo'] ?? ''), $avisos)) {
            return self::r('ocr', implode(' ', array_unique($avisos[1])));
        }
        $estado   = (string) $fila['estado'];
        $numLista = (string) $fila['numero_documento'];
        $numLeido = ($fila['numero_leido'] ?? null) !== null ? (string) $fila['numero_leido'] : '';
        $conf     = $fila['confianza'] !== null ? (float) $fila['confianza'] : null;
        $zona     = (string) ($fila['zona_fuente'] ?? '');
        if ($numLeido !== '' && $numLeido === $numLista && empty($fila['confirmado']) && empty($fila['corregido_manual'])) {
            $motivo = Identidad::motivoRevision((string)($fila['nombre'] ?? ''), [
                'nombres' => $fila['nombres_ocr'] ?? null, 'apellidos' => $fila['apellidos_ocr'] ?? null,
                'confianza' => $conf, 'texto_crudo' => $fila['texto_crudo'] ?? '']);
            if ($motivo !== null) return self::r('documento', $motivo);
        }
        if ($estado === 'no_coincide' && !empty($fila['documento_ocr_id']) && ($numLeido === '' || levenshtein($numLista, $numLeido) > 2)) {
            return self::r('ocr', 'Imagen asociada por coincidencia única de nombres y apellidos. El número OCR falta o es distinto; verifica la identificación contra la imagen antes de confirmar.');
        }

        $zonaFiable = in_array($zona, self::ZONAS_FIABLES, true);
        $confBaja   = $conf !== null && $conf < self::UMBRAL_CONFIANZA;
        $confTxt    = $conf !== null ? self::num($conf) . '%' : 'desconocida';

        // --- No coincide: hay un numero leido cercano pero distinto ---
        if ($estado === 'no_coincide' && $numLeido !== '') {
            $dist = levenshtein($numLista, $numLeido);

            if ($zonaFiable && !$confBaja) {
                return self::r('documento',
                    'El numero se leyo del ' . self::zonaTxt($zona) . ' con buena confianza (' . $confTxt
                    . ') y solo difiere en ' . $dist . ' digito(s) del de la lista. '
                    . 'Compara la imagen con la lista: esta señal no permite decidir cuál de los dos datos está equivocado.');
            }
            if ($confBaja || !$zonaFiable) {
                return self::r('ocr',
                    'La lectura fue de ' . self::zonaTxt($zona) . ' con confianza ' . $confTxt
                    . '. Verifica el número y los nombres contra la imagen y el Excel antes de decidir cuál corregir.');
            }
            return self::r('documento',
                'El numero leido (' . $numLeido . ') difiere del de la lista; revisar que la cedula '
                . 'corresponda a este inscrito.');
        }

        // --- Falta documento: ¿hay una cedula leida "suelta" parecida a este numero? ---
        if ($estado === 'falta_documento') {
            foreach ($sobrantes as $s) {
                $ns = ($s['numero_leido'] ?? null) !== null ? (string) $s['numero_leido'] : '';
                if ($ns === '') {
                    continue;
                }
                if (abs(strlen($ns) - strlen($numLista)) <= 2 && levenshtein($numLista, $ns) <= 2) {
                    return self::r('documento',
                        'Se leyo una cedula (' . $ns . ') que no aparece en la lista pero se parece a este '
                        . 'numero. La semejanza del número no demuestra que sea la misma persona. '
                        . 'Revisar ambos.');
                }
            }
            return self::r('documento',
                'No se encontró una imagen con coincidencia suficiente para este inscrito. Revisa los documentos sin asociar antes de volver a subirla.');
        }

        // --- Coincide pero la lectura fue floja: avisar por OCR ---
        if (($estado === 'ok' || $estado === 'corregido') && $confBaja) {
            return self::r('ocr',
                'El numero coincide, pero la lectura OCR fue de baja confianza (' . $confTxt
                . '); conviene verificarlo contra la imagen.');
        }

        return self::r('ninguno', '');
    }

    /** Etiqueta corta por origen (para el badge). */
    public static function etiqueta(string $origen): string
    {
        return [
            'excel'     => 'Revisar lista (Excel)',
            'ocr'       => 'Revisar OCR',
            'documento' => 'Revisar documento',
            'ninguno'   => '',
        ][$origen] ?? '';
    }

    /** @return array{origen:string, etiqueta:string, detalle:string} */
    private static function r(string $origen, string $detalle): array
    {
        return ['origen' => $origen, 'etiqueta' => self::etiqueta($origen), 'detalle' => $detalle];
    }

    private static function zonaTxt(string $zona): string
    {
        return [
            'mrz'           => 'MRZ (banda de la cedula nueva)',
            'codigo_barras' => 'codigo de barras',
            'frontal'       => 'numero frontal impreso',
            'desconocida'   => 'una zona no identificada',
        ][$zona] ?? ('zona ' . $zona);
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2), '0'), '.');
    }
}
