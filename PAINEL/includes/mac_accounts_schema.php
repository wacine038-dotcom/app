<?php
/**
 * Várias listas (usuário/DNS) por mesmo MAC.
 */
function unipro_ensure_mac_accounts_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS client_mac_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        mac TEXT NOT NULL,
        username TEXT NOT NULL DEFAULT '',
        password TEXT NOT NULL DEFAULT '',
        dns_assigned TEXT NOT NULL DEFAULT '',
        expiry_date TEXT,
        status TEXT DEFAULT 'active',
        is_auto INTEGER DEFAULT 0,
        renew_url TEXT,
        display_name TEXT,
        xtream_synced_at TEXT
    )");

    try {
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_mac_accounts_mac_user_dns
            ON client_mac_accounts (mac, username, dns_assigned)');
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("INSERT OR IGNORE INTO client_mac_accounts (mac, username, password, dns_assigned, expiry_date, status, is_auto, renew_url)
            SELECT mac, username, password, dns_assigned, expiry_date, status, COALESCE(is_auto, 0), COALESCE(renew_url, '') FROM clients");
    } catch (Throwable $e) {
    }

    static $xtCol = false;
    if (!$xtCol) {
        $xtCol = true;
        try {
            $pdo->exec('ALTER TABLE client_mac_accounts ADD COLUMN xtream_synced_at TEXT');
        } catch (Throwable $e) {
        }
    }

    static $macNormMig = false;
    if (!$macNormMig) {
        $macNormMig = true;
        try {
            unipro_migrate_mac_columns_to_normalized($pdo);
        } catch (Throwable $e) {
        }
    }
}

function unipro_normalize_dns(?string $dns): string {
    $d = trim((string) $dns);

    return rtrim($d, '/');
}

/**
 * Mesmo endereço físico no app (AA:BB:… maiúsculo) e no painel (aa:bb… minúsculo).
 * SQLite compara TEXT de forma sensível a maiúsculas — sempre gravar e buscar com este formato.
 */
function unipro_normalize_mac(?string $mac): string {
    $raw = trim((string) $mac);
    if ($raw === '') {
        return '';
    }
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', $raw);
    if (strlen($hex) === 12) {
        return strtolower(implode(':', str_split($hex, 2)));
    }

    return strtolower($raw);
}

/** Corrige MACs antigos (ex.: maiúsculas) para o formato único de [unipro_normalize_mac]. */
function unipro_migrate_mac_columns_to_normalized(PDO $pdo): void {
    foreach (['client_mac_accounts', 'clients'] as $table) {
        try {
            $st = $pdo->query('SELECT id, mac FROM ' . $table);
            if (!$st) {
                continue;
            }
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $old = (string) ($row['mac'] ?? '');
                $n = unipro_normalize_mac($old);
                if ($n !== '' && $n !== $old) {
                    $u = $pdo->prepare('UPDATE ' . $table . ' SET mac = ? WHERE id = ?');
                    $u->execute([$n, (int) $row['id']]);
                }
            }
        } catch (Throwable $e) {
        }
    }
}

/**
 * Mantém a linha em clients espelhando a lista mais antiga (id menor) do MAC, ou remove clients se não houver listas.
 *
 * @param object $conn SQLiteCompat (prepare/fetch como PDOStatement)
 */
function unipro_reconcile_clients_for_mac($conn, string $mac): void {
    $stmt = $conn->prepare('SELECT username, password, expiry_date, dns_assigned, status, is_auto, renew_url FROM client_mac_accounts WHERE mac = ? ORDER BY id ASC LIMIT 1');
    $stmt->execute([$mac]);
    $first = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$first) {
        $d = $conn->prepare('DELETE FROM clients WHERE mac = ?');
        $d->execute([$mac]);

        return;
    }
    $chk = $conn->prepare('SELECT id FROM clients WHERE mac = ?');
    $chk->execute([$mac]);
    $hasClient = $chk->fetch(PDO::FETCH_ASSOC);
    if ($hasClient) {
        $up = $conn->prepare('UPDATE clients SET username = ?, password = ?, expiry_date = ?, dns_assigned = ?, status = ?, is_auto = ?, renew_url = ? WHERE mac = ?');
        $up->execute([
            $first['username'],
            $first['password'],
            $first['expiry_date'],
            $first['dns_assigned'],
            $first['status'],
            $first['is_auto'] ?? 0,
            $first['renew_url'] ?? '',
            $mac,
        ]);
    } else {
        $ins = $conn->prepare('INSERT INTO clients (mac, username, password, expiry_date, dns_assigned, status, is_auto, renew_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([
            $mac,
            $first['username'],
            $first['password'],
            $first['expiry_date'],
            $first['dns_assigned'],
            $first['status'],
            $first['is_auto'] ?? 0,
            $first['renew_url'] ?? '',
        ]);
    }
}

/** Após exclusões em massa em client_mac_accounts, remove clients órfãos e reespelha demais MACs. */
function unipro_reconcile_all_clients_from_mac_accounts($conn): void {
    $res = $conn->query('SELECT DISTINCT mac FROM client_mac_accounts');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (!empty($row['mac'])) {
                unipro_reconcile_clients_for_mac($conn, (string) $row['mac']);
            }
        }
    }
    $res = $conn->query('SELECT mac FROM clients');
    if ($res) {
        while ($c = $res->fetch_assoc()) {
            $mac = (string) ($c['mac'] ?? '');
            if ($mac === '') {
                continue;
            }
            $st = $conn->prepare('SELECT 1 FROM client_mac_accounts WHERE mac = ? LIMIT 1');
            $st->execute([$mac]);
            if (!$st->fetch(PDO::FETCH_ASSOC)) {
                $del = $conn->prepare('DELETE FROM clients WHERE mac = ?');
                $del->execute([$mac]);
            }
        }
    }
}
