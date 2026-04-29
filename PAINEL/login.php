<?php
session_start();

if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: dashboard.php');
    exit;
}

require __DIR__ . '/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';

    // TRADUÇÃO PARA SQLITE: Usando a lógica do seu novo db.php
    try {
        $stmt = $conn->prepare('SELECT id, username, password FROM admins WHERE username = ?');
        $stmt->execute([$user]);
        $admin = $stmt->fetch(); // Captura os dados do administrador

        if ($admin) {
            if (password_verify($pass, $admin['password'])) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_username'] = $admin['username'];
                header('Location: dashboard.php');
                exit;
            }
        }
        
        $error = 'Credenciais inválidas.';
        
        // Lógica de bloqueio por tentativas (Opcional)
        if(!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
        $_SESSION['login_attempts']++;
        
        if($_SESSION['login_attempts'] > 4) {
            header('Location: acesso_negado.php');
            exit;
        }

    } catch (Exception $e) {
        $error = 'Erro de conexão com o banco local.';
    }
}

$pageTitle = 'Entrar';
$bodyClass = '';
require __DIR__ . '/includes/head.php';
?>

<div class="login-page">
    <aside class="login-page__aside">
        <div>
            <div class="login-brand">
                <span class="login-brand__mark">UV</span>
                <div>
                    <strong class="d-block fs-5 text-white">Legacy Player</strong>
                    <span class="small text-muted">Console do revendedor</span>
                </div>
            </div>
            <h2 class="login-aside-title mt-5 pt-3">Gerencie clientes, DNS e branding em um só lugar.</h2>
            <ul class="login-aside-list">
                <li><i class="bi bi-check-circle-fill"></i> Ativação de DNS</li>
                <li><i class="bi bi-check-circle-fill"></i> Configurações sincronizadas com a API do app</li>
                <li><i class="bi bi-check-circle-fill"></i> Painel escuro otimizado para uso prolongado</li>
            </ul>
        </div>
        <p class="small text-muted mb-0 position-relative" style="z-index:1;">© <?php echo date('Y'); ?> Legacy player — acesso restrito.</p>
    </aside>
    <div class="login-page__main">
        <div class="login-card">
            <div class="login-card__inner">
                <h1>Acessar painel</h1>
                <p class="lead-in">Use o usuário e senha cadastrados na tabela <code class="text-warning">admins</code>.</p>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-app alert-app--danger" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" autocomplete="on">
                    <div class="mb-3">
                        <label class="app-form-label" for="username">Usuário</label>
                        <input type="text" name="username" id="username" class="form-control form-control-lg app-input" required autofocus autocomplete="username">
                    </div>
                    <div class="mb-4">
                        <label class="app-form-label" for="password">Senha</label>
                        <input type="password" name="password" id="password" class="form-control form-control-lg app-input" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-app-primary btn-lg w-100 py-2">
                        <i class="bi bi-box-arrow-in-right me-2"></i> Entrar com segurança
                    </button>
                </form>
            </div>
            <p class="text-center small text-muted mt-4 mb-0">Conexão protegida por sessão PHP. Saia ao terminar em computadores compartilhados.</p>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>