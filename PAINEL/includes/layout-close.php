</main>
    </div>
</div>

<script>
    // 1. LÓGICA DO MENU SANDUÍCHE (SIDEBAR)
    const btnSide = document.getElementById('openSidebar');
    const sideBar = document.getElementById('mainSidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (btnSide && sideBar && overlay) {
        btnSide.onclick = () => {
            sideBar.classList.add('active');
            overlay.classList.add('active');
        };
        overlay.onclick = () => {
            sideBar.classList.remove('active');
            overlay.classList.remove('active');
        };
    }

    // 2. LÓGICA DO MENU DO USUÁRIO (DROPDOWN ADMIN)
    const userBtn = document.getElementById('userMenuBtn');
    const userMenu = document.getElementById('userDropdownContent');

    if (userBtn && userMenu) {
        userBtn.addEventListener('click', function(e) {
            e.stopPropagation(); // Impede que o clique feche o menu imediatamente
            
            // Alterna entre mostrar e esconder
            if (userMenu.style.display === 'block') {
                userMenu.style.display = 'none';
            } else {
                userMenu.style.display = 'block';
            }
        });

        // Fecha o menu se o usuário clicar em qualquer lugar fora dele
        document.addEventListener('click', function(e) {
            if (!userMenu.contains(e.target) && e.target !== userBtn) {
                userMenu.style.display = 'none';
            }
        });
    }
</script>