<?php
/**
 * Migration: Criar tabela secoes, secao_produtos, adicionar secao_id em modulos e conteudo_extra em secoes
 * Versão mínima: 1.0.0
 * Hierarquia: Curso -> Seção -> Módulo -> Aula
 */

class Migration_20250131_100000_criar_secoes_e_vincular_modulos {
    /**
     * Versão mínima do sistema requerida para executar esta migration
     * @return string
     */
    public function getVersion() {
        return '1.0.0';
    }

    /**
     * Executa a migration (aplicar mudanças)
     * @param PDO $pdo
     */
    public function up($pdo) {
        // 1. Criar tabela secoes
        $stmt = $pdo->query("SHOW TABLES LIKE 'secoes'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `secoes` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `curso_id` INT(11) NOT NULL,
                    `titulo` VARCHAR(255) NOT NULL,
                    `tipo_secao` ENUM('curso','outros_produtos','extra') NOT NULL DEFAULT 'curso' COMMENT 'curso=conteúdo padrão, outros_produtos=lista de produtos, extra=conteúdo livre',
                    `ordem` INT(11) NOT NULL DEFAULT 0,
                    `conteudo_extra` TEXT NULL COMMENT 'Conteúdo HTML/texto para tipo extra',
                    `data_criacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_secoes_curso_id` (`curso_id`),
                    CONSTRAINT `fk_secoes_curso` FOREIGN KEY (`curso_id`) REFERENCES `cursos` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        // 2. Adicionar secao_id em modulos (se não existir)
        $stmt = $pdo->query("SHOW COLUMNS FROM modulos LIKE 'secao_id'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `modulos` ADD COLUMN `secao_id` INT(11) NULL AFTER `curso_id`, ADD KEY `idx_modulos_secao_id` (`secao_id`)");
            $pdo->exec("ALTER TABLE `modulos` ADD CONSTRAINT `fk_modulos_secao` FOREIGN KEY (`secao_id`) REFERENCES `secoes` (`id`) ON DELETE SET NULL");
        }

        // 3. Criar tabela secao_produtos (para seções tipo outros_produtos)
        $stmt = $pdo->query("SHOW TABLES LIKE 'secao_produtos'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `secao_produtos` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `secao_id` INT(11) NOT NULL,
                    `produto_id` INT(11) NOT NULL,
                    `ordem` INT(11) NOT NULL DEFAULT 0,
                    `imagem_capa_url` VARCHAR(255) NULL COMMENT 'Caminho da imagem de capa do produto na seção',
                    `link_personalizado` VARCHAR(500) NULL COMMENT 'Link personalizado para página de vendas. Se NULL, usa checkout_hash padrão',
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_secao_produto` (`secao_id`, `produto_id`),
                    KEY `idx_secao_produtos_secao_id` (`secao_id`),
                    KEY `idx_secao_produtos_produto_id` (`produto_id`),
                    CONSTRAINT `fk_secao_produtos_secao` FOREIGN KEY (`secao_id`) REFERENCES `secoes` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_secao_produtos_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        // 4. Opcional: criar uma seção "Conteúdo do curso" por curso e atribuir módulos existentes
        $stmt = $pdo->query("SELECT id FROM cursos");
        $cursos = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cursos as $curso_id) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM secoes WHERE curso_id = ?");
            $stmt->execute([$curso_id]);
            if ($stmt->fetchColumn() == 0) {
                $pdo->prepare("INSERT INTO secoes (curso_id, titulo, tipo_secao, ordem) VALUES (?, 'Conteúdo do curso', 'curso', 0)")->execute([$curso_id]);
                $secao_id = $pdo->lastInsertId();
                $pdo->prepare("UPDATE modulos SET secao_id = ? WHERE curso_id = ? AND secao_id IS NULL")->execute([$secao_id, $curso_id]);
            }
        }
    }

    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        try {
            $pdo->exec("ALTER TABLE `modulos` DROP FOREIGN KEY `fk_modulos_secao`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        try {
            $pdo->exec("ALTER TABLE `modulos` DROP COLUMN `secao_id`");
        } catch (PDOException $e) {
            // Ignorar
        }
        try {
            $pdo->exec("ALTER TABLE `secao_produtos` DROP FOREIGN KEY `fk_secao_produtos_secao`");
            $pdo->exec("ALTER TABLE `secao_produtos` DROP FOREIGN KEY `fk_secao_produtos_produto`");
        } catch (PDOException $e) {
            // Ignorar
        }
        $pdo->exec("DROP TABLE IF EXISTS `secao_produtos`");
        try {
            $pdo->exec("ALTER TABLE `secoes` DROP FOREIGN KEY `fk_secoes_curso`");
        } catch (PDOException $e) {
            // Ignorar
        }
        $pdo->exec("DROP TABLE IF EXISTS `secoes`");
    }
}
