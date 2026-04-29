<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/includes/xtream_sync.php';

header('Content-Type: application/json; charset=utf-8');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$save = isset($_GET['save']) ? ($_GET['save'] === '1' || $_GET['save'] === 'true') : true;
if (isset($_POST['save'])) {
    $save = $_POST['save'] === '1' || $_POST['save'] === 'true';
}

if ($id < 1) {
    echo json_encode(['ok' => false, 'error' => 'id', 'message' => 'ID inválido.']);
    exit;
}

$stmt = $conn->prepare('SELECT id, mac, username, password, dns_assigned, status, expiry_date FROM client_mac_accounts WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'not_found', 'message' => 'Lista não encontrada.']);
    exit;
}

$r = xtream_sync_mac_account_row($conn, $row, $save);
if ($save && !empty($row['mac'])) {
    unipro_reconcile_clients_for_mac($conn, (string) $row['mac']);
}

if (($r['error'] ?? '') === 'incomplete') {
    echo json_encode([
        'ok' => false,
        'error' => 'incomplete',
        'message' => 'Preencha DNS atribuída, usuário e senha no cadastro para consultar o Xtream.',
    ]);
    exit;
}

if (!$r['ok']) {
    $base = xtream_base_from_dns((string) ($row['dns_assigned'] ?? ''));
    $m3u = ($base && trim((string) ($row['username'] ?? '')) !== '')
        ? xtream_build_m3u_url($base, trim((string) ($row['username'] ?? '')), (string) ($row['password'] ?? ''))
        : '';
    echo json_encode([
        'ok' => false,
        'error' => $r['error'] ?? 'fetch',
        'message' => $r['message'] ?? '',
        'm3u_url' => $m3u,
    ]);
    exit;
}

$data = $r['panel'];
$dbStatus = xtream_derive_db_status($data);
$isUnlimited = ($data['exp_human'] === 'Ilimitado');

echo json_encode([
    'ok' => true,
    'expiry_human' => $data['exp_human'],
    'expiry_sql' => $data['exp_sql'],
    'm3u_url' => $data['m3u_url'],
    'panel_status' => $data['status'],
    'panel_auth' => $data['auth'],
    'panel_message' => $data['message'],
    'derived_status' => $dbStatus,
    'saved' => (bool) $save,
    'unlimited' => $isUnlimited,
]);
