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
    if (strpos($hash, '$2a$') === 0 || strpos($hash, '$2b$') === 0 || strpos($hash, '$2y$') === 0) {
        return password_verify($password, $hash);
    }

    return hash_equals($hash, $password);
}
