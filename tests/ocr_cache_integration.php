<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    $path = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});
App\Core\Env::load(BASE_PATH . '/.env');
putenv('OCR_CACHE=1');
putenv('OCR_CACHE_VERSION=test-' . bin2hex(random_bytes(6)));
$image = realpath($argv[1] ?? '');
if (!$image || !is_file($image)) throw new RuntimeException('Indica imagen dentro de uploads.');
$copy = dirname($image) . '/cache-copy-' . bin2hex(random_bytes(6)) . '.jpg';
copy($image, $copy);
$reverse = isset($argv[2]) ? realpath($argv[2]) : null;
$copyReverse = null;
if ($reverse) {
    $copyReverse = dirname($reverse) . '/cache-copy-' . bin2hex(random_bytes(6)) . '.jpg';
    copy($reverse, $copyReverse);
}
try {
    $service = new App\Services\OcrService();
    $start = microtime(true);
    $first = $reverse ? $service->procesarCaras($image, $reverse) : $service->procesar($image);
    $firstMs = round((microtime(true) - $start) * 1000);
    if (App\Services\OcrService::metricas()['cache']['acierto'] ?? true) throw new RuntimeException('Primer intento no es nuevo.');
    $start = microtime(true);
    $second = $copyReverse ? $service->procesarCaras($copy, $copyReverse) : $service->procesar($copy);
    $secondMs = round((microtime(true) - $start) * 1000);
    $metrics = App\Services\OcrService::metricas();
    if (!($metrics['cache']['acierto'] ?? false) || $metrics['inferencia'] !== [] || $first !== $second) {
        throw new RuntimeException('La copia debe reutilizar exactamente los datos sin inferencia.');
    }
    echo json_encode(['primera_ms' => $firstMs, 'repetida_ms' => $secondMs,
        'campos_identicos' => true, 'inferencias_repetida' => count($metrics['inferencia'])]) . "\n";
} finally {
    unlink($copy);
    if ($copyReverse) unlink($copyReverse);
}
