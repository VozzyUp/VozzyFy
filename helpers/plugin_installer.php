<?php
/**
 * Helper para Instalação/Desinstalação de Plugins
 * Fornece funções auxiliares para gerenciar plugins via ZIP e URL
 */

// Função auxiliar para remover diretório recursivamente
if (!function_exists('rmdir_recursive')) {
    function rmdir_recursive($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        $files = @array_diff(@scandir($dir), ['.', '..']);
        if ($files === false) {
            return false;
        }
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                rmdir_recursive($path);
            } else {
                @unlink($path);
            }
        }
        return @rmdir($dir);
    }
}

if (!function_exists('install_plugin_from_zip')) {
    /**
     * Instala plugin a partir de arquivo ZIP
     * @param string $zip_path Caminho do arquivo ZIP
     * @param string $extract_to Diretório de destino (padrão: plugins/)
     * @return array ['success' => bool, 'message' => string, 'plugin_info' => array|null]
     */
    function install_plugin_from_zip($zip_path, $extract_to = null) {
        global $pdo;
        
        if ($extract_to === null) {
            $extract_to = __DIR__ . '/../plugins/';
        }
        
        // Valida se ZipArchive está disponível
        if (!class_exists('ZipArchive')) {
            return [
                'success' => false,
                'message' => 'Extensão ZipArchive não está disponível no servidor'
            ];
        }
        
        // Valida arquivo ZIP
        if (!file_exists($zip_path)) {
            return [
                'success' => false,
                'message' => 'Arquivo ZIP não encontrado'
            ];
        }
        
        $zip = new ZipArchive();
        $zip_result = $zip->open($zip_path);
        
        if ($zip_result !== TRUE) {
            $zip_errors = [
                ZipArchive::ER_NOZIP => 'Não é um arquivo ZIP válido',
                ZipArchive::ER_OPEN => 'Não foi possível abrir o arquivo',
                ZipArchive::ER_READ => 'Erro ao ler o arquivo',
                ZipArchive::ER_MEMORY => 'Erro de memória',
            ];
            $error_msg = $zip_errors[$zip_result] ?? 'Erro ao abrir ZIP (código: ' . $zip_result . ')';
            return [
                'success' => false,
                'message' => $error_msg
            ];
        }
        
        // Cria diretório temporário
        $temp_dir = sys_get_temp_dir() . '/plugin_' . uniqid();
        if (!@mkdir($temp_dir, 0755, true)) {
            $zip->close();
            return [
                'success' => false,
                'message' => 'Não foi possível criar pasta temporária'
            ];
        }
        
        try {
            // Extrai para pasta temporária
            $zip->extractTo($temp_dir);
            $zip->close();
            
            // Procura pela pasta do plugin (primeira pasta dentro do ZIP)
            $files = @scandir($temp_dir);
            if ($files === false) {
                throw new Exception('Não foi possível ler a pasta temporária');
            }
            
            $plugin_folder = null;
            foreach ($files as $file_item) {
                if ($file_item !== '.' && $file_item !== '..' && is_dir($temp_dir . '/' . $file_item)) {
                    $plugin_folder = $file_item;
                    break;
                }
            }
            
            if (!$plugin_folder) {
                throw new Exception('Estrutura do plugin inválida. O ZIP deve conter uma pasta com o nome do plugin.');
            }
            
            // Valida estrutura do plugin
            $plugin_main_file = $temp_dir . '/' . $plugin_folder . '/' . $plugin_folder . '.php';
            if (!file_exists($plugin_main_file)) {
                throw new Exception('Arquivo principal do plugin não encontrado. Procure por: ' . $plugin_folder . '/' . $plugin_folder . '.php');
            }
            
            // Valida estrutura
            $validation = validate_plugin_structure($temp_dir . '/' . $plugin_folder);
            if (!$validation['valid']) {
                throw new Exception($validation['message'] ?? 'Estrutura do plugin inválida');
            }
            
            // Move para pasta plugins
            $target_dir = $extract_to . $plugin_folder;
            
            // Garante que a pasta plugins existe e é gravável
            if (!is_dir($extract_to)) {
                if (!@mkdir($extract_to, 0755, true)) {
                    throw new Exception('Não foi possível criar a pasta plugins/. Verifique permissões do diretório.');
                }
            }
            
            // Verifica permissões de escrita
            if (!is_writable($extract_to)) {
                throw new Exception('A pasta plugins/ não tem permissão de escrita. Defina permissões 755 ou 775.');
            }
            
            // Remove pasta existente se houver
            if (is_dir($target_dir)) {
                if (function_exists('rmdir_recursive')) {
                    rmdir_recursive($target_dir);
                } else {
                    $existing_files = @array_diff(@scandir($target_dir), ['.', '..']);
                    if ($existing_files !== false) {
                        foreach ($existing_files as $file) {
                            $path = $target_dir . '/' . $file;
                            if (is_dir($path)) {
                                @array_map('unlink', glob($path . '/*'));
                                @rmdir($path);
                            } else {
                                @unlink($path);
                            }
                        }
                    }
                    @rmdir($target_dir);
                }
            }
            
            $source_dir = $temp_dir . '/' . $plugin_folder;
            
            // Tenta primeiro usar rename (mais rápido, funciona no mesmo filesystem)
            if (!@rename($source_dir, $target_dir)) {
                // Se rename falhar (pode ser por filesystems diferentes), usa cópia recursiva
                $last_error = error_get_last();
                $error_msg = $last_error && isset($last_error['message']) ? $last_error['message'] : 'desconhecido';
                error_log("Plugin Installer: rename() falhou, tentando cópia recursiva. Erro: " . $error_msg);
                
                // Função auxiliar para copiar recursivamente
                $copy_recursive = function($src, $dst) use (&$copy_recursive) {
                    if (!is_dir($dst)) {
                        if (!@mkdir($dst, 0755, true)) {
                            return false;
                        }
                    }
                    
                    $files = @scandir($src);
                    if ($files === false) {
                        return false;
                    }
                    
                    foreach ($files as $file) {
                        if ($file === '.' || $file === '..') {
                            continue;
                        }
                        
                        $src_file = $src . '/' . $file;
                        $dst_file = $dst . '/' . $file;
                        
                        if (is_dir($src_file)) {
                            if (!$copy_recursive($src_file, $dst_file)) {
                                return false;
                            }
                        } else {
                            if (@copy($src_file, $dst_file) === false) {
                                error_log("Plugin Installer: Erro ao copiar arquivo $src_file para $dst_file");
                                return false;
                            }
                            // Copia permissões do arquivo original
                            @chmod($dst_file, fileperms($src_file));
                        }
                    }
                    
                    return true;
                };
                
                // Tenta copiar recursivamente
                if (!$copy_recursive($source_dir, $target_dir)) {
                    $error_details = error_get_last();
                    $error_msg = $error_details ? $error_details['message'] : 'Erro desconhecido ao copiar arquivos';
                    error_log("Plugin Installer: Falha ao copiar plugin. Erro: $error_msg | Source: $source_dir | Target: $target_dir");
                    throw new Exception('Não foi possível mover/copiar o plugin para a pasta plugins/. Verifique permissões de escrita na pasta plugins/. Erro: ' . $error_msg);
                }
                
                error_log("Plugin Installer: Plugin copiado com sucesso usando cópia recursiva");
            } else {
                error_log("Plugin Installer: Plugin movido com sucesso usando rename()");
            }
            
            // Limpa pasta temporária
            rmdir_recursive($temp_dir);
            
            // Obtém metadados do plugin
            $plugin_info = get_plugin_metadata($target_dir . '/' . $plugin_folder . '.php');
            if (!$plugin_info) {
                $plugin_info = [
                    'nome' => $plugin_folder,
                    'versao' => '1.0.0',
                    'pasta' => $plugin_folder
                ];
            } else {
                $plugin_info['pasta'] = $plugin_folder;
            }
            
            return [
                'success' => true,
                'message' => 'Plugin instalado com sucesso',
                'plugin_info' => $plugin_info
            ];
            
        } catch (Exception $e) {
            // Limpa pasta temporária em caso de erro
            if (is_dir($temp_dir)) {
                rmdir_recursive($temp_dir);
            }
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}

if (!function_exists('install_plugin_from_url')) {
    /**
     * Baixa e instala plugin a partir de URL
     * @param string $download_url URL do arquivo ZIP para download
     * @return array ['success' => bool, 'message' => string, 'plugin_info' => array|null]
     */
    function install_plugin_from_url($download_url) {
        // Valida URL
        if (!filter_var($download_url, FILTER_VALIDATE_URL)) {
            return [
                'success' => false,
                'message' => 'URL inválida'
            ];
        }
        
        // Valida protocolo (apenas HTTP/HTTPS)
        $parsed = parse_url($download_url);
        if (!in_array($parsed['scheme'] ?? '', ['http', 'https'])) {
            return [
                'success' => false,
                'message' => 'URL deve ser HTTP ou HTTPS'
            ];
        }
        
        // Cria arquivo temporário
        $temp_file = sys_get_temp_dir() . '/plugin_download_' . uniqid() . '.zip';
        
        try {
            // Baixa arquivo
            $ch = curl_init($download_url);
            $fp = fopen($temp_file, 'w');
            
            if (!$fp) {
                throw new Exception('Não foi possível criar arquivo temporário');
            }
            
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutos
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Getfy Plugin Installer/1.0');
            
            $result = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            
            curl_close($ch);
            fclose($fp);
            
            if (!$result || $http_code < 200 || $http_code >= 300) {
                @unlink($temp_file);
                throw new Exception('Erro ao baixar arquivo: ' . ($curl_error ?: 'HTTP ' . $http_code));
            }
            
            // Valida tamanho do arquivo (máximo 50MB)
            $file_size = filesize($temp_file);
            if ($file_size > 50 * 1024 * 1024) {
                @unlink($temp_file);
                throw new Exception('Arquivo muito grande (máximo 50MB)');
            }
            
            // Instala a partir do ZIP
            $result = install_plugin_from_zip($temp_file);
            
            // Remove arquivo temporário
            @unlink($temp_file);
            
            return $result;
            
        } catch (Exception $e) {
            // Remove arquivo temporário em caso de erro
            if (file_exists($temp_file)) {
                @unlink($temp_file);
            }
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}

if (!function_exists('validate_plugin_structure')) {
    /**
     * Valida estrutura de um plugin
     * @param string $plugin_path Caminho do diretório do plugin
     * @return array ['valid' => bool, 'message' => string|null]
     */
    function validate_plugin_structure($plugin_path) {
        // Sanitiza caminho para prevenir path traversal
        $plugin_path = realpath($plugin_path);
        if (!$plugin_path) {
            return [
                'valid' => false,
                'message' => 'Caminho do plugin inválido'
            ];
        }
        
        // Verifica se é diretório
        if (!is_dir($plugin_path)) {
            return [
                'valid' => false,
                'message' => 'Plugin deve ser um diretório'
            ];
        }
        
        // Extrai nome da pasta
        $plugin_folder = basename($plugin_path);
        
        // Sanitiza nome da pasta (apenas letras, números, underscore e hífen)
        if (!preg_match('/^[a-z0-9_-]+$/i', $plugin_folder)) {
            return [
                'valid' => false,
                'message' => 'Nome do plugin contém caracteres inválidos (use apenas letras, números, underscore e hífen)'
            ];
        }
        
        // Verifica se arquivo principal existe
        $main_file = $plugin_path . '/' . $plugin_folder . '.php';
        if (!file_exists($main_file)) {
            return [
                'valid' => false,
                'message' => 'Arquivo principal não encontrado: ' . $plugin_folder . '/' . $plugin_folder . '.php'
            ];
        }
        
        // Verifica se arquivo principal contém definição PLUGIN_LOADED
        $file_content = @file_get_contents($main_file);
        if ($file_content === false) {
            return [
                'valid' => false,
                'message' => 'Não foi possível ler arquivo principal do plugin'
            ];
        }
        
        // Validação básica: verifica se contém algum código PHP válido
        if (strpos($file_content, '<?php') === false) {
            return [
                'valid' => false,
                'message' => 'Arquivo principal deve conter código PHP válido'
            ];
        }
        
        return [
            'valid' => true,
            'message' => null
        ];
    }
}

if (!function_exists('get_plugin_metadata')) {
    /**
     * Extrai metadados do arquivo principal do plugin
     * @param string $plugin_file Caminho do arquivo principal do plugin
     * @return array|null Metadados do plugin ou null se não encontrar
     */
    function get_plugin_metadata($plugin_file) {
        if (!file_exists($plugin_file)) {
            return null;
        }
        
        $content = @file_get_contents($plugin_file);
        if ($content === false) {
            return null;
        }
        
        // Extrai nome da pasta
        $plugin_folder = basename(dirname($plugin_file));
        
        // Tenta ler plugin.json se existir
        $json_file = dirname($plugin_file) . '/plugin.json';
        if (file_exists($json_file)) {
            $json_content = @file_get_contents($json_file);
            if ($json_content !== false) {
                $json = json_decode($json_content, true);
                if ($json && json_last_error() === JSON_ERROR_NONE) {
                    $metadata = [
                        'nome' => $json['nome'] ?? ucfirst(str_replace(['_', '-'], ' ', $plugin_folder)),
                        'versao' => $json['versao'] ?? '1.0.0',
                        'descricao' => $json['descricao'] ?? '',
                        'autor' => $json['autor'] ?? '',
                        'pasta' => $plugin_folder
                    ];
                    return $metadata;
                }
            }
        }
        
        // Tenta extrair do cabeçalho do arquivo PHP (comentários estilo WordPress)
        $metadata = [
            'nome' => ucfirst(str_replace(['_', '-'], ' ', $plugin_folder)),
            'versao' => '1.0.0',
            'descricao' => '',
            'autor' => '',
            'pasta' => $plugin_folder
        ];
        
        // Regex para extrair metadados do cabeçalho
        if (preg_match('/Plugin Name:\s*(.+)/i', $content, $matches)) {
            $metadata['nome'] = trim($matches[1]);
        }
        if (preg_match('/Version:\s*(.+)/i', $content, $matches)) {
            $metadata['versao'] = trim($matches[1]);
        }
        if (preg_match('/Description:\s*(.+)/i', $content, $matches)) {
            $metadata['descricao'] = trim($matches[1]);
        }
        if (preg_match('/Author:\s*(.+)/i', $content, $matches)) {
            $metadata['autor'] = trim($matches[1]);
        }
        
        return $metadata;
    }
}

if (!function_exists('uninstall_plugin')) {
    /**
     * Desinstala plugin (remove arquivos e registros do banco)
     * @param string $plugin_pasta Nome da pasta do plugin
     * @return array ['success' => bool, 'message' => string]
     */
    function uninstall_plugin($plugin_pasta) {
        global $pdo;
        
        // Sanitiza nome da pasta
        $plugin_pasta = preg_replace('/[^a-z0-9_-]/i', '', $plugin_pasta);
        
        if (empty($plugin_pasta)) {
            return [
                'success' => false,
                'message' => 'Nome do plugin inválido'
            ];
        }
        
        $plugin_dir = __DIR__ . '/../plugins/' . $plugin_pasta;
        
        try {
            // Executa uninstall.php se existir
            $uninstall_file = $plugin_dir . '/uninstall.php';
            if (file_exists($uninstall_file)) {
                define('PLUGIN_UNINSTALL', true);
                @require_once $uninstall_file;
            }
            
            // Remove arquivos do plugin
            if (is_dir($plugin_dir)) {
                if (function_exists('rmdir_recursive')) {
                    rmdir_recursive($plugin_dir);
                } else {
                    $files = @array_diff(@scandir($plugin_dir), ['.', '..']);
                    if ($files !== false) {
                        foreach ($files as $file) {
                            $path = $plugin_dir . '/' . $file;
                            if (is_dir($path)) {
                                @array_map('unlink', glob($path . '/*'));
                                @rmdir($path);
                            } else {
                                @unlink($path);
                            }
                        }
                    }
                    @rmdir($plugin_dir);
                }
            }
            
            // Remove do banco de dados
            if (isset($pdo)) {
                try {
                    $stmt = $pdo->prepare("DELETE FROM plugins WHERE pasta = ?");
                    $stmt->execute([$plugin_pasta]);
                } catch (PDOException $e) {
                    // Ignora erro se tabela não existir
                }
            }
            
            return [
                'success' => true,
                'message' => 'Plugin desinstalado com sucesso'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erro ao desinstalar plugin: ' . $e->getMessage()
            ];
        }
    }
}

if (!function_exists('activate_plugin')) {
    /**
     * Ativa plugin no banco de dados
     * @param string $plugin_pasta Nome da pasta do plugin
     * @return bool
     */
    function activate_plugin($plugin_pasta) {
        global $pdo;
        
        if (!isset($pdo)) {
            return false;
        }
        
        try {
            $stmt = $pdo->prepare("UPDATE plugins SET ativo = 1 WHERE pasta = ?");
            $stmt->execute([$plugin_pasta]);
            return true;
        } catch (PDOException $e) {
            error_log("Erro ao ativar plugin: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('deactivate_plugin')) {
    /**
     * Desativa plugin no banco de dados
     * @param string $plugin_pasta Nome da pasta do plugin
     * @return bool
     */
    function deactivate_plugin($plugin_pasta) {
        global $pdo;
        
        if (!isset($pdo)) {
            return false;
        }
        
        try {
            $stmt = $pdo->prepare("UPDATE plugins SET ativo = 0 WHERE pasta = ?");
            $stmt->execute([$plugin_pasta]);
            return true;
        } catch (PDOException $e) {
            error_log("Erro ao desativar plugin: " . $e->getMessage());
            return false;
        }
    }
}

