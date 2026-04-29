<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/db.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $novo_user = trim($_POST['username'] ?? '');
    $nova_senha = $_POST['password'] ?? '';
    $admin_atual = $_SESSION['admin_username'];
    $avatar_atual = $_POST['current_avatar'] ?? '';

    // --- LÓGICA DE UPLOAD DE AVATAR (AGREGADA) ---
    if (!empty($_FILES['avatar_file']['name'])) {
        $targetDir = "uploads/avatars/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
        
        $fileName = time() . "_" . basename($_FILES["avatar_file"]["name"]);
        $targetFilePath = $targetDir . $fileName;
        $fileType = pathinfo($targetFilePath, PATHINFO_EXTENSION);

        if (in_array(strtolower($fileType), ['jpg', 'jpeg', 'png', 'webp'])) {
            if (move_uploaded_file($_FILES["avatar_file"]["tmp_name"], $targetFilePath)) {
                $avatar_atual = $targetFilePath;
            }
        } else {
            $error = 'Apenas imagens JPG, PNG ou WEBP são permitidas.';
        }
    }

    if (empty($error) && !empty($novo_user)) {
        try {
            if (!empty($nova_senha)) {
                // Atualiza Usuário, Senha e Avatar
                $hash = password_hash($nova_senha, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE admins SET username = ?, password = ?, avatar = ? WHERE username = ?");
                $executou = $stmt->execute([$novo_user, $hash, $avatar_atual, $admin_atual]);
            } else {
                // Atualiza apenas Usuário e Avatar
                $stmt = $conn->prepare("UPDATE admins SET username = ?, avatar = ? WHERE username = ?");
                $executou = $stmt->execute([$novo_user, $avatar_atual, $admin_atual]);
            }

            if ($executou) {
                $_SESSION['admin_username'] = $novo_user;
                $success = 'Dados atualizados com sucesso!';
            } else {
                $error = 'Erro ao atualizar banco de dados.';
            }
        } catch (Exception $e) {
            $error = 'Erro técnico: ' . $e->getMessage();
        }
    } else if (empty($novo_user)) {
        $error = 'O nome de usuário não pode estar vazio.';
    }
}

// Busca os dados atuais do admin para carregar no formulário (incluindo o avatar)
$stmt = $conn->prepare("SELECT * FROM admins WHERE username = ?");
$stmt->execute([$_SESSION['admin_username']]);
$admin = $stmt->fetch();

$pageTitle = 'Minha Conta';
$nav = 'profile'; 
$topTitle = 'Perfil do Administrador';
$topSubtitle = 'Gerencie suas credenciais de acesso ao console.';

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/layout-open.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="app-card app-card--padded">
            <div class="app-card__header mb-4">
                <div>
                    <h2 class="app-card__title"><i class="bi bi-shield-lock-fill text-warning me-2"></i>Segurança da Conta</h2>
                    <p class="app-card__desc">Altere seu nome de usuário, avatar ou defina uma nova senha de acesso.</p>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-app alert-app--success mb-4">
                    <i class="bi bi-check-circle-fill me-2"></i><?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-app alert-app--danger mb-4">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="current_avatar" value="<?php echo htmlspecialchars($admin['avatar'] ?? ''); ?>">

                <div class="text-center mb-4">
                    <div class="position-relative d-inline-block">
                        <img src="<?php echo ($admin['avatar'] ?? '') ?: 'https://placehold.co/100x100?text=Avatar'; ?>" 
                             id="avatarPreview"
                             style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid rgba(255,255,255,0.1);">
                        <label for="avatar_file" class="btn btn-sm btn-primary position-absolute bottom-0 end-0 rounded-circle" style="padding: 5px 8px;">
                            <i class="bi bi-camera-fill"></i>
                            <input type="file" name="avatar_file" id="avatar_file" hidden onchange="document.getElementById('avatarPreview').src = window.URL.createObjectURL(this.files[0])">
                        </label>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="app-form-label" for="username">Nome de Usuário</label>
                    <input type="text" name="username" id="username" 
                           class="form-control form-control-lg app-input" 
                           value="<?php echo htmlspecialchars($admin['username']); ?>" required>
                    <div class="form-text text-dim">Este é o nome usado para entrar no painel.</div>
                </div>

                <div class="mb-4">
                    <label class="app-form-label" for="password">Nova Senha</label>
                    <input type="password" name="password" id="password" 
                           class="form-control form-control-lg app-input" 
                           placeholder="Deixe em branco para manter a atual">
                    <div class="form-text text-dim">Use uma senha forte com letras e números.</div>
                </div>

                <hr class="my-4" style="border-color: rgba(255,255,255,0.1);">

                <div class="d-flex justify-content-end gap-3">
                    <a href="dashboard.php" class="btn btn-app-ghost px-4">Cancelar</a>
                    <button type="submit" class="btn btn-app-primary px-5">
                        <i class="bi bi-save2 me-2"></i>Salvar Alterações
                    </button>
                </div>
            </form>
        </div>

        <div class="app-card app-card--padded mt-4" style="border-left: 3px solid var(--warning);">
            <div class="d-flex align-items-start gap-3">
                <i class="bi bi-info-circle-fill text-warning fs-4"></i>
                <div>
                    <h4 class="fs-6 text-white mb-1">Dica de Segurança</h4>
                    <p class="small text-dim mb-0">Ao alterar sua senha, você garante que apenas você tenha acesso às configurações de DNS e faturamento dos clientes. Nunca compartilhe essas credenciais.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require __DIR__ . '/includes/layout-close.php';
require __DIR__ . '/includes/footer.php';
?>