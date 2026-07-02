<?php
// QRZ XML Logbook Data Service — session management and callsign lookup.
// Requires an active QRZ Logbook Data subscription for full field access.
// Spec: https://www.qrz.com/page/current_spec.html

function qrz_configured(): bool {
    return (bool)(db_setting('qrz_username') && db_setting('qrz_password'));
}

function qrz_session_status(): array {
    return [
        'key'    => db_setting('qrz_session_key'),
        'age'    => (int)db_setting('qrz_session_time'),
        'sub'    => db_setting('qrz_sub_exp'),
        'error'  => db_setting('qrz_session_error'),
        'fresh'  => (bool)(db_setting('qrz_session_key') && (time() - (int)db_setting('qrz_session_time')) < 43200),
    ];
}

function qrz_get_session(PDO $pdo): string {
    $st = qrz_session_status();
    if ($st['fresh']) return $st['key'];
    return qrz_login($pdo);
}

function qrz_login(PDO $pdo): string {
    $user = db_setting('qrz_username');
    $pass = db_setting('qrz_password');
    if (!$user || !$pass) return '';

    $url = 'https://xmldata.qrz.com/xml/current/'
         . '?username=' . urlencode($user)
         . '&password=' . urlencode($pass)
         . '&agent=HamLog%2F' . HAMLOG_VERSION;

    $resp = qrz_http_get($url);
    if ($resp === false) {
        qrz_save_setting($pdo, 'qrz_session_error', 'Could not reach xmldata.qrz.com');
        return '';
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($resp);

    if (!$xml || !isset($xml->Session)) {
        qrz_save_setting($pdo, 'qrz_session_error', 'Invalid XML response from QRZ');
        return '';
    }

    if (isset($xml->Session->Error) && !isset($xml->Session->Key)) {
        qrz_save_setting($pdo, 'qrz_session_error', (string)$xml->Session->Error);
        qrz_save_setting($pdo, 'qrz_session_key', '');
        return '';
    }

    $key = (string)($xml->Session->Key ?? '');
    if (!$key) {
        qrz_save_setting($pdo, 'qrz_session_error', 'No session key returned — check credentials');
        return '';
    }

    qrz_save_setting($pdo, 'qrz_session_key',   $key);
    qrz_save_setting($pdo, 'qrz_session_time',  (string)time());
    qrz_save_setting($pdo, 'qrz_session_error', '');
    qrz_save_setting($pdo, 'qrz_sub_exp',       (string)($xml->Session->SubExp ?? ''));
    return $key;
}

// Returns array on success, null if not found / error, 'timeout' string on session expiry.
function qrz_fetch_call(string $call, string $session): array|string|null {
    $url = 'https://xmldata.qrz.com/xml/current/'
         . '?s='        . urlencode($session)
         . '&callsign=' . urlencode($call);

    $resp = qrz_http_get($url);
    if ($resp === false) return null;

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($resp);
    if (!$xml) return null;

    // Session expired: error present and no Key in response
    if (isset($xml->Session->Error) && !isset($xml->Session->Key)) {
        return 'timeout';
    }

    if (!isset($xml->Callsign)) return null;

    $c     = $xml->Callsign;
    $fname = trim((string)($c->fname ?? ''));
    $lname = trim((string)($c->name  ?? ''));
    $name  = trim("$fname $lname") ?: null;

    return [
        'call'    => strtoupper((string)($c->call ?? $call)),
        'name'    => $name,
        'qth'     => (string)($c->addr2 ?? '') ?: null,
        'country' => (string)($c->country ?? '') ?: null,
        'grid'    => strtoupper((string)($c->grid ?? '')) ?: null,
        'dxcc'    => isset($c->dxcc)    ? (int)$c->dxcc    : null,
        'cqz'     => isset($c->cqzone)  ? (int)$c->cqzone  : null,
        'ituz'    => isset($c->ituzone) ? (int)$c->ituzone : null,
        'cont'    => (string)($c->continent ?? '') ?: null,
        'source'  => 'qrz',
    ];
}

function qrz_lookup(string $call, PDO $pdo): ?array {
    $session = qrz_get_session($pdo);
    if (!$session) return null;

    $result = qrz_fetch_call($call, $session);

    if ($result === 'timeout') {
        // Session expired mid-use — clear and re-login once
        qrz_save_setting($pdo, 'qrz_session_key',  '');
        qrz_save_setting($pdo, 'qrz_session_time', '0');
        $session = qrz_login($pdo);
        if (!$session) return null;
        $result = qrz_fetch_call($call, $session);
    }

    return is_array($result) ? $result : null;
}

function qrz_http_get(string $url): string|false {
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => 10,
        'ignore_errors' => true,
        'header'        => 'User-Agent: HamLog/' . HAMLOG_VERSION . "\r\n",
    ]]);
    return @file_get_contents($url, false, $ctx);
}

function qrz_save_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare(
        'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?'
    )->execute([$key, $value, $value]);
}
