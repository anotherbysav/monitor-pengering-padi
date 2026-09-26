<?php
/**
 * Utilitas HTTP: JSON response, parsing input, CORS, dan-route matching.
 */

declare(strict_types=1);

namespace App;

final class Http
{
    private static bool $sent = false;

    public static function boot(): void
    {
        $cors = Db::config()['cors'] ?? [];
        if (($cors['enabled'] ?? true) === true) {
            header('Access-Control-Allow-Origin: ' . ($cors['allow_origin'] ?? '*'));
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, X-Device-Code, Authorization');
            header('Access-Control-Max-Age: 86400');
        }
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $uri  = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($base !== '' && $base !== '/' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        $uri = '/' . ltrim($uri, '/');
        return $uri === '/' ? '/' : rtrim($uri, '/');
    }

    /** Body JSON (fallback ke form-encoded lalu query string). */
    public static function input(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $data = [];
        $raw  = file_get_contents('php://input') ?: '';
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        if ($data === [] && $_POST !== []) {
            $data = $_POST;
        }
        if ($data === [] && self::method() === 'GET') {
            $data = $_GET;
        }

        return $cache = $data;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        $value = $_GET[$key] ?? null;
        return $value === null || $value === '' ? $default : $value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::input()[$key] ?? self::query($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $value = self::input()[$key] ?? self::query($key, $default);
        return is_numeric($value) ? (float) $value : $default;
    }

    public static function str(string $key, string $default = ''): string
    {
        $value = self::input()[$key] ?? self::query($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::input()[$key] ?? self::query($key, null);
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes', 'aktif', 'nyala'], true);
    }

    public static function json(mixed $payload, int $status = 200): void
    {
        if (self::$sent) {
            return;
        }
        self::$sent = true;
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    public static function ok(mixed $data = null, string $message = '', int $status = 200): void
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'time'    => date('c'),
        ], $status);
    }

    public static function fail(string $message, int $status = 400, string $code = 'error', array $extra = []): void
    {
        self::json(array_merge([
            'success' => false,
            'code'    => $code,
            'message' => $message,
            'time'    => date('c'),
        ], $extra), $status);
    }

    /** Jalankan handler, tangkap error lalu kirim sebagai JSON. */
    public static function handle(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $debug = (bool) Db::app('debug', false);
            error_log('[API] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            self::fail(
                $debug ? $e->getMessage() : 'Terjadi kesalahan pada server.',
                500,
                'server_error',
                $debug ? ['trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 6)] : []
            );
        }
    }

    public static function preflight(): void
    {
        http_response_code(204);
        exit;
    }
}
