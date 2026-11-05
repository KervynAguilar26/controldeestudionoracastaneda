// /sistema_nora/sw.js — Service Worker (ronda 5)
// Estrategia:
//   - Páginas (navegaciones): network-first con respaldo en caché → los portales
//     de Comedor e Infraestructura siguen funcionando sin internet.
//   - Estáticos (CSS/JS/íconos): cache-first con actualización en segundo plano.
//   - Nunca se cachean POST, APIs, el login, el CAPTCHA ni el logout.
// El caché de páginas se limpia al cerrar sesión (logout.php) para no dejar
// datos de un usuario en un equipo compartido.

const CACHE_VERSION = 'nora-v1';
const CACHE_STATIC  = 'nora-static-' + CACHE_VERSION;
const CACHE_PAGES   = 'nora-pages-' + CACHE_VERSION;

const PRECACHE = [
  './assets/manifest.json',
  './assets/img/icono.png'
];

// URLs que jamás deben guardarse en caché (contienen sesión/datos sensibles distintos)
// Nota: 'index.php' solo se excluye en la RAÍZ (el login); los index.php de
// módulos (p. ej. modulos/comedor/index.php) sí se cachean para uso offline.
function noCacheable(url) {
  const rel = url.replace(new URL(self.registration.scope).pathname, '');
  return /api_|captcha\.php|logout\.php|login|mi_qr\.php|imprimir_|_pdf\.php|buscar_padron/i.test(url)
         || rel === 'index.php';
}

self.addEventListener('install', (evento) => {
  evento.waitUntil(
    caches.open(CACHE_STATIC)
      .then((c) => c.addAll(PRECACHE))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (evento) => {
  evento.waitUntil(
    caches.keys().then((claves) =>
      Promise.all(
        claves
          .filter((k) => k !== CACHE_STATIC && k !== CACHE_PAGES)
          .map((k) => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (evento) => {
  const req = evento.request;

  // Solo GET; el resto (POST de formularios y APIs) va directo a la red.
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) {
    // Externos (CDN): cache-first para que CSS/JS/librerías carguen offline.
    evento.respondWith(
      caches.open(CACHE_STATIC).then(async (cache) => {
        const guardada = await cache.match(req);
        const actualizar = fetch(req)
          .then((res) => { if (res && (res.status === 200)) cache.put(req, res.clone()); return res; })
          .catch(() => null);
        return guardada || actualizar.then((r) => r || new Response('', { status: 504 }));
      })
    );
    return;
  }

  // Mismos dominios: las navegaciones (páginas PHP) se cachean para uso offline.
  if (req.mode === 'navigate' || (req.headers.get('accept') || '').includes('text/html')) {
    if (noCacheable(url.pathname)) return; // login, APIs, impresiones, logout...
    evento.respondWith(
      fetch(req)
        .then((res) => {
          if (res && res.status === 200) {
            const copia = res.clone();
            caches.open(CACHE_PAGES).then((c) => c.put(req, copia));
          }
          return res;
        })
        .catch(async () => {
          const guardada = await caches.match(req, { ignoreSearch: true });
          return guardada || new Response(
            '<!DOCTYPE html><html lang="es"><body style="font-family:sans-serif;background:#d6c4af;color:#521f29;text-align:center;padding:2rem">' +
            '<h3>Sin conexión</h3><p>El portal no está en caché. Conéctese a internet al menos una vez y vuelva a intentarlo.</p>' +
            '<p><a href="./index.php">Ir al inicio</a></p></body></html>',
            { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
          );
        })
    );
    return;
  }

  // Estáticos locales: cache-first.
  evento.respondWith(
    caches.open(CACHE_STATIC).then(async (cache) => {
      const guardada = await cache.match(req);
      const actualizar = fetch(req)
        .then((res) => { if (res && res.status === 200) cache.put(req, res.clone()); return res; })
        .catch(() => null);
      return guardada || actualizar.then((r) => r || new Response('', { status: 504 }));
    })
  );
});

// Mensaje desde la página: limpiar caché de páginas (logout)
self.addEventListener('message', (evento) => {
  if (evento.data === 'limpiar-caché-paginas') {
    caches.delete(CACHE_PAGES);
  }
});
