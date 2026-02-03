<?php
/**
 * Helper para Geração de Template de Email Padrão
 * Gera template de email de entrega usando configurações da plataforma
 */

/**
 * Gera template padrão de email de entrega com configurações da plataforma
 * @param string $logo_checkout_url URL completa da logo do checkout
 * @param string $cor_primaria Cor primária da plataforma (hex)
 * @param string $nome_plataforma Nome da plataforma
 * @return string HTML do template
 */
function generate_default_delivery_email_template($logo_checkout_url, $cor_primaria, $nome_plataforma) {
    // Escapa a cor primária para uso seguro em CSS
    $cor_primaria_escaped = htmlspecialchars($cor_primaria);
    
    // Template HTML completo com blocos condicionais
    $template = '<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Seu Acesso - ' . htmlspecialchars($nome_plataforma) . '</title>
    <style>
        @import url(\'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap\');
        @media screen and (max-width: 600px) {
            .container { width: 100% !important; padding: 10px !important; }
            .content { padding: 25px 20px !important; }
            .header-img { width: 150px !important; }
            h1 { font-size: 24px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: \'Inter\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
    <!-- Preheader -->
    <div style="display: none; max-height: 0; overflow: hidden;">Tudo pronto! Seu acesso aos produtos já está disponível.</div>
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <table class="container" align="center" border="0" cellpadding="0" cellspacing="0" width="600" style="border-collapse: collapse; background-color: #ffffff; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); overflow: hidden; border: 1px solid #e2e8f0;">
                    <!-- Cabeçalho com Logo -->
                    <tr>
                        <td align="center" bgcolor="#1e1e2f" style="padding: 30px 20px; background-color: #1e1e2f;">
                            <div>
                                <img class="header-img" src="{LOGO_URL}" alt="Logo ' . htmlspecialchars($nome_plataforma) . '" width="200" style="display: block; border: 0; max-width: 200px; height: auto;" />
                            </div>
                        </td>
                    </tr>
                    <!-- Corpo Principal -->
                    <tr>
                        <td class="content" style="padding: 40px 35px;">
                            <h1 style="font-size: 28px; font-weight: 700; color: #0f172a; margin: 0 0 15px 0;">Parabéns, {CLIENT_NAME}!</h1>
                            <p style="margin: 0 0 25px 0; font-size: 16px; line-height: 1.6; color: #475569;">
                                Seus produtos adquiridos foram liberados com sucesso! Abaixo estão os detalhes de acesso para cada um deles:
                            </p>
                            <!-- Início do Loop de Produtos -->
                            <!-- LOOP_PRODUCTS_START -->
                            <div style="background-color: #ffffff; border: 1px solid ' . $cor_primaria_escaped . '; border-radius: 12px; padding: 25px; margin-bottom: 25px; box-shadow: 0 4px 10px rgba(0,0,0,0.03);">
                                <h2 style="font-size: 20px; font-weight: 600; color: #1e293b; margin: 0 0 15px 0;">{PRODUCT_NAME}</h2>
                                
                                <!-- Bloco para Área de Membros -->
                                <!-- IF_PRODUCT_TYPE_MEMBER_AREA -->
                                <!-- IF_NEW_USER_SETUP -->
                                <p style="margin: 0 0 15px 0; font-size: 15px; color: #475569;">Clique no botão abaixo para criar sua senha e acessar sua área de membros:</p>
                                <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin: 20px 0;">
                                    <tr>
                                        <td align="center" style="background-color: ' . $cor_primaria_escaped . '; border-radius: 8px;">
                                            <a href="{SETUP_PASSWORD_URL}" target="_blank" style="color: #ffffff; text-decoration: none; font-size: 15px; font-weight: 600; padding: 14px 28px; border: 19px solid ' . $cor_primaria_escaped . '; display: inline-block; border-radius: 8px;">Criar Senha e Acessar</a>
                                        </td>
                                    </tr>
                                </table>
                                <p style="word-break: break-all; font-size: 12px; color: #64748b; margin: 15px 0 0 0;">
                                    Se o botão não funcionar, copie e cole o link abaixo no seu navegador:<br>
                                    <a href="{SETUP_PASSWORD_URL}" style="color: ' . $cor_primaria_escaped . ';">{SETUP_PASSWORD_URL}</a>
                                </p>
                                <!-- END_IF_NEW_USER_SETUP -->
                                
                                <!-- IF_EXISTING_USER -->
                                <p style="margin: 0 0 15px 0; font-size: 15px; color: #475569;">Seu produto está disponível em sua área de membros.</p>
                                <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin: 20px 0;">
                                    <tr>
                                        <td align="center" style="background-color: ' . $cor_primaria_escaped . '; border-radius: 8px;">
                                            <a href="{MEMBER_AREA_LOGIN_URL}" target="_blank" style="color: #ffffff; text-decoration: none; font-size: 15px; font-weight: 600; padding: 14px 28px; border: 19px solid ' . $cor_primaria_escaped . '; display: inline-block; border-radius: 8px;">Acessar Área de Membros</a>
                                        </td>
                                    </tr>
                                </table>
                                <p style="word-break: break-all; font-size: 12px; color: #64748b; margin: 15px 0 0 0;">
                                    Se o botão não funcionar, copie e cole o link abaixo no seu navegador:<br>
                                    <a href="{MEMBER_AREA_LOGIN_URL}" style="color: ' . $cor_primaria_escaped . ';">{MEMBER_AREA_LOGIN_URL}</a>
                                </p>
                                <!-- END_IF_EXISTING_USER -->
                                <!-- END_IF_PRODUCT_TYPE_MEMBER_AREA -->
                                
                                <!-- Bloco para Link Externo -->
                                <!-- IF_PRODUCT_TYPE_LINK -->
                                <p style="margin: 0 0 15px 0; font-size: 15px; color: #475569;"><strong>Link de Acesso:</strong></p>
                                <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin-bottom: 10px;">
                                    <tr>
                                        <td align="center" style="background-color: ' . $cor_primaria_escaped . '; border-radius: 8px;">
                                            <a href="{PRODUCT_LINK}" target="_blank" style="color: #ffffff; text-decoration: none; font-size: 15px; font-weight: 600; padding: 14px 28px; border: 19px solid ' . $cor_primaria_escaped . '; display: inline-block; border-radius: 8px;">Acessar {PRODUCT_NAME}</a>
                                        </td>
                                    </tr>
                                </table>
                                <p style="word-break: break-all; font-size: 12px; color: #64748b;">Se o botão não funcionar, copie e cole o link: <a href="{PRODUCT_LINK}" style="color: ' . $cor_primaria_escaped . ';">{PRODUCT_LINK}</a></p>
                                <!-- END_IF_PRODUCT_TYPE_LINK -->
                                
                                <!-- Bloco para PDF -->
                                <!-- IF_PRODUCT_TYPE_PDF -->
                                <p style="margin: 0 0 10px 0; font-size: 15px; color: #475569;">Seu PDF está anexado a este e-mail. Faça o download para começar a aproveitar!</p>
                                <!-- END_IF_PRODUCT_TYPE_PDF -->
                                
                                <!-- Bloco para Produto Físico -->
                                <!-- IF_PRODUCT_TYPE_PHYSICAL_PRODUCT -->
                                <div style="background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 15px 0; border-radius: 4px;">
                                    <p style="margin: 0; font-size: 14px; color: #92400e; font-weight: 600;">📦 Produto Físico</p>
                                    <p style="margin: 10px 0 0 0; font-size: 13px; color: #92400e;">Seu produto será enviado para o endereço informado no checkout. Você receberá atualizações sobre o envio por e-mail.</p>
                                </div>
                                <!-- END_IF_PRODUCT_TYPE_PHYSICAL_PRODUCT -->
                            </div>
                            <!-- Fim do Loop de Produtos -->
                            <!-- LOOP_PRODUCTS_END -->
                            
                            <!-- Endereço de Entrega (se houver produto físico) -->
                            {DELIVERY_ADDRESS}
                            
                            <p style="margin: 30px 0 0 0; font-size: 16px; line-height: 1.6; color: #475569;">
                                Caso tenha alguma dúvida ou precise de suporte, entre em contato conosco.
                            </p>
                            <p style="margin: 15px 0 0 0; font-size: 16px; line-height: 1.6; color: #475569;">
                                Obrigado e aproveite seus novos produtos!
                            </p>
                        </td>
                    </tr>
                    <!-- Rodapé -->
                    <tr>
                        <td align="center" style="padding: 25px 30px; background-color: #f8fafc; border-top: 1px solid #e2e8f0;">
                            <p style="margin: 0; font-size: 13px; color: #64748b;">
                                Este é um e-mail automático, por favor, não responda.
                            </p>
                            <p style="margin: 10px 0 0 0; font-size: 13px; color: #94a3b8;">
                                ' . htmlspecialchars($nome_plataforma) . ' &copy; ' . date('Y') . '. Todos os direitos reservados.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    
    return $template;
}

