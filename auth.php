<?php
/*
 * Session helpers.
 *
 * To protect a page, put this at the very top (before any output):
 *     require_once "auth.php";
 *     require_login();
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

const IDLE_TIMEOUT_SECONDS = 1800; // sign out after 30 minutes of inactivity

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function destroy_session(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function require_login(): void {
    if (is_logged_in()
        && time() - (int)($_SESSION['last_active'] ?? 0) > IDLE_TIMEOUT_SECONDS) {
        destroy_session();
        header('Location: login.php?timeout=1');
        exit;
    }

    if (!is_logged_in()) {
        $next = urlencode(basename($_SERVER['REQUEST_URI'] ?? 'dashboard.php'));
        header('Location: login.php?next=' . $next);
        exit;
    }

    $_SESSION['last_active'] = time();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $token): bool {
    return is_string($token)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}

function current_user_name(): string {
    return (string)($_SESSION['full_name'] ?: ($_SESSION['username'] ?? ''));
}