<?php
// Script temporário para rodar a migration
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/migrations/20250208_000000_add_stripe_columns.php';

try {
    $migration = new Migration_20250208_000000_add_stripe_columns();
    echo "Executando migration...\n";
    $migration->up($pdo);
    echo "Migration executada com sucesso!\n";
} catch (Exception $e) {
    echo "Erro ao executar migration: " . $e->getMessage() . "\n";
}
