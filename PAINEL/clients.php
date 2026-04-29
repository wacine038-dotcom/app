<?php
/**
 * Redirecionamento: fluxo M3U removido do menu; gestão em mac_contas.php.
 */
require __DIR__ . '/includes/auth.php';
$q = $_SERVER['QUERY_STRING'] ?? '';
header('Location: mac_contas.php' . ($q !== '' ? '?' . $q : ''));
exit;
