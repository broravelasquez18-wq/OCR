<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
App\Core\Env::load(BASE_PATH . '/.env');
$loteId = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT);
if (!$loteId || $loteId < 1) throw new RuntimeException('Indica el lote a evaluar.');
$documentos = App\Models\DocumentoOcr::porLote($loteId);
$ocr = new App\Services\OcrService();
$resultados = [];
foreach ($documentos as $documento) {
    $inicio = microtime(true);
    try {
        $resultado = $documento['ruta_reverso']
            ? $ocr->procesarCaras(BASE_PATH . '/storage/' . $documento['ruta_imagen'], BASE_PATH . '/storage/' . $documento['ruta_reverso'])
            : $ocr->procesar(BASE_PATH . '/storage/' . $documento['ruta_imagen']);
    } catch (Throwable $error) {
        $resultado = ['numero' => null, 'error' => $error->getMessage()];
    }
    $resultados[] = ['id' => $documento['id'], 'antes' => $documento['numero_leido'], 'resultado' => $resultado];
    file_put_contents(BASE_PATH . '/storage/tmp/evaluacion-lote-' . $loteId . '.json', json_encode($resultados, JSON_INVALID_UTF8_SUBSTITUTE));
    echo json_encode(['id' => $documento['id'], 'antes' => $documento['numero_leido'], 'ahora' => $resultado['numero'], 'error' => $resultado['error'] ?? null, 'segundos' => round(microtime(true) - $inicio, 2)]) . PHP_EOL;
}
