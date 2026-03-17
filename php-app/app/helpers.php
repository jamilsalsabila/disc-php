<?php

declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function now_iso(): string
{
    return gmdate('c');
}

function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function normalize_whatsapp(string $whatsapp): string
{
    return preg_replace('/\D+/', '', $whatsapp) ?? '';
}

function seconds_between(string $startIso, string $endIso): int
{
    $start = strtotime($startIso) ?: 0;
    $end = strtotime($endIso) ?: 0;
    return max(0, $end - $start);
}

function format_date_id(?string $iso): string
{
    if (!$iso) {
        return '-';
    }
    $timestamp = strtotime($iso);
    if ($timestamp === false) {
        return '-';
    }
    return date('d M Y H:i', $timestamp);
}

function map_recommendation_label(?string $code): string
{
    return match ($code) {
        'SERVER_SPECIALIST' => 'Server Specialist',
        'BEVERAGE_SPECIALIST' => 'Beverage Specialist',
        'SENIOR_COOK' => 'Senior Cook',
        'INCOMPLETE' => 'Incomplete',
        'TIDAK_DIREKOMENDASIKAN' => 'Tidak Direkomendasikan',
        default => '-',
    };
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function route_path(string $path): string
{
    $base = $GLOBALS['config']['base_path'] ?? '';
    if ($base === '') {
        return $path;
    }
    return $base . ($path === '/' ? '' : $path);
}

function asset_path(string $path): string
{
    return route_path('/assets/' . ltrim($path, '/'));
}

function current_path(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?? '/';
    $base = $GLOBALS['config']['base_path'] ?? '';
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
        $path = $path === '' ? '/' : $path;
    }
    return $path;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function rotate_csrf_token(): string
{
    $current = (string) ($_SESSION['csrf_token'] ?? '');
    if ($current !== '') {
        $_SESSION['csrf_token_prev'] = $current;
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf_token'];
}

function flash_set(string $key, string $message): void
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][$key] = $message;
}

function flash_get(string $key): ?string
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }
    if (!array_key_exists($key, $_SESSION['flash'])) {
        return null;
    }
    $value = (string) $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $value;
}

function prefers_json_response(): bool
{
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $xhr = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    $path = current_path();

    return str_contains($accept, 'application/json')
        || strcasecmp($xhr, 'XMLHttpRequest') === 0
        || str_starts_with($path, '/hr/api/');
}

function verify_csrf_or_abort(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        return;
    }

    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $previousToken = $_SESSION['csrf_token_prev'] ?? '';
    $isValidCurrent = is_string($token) && is_string($sessionToken) && $token !== '' && $sessionToken !== '' && hash_equals($sessionToken, $token);
    $isValidPrevious = is_string($token) && is_string($previousToken) && $token !== '' && $previousToken !== '' && hash_equals($previousToken, $token);

    if (!$isValidCurrent && !$isValidPrevious) {
        rotate_csrf_token();
        http_response_code(419);
        if (prefers_json_response()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'code' => 'CSRF_INVALID',
                'message' => 'Sesi form Anda sudah kadaluarsa. Silakan refresh halaman lalu coba lagi.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        flash_set('error_message', 'Sesi form Anda sudah kadaluarsa. Silakan coba kirim ulang.');
        $fallback = route_path('/');
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer !== '') {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
            $refHost = (string) (parse_url($referer, PHP_URL_HOST) ?? '');
            if ($refHost === '' || $refHost === $host) {
                $refPath = parse_url($referer, PHP_URL_PATH);
                $refQuery = parse_url($referer, PHP_URL_QUERY);
                if (is_string($refPath) && $refPath !== '') {
                    $fallback = $refPath . (is_string($refQuery) && $refQuery !== '' ? '?' . $refQuery : '');
                }
            }
        }
        header('Location: ' . $fallback);
        exit;
    }
}

function render(string $view, array $data = []): void
{
    if (!array_key_exists('error_message', $data)) {
        $flashError = flash_get('error_message');
        if (is_string($flashError) && $flashError !== '') {
            $data['error_message'] = $flashError;
        }
    }

    $data['view'] = $view;
    $data['csrf_token'] = csrf_token();
    extract($data, EXTR_SKIP);
    require dirname(__DIR__) . '/views/layout/main.php';
}

function set_security_headers(array $config): void
{
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if ($config['csp_enabled']) {
        header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:;");
    }
}
