<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
spl_autoload_register(function (string $class): void {
    $path = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});
App\Core\Env::load(BASE_PATH . '/.env');
$image = realpath($argv[1] ?? '');
if (!$image || !is_file($image)) throw new RuntimeException('Indica una imagen de storage/uploads.');
$service = new App\Services\OcrService();
$service->preparar();
$python = App\Core\Env::get('PYTHON_BIN', 'python3');
$expired = false;
try {
    App\Services\OcrWorker::ejecutar($python, [$image], microtime(true) + 0.05);
} catch (RuntimeException $e) {
    $expired = true;
}
if (!$expired) throw new RuntimeException('La prueba no alcanzó el vencimiento esperado.');
// Debe reiniciar el slot muerto y producir JSON válido, sin quedar bloqueado.
$result = $service->procesar($image);
if (!array_key_exists('texto_crudo', $result)) throw new RuntimeException('No se recuperó el motor.');
$blocked = false;
try {
    App\Services\OcrWorker::ejecutar($python, [BASE_PATH . '/.env'], microtime(true) + 2);
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'almacenamiento');
}
if (!$blocked) throw new RuntimeException('El motor aceptó una ruta externa a uploads.');
echo "OK: plazo, reinicio automático y restricción de rutas.\n";
