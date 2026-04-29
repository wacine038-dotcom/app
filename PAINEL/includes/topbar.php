<?php
require_once __DIR__ . '/../db.php';

$topTitle = $topTitle ?? 'Painel';
$topSubtitle = $topSubtitle ?? '';
$displayUser = $_SESSION['admin_username'] ?? 'Admin';
$initial = strtoupper(substr($displayUser, 0, 1));

// BUSCA O AVATAR NO BANCO DE DADOS
$avatarImg = '';
try {
    $stmtAvatar = $conn->prepare("SELECT avatar FROM admins WHERE username = ?");
    $stmtAvatar->execute([$displayUser]);
    $adminData = $stmtAvatar->fetch();
    $avatarImg = $adminData['avatar'] ?? '';
} catch (Exception $e) {
    // Se a coluna ainda n«ªo existir no banco, n«ªo faz nada para n«ªo dar erro 500
}
?>
<header class="app-topbar d-flex align-items-center justify-content-between px-3" style="position: relative; z-index: 1000;">
    <div class="d-flex align-items-center">
        <div class="btn-toggle-menu me-3" id="openSidebar" style="cursor: pointer;">
            <i class="bi bi-list fs-3 text-white"></i>
        </div>
        
        <div class="topbar-text">
            <h1 class="app-topbar__title text-white mb-0" style="font-size: 1.2rem;"><?php echo htmlspecialchars($topTitle); ?></h1>
            <?php if ($topSubtitle !== ''): ?>
                <p class="app-topbar__subtitle text-white mb-0 d-none d-md-block" style="opacity: 0.7; font-size: 0.8rem;"><?php echo htmlspecialchars($topSubtitle); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="app-topbar__actions" style="position: relative;">
        <div class="app-user-pill d-flex align-items-center gap-2" id="userMenuBtn" style="background: rgba(255,255,255,0.05); padding: 5px 12px; border-radius: 50px; cursor: pointer; transition: 0.3s;">
            
            <?php if (!empty($avatarImg) && file_exists(__DIR__ . '/../' . $avatarImg)): ?>
                <img src="<?php echo htmlspecialchars($avatarImg); ?>" style="width: 30px; height: 30px; border-radius: 50%; object-fit: cover; border: 1px solid rgba(255,255,255,0.2);">
            <?php else: ?>
                <span class="app-user-pill__avatar bg-primary d-flex align-items-center justify-content-center text-white fw-bold" style="width: 30px; height: 30px; border-radius: 50%; font-size: 0.8rem;"><?php echo htmlspecialchars($initial); ?></span>
            <?php endif; ?>

            <span class="text-white small d-none d-sm-block"><?php echo htmlspecialchars($displayUser); ?></span>
            <i class="bi bi-chevron-down text-white small ms-1" style="font-size: 0.7rem; opacity: 0.6;"></i>
        </div>

        <div id="userDropdownContent" class="user-dropdown-menu" style="display: none;">
            <a href="perfil.php" class="user-dropdown-item">
                <i class="bi bi-person-badge text-info"></i> Meu Perfil
            </a>
            <a href="settings.php" class="user-dropdown-item">
                <i class="bi bi-sliders text-warning"></i> Prefer&ecirc;ncias
            </a>
            <div style="height: 1px; background: rgba(255,255,255,0.1); margin: 5px 0;"></div>
            <a href="logout.php" class="user-dropdown-item text-danger">
                <i class="bi bi-box-arrow-right"></i> Sair do Painel
            </a>
        </div>
    </div>
</header>

<style>
.user-dropdown-menu {
    position: absolute;
    top: 55px;
    right: 0;
    width: 200px;
    background: #161b22;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.5);
    overflow: hidden;
    z-index: 9999;
}
.user-dropdown-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 15px;
    color: #fff;
    text-decoration: none;
    font-size: 0.85rem;
    transition: 0.2s;
}
.user-dropdown-item:hover {
    background: rgba(255,255,255,0.05);
    color: #fff;
}
.app-user-pill:hover {
    background: rgba(255,255,255,0.1) !important;
}
@media (min-width: 992px) { .btn-toggle-menu { display: none; } }
</style>