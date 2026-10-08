<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/Services/OcrCache.php';
use App\Services\OcrCache;
if (($argv[1] ?? '') === '--child') {
    $dir = $argv[2];
    $hit = false;
    OcrCache::recordar($dir, hash('sha256', 'simultaneo'), microtime(true) + 4,
        static function () use ($dir): array {
            file_put_contents($dir . '/calls.txt', "motor\n", FILE_APPEND | LOCK_EX);
            usleep(300000);
            return ['ok' => true];
        }, static fn(array $data): bool => !empty($data['ok']), $hit);
    echo $hit ? 'hit' : 'miss';
    exit;
}
$dir = dirname(__DIR__) . '/storage/tmp/cache-test-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
$processes = [];
try {
    for ($i = 0; $i < 2; $i++) {
        $pipes = [];
        $processes[] = proc_open([PHP_BINARY, __FILE__, '--child', $dir],
            [0 => ['file', 'NUL', 'r'], 1 => ['file', $dir . '/' . $i . '.out', 'w'],
             2 => ['file', $dir . '/' . $i . '.err', 'w']], $pipes);
    }
    foreach ($processes as $process) {
        if (proc_close($process) !== 0) throw new RuntimeException('Falló el proceso de prueba.');
    }
    $outputs = [file_get_contents($dir . '/0.out'), file_get_contents($dir . '/1.out')];
    sort($outputs);
    if ($outputs !== ['hit', 'miss'] || file_get_contents($dir . '/calls.txt') !== "motor\n") {
        throw new RuntimeException('Dos solicitudes idénticas ejecutaron más de un cálculo.');
    }
    echo "OK: dos solicitudes simultáneas comparten una sola ejecución.\n";
} finally {
    foreach (new DirectoryIterator($dir) as $entry) {
        if ($entry->isFile()) unlink($entry->getPathname());
    }
    rmdir($dir);
}
