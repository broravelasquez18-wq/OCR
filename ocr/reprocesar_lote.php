<?php

declare(strict_types=1);

/**
 * Reprocesa las imagenes ya cargadas de un lote con el OCR actual y actualiza
 * `documentos_ocr` (numero, nombres, apellidos, fecha, zona, confianza, texto).
 *
 * Uso:
 *   php ocr/reprocesar_lote.php <ID_LOTE> [--todos]
 *
 * Por defecto es conservador: solo sobrescribe una fila cuando la nueva lectura
 * SI obtiene numero (para no perder lecturas previas por una regresion). Con
 * `--todos` sobrescribe siempre con el resultado nuevo.
 *
 * No toca `verificaciones` (asociaciones ni correcciones manuales). Despues de
 * reprocesar, recalcula el cruce desde la vista de Verificacion.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
define('STORAGE_PATH', BASE_PATH . '/storage');
require BASE_PATH . '/vendor/autoload.php';

App\Core\Env::load(BASE_PATH . '/.env');

$loteId = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT);
if (!$loteId || $loteId < 1) {
    fwrite(STDERR, "Uso: php ocr/reprocesar_lote.php <ID_LOTE> [--todos]\n");
    exit(1);
}
$forzarTodos = in_array('--todos', $argv, true);

$lote = App\Models\Lote::buscar($loteId);
if (!$lote) {
    fwrite(STDERR, "Lote {$loteId} no existe.\n");
    exit(1);
}

$documentos = App\Models\DocumentoOcr::porLote($loteId);
$ocr = new App\Services\OcrService();

$total = count($documentos);
$actualizados = 0;
$recuperados = 0;   // pasaron de sin-numero a con-numero
$sinCambio = 0;
$fallidos = 0;

echo "Reprocesando lote {$loteId} ({$lote['codigo_ficha']}): {$total} documentos" . ($forzarTodos ? ' [--todos]' : '') . "\n";

foreach ($documentos as $documento) {
    $inicio = microtime(true);
    $antes = $documento['numero_leido'];
    try {
        $frente = STORAGE_PATH . '/' . $documento['ruta_imagen'];
        $resultado = !empty($documento['ruta_reverso'])
            ? $ocr->procesarCaras($frente, STORAGE_PATH . '/' . $documento['ruta_reverso'])
            : $ocr->procesar($frente);
    } catch (Throwable $error) {
        $fallidos++;
        echo "  #{$documento['id']}: ERROR {$error->getMessage()}\n";
        continue;
    }

    $ahora = $resultado['numero'] ?? null;

    // Conservador: si la lectura nueva no trae numero y no se fuerza, no tocar la fila.
    if ($ahora === null && !$forzarTodos) {
        $sinCambio++;
        echo "  #{$documento['id']}: sin numero (se conserva), " . round(microtime(true) - $inicio, 2) . "s\n";
        continue;
    }

    App\Models\DocumentoOcr::actualizarLectura((int) $documento['id'], [
        'numero_leido'     => $ahora,
        'zona_fuente'      => (string) ($resultado['zona'] ?? 'desconocida'),
        'confianza'        => $resultado['confianza'] ?? null,
        'nombres'          => $resultado['nombres'] ?? null,
        'apellidos'        => $resultado['apellidos'] ?? null,
        'fecha_nacimiento' => $resultado['fecha_nacimiento'] ?? null,
        'fecha_zona'       => $resultado['fecha_zona'] ?? null,
        'texto_crudo'      => (string) ($resultado['texto_crudo'] ?? ''),
    ]);
    $actualizados++;
    if (empty($antes) && !empty($ahora)) {
        $recuperados++;
    }

    $marca = (empty($antes) && !empty($ahora)) ? ' (RECUPERADO)' : '';
    echo "  #{$documento['id']}: " . ($ahora ? 'numero leido' : 'sin numero') . "{$marca}, " . round(microtime(true) - $inicio, 2) . "s\n";
}

echo "\nResumen: {$total} documentos | actualizados {$actualizados} | recuperados {$recuperados} | sin cambio {$sinCambio} | fallidos {$fallidos}\n";
echo "Ahora abre Verificacion y recalcula el cruce para reasociar por numero.\n";
