<?php
require_once __DIR__ . '/../config/config.php'; // Inclui o arquivo de configuração e a conexão PDO

// Define cabeçalhos para prevenir caching e garantir que a página seja sempre atualizada
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT'); // Uma data no passado

// Verifica se o usuário está logado (para visualização no painel do editor)
// Se o site clonado deve ser público (via slug e status published), permitimos acesso sem login.

$usuario_id_logado = $_SESSION['id'] ?? null;
$cloned_site_id = $_GET['id'] ?? null;
$slug = $_GET['slug'] ?? null;

if (!$cloned_site_id && !$slug) {
    http_response_code(400);
    echo "<!DOCTYPE html><html lang='pt-BR'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'><title>Erro</title><style>body{font-family:sans-serif;text-align:center;padding:50px;background-color:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}h1{color:#721c24;}p{font-size:1.2em;}</style></head><body><h1>Erro na Requisição</h1><p>ID ou Slug do site clonado é obrigatório para visualização.</p></body></html>";
    exit;
}

try {
    // Constrói a query dinamicamente baseada no ID ou Slug
    $sql = "
        SELECT 
            cs.id, cs.usuario_id, cs.edited_html, cs.title, cs.status, cs.slug,
            css.facebook_pixel_id, css.google_analytics_id, css.custom_head_scripts
        FROM cloned_sites cs
        LEFT JOIN cloned_site_settings css ON cs.id = css.cloned_site_id
        WHERE ";
    
    $params = [];
    if ($slug) {
        $sql .= "cs.slug = :slug";
        $params[':slug'] = $slug;
    } else {
        $sql .= "cs.id = :id";
        $params[':id'] = $cloned_site_id;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $site_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$site_data) {
        http_response_code(404);
        echo "<!DOCTYPE html><html lang='pt-BR'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'><title>Não Encontrado</title><style>body{font-family:sans-serif;text-align:center;padding:50px;background-color:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}h1{color:#721c24;}p{font-size:1.2em;}</style></head><body><h1>Página Não Encontrada</h1><p>O site clonado não foi encontrado.</p></body></html>";
        exit;
    }

    // Verificação de permissão
    $is_published = ($site_data['status'] === 'published');
    $is_owner = ($usuario_id_logado && $usuario_id_logado == $site_data['usuario_id']);

    // Se não for público E não for o dono (ou não estiver logado), nega acesso.
    if (!$is_published && !$is_owner) {
        http_response_code(403);
        echo "<!DOCTYPE html><html lang='pt-BR'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'><title>Acesso Negado</title><style>body{font-family:sans-serif;text-align:center;padding:50px;background-color:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}h1{color:#721c24;}p{font-size:1.2em;}</style></head><body><h1>Acesso Não Autorizado</h1><p>Você precisa estar logado ou ser o proprietário para visualizar este rascunho.</p></body></html>";
        exit;
    }
    
    // Atualiza o ID para o correto (caso tenha vindo por slug)
    $cloned_site_id = $site_data['id'];

    $edited_html = $site_data['edited_html'];
    $page_title = htmlspecialchars($site_data['title'] ?? 'Site Clonado');
    $facebook_pixel_id = htmlspecialchars($site_data['facebook_pixel_id'] ?? '');
    $google_analytics_id = htmlspecialchars($site_data['google_analytics_id'] ?? '');
    $custom_head_scripts = $site_data['custom_head_scripts'] ?? ''; // Pode conter HTML, não usar htmlspecialchars

    // LOG DE DEBUG: Exibe o Facebook Pixel ID obtido do banco de dados para este request.
    error_log("cloned_site_viewer.php: Fetching site ID {$cloned_site_id}. Facebook Pixel ID from DB: '{$facebook_pixel_id}'");

    // Usar DOMDocument para injetar scripts na head
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // Suprime erros de HTML malformado

    // O edited_html pode ser um fragmento ou um documento completo.
    // Para garantir que sempre temos um <html> e <head> para injetar scripts,
    // tentamos carregar como HTML normal. Se for um fragmento de body, DOMDocument
    // adicionará o <html> e <head> automaticamente.
    $dom->loadHTML($edited_html);
    libxml_clear_errors();

    $head_node = $dom->getElementsByTagName('head')->item(0);

    // Se a tag <head> não existir após loadHTML (o que é raro para documentos HTML válidos, mas possível para fragmentos),
    // tente encontrá-la ou criá-la.
    if (!$head_node) {
        $html_node = $dom->getElementsByTagName('html')->item(0);
        if (!$html_node) {
            // Se nem <html> existe, cria-o e o <head> dentro.
            $html_node = $dom->createElement('html');
            $dom->appendChild($html_node);
        }
        $head_node = $dom->createElement('head');
        // Insere o <head> no início do <html>
        if ($html_node->firstChild) {
            $html_node->insertBefore($head_node, $html_node->firstChild);
        } else {
            $html_node->appendChild($head_node);
        }
    }

    if ($head_node) {
        // 1. Injeta o título da página
        $current_title_nodes = $head_node->getElementsByTagName('title');
        if ($current_title_nodes->length > 0) {
            $current_title_nodes->item(0)->textContent = $page_title;
        } else {
            $title_element = $dom->createElement('title', $page_title);
            $head_node->appendChild($title_element);
        }

        // 2. Facebook Pixel
        if (!empty($facebook_pixel_id)) {
            $fb_script = $dom->createElement('script');
            $fb_script->textContent = "
                !function(f,b,e,v,n,t,s)
                {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
                n.callMethod.apply(n,arguments):n.queue.push(arguments)};
                if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
                n.queue=[];t=b.createElement(e);t.async=!0;
                t.src=v;s=b.getElementsByTagName(e)[0];
                s.parentNode.insertBefore(t,s)}(window, document,'script',
                'https://connect.facebook.net/en_US/fbevents.js');
                fbq('init', '{$facebook_pixel_id}');
                fbq('track', 'PageView');
                console.log('Facebook Pixel ID injetado no cliente:', '{$facebook_pixel_id}'); // Debug log para o navegador
            ";
            $head_node->appendChild($fb_script);
            
            // Add noscript fallback for Facebook Pixel
            $fb_noscript = $dom->createElement('noscript');
            $fb_img = $dom->createElement('img');
            $fb_img->setAttribute('height', '1');
            $fb_img->setAttribute('width', '1');
            $fb_img->setAttribute('style', 'display:none');
            $fb_img->setAttribute('src', "https://www.facebook.com/tr?id={$facebook_pixel_id}&ev=PageView&noscript=1");
            $fb_noscript->appendChild($fb_img);
            $head_node->appendChild($fb_noscript);
        }

        // 3. Google Analytics / Google Tag Manager
        if (!empty($google_analytics_id)) {
            $ga_script = $dom->createElement('script');
            $ga_script->setAttribute('async', '');
            $ga_script->setAttribute('src', "https://www.googletagmanager.com/gtag/js?id={$google_analytics_id}");
            $head_node->appendChild($ga_script);

            $ga_inline_script = $dom->createElement('script');
            $ga_inline_script->textContent = "
                window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                gtag('js', new Date());
                gtag('config', '{$google_analytics_id}');
                console.log('Google Analytics ID injetado:', '{$google_analytics_id}'); // Debug log
            ";
            $head_node->appendChild($ga_inline_script);
        }

        // 4. Scripts Personalizados
        if (!empty($custom_head_scripts)) {
            // Cria um fragmento de documento para carregar os scripts personalizados
            $fragment = $dom->createDocumentFragment();
            // Para parsear HTML string e adicionar como DOM nodes:
            // Cria um DOM temporário e carrega o HTML nele.
            $temp_dom = new DOMDocument();
            libxml_use_internal_errors(true);
            // É importante usar loadHTML com um wrapper, pois fragments HTML não podem ter múltiplos nós raiz (como <script><script>)
            // sem um elemento pai. O DOMDocument sempre adicionará <html><body> se não houver.
            $temp_dom->loadHTML("<body>{$custom_head_scripts}</body>");
            libxml_clear_errors();

            $temp_body_children = $temp_dom->getElementsByTagName('body')->item(0);
            if ($temp_body_children) {
                foreach (iterator_to_array($temp_body_children->childNodes) as $node) {
                    // Importa o nó para o documento principal antes de anexar
                    $fragment->appendChild($dom->importNode($node, true));
                }
            }
            $head_node->appendChild($fragment);
            error_log("cloned_site_viewer.php: Scripts personalizados injetados para o site ID: " . $cloned_site_id); // Debug log
        }
    }

    // Output o HTML final
    echo $dom->saveHTML();

} catch (PDOException $e) {
    http_response_code(500);
    error_log("cloned_site_viewer.php: Erro de PDO ao renderizar site clonado ID {$cloned_site_id}: " . $e->getMessage());
    echo "<!DOCTYPE html><html lang='pt-BR'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'><title>Erro Interno</title><style>body{font-family:sans-serif;text-align:center;padding:50px;background-color:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}h1{color:#721c24;}p{font-size:1.2em;}</style></head><body><h1>Erro Interno do Servidor</h1><p>Ocorreu um erro no servidor ao carregar o site.</p></body></html>";
} catch (Exception $e) {
    http_response_code(500);
    error_log("cloned_site_viewer.php: Erro ao processar HTML do site clonado ID {$cloned_site_id}: " . $e->getMessage());
    echo "<!DOCTYPE html><html lang='pt-BR'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'><title>Erro Inesperado</title><style>body{font-family:sans-serif;text-align:center;padding:50px;background-color:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}h1{color:#721c24;}p{font-size:1.2em;}</style></head><body><h1>Erro Inesperado</h1><p>Ocorreu um erro inesperado ao processar o site.</p></body></html>";
}
?>