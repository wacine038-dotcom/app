<?php
/**
 * Tela removida do menu; campos apk_* em config permanecem no banco se já existirem.
 */
require __DIR__ . '/includes/auth.php';
header('Location: dashboard.php');
exit;
