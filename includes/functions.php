<?php
// Ham radio utility functions

// Standard amateur bands with frequency ranges (MHz)
const BANDS = [
    '160m' => [1.8,   2.0],
    '80m'  => [3.5,   4.0],
    '60m'  => [5.3,   5.4],
    '40m'  => [7.0,   7.3],
    '30m'  => [10.1,  10.15],
    '20m'  => [14.0,  14.35],
    '17m'  => [18.068, 18.168],
    '15m'  => [21.0,  21.45],
    '12m'  => [24.89, 24.99],
    '10m'  => [28.0,  29.7],
    '6m'   => [50.0,  54.0],
    '4m'   => [70.0,  70.5],
    '2m'   => [144.0, 148.0],
    '1.25m'=> [222.0, 225.0],
    '70cm' => [420.0, 450.0],
    '33cm' => [902.0, 928.0],
    '23cm' => [1240.0,1300.0],
];

const MODES = [
    'SSB', 'CW', 'FM', 'AM', 'RTTY', 'PSK31', 'PSK63', 'FT8', 'FT4',
    'JS8', 'WSPR', 'JT65', 'JT9', 'MSK144', 'OLIVIA', 'HELL', 'THOR',
    'CONTESTIA', 'DOMINO', 'MT63', 'MFSK', 'PACKET', 'PACTOR', 'AMTOR',
    'SSTV', 'ATV', 'FAX', 'DIGITALVOICE', 'OTHER',
];

const CONTINENTS = ['AF','AN','AS','EU','NA','OC','SA'];

function freq_to_band(float $freq): string {
    foreach (BANDS as $band => [$lo, $hi]) {
        if ($freq >= $lo && $freq <= $hi) return $band;
    }
    return 'OTHER';
}

function band_center_freq(string $band): float {
    if (!isset(BANDS[$band])) return 0.0;
    [$lo, $hi] = BANDS[$band];
    return round(($lo + $hi) / 2, 3);
}

