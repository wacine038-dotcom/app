<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/db.php';

$dnsSaved = false;

// ==========================================
// LÓGICA DE SALVAMENTO (SQLITE)
// ==========================================
if (isset($_POST['save_dns'])) {
    $dns = [];
    if (!empty($_POST['dns_items']) && is_array($_POST['dns_items'])) {
        foreach ($_POST['dns_items'] as $d) {
            $t = trim((string) $d);
            if ($t !== '') {
                $dns[] = $t;
            }
        }
    }
    
    // No SQLite/PDO, não usamos escape_string. O implode gera a string para o execute.
    $dns_list_string = implode(',', $dns);

    // Verifica se a configuração ID=1 existe
    $existsStmt = $conn->prepare('SELECT id FROM config WHERE id = 1');
    $existsStmt->execute();
    $exists = $existsStmt->fetch();

    if (!$exists) {
        // Se não existir, cria o registro inicial
        $conn->query('INSERT INTO config (id) VALUES (1)');
    }
    
    // Atualiza a lista usando Prepared Statement para segurança
    $updateStmt = $conn->prepare("UPDATE config SET dns_list = ? WHERE id = 1");
    $updateStmt->execute([$dns_list_string]);
    
    $dnsSaved = true;
}

// ==========================================
// BUSCA DE CONFIGURAÇÃO ATUAL
// ==========================================
$config_res = $conn->prepare('SELECT * FROM config WHERE id = 1');
$config_res->execute();
$config = $config_res->fetch();

// Se por algum motivo ainda não existir após o SELECT, garante a criação
if (!$config) {
    $conn->query('INSERT INTO config (id) VALUES (1)');
    $config_res->execute();
    $config = $config_res->fetch();
}

$dns_raw = trim((string) ($config['dns_list'] ?? ''));
$dns_array = $dns_raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $dns_raw)), static function ($s) {
    return $s !== '';
}));

$pageTitle = 'Servidores DNS';
$nav = 'dns';
$topTitle = 'Servidores DNS';
$topSubtitle = 'Lista enviada ao app em get_config como array. Salve para aplicar.';

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/layout-open.php';
?>

<?php if ($dnsSaved): ?>
    <div class="alert alert-app alert-app--success">
        <i class="bi bi-check-circle-fill me-2"></i> DNS salvos. O app pode levar até ~1 minuto para refletir o cache de <code class="text-warning">get_config</code>.
    </div>
<?php endif; ?>

<div class="row g-4 justify-content-center">
    <div class="col-12 col-xl-8 col-xxl-7">
        <div class="app-card app-card--padded border-start border-4 border-info">
            <div class="app-card__header">
                <div>
                    <h2 class="app-card__title">Endereços disponíveis no aplicativo</h2>
                    <p class="app-card__desc">Adicione um servidor por vez; cada linha abaixo vira um item na lista da API.</p>
                </div>
                <a href="settings.php" class="btn btn-app-ghost btn-sm"><i class="bi bi-sliders me-1"></i> Outras configurações</a>
            </div>
            <form method="POST" id="form-dns">
                <label class="app-form-label">Novo DNS ou URL base</label>
                <div class="dns-add-row mb-2">
                    <input type="text" class="form-control app-input" id="dns-new" placeholder="Ex: dns1.meuservidor.com ou http://ip:porta" autocomplete="off">
                    <button type="button" class="btn btn-app-accent" id="dns-add-btn"><i class="bi bi-plus-lg"></i> Adicionar</button>
                </div>
                <div class="dns-stack mt-3" id="dns-stack" aria-live="polite">
                    <?php if (count($dns_array) === 0): ?>
                        <div class="dns-empty text-white-50" id="dns-empty">Nenhum DNS cadastrado. Use o campo acima.</div>
                    <?php endif; ?>
                    <?php foreach ($dns_array as $d): ?>
                        <div class="dns-chip">
                            <span class="dns-chip__text"><?php echo htmlspecialchars($d); ?></span>
                            <input type="hidden" name="dns_items[]" value="<?php echo htmlspecialchars($d); ?>">
                            <button type="button" class="dns-chip__remove" title="Remover" aria-label="Remover">&times;</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" name="save_dns" class="btn btn-app-primary btn-lg w-100 mt-4">
                    <i class="bi bi-cloud-upload me-2"></i> Salvar lista de DNS
                </button>
            </form>
        </div>
    </div>
    <div class="col-12 col-xl-4 col-xxl-4">
        <div class="app-card app-card--padded border-start border-4 border-violet">
            <h3 class="app-card__title mb-3"><i class="bi bi-hdd-network text-warning me-2"></i> Como o app usa</h3>
            <p class="small text-white mb-3">A ação <code class="text-warning">get_config</code> devolve o campo <strong>dns</strong> como array de strings, na ordem em que você cadastrou aqui.</p>
            <p class="small text-dim mb-0">O DNS individual de cada cliente (M3U) continua em <strong>Lista de clientes</strong> / cadastro — não é substituído por esta lista.</p>
        </div>
    </div>
</div>

<script>
(function () {
  var stack = document.getElementById('dns-stack');
  var input = document.getElementById('dns-new');
  var btn = document.getElementById('dns-add-btn');
  if (!stack || !input || !btn) return;

  function addDns(val) {
    if (!val || !val.trim()) return;
    val = val.trim();
    
    var empty = document.getElementById('dns-empty');
    if (empty) empty.remove();
    
    var chip = document.createElement('div');
    chip.className = 'dns-chip';
    chip.innerHTML = '<span class="dns-chip__text"></span>' +
      '<input type="hidden" name="dns_items[]" value="">' +
      '<button type="button" class="dns-chip__remove" title="Remover" aria-label="Remover">&times;</button>';
    
    chip.querySelector('.dns-chip__text').textContent = val;
    chip.querySelector('input').value = val;
    
    chip.querySelector('.dns-chip__remove').addEventListener('click', function () {
      chip.remove();
      checkEmpty();
    });
    
    stack.appendChild(chip);
    input.value = '';
    input.focus();
  }

  function checkEmpty() {
    if (stack && stack.querySelectorAll('.dns-chip').length === 0) {
        if (!document.getElementById('dns-empty')) {
            var d = document.createElement('div');
            d.className = 'dns-empty text-white-50';
            d.id = 'dns-empty';
            d.textContent = 'Nenhum DNS cadastrado. Use o campo acima.';
            stack.appendChild(d);
        }
    }
  }

  btn.addEventListener('click', function() { addDns(input.value); });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      addDns(input.value);
    }
  });

  stack.addEventListener('click', function (e) {
    if (e.target.classList.contains('dns-chip__remove')) {
      var chip = e.target.closest('.dns-chip');
      if (chip) {
          chip.remove();
          checkEmpty();
      }
    }
  });
})();
</script>

<?php
require __DIR__ . '/includes/layout-close.php';
require __DIR__ . '/includes/footer.php';
?>