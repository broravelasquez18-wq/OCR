<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Controlador base: render de vistas, respuestas JSON y helpers.
 */
abstract class Controller
{
    /**
     * Renderiza una vista PHP dentro del layout.
     *
     * @param string               $view  ruta relativa dentro de app/Views sin extension, ej "lote/subir_lista"
     * @param array<string,mixed>  $data  variables expuestas a la vista
     */
    protected function view(string $view, array $data = [], string $layout = 'layout'): void
    {
        $viewFile = APP_PATH . '/Views/' . $view . '.php';
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo "Vista no encontrada: {$view}";
            return;
        }

        extract($data, EXTR_SKIP);

        // Capturar el contenido de la vista.
        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        $layoutFile = APP_PATH . '/Views/' . $layout . '.php';
        if (is_file($layoutFile)) {
            require $layoutFile;
        } else {
            echo $content;
        }
    }

    /**
     * @param array<string,mixed>|list<mixed> $payload
     */
    protected function json(mixed $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        // JSON_INVALID_UTF8_SUBSTITUTE evita que json_encode devuelva false (y por
        // tanto un cuerpo vacio => "Unexpected end of JSON input" en el navegador)
        // cuando el payload arrastra bytes no-UTF8 provenientes del OCR crudo.
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            $json = json_encode(
                ['ok' => false, 'error' => 'error al serializar la respuesta: ' . json_last_error_msg()]
            );
        }
        echo $json;
    }

    protected function redirect(string $to, int $status = 302): void
    {
        // Prefijar el subdirectorio base a las rutas internas (que empiezan por '/'),
        // sin tocar URLs absolutas (http...).
        $base = defined('BASE_URL') ? BASE_URL : '';
        if ($base !== '' && str_starts_with($to, '/') && !str_starts_with($to, $base . '/')) {
            $to = $base . $to;
        }
        header('Location: ' . $to, true, $status);
        exit;
    }

    /**
     * Lee un parametro de $_POST de forma segura.
     */
    protected function input(string $key, ?string $default = null): ?string
    {
        $val = $_POST[$key] ?? $default;
        return is_string($val) ? trim($val) : $default;
    }

    /**
     * Escapa para salida HTML.
     */
    protected function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
