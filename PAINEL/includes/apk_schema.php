<?php
/**
 * Garante colunas de distribuição de APK na tabela config (SQLite).
 * Usa PDO direto para não depender de wrappers; pode ser chamado várias vezes.
 */
function unipro_ensure_apk_schema($pdo) {
    if (!$pdo instanceof PDO) {
        return;
    }

    $stmt = $pdo->query('PRAGMA table_info(config)');
    if ($stmt === false) {
        return;
    }
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($cols)) {
        $cols = [];
    }
    $have = [];
    foreach ($cols as $row) {
        if (isset($row['name'])) {
            $have[] = $row['name'];
        }
    }

    $alters = [
        'apk_version_code' => 'INTEGER DEFAULT 0',
        'apk_version_name' => "TEXT DEFAULT ''",
        'apk_download_url' => "TEXT DEFAULT ''",
    ];

    foreach ($alters as $column => $sqlType) {
        if (in_array($column, $have, true)) {
            continue;
        }
        try {
            $pdo->exec("ALTER TABLE config ADD COLUMN {$column} {$sqlType}");
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'duplicate column') !== false) {
                continue;
            }
            throw $e;
        }
    }
}
