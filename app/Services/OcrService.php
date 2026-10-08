<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use RuntimeException;

/**
 * Invoca el helper de Python (OpenCV + RapidOCR/Tesseract) sobre una imagen y
 * devuelve el numero leido, la zona y la confianza.
 */
final class OcrService
{
    private ?float $deadline = null;
    private static array $metricas = [];

    public static function metricas(): array { return self::$metricas; }

    public function __construct(?float $deadline = null)
    {
        $this->deadline = $deadline;
    }

    private function configurarMotor(): void
    {
        foreach (['OCR_ENGINE' => 'tesseract', 'OCR_THREADS' => '2', 'OCR_MAX_SIDE' => '1600',
            'OCR_BARCODE' => '1', 'TESSERACT_BIN' => '', 'TESSERACT_LANG' => 'spa',
            'RAPIDOCR_DET_SIDE' => '960', 'OCR_REC_BATCH' => '1', 'OCR_ZONES' => '1'] as $key => $default) {
            putenv($key . '=' . Env::get($key, $default));
        }
    }

    public function preparar(): void
    {
        $this->configurarMotor();
        OcrWorker::preparar(Env::get('PYTHON_BIN', 'python3'), $this->deadline ?? microtime(true) + 25.0);
    }

    public static function combinarCaras(array $frente, array $reverso): array
    {
        if (!empty($frente['error']) || !empty($reverso['error'])) {
            throw new RuntimeException('Falló la lectura de una de las caras; vuelve a procesar ambas.');
        }
        $numeroFrente = $frente['numero'] ?? null;
        $numeroReverso = $reverso['numero'] ?? null;

        // Evidencia de cada numero: 2 = fuente estructurada (también puede fallar),
        // 1 = frontal con nombre en esa cara, 0 = digito suelto (posible espurio,
        // p. ej. una fecha o serial del reverso), -1 = sin numero. Muchos escaneos
        // ponen frente y reverso en paginas separadas y el reverso lee un numero
        // espurio: en ese caso gana el numero mas confiable en vez de rechazar la
        // union. Se rechaza si AMBAS lecturas tienen evidencia (>=1) y
        // distintas: ahi si podrian ser personas distintas.
        $rango = static function (?string $numero, array $datos): int {
            if (!$numero) {
                return -1;
            }
            if (in_array($datos['zona'] ?? null, ['pdf417', 'mrz', 'codigo_barras'], true)) {
                return 2;
            }
            return (!empty($datos['nombres']) || !empty($datos['apellidos'])) ? 1 : 0;
        };
        $rangoFrente = $rango($numeroFrente, $frente);
        $rangoReverso = $rango($numeroReverso, $reverso);

        $numeroFinal = $numeroFrente ?: $numeroReverso;
        $vieneDeReverso = false;
        if ($numeroFrente && $numeroReverso && $numeroFrente !== $numeroReverso) {
            if ($rangoFrente >= 1 && $rangoReverso >= 1) {
                throw new RuntimeException('Las caras tienen números distintos. Revisa si pertenecen a la misma persona o si el OCR leyó mal; no se unieron.');
            }
            if ($rangoReverso > $rangoFrente) {
                $numeroFinal = $numeroReverso;
                $vieneDeReverso = true;
            } elseif ($rangoFrente > $rangoReverso) {
                $numeroFinal = $numeroFrente;
            } else {
                $numeroFinal = null; // ambas debiles y contradictorias: dejar pendiente
            }
        }

        // El orden de las páginas no autoriza a mezclar datos contradictorios.
        foreach (['nombres', 'apellidos'] as $campo) {
            if (!empty($frente[$campo]) && !empty($reverso[$campo])
                && Identidad::nombre($frente[$campo]) !== Identidad::nombre($reverso[$campo])) {
                throw new RuntimeException('Las caras tienen nombres o apellidos distintos. Revisa las dos páginas antes de asociarlas.');
            }
        }
        $fechaFrente = $frente['fecha_nacimiento'] ?? null;
        $fechaReverso = $reverso['fecha_nacimiento'] ?? null;
        if ($fechaFrente && $fechaReverso && $fechaFrente !== $fechaReverso) {
            throw new RuntimeException('Las caras tienen fechas de nacimiento distintas. Revisa las dos páginas antes de asociarlas.');
        }

        $resultado = $vieneDeReverso ? $reverso : $frente;
        $resultado['numero'] = $numeroFinal;
        $resultado['nombres'] = ($frente['nombres'] ?? null) ?: ($reverso['nombres'] ?? null);
        $resultado['apellidos'] = ($frente['apellidos'] ?? null) ?: ($reverso['apellidos'] ?? null);
        $resultado['fecha_nacimiento'] = $fechaReverso ?: $fechaFrente;
        $resultado['fecha_zona'] = $fechaReverso ? ($reverso['fecha_zona'] ?? null) : ($frente['fecha_zona'] ?? null);
        $resultado['edad'] = Edad::calcular($resultado['fecha_nacimiento']);
        $resultado['texto_crudo'] = "[FRENTE]\n" . ($frente['texto_crudo'] ?? '') . "\n[REVERSO]\n" . ($reverso['texto_crudo'] ?? '');
        return $resultado;
    }

