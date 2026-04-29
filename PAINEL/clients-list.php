<?php
require __DIR__ . '/includes/auth.php';
$q = $_SERVER['QUERY_STRING'] ?? '';
header('Location: mac_contas.php' . ($q !== '' ? '?' . $q : ''));
exit;
