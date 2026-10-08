<?php

declare(strict_types=1);

use App\Core\Env;
use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\LoteController;
use App\Controllers\OcrController;
use App\Controllers\VerificacionController;

// Bajo el servidor embebido de PHP (php -S ... public/index.php), dejar que
// los archivos estaticos reales (css/js/img) se sirvan tal cual.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');

// ---------------------------------------------------------------------------
// Prefijo base para URLs. Vacio cuando public/ es la raiz del sitio
// (VirtualHost en :8080 o servidor embebido), o el subdirectorio cuando la
// app se sirve desde una subcarpeta de htdocs (p. ej. /OCR/public).
// ---------------------------------------------------------------------------
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
define('BASE_URL', ($scriptDir === '/' || $scriptDir === '.' || $scriptDir === '') ? '' : rtrim($scriptDir, '/'));

// ---------------------------------------------------------------------------
// Autoload: Composer (para PhpSpreadsheet) + fallback PSR-4 para App\*.
// ---------------------------------------------------------------------------
$composer = BASE_PATH . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $relative = substr($class, strlen('App\\'));
    $file = APP_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---------------------------------------------------------------------------
// Entorno y errores.
// ---------------------------------------------------------------------------
Env::load(BASE_PATH . '/.env');

$debug = Env::bool('APP_DEBUG', false);
$isOcrRequest = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    || rtrim(rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/') === '/ocr/procesar';
error_reporting(E_ALL);
ini_set('display_errors', $debug && !$isOcrRequest ? '1' : '0');
ini_set('log_errors', '1');

// ---------------------------------------------------------------------------
// Rutas.
// ---------------------------------------------------------------------------
$router = new Router();

// Auth
$router->get('/login',  [AuthController::class, 'form']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/logout', [AuthController::class, 'logout']);

// Home -> listado de lotes
$router->get('/', [LoteController::class, 'index']);

// Lotes
$router->get('/lotes',              [LoteController::class, 'index']);
$router->get('/lotes/nuevo',        [LoteController::class, 'formSubirLista']);
$router->post('/lotes/importar',    [LoteController::class, 'importar']);
$router->get('/lotes/{id}',         [LoteController::class, 'ver']);
$router->post('/lotes/{id}/eliminar', [LoteController::class, 'eliminar']);
$router->get('/lotes/{id}/documentos', [LoteController::class, 'formSubirDocumentos']);

// OCR
$router->post('/ocr/procesar', [OcrController::class, 'procesar']);
$router->post('/ocr/preparar', [OcrController::class, 'preparar']);
$router->post('/ocr/agrupar-pdf', [OcrController::class, 'agruparPdf']);
$router->post('/ocr/finalizar', [OcrController::class, 'finalizar']);

// Verificacion
$router->post('/lotes/{id}/comparar',    [VerificacionController::class, 'comparar']);
$router->get('/lotes/{id}/verificacion', [VerificacionController::class, 'tabla']);
$router->post('/verificaciones/{id}/guardar',  [VerificacionController::class, 'guardarFila']);
$router->post('/verificaciones/{id}/reabrir', [VerificacionController::class, 'reabrir']);
$router->get('/verificaciones/{id}/imagen/{cara}', [VerificacionController::class, 'imagen']);
$router->get('/documentos/{id}/imagen/{cara}', [VerificacionController::class, 'imagenDocumento']);
$router->post('/documentos/{id}/asociar', [VerificacionController::class, 'asociarImagen']);
$router->post('/verificaciones/{id}/confirmar', [VerificacionController::class, 'confirmar']);
$router->post('/lotes/{id}/cerrar',      [VerificacionController::class, 'cerrar']);
$router->get('/lotes/{id}/informe',      [VerificacionController::class, 'informe']);

// ---------------------------------------------------------------------------
// Despacho.
// ---------------------------------------------------------------------------
try {
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
} catch (Throwable $e) {
    http_response_code(500);
    if ($isOcrRequest) {
        error_log((string) $e);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No se pudo completar la operación. Revisa los documentos antes de reintentar.', 'resultado_desconocido' => true]);
    } elseif ($debug) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Error: {$e->getMessage()}\n\n{$e->getTraceAsString()}";
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>500</h1><p>Ocurrio un error interno.</p>';
    }
}