    /**
     * @return array{numero:?string, zona:string, confianza:?float, fecha_nacimiento:?string,
     *               fecha_zona:?string, texto_crudo:string, error:?string}
     */
    public function procesar(string $rutaImagen, bool $binarizado = false): array
    {
        return $this->procesarImagenes([$rutaImagen], $binarizado)[0];
    }

    private function procesarImagenes(array $rutas, bool $binarizado = false): array
    {
        self::$metricas = [];
        $this->configurarMotor();
        $base = realpath(BASE_PATH . '/storage/uploads');
        foreach ($rutas as $ruta) {
            $real = realpath($ruta);
            // La caché nunca debe saltarse las restricciones del motor.
            if (!$base || !$real || !is_file($real)
                || !str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $base) . '/')) {
                throw new RuntimeException('La imagen debe estar en el almacenamiento de uploads.');
            }
        }
        $calcular = function () use ($rutas, $binarizado): array {
            $datos = $this->leerImagenes($rutas, $binarizado);
            self::$metricas = OcrWorker::metricas();
            return $datos;
        };
        if (!Env::bool('OCR_CACHE', true)) {
            $datos = $calcular();
            self::$metricas['cache'] = ['habilitada' => false, 'acierto' => false];
            return $datos;
        }
        $config = ['binarizado' => $binarizado, 'version' => Env::get('OCR_CACHE_VERSION', '1'),
            // El parser de años de dos dígitos depende del año actual.
            'anio' => date('Y')];
        foreach (['PYTHON_BIN', 'OCR_ENGINE', 'OCR_THREADS', 'OCR_MAX_SIDE', 'OCR_BARCODE',
            'TESSERACT_BIN', 'TESSERACT_LANG', 'RAPIDOCR_DET_SIDE', 'OCR_REC_BATCH', 'OCR_ZONES'] as $key) {
            $config[$key] = Env::get($key);
        }
        foreach (['ocr/procesar.py', 'ocr/zonas.py', 'ocr/worker.py', 'ocr/requirements.txt',
            'app/Services/OcrService.php', 'app/Services/OcrCache.php'] as $file) {
            $config[$file] = hash_file('sha256', BASE_PATH . '/' . $file);
        }
        $inicio = microtime(true);
        $clave = OcrCache::clave($rutas, $config);
        $acierto = false;
        $admitir = static function (array $caras) use ($rutas): bool {
            if (count($caras) !== count($rutas) || !array_is_list($caras)) return false;
            foreach ($caras as $cara) {
                if (!is_array($cara) || !empty($cara['error']) || !isset($cara['texto_crudo'])
                    || !is_string($cara['texto_crudo'])) return false;
                foreach (['numero', 'nombres', 'apellidos', 'fecha_nacimiento'] as $campo) {
                    if (!array_key_exists($campo, $cara) || ($cara[$campo] !== null && !is_string($cara[$campo]))) return false;
                }
            }
            try {
                $datos = count($caras) === 2 ? self::combinarCaras(...$caras) : $caras[0];
            } catch (RuntimeException $e) {
                return false;
            }
            return !empty($datos['numero']) || !empty($datos['nombres']) || !empty($datos['fecha_nacimiento']);
        };
        $datos = OcrCache::recordar(BASE_PATH . '/storage/tmp/ocr-cache', $clave,
            $this->deadline ?? microtime(true) + 25.0, $calcular, $admitir, $acierto);
        if ($acierto) self::$metricas = ['cola_ms' => 0, 'arranque_ms' => 0, 'inferencia' => []];
        self::$metricas['cache'] = ['habilitada' => true, 'acierto' => $acierto,
            'consulta_y_lectura_ms' => (int) round((microtime(true) - $inicio) * 1000)];
        // Recalcular edad también al reutilizar una lectura antigua.
        return array_map(fn (array $cara): array => $this->normalizar($cara), $datos);
    }

    private function leerImagenes(array $rutas, bool $binarizado = false): array
    {
        $this->configurarMotor();
        foreach ($rutas as $rutaImagen) {
            if (!is_file($rutaImagen)) {
                throw new RuntimeException('La imagen a procesar no existe.');
            }
        }

        $python = Env::get('PYTHON_BIN', 'python3');
        $script = BASE_PATH . '/ocr/procesar.py';
        $lang    = Env::get('TESSERACT_LANG', 'spa');
        $engine  = Env::get('OCR_ENGINE', 'tesseract');
        $barcode = Env::get('OCR_BARCODE', '1');
        $threads = Env::get('OCR_THREADS', '');
        $maxSide = Env::get('OCR_MAX_SIDE', '');

        if (!is_file($script)) {
            throw new RuntimeException('No se encontro el script OCR: ' . $script);
        }

        // Las variables de .env viven en la clase Env, no en el entorno real del
        // proceso. Las exportamos para que el subproceso de Python las herede
        // (procesar.py lee TESSERACT_BIN / TESSERACT_LANG / OCR_ENGINE de os.environ).
        $tesseractBin = Env::get('TESSERACT_BIN', '');
        if ($tesseractBin !== '' && $tesseractBin !== null) {
            putenv('TESSERACT_BIN=' . $tesseractBin);
        }
        putenv('TESSERACT_LANG=' . $lang);
        if ($engine !== '' && $engine !== null) {
            putenv('OCR_ENGINE=' . $engine);
        }
        if ($barcode !== '' && $barcode !== null) {
            putenv('OCR_BARCODE=' . $barcode);
        }
        if ($threads !== '' && $threads !== null) {
            putenv('OCR_THREADS=' . $threads);
        }
        if ($maxSide !== '' && $maxSide !== null) {
            putenv('OCR_MAX_SIDE=' . $maxSide);
        }
        putenv('RAPIDOCR_DET_SIDE=' . Env::get('RAPIDOCR_DET_SIDE', '960'));

        // Ambas caras se componen en una imagen y se leen en una sola inferencia.
        $cmd = [$python, '-X', 'utf8', $script, $rutas[0], '--lang', $lang];
        if (isset($rutas[1])) {
            array_push($cmd, '--reverso', $rutas[1]);
        }
        if ($binarizado) {
            $cmd[] = '--binarizado';
        }
        $salida = OcrWorker::ejecutar($python, array_slice($cmd, 4), $this->deadline ?? microtime(true) + 25.0);
        if ($salida === null || trim((string) $salida) === '') {
            throw new RuntimeException('El proceso OCR no devolvio salida (¿python/tesseract instalados?).');
        }

        // El script imprime un JSON por linea; tomar la ultima linea JSON valida
        // (por si alguna libreria escribio warnings antes).
        $decoded = $this->extraerJson((string) $salida);
        if ($decoded === null) {
            throw new RuntimeException('Salida OCR no es JSON valido: ' . substr((string) $salida, 0, 300));
        }

        $caras = isset($rutas[1]) ? ($decoded['caras'] ?? []) : [$decoded];
        if (count($caras) !== count($rutas)) {
            throw new RuntimeException('El OCR no devolvió todas las caras del documento.');
        }
        return array_map(fn (array $cara): array => $this->normalizar($cara), $caras);
    }

    private function normalizar(array $decoded): array
    {
        return [
            'nombres' => isset($decoded['nombres']) ? (string) $decoded['nombres'] : null,
            'apellidos' => isset($decoded['apellidos']) ? (string) $decoded['apellidos'] : null,
            'edad' => Edad::calcular($decoded['fecha_nacimiento'] ?? null),
            'numero'      => isset($decoded['numero']) && $decoded['numero'] !== null
                                ? (string) $decoded['numero'] : null,
            'zona'        => ($decoded['zona'] ?? '') === 'pdf417' ? 'codigo_barras' : (string) ($decoded['zona'] ?? 'desconocida'),
            'confianza'   => isset($decoded['confianza']) && $decoded['confianza'] !== null
                                ? (float) $decoded['confianza'] : null,
            'fecha_nacimiento' => isset($decoded['fecha_nacimiento']) && $decoded['fecha_nacimiento'] !== null
                                ? (string) $decoded['fecha_nacimiento'] : null,
            'fecha_zona'  => isset($decoded['fecha_zona']) && $decoded['fecha_zona'] !== null
                                ? (string) $decoded['fecha_zona'] : null,
            'texto_crudo' => (string) ($decoded['texto_crudo'] ?? ''),
            'error'       => $decoded['error'] ?? null,
        ];
    }

    /**
     * Encuentra el ultimo objeto JSON en la salida (robusto ante warnings previos).
     *
     * @return array<string,mixed>|null
     */
    private function extraerJson(string $salida): ?array
    {
        $lineas = array_reverse(preg_split('/\r?\n/', trim($salida)) ?: []);
        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if ($linea === '' || $linea[0] !== '{') {
                continue;
            }
            $decoded = json_decode($linea, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    public function procesarCaras(string $frente, string $reverso): array
    {
        $deadlineAnterior = $this->deadline;
        $this->deadline ??= microtime(true) + 25.0;
        try {
            [$principal, $segunda] = $this->procesarImagenes([$frente, $reverso]);
            return self::combinarCaras($principal, $segunda);
        } finally {
            $this->deadline = $deadlineAnterior;
        }
    }
}
