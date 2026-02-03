<?php
/**
 * Script para executar migrations localmente
 * 
 * Uso:
 * - Via navegador: http://localhost/run_migrations.php
 * - Via CLI: php run_migrations.php
 */

// Verificar se está sendo executado via CLI ou navegador
$isCli = php_sapi_name() === 'cli';

// Incluir configuração
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/helpers/migration_helper.php';

// Se for via navegador, verificar se está logado como admin (opcional)
if (!$isCli) {
    session_start();
    // Descomente a linha abaixo se quiser exigir login de admin
    // if (empty($_SESSION['id']) || empty($_SESSION['is_admin'])) {
    //     die('Acesso negado. Faça login como administrador.');
    // }
    
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Executar Migrations</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #1a1a1a;
            color: #fff;
        }
        .success { color: #4ade80; }
        .error { color: #f87171; }
        .warning { color: #fbbf24; }
        .info { color: #60a5fa; }
        pre {
            background: #2a2a2a;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
        h1 { color: #32e768; }
        h2 { color: #60a5fa; margin-top: 30px; }
    </style>
</head>
<body>
    <h1>🔄 Executar Migrations</h1>
    <p class="info">Executando migrations pendentes...</p>
    <hr>
';
}

try {
    // Obter versão atual
    $versionFile = __DIR__ . '/VERSION.txt';
    $currentVersion = '2.5.2'; // Versão padrão
    if (file_exists($versionFile)) {
        $currentVersion = trim(file_get_contents($versionFile));
    }
    
    if (!$isCli) {
        echo "<p><strong>Versão atual do sistema:</strong> <span class='info'>{$currentVersion}</span></p>";
    } else {
        echo "Versão atual do sistema: {$currentVersion}\n";
    }
    
    // Executar migrations
    $results = run_migrations($pdo, $currentVersion);
    
    // Exibir resultados
    if (!$isCli) {
        echo '<h2>📊 Resultados</h2>';
    } else {
        echo "\n=== RESULTADOS ===\n";
    }
    
    // Migrations executadas
    if (!empty($results['executed'])) {
        if (!$isCli) {
            echo '<p class="success"><strong>✅ Migrations Executadas (' . count($results['executed']) . '):</strong></p>';
            echo '<ul>';
            foreach ($results['executed'] as $migration) {
                echo '<li class="success">' . htmlspecialchars($migration) . '</li>';
            }
            echo '</ul>';
        } else {
            echo "\n✅ Migrations Executadas (" . count($results['executed']) . "):\n";
            foreach ($results['executed'] as $migration) {
                echo "  - {$migration}\n";
            }
        }
    } else {
        if (!$isCli) {
            echo '<p class="info">Nenhuma migration executada (todas já foram executadas ou não há migrations pendentes).</p>';
        } else {
            echo "\nℹ️  Nenhuma migration executada.\n";
        }
    }
    
    // Migrations puladas
    if (!empty($results['skipped'])) {
        if (!$isCli) {
            echo '<p class="warning"><strong>⏭️ Migrations Puladas (' . count($results['skipped']) . '):</strong></p>';
            echo '<ul>';
            foreach ($results['skipped'] as $migration) {
                echo '<li class="warning">' . htmlspecialchars($migration) . '</li>';
            }
            echo '</ul>';
        } else {
            echo "\n⏭️  Migrations Puladas (" . count($results['skipped']) . "):\n";
            foreach ($results['skipped'] as $migration) {
                echo "  - {$migration}\n";
            }
        }
    }
    
    // Erros
    if (!empty($results['errors'])) {
        if (!$isCli) {
            echo '<p class="error"><strong>❌ Erros (' . count($results['errors']) . '):</strong></p>';
            echo '<ul>';
            foreach ($results['errors'] as $error) {
                $migration = $error['migration'] ?? 'Desconhecido';
                $errorMsg = $error['error'] ?? 'Erro desconhecido';
                echo '<li class="error"><strong>' . htmlspecialchars($migration) . ':</strong> ' . htmlspecialchars($errorMsg) . '</li>';
            }
            echo '</ul>';
        } else {
            echo "\n❌ Erros (" . count($results['errors']) . "):\n";
            foreach ($results['errors'] as $error) {
                $migration = $error['migration'] ?? 'Desconhecido';
                $errorMsg = $error['error'] ?? 'Erro desconhecido';
                echo "  - {$migration}: {$errorMsg}\n";
            }
        }
    } else {
        if (!$isCli) {
            echo '<p class="success">✅ Nenhum erro encontrado!</p>';
        } else {
            echo "\n✅ Nenhum erro encontrado!\n";
        }
    }
    
    // Resumo
    $totalExecuted = count($results['executed']);
    $totalSkipped = count($results['skipped']);
    $totalErrors = count($results['errors']);
    
    if (!$isCli) {
        echo '<hr>';
        echo '<h2>📋 Resumo</h2>';
        echo '<p><strong>Total executadas:</strong> <span class="success">' . $totalExecuted . '</span></p>';
        echo '<p><strong>Total puladas:</strong> <span class="warning">' . $totalSkipped . '</span></p>';
        echo '<p><strong>Total de erros:</strong> <span class="' . ($totalErrors > 0 ? 'error' : 'success') . '">' . $totalErrors . '</span></p>';
        
        if ($totalExecuted > 0) {
            echo '<p class="success"><strong>✅ Migrations executadas com sucesso!</strong></p>';
        }
        
        echo '</body></html>';
    } else {
        echo "\n=== RESUMO ===\n";
        echo "Total executadas: {$totalExecuted}\n";
        echo "Total puladas: {$totalSkipped}\n";
        echo "Total de erros: {$totalErrors}\n";
        
        if ($totalExecuted > 0) {
            echo "\n✅ Migrations executadas com sucesso!\n";
        }
    }
    
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    
    if (!$isCli) {
        echo '<p class="error"><strong>❌ Erro fatal:</strong> ' . htmlspecialchars($errorMsg) . '</p>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        echo '</body></html>';
    } else {
        echo "\n❌ Erro fatal: {$errorMsg}\n";
        echo $e->getTraceAsString() . "\n";
    }
    
    exit(1);
}

