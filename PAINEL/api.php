<?php
// unit.x-ui.shop - api.php (Versão com Auto-Sincronização de Data e Status)

ini_set('display_errors', 0); 
error_reporting(E_ALL);

include 'db.php';
require_once __DIR__ . '/includes/events_url_normalize.php';
require_once __DIR__ . '/includes/sigma_webhook.php';
require_once __DIR__ . '/includes/api_mac_sigma_sync.php';
unipro_chatbot_ensure_schema($conn);
header('Content-Type: application/json; charset=utf-8');

// =======================
// RATE LIMIT SUAVE
// =======================
$ip = $_SERVER['REMOTE_ADDR'];
$action = $_GET['action'] ?? '';
// Por ação: evita bloquear check_mac logo após get_config (muitos apps chamam os dois seguidos)
$rateFile = sys_get_temp_dir() . "/api_" . md5($ip . '|' . $action);
$now = microtime(true);
$last = file_exists($rateFile) ? (float) file_get_contents($rateFile) : 0;
$minInterval = ($action === 'check_mac') ? 0.08 : 0.05;
if (($now - $last) < $minInterval) {
    echo json_encode(["error" => "slow down"]);
    exit;
}
file_put_contents($rateFile, $now);

// =======================
// GET CONFIG
// =======================
if ($action == 'get_config') {
    header("Cache-Control: public, max-age=60");
    $stmt = $conn->prepare("SELECT dns_list, app_logo, app_background, message_global, support_whatsapp, events_url, news_banner_url, apk_version_code, apk_version_name, apk_download_url FROM config LIMIT 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$config) {
        $config = [];
    }

    $apkCode = isset($config['apk_version_code']) ? (int) $config['apk_version_code'] : 0;
    $apkUrl = trim((string) ($config['apk_download_url'] ?? ''));
    $apkName = (string) ($config['apk_version_name'] ?? '');

    $dnsArr = ($config && !empty($config['dns_list']))
        ? array_values(array_filter(array_map('trim', explode(',', $config['dns_list'])), static function ($s) {
            return $s !== '';
        }))
        : [];

    $xui_dns = [];
    foreach ($dnsArr as $i => $d) {
        $xui_dns[] = [
            'dns_base' => $d,
            'dns_title' => 'DNS ' . ($i + 1),
        ];
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/api.php';
    $absoluteCfg = ($host !== '') ? ($scheme . '://' . $host . $script . '?action=get_config') : '';

    echo json_encode([
        "dns" => $dnsArr,
        "xui_dns" => $xui_dns,
        "panel_get_config_url" => $absoluteCfg,
        "logo" => $config['app_logo'] ?? "",
        "bg_img" => $config['app_background'] ?? "",
        "message" => $config['message_global'] ?? "",
        "suporte" => $config['support_whatsapp'] ?? "",
        "app_logo" => $config['app_logo'] ?? "",
        "app_background" => $config['app_background'] ?? "",
        "message_global" => $config['message_global'] ?? "",
        "support_whatsapp" => $config['support_whatsapp'] ?? "",
        "events_url" => unipro_normalize_events_url_string($config['events_url'] ?? ''),
        "news_banner_url" => $config['news_banner_url'] ?? "",
        "apk_version_code" => $apkCode,
        "apk_version_name" => $apkName,
        "apk_download_url" => $apkUrl,
        "status" => "success",
    ]);
    exit;
}

// =======================
// CHECK MAC (COM SINCRONIZAÇÃO)
// =======================
if ($action == 'check_mac') {
    $mac = unipro_normalize_mac(isset($_GET['mac']) ? trim((string) $_GET['mac']) : '');

    if ($mac === '') {
        echo json_encode(["active" => false, "message" => "MAC vazio"]);
        exit;
    }

    $stmtCfgEv = $conn->prepare('SELECT events_url FROM config WHERE id = 1 LIMIT 1');
    $stmtCfgEv->execute();
    $cfgEvRow = $stmtCfgEv->fetch(PDO::FETCH_ASSOC);
    $events_url_for_mac = unipro_normalize_events_url_string($cfgEvRow['events_url'] ?? '');

    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    // Várias listas por MAC (client_mac_accounts)
    $stmtAcc = $conn->prepare("SELECT * FROM client_mac_accounts WHERE mac = ? ORDER BY id ASC");
    $stmtAcc->execute([$mac]);
    $accountRows = $stmtAcc->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($accountRows)) {
        foreach ($accountRows as &$accRow) {
            unipro_sigma_sync_mac_account_row_api($conn, $accRow);
        }
        unset($accRow);

        $stmtAcc2 = $conn->prepare("SELECT * FROM client_mac_accounts WHERE mac = ? ORDER BY id ASC");
        $stmtAcc2->execute([$mac]);
        $accountRows = $stmtAcc2->fetchAll(PDO::FETCH_ASSOC);

        $profiles = [];
        $anyActive = false;
        $firstUser = '';
        $firstPass = '';
        $firstDns = '';
        $message = 'Acesso liberado';

        foreach ($accountRows as $r) {
            $exp_ts = strtotime($r['expiry_date'] ?? '');
            $is_expired = ($exp_ts === false) ? true : ($exp_ts < time());
            $isAct = ($r['status'] == 'active' && !$is_expired);
            if ($isAct) {
                $anyActive = true;
            }
            $lbl = trim((string) ($r['display_name'] ?? ''));
            if ($lbl === '') {
                $lbl = (string) ($r['username'] ?? '');
            }
            $profiles[] = [
                'username' => $r['username'] ?? '',
                'password' => $r['password'] ?? '',
                'dns' => $r['dns_assigned'] ?: '',
                'label' => $lbl,
                'active' => $isAct,
            ];
        }

        foreach ($accountRows as $r) {
            $exp_ts = strtotime($r['expiry_date'] ?? '');
            $is_expired = ($exp_ts === false) ? true : ($exp_ts < time());
            if ($r['status'] == 'active' && !$is_expired) {
                $firstUser = $r['username'] ?? '';
                $firstPass = $r['password'] ?? '';
                $firstDns = $r['dns_assigned'] ?: '';
                break;
            }
        }

        if (!$anyActive) {
            $message = 'Nenhuma lista ativa para este MAC';
            foreach ($accountRows as $r) {
                $exp_ts = strtotime($r['expiry_date'] ?? '');
                if ($exp_ts !== false && $exp_ts < time()) {
                    $message = 'Vencido em: ' . date('d/m/Y', $exp_ts);
                    break;
                }
            }
        }

        echo json_encode([
            'active' => $anyActive,
            'username' => $firstUser,
            'password' => $firstPass,
            'dns' => $firstDns,
            'profiles' => $profiles,
            'message' => $message,
            'events_url' => $events_url_for_mac,
        ]);
        exit;
    }

    // Legado: apenas tabela clients (sem linhas em client_mac_accounts)
    $stmt = $conn->prepare("SELECT * FROM clients WHERE mac = ?");
    $stmt->execute([$mac]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $current_user = $row['username'];

        if (!empty($current_user)) {
            $stmtBot = $conn->prepare("SELECT api_url, webhook_token FROM chatbot_config WHERE id = 1");
            $stmtBot->execute();
            $bot = $stmtBot->fetch();
            $token = trim((string) ($bot['webhook_token'] ?? ''));

            // Só sincroniza fora do SQLite com API Sigma (Bearer + GET). Chatbot genérico costuma ser só "criar teste":
            // um POST extra com username devolve [] ou HTML e o código marcava is_auto como inativo — app perdia login.
            if ($bot && !empty($bot['api_url']) && $token !== '') {
                $json_sigma = null;
                $curlErr = 0;
                $httpCode = 0;

                $r = sigma_fetch_customer_for_sync($bot['api_url'], $token, $current_user);
                $curlErr = $r['errno'];
                $httpCode = $r['http'];
                $json_sigma = $r['json'];

                if ($curlErr === 0) {
                    if ($httpCode === 404 || $httpCode === 401) {
                        $up = $conn->prepare("UPDATE clients SET status = 'inactive' WHERE mac = ?");
                        $up->execute([$mac]);
                        $row['status'] = 'inactive';
                    } elseif (is_array($json_sigma)) {
                        $gone = isset($json_sigma['error']) && $json_sigma['error'] !== '' && $json_sigma['error'] !== null;
                        $parsed = sigma_parse_customer_payload($json_sigma);

                        if ($gone && !empty($row['is_auto'])) {
                            $up = $conn->prepare("UPDATE clients SET status = 'inactive' WHERE mac = ?");
                            $up->execute([$mac]);
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
                            $sqlUp = "UPDATE clients SET expiry_date = ?, status = ? WHERE mac = ?";
                            $params = [$new_expiry, $new_status, $mac];
                            if ($parsed['password'] !== null && $parsed['password'] !== '') {
                                $sqlUp = "UPDATE clients SET expiry_date = ?, status = ?, password = ? WHERE mac = ?";
                                $params = [$new_expiry, $new_status, $parsed['password'], $mac];
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
                                $up = $conn->prepare("UPDATE clients SET status = 'inactive' WHERE mac = ?");
                                $up->execute([$mac]);
                                $row['status'] = 'inactive';
                            }
                        }
                    }
                }
            }
        }

        $exp_ts = strtotime($row['expiry_date'] ?? '');
        $is_expired = ($exp_ts === false) ? true : ($exp_ts < time());
        $legacyActive = ($row['status'] == 'active' && !$is_expired);
        $lbl = trim((string) ($row['display_name'] ?? ''));
        if ($lbl === '') {
            $lbl = (string) ($row['username'] ?? '');
        }

        echo json_encode([
            'active' => $legacyActive,
            'username' => $row['username'] ?? '',
            'password' => $row['password'] ?? '',
            'dns' => $row['dns_assigned'] ?: '',
            'profiles' => [[
                'username' => $row['username'] ?? '',
                'password' => $row['password'] ?? '',
                'dns' => $row['dns_assigned'] ?: '',
                'label' => $lbl,
                'active' => $legacyActive,
            ]],
            'message' => $is_expired
                ? ('Vencido em: ' . ($exp_ts !== false ? date('d/m/Y', $exp_ts) : ($row['expiry_date'] ?? '—')))
                : 'Acesso liberado',
            'events_url' => $events_url_for_mac,
        ]);
        exit;
    }

    // 2. SE O MAC É NOVO: GERA TESTE NO CHATBOT (LÓGICA ORIGINAL)
    $stmtBot = $conn->prepare("SELECT * FROM chatbot_config WHERE id = 1");
    $stmtBot->execute();
    $bot = $stmtBot->fetch();

    $user = ""; $pass = ""; $dns_final = ""; $status = "inactive";
    $pay_url = ""; $is_auto = 0;
    $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
    $msg_retorno = "Solicitação enviada! Ative o MAC $mac no painel.";

    if ($bot && $bot['enabled'] == 1 && !empty($bot['api_url'])) {
        $token = trim((string) ($bot['webhook_token'] ?? ''));
        $sigmaUid = trim((string) ($bot['sigma_user_id'] ?? ''));
        $trialPkg = trim((string) ($bot['trial_package_id'] ?? ''));
        $json = null;

        if ($token !== '' && $sigmaUid !== '' && $trialPkg !== '') {
            $createUrl = sigma_customer_create_url($bot['api_url']);
            $body = [
                'userId' => $sigmaUid,
                'packageId' => $trialPkg,
                'username' => '',
                'password' => '',
                'name' => '',
                'email' => '',
                'whatsapp' => '',
                'note' => 'MAC:' . $mac,
            ];
            $r = sigma_webhook_curl('POST', $createUrl, $token, $body);
            if ($r['errno'] === 0 && $r['http'] >= 200 && $r['http'] < 300) {
                $json = $r['json'];
            }
        }

        // Modo genérico: qualquer chatbot com POST + JSON (mac na criação do teste)
        if ($json === null) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $bot['api_url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['mac' => $mac]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json, text/plain, */*']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $res_api = curl_exec($ch);
            curl_close($ch);
            $json = is_string($res_api) ? json_decode($res_api, true) : null;
            if (!is_array($json) && is_string($res_api) && preg_match('/\{[\s\S]*\}/', $res_api, $m)) {
                $json = json_decode($m[0], true);
            }
        }

        if (is_array($json)) {
            $p = sigma_parse_customer_payload($json);
            $user = $p['username'] ?? ($json['username'] ?? '');
            $pass = $p['password'] ?? ($json['password'] ?? '');
            if ($user !== '') {
                $status = "active";
                $is_auto = 1;
                if ($p['expiry'] !== null) {
                    $expiry = $p['expiry'];
                } else {
                    $expiry = $json['expiresAt'] ?? date('Y-m-d H:i:s', strtotime('+6 hours'));
                }
                $pay_url = $json['payUrl'] ?? "";

                $dns_options = array_filter([$bot['dns1'], $bot['dns2'], $bot['dns3'], $bot['dns4']]);
                if (!empty($dns_options)) {
                    shuffle($dns_options);
                    $dns_final = $dns_options[0];
                } else {
                    $dns_final = $json['dns'] ?? "";
                }
                if ($p['status'] === 'inactive') {
                    $status = 'inactive';
                }
            }
        }
    }

    $ins = $conn->prepare("INSERT OR IGNORE INTO clients (mac, username, password, dns_assigned, status, expiry_date, renew_url, is_auto) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $ins->execute([$mac, $user, $pass, $dns_final, $status, $expiry, $pay_url, $is_auto]);

    // Se já existia linha (ex.: tentativa anterior falhou), IGNORE não atualiza — gravar dados do chatbot de qualquer forma
    if ($user !== '') {
        $up = $conn->prepare("UPDATE clients SET username = ?, password = ?, dns_assigned = ?, status = ?, expiry_date = ?, renew_url = ?, is_auto = ? WHERE mac = ?");
        $up->execute([$user, $pass, $dns_final, $status, $expiry, $pay_url, $is_auto, $mac]);

        $dnsN = unipro_normalize_dns($dns_final);
        $upsertAcc = $conn->prepare(
            'INSERT INTO client_mac_accounts (mac, username, password, dns_assigned, expiry_date, status, is_auto, renew_url, display_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(mac, username, dns_assigned) DO UPDATE SET
                password = excluded.password,
                expiry_date = excluded.expiry_date,
                status = excluded.status,
                is_auto = excluded.is_auto,
                renew_url = excluded.renew_url,
                display_name = COALESCE(NULLIF(TRIM(excluded.display_name), \'\'), client_mac_accounts.display_name)'
        );
        $upsertAcc->execute([$mac, $user, $pass, $dnsN, $expiry, $status, $is_auto, $pay_url, $user]);
    }

    $trialActive = ($status == 'active');
    echo json_encode([
        'active' => $trialActive,
        'username' => $user,
        'password' => $pass,
        'dns' => $dns_final,
        'profiles' => [[
            'username' => $user,
            'password' => $pass,
            'dns' => $dns_final,
            'label' => $user !== '' ? $user : $mac,
            'active' => $trialActive,
        ]],
        'message' => $msg_retorno,
        'events_url' => $events_url_for_mac,
    ]);
    exit;
}

echo json_encode(["error" => "Ação inválida"]);