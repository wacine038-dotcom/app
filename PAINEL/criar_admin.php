<?php
require __DIR__ . '/db.php';

// 1. CRIA A TABELA DE ADMINS (Que não existe no seu db.php original)
$conn->query("CREATE TABLE IF NOT EXISTS `admins` (
  `id` int(11) PRIMARY KEY AUTO_INCREMENT,
  `username` varchar(50) NOT NULL UNIQUE,
  `password` varchar(255) NOT NULL
)");

// 2. DEFINE O USUÁRIO E SENHA DA AULA
$usuario_aula = "admin";
$senha_aula = "aula123"; 

// Aqui o PHP transforma "aula123" em um código seguro (Hash)
$senha_segura = password_hash($senha_aula, PASSWORD_DEFAULT);

// 3. INSERE NO BANCO DE DADOS
$sql = "INSERT IGNORE INTO admins (username, password) VALUES (?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $usuario_aula, $senha_segura);

if ($stmt->execute()) {
    echo "<h2>Sucesso!</h2>";
    echo "O administrador da aula foi criado.<br>";
    echo "<b>Usuário:</b> admin<br>";
    echo "<b>Senha:</b> aula123<br><br>";
    echo "<a href='login.php'>Clique aqui para ir ao Login</a>";
} else {
    echo "Erro ao criar: " . $conn->error;
}
?>