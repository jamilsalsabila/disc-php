<?php

declare(strict_types=1);

function client_ip(): string
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return trim(explode(',', $ip)[0]);
}

function require_hr_auth(array $config): void
{
    if ($config['hr_auth_disabled']) {
        return;
    }

    $ok = !empty($_SESSION['hr_auth']) && $_SESSION['hr_auth'] === true;
    if (!$ok) {
        redirect(route_path('/hr/login'));
    }
}

function is_hr_authenticated(array $config): bool
{
    return $config['hr_auth_disabled'] || (!empty($_SESSION['hr_auth']) && $_SESSION['hr_auth'] === true);
}

function verify_hr_credentials(array $config, string $email, string $password): bool
{
    $emailOk = hash_equals(strtolower($config['hr_login_email']), strtolower(trim($email)));
    if (!$emailOk) {
        return false;
    }

    $hash = $config['hr_password_hash'];
    if (str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$') || str_starts_with($hash, '$2y$')) {
        return password_verify($password, $hash);
    }

    return hash_equals($hash, $password);
}
