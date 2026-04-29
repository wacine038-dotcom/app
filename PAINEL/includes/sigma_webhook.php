<?php
/**
 * Sigma Webhook API helpers (Atlas-style panel).
 * Postman: GET {panelUrl}/webhook/customer?username=...
 *          POST {panelUrl}/webhook/customer/create (JSON + Bearer)
 */

function unipro_chatbot_ensure_schema($conn): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $conn->query("CREATE TABLE IF NOT EXISTS chatbot_config (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        enabled INTEGER DEFAULT 0,
        api_url TEXT,
        dns1 TEXT,
        dns2 TEXT,
        dns3 TEXT,
        dns4 TEXT
    )");
    foreach ([
        'ALTER TABLE chatbot_config ADD COLUMN webhook_token TEXT',
        'ALTER TABLE chatbot_config ADD COLUMN sigma_user_id TEXT',
        'ALTER TABLE chatbot_config ADD COLUMN trial_package_id TEXT',
    ] as $sql) {
        try {
            $conn->prepare($sql)->execute();
        } catch (Throwable $e) {
            // coluna já existe
        }
    }
}

function sigma_normalize_base_url(string $url): string {
    return rtrim(trim($url), '/');
}

/**
 * Aceita URL colada do Postman completa ou só a base; remove sufixo /webhook/customer(/create).
 * Se faltar esquema, prefixa http:// (paineis Xtream costumam ser HTTP).
 */
function sigma_normalize_panel_api_input(string $url): string {
    $u = trim($url);
    if ($u === '') {
        return $u;
    }
    if (!preg_match('#^https?://#i', $u)) {
        $u = 'http://' . $u;
    }
    $u = rtrim($u, '/');
    $u = preg_replace('#/webhook/customer/create$#i', '', $u);
    $u = preg_replace('#/webhook/customer$#i', '', $u);

    return rtrim($u, '/');
}

function sigma_customer_show_url(string $baseApiUrl, string $username): string {
    $base = sigma_normalize_base_url($baseApiUrl);
    $u = rawurlencode($username);
    if (preg_match('#/webhook/customer$#i', $base)) {
        return $base . '?username=' . $u;
    }
    return $base . '/webhook/customer?username=' . $u;
}

function sigma_customer_create_url(string $baseApiUrl): string {
    $base = sigma_normalize_base_url($baseApiUrl);
    if (preg_match('#/webhook/customer/create$#i', $base)) {
        return $base;
    }
    if (preg_match('#/webhook/customer$#i', $base)) {
        return $base . '/create';
    }
    return $base . '/webhook/customer/create';
}

/**
 * @return array{errno:int,http:int,raw:?string,json:?array}
 */
function sigma_webhook_curl(string $method, string $url, ?string $bearerToken, $bodyJson = null): array {
    $ch = curl_init();
    $headers = ['Accept: application/json'];
    if ($bearerToken !== null && $bearerToken !== '') {
        $headers[] = 'Authorization: Bearer ' . $bearerToken;
    }
    if ($bodyJson !== null) {
        $headers[] = 'Content-Type: application/json; charset=utf-8';
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($bodyJson) ? $bodyJson : json_encode($bodyJson, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $raw = curl_exec($ch);
    $errno = (int) curl_errno($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $json = $decoded;
        }
    }

    return ['errno' => $errno, 'http' => $code, 'raw' => is_string($raw) ? $raw : null, 'json' => $json];
}

function sigma_flatten_leaves(array $arr, string $prefix = '', array &$out = []): array {
    foreach ($arr as $k => $v) {
        $key = (string) $k;
        $path = $prefix === '' ? $key : $prefix . '.' . $key;
        if (is_array($v) && $v !== [] && array_keys($v) !== range(0, count($v) - 1)) {
            sigma_flatten_leaves($v, $path, $out);
        } elseif (is_array($v) && $v !== [] && isset($v[0]) && is_array($v[0])) {
            sigma_flatten_leaves($v[0], $path, $out);
        } else {
            $out[$path] = $v;
        }
    }
    return $out;
}

/**
 * Normaliza data de expiração para Y-m-d H:i:s ou null.
 */
function sigma_coerce_expiry_string($value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        $n = (float) $value;
        if ($n > 20000000000) {
            $n = (int) ($n / 1000);
        }
        if ($n > 1000000000) {
            return date('Y-m-d H:i:s', (int) $n);
        }
    }
    if (!is_string($value)) {
        $value = (string) $value;
    }
    $ts = strtotime($value);
    if ($ts !== false) {
        return date('Y-m-d H:i:s', $ts);
    }
    return null;
}

/**
 * Extrai validade, status e credenciais de qualquer JSON típico do painel.
 *
 * @return array{expiry:?string,status:?string,username:?string,password:?string}
 */
