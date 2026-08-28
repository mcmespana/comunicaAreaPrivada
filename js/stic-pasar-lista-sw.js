/**
 * PASAR LISTA — service worker.
 * ===========================================================================
 * Hace que la pantalla de marcado ABRA sin cobertura. El borrador y la cola de
 * js/stic-pasar-lista.js ya cubren que la cobertura se caiga mientras marcas;
 * esto cubre lo otro: entrar en un sótano y que la página exista.
 *
 * SE SIRVE DESDE LA RAÍZ del sitio (`/?sticpa_sw=1`, ver
 * inc/stic-pasar-lista-sw.php) porque el alcance de un service worker no puede
 * ser más amplio que la carpeta desde la que se sirve, y el área privada no
 * está dentro de la carpeta del plugin.
 *
 * PRIVACIDAD — lo que se guarda aquí son pantallas con nombres, teléfonos y
 * datos de salud de menores. Dos reglas, y ninguna es opcional:
 *
 *   1. La caché va NOMBRADA POR USUARIO. Si en el mismo móvil entra otra
 *      persona, su caché es otra y no puede leer la anterior; y al detectar un
 *      usuario distinto, las cachés de los demás se borran.
 *   2. Al cerrar sesión se borra todo. La página lo avisa por postMessage.
 *
 * Solo se guardan las pantallas de Pasar Lista y los recursos estáticos. Nada
 * de POST, nada de otras secciones del área privada.
 */

/* global self, caches, clients */

'use strict';

var CACHE_PREFIX = 'sticpa-pl-';
var SHELL_CACHE = CACHE_PREFIX + 'shell-v1';

// El usuario activo. Llega por postMessage desde la página; hasta entonces no
// se guarda NINGUNA pantalla, solo recursos estáticos.
var userKey = null;

function pageCacheName() {
    return userKey ? (CACHE_PREFIX + 'pages-' + userKey) : null;
}

/** ¿Es una pantalla de Pasar Lista? Solo esas se guardan.
 *
 * Copia de `sticpa_es_pantalla_pl()` (inc/stic-pasar-lista.php): el service
 * worker no ve PHP. Si allí se añade una pantalla, aquí también. */
var PL_EXTRA = ['single_stic_mis_grupos'];

function isPasarLista(url) {
    var page = url.searchParams.get('internalpage');
    if (!page) {
        return false;
    }
    return page.indexOf('single_stic_pasar_lista') === 0 || PL_EXTRA.indexOf(page) !== -1;
}

/** Recursos estáticos del plugin: CSS, JS, tipografías, iconos. */
function isAsset(url) {
    return /\.(css|js|woff2?|ttf|svg|png|jpg|jpeg|webp)$/i.test(url.pathname);
}

self.addEventListener('install', function () {
    // Sin precarga: lo que hace falta se guarda al usarlo. Precargar una lista
    // fija de rutas se queda desactualizada en el primer despliegue.
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (names) {
            return Promise.all(names.map(function (name) {
                // Se limpian versiones viejas del shell; las páginas se limpian
                // cuando se sabe quién es el usuario (mensaje `user`).
                if (name.indexOf(CACHE_PREFIX) === 0 && name.indexOf('shell-') !== -1 && name !== SHELL_CACHE) {
                    return caches.delete(name);
                }
                return null;
            }));
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('message', function (event) {
    var data = event.data || {};

    if (data.type === 'sticpa:user' && data.key) {
        userKey = String(data.key);
        // Cachés de páginas de OTROS usuarios: fuera. Es la regla que impide
        // que en un móvil compartido se vea la pantalla del anterior.
        var keep = pageCacheName();
        caches.keys().then(function (names) {
            names.forEach(function (name) {
                if (name.indexOf(CACHE_PREFIX + 'pages-') === 0 && name !== keep) {
                    caches.delete(name);
                }
            });
        });
        return;
    }

    if (data.type === 'sticpa:logout') {
        userKey = null;
        caches.keys().then(function (names) {
            names.forEach(function (name) {
                if (name.indexOf(CACHE_PREFIX) === 0) { caches.delete(name); }
            });
        });
    }
});

self.addEventListener('fetch', function (event) {
    var req = event.request;

    // Solo GET. Un POST guardado y reproducido escribiría dos veces en el CRM,
    // y de los envíos sin cobertura ya se encarga la cola de la página.
    if (req.method !== 'GET') { return; }

    var url;
    try { url = new URL(req.url); } catch (e) { return; }
    if (url.origin !== self.location.origin) { return; }

    // --- Recursos estáticos: de la caché primero, y se refrescan por detrás.
    if (isAsset(url)) {
        event.respondWith(
            caches.open(SHELL_CACHE).then(function (cache) {
                return cache.match(req).then(function (hit) {
                    var network = fetch(req).then(function (res) {
                        if (res && res.ok) { cache.put(req, res.clone()); }
                        return res;
                    }).catch(function () { return hit; });
                    return hit || network;
                });
            })
        );
        return;
    }

    // --- Pantallas de Pasar Lista: red primero, caché como red de seguridad.
    // Red primero y no caché primero a propósito: una lista de asistencia vieja
    // es peor que esperar un segundo. La caché es para cuando no hay red.
    if (req.mode === 'navigate' && isPasarLista(url)) {
        event.respondWith(
            fetch(req).then(function (res) {
                var name = pageCacheName();
                if (res && res.ok && name) {
                    var copy = res.clone();
                    caches.open(name).then(function (cache) { cache.put(req, copy); });
                }
                return res;
            }).catch(function () {
                var name = pageCacheName();
                if (!name) { return offlineResponse(); }
                return caches.open(name).then(function (cache) {
                    return cache.match(req).then(function (hit) {
                        return hit || cache.match(stripVolatile(url)) || offlineResponse();
                    });
                });
            })
        );
    }
});

/**
 * La misma pantalla sin los parámetros que no cambian su contenido.
 *
 * Un `&refrescar=1` pegado a la url la convierte en otra entrada de caché que
 * nunca acierta. Quitándolo, volver a la misma pantalla sin cobertura encuentra
 * la copia guardada.
 */
function stripVolatile(url) {
    var clean = new URL(url.href);
    clean.searchParams.delete('refrescar');
    return clean.href;
}

/** Lo mínimo para que no salga el dinosaurio del navegador. */
function offlineResponse() {
    var html = '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        + '<meta name="viewport" content="width=device-width,initial-scale=1">'
        + '<title>Sin conexión</title><style>'
        + 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        + 'font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f7f9fc;color:#1f2937;padding:2rem}'
        + 'div{max-width:22rem;text-align:center}h1{font-size:1.15rem;margin:0 0 .5rem}'
        + 'p{font-size:.92rem;line-height:1.5;color:#6b7280;margin:0}'
        + '</style></head><body><div>'
        + '<h1>Sin conexión</h1>'
        + '<p>Esta pantalla no está guardada en el móvil todavía. '
        + 'Ábrela una vez con cobertura y volverá a estar disponible aquí.</p>'
        + '</div></body></html>';
    return new Response(html, {
        status: 200,
        headers: { 'Content-Type': 'text/html; charset=utf-8' }
    });
}