function h(mixed $val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function flash(string $type, string $msg): void {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function get_flashes(): array {
    $flashes = $_SESSION['flash'] ?? [];
    $_SESSION['flash'] = [];
    return $flashes;
}

function redirect(string $path): never {
    header('Location: ' . BASE_URL . $path);
    exit;
}

function utc_now(): string {
    return gmdate('Y-m-d H:i:s');
}

function utc_date(): string { return gmdate('Y-m-d'); }
function utc_time(): string { return gmdate('H:i'); }

function format_utc_datetime(string $date, string $time): string {
    return date('d-M-Y H:i', strtotime("$date $time")) . 'z';
}

function dxcc_name(int $adif): string {
    static $cache = [];
    if (!isset($cache[$adif])) {
        $st = db()->prepare('SELECT name FROM dxcc_entities WHERE adif = ?');
        $st->execute([$adif]);
        $cache[$adif] = $st->fetchColumn() ?: "DXCC #$adif";
    }
    return $cache[$adif];
}

function get_logbook(int $id, int $user_id): ?array {
    $st = db()->prepare(
        'SELECT lb.*, s.callsign, s.owner_id FROM logbooks lb
         JOIN stations s ON s.id = lb.station_id
         WHERE lb.id = ?'
    );
    $st->execute([$id]);
    $lb = $st->fetch();
    if (!$lb) return null;
    if (!user_can_access_station($user_id, $lb['station_id'])) return null;
    return $lb;
}

function get_station_logbooks(int $station_id): array {
    $st = db()->prepare('SELECT * FROM logbooks WHERE station_id = ? ORDER BY is_default DESC, name');
    $st->execute([$station_id]);
    return $st->fetchAll();
}

function qso_count_for_station(int $station_id): int {
    $st = db()->prepare('SELECT COUNT(*) FROM qsos WHERE station_id = ?');
    $st->execute([$station_id]);
    return (int)$st->fetchColumn();
}

// ADIF field encoder
function adif_field(string $name, string $value): string {
    $len = strlen($value);
    return $len > 0 ? "<{$name}:{$len}>{$value} " : '';
}

// Build an ADIF export from a list of QSOs
function export_adif(array $qsos, string $station_call): string {
    $lines = [];
    $lines[] = 'ADIF Export from HamLog ' . HAMLOG_VERSION;
    $lines[] = 'Station: ' . $station_call;
    $lines[] = 'Date: ' . gmdate('Y-m-d H:i:s') . ' UTC';
    $lines[] = '<EOH>';
    $lines[] = '';
    foreach ($qsos as $q) {
        $record = '';
        $record .= adif_field('CALL',       $q['call']);
        $record .= adif_field('QSO_DATE',   str_replace('-', '', $q['date_on']));
        $record .= adif_field('TIME_ON',    str_replace(':', '', substr($q['time_on'], 0, 5)));
        $record .= adif_field('BAND',       strtoupper($q['band'] ?? ''));
        if (!empty($q['freq']))    $record .= adif_field('FREQ', (string)$q['freq']);
        $record .= adif_field('MODE',       $q['mode']);
        if (!empty($q['submode'])) $record .= adif_field('SUBMODE', $q['submode']);
        if (!empty($q['rst_sent'])) $record .= adif_field('RST_SENT', $q['rst_sent']);
        if (!empty($q['rst_rcvd'])) $record .= adif_field('RST_RCVD', $q['rst_rcvd']);
        if (!empty($q['name']))    $record .= adif_field('NAME', $q['name']);
        if (!empty($q['qth']))     $record .= adif_field('QTH', $q['qth']);
        if (!empty($q['gridsquare'])) $record .= adif_field('GRIDSQUARE', $q['gridsquare']);
        if (!empty($q['country'])) $record .= adif_field('COUNTRY', $q['country']);
        if (!empty($q['dxcc']))    $record .= adif_field('DXCC', (string)$q['dxcc']);
        if (!empty($q['cont']))    $record .= adif_field('CONT', $q['cont']);
        if (!empty($q['cqz']))     $record .= adif_field('CQZ', (string)$q['cqz']);
        if (!empty($q['ituz']))    $record .= adif_field('ITUZ', (string)$q['ituz']);
        if (!empty($q['iota']))    $record .= adif_field('IOTA', $q['iota']);
        if (!empty($q['tx_pwr'])) $record .= adif_field('TX_PWR', (string)$q['tx_pwr']);
        if (!empty($q['comment'])) $record .= adif_field('COMMENT', $q['comment']);
        if (!empty($q['notes']))   $record .= adif_field('NOTES', $q['notes']);
        $record .= adif_field('STATION_CALLSIGN', $station_call);
        $record .= adif_field('LOTW_QSL_SENT', $q['lotw_qsl_sent']);
        $record .= adif_field('LOTW_QSL_RCVD', $q['lotw_qsl_rcvd']);
        $record .= adif_field('EQSL_QSL_SENT', $q['eqsl_qsl_sent']);
        $record .= adif_field('EQSL_QSL_RCVD', $q['eqsl_qsl_rcvd']);
        $record .= adif_field('QSL_SENT', $q['qsl_sent']);
        $record .= adif_field('QSL_RCVD', $q['qsl_rcvd']);
        $record .= '<EOR>';
        $lines[] = $record;
    }
    return implode("\n", $lines) . "\n";
}

// Parse an ADIF string and return an array of field arrays
function parse_adif(string $content): array {
    $qsos = [];
    // Skip header (everything before <EOH>)
    $eoh = stripos($content, '<eoh>');
    if ($eoh !== false) {
        $content = substr($content, $eoh + 5);
    }
    // Split on <EOR>
    $records = preg_split('/<eor>/i', $content);
    foreach ($records as $record) {
        $record = trim($record);
        if (empty($record)) continue;
        $fields = [];
        preg_match_all('/<([^:>]+)(?::(\d+)(?::[^>]*)?)?>([^<]*)/i', $record, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $name  = strtolower($match[1]);
            $value = isset($match[2]) && $match[2] !== ''
                ? substr($match[3], 0, (int)$match[2])
                : $match[3];
            $fields[$name] = trim($value);
        }
        if (!empty($fields['call'])) {
            $qsos[] = $fields;
        }
    }
    return $qsos;
}

// Convert an ADIF record array to a qsos-table row
function adif_to_qso(array $f): array {
    $date_raw = $f['qso_date'] ?? '';
    $date = strlen($date_raw) === 8
        ? substr($date_raw, 0, 4) . '-' . substr($date_raw, 4, 2) . '-' . substr($date_raw, 6, 2)
        : $date_raw;
    $time_raw = str_pad($f['time_on'] ?? '0000', 4, '0', STR_PAD_LEFT);
    $time = substr($time_raw, 0, 2) . ':' . substr($time_raw, 2, 2) . ':00';
    $freq = isset($f['freq']) ? (float)$f['freq'] : null;
    $band = $f['band'] ?? ($freq ? freq_to_band($freq) : null);
    return [
        'call'          => strtoupper($f['call'] ?? ''),
        'date_on'       => $date,
        'time_on'       => $time,
        'band'          => $band ? strtolower($band) : null,
        'freq'          => $freq,
        'mode'          => strtoupper($f['mode'] ?? 'SSB'),
        'submode'       => strtoupper($f['submode'] ?? '') ?: null,
        'rst_sent'      => $f['rst_sent'] ?? '59',
        'rst_rcvd'      => $f['rst_rcvd'] ?? '59',
        'name'          => $f['name'] ?? null,
        'qth'           => $f['qth'] ?? null,
        'gridsquare'    => strtoupper($f['gridsquare'] ?? '') ?: null,
        'dxcc'          => isset($f['dxcc']) ? (int)$f['dxcc'] : null,
        'country'       => $f['country'] ?? null,
        'cont'          => strtoupper($f['cont'] ?? '') ?: null,
        'ituz'          => isset($f['ituz']) ? (int)$f['ituz'] : null,
        'cqz'           => isset($f['cqz'])  ? (int)$f['cqz']  : null,
        'iota'          => $f['iota'] ?? null,
        'tx_pwr'        => isset($f['tx_pwr']) ? (float)$f['tx_pwr'] : null,
        'comment'       => $f['comment'] ?? null,
        'notes'         => $f['notes'] ?? null,
        'lotw_qsl_sent' => strtoupper($f['lotw_qsl_sent'] ?? 'N'),
        'lotw_qsl_rcvd' => strtoupper($f['lotw_qsl_rcvd'] ?? 'N'),
        'eqsl_qsl_sent' => strtoupper($f['eqsl_qsl_sent'] ?? 'N'),
        'eqsl_qsl_rcvd' => strtoupper($f['eqsl_qsl_rcvd'] ?? 'N'),
        'qsl_sent'      => strtoupper($f['qsl_sent'] ?? 'N'),
        'qsl_rcvd'      => strtoupper($f['qsl_rcvd'] ?? 'N'),
    ];
}

function is_duplicate_qso(int $station_id, string $call, string $date, string $time, string $band, string $mode): bool {
    $st = db()->prepare(
        'SELECT id FROM qsos
         WHERE station_id = ? AND `call` = ? AND date_on = ? AND band = ? AND mode = ?
         AND ABS(TIME_TO_SEC(TIMEDIFF(time_on, ?))) < 120
         LIMIT 1'
    );
    $st->execute([$station_id, $call, $date, $band, $mode, $time]);
    return (bool)$st->fetch();
}
