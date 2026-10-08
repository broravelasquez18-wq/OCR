<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class OcrWorker
{
    private static array $metricas = [];
    public static function metricas(): array { return self::$metricas; }
    public static function ejecutar(string $python, array $args, float $deadline): string
    {
        self::$metricas = [];
        $start = microtime(true);
        [$lock, $state] = self::reservar($python, $deadline);
        $reserved = microtime(true);
        try {
            self::iniciar($python, $state, $deadline);
            $ready = microtime(true);
            $response = self::enviar($state, ['args' => $args, 'deadline' => $deadline], $deadline);
            self::$metricas = ['cola_ms' => (int) round(($reserved - $start) * 1000),
                'arranque_ms' => (int) round(($ready - $reserved) * 1000),
                'inferencia' => $response['inferencia'] ?? []];
            if (empty($response['ok']) || !isset($response['output'])) {
                throw new RuntimeException($response['error'] ?? 'Se agotó el tiempo del motor OCR. Las imágenes quedan para revisión.');
            }
            return $response['output'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function preparar(string $python, float $deadline): void
    {
        // Calentar los dos slots una sola vez mientras el usuario prepara el PDF.
        foreach ([0, 1] as $slot) {
            [$lock, $state] = self::reservar($python, $deadline, $slot);
            try {
                self::iniciar($python, $state, $deadline);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function reservar(string $python, float $deadline, ?int $onlySlot = null): array
    {
        $directory = BASE_PATH . '/storage/tmp/ocr-workers';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el motor OCR.');
        }
        if (!is_file($directory . '/.htaccess')) {
            file_put_contents($directory . '/.htaccess', "Require all denied\n", LOCK_EX);
        }
        $config = [];
        foreach (['OCR_ENGINE', 'OCR_THREADS', 'OCR_MAX_SIDE', 'OCR_BARCODE', 'TESSERACT_BIN', 'TESSERACT_LANG', 'RAPIDOCR_DET_SIDE', 'OCR_REC_BATCH', 'OCR_ZONES'] as $key) {
            $config[$key] = getenv($key);
        }
        $version = substr(hash('sha256', $python . json_encode($config)
              . hash_file('sha256', BASE_PATH . '/ocr/procesar.py')
              . hash_file('sha256', BASE_PATH . '/ocr/zonas.py')
            . hash_file('sha256', BASE_PATH . '/ocr/worker.py')), 0, 20);
        do {
            foreach ($onlySlot === null ? [0, 1] : [$onlySlot] as $slot) {
                $state = $directory . '/' . $version . '-' . $slot . '.json';
                $lock = fopen($state . '.lock', 'c');
                if ($lock === false) throw new RuntimeException('No se pudo reservar el motor OCR.');
                if (flock($lock, LOCK_EX | LOCK_NB)) return [$lock, $state];
                fclose($lock);
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('El motor OCR está ocupado. Las imágenes quedan para revisión.');
    }

    private static function iniciar(string $python, string $state, float $deadline): void
    {
        if (self::enviar($state, ['action' => 'ping'], min($deadline, microtime(true) + 0.5))['ok'] ?? false) return;
        OcrProcess::ejecutar([$python, '-X', 'utf8', BASE_PATH . '/ocr/worker.py', '--start', '--state', $state], $deadline);
        do {
            usleep(50000);
            if (self::enviar($state, ['action' => 'ping'], min($deadline, microtime(true) + 0.5))['ok'] ?? false) return;
        } while (microtime(true) < $deadline);
        throw new RuntimeException('No se pudo iniciar el motor OCR dentro del plazo.');
    }

    private static function enviar(string $state, array $request, float $deadline): array
    {
        $config = is_file($state) ? json_decode((string) file_get_contents($state), true) : null;
        if (!is_array($config) || empty($config['port']) || empty($config['token'])) return [];
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) return [];
        $connection = @stream_socket_client('tcp://127.0.0.1:' . (int) $config['port'], $errno, $error, min(0.5, $remaining));
        if (!$connection) return [];
        try {
            $remaining = max(0.001, $deadline - microtime(true));
            stream_set_timeout($connection, (int) $remaining, (int) (($remaining - (int) $remaining) * 1000000));
            $message = json_encode(['token' => $config['token']] + $request, JSON_THROW_ON_ERROR) . "\n";
            while ($message !== '') {
                $written = @fwrite($connection, $message);
                if (!$written) return [];
                $message = substr($message, $written);
            }
            $response = fgets($connection, 4 * 1024 * 1024);
            return $response === false ? [] : (json_decode($response, true) ?: []);
        } finally {
            fclose($connection);
        }
    }
}