function sigma_parse_customer_payload(?array $json): array {
    $empty = ['expiry' => null, 'status' => null, 'username' => null, 'password' => null];
    if ($json === null) {
        return $empty;
    }
    $merged = $json;
    if (isset($json['data']) && is_array($json['data'])) {
        $merged = array_merge($json, $json['data']);
    }
    foreach (['customer', 'user', 'account', 'result', 'payload'] as $wrap) {
        if (isset($merged[$wrap]) && is_array($merged[$wrap])) {
            $merged = array_merge($merged, $merged[$wrap]);
        }
    }

    // Xtream / Sigma costuma enviar a data em exp_date (nível raiz ou dentro de data)
    $expiry = null;
    foreach (['exp_date', 'expDate', 'EXP_DATE'] as $k) {
        if (isset($merged[$k]) && $merged[$k] !== '' && $merged[$k] !== null) {
            $expiry = sigma_coerce_expiry_string($merged[$k]);
            if ($expiry !== null) {
                break;
            }
        }
    }

    $leaves = [];
    sigma_flatten_leaves($merged, '', $leaves);

    if ($expiry === null) {
        $expiryCandidates = [
            'exp_date', 'expDate', 'expiresAt', 'expires_at', 'expiredAt', 'expired_at',
            'expireDate', 'expire_date', 'expirationDate', 'expiration', 'validUntil', 'valid_until',
            'endDate', 'end_date', 'subscriptionEnd', 'subscription_end', 'dueDate', 'due_date',
            'expiry', 'expiry_date', 'period_end', 'periodEnd', 'validity', 'until',
        ];
        foreach ($leaves as $path => $val) {
            $leaf = strtolower(preg_replace('/^.*\./', '', $path));
            $leafNorm = str_replace(['_', '-', ' '], '', $leaf);
            foreach ($expiryCandidates as $cand) {
                $cNorm = str_replace('_', '', strtolower($cand));
                if ($leafNorm === $cNorm || $leaf === strtolower($cand)) {
                    $coerced = sigma_coerce_expiry_string($val);
                    if ($coerced !== null) {
                        $expiry = $coerced;
                        break 2;
                    }
                }
            }
        }
    }
    if ($expiry === null) {
        foreach ($leaves as $path => $val) {
            $leaf = strtolower(preg_replace('/^.*\./', '', $path));
            // exp_date não contém "expir"; incluir expdate / exp_date explicitamente
            if (preg_match('/^exp_?date$|expdate$|expir|validuntil|duedate|end_?date|subscription|periodend|validity/', $leaf)) {
                $coerced = sigma_coerce_expiry_string($val);
                if ($coerced !== null) {
                    $expiry = $coerced;
                    break;
                }
            }
        }
    }

    $status = null;
    foreach ($leaves as $path => $val) {
        $leaf = strtolower(preg_replace('/^.*\./', '', $path));
        if ($leaf !== 'status' && $leaf !== 'state') {
            continue;
        }
        if (is_bool($val)) {
            $status = $val ? 'active' : 'inactive';
            break;
        }
        $s = strtoupper(trim((string) $val));
        if (in_array($s, ['INACTIVE', 'DISABLED', 'EXPIRED', 'BLOCKED', 'CANCELLED', 'CANCELED', 'BANNED', 'OFF'], true)) {
            $status = 'inactive';
            break;
        }
        if (in_array($s, ['ACTIVE', 'ENABLED', 'OK', 'ON'], true)) {
            $status = 'active';
            break;
        }
    }

    $username = null;
    $password = null;
    foreach (['username', 'user', 'login'] as $k) {
        if (!empty($merged[$k]) && is_string($merged[$k])) {
            $username = $merged[$k];
            break;
        }
    }
    foreach ($leaves as $path => $val) {
        if (preg_match('/(^|\\.)username$/i', $path) && is_string($val) && $val !== '') {
            $username = $val;
            break;
        }
    }
    if (!empty($merged['password']) && is_string($merged['password'])) {
        $password = $merged['password'];
    } else {
        foreach ($leaves as $path => $val) {
            if (preg_match('/\.password$/i', $path) && is_string($val) && $val !== '') {
                $password = $val;
                break;
            }
        }
    }

    return [
        'expiry' => $expiry,
        'status' => $status,
        'username' => $username,
        'password' => $password,
    ];
}

/**
 * Busca cliente no Sigma (modo novo: Bearer + GET).
 */
function sigma_fetch_customer_for_sync(string $baseApiUrl, string $bearerToken, string $username): array {
    $url = sigma_customer_show_url($baseApiUrl, $username);
    return sigma_webhook_curl('GET', $url, $bearerToken, null);
}
