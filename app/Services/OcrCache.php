<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Caché del resultado original del motor, nunca de correcciones del usuario. */
final class OcrCache
{
    public static function clave(array $rutas, array $config): string
    {
        $imagenes = [];
        foreach ($rutas as $ruta) {
            $hash = hash_file('sha256', $ruta);
            if ($hash === false) throw new RuntimeException('No se pudo leer la imagen.');
            $imagenes[] = $hash; // El orden frente/reverso forma parte de la clave.
        }
        return hash('sha256', json_encode([$imagenes, $config], JSON_THROW_ON_ERROR));
    }

    public static function recordar(string $directorio, string $clave, float $deadline,
        callable $calcular, callable $admitir, bool &$acierto, int $ttl = 604800): array
    {
        $acierto = false;
        if (!preg_match('/^[a-f0-9]{64}$/D', $clave)) throw new RuntimeException('Clave OCR inválida.');
        // Un fallo de almacenamiento no debe impedir el OCR. No guardar datos
        // si no se pudo instalar la protección HTTP del directorio.
        if ((!is_dir($directorio) && !@mkdir($directorio, 0700, true) && !is_dir($directorio))
            || @file_put_contents($directorio . '/.htaccess', "Require all denied\n", LOCK_EX) === false) {
            return $calcular();
        }
        // Número fijo de archivos de bloqueo: no crece con cada documento.
        $lock = @fopen($directorio . '/' . substr($clave, 0, 2) . '.lock', 'c');
        if (!$lock) return $calcular();
        try {
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) throw new RuntimeException('Se agotó el tiempo de espera del OCR.');
                usleep(20000);
            }
            $archivo = $directorio . '/' . $clave . '.json';
            $entrada = is_file($archivo) && filesize($archivo) <= 4 * 1024 * 1024
                ? json_decode((string) @file_get_contents($archivo), true) : null;
            if (is_array($entrada) && ($entrada['version'] ?? null) === 1
                && is_int($entrada['creado'] ?? null) && $entrada['creado'] <= time()
                && $entrada['creado'] > time() - $ttl && is_array($entrada['datos'] ?? null)
                && $admitir($entrada['datos'])) {
                $acierto = true;
                return $entrada['datos'];
            }
            if (is_file($archivo)) @unlink($archivo);
            $datos = $calcular();
            if ($admitir($datos)) {
                $json = json_encode(['version' => 1, 'creado' => time(), 'datos' => $datos]);
                if ($json !== false && strlen($json) <= 4 * 1024 * 1024) {
                    $tmp = @tempnam($directorio, 'ocr-');
                    if ($tmp !== false) {
                        if (@file_put_contents($tmp, $json, LOCK_EX) === strlen($json)) @rename($tmp, $archivo);
                        if (is_file($tmp)) @unlink($tmp);
                    }
                }
            }
            // Limpieza acotada de entradas vencidas; nunca imágenes originales.
            $revisados = 0;
            foreach (new \DirectoryIterator($directorio) as $entry) {
                if ($entry->isFile() && preg_match('/^[a-f0-9]{64}\.json$/D', $entry->getFilename())) {
                    if ($entry->getMTime() < time() - $ttl) @unlink($entry->getPathname());
                    if (++$revisados >= 100) break;
                }
            }
            return $datos;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
