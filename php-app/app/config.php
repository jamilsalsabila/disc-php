<?php

declare(strict_types=1);

function compat_starts_with($haystack, $needle)
{
    return $needle === '' || strpos((string) $haystack, (string) $needle) === 0;
}

function compat_ends_with($haystack, $needle)
{
    $haystack = (string) $haystack;
    $needle = (string) $needle;
    if ($needle === '') {
        return true;
    }
    $length = strlen($needle);
    if ($length > strlen($haystack)) {
        return false;
    }
    return substr($haystack, -$length) === $needle;
}

function compat_contains($haystack, $needle)
{
    return strpos((string) $haystack, (string) $needle) !== false;
}

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
        if ($line === '' || compat_starts_with($line, '#') || !compat_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        if ((compat_starts_with($value, '"') && compat_ends_with($value, '"')) || (compat_starts_with($value, "'") && compat_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

function env(string $key, $default = null)
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function env_int(string $key, int $default, int $min = 0): int
{
    $raw = getenv($key);
    if ($raw === false) {
        return max($default, $min);
    }
    $raw = trim((string) $raw);
    if ($raw === '') {
        return max($default, $min);
    }
    if (!preg_match('/^-?\d+$/', $raw)) {
        return max($default, $min);
    }
    return max((int) $raw, $min);
}

function env_float(string $key, float $default): float
{
    $raw = getenv($key);
    if ($raw === false) {
        return $default;
    }
    $raw = trim((string) $raw);
    if ($raw === '' || !is_numeric($raw)) {
        return $default;
    }
    return (float) $raw;
}

function detect_base_path(): string
{
    $envBase = rtrim((string) env('APP_BASE_PATH', ''), '/');
    if ($envBase !== '') {
        return $envBase;
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($scriptName === '') {
        return '';
    }

    $dir = str_replace('\\', '/', dirname($scriptName));
    if ($dir === '.' || $dir === '/' || $dir === '\\') {
        return '';
    }

    if (compat_ends_with($dir, '/public')) {
        $dir = substr($dir, 0, -7);
    }

    $dir = rtrim($dir, '/');
    return $dir === '' ? '' : $dir;
}

load_env(dirname(__DIR__) . '/.env');

return [
    'app_name' => 'DISC Assessment',
    'timezone' => env('APP_TIMEZONE', 'Asia/Jakarta'),
    'base_path' => detect_base_path(),
    'test_duration_minutes' => env_int('TEST_DURATION_MINUTES', 10, 1),
    'essay_duration_minutes' => env_int('ESSAY_DURATION_MINUTES', 15, 1),
    'min_completion_ratio' => max(0.1, min(1.0, env_float('MIN_COMPLETION_RATIO', 0.8))),
    'timeout_sweep_every_seconds' => env_int('TIMEOUT_SWEEP_EVERY_SECONDS', 20, 5),
    'timeout_sweep_limit' => env_int('TIMEOUT_SWEEP_LIMIT', 200, 1),
    'ai_evaluation_enabled' => strtolower((string) env('AI_EVALUATION_ENABLED', 'false')) === 'true',
    'openai_api_key' => (string) env('OPENAI_API_KEY', ''),
    'openai_model' => (string) env('OPENAI_MODEL', 'gpt-5.4'),
    'openai_timeout_seconds' => env_int('OPENAI_TIMEOUT_SECONDS', 60, 10),
    'openai_max_retries' => env_int('OPENAI_MAX_RETRIES', 2, 0),
    'auto_seed_questions' => strtolower((string) env('AUTO_SEED_QUESTIONS', 'false')) === 'true',
    'role_options' => [
        'Manager',
        'Back Office',
        'Head Kitchen',
        'Head Bar',
        'Floor Captain',
        'Cook',
        'Cook Helper',
        'Steward',
        'Mixologist',
        'Server',
        'Housekeeping',
    ],
    'db_path' => dirname(__DIR__) . '/storage/disc_app.sqlite',
    'question_sources_by_role' => [
        'Manager' => dirname(__DIR__) . '/server-beverage-cook.txt',
        'Back Office' => dirname(__DIR__) . '/server-beverage-cook.txt',
        'Head Kitchen' => dirname(__DIR__) . '/server-beverage-cook.txt',
        'Head Bar' => dirname(__DIR__) . '/server-beverage-cook.txt',
        'Floor Captain' => dirname(__DIR__) . '/server-beverage-cook.txt',
        'Cook' => dirname(__DIR__) . '/server-beverage-cook.txt',
        'Cook Helper' => dirname(__DIR__) . '/server-beverage-cook.txt',
        'Steward' => dirname(__DIR__) . '/server-beverage-cook.txt',
        'Mixologist' => dirname(__DIR__) . '/server-beverage-cook.txt',
        'Server' => dirname(__DIR__) . '/server-beverage-cook.txt',
        'Housekeeping' => dirname(__DIR__) . '/server-beverage-cook.txt',
    ],
    'hr_auth_disabled' => strtolower((string) env('HR_AUTH_DISABLED', 'false')) === 'true',
    'hr_login_email' => (string) env('HR_LOGIN_EMAIL', 'hr@disc.local'),
    'hr_password_hash' => (string) env('HR_PASSWORD_HASH', '$2b$10$txN96OIJRG.tmEToCLg/qu5.f6v.2BQx0x1pC40YSJCEHKBA2N.dy'),
    'hr_login_max_attempts' => env_int('HR_LOGIN_MAX_ATTEMPTS', 5, 1),
    'hr_login_window_sec' => env_int('HR_LOGIN_WINDOW_SEC', 900, 60),
    'hr_login_lock_sec' => env_int('HR_LOGIN_LOCK_SEC', 900, 60),
    'session_name' => (string) env('SESSION_NAME', 'disc_php_session'),
    'session_secure' => strtolower((string) env('SESSION_SECURE', 'false')) === 'true',
    'session_samesite' => (string) env('SESSION_SAMESITE', 'Lax'),
    'cookie_secure' => strtolower((string) env('COOKIE_SECURE', 'false')) === 'true',
    'cookie_samesite' => (string) env('COOKIE_SAMESITE', 'Lax'),
    'csp_enabled' => strtolower((string) env('CSP_ENABLED', 'false')) === 'true',
];
