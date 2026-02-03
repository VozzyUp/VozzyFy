<?php
// Aba Rastreamento & Pixels - Configurações de tracking
?>

<div class="space-y-6">
    <div>
        <h2 class="text-xl font-semibold mb-4 text-white flex items-center gap-2">
            <i data-lucide="activity" class="w-5 h-5" style="color: var(--accent-primary);"></i>
            Rastreamento & Pixels
        </h2>
        
        <div class="space-y-6">
            <!-- Facebook Pixel -->
            <div class="bg-dark-elevated p-6 rounded-lg border border-dark-border">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
                    <i data-lucide="facebook" class="w-5 h-5 text-blue-400"></i>
                    Facebook Pixel
                </h3>
                <div class="space-y-4">
                    <div>
                        <label for="facebookPixelId" class="block text-gray-300 text-sm font-semibold mb-2">ID do Pixel do Facebook</label>
                        <input type="text" id="facebookPixelId" name="facebookPixelId" class="form-input" placeholder="Apenas os números" value="<?php echo htmlspecialchars($tracking_config['facebookPixelId'] ?? ''); ?>">
                        <p class="text-xs text-gray-400 mt-1">Encontre este ID no Gerenciador de Eventos do Facebook</p>
                    </div>
                    <div>
                        <label for="facebookApiToken" class="block text-gray-300 text-sm font-semibold mb-2">Token da API de Conversões (Facebook)</label>
                        <input type="text" id="facebookApiToken" name="facebookApiToken" class="form-input" placeholder="Cole seu token de acesso aqui" value="<?php echo htmlspecialchars($tracking_config['facebookApiToken'] ?? ''); ?>">
                        <p class="text-xs text-gray-400 mt-1">Necessário para enviar eventos de conversão via API</p>
                    </div>
                </div>
            </div>

            <!-- Google Analytics & Ads -->
            <div class="bg-dark-elevated p-6 rounded-lg border border-dark-border">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
                    <i data-lucide="bar-chart-3" class="w-5 h-5 text-orange-400"></i>
                    Google Analytics & Ads
                </h3>
                <div class="space-y-4">
                    <div>
                        <label for="googleAnalyticsId" class="block text-gray-300 text-sm font-semibold mb-2">ID do Google Analytics (GA4)</label>
                        <input type="text" id="googleAnalyticsId" name="googleAnalyticsId" class="form-input" placeholder="Ex: G-XXXXXXXXXX" value="<?php echo htmlspecialchars($tracking_config['googleAnalyticsId'] ?? ''); ?>">
                    </div>
                    <div>
                        <label for="googleAdsId" class="block text-gray-300 text-sm font-semibold mb-2">ID de Conversão do Google Ads</label>
                        <input type="text" id="googleAdsId" name="googleAdsId" class="form-input" placeholder="Ex: AW-XXXXXXXXX" value="<?php echo htmlspecialchars($tracking_config['googleAdsId'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- Script Manual -->
            <div class="bg-dark-elevated p-6 rounded-lg border border-dark-border">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center gap-2">
                    <i data-lucide="code" class="w-5 h-5 text-purple-400"></i>
                    Script Manual
                </h3>
                <div class="space-y-4">
                    <div>
                        <label for="customScript" class="block text-gray-300 text-sm font-semibold mb-2">Script de Rastreamento Personalizado</label>
                        <textarea id="customScript" name="customScript" rows="8" class="form-input font-mono text-sm" placeholder="Cole aqui o script completo de qualquer plataforma de rastreamento (TikTok Pixel, LinkedIn Insight Tag, etc.)"><?php echo htmlspecialchars($tracking_config['customScript'] ?? ''); ?></textarea>
                        <p class="text-xs text-gray-400 mt-2">
                            Este script será inserido automaticamente no <code class="text-gray-300">&lt;head&gt;</code> da página de checkout e da página de obrigado. 
                            Cole o script completo incluindo as tags <code class="text-gray-300">&lt;script&gt;</code> se necessário.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Eventos do Facebook -->
            <div class="bg-dark-elevated p-6 rounded-lg border border-dark-border">
                <h3 class="text-lg font-semibold text-white mb-4">Eventos do Facebook</h3>
                <div class="space-y-3">
                    <?php
                    function render_event_toggle($platform, $event_key, $label, $events_array) {
                        $name = htmlspecialchars($platform . '_event_' . $event_key);
                        $is_checked_by_default = in_array($event_key, ['purchase', 'initiate_checkout']);
                        $checked = isset($events_array[$event_key]) ? $events_array[$event_key] : $is_checked_by_default;
                        $checked_attr = $checked ? 'checked' : '';
                        echo "<div class='flex items-center justify-between p-3 bg-dark-card rounded-md border border-dark-border transition-colors' onmouseover=\"this.style.borderColor='var(--accent-primary)'\" onmouseout=\"this.style.borderColor=''\">
                                <label for='{$name}' class='text-sm font-medium text-gray-300 cursor-pointer flex-1'>{$label}</label>
                                <label for='{$name}' class='relative inline-flex items-center cursor-pointer'>
                                    <input type='checkbox' id='{$name}' name='{$name}' class='sr-only peer' {$checked_attr}>
                                    <div class='w-11 h-6 bg-dark-elevated rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[\"\"] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:border-dark-border after:border after:rounded-full after:h-5 after:w-5 after:transition-all' style='peer-checked:background-color: var(--accent-primary);'></div>
                                </label>
                              </div>";
                    }
                    render_event_toggle('fb', 'purchase', 'Compra Aprovada', $fb_events);
                    render_event_toggle('fb', 'initiate_checkout', 'Carrinho Abandonado (InitiateCheckout)', $fb_events);
                    render_event_toggle('fb', 'pending', 'Pagamento Pendente', $fb_events);
                    render_event_toggle('fb', 'rejected', 'Cartão Recusado', $fb_events);
                    render_event_toggle('fb', 'refund', 'Pagamento Estornado (Reembolso)', $fb_events);
                    render_event_toggle('fb', 'chargeback', 'Chargeback', $fb_events);
                    ?>
                </div>
            </div>

            <!-- Eventos do Google -->
            <div class="bg-dark-elevated p-6 rounded-lg border border-dark-border">
                <h3 class="text-lg font-semibold text-white mb-4">Eventos do Google</h3>
                <div class="space-y-3">
                    <?php 
                    render_event_toggle('gg', 'purchase', 'Compra Aprovada', $gg_events);
                    render_event_toggle('gg', 'initiate_checkout', 'Início de Checkout', $gg_events);
                    render_event_toggle('gg', 'pending', 'Pagamento Pendente', $gg_events);
                    render_event_toggle('gg', 'rejected', 'Cartão Recusado', $gg_events);
                    render_event_toggle('gg', 'refund', 'Pagamento Estornado (Reembolso)', $gg_events);
                    render_event_toggle('gg', 'chargeback', 'Chargeback', $gg_events);
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>

