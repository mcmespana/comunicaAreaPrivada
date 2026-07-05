/* ============================================================
   SinergiaCRM Private Area — CROPPER DE FOTOS (stic-cropper.js)
   ------------------------------------------------------------
   Recorte de imagen pensado PARA MÓVIL, sin dependencias:
     · Se engancha a cualquier <input type="file"> que acepte imágenes.
     · Al elegir una foto abre un modal con lienzo cuadrado:
       arrastrar con 1 dedo, pellizcar con 2 (pinch) o rueda/slider de zoom.
     · "Usar recorte" genera un JPEG cuadrado (800×800, calidad 0.85) y lo
       mete DE VUELTA en el input vía DataTransfer: el formulario se envía
       exactamente igual que antes, con la imagen ya recortada.
     · Progressive enhancement: si el navegador no soporta DataTransfer o
       canvas, o algo falla, se conserva el archivo original tal cual.
   ============================================================ */
(function () {
    'use strict';

    var VIEW = 320;      // tamaño lógico del lienzo de previsualización
    var OUTPUT = 800;    // tamaño del recorte final (px)

    function supported() {
        try {
            return typeof DataTransfer !== 'undefined' &&
                (new DataTransfer()).files instanceof FileList &&
                !!document.createElement('canvas').getContext;
        } catch (err) {
            return false;
        }
    }

    function buildModal() {
        var overlay = document.createElement('div');
        overlay.className = 'stic-modal-overlay';
        overlay.innerHTML =
            '<div class="stic-crop-card" role="dialog" aria-modal="true" aria-label="Recortar foto">' +
                '<h4 class="stic-crop-title">Encuadra tu foto</h4>' +
                '<p class="stic-crop-sub">Arrastra para mover · pellizca o usa el zoom</p>' +
                '<div class="stic-crop-canvas-wrap">' +
                    '<canvas width="' + VIEW + '" height="' + VIEW + '"></canvas>' +
                '</div>' +
                '<div class="stic-crop-zoom">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/><path d="M8 11h6"/></svg>' +
                    '<input type="range" min="0" max="1" step="0.001" value="0" aria-label="Zoom">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/><path d="M8 11h6M11 8v6"/></svg>' +
                '</div>' +
                '<div class="stic-crop-actions">' +
                    '<button type="button" class="stic-modal-btn stic-modal-btn--cancel">Usar sin recortar</button>' +
                    '<button type="button" class="stic-modal-btn stic-crop-btn-apply">Usar recorte</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);
        return overlay;
    }

    function openCropper(input, file) {
        var url = URL.createObjectURL(file);
        var img = new Image();

        img.onload = function () {
            var overlay = buildModal();
            var canvas = overlay.querySelector('canvas');
            var ctx = canvas.getContext('2d');
            var zoomInput = overlay.querySelector('input[type="range"]');

            // Escala mínima: la imagen siempre cubre el lienzo completo.
            var minScale = Math.max(VIEW / img.width, VIEW / img.height);
            var maxScale = minScale * 4;
            var scale = minScale;
            var offX = (VIEW - img.width * scale) / 2;
            var offY = (VIEW - img.height * scale) / 2;

            function clamp() {
                // La imagen nunca deja huecos dentro del lienzo.
                offX = Math.min(0, Math.max(offX, VIEW - img.width * scale));
                offY = Math.min(0, Math.max(offY, VIEW - img.height * scale));
            }

            function draw() {
                clamp();
                ctx.fillStyle = '#f3f4f6';
                ctx.fillRect(0, 0, VIEW, VIEW);
                ctx.drawImage(img, offX, offY, img.width * scale, img.height * scale);
            }

            function setScale(next, cx, cy) {
                next = Math.min(maxScale, Math.max(minScale, next));
                // Zoom centrado en (cx, cy) del lienzo.
                offX = cx - (cx - offX) * (next / scale);
                offY = cy - (cy - offY) * (next / scale);
                scale = next;
                zoomInput.value = String((scale - minScale) / (maxScale - minScale));
                draw();
            }

            draw();

            /* ---- Gestos (Pointer Events): 1 dedo arrastra, 2 pellizcan ---- */
            var pointers = {};
            var lastDist = 0;

            function canvasPoint(e) {
                var r = canvas.getBoundingClientRect();
                var k = VIEW / r.width;   // el canvas se escala por CSS
                return { x: (e.clientX - r.left) * k, y: (e.clientY - r.top) * k };
            }

            canvas.addEventListener('pointerdown', function (e) {
                e.preventDefault();
                canvas.setPointerCapture(e.pointerId);
                pointers[e.pointerId] = canvasPoint(e);
                lastDist = 0;
            });
            canvas.addEventListener('pointermove', function (e) {
                if (!(e.pointerId in pointers)) { return; }
                var p = canvasPoint(e);
                var ids = Object.keys(pointers);
                if (ids.length === 1) {
                    offX += p.x - pointers[e.pointerId].x;
                    offY += p.y - pointers[e.pointerId].y;
                    draw();
                } else if (ids.length >= 2) {
                    pointers[e.pointerId] = p;
                    var a = pointers[ids[0]];
                    var b = pointers[ids[1]];
                    var dist = Math.hypot(a.x - b.x, a.y - b.y);
                    if (lastDist) {
                        setScale(scale * (dist / lastDist), (a.x + b.x) / 2, (a.y + b.y) / 2);
                    }
                    lastDist = dist;
                }
                pointers[e.pointerId] = p;
            });
            function pointerEnd(e) {
                delete pointers[e.pointerId];
                lastDist = 0;
            }
            canvas.addEventListener('pointerup', pointerEnd);
            canvas.addEventListener('pointercancel', pointerEnd);

            // Rueda del ratón (escritorio) y slider (todos).
            canvas.addEventListener('wheel', function (e) {
                e.preventDefault();
                var p = canvasPoint(e);
                setScale(scale * (e.deltaY < 0 ? 1.08 : 0.93), p.x, p.y);
            }, { passive: false });
            zoomInput.addEventListener('input', function () {
                var next = minScale + parseFloat(zoomInput.value) * (maxScale - minScale);
                setScale(next, VIEW / 2, VIEW / 2);
            });

            /* ---- Cerrar / aplicar ---- */
            function close() {
                overlay.classList.remove('is-active');
                setTimeout(function () { overlay.remove(); }, 280);
                URL.revokeObjectURL(url);
                document.removeEventListener('keydown', escHandler);
            }
            function escHandler(e) { if (e.key === 'Escape') { close(); } }
            document.addEventListener('keydown', escHandler);

            // "Usar sin recortar": se queda el archivo original del input.
            overlay.querySelector('.stic-modal-btn--cancel').addEventListener('click', close);

            overlay.querySelector('.stic-crop-btn-apply').addEventListener('click', function () {
                try {
                    var out = document.createElement('canvas');
                    out.width = OUTPUT;
                    out.height = OUTPUT;
                    var k = OUTPUT / VIEW;
                    out.getContext('2d').drawImage(
                        img,
                        offX * k, offY * k,
                        img.width * scale * k, img.height * scale * k
                    );
                    out.toBlob(function (blob) {
                        if (blob) {
                            var name = (file.name || 'foto').replace(/\.[^.]+$/, '') + '-recorte.jpg';
                            var cropped = new File([blob], name, { type: 'image/jpeg' });
                            var dt = new DataTransfer();
                            dt.items.add(cropped);
                            input.files = dt.files;
                            // Notificar por si alguien escucha el change (sin re-abrir el cropper).
                            input.setAttribute('data-cropped', '1');
                        }
                        close();
                    }, 'image/jpeg', 0.85);
                } catch (err) {
                    close(); // ante cualquier problema, se conserva el original
                }
            });

            // Activar transición del overlay.
            void overlay.offsetWidth;
            overlay.classList.add('is-active');
        };

        img.onerror = function () { URL.revokeObjectURL(url); };
        img.src = url;
    }

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    ready(function () {
        if (!supported()) { return; }
        document.addEventListener('change', function (e) {
            var input = e.target;
            if (!input || input.type !== 'file') { return; }
            var accept = (input.getAttribute('accept') || '');
            if (accept.indexOf('image') === -1) { return; }         // solo inputs de imagen
            if (input.getAttribute('data-cropped') === '1') {
                input.removeAttribute('data-cropped');              // change disparado por el propio cropper
                return;
            }
            var file = input.files && input.files[0];
            if (!file || !/^image\//.test(file.type)) { return; }
            openCropper(input, file);
        });
    });
})();
