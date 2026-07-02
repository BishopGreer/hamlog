<?php
// HamQTH XML API — session management and callsign lookup.
// https://www.hamqth.com/developers.php
// Auth: username + password → session_id (valid ~1 hour).

function hamqth_configured(): bool {
    return (bool)(db_setting('hamqth_username') && db_setting('hamqth_password'));
}

function hamqth_session_status(): array {
    return [
        'key'   => db_setting('hamqth_session_key'),
        'age'   => (int)db_setting('hamqth_session_time'),
        'error' => db_setting('hamqth_session_error'),
        'fresh' => (bool)(db_setting('hamqth_session_key') && (time() - (int)db_setting('hamqth_session_time')) < 3000),
    ];
}

function hamqth_get_session(PDO $pdo): string {
    $st = hamqth_session_status();
    if ($st['fresh']) return $st['key'];
    return hamqth_login($pdo);
}

function hamqth_login(PDO $pdo): string {
    $user = db_setting('hamqth_username');
    $pass = db_setting('hamqth_password');
    if (!$user || !$pass) return '';

    $url = 'https://www.hamqth.com/xml.php'
         . '?u=' . urlencode($user)
         . '&p=' . urlencode($pass)
         . '&prg=HamLog%2F' . HAMLOG_VERSION;

    $resp = hamqth_http_get($url);
    if ($resp === false) {
        hamqth_save_setting($pdo, 'hamqth_session_error', 'Could not reach hamqth.com');
        return '';
    }

    $xml = hamqth_parse($resp);
    if (!$xml) {
        hamqth_save_setting($pdo, 'hamqth_session_error', 'Invalid XML response from HamQTH');
        return '';
    }

    if (isset($xml->session->error)) {
        hamqth_save_setting($pdo, 'hamqth_session_error', (string)$xml->session->error);
        hamqth_save_setting($pdo, 'hamqth_session_key',   '');
        return '';
    }

    $key = (string)($xml->session->session_id ?? '');
    if (!$key) {
        hamqth_save_setting($pdo, 'hamqth_session_error', 'No session ID returned — check credentials');
        return '';
    }

    hamqth_save_setting($pdo, 'hamqth_session_key',   $key);
    hamqth_save_setting($pdo, 'hamqth_session_time',  (string)time());
    hamqth_save_setting($pdo, 'hamqth_session_error', '');
    return $key;
}

// Returns array on success, 'timeout' on session expiry, null on other failure.
function hamqth_fetch_call(string $call, string $session): array|string|null {
    $url = 'https://www.hamqth.com/xml.php'
         . '?id='       . urlencode($session)
         . '&callsign=' . urlencode($call)
         . '&prg=HamLog%2F' . HAMLOG_VERSION;

    $resp = hamqth_http_get($url);
    if ($resp === false) return null;

    $xml = hamqth_parse($resp);
    if (!$xml) return null;

    // Session error — expired or invalid
    if (isset($xml->session->error)) return 'timeout';

    // Callsign not found
    if (!isset($xml->search) || isset($xml->search->error)) return null;

    $s    = $xml->search;
    $name = trim((string)($s->adr_name ?? ''))
          ?: trim((string)($s->nick ?? ''))
          ?: null;

    return [
        'call'    => strtoupper($call),
        'name'    => $name,
        'qth'     => (string)($s->qth      ?? '') ?: null,
        'country' => (string)($s->country  ?? '') ?: null,
        'grid'    => strtoupper((string)($s->grid ?? '')) ?: null,
        'dxcc'    => isset($s->adif) ? (int)$s->adif : null,
        'cqz'     => isset($s->cq)   ? (int)$s->cq   : null,
        'ituz'    => isset($s->itu)  ? (int)$s->itu  : null,
        'cont'    => (string)($s->continent ?? '') ?: null,
        'source'  => 'hamqth',
    ];
}

function hamqth_lookup(string $call, PDO $pdo): ?array {
    $session = hamqth_get_session($pdo);
    if (!$session) return null;

    $result = hamqth_fetch_call($call, $session);

    if ($result === 'timeout') {
        hamqth_save_setting($pdo, 'hamqth_session_key',  '');
        hamqth_save_setting($pdo, 'hamqth_session_time', '0');
        $session = hamqth_login($pdo);
        if (!$session) return null;
        $result = hamqth_fetch_call($call, $session);
    }

    return is_array($result) ? $result : null;
}

// HamQTH uses xmlns="https://www.hamqth.com" as the default namespace,
// which breaks SimpleXML direct child access. Strip it before parsing.
function hamqth_parse(string $xml): SimpleXMLElement|false {
    libxml_use_internal_errors(true);
    $clean = str_replace(' xmlns="https://www.hamqth.com"', '', $xml);
    return simplexml_load_string($clean);
}

function hamqth_http_get(string $url): string|false {
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => 10,
        'ignore_errors' => true,
        'header'        => 'User-Agent: HamLog/' . HAMLOG_VERSION . "\r\n",
    ]]);
    return @file_get_contents($url, false, $ctx);
}

function hamqth_save_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare(
        'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?'
    )->execute([$key, $value, $value]);
}
