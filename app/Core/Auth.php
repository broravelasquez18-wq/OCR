<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Autenticacion simple basada en sesion. Un solo operador por ahora,
 * pero deja el camino listo para roles (columna usuarios.rol).
 */
final class Auth
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $directory = dirname(__DIR__, 2) . '/storage/sessions';
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new \RuntimeException('No se pudo preparar el almacenamiento de sesiones del sistema.');
            }
            if (!is_writable($directory)) {
                throw new \RuntimeException('El almacenamiento de sesiones del sistema no permite escritura.');
            }
            session_save_path($directory);
            session_name('OCRSESSID');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'path' => '/',
            ]);
            if (!@session_start()) {
                throw new \RuntimeException('No se pudo abrir la sesión del sistema. Revisa el almacenamiento de sesiones.');
            }
        }
    }

    public static function attempt(string $email, string $password): bool
    {
        $stmt = Database::conn()->prepare(
            'SELECT id, nombre, email, password_hash, rol FROM usuarios WHERE email = :email AND activo = 1 LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        self::start();
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'     => (int) $user['id'],
            'nombre' => $user['nombre'],
            'email'  => $user['email'],
            'rol'    => $user['rol'],
        ];
        return true;
    }

    public static function check(): bool
    {
        self::start();
        return isset($_SESSION['user']['id']);
    }

    /** @return array{id:int,nombre:string,email:string,rol:string}|null */
    public static function user(): ?array
    {
        self::start();
        return $_SESSION['user'] ?? null;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    /**
     * Exige sesion activa; si no, redirige a login.
     */
    public static function require(): void
    {
        if (!self::check()) {
            $base = defined('BASE_URL') ? BASE_URL : '';
            header('Location: ' . $base . '/login', true, 302);
            exit;
        }
    }
}
