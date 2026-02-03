<?php
require __DIR__ . '/../config/config.php';

// Protege a página, apenas usuários logados (admin) podem ver o preview
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: /login");
    exit;
}

$mensagem_erro = '';
$curso = null;
$modulos_com_aulas = [];
$total_aulas = 0;
$aulas_concluidas = 0; // Para a preview, o progresso é 0
$progresso_percentual = 0;
$upload_dir = 'uploads/'; // Pasta onde as imagens estão salvas
$aula_files_dir_public = 'uploads/aula_files/'; // Caminho público para arquivos de aula

// Valida o ID do produto
if (!isset($_GET['produto_id']) || !is_numeric($_GET['produto_id'])) {
    $mensagem_erro = "ID do curso inválido.";
} else {
    $produto_id = (int)$_GET['produto_id'];

    try {
        // Busca o curso correspondente ao produto
        $stmt_curso = $pdo->prepare("
            SELECT c.* FROM cursos c
            JOIN produtos p ON c.produto_id = p.id
            WHERE p.id = ? AND p.tipo_entrega = 'area_membros'
        ");
        $stmt_curso->execute([$produto_id]);
        $curso = $stmt_curso->fetch(PDO::FETCH_ASSOC);

        if (!$curso) {
            $mensagem_erro = "Curso não encontrado ou não está configurado como 'Área de Membros'.";
        } else {
            // SIMULAÇÃO: Para a preview, consideramos a "data de compra" como AGORA.
            // Isso permite que módulos/aulas com release_days = 0 apareçam liberados,
            // e os com release_days > 0 apareçam bloqueados com uma data futura.
            $simulated_purchase_date = new DateTime(); // Use new DateTime() for simulation
            $current_date = new DateTime(); // Current date for comparison

            // Busca os módulos do curso (inclui release_days)
            $stmt_modulos = $pdo->prepare("SELECT id, curso_id, titulo, imagem_capa_url, ordem, release_days FROM modulos WHERE curso_id = ? ORDER BY ordem ASC, id ASC");
            $stmt_modulos->execute([$curso['id']]);
            $modulos = $stmt_modulos->fetchAll(PDO::FETCH_ASSOC);

            // Para cada módulo, busca as aulas (inclui release_days, tipo_conteudo) e seus arquivos
            foreach ($modulos as $modulo) {
                // Calcula a data de liberação do módulo para a pré-visualização
                $module_release_date = (clone $simulated_purchase_date);
                $module_release_date->modify("+{$modulo['release_days']} days");
                $modulo['is_locked'] = ($current_date < $module_release_date);
                $modulo['available_at'] = $module_release_date->format('d/m/Y H:i');

                // MODIFICADO: Incluir 'tipo_conteudo' na consulta das aulas
                $stmt_aulas = $pdo->prepare("SELECT id, modulo_id, titulo, url_video, descricao, ordem, release_days, tipo_conteudo FROM aulas WHERE modulo_id = ? ORDER BY ordem ASC, id ASC");
                $stmt_aulas->execute([$modulo['id']]);
                $aulas = $stmt_aulas->fetchAll(PDO::FETCH_ASSOC);
                
                $total_aulas += count($aulas);
                
                $aulas_com_status = [];
                foreach ($aulas as $aula) {
                    // Calcula a data de liberação da aula para a pré-visualização
                    $lesson_release_date = (clone $simulated_purchase_date);
                    $lesson_release_date->modify("+{$aula['release_days']} days");
                    $aula['is_locked'] = ($current_date < $lesson_release_date);
                    $aula['available_at'] = $lesson_release_date->format('d/m/Y H:i');

                    // NOVO: Busca arquivos da aula
                    $stmt_files = $pdo->prepare("SELECT id, nome_original, nome_salvo FROM aula_arquivos WHERE aula_id = ? ORDER BY ordem ASC, id ASC");
                    $stmt_files->execute([$aula['id']]);
                    $aula['files'] = $stmt_files->fetchAll(PDO::FETCH_ASSOC);

                    $aulas_com_status[] = $aula;
                }

                $modulos_com_aulas[] = [
                    'modulo' => $modulo,
                    'aulas' => $aulas_com_status
                ];
            }
            if ($total_aulas > 0) {
                // Para o preview, o progresso é sempre 0
                $progresso_percentual = 0;
            }
        }
    } catch (PDOException $e) {
        $mensagem_erro = "Erro de banco de dados: " . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview: <?php echo htmlspecialchars($curso['titulo'] ?? 'Curso'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .prose { --tw-prose-body: #d1d5db; --tw-prose-headings: #f9fafb; --tw-prose-links: #fb923c; } /* Estilos para o texto da descrição */
        .module-card.active { border-color: #f97316; box-shadow: 0 0 15px rgba(249, 115, 22, 0.5); transform: scale(1.05); }
        .lesson-item.active { background-color: #7c2d12; color: #ffedd5; font-weight: 600; }
        .lesson-item.active .lucide-play-circle { color: #fdba74; }
        .aspect-video { aspect-ratio: 16 / 9; }
        .module-card.disabled, .lesson-item.locked { 
            cursor: not-allowed; 
            opacity: 0.6; 
        }
        .module-card.disabled:hover, .lesson-item.locked:hover {
            border-color: #2d3748; /* Mantém a cor da borda padrão ou similar ao bloqueado */
            box-shadow: none;
            transform: none;
            background-color: #2d3748;
        }
        .lesson-item.locked { 
            background-color: #2d3748; /* Mais escuro para indicar bloqueio */
        }
        .lesson-item.locked .lucide-play-circle, .lesson-item.locked .lucide-lock, .lesson-item.locked .lucide-file-text {
            color: #718096; /* Cinza para ícones bloqueados */
        }

        /* ===== INÍCIO: PLAYER YOUTUBE CUSTOMIZADO (CSS DO YMin) ===== */
        .ymin{
         --aspect:16/9; --crop:2000px; --accent:#f97316; --bar-color:var(--accent); --track-color:#202532; /* <-- COR LARANJA PRINCIPAL AJUSTADA AQUI */
         position:relative; width:100%; aspect-ratio:var(--aspect); background:#000; overflow:hidden;
         /* Adicionado para se encaixar no layout */
         border-radius: 0.75rem; /* 12px */
         box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }
        .ymin .frame{position:relative;width:100%;height:100%;background:#000;overflow:hidden}
        .ymin iframe{position:absolute;inset:0;width:100%;height:calc(100% + var(--crop));top:calc(var(--crop)*-0.5);border:0;display:block;opacity:0;transition:opacity .18s ease}
        .ymin.ready iframe{opacity:1}
        .ymin .veil{position:absolute;inset:0;background:#000;z-index:8;opacity:1;transition:opacity .18s ease}
        .ymin.ready .veil{opacity:0;pointer-events:none}
        .ymin .clickzone{position:absolute;inset:0;z-index:9}

        /* Capas (com ícone) */
        .ymin .overlay{position:absolute;inset:0;z-index:10;display:grid;place-items:center;background:rgba(0,0,0,.5);pointer-events:none}
        .ymin .overlay[hidden]{display:none}
.ymin .cover{display:grid;place-items:center;text-align:center}
.ymin .icon{width:110px;max-width:26vw;height:auto;filter:drop-shadow(0 10px 28px rgba(0,0,0,.6));animation:pulse 1.6s ease-in-out infinite;
         filter: brightness(0) invert(1); /* <-- FORÇA O ÍCONE GRANDE DE PLAY A SER BRANCO */
        }
@keyframes pulse{0%{transform:scale(1)}50%{transform:scale(1.06)}100%{transform:scale(1)}}

        /* HUD + barra (interativa) */
        .ymin .hud.ui{position:absolute;left:0;right:0;bottom:0;z-index:12;height:10px;pointer-events:auto}
        .ymin .progress{position:absolute;left:0;right:0;bottom:0;height:10px;background:var(--track-color);border:0;overflow:hidden;cursor:pointer}
        .ymin .progress .bar{position:absolute;left:0;top:0;bottom:0;width:0;background:var(--bar-color);transition:width .08s linear}

        .ymin .timecode.ui{
         position:absolute; left:12px; bottom:14px; z-index:13;
         padding:4px 8px; border-radius:8px; background:rgba(0,0,0,.55);
         color:#fff; font:600 12px/1.2 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial,"Noto Sans","Apple Color Emoji","Segoe UI Emoji"; /* <-- COR DO TEXTO (BRANCO) */
        }

        .ymin .ctrls-right.ui{
         position:absolute; right:10px; bottom:12px; z-index:13; display:flex; gap:8px;
        }
        .ymin .btn{
         width:40px; height:40px; border:0; border-radius:10px; background:var(--accent); color:#fff; /* <-- COR DO BOTÃO (LARANJA) E ÍCONE (BRANCO) */
         display:grid; place-items:center; cursor:pointer; box-shadow:0 6px 18px rgba(0,0,0,.35);
         transition:transform .12s ease, filter .12s ease;
        }
        .ymin .btn:hover{transform:translateY(-1px);filter:brightness(.9)}
.ymin .btn img{width:22px;height:22px;display:block;pointer-events:none;
         filter: brightness(0) invert(1); /* <-- FORÇA OS ÍCONES DOS BOTÕES A SEREM BRANCOS */
        }

:fullscreen .ymin .frame{aspect-ratio:auto;height:100vh}
        :-webkit-full-screen .ymin .frame{aspect-ratio:auto;height:100vh}

        .ymin .ui{opacity:1;transition:opacity .18s ease, transform .18s ease}
        .ymin.controls-hidden .ui{opacity:0; transform:translateY(12px); pointer-events:none}

        /* ===== Vertical (Shorts) ===== */
        .ymin.vertical{
         --aspect:9/16;
         width:min(520px, 100%);
         max-height:84vh;
         margin:0 auto;
         border-radius:14px;
        }
        .ymin.vertical iframe{
         width:calc(100% + var(--crop));
         height:100%;
         left:calc(var(--crop)*-0.5);
         top:0;
        }
        /* ===== FIM: PLAYER YOUTUBE CUSTOMIZADO (CSS DO YMin) ===== */
    </style>
</head>
<body class="bg-gray-900 text-gray-200 antialiased">
    
    <?php if ($mensagem_erro): ?>
        <div class="flex h-screen items-center justify-center p-8">
            <div class="bg-red-900 border border-red-700 text-red-200 px-6 py-4 rounded-lg text-center max-w-lg">
                <p class="font-bold text-lg">Ocorreu um Erro</p>
                <p><?php echo $mensagem_erro; ?></p>
                 <a href="/index?pagina=area_membros" class="mt-4 inline-block bg-red-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-red-700 transition">Voltar</a>
            </div>
        </div>
    <?php elseif (!$curso): ?>
        <div class="flex h-screen items-center justify-center p-8">
             <div class="bg-gray-800 border border-gray-700 text-gray-300 px-6 py-4 rounded-lg text-center">
                <p>Carregando...</p>
            </div>
        </div>
    <?php else: ?>
    <div id="course-container" class="min-h-screen">
        <!-- Banner do Topo -->
        <header class="relative h-64 md:h-80 bg-gray-800 bg-cover bg-center" style="background-image: url('<?php echo htmlspecialchars($curso['banner_url'] ?? ''); ?>')">
            <div class="absolute inset-0 bg-gradient-to-t from-gray-900 via-gray-900/70 to-transparent"></div>
            <div class="relative h-full flex flex-col justify-end p-6 md:p-10 max-w-7xl mx-auto">
                 <a href="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" class="absolute top-4 right-4 bg-black/50 text-white font-semibold py-2 px-4 rounded-lg hover:bg-black/80 transition duration-300 flex items-center space-x-2 text-sm z-10">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>
                    <span>Voltar ao Gerenciador</span>
                </a>
                <h1 class="text-3xl md:text-5xl font-extrabold text-white drop-shadow-lg"><?php echo htmlspecialchars($curso['titulo']); ?></h1>
                <p class="mt-2 text-lg text-gray-300 max-w-2xl drop-shadow-md"><?php echo htmlspecialchars($curso['descricao']); ?></p>
            </div>
        </header>

        <main class="max-w-7xl mx-auto p-4 md:p-8 w-full">
            <?php if (empty($modulos_com_aulas) || $total_aulas === 0): ?>
                <div class="bg-gray-800 border border-gray-700 p-8 rounded-lg text-center text-gray-400">
                    <i data-lucide="video-off" class="mx-auto w-16 h-16 text-gray-600"></i>
                    <p class="mt-4 font-semibold text-lg text-gray-200">Este curso ainda não tem conteúdo.</p>
                    <p>Adicione módulos e aulas no gerenciador para visualizar a área de membros.</p>
                </div>
            <?php else: ?>

                <!-- Player e Aulas (Oculto por padrão) -->
                <div id="player-wrapper" class="hidden">
                    <!-- Barra de Progresso -->
                    <div class="mb-8">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm font-semibold text-orange-400">SEU PROGRESSO</span>
                            <span class="text-sm font-bold text-white"><?php echo $progresso_percentual; ?>% Completo</span>
                        </div>
                        <div class="w-full bg-gray-700 rounded-full h-2.5">
                            <div class="bg-orange-500 h-2.5 rounded-full" style="width: <?php echo $progresso_percentual; ?>%"></div>
                        </div>
                    </div>

                    <!-- Player e Lista de Aulas -->
                    <div id="player-section" class="flex flex-col lg:flex-row gap-8 mb-12">
                        <!-- Coluna Esquerda: Player e Detalhes -->
                        <div class="lg:w-2/3 w-full">

                            <!-- [INÍCIO DA MUDANÇA] Container do Player YMin -->
                            <div id="player-host" class="bg-black rounded-xl shadow-2xl mb-6">
                                <!-- Placeholder inicial que será substituído -->
                                <div class="w-full aspect-video bg-black flex flex-col items-center justify-center text-gray-500 rounded-xl">
                                    <i data-lucide="play-circle" class="w-16 h-16 text-gray-600 mb-4"></i>
                                    <p class="text-lg font-semibold">Selecione um módulo e uma aula para começar.</p>
                                </div>
                            </div>
                            <!-- [FIM DA MUDANÇA] Container do Player YMin -->

                            <div class="bg-gray-800 p-6 rounded-xl shadow-lg">
                                <h2 id="lesson-title" class="text-2xl font-bold text-white mb-4">Selecione um módulo para começar</h2>
                                <div id="lesson-description" class="prose max-w-none">
                                    <p>A descrição e materiais da aula aparecerão aqui.</p>
                                </div>
                            </div>
                        </div>
                        <!-- Coluna Direita: Lista de Aulas do Módulo Ativo -->
                        <aside class="lg:w-1/3 w-full bg-gray-800 rounded-xl shadow-lg p-4 flex-shrink-0 h-fit lg:sticky top-8">
                            <h3 id="module-title-aside" class="font-bold text-xl text-white mb-4 px-2">Aulas do Módulo</h3>
                            <div id="lesson-list-container" class="space-y-2 max-h-[70vh] overflow-y-auto pr-2">
                               <p class="text-gray-400 px-2">Selecione um módulo abaixo para ver as aulas.</p>
                            </div>
                        </aside>
                    </div>
                </div>

                <!-- Seção de Módulos (Sempre visível) -->
                <div>
                    <h2 class="text-3xl font-bold text-white mb-6">Módulos do Curso</h2>
                    <div id="modules-grid" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
                        <?php foreach ($modulos_com_aulas as $index => $item): ?>
                            <?php
                            $module = $item['modulo'];
                            $is_module_locked = $module['is_locked'];
                            $module_button_classes = "module-card group relative rounded-lg overflow-hidden border-2 border-gray-700 hover:border-orange-500 focus:outline-none focus:ring-4 focus:ring-orange-500/50 transition-all duration-300 text-left";
                            $module_button_classes .= $is_module_locked ? ' disabled opacity-50 cursor-not-allowed' : '';
                            ?>
                            <button class="<?php echo $module_button_classes; ?>" 
                                    data-module-id="<?php echo $module['id']; ?>" 
                                    data-module-index="<?php echo $index; ?>"
                                    <?php echo $is_module_locked ? 'disabled' : ''; ?>
                                    >
                                <div class="aspect-[3/4]">
                                    <?php 
                                    $imagem_capa = '';
                                    if (!empty($module['imagem_capa_url'])) {
                                        // O caminho no banco está como 'uploads/imagem_capa_modulo_xxx.png' (sem barra inicial)
                                        $caminho_banco = $module['imagem_capa_url'];
                                        
                                        // Verifica se o arquivo existe usando caminho absoluto
                                        // __DIR__ está em views/, então sobe 1 nível para chegar à raiz
                                        $file_path_absoluto = __DIR__ . '/../' . $caminho_banco;
                                        
                                        if (file_exists($file_path_absoluto)) {
                                            // Se existe, constrói a URL com / inicial
                                            $imagem_capa = '/' . $caminho_banco;
                                        }
                                    }
                                    ?>
                                    <?php if (!empty($imagem_capa)): ?>
                                        <img src="<?php echo htmlspecialchars($imagem_capa); ?>" alt="Capa do <?php echo htmlspecialchars($module['titulo']); ?>" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
                                    <?php else: ?>
                                        <div class="w-full h-full bg-gray-700 flex items-center justify-center">
                                            <i data-lucide="image" class="w-12 h-12 text-gray-500"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/50 to-transparent"></div>
                                <div class="absolute bottom-0 left-0 p-4">
                                    <h4 class="font-bold text-lg text-white"><?php echo htmlspecialchars($module['titulo']); ?></h4>
                                    <?php if ($is_module_locked): ?>
                                        <span class="text-xs text-red-400 flex items-center mt-1"><i data-lucide="lock" class="w-4 h-4 mr-1"></i> Disponível em: <?php echo $module['available_at']; ?></span>
                                    <?php else: ?>
                                        <span class="text-xs text-orange-300"><?php echo count($item['aulas']); ?> aulas</span>
                                    <?php endif; ?>
                                </div>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
    <?php endif; ?>

    <script>
        /* =================================================================== */
        /* ====== INÍCIO: TECNOLOGIA DO PLAYER (COPIADO DO YMin) ====== */
        /* =================================================================== */
        const ICONS = { back5: "https://iili.io/KCUAyMJ.png", fwd5: "https://iili.io/KCU5QhF.png", play: "https://iili.io/KCUYGS4.png", fs: "https://iili.io/KCUaDBe.png" };
        const HIDE_DELAY_MS = 2200;
        (function(){
         if (!window._ytApi) {
         window._ytApi = {};
         window._ytApi.promise = new Promise((resolve) => {
           window._ytApi._resolve = resolve;
           const s = document.createElement('script');
           s.src = 'https://www.youtube.com/iframe_api';
           document.head.appendChild(s);
           const prev = window.onYouTubeIframeAPIReady;
           window.onYouTubeIframeAPIReady = function(){
           if (typeof prev === 'function') try { prev(); } catch {}
           window._ytApi._resolve();
           };
         });
         }})();
        const ytApiReady = window._ytApi.promise;
        let yminPlayer=null, yminRaf=0, yminRoot=null, yminPlaying=false, yminFirst=false, idleTimer=0, scrubbing=false;
        const REACH_AT = 0.90, PEAK_AT = 0.70, ACCEL_SHAPE = 0.6;
        function fakeFromReal(p){
         p=Math.max(0,Math.min(1,p));
         if(p<=REACH_AT){ const t=p/REACH_AT; return PEAK_AT*Math.pow(t,ACCEL_SHAPE); }
         const t=(p-REACH_AT)/(1-REACH_AT); return PEAK_AT+(1-PEAK_AT)*(1-Math.pow(1-t,3));
        }
        function formatTime(s){
         s = Math.max(0, Math.floor(s||0));
         const h = Math.floor(s/3600), m = Math.floor((s%3600)/60), sec = s%60;
         if (h>0) return `${h}:${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}`;
         return `${m}:${String(sec).padStart(2,'0')}`;}
        function mountYMinHTML(root){
         const mountId='yt-mount-'+Math.random().toString(36).slice(2,8);
         root.innerHTML=`
         <div class="frame">
           <div class="clickzone" aria-hidden="true"></div>
           <div id="${mountId}"></div>
           <div class="veil" aria-hidden="true"></div>
           <div class="overlay start"><div class="cover"><img class="icon" src="${ICONS.play}" alt="Play"></div></div>
           <div class="overlay paused" hidden><div class="cover"><img class="icon" src="${ICONS.play}" alt="Play"></div></div>
           <div class="hud ui"><div class="progress"><div class="bar"></div></div></div>
           <div class="timecode ui"><span class="cur">0:00</span> / <span class="dur">0:00</span></div>
           <div class="ctrls-right ui">
           <button class="btn back5" type="button" aria-label="Voltar 5 segundos" title="Voltar 5s"><img src="${ICONS.back5}" alt="Voltar 5s"></button>
           <button class="btn fwd5" type="button" aria-label="Avançar 5 segundos" title="Avançar 5s"><img src="${ICONS.fwd5}" alt="Avançar 5s"></button>
           <button class="btn fsbtn" type="button" aria-label="Tela cheia" title="Tela cheia"><img src="${ICONS.fs}" alt="Tela cheia"></button>
           </div>
         </div>
         `;
         return mountId;
        }
        function destroyYMin(){
         cancelAnimationFrame(yminRaf); yminRaf=0;
         try{ yminPlayer && yminPlayer.destroy && yminPlayer.destroy(); }catch{}
         yminPlayer=null; yminRoot=null; yminPlaying=false; yminFirst=false; scrubbing=false;
         clearTimeout(idleTimer);
        }
        function showControls(root){
         root.classList.remove('controls-hidden');
         clearTimeout(idleTimer);
         idleTimer = setTimeout(()=>{ if (!scrubbing) root.classList.add('controls-hidden'); }, HIDE_DELAY_MS);
        }
        function clamp01(x){ return Math.max(0, Math.min(1, x)); }
        async function createYMin(root, videoId){
         destroyYMin(); yminRoot=root;
         const mountId = mountYMinHTML(root);
         const isVertical = root.classList.contains('vertical') || root.dataset.vertical === '1';
         if (isVertical) { root.style.setProperty('--aspect','9/16'); }
         const frame   = root.querySelector('.frame');
         const clickzone = root.querySelector('.clickzone');
         const startOv  = root.querySelector('.overlay.start');
         const pausedOv = root.querySelector('.overlay.paused');
         const barEl   = root.querySelector('.progress .bar');
         const progress = root.querySelector('.progress');
         const curEl   = root.querySelector('.timecode .cur');
         const durEl   = root.querySelector('.timecode .dur');
         const fsBtn   = root.querySelector('.fsbtn');
         const back5Btn = root.querySelector('.back5');
         const fwd5Btn  = root.querySelector('.fwd5');
         setTimeout(() => { try { root.classList.add('ready'); } catch {} }, 1500);
         showControls(root);
         await ytApiReady;
         yminPlayer = new YT.Player(mountId,{
         videoId, host:'https://www.youtube-nocookie.com',
         playerVars:{autoplay:1,mute:1,controls:0,disablekb:1,fs:0,modestbranding:1,rel:0,iv_load_policy:3,playsinline:1},
         events:{
           onReady(){
           try{yminPlayer.mute();yminPlayer.playVideo();}catch{}
           requestAnimationFrame(()=>root.classList.add('ready'));
           setTimeout(()=>{ try { root.classList.add('ready'); } catch {} }, 400);
           loop();
           },
           onStateChange(e){
           if(e.data===YT.PlayerState.PLAYING){
             yminPlaying=true; if(yminFirst){ startOv.hidden=true; pausedOv.hidden=true; }
           }else if(e.data===YT.PlayerState.PAUSED){
             yminPlaying=false; if(yminFirst){ pausedOv.hidden=false; }
           }else if(e.data===YT.PlayerState.ENDED){
             yminPlaying=false; try{yminPlayer.seekTo(0,true);yminPlayer.pauseVideo();}catch{} pausedOv.hidden=false;
           }
           }
         }
         });
         function firstPlay(){ yminFirst=true; startOv.hidden=true; try{yminPlayer.seekTo(0,true);yminPlayer.unMute();}catch{} play(); }
         function play(){ try{yminPlayer.playVideo();}catch{} }
         function pause(){ try{yminPlayer.pauseVideo();}catch{} }
         function toggle(){ showControls(root); yminPlaying ? pause() : (yminFirst ? play() : firstPlay()); }
         clickzone.addEventListener('click', toggle);
         root.addEventListener('mousemove', ()=>showControls(root), {passive:true});
         root.addEventListener('touchstart', ()=>showControls(root), {passive:true});
         root.addEventListener('touchmove', ()=>showControls(root), {passive:true});
         function enterFs(el){ (el.requestFullscreen||el.webkitRequestFullscreen||el.msRequestFullscreen||el.mozRequestFullScreen)?.call(el); }
         function exitFs(){ (document.exitFullscreen||document.webkitExitFullscreen||document.msExitFullscreen||document.mozCancelFullScreen)?.call(document); }
         function isFs(){ return document.fullscreenElement||document.webkitFullscreenElement||document.msFullscreenElement||document.mozFullScreenElement; }
         fsBtn.addEventListener('click', e=>{ e.stopPropagation(); showControls(root); isFs()?exitFs():enterFs(frame); });
         function seekBy(delta){
         try{
           const cur = yminPlayer?.getCurrentTime?.()||0;
           const dur = yminPlayer?.getDuration?.()||0;
           if (dur>0){
           let t = Math.max(0, Math.min(dur-0.1, cur + delta));
           yminPlayer.seekTo(t, true);
           }
         }catch{}
         }
         back5Btn.addEventListener('click', (e)=>{ e.stopPropagation(); showControls(root); seekBy(-5); });
         fwd5Btn .addEventListener('click', (e)=>{ e.stopPropagation(); showControls(root); seekBy(+5); });
         function pctFromEvent(ev){
         const r = progress.getBoundingClientRect();
         const x = (ev.touches ? ev.touches[0].clientX : ev.clientX) - r.left;
         return clamp01(x / r.width);
         }
         function preview(p){ barEl.style.width = (fakeFromReal(p)*100).toFixed(2)+'%'; }
         function seekToPct(p){
         const dur = yminPlayer?.getDuration?.() || 0;
         if (dur>0) yminPlayer.seekTo(dur * clamp01(p), true);
         }
         function startScrub(ev){
         ev.preventDefault(); scrubbing = true; showControls(root);
         const p = pctFromEvent(ev); preview(p); seekToPct(p);
         window.addEventListener('mousemove', moveScrub);
         window.addEventListener('touchmove', moveScrub, {passive:false});
         window.addEventListener('mouseup', endScrub);
         window.addEventListener('touchend', endScrub);
         }
         function moveScrub(ev){
         ev.preventDefault();
         if(!scrubbing) return;
         const p = pctFromEvent(ev); preview(p); seekToPct(p);
         }
         function endScrub(ev){
         if(!scrubbing) return;
         scrubbing=false;
         const p = pctFromEvent(ev); preview(p); seekToPct(p);
         window.removeEventListener('mousemove', moveScrub);
         window.removeEventListener('touchmove', moveScrub);
         window.removeEventListener('mouseup', endScrub);
         window.removeEventListener('touchend', endScrub);
         showControls(root);
         }
         progress.addEventListener('mousedown', startScrub);
         progress.addEventListener('touchstart', startScrub, {passive:true});
         function loop(){
         cancelAnimationFrame(yminRaf);
         const tick=()=>{
           try{
           const cur=yminPlayer?.getCurrentTime?.()||0;
           const dur=yminPlayer?.getDuration?.()||0;
           if(dur>0){
             curEl.textContent = formatTime(cur);
             durEl.textContent = formatTime(dur);
             if(!scrubbing){
             const pReal = cur/dur;
             barEl.style.width = (fakeFromReal(pReal)*100).toFixed(2)+'%';
             }
           }
           }catch{}
           yminRaf=requestAnimationFrame(tick);
         };
         yminRaf=requestAnimationFrame(tick);
         }
        }
        /* =================================================================== */
        /* ====== FIM: TECNOLOGIA DO PLAYER (COPIADO DO YMin) ======== */
        /* =================================================================== */


        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
            
            const allModulesData = <?php echo json_encode($modulos_com_aulas); ?>;
            const aulaFilesDirPublic = "<?php echo htmlspecialchars($aula_files_dir_public); ?>";
            if (!allModulesData || allModulesData.length === 0) return;

            const playerWrapper = document.getElementById('player-wrapper');
            // [INÍCIO DA MUDANÇA] Referências do Player
            const playerHost = document.getElementById('player-host'); // Novo container do player
            const initialPlaceholderHTML = playerHost.innerHTML; // Salva o placeholder inicial
            // [FIM DA MUDANÇA]
            
            const lessonTitle = document.getElementById('lesson-title');
            const lessonDescription = document.getElementById('lesson-description');
            const lessonListContainer = document.getElementById('lesson-list-container');
            const moduleCards = document.querySelectorAll('.module-card');
            const moduleTitleAside = document.getElementById('module-title-aside');
            
            let currentModuleId = null;

            // REMOVIDO: function getYoutubeEmbedUrl(url)
            
            // [INÍCIO DA MUDANÇA] Função loadLesson atualizada para usar YMin
            function loadLesson(lessonData) {
                 // 1. Destrói qualquer player YMin anterior
                destroyYMin();

                if (!lessonData) { // Reset player if no lesson
                    playerHost.innerHTML = initialPlaceholderHTML; // Restaura placeholder inicial
                    lucide.createIcons();
                    lessonTitle.textContent = 'Nenhuma aula selecionada';
                    lessonDescription.innerHTML = '<p>Selecione uma aula na lista ao lado.</p>';
                    return;
                }

                // 2. Lida com aula bloqueada (simulação)
                if (lessonData.is_locked) {
                    playerHost.innerHTML = `<div class="w-full aspect-video bg-black flex flex-col items-center justify-center text-gray-500 rounded-xl">
                                                <i data-lucide="lock" class="w-16 h-16 text-gray-600 mb-4"></i>
                                                <p class="text-lg font-semibold">Aula Bloqueada (Preview)</p>
                                                <p class="text-sm">Disponível em: ${lessonData.available_at}</p>
                                            </div>`;
                    lucide.createIcons();
                    lessonTitle.textContent = 'Aula Bloqueada';
                    lessonDescription.innerHTML = `<p class="text-red-400 flex items-center"><i data-lucide="lock" class="w-5 h-5 mr-2"></i> Esta aula estará disponível em: ${lessonData.available_at}.</p>`;
                    lucide.createIcons(); // Render the lock icon in the description
                    return;
                }

                // 3. Lógica de exibição: Tenta encontrar um ID de vídeo do YouTube
                let videoId = null;
                let isShort = false;
                if ((lessonData.tipo_conteudo === 'video' || lessonData.tipo_conteudo === 'mixed') && lessonData.url_video) {
                    // Regex do player YMin para extrair o ID
                    const match = lessonData.url_video.match(/(?:youtube\.com\/(?:watch\?v=|shorts\/|embed\/|v\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/i);
                    if (match && match[1]) {
                        videoId = match[1];
                        isShort = /youtube\.com\/shorts\//i.test(lessonData.url_video);
                    }
                }

                // 4. Carrega o Player YMin ou o Placeholder de "Sem Vídeo"
                if (videoId) {
                    // Encontrou um vídeo do YouTube -> Carrega o YMin
                    playerHost.innerHTML = ''; // Limpa o placeholder
                    const playerDiv = document.createElement('div');
                    playerDiv.className = `ymin controls-hidden ${isShort ? 'vertical' : ''}`;
                    playerHost.appendChild(playerDiv);
                    
                    // Chama a função principal do YMin
                    createYMin(playerDiv, videoId);
                } else {
                    // Não é um vídeo do YouTube (pode ser 'files' ou URL inválida) -> Mostra placeholder
                    playerHost.innerHTML = `<div class="w-full aspect-video bg-black flex flex-col items-center justify-center text-gray-500 rounded-xl">
                                                <i data-lucide="video-off" class="w-16 h-16 text-gray-600 mb-4"></i>
                                                <p class="text-lg font-semibold">Esta aula não contém vídeo.</p>
                                                <p class="text-sm">Verifique os materiais de apoio abaixo.</p>
                                            </div>`;
                    lucide.createIcons();
                }

                // 5. Carrega Título, Descrição e Arquivos (lógica original mantida)
                lessonTitle.textContent = lessonData.titulo;

                let descriptionHtml = (lessonData.descricao || 'Esta aula não possui descrição.')
                    .replace(/</g, "&lt;").replace(/>/g, "&gt;") // Basic HTML escaping
                    .replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank" class="text-orange-500 hover:underline">$1</a>') // Link detection
                    .replace(/\n/g, '<br>');
                
                // NOVO: Adicionar arquivos de apoio como botões CTA
                if ((lessonData.tipo_conteudo === 'files' || lessonData.tipo_conteudo === 'mixed') && lessonData.files && lessonData.files.length > 0) {
                    descriptionHtml += '<h4 class="text-lg font-bold text-white mt-6 mb-3">Materiais de Apoio</h4>';
                    descriptionHtml += '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">'; // Responsive grid container
                    lessonData.files.forEach(file => {
                        const filePath = `${aulaFilesDirPublic}${file.nome_salvo}`;
                        descriptionHtml += `
                            <a href="${filePath}" target="_blank" class="bg-orange-600 text-white font-bold py-3 px-6 rounded-lg hover:bg-orange-700 transition duration-300 text-base flex items-center justify-center space-x-2">
                                <i data-lucide="download" class="w-5 h-5 flex-shrink-0"></i>
                                <span>${file.nome_original}</span>
                            </a>
                        `;
                    });
                    descriptionHtml += '</div>'; // Close the grid div
                } else if ((lessonData.tipo_conteudo === 'files' || lessonData.tipo_conteudo === 'mixed') && (!lessonData.files || lessonData.files.length === 0)) {
                    descriptionHtml += '<p class="text-gray-500 mt-4">Nenhum material de apoio disponível para esta aula.</p>';
                }


                lessonDescription.innerHTML = descriptionHtml;
                lucide.createIcons(); // Re-render icons if new ones were added in descriptionHtml

                // 6. Highlight na aula ativa
                document.querySelectorAll('.lesson-item').forEach(item => {
                    item.classList.toggle('active', item.dataset.lessonId == lessonData.id);
                });
            }
            // [FIM DA MUDANÇA] Função loadLesson

            function displayLessonsForModule(moduleIndex) {
                const moduleData = allModulesData[moduleIndex];
                if (!moduleData) return;

                currentModuleId = moduleData.modulo.id;

                // Highlight active module card
                moduleCards.forEach(card => {
                    card.classList.toggle('active', card.dataset.moduleId == currentModuleId);
                });
                
                moduleTitleAside.textContent = moduleData.modulo.titulo;
                lessonListContainer.innerHTML = ''; // Clear previous lessons

                if (moduleData.aulas.length === 0) {
                    lessonListContainer.innerHTML = '<p class="text-gray-400 px-2">Este módulo não possui aulas.</p>';
                    loadLesson(null); // Clear the player
                    return;
                }
                
                let firstAvailableLesson = null; // Track the first unlocked lesson

                moduleData.aulas.forEach(aula => {
                    const lessonButton = document.createElement('button');
                    let iconHtml = '';
                    let textClass = 'text-gray-300';

                    if (aula.is_locked) {
                        lessonButton.className = 'lesson-item w-full text-left flex items-center space-x-3 p-3 rounded-lg locked';
                        iconHtml = `<i data-lucide="lock" class="w-5 h-5 flex-shrink-0 text-gray-500"></i>`;
                        textClass = 'text-gray-500'; // Make text dimmer for locked lessons
                    } else {
                        lessonButton.className = 'lesson-item w-full text-left flex items-center space-x-3 p-3 rounded-lg text-gray-300 hover:bg-gray-700 transition';
                        
                        // NEW: Determine icons based on content type
                        let videoIcon = '';
                        let fileIcon = '';

                        if (aula.tipo_conteudo === 'video' || aula.tipo_conteudo === 'mixed') {
                            videoIcon = `<i data-lucide="play-circle" class="w-5 h-5 text-gray-500 flex-shrink-0"></i>`;
                        }
                        if (aula.tipo_conteudo === 'files' || aula.tipo_conteudo === 'mixed') {
                            fileIcon = `<i data-lucide="file-text" class="w-5 h-5 text-gray-500 flex-shrink-0"></i>`;
                        }
                        // Combine them, possibly with a small space
                        iconHtml = videoIcon + (videoIcon && fileIcon ? '<span class="w-1"></span>' : '') + fileIcon;


                        if (!firstAvailableLesson) { // Keep track of the first unlocked lesson
                            firstAvailableLesson = aula;
                        }
                    }

                    lessonButton.dataset.lessonId = aula.id;
                    lessonButton.innerHTML = `
                        <div class="flex items-center space-x-1">
                            ${iconHtml}
                        </div>
                        <span class="${textClass}">${aula.titulo}</span>
                        ${aula.is_locked ? `<span class="ml-auto text-xs text-gray-500">Disp. ${aula.available_at}</span>` : ''}
                    `;
                    lessonButton.addEventListener('click', () => loadLesson(aula));
                    lessonListContainer.appendChild(lessonButton);
                });
                lucide.createIcons();
                
                // Auto-load the first unlocked lesson of this module, or the very first one if none are unlocked.
                loadLesson(firstAvailableLesson || moduleData.aulas[0]);
            }
            
            // Event listeners for module cards
            moduleCards.forEach(card => {
                card.addEventListener('click', () => {
                    // Only allow click if module is not disabled
                    if (card.disabled) return;

                    playerWrapper.classList.remove('hidden'); // Make the player section visible
                    
                    const moduleIndex = parseInt(card.dataset.moduleIndex, 10);
                    displayLessonsForModule(moduleIndex);

                    // Scroll to player
                    playerWrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            });
        });
    </script>
</body>
</html>