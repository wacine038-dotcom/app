<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/includes/xtream_sync.php';

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$prefill_mac = isset($_GET['mac']) ? unipro_normalize_mac(trim((string) $_GET['mac'])) : '';
$prefill_expiry = '';
$error_msg = '';
$form = [
    'id' => 0,
    'mac' => '',
    'username' => '',
    'password' => '',
    'dns_assigned' => '',
    'expiry_date' => '',
    'status' => 'active',
    'display_name' => '',
];

if ($editId > 0) {
    $stE = $conn->prepare('SELECT * FROM client_mac_accounts WHERE id = ?');
    $stE->execute([$editId]);
    $rowE = $stE->fetch(PDO::FETCH_ASSOC);
    if ($rowE) {
        $form = [
            'id' => (int) $rowE['id'],
            'mac' => (string) ($rowE['mac'] ?? ''),
            'username' => (string) ($rowE['username'] ?? ''),
            'password' => (string) ($rowE['password'] ?? ''),
            'dns_assigned' => (string) ($rowE['dns_assigned'] ?? ''),
            'expiry_date' => $rowE['expiry_date'] ? substr(preg_replace('/[ T].*$/', '', (string) $rowE['expiry_date']), 0, 10) : '',
            'status' => (string) ($rowE['status'] ?? 'active'),
            'display_name' => (string) ($rowE['display_name'] ?? ''),
        ];
    } else {
        $editId = 0;
    }
} elseif ($prefill_mac !== '') {
    $stmtXs = $conn->prepare('SELECT id, mac, username, password, dns_assigned, status, expiry_date FROM client_mac_accounts WHERE mac = ? ORDER BY id ASC');
    $stmtXs->execute([$prefill_mac]);
    $anyAcc = false;
    while ($rowXs = $stmtXs->fetch(PDO::FETCH_ASSOC)) {
        $anyAcc = true;
        xtream_sync_mac_account_row($conn, $rowXs, true);
    }
    if (!$anyAcc) {
        $stmtLegacy = $conn->prepare('SELECT id, mac, username, password, dns_assigned, status, expiry_date FROM clients WHERE mac = ?');
        $stmtLegacy->execute([$prefill_mac]);
        $rowLegacy = $stmtLegacy->fetch(PDO::FETCH_ASSOC);
        if ($rowLegacy) {
            xtream_sync_client_row($conn, $rowLegacy, true);
        }
    }
    $stmtXs2 = $conn->prepare('SELECT expiry_date FROM clients WHERE mac = ?');
    $stmtXs2->execute([$prefill_mac]);
    $ed = $stmtXs2->fetch(PDO::FETCH_ASSOC);
    if (!$ed || empty($ed['expiry_date'])) {
        $stmtXs3 = $conn->prepare('SELECT expiry_date FROM client_mac_accounts WHERE mac = ? ORDER BY id ASC LIMIT 1');
        $stmtXs3->execute([$prefill_mac]);
        $ed = $stmtXs3->fetch(PDO::FETCH_ASSOC);
    }
    if ($ed && !empty($ed['expiry_date'])) {
        $prefill_expiry = substr(preg_replace('/[ T].*$/', '', (string) $ed['expiry_date']), 0, 10);
    }
    $form['mac'] = $prefill_mac;
    $form['expiry_date'] = $prefill_expiry;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mac_account'])) {
    $id = (int) ($_POST['account_id'] ?? 0);
    $mac = unipro_normalize_mac(trim((string) ($_POST['mac'] ?? '')));
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = trim((string) ($_POST['password'] ?? ''));
    $dnsRaw = trim((string) ($_POST['dns_assigned'] ?? ''));
    $dnsN = unipro_normalize_dns($dnsRaw);
    $expiry = trim((string) ($_POST['expiry_date'] ?? ''));
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $display = trim((string) ($_POST['display_name'] ?? ''));

    if ($mac === '') {
        $error_msg = 'Informe um MAC válido.';
    } elseif ($user === '' || $dnsN === '') {
        $error_msg = 'Usuário e DNS (URL do servidor) são obrigatórios.';
    } else {
        try {
            if ($id > 0) {
                $stmt = $conn->prepare('SELECT mac FROM client_mac_accounts WHERE id = ?');
                $stmt->execute([$id]);
                $prev = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$prev) {
                    throw new RuntimeException('Registro não encontrado.');
                }
                $oldMac = (string) $prev['mac'];
                $up = $conn->prepare(
                    'UPDATE client_mac_accounts SET mac = ?, username = ?, password = ?, dns_assigned = ?, expiry_date = ?, status = ?, display_name = ? WHERE id = ?'
                );
                $up->execute([$mac, $user, $pass, $dnsN, $expiry !== '' ? $expiry : null, $status, $display, $id]);
                if ($oldMac !== $mac) {
                    unipro_reconcile_clients_for_mac($conn, $oldMac);
                }
                unipro_reconcile_clients_for_mac($conn, $mac);
            } else {
                $upsertAcc = $conn->prepare(
                    'INSERT INTO client_mac_accounts (mac, username, password, dns_assigned, expiry_date, status, is_auto, renew_url, display_name)
                     VALUES (?, ?, ?, ?, ?, ?, 0, \'\', ?)
                     ON CONFLICT(mac, username, dns_assigned) DO UPDATE SET
                        password = excluded.password,
                        expiry_date = excluded.expiry_date,
                        status = excluded.status,
                        is_auto = 0,
                        display_name = COALESCE(NULLIF(TRIM(excluded.display_name), \'\'), client_mac_accounts.display_name)'
                );
                $dispVal = $display !== '' ? $display : $user;
                $upsertAcc->execute([$mac, $user, $pass, $dnsN, $expiry !== '' ? $expiry : null, $status, $dispVal]);

                $stmtFirst = $conn->prepare('SELECT username, password, expiry_date, dns_assigned FROM client_mac_accounts WHERE mac = ? ORDER BY id ASC LIMIT 1');
                $stmtFirst->execute([$mac]);
                $first = $stmtFirst->fetch(PDO::FETCH_ASSOC);
                if (!$first) {
                    throw new RuntimeException('Falha ao gravar conta MAC.');
                }
                $checkStmt = $conn->prepare('SELECT id FROM clients WHERE mac = ?');
                $checkStmt->execute([$mac]);
                $clientExists = (bool) $checkStmt->fetch(PDO::FETCH_ASSOC);
                if (!$clientExists) {
                    $ins = $conn->prepare('INSERT INTO clients (mac, username, password, expiry_date, dns_assigned, status) VALUES (?, ?, ?, ?, ?, ?)');
                    $ins->execute([$mac, $first['username'], $first['password'], $first['expiry_date'], $first['dns_assigned'], $status]);
                } else {
                    $upd = $conn->prepare('UPDATE clients SET username = ?, password = ?, expiry_date = ?, dns_assigned = ?, status = ? WHERE mac = ?');
                    $upd->execute([$first['username'], $first['password'], $first['expiry_date'], $first['dns_assigned'], $status, $mac]);
                }
                unipro_reconcile_clients_for_mac($conn, $mac);
            }
            header('Location: mac_contas.php?saved=1');
            exit;
        } catch (Throwable $e) {
            $error_msg = 'Erro ao salvar: ' . $e->getMessage();
        }
    }
    if ($error_msg !== '') {
        $form['id'] = (int) ($_POST['account_id'] ?? 0);
        $form['mac'] = unipro_normalize_mac(trim((string) ($_POST['mac'] ?? '')));
        $form['username'] = trim((string) ($_POST['username'] ?? ''));
        $form['password'] = trim((string) ($_POST['password'] ?? ''));
        $form['dns_assigned'] = trim((string) ($_POST['dns_assigned'] ?? ''));
        $form['expiry_date'] = trim((string) ($_POST['expiry_date'] ?? ''));
        $form['status'] = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $form['display_name'] = trim((string) ($_POST['display_name'] ?? ''));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_dns_replace'])) {
    $old = trim((string) ($_POST['dns_old'] ?? ''));
    $new = trim((string) ($_POST['dns_new'] ?? ''));

    if ($old === '' || $new === '') {
        header('Location: mac_contas.php?dns_err=empty');
        exit;
    }
    if ($old === $new) {
        header('Location: mac_contas.php?dns_err=same');
        exit;
    }

    $stmtUpdate = $conn->prepare('UPDATE clients SET dns_assigned = ? WHERE dns_assigned = ?');
    $stmtUpdate->execute([$new, $old]);
    $stmtMa = $conn->prepare('UPDATE client_mac_accounts SET dns_assigned = ? WHERE dns_assigned = ?');
    $stmtMa->execute([$new, $old]);
    $nClients = (int) $stmtUpdate->rowCount() + (int) $stmtMa->rowCount();

    $nConfigTokens = 0;
    $res = $conn->query('SELECT dns_list FROM config WHERE id = 1');
    $row = $res ? $res->fetch_assoc() : null;

    if ($row) {
        $list = (string) ($row['dns_list'] ?? '');
        $parts = array_map('trim', explode(',', $list));
        $out = [];
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }
            if ($p === $old) {
                $out[] = $new;
                $nConfigTokens++;
            } else {
                $out[] = $p;
            }
        }
        if ($nConfigTokens > 0) {
            $joined = implode(',', $out);
            $stmtConf = $conn->prepare('UPDATE config SET dns_list = ? WHERE id = 1');
            $stmtConf->execute([$joined]);
        }
    }

    header('Location: mac_contas.php?dns_ok=1&c=' . (int) $nClients . '&cfg=' . (int) $nConfigTokens);
    exit;
}

if (isset($_GET['bulk_delete'])) {
    $dateLimit = date('Y-m-d', strtotime('-30 days'));
    $stmtBulk = $conn->prepare("DELETE FROM client_mac_accounts WHERE status = 'inactive' OR expiry_date <= ?");
    $stmtBulk->execute([$dateLimit]);
    $nDel = (int) $stmtBulk->rowCount();
    unipro_reconcile_all_clients_from_mac_accounts($conn);
    header('Location: mac_contas.php?bulk_ok=' . $nDel);
    exit;
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $stm = $conn->prepare('SELECT mac FROM client_mac_accounts WHERE id = ?');
    $stm->execute([$id]);
    $hit = $stm->fetch(PDO::FETCH_ASSOC);
    if ($hit && !empty($hit['mac'])) {
        $mac = (string) $hit['mac'];
        $stmtDel = $conn->prepare('DELETE FROM client_mac_accounts WHERE id = ?');
        $stmtDel->execute([$id]);
        unipro_reconcile_clients_for_mac($conn, $mac);
    }
    header('Location: mac_contas.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['xtream_sync_all'])) {
    if (function_exists('set_time_limit')) {
        @set_time_limit(900);
    }
    $st = unipro_xtream_sync_all_clients_now($conn);
    header('Location: mac_contas.php?xtream_sync=1&ok=' . (int) $st['panel_ok'] . '&fail=' . (int) $st['panel_fail'] . '&skip=' . (int) $st['skipped']);
    exit;
}

$pageTitle = 'Contas MAC';
$nav = 'mac_accounts';
require_once __DIR__ . '/includes/panel_xtream_autosync.php';

$clientsQuery = $conn->query('SELECT * FROM client_mac_accounts ORDER BY id DESC');

$topTitle = 'Contas MAC';
$topSubtitle = 'Credenciais e DNS por dispositivo (o app usa esta base no check_mac). Troca de DNS em massa e sincronização Xtream.';

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/layout-open.php';
?>

<?php if (!empty($_GET['saved'])): ?>
    <div class="alert alert-app alert-app--success mb-4">
        <i class="bi bi-check-circle-fill me-2"></i> Conta salva com sucesso.
    </div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-app alert-app--danger mb-4"><?php echo htmlspecialchars($error_msg); ?></div>
<?php endif; ?>

<?php if (isset($_GET['bulk_ok'])): ?>
    <div class="alert alert-app alert-app--success mb-4">
        <i class="bi bi-trash-fill me-2"></i> <strong><?php echo (int) $_GET['bulk_ok']; ?></strong> conta(s) inativa(s) ou vencida(s) há mais de 30 dias foram excluída(s); cadastros MAC foram reajustados.
    </div>
<?php endif; ?>

<?php if (!empty($_GET['dns_ok'])): ?>
    <div class="alert alert-app alert-app--success">
        <i class="bi bi-check-circle-fill me-2"></i> DNS substituído com sucesso.
    </div>
<?php endif; ?>

<?php if (!empty($_GET['dns_err'])): ?>
    <div class="alert alert-app alert-app--danger">
        <?php echo $_GET['dns_err'] === 'same' ? 'DNS antigo e novo não podem ser iguais.' : 'Preencha DNS atual e novo.'; ?>
    </div>
<?php endif; ?>

<?php if (!empty($_GET['xtream_sync'])): ?>
    <div class="alert alert-app alert-app--success mb-4">
        <i class="bi bi-cloud-download-fill me-2"></i>
        <strong>Sincronização Xtream concluída.</strong>
        <?php echo (int) ($_GET['ok'] ?? 0); ?> registro(s) atualizado(s);
        <?php if ((int) ($_GET['fail'] ?? 0) > 0): ?>
            <span class="text-warning"><?php echo (int) $_GET['fail']; ?> falha(s)</span>
        <?php endif; ?>
        <?php if ((int) ($_GET['skip'] ?? 0) > 0): ?>
            <span class="text-dim"><?php echo (int) $_GET['skip']; ?> ignorado(s) (sem DNS ou credenciais).</span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="app-card app-card--padded mb-4 border-start border-4 border-primary">
    <div class="app-card__header mb-3">
        <h2 class="app-card__title mb-0"><?php echo $form['id'] > 0 ? 'Editar conta MAC' : 'Nova conta MAC'; ?></h2>
        <p class="app-card__desc mb-0 mt-1">MAC, usuário, senha e URL do servidor (sem colar M3U). A validade pode ser preenchida manualmente ou atualizada pelo botão “Sincronizar dados”.</p>
    </div>
    <form method="POST" class="row g-3">
        <input type="hidden" name="save_mac_account" value="1">
        <input type="hidden" name="account_id" value="<?php echo (int) $form['id']; ?>">
        <div class="col-md-6 col-lg-4">
            <label class="app-form-label">MAC</label>
            <input type="text" name="mac" class="form-control app-input" required placeholder="aa:bb:cc:dd:ee:ff" value="<?php echo htmlspecialchars($form['mac']); ?>">
        </div>
        <div class="col-md-6 col-lg-4">
            <label class="app-form-label">Usuário</label>
            <input type="text" name="username" class="form-control app-input" required autocomplete="off" value="<?php echo htmlspecialchars($form['username']); ?>">
        </div>
        <div class="col-md-6 col-lg-4">
            <label class="app-form-label">Senha</label>
            <input type="text" name="password" class="form-control app-input" autocomplete="off" value="<?php echo htmlspecialchars($form['password']); ?>">
        </div>
        <div class="col-12 col-lg-8">
            <label class="app-form-label">DNS / URL do servidor</label>
            <input type="text" name="dns_assigned" class="form-control app-input" required placeholder="http://servidor:80" value="<?php echo htmlspecialchars($form['dns_assigned']); ?>">
        </div>
        <div class="col-md-6 col-lg-2">
            <label class="app-form-label">Validade</label>
            <input type="date" name="expiry_date" class="form-control app-input" value="<?php echo htmlspecialchars($form['expiry_date']); ?>">
        </div>
        <div class="col-md-6 col-lg-2">
            <label class="app-form-label">Status</label>
            <select name="status" class="form-control app-input">
                <option value="active"<?php echo $form['status'] === 'active' ? ' selected' : ''; ?>>Ativo</option>
                <option value="inactive"<?php echo $form['status'] === 'inactive' ? ' selected' : ''; ?>>Inativo</option>
            </select>
        </div>
        <div class="col-12 col-lg-6">
            <label class="app-form-label">Nome de exibição (opcional)</label>
            <input type="text" name="display_name" class="form-control app-input" value="<?php echo htmlspecialchars($form['display_name']); ?>">
        </div>
        <div class="col-12 d-flex flex-wrap gap-2 mt-2">
            <button type="submit" class="btn btn-app-primary"><i class="bi bi-save me-1"></i> Salvar</button>
            <?php if ($form['id'] > 0): ?>
                <a href="mac_contas.php" class="btn btn-app-ghost">Nova conta</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="app-card app-card--padded dns-replace-card mb-4">
    <div class="app-card__header">
        <h2 class="app-card__title"><i class="bi bi-arrow-left-right text-warning me-2"></i> Alterar DNS em massa</h2>
    </div>
    <form method="POST" class="row g-3 align-items-end">
        <input type="hidden" name="bulk_dns_replace" value="1">
        <div class="col-md-5">
            <label class="app-form-label">DNS / URL atual</label>
            <input type="text" name="dns_old" class="form-control app-input" placeholder="Ex: http://servidor:80" required>
        </div>
        <div class="col-md-5">
            <label class="app-form-label">DNS / URL novo</label>
            <input type="text" name="dns_new" class="form-control app-input" placeholder="Ex: http://novo:80" required>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-app-primary w-100 py-2">Trocar</button>
        </div>
    </form>
</div>

<div class="app-card app-card--padded border-top border-4 border-info mb-4">
    <div class="row align-items-center g-3">
        <div class="col-md-7">
            <h2 class="app-card__title mb-1">Contas cadastradas</h2>
            <p class="app-card__desc mb-0">Destaque em <span class="text-warning">amarelo</span>: vence em menos de 3 dias.</p>
        </div>
        <div class="col-md-5 text-md-end d-flex flex-wrap gap-2 justify-content-md-end">
            <form method="post" class="d-inline" onsubmit="return confirm('Atualizar validades via player_api (Xtream) para todos com DNS e credenciais? Pode levar vários minutos.');">
                <input type="hidden" name="xtream_sync_all" value="1">
                <button type="submit" class="btn btn-app-accent btn-sm">
                    <i class="bi bi-arrow-repeat me-1"></i> Sincronizar dados
                </button>
            </form>
            <a href="?bulk_delete=1" class="btn btn-app-danger btn-sm" onclick="return confirm('Excluir inativos e vencidos há +30 dias?')">
                <i class="bi bi-trash3 me-1"></i> Limpar inativos
            </a>
        </div>
    </div>
</div>

<div class="app-card overflow-hidden">
    <div class="table-responsive">
        <table class="table app-table table-hover mb-0">
            <thead>
                <tr>
                    <th>MAC</th>
                    <th>Perfil / DNS</th>
                    <th>Expira</th>
                    <th>Status</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($clientsQuery):
                    while ($c = $clientsQuery->fetch_assoc()):
                        $expiryTS = strtotime($c['expiry_date'] ?? '');
                        $isExpired = $c['expiry_date'] && $expiryTS < time();
                        $isNear = (!$isExpired && $c['expiry_date'] && $expiryTS < strtotime('+3 days'));

                        $info = [
                            'id' => (int) $c['id'],
                            'mac' => $c['mac'],
                            'username' => $c['username'] ?? '',
                            'password' => $c['password'] ?? '',
                            'dns_assigned' => $c['dns_assigned'] ?? '',
                            'status' => $c['status'] ?? '',
                            'expiry_date' => $c['expiry_date'] ?? '',
                            'is_auto' => $c['is_auto'] ?? 0,
                        ];
                        $dataClient = htmlspecialchars(json_encode($info, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                ?>
                        <tr class="client-row <?php echo $isNear ? 'border-start border-4 border-warning' : ''; ?>"
                            style="<?php echo $isNear ? 'background: rgba(255, 193, 7, 0.05);' : ''; ?>"
                            data-href="mac_contas.php?edit=<?php echo (int) $c['id']; ?>">

                            <td>
                                <span class="fw-bold text-white"><?php echo htmlspecialchars($c['mac']); ?></span>
                                <?php if (isset($c['is_auto']) && (int) $c['is_auto'] === 1): ?>
                                    <i class="bi bi-lightning-charge text-info ms-1" title="Registro automático"></i>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?php
                                $profLabel = trim((string) ($c['display_name'] ?? ''));
                                if ($profLabel === '') {
                                    $profLabel = (string) ($c['username'] ?? '');
                                }
                                ?>
                                <div class="text-white"><?php echo htmlspecialchars($profLabel !== '' ? $profLabel : '—'); ?></div>
                                <div class="text-dim small font-monospace"><?php echo htmlspecialchars($c['dns_assigned'] ?? ''); ?></div>
                            </td>
                            <td class="<?php echo $isExpired ? 'text-danger' : ($isNear ? 'text-warning fw-bold' : 'text-white'); ?>">
                                <?php echo $c['expiry_date'] ? date('d/m/Y', $expiryTS) : '—'; ?>
                            </td>
                            <td>
                                <span class="badge-app <?php echo ($c['status'] === 'active' && !$isExpired) ? 'badge-app--ok' : 'badge-app--off'; ?>">
                                    <?php echo ($c['status'] === 'active' && !$isExpired) ? 'Ativo' : 'Inativo'; ?>
                                </span>
                            </td>
                            <td class="text-end" onclick="event.stopPropagation();">
                                <button type="button" class="btn btn-sm btn-app-accent btn-client-info" data-client='<?php echo $dataClient; ?>'>
                                    <i class="bi bi-info-circle"></i> Info
                                </button>
                                <a href="?delete=<?php echo (int) $c['id']; ?>" class="btn btn-sm btn-app-danger" onclick="return confirm('Excluir esta conta deste MAC?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                <?php endwhile; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="clientInfoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content app-modal-dark">
            <div class="modal-header">
                <h5 class="modal-title text-white">Detalhes da conta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="text-dim small d-block">ID</label>
                        <div id="ci-id" class="text-white fw-bold">—</div>
                    </div>
                    <div class="col-md-6">
                        <label class="text-dim small d-block">MAC</label>
                        <div id="ci-mac" class="text-warning fw-bold">—</div>
                    </div>
                    <div class="col-md-6">
                        <label class="text-dim small d-block">Usuário</label>
                        <div id="ci-user" class="text-info">—</div>
                    </div>
                    <div class="col-md-6">
                        <label class="text-dim small d-block">Senha</label>
                        <div id="ci-pass" class="text-info">—</div>
                    </div>
                    <div class="col-12 border-top border-secondary pt-2">
                        <label class="text-dim small d-block">DNS / URL</label>
                        <div id="ci-dns" class="text-white small">—</div>
                    </div>
                    <div class="col-12">
                        <label class="text-dim small d-block">URL de lista (montada a partir do servidor)</label>
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control app-input font-monospace small" id="ci-m3u" readonly placeholder="Abra para consultar o servidor…">
                            <button type="button" class="btn btn-app-accent" id="ci-m3u-copy" title="Copiar"><i class="bi bi-clipboard"></i></button>
                        </div>
                        <p class="text-dim small mb-0 mt-1" id="ci-xtream-note">Validade sincronizada com o player_api do painel Xtream, quando disponível.</p>
                    </div>
                    <div class="col-md-4">
                        <label class="text-dim small d-block">Status</label>
                        <div id="ci-status">—</div>
                    </div>
                    <div class="col-md-4">
                        <label class="text-dim small d-block">Validade (servidor)</label>
                        <div id="ci-expiry" class="text-white">—</div>
                    </div>
                    <div class="col-md-4">
                        <label class="text-dim small d-block">Origem</label>
                        <div id="ci-origin" class="fw-bold">—</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-app-ghost" data-bs-dismiss="modal">Fechar</button>
                <a href="#" class="btn btn-app-primary" id="ci-edit-link"><i class="bi bi-pencil-square me-1"></i> Editar</a>
            </div>
        </div>
    </div>
</div>

<?php
$footerScripts = <<<'JS'
<script>
function formatBrDateFromSql(s) {
    if (!s) return "—";
    var p = String(s).trim().split(/[ T]/);
    var pt = p[0].split("-");
    if (pt.length === 3) return pt[2] + "/" + pt[1] + "/" + pt[0] + (p[1] ? (" " + p[1].slice(0, 5)) : "");
    return s;
}
function setStatusBadge(el, isActive) {
    el.innerHTML = isActive
        ? "<span class=\"text-success\">Ativo</span>"
        : "<span class=\"text-danger\">Inativo</span>";
}
document.querySelectorAll("tr.client-row[data-href]").forEach(function (row) {
    row.addEventListener("click", function (e) {
        if (!e.target.closest("button, a")) window.location.href = row.getAttribute("data-href");
    });
});
var m3uCopyBtn = document.getElementById("ci-m3u-copy");
if (m3uCopyBtn) {
    m3uCopyBtn.addEventListener("click", function () {
        var inp = document.getElementById("ci-m3u");
        if (!inp || !inp.value) return;
        inp.select();
        document.execCommand("copy");
    });
}
if (typeof window.__ciXtreamSeq === "undefined") { window.__ciXtreamSeq = 0; }
document.querySelectorAll(".btn-client-info").forEach(function (btn) {
    btn.addEventListener("click", function () {
        var mySeq = ++window.__ciXtreamSeq;
        var d = JSON.parse(btn.getAttribute("data-client"));
        document.getElementById("ci-id").textContent = d.id;
        document.getElementById("ci-mac").textContent = d.mac || "—";
        document.getElementById("ci-user").textContent = d.username || "—";
        document.getElementById("ci-pass").textContent = d.password || "—";
        document.getElementById("ci-dns").textContent = d.dns_assigned || "—";
        document.getElementById("ci-m3u").value = "";
        setStatusBadge(document.getElementById("ci-status"), d.status === "active");
        document.getElementById("ci-expiry").textContent = "Consultando servidor…";
        document.getElementById("ci-origin").innerHTML = d.is_auto == 1
            ? "<span class=\"text-info\"><i class=\"bi bi-lightning-charge\"></i> Automático</span>"
            : "Manual";
        document.getElementById("ci-edit-link").href = "mac_contas.php?edit=" + encodeURIComponent(d.id);
        var note = document.getElementById("ci-xtream-note");
        if (note) note.textContent = "Consultando player_api.php no servidor…";

        var modalEl = document.getElementById("clientInfoModal");
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();

        fetch("ajax_xtream_expiry.php?id=" + encodeURIComponent(d.id) + "&save=1", { credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (mySeq !== window.__ciXtreamSeq) return;
                if (!j.ok) {
                    document.getElementById("ci-expiry").textContent = formatBrDateFromSql(d.expiry_date)
                        + " (local; servidor: " + (j.message || j.error || "erro") + ")";
                    if (j.m3u_url) document.getElementById("ci-m3u").value = j.m3u_url;
                    if (note) note.textContent = "Não foi possível ler a data no servidor. Verifique DNS e porta.";
                    return;
                }
                document.getElementById("ci-expiry").textContent = j.expiry_human || "—";
                document.getElementById("ci-m3u").value = j.m3u_url || "";
                setStatusBadge(document.getElementById("ci-status"), j.derived_status === "active");
                var extra = [];
                if (j.panel_status) extra.push("Painel: " + j.panel_status);
                if (j.panel_message) extra.push(j.panel_message);
                if (j.saved) extra.push("Cadastro atualizado com esta validade.");
                if (note) note.textContent = extra.length ? extra.join(" · ") : "Dados lidos do servidor Xtream.";
            })
            .catch(function () {
                if (mySeq !== window.__ciXtreamSeq) return;
                document.getElementById("ci-expiry").textContent = formatBrDateFromSql(d.expiry_date) + " (local; falha de rede)";
                if (note) note.textContent = "Erro de rede ao consultar o servidor.";
            });
    });
});
</script>
JS;

require __DIR__ . '/includes/layout-close.php';
require __DIR__ . '/includes/footer.php';
