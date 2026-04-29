<?php
/**
 * Xtream Codes: monta URL M3U e lê validade real via player_api.php (user_info.exp_date).
 */

function xtream_base_from_dns(string $dns): ?string {
    $dns = trim($dns);
    if ($dns === '') {
        return null;
    }
    if (!preg_match('#^https?://#i', $dns)) {
        $dns = 'http://' . $dns;
    }
    $p = parse_url($dns);
    if (empty($p['host'])) {
        return null;
    }
    $scheme = isset($p['scheme']) ? strtolower($p['scheme']) : 'http';
    if ($scheme !== 'http' && $scheme !== 'https') {
        $scheme = 'http';
    }
    $port = isset($p['port']) ? ':' . (int) $p['port'] : '';

    return $scheme . '://' . $p['host'] . $port;
}

function xtream_build_m3u_url(string $base, string $user, string $pass): string {
    $base = rtrim($base, '/');

    return $base . '/get.php?' . http_build_query([
        'username' => $user,
        'password' => $pass,
        'type' => 'm3u_plus',
        'output' => 'ts',
    ]);
}

/**
 * @return array{ok:bool,error?:string,exp_timestamp:?int,exp_sql:?string,exp_human:string,m3u_url:string,status:string,auth:string,message:string}
 */
function xtream_fetch_from_panel(string $base, string $user, string $pass): array {
    $base = rtrim($base, '/');
    $m3uUrl = xtream_build_m3u_url($base, $user, $pass);
    $apiUrl = $base . '/player_api.php?' . http_build_query([
        'username' => $user,
        'password' => $pass,
    ]);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 18,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json,*/*'],
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno !== 0 || !is_string($raw) || $raw === '') {
        return [
            'ok' => false,
            'error' => 'rede',
            'exp_timestamp' => null,
            'exp_sql' => null,
            'exp_human' => '—',
            'm3u_url' => $m3uUrl,
            'status' => '',
            'auth' => '',
            'message' => 'Não foi possível contatar o servidor (DNS/lista).',
        ];
    }

    $json = json_decode($raw, true);
    if (!is_array($json) || empty($json['user_info']) || !is_array($json['user_info'])) {
        return [
            'ok' => false,
            'error' => 'json',
            'exp_timestamp' => null,
            'exp_sql' => null,
            'exp_human' => '—',
            'm3u_url' => $m3uUrl,
            'status' => '',
            'auth' => '',
            'message' => 'Resposta do painel não é JSON válido (verifique DNS e porta).',
        ];
    }

    $ui = $json['user_info'];
    $expTs = null;
    $expSql = null;
    $expHuman = '—';

    $rawExp = $ui['exp_date'] ?? null;
    if ($rawExp !== null && $rawExp !== '' && (string) $rawExp !== '0') {
        if (is_numeric($rawExp)) {
            $expTs = (int) $rawExp;
            if ($expTs > 20000000000) {
                $expTs = (int) round($expTs / 1000);
            }
        } else {
            $parsed = strtotime((string) $rawExp);
            if ($parsed !== false) {
                $expTs = $parsed;
            }
        }
        if ($expTs !== null && $expTs > 0) {
            $expSql = date('Y-m-d H:i:s', $expTs);
            $expHuman = date('d/m/Y H:i', $expTs);
        }
    } else {
        $expHuman = 'Ilimitado';
    }

    $auth = isset($ui['auth']) ? (string) $ui['auth'] : '';
    $status = isset($ui['status']) ? (string) $ui['status'] : '';
    $message = isset($ui['message']) ? (string) $ui['message'] : '';

    return [
        'ok' => true,
        'exp_timestamp' => $expTs,
        'exp_sql' => $expSql,
        'exp_human' => $expHuman,
        'm3u_url' => $m3uUrl,
        'status' => $status,
        'auth' => $auth,
        'message' => $message,
    ];
}
