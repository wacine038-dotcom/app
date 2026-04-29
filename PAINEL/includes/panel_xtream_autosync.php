<?php
/**
 * Sincroniza validades com player_api (Xtream) ao abrir páginas principais do painel.
 * Executa no máximo uma vez por requisição HTTP.
 */
if (!empty($GLOBALS['_uni_xtream_autosync_done'])) {
    return;
}
$GLOBALS['_uni_xtream_autosync_done'] = true;

if (!isset($conn)) {
    return;
}

require_once __DIR__ . '/xtream_sync.php';

if (function_exists('set_time_limit')) {
    @set_time_limit(260);
}

$navKey = $nav ?? '';

// Páginas onde faz sentido atualizar datas em lote (evita POST lento em settings/login)
if ($navKey === 'mac_accounts') {
    unipro_xtream_sync_stale_clients($conn, 150, 45);
} elseif ($navKey === 'dashboard') {
    unipro_xtream_sync_stale_clients($conn, 90, 60);
}
