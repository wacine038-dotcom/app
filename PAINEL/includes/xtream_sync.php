<?php
/**
 * Sincroniza expiry/status no SQLite a partir do player_api do Xtream.
 */

require_once __DIR__ . '/xtream_m3u.php';

function unipro_clients_ensure_xtream_column($conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->prepare('ALTER TABLE clients ADD COLUMN xtream_synced_at TEXT')->execute();
    } catch (Throwable $e) {
        // coluna já existe
    }
}

function xtream_derive_db_status(array $data): string {
    $expTs = $data['exp_timestamp'];
    $isUnlimited = ($data['exp_human'] === 'Ilimitado');
    $isExpired = ($expTs !== null && $expTs < time());

    $authVal = $data['auth'];
    $authOk = ($authVal === '' || $authVal === null) ? true : ((int) $authVal === 1);

    if (!$authOk) {
        return 'inactive';
    }
    if ($isUnlimited) {
        return 'active';
    }
    if ($expTs !== null) {
        return $isExpired ? 'inactive' : 'active';
    }

    return 'active';
}

/**
 * Consulta o Xtream e opcionalmente grava no cliente.
 *
 * @return array{ok:bool,updated:bool,error?:string,message?:string,id:int}
 */
function xtream_sync_client_row($conn, array $row, bool $persist): array {
    $id = (int) ($row['id'] ?? 0);
    if ($id < 1) {
        return ['ok' => false, 'updated' => false, 'error' => 'id', 'id' => $id];
    }

    $user = trim((string) ($row['username'] ?? ''));
    $pass = (string) ($row['password'] ?? '');
    $base = xtream_base_from_dns((string) ($row['dns_assigned'] ?? ''));

    if ($base === null || $user === '' || $pass === '') {
        return ['ok' => false, 'updated' => false, 'error' => 'incomplete', 'id' => $id];
    }

    $data = xtream_fetch_from_panel($base, $user, $pass);
    $now = date('Y-m-d H:i:s');

    if (!$data['ok']) {
        if ($persist) {
            $st = $conn->prepare('UPDATE clients SET xtream_synced_at = ? WHERE id = ?');
            $st->execute([$now, $id]);
        }

        return [
            'ok' => false,
            'updated' => false,
            'error' => $data['error'] ?? 'fetch',
            'message' => $data['message'] ?? '',
            'id' => $id,
        ];
    }

    $dbStatus = xtream_derive_db_status($data);
    $isUnlimited = ($data['exp_human'] === 'Ilimitado');
    $updated = false;

    if ($persist) {
        if ($data['exp_sql'] !== null) {
            $up = $conn->prepare('UPDATE clients SET expiry_date = ?, status = ?, xtream_synced_at = ? WHERE id = ?');
            $up->execute([$data['exp_sql'], $dbStatus, $now, $id]);
            $updated = true;
        } elseif ($isUnlimited) {
            $up = $conn->prepare('UPDATE clients SET status = ?, xtream_synced_at = ? WHERE id = ?');
            $up->execute([$dbStatus, $now, $id]);
            $updated = true;
        } else {
            $up = $conn->prepare('UPDATE clients SET xtream_synced_at = ? WHERE id = ?');
            $up->execute([$now, $id]);
            $updated = true;
        }
    }

    return ['ok' => true, 'updated' => $updated, 'id' => $id, 'panel' => $data];
}

function unipro_mac_accounts_ensure_xtream_column($conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $conn->prepare('ALTER TABLE client_mac_accounts ADD COLUMN xtream_synced_at TEXT')->execute();
    } catch (Throwable $e) {
    }
}

/**
 * Igual a xtream_sync_client_row, mas grava em client_mac_accounts.
 *
 * @return array{ok:bool,updated:bool,error?:string,message?:string,id:int}
 */
