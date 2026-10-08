<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/OcrProcess.php';

use App\Services\OcrProcess;

function comprobar(bool $ok, string $mensaje): void
{
    if (!$ok) throw new RuntimeException($mensaje);
}

$salida = OcrProcess::ejecutar([PHP_BINARY, '-r', 'echo str_repeat("x", 200000);'], microtime(true) + 5);
comprobar(strlen($salida) === 200000, 'Salida extensa incompleta o bloqueada.');
$salida = OcrProcess::ejecutar([PHP_BINARY, '-r', 'echo $argv[1];', 'ruta con espacios & caracteres'], microtime(true) + 5);
comprobar($salida === 'ruta con espacios & caracteres', 'Argumentos alterados por el shell.');

$marca = tempnam(sys_get_temp_dir(), 'ocr_test_');
try {
    $inicio = microtime(true);
    $agotado = false;
    try {
        OcrProcess::ejecutar([PHP_BINARY, '-r', 'sleep(3); file_put_contents($argv[1], "continuo");', $marca], $inicio + 1.0);
    } catch (RuntimeException $e) {
        $agotado = str_contains($e->getMessage(), 'agotó');
    }
    comprobar($agotado, 'No se informó el vencimiento.');
    comprobar(microtime(true) - $inicio < 2.5, 'El proceso no se detuvo a tiempo.');
    usleep(3100000);
    comprobar(file_get_contents($marca) === '', 'El proceso continuó ejecutándose tras el vencimiento.');
} finally {
    unlink($marca);
}
echo "OK: salida extensa, argumentos y terminación real del proceso.\n";
