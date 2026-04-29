<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/includes/events_url_normalize.php';

$configSaved = false;
$uploadError = '';

// ==========================================
// LÓGICA DE UPLOAD E SALVAMENTO (SQLITE)
// ==========================================
if (isset($_POST['save_config'])) {
    $logo = $_POST['app_logo'] ?? '';
    $bg = $_POST['app_background'] ?? '';
    $msg = $_POST['message_global'] ?? '';
    $whatsapp = $_POST['support_whatsapp'] ?? '';
    $events = unipro_normalize_events_url_string($_POST['events_url'] ?? '');
    $news = $_POST['news_banner_url'] ?? '';

    // Processar Uploads de Arquivos (Logo e Background)
    foreach (['logo_file' => 'app_logo', 'bg_file' => 'app_background'] as $fileKey => $dbField) {
        if (!empty($_FILES[$fileKey]['name'])) {
            $targetDir = "uploads/";
            if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
            
            $fileName = time() . "_" . basename($_FILES[$fileKey]["name"]);
            $targetFilePath = $targetDir . $fileName;
            $fileType = pathinfo($targetFilePath, PATHINFO_EXTENSION);

            $allowTypes = ['jpg', 'png', 'jpeg', 'gif', 'webp'];
            if (in_array(strtolower($fileType), $allowTypes)) {
                if (move_uploaded_file($_FILES[$fileKey]["tmp_name"], $targetFilePath)) {
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                    $fullUrl = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/" . $targetFilePath;
                    
                    if ($dbField == 'app_logo') $logo = $fullUrl;
                    if ($dbField == 'app_background') $bg = $fullUrl;
                }
            } else {
                $uploadError = "Apenas imagens (JPG, PNG, WEBP) são permitidas.";
            }
        }
    }

    $stmtUpdate = $conn->prepare("UPDATE config SET
        app_logo = ?,
        app_background = ?,
        message_global = ?,
        support_whatsapp = ?,
        events_url = ?,
        news_banner_url = ?
        WHERE id = 1");
    
    $stmtUpdate->execute([$logo, $bg, $msg, $whatsapp, $events, $news]);
    $configSaved = true;
}

$config_res = $conn->prepare('SELECT * FROM config WHERE id = 1');
$config_res->execute();
$config = $config_res->fetch();

if (!$config) {
    $conn->query('INSERT INTO config (id) VALUES (1)');
    $config_res->execute();
    $config = $config_res->fetch();
}

$pageTitle = 'Configurações';
$nav = 'settings';
require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/layout-open.php';
?>

<style>
    .img-preview-container {
        width: 100%;
        min-height: 180px; /* Aumentado conforme pedido */
        display: flex;
        align-items: center;
        justify-content: center;
        background: #0f111a;
        border: 2px dashed rgba(255,255,255,0.15);
        border-radius: 12px;
        overflow: hidden;
        margin-bottom: 15px;
    }
    .img-preview-container img {
        max-width: 100%;
        max-height: 160px;
        object-fit: contain;
    }
    .badge-pill-cyan { background-color: #00e5ff; color: #000; }
    .badge-pill-magenta { background-color: #ff00ff; color: #fff; }
    .badge-pill-lime { background-color: #ccff00; color: #000; }
    .text-dim { color: rgba(255,255,255,0.5); }
</style>

<?php if ($configSaved): ?>
    <div class="alert alert-app alert-app--success mb-4">
        <i class="bi bi-check-circle-fill me-2"></i> Configurações salvas. Cache limpa em ~1min.
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-xl-8">
        <div class="app-card app-card--padded">
            <div class="app-card__header mb-4">
                <div>
                    <h2 class="app-card__title">Branding & Mensagens</h2>
                    <p class="app-card__desc">Personalize o visual e os avisos do seu aplicativo.</p>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="save_config" value="1">
                
                <div class="row">
                    <div class="col-12 col-md-6 mb-4">
                        <label class="app-form-label">Logo do App</label>
                        <div class="img-preview-container">
                            <img id="prev_logo" src="<?php echo $config['app_logo'] ?: 'https://placehold.co/400x200?text=Sua+Logo'; ?>">
                        </div>
                        <input type="url" name="app_logo" id="input_logo" value="<?php echo htmlspecialchars($config['app_logo'] ?? ''); ?>" class="form-control app-input mb-2" placeholder="URL da Logo">
                        <input type="file" name="logo_file" class="form-control form-control-sm app-input">
                    </div>

                    <div class="col-12 col-md-6 mb-4">
                        <label class="app-form-label">Plano de Fundo (Wallpaper)</label>
                        <div class="img-preview-container">
                            <img id="prev_bg" src="<?php echo $config['app_background'] ?: 'https://placehold.co/400x200?text=Plano+de+Fundo'; ?>">
                        </div>
                        <input type="url" name="app_background" id="input_bg" value="<?php echo htmlspecialchars($config['app_background'] ?? ''); ?>" class="form-control app-input mb-2" placeholder="URL do Fundo">
                        <input type="file" name="bg_file" class="form-control form-control-sm app-input">
                    </div>
                </div>

                

                <div class="row g-3">
                    
                    <div class="col-12 col-md-6">
                        <label class="app-form-label">URL — Jogos do dia (aba Futebol no app)</label>
                        <input type="url" name="events_url" value="<?php echo htmlspecialchars(unipro_normalize_events_url_string($config['events_url'] ?? '')); ?>" class="form-control app-input" placeholder="https://seu-dominio/card3.php">
                        <p class="small text-dim mt-2 mb-0">No app Legacy Prime (WebView), use a <strong>URL completa</strong> da página HTML dos jogos (ex.: <code class="text-white-50">card3.php</code>). O valor vai no <code class="text-white-50">get_config</code> como <code class="text-white-50">events_url</code> e o app carrega essa página na aba Futebol. Barra final é normalizada pelo painel.</p>
                    </div>
                    
                </div>

                <button type="submit" class="btn btn-app-primary btn-lg w-100 mt-4 py-3">
                    <i class="bi bi-cloud-upload me-2"></i> SALVAR CONFIGURAÇÕES
                </button>
            </form>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="app-card app-card--padded mb-4 border-start border-4 border-info">
            <h3 class="app-card__title mb-3"><i class="bi bi-diagram-3 text-info me-2"></i> O que vai para cada Endpoint</h3>
            <ul class="list-unstyled small text-white mb-0">
                <li class="mb-3">
                    <span class="badge rounded-pill badge-pill-cyan mb-1">get_config</span><br>
                    <span class="text-dim">Envia: DNS, Logo, Fundo, Aviso Global, WhatsApp, Eventos e Banner de News.</span>
                </li>
                
                <li>
                    <span class="badge rounded-pill badge-pill-lime mb-1">Painel Admin</span><br>
                    <span class="text-dim">Gerenciamento via SQLite integrado para resposta ultra-rápida.</span>
                </li>
            </ul>
        </div>

        <div class="app-card app-card--padded border-start border-4 border-warning">
            <h4 class="fw-bold fs-6 mb-2 text-white">Dica de Performance</h4>
            <p class="small text-dim mb-0">Ao fazer upload, o sistema gera uma URL interna. Imagens pesadas podem lentificar o carregamento do app; use arquivos otimizados.</p>
        </div>
    </div>
</div>

<script>
    // Monitor de Preview em Tempo Real para as URLs
    function syncPreview(inputId, imgId) {
        const input = document.getElementById(inputId);
        const img = document.getElementById(imgId);
        input.addEventListener('input', () => {
            if(input.value.trim() !== "") {
                img.src = input.value;
            }
        });
    }
    syncPreview('input_logo', 'prev_logo');
    syncPreview('input_bg', 'prev_bg');
</script>

<?php
require __DIR__ . '/includes/layout-close.php';
require __DIR__ . '/includes/footer.php';
?>