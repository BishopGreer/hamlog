<?php
require_once __DIR__ . '/../config/database.php';

function session_start_hamlog(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
    }
}

function current_user(): ?array {
    session_start_hamlog();
    if (empty($_SESSION['user_id'])) return null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$_SESSION['user_id']]);
    return $st->fetch() ?: null;
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    return $user;
}

function require_admin(): array {
    $user = require_login();
    if (!$user['is_admin']) {
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }
    return $user;
}

function login(string $username, string $password): bool {
    $st = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ?');
    $st->execute([$username, $username]);
    $user = $st->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        session_start_hamlog();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        return true;
    }
    return false;
}

function logout(): void {
    session_start_hamlog();
    $_SESSION = [];
    session_destroy();
}

function register_user(string $username, string $email, string $password, string $callsign = ''): int|false {
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo  = db();
    // First user becomes admin
    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $is_admin = $count === 0 ? 1 : 0;
    try {
        $st = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, callsign, is_admin) VALUES (?,?,?,?,?)'
        );
        $st->execute([$username, $email, $hash, strtoupper($callsign), $is_admin]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException) {
        return false;
    }
}

function user_can_access_station(int $user_id, int $station_id): bool {
    $st = db()->prepare(
        'SELECT id FROM stations WHERE id = ? AND owner_id = ?
         UNION
         SELECT station_id FROM club_members WHERE station_id = ? AND user_id = ?'
    );
    $st->execute([$station_id, $user_id, $station_id, $user_id]);
    return (bool)$st->fetch();
}

function get_user_stations(int $user_id): array {
    $pdo = db();
    // Personal stations
    $st = $pdo->prepare('SELECT *, "owner" as role FROM stations WHERE owner_id = ? ORDER BY callsign');
    $st->execute([$user_id]);
    $own = $st->fetchAll();
    // Club stations the user is a member of (not owner)
    $st = $pdo->prepare(
        'SELECT s.*, cm.role FROM stations s
         JOIN club_members cm ON cm.station_id = s.id
         WHERE cm.user_id = ? AND s.owner_id != ?
         ORDER BY s.callsign'
    );
    $st->execute([$user_id, $user_id]);
    $club = $st->fetchAll();
    return array_merge($own, $club);
}
