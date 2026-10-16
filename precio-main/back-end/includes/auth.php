<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit;
    }
}

function isAdmin(): bool {
    return isLoggedIn() && ($_SESSION['user_role'] ?? 'user') === 'admin';
}

function requireAdmin(): void {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit;
    }
    if (!isAdmin()) {
        header('Location: home.php');
        exit;
    }
}

function currentUser(): array|null {
    if (!isLoggedIn()) return null;
    return [
        'id'    => $_SESSION['user_id'],
        'name'  => $_SESSION['user_name'],
        'email' => $_SESSION['user_email'],
        'role'  => $_SESSION['user_role'] ?? 'user',
    ];
}

function loginUser(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role']  = $user['role'] ?? 'user';
}

function logoutUser(): void {
    $_SESSION = [];
    session_destroy();
}
