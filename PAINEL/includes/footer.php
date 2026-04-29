
<style>
/* 1. Efeito de Gradiente que Muda de Cor (Animação) */
.gradient-text-fixed {
    background: linear-gradient(270deg, #ffffff, #fca5a5, #ef4444, #ffffff);
    background-size: 600% 600%;
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    
    /* Animação para mudar de cor */
    animation: changeColorGradient 10s ease infinite;
}

@keyframes changeColorGradient {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

/* 2. Estilo do Link com ícone (Start 3D Mídia) */
.site-link {
    display: inline-flex;
    align-items: center;
    transition: 0.3s;
    color: #fff !important;
}

.site-link:hover {
    color: #fca5a5 !important;
    transform: translateY(-1px);
}

.site-link i {
    font-size: 0.9rem;
}

/* Ajustes gerais do footer */
.app-footer {
    border-top: 1px solid rgba(255, 255, 255, 0.05);
    padding-top: 20px;
    clear: both;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php
if (!empty($footerScripts ?? null)) {
    echo $footerScripts;
}
?>
</body>
</html>