<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class OcrProcess
{
    /** Ejecuta directamente Python y limita el tiempo real, también en Windows. */
    public static function ejecutar(array $comando, float $deadline): string
    {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Se agotó el tiempo de OCR. Las imágenes quedan para revisión.');
        }
        $salida = tempnam(sys_get_temp_dir(), 'ocr_');
        if ($salida === false) {
            throw new RuntimeException('No se pudo preparar la salida OCR.');
        }
        $proceso = null;
        try {
            // Archivos en vez de pipes: evitan bloqueos al leer stdout en Windows.
            $proceso = proc_open($comando, [
                0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => ['file', $salida, 'w'],
                2 => ['redirect', 1],
            ], $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($proceso)) {
                throw new RuntimeException('No se pudo iniciar Python.');
            }
            while (proc_get_status($proceso)['running']) {
                if (microtime(true) >= $deadline) {
                    proc_terminate($proceso, 9);
                    throw new RuntimeException('Se agotó el tiempo de OCR. Las imágenes quedan para revisión.');
                }
                usleep(20000);
            }
            return (string) file_get_contents($salida);
        } finally {
            if (is_resource($proceso)) {
                proc_close($proceso);
            }
            @unlink($salida);
        }
    }
}
