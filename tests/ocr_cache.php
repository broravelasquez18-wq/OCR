<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/Services/OcrCache.php';
use App\Services\OcrCache;
function comprobar(bool $ok, string $mensaje): void {
    if (!$ok) throw new RuntimeException($mensaje);
}
$dir = dirname(__DIR__) . '/storage/tmp/cache-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
try {
    file_put_contents($dir . '/a.img', 'frente');
    file_put_contents($dir . '/b.img', 'reverso');
    file_put_contents($dir . '/c.img', 'frente');
    $a = $dir . '/a.img'; $b = $dir . '/b.img'; $c = $dir . '/c.img';
    $key = OcrCache::clave([$a, $b], ['mode' => 1]);
    comprobar($key === OcrCache::clave([$c, $b], ['mode' => 1]), 'El nombre del archivo no debe influir.');
    comprobar($key !== OcrCache::clave([$b, $a], ['mode' => 1]), 'El orden sí debe influir.');
    comprobar($key !== OcrCache::clave([$a], ['mode' => 1]), 'Una cara no equivale a dos.');
    comprobar($key !== OcrCache::clave([$a, $b], ['mode' => 2]), 'Configuración no invalidada.');
    file_put_contents($c, 'otro contenido');
    comprobar($key !== OcrCache::clave([$c, $b], ['mode' => 1]), 'Contenido no invalidado.');
    $calls = 0; $hit = false;
    $compute = static function () use (&$calls): array { ++$calls; return ['ok' => true]; };
    $allow = static fn(array $data): bool => ($data['ok'] ?? false) === true;
    $read = static function () use ($dir, $key, &$hit, $compute, $allow): array {
        return OcrCache::recordar($dir, $key, microtime(true) + 2, $compute, $allow, $hit);
    };
    comprobar($read() === ['ok' => true] && !$hit, 'Primera lectura debe calcular.');
    comprobar($read() === ['ok' => true] && $hit && $calls === 1, 'Segunda lectura debe reutilizar.');
    file_put_contents($dir . '/' . $key . '.json', '{corrupto');
    $read(); comprobar(!$hit && $calls === 2, 'Debe recuperarse de JSON corrupto.');
    file_put_contents($dir . '/' . $key . '.json', json_encode(['version' => 1,
        'creado' => time() - 604801, 'datos' => ['ok' => true]]));
    $read(); comprobar(!$hit && $calls === 3, 'Debe expirar a los siete días.');
    $other = hash('sha256', 'error');
    for ($i = 0; $i < 2; $i++) {
        OcrCache::recordar($dir, $other, microtime(true) + 2,
            static fn(): array => ['error' => 'fallo'], $allow, $hit);
        comprobar(!$hit && !is_file($dir . '/' . $other . '.json'), 'No guardar errores.');
    }
    comprobar(file_get_contents($dir . '/.htaccess') === "Require all denied\n", 'Falta protección HTTP.');
    echo "OK: contenido, orden, configuración, aciertos, errores, corrupción y vencimiento.\n";
} finally {
    foreach (new DirectoryIterator($dir) as $entry) {
        if ($entry->isFile()) unlink($entry->getPathname());
    }
    rmdir($dir);
}
