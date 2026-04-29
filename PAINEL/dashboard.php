<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/db.php';

$nav = 'dashboard';
require_once __DIR__ . '/includes/panel_xtream_autosync.php';

function getCount($conn, $sql) {
    $res = $conn->query($sql);
    if ($res) {
        $row = $res->fetch_assoc();
        return (int) ($row['COUNT(*)'] ?? array_values($row)[0] ?? 0);
    }
    return 0;
}

// 🔹 CONTAGEM DNS
$totalDns = 0;

try {
    $resConf = $conn->query("SELECT dns_list FROM config WHERE id = 1");
    $rowDns = $resConf ? $resConf->fetch_assoc() : null;

    if (!empty($rowDns['dns_list'])) {
        $dnsArr = array_filter(array_map('trim', explode(',', $rowDns['dns_list'])));
        $totalDns = count($dnsArr);
    }
} catch (Throwable $e) {
    $totalDns = 0;
}

// CONFIG APP
$resConf = $conn->query('SELECT app_logo, app_background, message_global, events_url FROM config WHERE id = 1');
$appConfig = $resConf ? $resConf->fetch_assoc() : null;

$pageTitle = 'Dashboard';
$topTitle = 'Painel';
$topSubtitle = 'DNS e status do sistema';

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/layout-open.php';
?>

<style>
.stat-grid-top { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
.visual-preview-card { background: rgba(15, 17, 26, 0.5); border-radius: 15px; border: 1px solid rgba(255,255,255,0.1); padding: 25px; margin-top: 20px; margin-bottom: 40px; }
.preview-box { background: #000; border-radius: 10px; padding: 15px; text-align: center; height: 140px; display: flex; align-items: center; justify-content: center; border: 1px solid #333; }
.preview-box img { max-width: 100%; max-height: 110px; object-fit: contain; }
.preview-bg { background-size: cover; background-position: center; border-radius: 10px; height: 140px; border: 1px solid #333; }
.btn-dash-action { display: flex; align-items: center; gap: 15px; padding: 15px; border-radius: 12px; transition: 0.3s; font-weight: 600; text-decoration: none !important; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.03); color: #fff; margin-bottom: 10px; }
.btn-dash-action:hover { background: rgba(255,255,255,0.08); transform: translateY(-3px); border-color: #00e5ff; color: #fff; }
</style>

<!-- ✅ CARDS SIMPLES -->
<div class="stat-grid-top">
    <div class="stat-card stat-card--emerald">
        <div class="stat-card__icon"><i class="bi bi-server"></i></div>
        <div class="stat-card__label">DNS Ativas</div>
        <div class="stat-card__value"><?php echo $totalDns; ?></div>
    </div>

    <div class="stat-card stat-card--sky">
        <div class="stat-card__icon"><i class="bi bi-activity"></i></div>
        <div class="stat-card__label">Status do Sistema</div>
        <div class="stat-card__value text-success">Online</div>
    </div>
</div>

<!-- ✅ AÇÕES -->
<div class="d-grid gap-1 mb-4">
    <a href="settings.php" class="btn-dash-action">
        <i class="bi bi-gear-fill text-warning fs-4"></i>
        <div>Configurações do App</div>
    </a>

    <a href="dns.php" class="btn-dash-action">
        <i class="bi bi-server text-info fs-4"></i>
        <div>Servidores DNS</div>
    </a>
</div>

<!-- ✅ PREVIEW VISUAL -->
<div class="visual-preview-card">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h3 class="app-card__title mb-0">
            <i class="bi bi-palette2 text-primary me-2"></i> Visual do app (get_config)
        </h3>
        <a href="settings.php" class="btn btn-app-primary btn-sm px-4">Editar</a>
    </div>

    <div class="row g-4">
        <div class="col-12 col-md-4 text-center">
            <label class="text-dim small mb-2 d-block">LOGOTIPO</label>
            <div class="preview-box">
                <img src="<?php echo ($appConfig['app_logo'] ?? '') ?: 'https://placehold.co/200x100?text=Sem+Logo'; ?>">
            </div>
        </div>

        <div class="col-12 col-md-4 text-center">
            <label class="text-dim small mb-2 d-block">WALLPAPER</label>
            <div class="preview-bg" style="background-image: url('<?php echo ($appConfig['app_background'] ?? '') ?: 'https://placehold.co/400x200?text=Sem+Fundo'; ?>');"></div>
        </div>
    </div>
</div>

<?php
require __DIR__ . '/includes/layout-close.php';
require __DIR__ . '/includes/footer.php';