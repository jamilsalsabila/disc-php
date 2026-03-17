<?php

declare(strict_types=1);

function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

function env(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

load_env(dirname(__DIR__) . '/.env');

return [
    'app_name' => 'DISC Assessment',
    'timezone' => env('APP_TIMEZONE', 'Asia/Jakarta'),
    'base_path' => rtrim((string) env('APP_BASE_PATH', ''), '/'),
    'test_duration_minutes' => (int) env('TEST_DURATION_MINUTES', 10),
    'min_completion_ratio' => (float) env('MIN_COMPLETION_RATIO', 0.8),
    'role_options' => ['Server Specialist', 'Beverage Specialist', 'Senior Cook'],
    'db_path' => dirname(__DIR__) . '/storage/disc_app.sqlite',
    'question_source' => dirname(__DIR__) . '/one_for_all_v1.txt',
    'hr_auth_disabled' => strtolower((string) env('HR_AUTH_DISABLED', 'false')) === 'true',
    'hr_login_email' => (string) env('HR_LOGIN_EMAIL', 'hr@disc.local'),
    'hr_password_hash' => (string) env('HR_PASSWORD_HASH', '$2b$10$txN96OIJRG.tmEToCLg/qu5.f6v.2BQx0x1pC40YSJCEHKBA2N.dy'),
    'hr_login_max_attempts' => (int) env('HR_LOGIN_MAX_ATTEMPTS', 5),
    'hr_login_window_sec' => (int) env('HR_LOGIN_WINDOW_SEC', 900),
    'hr_login_lock_sec' => (int) env('HR_LOGIN_LOCK_SEC', 900),
    'session_name' => (string) env('SESSION_NAME', 'disc_php_session'),
    'session_secure' => strtolower((string) env('SESSION_SECURE', 'false')) === 'true',
    'session_samesite' => (string) env('SESSION_SAMESITE', 'Lax'),
    'cookie_secure' => strtolower((string) env('COOKIE_SECURE', 'false')) === 'true',
    'cookie_samesite' => (string) env('COOKIE_SAMESITE', 'Lax'),
    'csp_enabled' => strtolower((string) env('CSP_ENABLED', 'false')) === 'true',
];
