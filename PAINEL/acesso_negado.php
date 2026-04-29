<?php
// acesso_negado.php
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>SISTEMA MONITORADO - ACESSO NEGADO</title>
    <style>
        body { background: #0b0e14; color: #00f2ff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; overflow: hidden; }
        .terminal { background: rgba(0, 0, 0, 0.9); border: 2px solid #00f2ff; padding: 40px; box-shadow: 0 0 30px rgba(0, 242, 255, 0.2); max-width: 600px; width: 90%; position: relative; }
        .terminal::before { content: "SECURITY_ALERT"; position: absolute; top: -15px; left: 20px; background: #ff0055; color: white; padding: 2px 10px; font-weight: bold; font-size: 12px; }
        h1 { color: #ff0055; font-size: 3rem; margin: 0; text-shadow: 2px 2px #000; }
        p { line-height: 1.6; margin: 20px 0; border-left: 3px solid #ff0055; padding-left: 15px; }
        .info { font-family: monospace; color: #555; font-size: 0.9rem; margin-top: 30px; }
        .blink { animation: blinker 1s linear infinite; color: #ff0055; font-weight: bold; }
        @keyframes blinker { 50% { opacity: 0; } }
    </style>
</head>
<body>
    <div class="terminal">
        <h1>VIOLAÇÃO <span class="blink">!</span></h1>
        <p>Tentativa de acesso direto a arquivos sensíveis detectada.<br>
        O arquivo solicitado está protegido por criptografia de nível de sistema.</p>
        
        <div class="info">
            EVENT_LOG: ACCESS_DENIED<br>
            TARGET_IP: <?php echo $_SERVER['REMOTE_ADDR']; ?><br>
            TIMESTAMP: <?php echo date('Y-m-d H:i:s'); ?><br>
            LOCATION: <?php echo $_SERVER['REQUEST_URI']; ?>
        </div>
        <br>
        <a href="login.php" style="color: #00f2ff; text-decoration: none;">[ VOLTAR PARA ÁREA SEGURA ]</a>
    </div>
</body>
</html>