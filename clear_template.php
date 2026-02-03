<?php
/**
 * Script para limpar template de email antigo
 * Execute este arquivo uma vez para limpar o template e usar o padrão
 */

require_once __DIR__ . '/config/config.php';

try {
    $stmt = $pdo->prepare("UPDATE configuracoes SET valor = '' WHERE chave = 'email_template_delivery_html'");
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        echo "✅ Template limpo com sucesso! O sistema agora usará o template padrão da plataforma.\n";
    } else {
        // Se não encontrou registro, cria um vazio
        $stmt = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('email_template_delivery_html', '') ON DUPLICATE KEY UPDATE valor = ''");
        $stmt->execute();
        echo "✅ Template limpo com sucesso! O sistema agora usará o template padrão da plataforma.\n";
    }
    
    // Verifica
    $stmt = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'email_template_delivery_html'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (empty($result['valor'])) {
        echo "✅ Confirmação: Template está vazio. Sistema usará template padrão.\n";
    } else {
        echo "⚠️ Aviso: Template ainda tem conteúdo (" . strlen($result['valor']) . " caracteres).\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
}

