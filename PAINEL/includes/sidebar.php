<?php
$nav = $nav ?? 'dashboard';
?>
<aside class="app-sidebar" id="mainSidebar">
    <div class="app-sidebar__brand">
        <a class="app-sidebar__logo" href="dashboard.php">
            <span class="app-sidebar__logo-mark">UV</span>
            <span class="app-sidebar__logo-text">
                <strong>Legacy Player</strong>
                <span>Painel administrativo</span>
            </span>
        </a>
    </div>
    
    <nav class="app-sidebar__nav" aria-label="Principal">
        <div class="app-sidebar__label">Menu</div>
        <a class="app-nav-link<?php echo $nav === 'dashboard' ? ' is-active' : ''; ?>" href="dashboard.php">
            <i class="bi bi-grid-1x2-fill"></i> Dashboard
        </a>
        
        

        <div class="app-sidebar__label">Sistema</div>
        <a class="app-nav-link<?php echo $nav === 'dns' ? ' is-active' : ''; ?>" href="dns.php">
            <i class="bi bi-hdd-network"></i> Servidores DNS
        </a>
        <a class="app-nav-link<?php echo $nav === 'settings' ? ' is-active' : ''; ?>" href="settings.php">
            <i class="bi bi-sliders"></i> Configurações do app
        </a>
        <a class="app-nav-link<?php echo $nav === 'profile' ? ' is-active' : ''; ?>" href="perfil.php">
            <i class="bi bi-person-badge-fill"></i> Perfil do Admin
        </a>
    </nav>

    <div class="app-sidebar__footer" style="padding: 15px; border-top: 1px solid rgba(255,255,255,0.05);">
        <a class="btn btn-app-danger btn-sm w-100 d-flex align-items-center justify-content-center gap-2 mb-3" href="logout.php">
            <i class="bi bi-box-arrow-right"></i> Sair
        </a>

        <div class="sidebar-credits text-center" style="font-size: 0.7rem;">
            <div class="mb-2" style="color: rgba(255,255,255,0.4);">
                &copy; <?php echo date('Y'); ?> <br>
                <span style="color: #00e5ff;"></span> & <span style="color: #ffc107;"></span>
            </div>
            
            <a href="https://x-ui.shop" target="_blank" class="text-decoration-none d-block">
                <span class="gradient-sidebar-text" style="text-transform: uppercase; font-weight: bold; font-size: 0.65rem;">
                    Desenvolvido por:
                </span>
                <div style="color: #fff; margin-top: 2px;">
                    <i class="bi bi-globe me-1" style="color: #00e5ff;"></i> X-UI DEV
                </div>
            </a>
        </div>
    </div>
</aside>

<style>
/* Gradiente para a Sidebar */
.gradient-sidebar-text {
    background: linear-gradient(270deg, #ffffff, #fca5a5, #ef4444, #fecaca, #ffffff);
    background-size: 400% 400%;
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    animation: changeColorSidebar 8s ease infinite;
}

@keyframes changeColorSidebar {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}
</style>
