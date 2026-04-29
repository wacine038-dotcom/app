<?php
/**
 * Sincroniza uma linha de client_mac_accounts com Sigma (mesma lógica que clients, mas por id).
 *
 * @param array<string,mixed> $row
 */
function unipro_sigma_sync_mac_account_row_api($conn, array &$row): void {
    $accId = (int) ($row['id'] ?? 0);
    if ($accId < 1) {
        return;
    }

    $current_user = trim((string) ($row['username'] ?? ''));
    if ($current_user === '') {
        return;
    }

    $stmtBot = $conn->prepare("SELECT api_url, webhook_token FROM chatbot_config WHERE id = 1");
    $stmtBot->execute();
    $bot = $stmtBot->fetch();
    $token = trim((string) ($bot['webhook_token'] ?? ''));

    if (!$bot || empty($bot['api_url']) || $token === '') {
        return;
    }

    $json_sigma = null;
    $curlErr = 0;
    $httpCode = 0;

    $r = sigma_fetch_customer_for_sync($bot['api_url'], $token, $current_user);
    $curlErr = $r['errno'];
    $httpCode = $r['http'];
    $json_sigma = $r['json'];

    if ($curlErr !== 0) {
        return;
    }

    if ($httpCode === 404 || $httpCode === 401) {
        $up = $conn->prepare("UPDATE client_mac_accounts SET status = 'inactive' WHERE id = ?");
        $up->execute([$accId]);
        $row['status'] = 'inactive';

        return;
    }

    if (!is_array($json_sigma)) {
        return;
    }

    $gone = isset($json_sigma['error']) && $json_sigma['error'] !== '' && $json_sigma['error'] !== null;
    $parsed = sigma_parse_customer_payload($json_sigma);

    if ($gone && !empty($row['is_auto'])) {
        $up = $conn->prepare("UPDATE client_mac_accounts SET status = 'inactive' WHERE id = ?");
        $up->execute([$accId]);
        $row['status'] = 'inactive';
    } elseif ($parsed['expiry'] !== null) {
        $new_expiry = $parsed['expiry'];
        $new_ts = strtotime($new_expiry);
        if ($parsed['status'] === 'inactive') {
            $new_status = 'inactive';
        } elseif ($parsed['status'] === 'active') {
            $new_status = 'active';
        } else {
            $new_status = ($new_ts !== false && $new_ts >= time()) ? 'active' : 'inactive';
        }
        $sqlUp = "UPDATE client_mac_accounts SET expiry_date = ?, status = ? WHERE id = ?";
        $params = [$new_expiry, $new_status, $accId];
        if ($parsed['password'] !== null && $parsed['password'] !== '') {
            $sqlUp = "UPDATE client_mac_accounts SET expiry_date = ?, status = ?, password = ? WHERE id = ?";
            $params = [$new_expiry, $new_status, $parsed['password'], $accId];
        }
        $up = $conn->prepare($sqlUp);
        $up->execute($params);
        $row['expiry_date'] = $new_expiry;
        $row['status'] = $new_status;
        if ($parsed['password'] !== null && $parsed['password'] !== '') {
            $row['password'] = $parsed['password'];
        }
    } elseif (!empty($row['is_auto'])) {
        $sigma_says_gone = $json_sigma === []
            || $gone
            || (isset($json_sigma['active']) && $json_sigma['active'] === false)
            || (isset($json_sigma['success']) && $json_sigma['success'] === false)
            || ($token !== '' && $httpCode >= 400 && $httpCode !== 404);
        if ($sigma_says_gone) {
            $up = $conn->prepare("UPDATE client_mac_accounts SET status = 'inactive' WHERE id = ?");
            $up->execute([$accId]);
            $row['status'] = 'inactive';
        }
    }
}
