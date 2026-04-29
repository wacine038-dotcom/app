<?php
/**
 * Tela removida do menu; API (api.php) pode continuar usando chatbot_config se configurado externamente.
 */
require __DIR__ . '/includes/auth.php';
header('Location: dashboard.php');
exit;
