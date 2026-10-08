<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
spl_autoload_register(function (string $class): void {
    $path = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});
App\Core\Env::load(BASE_PATH . '/.env');
// Esta prueba mide inferencia real, nunca resultados reutilizados.
putenv('OCR_CACHE=0');
$service = new App\Services\OcrService();
$start = microtime(true);
try {
    if (($argv[1] ?? '') === '--prepare') {
        $service->preparar();
        $result = [];
    } else {
        $result = isset($argv[2]) ? $service->procesarCaras($argv[1], $argv[2]) : $service->procesar($argv[1]);
    }
    echo json_encode(['seconds' => round(microtime(true) - $start, 3),
        'fields' => count(array_filter(array_intersect_key($result, array_flip(['numero', 'nombres', 'apellidos', 'fecha_nacimiento'])))),
        'review' => !empty($result['error']),
        'timings' => App\Services\OcrService::metricas()]);
} catch (Throwable $e) {
    echo json_encode(['seconds' => round(microtime(true) - $start, 3), 'error' => $e->getMessage()]);
}
