<?php
// db.php - Versão SQLite Compatível
$sqlite_file = __DIR__ . "/database.sqlite";

try {
    $pdo = new PDO("sqlite:" . $sqlite_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 8000');

    require_once __DIR__ . '/includes/apk_schema.php';
    unipro_ensure_apk_schema($pdo);

    require_once __DIR__ . '/includes/mac_accounts_schema.php';
    unipro_ensure_mac_accounts_schema($pdo);

    class SQLiteCompat {
        private $pdo;
        public function __construct($pdo) { $this->pdo = $pdo; }

        public function query($sql) {
            // TRADUÇÃO DE EMERGÊNCIA: Remove funções que o SQLite não entende
            // Se a consulta for a de "7 dias", simplificamos para não dar erro na aula
            if (strpos($sql, 'INTERVAL 7 DAY') !== false) {
                $sql = "SELECT COUNT(*) AS c FROM clients WHERE status = 'active' AND expiry_date <= date('now', '+7 days')";
            }
            
            $stmt = $this->pdo->query($sql);
            return new SQLiteResult($stmt);
        }

        public function prepare($sql) { return $this->pdo->prepare($sql); }
        public function set_charset($charset) { return true; } // SQLite não precisa
    }

    class SQLiteResult {
        private $stmt;
        public $num_rows;
        public function __construct($stmt) { 
            $this->stmt = $stmt; 
            // Tentativa de contar linhas para manter compatibilidade
            $all = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->num_rows = count($all);
            $this->data = $all;
            $this->index = 0;
        }
        public function fetch_assoc() { 
            return $this->data[$this->index++] ?? null; 
        }
    }

    $conn = new SQLiteCompat($pdo);

} catch (PDOException $e) {
    die("Erro SQLite: " . $e->getMessage());
}