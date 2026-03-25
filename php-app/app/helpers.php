<?php

declare(strict_types=1);

function h($value): string
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
    switch ($code) {
        case 'FLOOR_CREW':
            return 'Floor Crew ( Server, Runner, Housekeeping )';
        case 'BAR_CREW':
            return 'Bar Crew';
        case 'KITCHEN_CREW':
            return 'Kitchen Crew ( Cook, Cook Helper, Steward )';
        case 'MANAGER':
            return 'Manager';
        case 'BACK_OFFICE':
            return 'Back Office ( Admin )';
        // Legacy codes
        case 'SERVER_SPECIALIST':
            return 'Floor Crew ( Server, Runner, Housekeeping )';
        case 'BEVERAGE_SPECIALIST':
            return 'Bar Crew';
        case 'SENIOR_COOK':
            return 'Kitchen Crew ( Cook, Cook Helper, Steward )';
        case 'ASSISTANT_MANAGER':
            return 'Manager';
        case 'OPERATIONS_ADMIN':
            return 'Back Office ( Admin )';
        case 'INCOMPLETE':
            return 'Incomplete';
        case 'TIDAK_DIREKOMENDASIKAN_SERVICE':
            return 'Tidak Direkomendasikan (Grup Service)';
        case 'TIDAK_DIREKOMENDASIKAN_MANAGEMENT':
            return 'Tidak Direkomendasikan (Grup Management)';
        case 'TIDAK_DIREKOMENDASIKAN':
            return 'Tidak Direkomendasikan';
        default:
            return '-';
    }
}

function redirect(string $path): void
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
    if ($base !== '' && strpos($path, $base) === 0) {
        $path = substr($path, strlen($base));
        $path = $path === '' ? '/' : $path;
    }
    return $path;
}

function json_response(array $payload, int $status = 200): void
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

function verify_csrf_or_abort(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        return;
    }

    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($token) || !is_string($sessionToken) || $token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        http_response_code(419);
        echo 'CSRF token tidak valid.';
        exit;
    }
}

function render(string $view, array $data = []): void
{
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