function xtream_sync_mac_account_row($conn, array $row, bool $persist): array {
    unipro_mac_accounts_ensure_xtream_column($conn);

    $id = (int) ($row['id'] ?? 0);
    if ($id < 1) {
        return ['ok' => false, 'updated' => false, 'error' => 'id', 'id' => $id];
    }

    $user = trim((string) ($row['username'] ?? ''));
    $pass = (string) ($row['password'] ?? '');
    $base = xtream_base_from_dns((string) ($row['dns_assigned'] ?? ''));

    if ($base === null || $user === '' || $pass === '') {
        return ['ok' => false, 'updated' => false, 'error' => 'incomplete', 'id' => $id];
    }

    $data = xtream_fetch_from_panel($base, $user, $pass);
    $now = date('Y-m-d H:i:s');

    if (!$data['ok']) {
        if ($persist) {
            $st = $conn->prepare('UPDATE client_mac_accounts SET xtream_synced_at = ? WHERE id = ?');
            $st->execute([$now, $id]);
        }

        return [
            'ok' => false,
            'updated' => false,
            'error' => $data['error'] ?? 'fetch',
            'message' => $data['message'] ?? '',
            'id' => $id,
        ];
    }

    $dbStatus = xtream_derive_db_status($data);
    $isUnlimited = ($data['exp_human'] === 'Ilimitado');
    $updated = false;

    if ($persist) {
        if ($data['exp_sql'] !== null) {
            $up = $conn->prepare('UPDATE client_mac_accounts SET expiry_date = ?, status = ?, xtream_synced_at = ? WHERE id = ?');
            $up->execute([$data['exp_sql'], $dbStatus, $now, $id]);
            $updated = true;
        } elseif ($isUnlimited) {
            $up = $conn->prepare('UPDATE client_mac_accounts SET status = ?, xtream_synced_at = ? WHERE id = ?');
            $up->execute([$dbStatus, $now, $id]);
            $updated = true;
        } else {
            $up = $conn->prepare('UPDATE client_mac_accounts SET xtream_synced_at = ? WHERE id = ?');
            $up->execute([$now, $id]);
            $updated = true;
        }
    }

    return ['ok' => true, 'updated' => $updated, 'id' => $id, 'panel' => $data];
}

/**
 * Atualiza até $limit clientes cuja última sync no Xtream tem mais de $minStaleSeconds segundos.
 * (Quanto menor $minStaleSeconds, mais “ao vivo” fica a validade ao navegar no painel.)
 */
function unipro_xtream_sync_stale_clients($conn, int $limit = 40, int $minStaleSeconds = 600): int {
    if ($limit < 1) {
        return 0;
    }

    unipro_mac_accounts_ensure_xtream_column($conn);

    $secs = max(0, (int) $minStaleSeconds);

    $sql = "SELECT id, mac, username, password, dns_assigned, status, expiry_date, xtream_synced_at
            FROM client_mac_accounts
            WHERE TRIM(COALESCE(username,'')) != ''
              AND TRIM(COALESCE(password,'')) != ''
              AND TRIM(COALESCE(dns_assigned,'')) != ''
              AND (
                    xtream_synced_at IS NULL
                 OR TRIM(xtream_synced_at) = ''
                 OR (strftime('%s','now') - COALESCE(strftime('%s', xtream_synced_at), 0)) > {$secs}
              )
            ORDER BY
              CASE WHEN xtream_synced_at IS NULL OR TRIM(xtream_synced_at) = '' THEN 0 ELSE 1 END,
              datetime(COALESCE(xtream_synced_at, '1970-01-01'))
            LIMIT " . (int) $limit;

    $res = $conn->query($sql);
    if (!$res) {
        return 0;
    }

    $synced = 0;
    while ($row = $res->fetch_assoc()) {
        $r = xtream_sync_mac_account_row($conn, $row, true);
        if ($r['ok'] || !empty($r['error'])) {
            $synced++;
        }
    }

    return $synced;
}

/**
 * Sincroniza todos os clientes elegíveis com player_api (mesma lógica do Info / ajax_xtream).
 *
 * @return array{panel_ok:int,panel_fail:int,skipped:int}
 */
function unipro_xtream_sync_all_clients_now($conn): array {
    unipro_mac_accounts_ensure_xtream_column($conn);

    $sql = "SELECT id, mac, username, password, dns_assigned, status, expiry_date, xtream_synced_at
            FROM client_mac_accounts
            ORDER BY id DESC";

    $res = $conn->query($sql);
    if (!$res) {
        return ['panel_ok' => 0, 'panel_fail' => 0, 'skipped' => 0];
    }

    $panel_ok = 0;
    $panel_fail = 0;
    $skipped = 0;

    while ($row = $res->fetch_assoc()) {
        $user = trim((string) ($row['username'] ?? ''));
        $pass = (string) ($row['password'] ?? '');
        $base = xtream_base_from_dns((string) ($row['dns_assigned'] ?? ''));

        if ($base === null || $user === '' || $pass === '') {
            $skipped++;
            continue;
        }

        $r = xtream_sync_mac_account_row($conn, $row, true);
        if ($r['ok']) {
            $panel_ok++;
        } else {
            $panel_fail++;
        }
    }

    return [
        'panel_ok' => $panel_ok,
        'panel_fail' => $panel_fail,
        'skipped' => $skipped,
    ];
}
