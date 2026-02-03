<?php
/**
 * Migration: Banner hero desktop, mobile e logo no curso
 * Adiciona em cursos: banner_desktop_url, banner_mobile_url, banner_logo_url
 */

class Migration_20250131_120000_banner_hero_desktop_mobile_logo {

    public function getVersion() {
        return '1.0.0';
    }

    public function up($pdo) {
        $columns = [
            'banner_desktop_url' => "ALTER TABLE `cursos` ADD COLUMN `banner_desktop_url` VARCHAR(255) DEFAULT NULL COMMENT 'Imagem hero desktop 2560x1280' AFTER `banner_url`",
            'banner_mobile_url'  => "ALTER TABLE `cursos` ADD COLUMN `banner_mobile_url` VARCHAR(255) DEFAULT NULL COMMENT 'Imagem hero mobile 1630x1920' AFTER `banner_desktop_url`",
            'banner_logo_url'    => "ALTER TABLE `cursos` ADD COLUMN `banner_logo_url` VARCHAR(255) DEFAULT NULL COMMENT 'Logo no canto esquerdo do hero' AFTER `banner_mobile_url`",
        ];
        foreach ($columns as $col => $sql) {
            $stmt = $pdo->query("SHOW COLUMNS FROM `cursos` LIKE '" . $col . "'");
            if ($stmt->rowCount() === 0) {
                $pdo->exec($sql);
            }
        }
    }

    public function down($pdo) {
        foreach (['banner_logo_url', 'banner_mobile_url', 'banner_desktop_url'] as $col) {
            try {
                $pdo->exec("ALTER TABLE `cursos` DROP COLUMN `" . $col . "`");
            } catch (PDOException $e) {
                // ignorar se coluna não existir
            }
        }
    }
}
