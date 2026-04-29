<?php
$pageTitle = $pageTitle ?? 'Painel';
$bodyClass = $bodyClass ?? 'app-body';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($pageTitle); ?> — UnitV Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <style>
        @media (max-width: 991px) {
            .app-sidebar {
                position: fixed !important;
                left: -280px !important;
                top: 0 !important;
                bottom: 0 !important;
                width: 280px !important;
                z-index: 9999 !important;
                background: linear-gradient(180deg, #080305 0%, #12080c 100%) !important;
                box-shadow: 8px 0 32px rgba(0,0,0,0.65) !important;
                border-right: 1px solid rgba(220,38,38,0.2) !important;
                transition: left 0.3s ease !important;
                display: flex !important;
                flex-direction: column !important;
                visibility: visible !important;
            }
            .app-sidebar.active { left: 0 !important; }

            .app-nav-link {
                color: #ffffff !important;
                display: flex !important;
                opacity: 1 !important;
                padding: 12px 20px !important;
            }
            .app-sidebar__label { color: rgba(248,113,113,0.55) !important; display: block !important; }

            .sidebar-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: transparent;
                z-index: 9998;
            }
            .sidebar-overlay.active { display: block; }
        }
        .btn-toggle-menu { display: none; cursor: pointer; padding: 10px; font-size: 1.8rem; color: #fff; }
        @media (max-width: 991px) { .btn-toggle-menu { display: block; } }
    </style>
</head>
<body class="<?php echo htmlspecialchars($bodyClass); ?>">
<div class="sidebar-overlay" id="sidebarOverlay"></div>