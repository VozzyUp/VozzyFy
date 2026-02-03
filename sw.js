// sw.js - MATADOR DE CACHE (Use isso durante o desenvolvimento)

const CACHE_NAME = 'limpeza-cache-v1';

self.addEventListener('install', (event) => {
  // Força o novo Service Worker a ativar imediatamente
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      // APAGA TODOS OS CACHES ANTIGOS
      return Promise.all(
        cacheNames.map((cache) => {
          console.log('Removendo cache antigo:', cache);
          return caches.delete(cache);
        })
      );
    }).then(() => {
      // Desregistra o Service Worker para ele parar de rodar
      console.log('Service Worker desregistrado com sucesso.');
      return self.registration.unregister();
    })
  );
  
  // Toma o controle de todas as abas abertas para aplicar a limpeza
  self.clients.claim();
});