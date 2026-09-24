<?php
/**
 * EL CUERPO DE UN EVENTO — de lo que se escribe en el CRM a lo que se ve.
 * ============================================================================
 *
 * ⚠️ ESTE FICHERO VIVE EN DOS REPOS Y ES EL MISMO, BYTE A BYTE.
 *
 *   Original → comunicaAreaPrivada/inc/eventos-cuerpo.php   ← SE EDITA AQUÍ
 *   Copia    → comunicaFormularios/eventos_cuerpo.php
 *
 * La copia la trae sola `.github/workflows/sync-cuerpo-eventos.yml` del repo de
 * formularios (el mismo mecanismo que `CAMPOS.md`). Lo que se toque en la copia
 * se pisa en la siguiente sincronización.
 *
 * Existe para que el texto de un evento se lea IGUAL en los tres sitios donde
 * sale: la página pública (`/actividades`), el modal de la convivencia de los
 * formularios y la ficha del evento en el área privada. Dos renderizadores
 * serían dos formas de escribir, y en un mes dirían cosas distintas.
 *
 * No depende de nada: ni de WordPress ni de `crm_proxy.php`. Solo PHP (y la
 * extensión DOM para el día que el cuerpo llegue en HTML; sin ella, el HTML se
 * trata como texto y sigue saliendo, feo pero entero).
 *
 * ── Tres pasos, separados a propósito ──────────────────────────────────────
 *
 *   1. mcm_cuerpo_bloques($texto)          lo escrito → una lista de BLOQUES
 *   2. (quien llama puede quitar o mover bloques: el cartel, por ejemplo)
 *   3. mcm_cuerpo_html($bloques, $opciones) los bloques → HTML de la web
 *
 * Los bloques son el contrato. Hoy hay un pintor (la web); el día que el mismo
 * contenido tenga que salir en un CORREO, se escribe un segundo pintor sobre
 * los mismos bloques y nadie tiene que volver a escribir el evento.
 *
 * ── Lo que entra ───────────────────────────────────────────────────────────
 *
 * Hoy, `web_cuerpo_c` es un TextArea y se escribe en MARKDOWN REDUCIDO (la
 * chuleta está en `mcm_cuerpo_bloques_md()` y en la guía de administradores).
 *
 * Mañana será un campo HTML de Sinergia (con su editor). Para entonces ya está
 * hecho: si lo que llega tiene etiquetas de editor (<p>, <strong>, <ul>…), se
 * SANEA y se traduce al mismo Markdown, y de ahí a los mismos bloques. O sea
 * que un evento viejo en Markdown y uno nuevo en HTML se pintan igual, y el día
 * del cambio no hay que migrar nada.
 *
 * ── La regla de seguridad, que no se toca ──────────────────────────────────
 *
 * PRIMERO SE ESCAPA TODO Y DESPUÉS SE APLICAN LAS MARCAS. Lo que alguien
 * escriba en el CRM no puede meter una etiqueta en nuestra web: un `<script>`
 * escrito como texto sale como el texto «<script>». Del HTML solo se quedan las
 * etiquetas de la lista blanca, traducidas; todo atributo (style, onclick…) se
 * tira. Y todo enlace pasa por `mcm_cuerpo_url()`: ni `javascript:` ni `data:`.
 */

if (!function_exists('mcm_cuerpo_bloques')) {

    /**
     * Lo escrito en el CRM → lista de bloques.
     *
     * Cada bloque es un array con `tipo` y sus datos. Los textos van SIN
     * escapar y con las marcas de línea (negrita, enlaces) sin aplicar: eso es
     * cosa del pintor.
     *
     *   titulo     nivel (2|3), texto
     *   parrafo    texto (puede llevar saltos de línea)
     *   lista      ordenada (bool), inicio (int), items []
     *   cita       texto
     *   aviso      texto
     *   boton      texto, url
     *   imagen     src, pie
     *   galeria    imagenes [src…]
     *   video      proveedor (youtube|vimeo), id, url
     *   separador
     */
    function mcm_cuerpo_bloques($texto)
    {
        $texto = mcm_cuerpo_normalizar($texto);
        if ($texto === '') {
            return array();
        }
        if (mcm_cuerpo_es_html($texto)) {
            $md = mcm_cuerpo_html_a_md($texto);
            if ($md !== null) {
                $texto = $md;
            }
        }
        return mcm_cuerpo_bloques_md($texto);
    }

    /**
     * Deja el texto como lo escribió la persona.
     *
     * ⚠️ SuiteCRM GUARDA LOS TEXTOS CON ENTIDADES HTML: unas «comillas» llegan
     * como `&quot;comillas&quot;`, un `>` como `&gt;` y un apóstrofo como
     * `&#039;`. Si no se deshacen aquí, el escape de después las escapa OTRA
     * vez y en la web sale literalmente «&quot;». A veces vienen dobles
     * (`&amp;quot;`), por eso se repite hasta que no cambie (tres vueltas como
     * mucho).
     */
    function mcm_cuerpo_normalizar($texto)
    {
        $t = (string) $texto;
        for ($i = 0; $i < 3 && preg_match('/&(#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i', $t); $i++) {
            $antes = $t;
            $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($t === $antes) {
                break;
            }
        }
        // El BOM y los espacios de anchura cero que trae un copiar-pegar de
        // Word o de WhatsApp no se ven, pero rompen un `## ` al principio.
        $t = str_replace(array("\xEF\xBB\xBF", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D"), '', $t);
        // El espacio duro de `&nbsp;` es un espacio para todo lo de aquí.
        $t = str_replace("\xC2\xA0", ' ', $t);
        $t = str_replace("\t", '    ', $t);
        return trim(preg_replace('/\R/u', "\n", $t));
    }

    /**
     * ¿Esto es HTML de un editor, o Markdown con algún `<` suelto?
     *
     * Solo cuenta como HTML si trae etiquetas de las que pone un editor. Un
     * `<script>` a secas NO lo convierte en HTML: se queda como texto y sale
     * como texto, que es lo que tiene que pasar con él.
     */
    function mcm_cuerpo_es_html($texto)
    {
        return (bool) preg_match(
            '~<(p|br|div|h[1-6]|ul|ol|li|strong|b|em|i|u|a\s[^>]*href|img\s|table|blockquote|hr|span|figure)\b[^>]*>~i',
            (string) $texto
        );
    }

    /* ── MARKDOWN → bloques ────────────────────────────────────────────── */

    /**
     * El Markdown reducido de la casa.
     *
     *   # Título  /  ## Título         título de sección
     *   ### Subtítulo                  subtítulo
     *   Una línea                      párrafo; un salto de línea se RESPETA
     *   (línea en blanco)              separa párrafos
     *   **negrita**  __negrita__        *cursiva*
     *   - una cosa  (o *, +, •, –)     lista
     *   1. primero  (o 1))             lista numerada
     *   > una cita                     cita destacada
     *   ---                            línea separadora
     *   [texto](https://…)             enlace (también mailto: y tel:)
     *   https://…  correo@…  649 …      se enlazan solos
     *   ![pie](https://…/foto.jpg)     imagen con su pie
     *   https://…/foto.jpg             una línea que es solo una imagen, también
     *   [aviso] Texto                  recuadro destacado (sigue en las líneas
     *                                  de debajo hasta la línea en blanco)
     *   [boton] Texto | https://…      botón
     *   [galeria] + una URL por línea  rejilla de imágenes
     *   [video] https://youtu.be/…     vídeo de YouTube o Vimeo
     *
     * ⚠️ UN SALTO DE LÍNEA ES UN SALTO DE LÍNEA. En Markdown de verdad dos
     * líneas seguidas se juntan en una, y así «60 €» + «Incluye el
     * alojamiento…» salían pegados en la misma frase. Quien escribe en la caja
     * del CRM pulsa Intro porque quiere una línea nueva; se le hace caso.
     */
    function mcm_cuerpo_bloques_md($texto)
    {
        $bloques = array();
        $actual = null;   // el bloque que se está rellenando

        $cierra = function () use (&$actual, &$bloques) {
            if ($actual === null) {
                return;
            }
            if ($actual['tipo'] === 'galeria' && empty($actual['imagenes'])) {
                $actual = null;
                return;
            }
            if (isset($actual['lineas'])) {
                $actual['texto'] = implode("\n", $actual['lineas']);
                unset($actual['lineas']);
                if (trim($actual['texto']) === '') {
                    $actual = null;
                    return;
                }
            }
            $bloques[] = $actual;
            $actual = null;
        };

        foreach (explode("\n", (string) $texto) as $crudo) {
            $l = trim($crudo);
            $sangrado = $crudo !== '' && ($crudo[0] === ' ') && $l !== '';

            // Dentro de una galería, cada línea con una URL es una imagen. Las
            // líneas en blanco no la cierran (un editor HTML pone cada URL en
            // su párrafo); cualquier otra cosa, sí.
            if ($actual !== null && $actual['tipo'] === 'galeria') {
                if ($l === '') {
                    continue;
                }
                $url = mcm_cuerpo_url($l, false);
                if ($url !== '') {
                    $actual['imagenes'][] = $url;
                    continue;
                }
                $cierra();
            }

            if ($l === '') {
                $cierra();
                continue;
            }

            // Títulos. `#` y `##` son el título de sección (en la página ya hay
            // un h1, que es el nombre del evento); `###` y más, subtítulo.
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/u', $l, $m)) {
                $cierra();
                $bloques[] = array('tipo' => 'titulo', 'nivel' => strlen($m[1]) <= 2 ? 2 : 3, 'texto' => $m[2]);
                continue;
            }

            // Separador: `---`, `***`, `___` (con o sin espacios).
            if (preg_match('/^([-*_])(\s*\1){2,}$/', $l)) {
                $cierra();
                $bloques[] = array('tipo' => 'separador');
                continue;
            }

            if (preg_match('/^\[(galeria|galería)\]$/iu', $l)) {
                $cierra();
                $actual = array('tipo' => 'galeria', 'imagenes' => array());
                continue;
            }

            if (preg_match('/^\[aviso\]\s*(.*)$/iu', $l, $m)) {
                $cierra();
                $actual = array('tipo' => 'aviso', 'lineas' => array($m[1]));
                continue;
            }

            if (preg_match('/^\[(boton|botón)\]\s*(.*)$/iu', $l, $m)) {
                $cierra();
                $partes = explode('|', $m[2]);
                $texto = trim(array_shift($partes));
                $url = mcm_cuerpo_url(trim(implode('|', $partes)));
                // Un botón sin destino no es un botón: es un bulo. No se pinta.
                if ($texto !== '' && $url !== '') {
                    $bloques[] = array('tipo' => 'boton', 'texto' => $texto, 'url' => $url);
                }
                continue;
            }

            if (preg_match('/^\[(video|vídeo)\]\s*(.*)$/iu', $l, $m)) {
                $cierra();
                $video = mcm_cuerpo_video($m[2]);
                if ($video !== null) {
                    $bloques[] = $video;
                } elseif (($url = mcm_cuerpo_url(trim($m[2]), false)) !== '') {
                    // No es de YouTube ni de Vimeo: no se incrusta lo que no
                    // conocemos, pero tampoco se pierde. Va de botón.
                    $bloques[] = array('tipo' => 'boton', 'texto' => 'Ver el vídeo', 'url' => $url);
                }
                continue;
            }

            // Una imagen sola en su línea: `![pie](url)`, o la URL de una imagen
            // pegada a pelo (lo que hace casi todo el mundo con un imgur).
            if (preg_match('/^!\[([^\]]*)\]\(\s*([^)\s]+)(?:\s+"[^"]*")?\s*\)$/u', $l, $m)
                || preg_match('/^()(https?:\/\/\S+\.(?:jpe?g|png|gif|webp)(?:\?\S*)?)$/iu', $l, $m)) {
                $cierra();
                $src = mcm_cuerpo_url($m[2], false);
                if ($src !== '') {
                    $bloques[] = array('tipo' => 'imagen', 'src' => $src, 'pie' => trim($m[1]));
                }
                continue;
            }

            // Listas. El `*` de lista lleva espacio detrás; el de `*cursiva*`, no.
            if (preg_match('/^(?:[-*+•▪◦–]|·)\s+(.*)$/u', $l, $m)
                || preg_match('/^(\d{1,3})[.)]\s+(.*)$/', $l, $n)) {
                $ordenada = empty($m);
                $item = $ordenada ? $n[2] : $m[1];
                if ($actual === null || $actual['tipo'] !== 'lista' || $actual['ordenada'] !== $ordenada) {
                    $cierra();
                    $actual = array(
                        'tipo' => 'lista',
                        'ordenada' => $ordenada,
                        'inicio' => $ordenada ? max(1, (int) $n[1]) : 1,
                        'items' => array(),
                    );
                }
                $actual['items'][] = $item;
                continue;
            }

            if (preg_match('/^>\s?(.*)$/u', $l, $m)) {
                if ($actual === null || $actual['tipo'] !== 'cita') {
                    $cierra();
                    $actual = array('tipo' => 'cita', 'lineas' => array());
                }
                $actual['lineas'][] = $m[1];
                continue;
            }

            // Texto normal. Si se está dentro de un aviso, sigue el aviso; si es
            // una línea sangrada debajo de una lista, continúa el último punto.
            if ($actual !== null && $actual['tipo'] === 'aviso') {
                $actual['lineas'][] = $l;
                continue;
            }
            if ($actual !== null && $actual['tipo'] === 'lista' && $sangrado) {
                $ultimo = count($actual['items']) - 1;
                $actual['items'][$ultimo] .= "\n" . $l;
                continue;
            }
            if ($actual === null || $actual['tipo'] !== 'parrafo') {
                $cierra();
                $actual = array('tipo' => 'parrafo', 'lineas' => array());
            }
            $actual['lineas'][] = $l;
        }
        $cierra();

        return $bloques;
    }

    /**
     * Un vídeo que sabemos incrustar, o null.
     *
     * Solo YouTube y Vimeo, y del enlace solo se saca el identificador: la URL
     * que se pinta la escribe este código, no la persona. Así no se puede
     * incrustar nada que no sea un vídeo.
     */
    function mcm_cuerpo_video($url)
    {
        $url = trim((string) $url);
        if (preg_match('~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)
            || preg_match('~^https?://(?:www\.)?youtube-nocookie\.com/embed/([A-Za-z0-9_-]{11})~', $url, $m)) {
            return array('tipo' => 'video', 'proveedor' => 'youtube', 'id' => $m[1],
                'url' => 'https://www.youtube.com/watch?v=' . $m[1]);
        }
        if (preg_match('~^https?://(?:www\.|player\.)?vimeo\.com/(?:video/)?(\d{6,12})~', $url, $m)) {
            return array('tipo' => 'video', 'proveedor' => 'vimeo', 'id' => $m[1],
                'url' => 'https://vimeo.com/' . $m[1]);
        }
        return null;
    }

    /* ── HTML → Markdown (para el día que el campo sea HTML) ───────────── */

    /**
     * HTML de un editor → el Markdown de la casa. Null si no hay DOM.
     *
     * Se traduce en vez de pintarse tal cual por dos razones: la seguridad (lo
     * que no está en la lista blanca desaparece, y los atributos no pasan) y el
     * diseño (pegar desde Word trae `<span style="font-family:Calibri">`, y
     * aquí cada evento se ve con la letra de la casa y no con la suya).
     *
     * Y así las marcas de la casa ([aviso], [boton]…) siguen funcionando
     * escritas dentro del editor, en su propio párrafo.
     */
    function mcm_cuerpo_html_a_md($html)
    {
        if (!class_exists('DOMDocument')) {
            return null;
        }
        $dom = new DOMDocument();
        $previo = libxml_use_internal_errors(true);
        $ok = $dom->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'
                . $html . '</body></html>',
            LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previo);
        if (!$ok) {
            return null;
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return null;
        }
        $md = mcm_cuerpo_nodo_md($body, true);
        // Nunca más de una línea en blanco seguida.
        return trim(preg_replace("/\n{3,}/", "\n\n", $md));
    }

    /**
     * Un nodo de bloque → Markdown.
     *
     * ⚠️ El texto suelto de la RAÍZ conserva sus saltos de línea. Es por quien
     * escribe en Markdown y un día mete un `<b>` a mano: eso ya cuenta como
     * HTML, y si aquí se juntaran las líneas, sus `##` y sus `-` acabarían
     * todos en un solo párrafo. Dentro de un <p> sí se juntan, que es lo que
     * hace un navegador.
     */
    function mcm_cuerpo_nodo_md($nodo, $raiz = false)
    {
        $salida = '';
        $enLinea = '';   // texto suelto que se va juntando hasta el próximo bloque

        $suelta = function () use (&$enLinea, &$salida) {
            $t = trim(preg_replace("/[ ]*\n[ ]*/", "\n", $enLinea));
            if ($t !== '') {
                $salida .= $t . "\n\n";
            }
            $enLinea = '';
        };

        foreach ($nodo->childNodes as $hijo) {
            if ($hijo->nodeType === XML_TEXT_NODE) {
                $enLinea .= $raiz
                    ? preg_replace('/[ ]+/u', ' ', $hijo->nodeValue)
                    : preg_replace('/\s+/u', ' ', $hijo->nodeValue);
                continue;
            }
            if ($hijo->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $tag = strtolower($hijo->nodeName);

            // Lo que se tira CON su contenido.
            if (in_array($tag, array('script', 'style', 'noscript', 'template', 'head', 'title', 'object', 'embed', 'form', 'button', 'select', 'textarea', 'svg', 'math'), true)) {
                continue;
            }

            switch ($tag) {
                case 'h1':
                case 'h2':
                    $suelta();
                    $salida .= '## ' . mcm_cuerpo_una_linea(mcm_cuerpo_linea_md($hijo)) . "\n\n";
                    continue 2;
                case 'h3':
                case 'h4':
                case 'h5':
                case 'h6':
                    $suelta();
                    $salida .= '### ' . mcm_cuerpo_una_linea(mcm_cuerpo_linea_md($hijo)) . "\n\n";
                    continue 2;
                case 'ul':
                case 'ol':
                    $suelta();
                    $salida .= mcm_cuerpo_lista_md($hijo, $tag === 'ol') . "\n";
                    continue 2;
                case 'blockquote':
                    $suelta();
                    $dentro = trim(mcm_cuerpo_nodo_md($hijo));
                    if ($dentro !== '') {
                        $salida .= '> ' . str_replace("\n", "\n> ", preg_replace("/\n{2,}/", "\n", $dentro)) . "\n\n";
                    }
                    continue 2;
                case 'hr':
                    $suelta();
                    $salida .= "---\n\n";
                    continue 2;
                case 'img':
                    $suelta();
                    $salida .= mcm_cuerpo_img_md($hijo, '') . "\n\n";
                    continue 2;
                case 'figure':
                    $suelta();
                    $pie = '';
                    foreach ($hijo->getElementsByTagName('figcaption') as $fc) {
                        $pie = mcm_cuerpo_una_linea($fc->textContent);
                    }
                    foreach ($hijo->getElementsByTagName('img') as $img) {
                        $salida .= mcm_cuerpo_img_md($img, $pie) . "\n\n";
                    }
                    continue 2;
                case 'iframe':
                    $suelta();
                    $video = mcm_cuerpo_video($hijo->getAttribute('src'));
                    if ($video !== null) {
                        $salida .= '[video] ' . $video['url'] . "\n\n";
                    }
                    continue 2;
                case 'table':
                    $suelta();
                    // Una tabla no cabe en un móvil de 375px ni en un correo: se
                    // lee fila a fila, con las celdas separadas por un punto.
                    foreach ($hijo->getElementsByTagName('tr') as $tr) {
                        $celdas = array();
                        foreach ($tr->childNodes as $td) {
                            if ($td->nodeType === XML_ELEMENT_NODE && in_array(strtolower($td->nodeName), array('td', 'th'), true)) {
                                $c = mcm_cuerpo_una_linea(mcm_cuerpo_linea_md($td));
                                if ($c !== '') {
                                    $celdas[] = $c;
                                }
                            }
                        }
                        if ($celdas) {
                            $salida .= implode(' · ', $celdas) . "\n\n";
                        }
                    }
                    continue 2;
                case 'br':
                    $enLinea .= "\n";
                    continue 2;
                case 'p':
                case 'div':
                case 'section':
                case 'article':
                case 'header':
                case 'footer':
                case 'main':
                case 'center':
                case 'li':
                case 'dd':
                case 'dt':
                case 'figcaption':
                    $suelta();
                    // Un bloque que dentro lleva más bloques se recorre; uno
                    // que solo lleva texto es un párrafo.
                    if (mcm_cuerpo_tiene_bloques($hijo)) {
                        $salida .= mcm_cuerpo_nodo_md($hijo);
                    } else {
                        $enLinea = mcm_cuerpo_linea_md($hijo);
                        $suelta();
                    }
                    continue 2;
            }

            // Cualquier otra cosa (negrita, enlace, span…) es texto en línea.
            $enLinea .= mcm_cuerpo_pieza_md($hijo);
        }
        $suelta();
        return $salida;
    }

    /** ¿Lleva dentro algo que sea un bloque (y no solo texto)? */
    function mcm_cuerpo_tiene_bloques($nodo)
    {
        foreach ($nodo->childNodes as $hijo) {
            if ($hijo->nodeType === XML_ELEMENT_NODE
                && in_array(strtolower($hijo->nodeName), array('p', 'div', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'table', 'hr', 'img', 'figure', 'iframe', 'section', 'article'), true)) {
                return true;
            }
        }
        return false;
    }

    /** Lo de dentro de un párrafo → Markdown de línea. */
    function mcm_cuerpo_linea_md($nodo)
    {
        $t = '';
        foreach ($nodo->childNodes as $hijo) {
            $t .= mcm_cuerpo_pieza_md($hijo);
        }
        return $t;
    }

    /** UN nodo de dentro de un párrafo (texto, negrita, enlace…) → Markdown. */
    function mcm_cuerpo_pieza_md($hijo)
    {
        if ($hijo->nodeType === XML_TEXT_NODE) {
            return preg_replace('/\s+/u', ' ', $hijo->nodeValue);
        }
        if ($hijo->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }
        $tag = strtolower($hijo->nodeName);
        if (in_array($tag, array('script', 'style', 'noscript', 'template', 'svg', 'iframe', 'object', 'embed', 'button', 'select', 'textarea'), true)) {
            return '';
        }
        $dentro = mcm_cuerpo_linea_md($hijo);
        switch ($tag) {
            case 'br':
                return "\n";
            case 'strong':
            case 'b':
                return trim($dentro) !== '' ? mcm_cuerpo_envuelve($dentro, '**') : $dentro;
            case 'em':
            case 'i':
                return trim($dentro) !== '' ? mcm_cuerpo_envuelve($dentro, '*') : $dentro;
            case 'a':
                $href = str_replace(' ', '%20', trim($hijo->getAttribute('href')));
                return ($href !== '' && trim($dentro) !== '') ? '[' . trim($dentro) . '](' . $href . ')' : $dentro;
            case 'img':
                // Una imagen dentro de una frase no tiene dónde ir: se deja en
                // su propia línea.
                return "\n" . mcm_cuerpo_img_md($hijo, '') . "\n";
        }
        return $dentro;
    }

    /** `**` alrededor, pero con los espacios FUERA (`** hola**` no es negrita). */
    function mcm_cuerpo_envuelve($texto, $marca)
    {
        preg_match('/^(\s*)(.*?)(\s*)$/su', $texto, $m);
        return $m[1] . $marca . $m[2] . $marca . $m[3];
    }

    function mcm_cuerpo_lista_md($lista, $ordenada)
    {
        $md = '';
        $n = 1;
        foreach ($lista->childNodes as $li) {
            if ($li->nodeType !== XML_ELEMENT_NODE || strtolower($li->nodeName) !== 'li') {
                continue;
            }
            // Las listas anidadas se aplanan: en un móvil, dos niveles de
            // sangría se comen media pantalla y no se leen mejor.
            $anidadas = array();
            $texto = '';
            foreach ($li->childNodes as $parte) {
                if ($parte->nodeType === XML_ELEMENT_NODE && in_array(strtolower($parte->nodeName), array('ul', 'ol'), true)) {
                    $anidadas[] = $parte;
                    continue;
                }
                if ($parte->nodeType === XML_ELEMENT_NODE && in_array(strtolower($parte->nodeName), array('p', 'div'), true)) {
                    $texto .= ' ' . mcm_cuerpo_linea_md($parte);
                } else {
                    $texto .= mcm_cuerpo_pieza_md($parte);
                }
            }
            $texto = mcm_cuerpo_una_linea($texto);
            if ($texto !== '') {
                $md .= ($ordenada ? ($n++) . '. ' : '- ') . $texto . "\n";
            }
            foreach ($anidadas as $sub) {
                $md .= mcm_cuerpo_lista_md($sub, strtolower($sub->nodeName) === 'ol');
            }
        }
        return $md;
    }

    function mcm_cuerpo_img_md($img, $pie)
    {
        $src = trim($img->getAttribute('src'));
        if ($src === '') {
            return '';
        }
        return '![' . str_replace(array('[', ']'), '', $pie) . '](' . str_replace(' ', '%20', $src) . ')';
    }

    /** Todo en una línea y sin espacios dobles. */
    function mcm_cuerpo_una_linea($texto)
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $texto));
    }

    /* ── Enlaces ───────────────────────────────────────────────────────── */

    /**
     * Un enlace que ha escrito una persona y que va a pulsar otra.
     *
     * Pasa: `http(s)://servidor…`, una ruta nuestra (`/algo`), `www.algo`
     * (se le pone https) y —si `$contacto`— `mailto:` y `tel:`. Todo lo demás
     * se tira, empezando por `javascript:` y `data:`.
     *
     * Y dos trampas ya pagadas:
     *  · SuiteCRM rellena un campo URL vacío con «http://» a secas: no es un
     *    enlace.
     *  · `/archivo.php?d=ID-DEL-CARTEL-EN-EL-CRM` es un hueco de la plantilla
     *    sin rellenar, no un documento: un id del CRM es hexadecimal.
     *
     * ⚠️ `eventosEnlaceSeguro()` de `crm_proxy.php` aplica estas mismas reglas a
     * los campos del evento; hay un test que comprueba que no divergen.
     */
    function mcm_cuerpo_url($url, $contacto = true)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if ($contacto) {
            if (preg_match('/^mailto:[^\s@<>"]+@[^\s@<>"]+\.[^\s@<>"]+$/i', $url)) {
                return $url;
            }
            if (preg_match('/^tel:\+?[0-9][0-9 .-]{5,}$/i', $url)) {
                return 'tel:' . preg_replace('/[^0-9+]/', '', substr($url, 4));
            }
        }
        if (preg_match('/^www\.[a-z0-9-]+\.[^\s]+$/i', $url)) {
            $url = 'https://' . $url;
        }
        if (preg_match('#^/archivo\.php\?d=(.*)$#i', $url, $m)) {
            $id = rawurldecode(explode('&', $m[1])[0]);
            if (!preg_match('/^[a-f0-9-]{1,64}$/i', $id)) {
                return '';
            }
        }
        if (preg_match('#^https?://[^/\s"<>]+[^\s"<>]*$#i', $url) || preg_match('#^/[^/\s][^\s"<>]*$|^/$#', $url)) {
            return $url;
        }
        return '';
    }

    /** Los dominios que son NUESTROS: se abren en la misma pestaña y por https. */
    function mcm_cuerpo_dominios_propios()
    {
        return array('comunica.movimientoconsolacion.com');
    }

    /**
     * Cómo se pinta un enlace ya validado: `[url final, ¿se va fuera?]`.
     *
     * Un enlace a nuestra casa escrito con `http://` (el de la inscripción de
     * la convivencia lo está) se pasa a https y NO abre pestaña nueva: salir de
     * la ficha para ir al formulario de al lado en otra pestaña es desorientar.
     */
    function mcm_cuerpo_destino($url, $propios = null)
    {
        $propios = $propios === null ? mcm_cuerpo_dominios_propios() : (array) $propios;
        if (preg_match('#^https?://([^/:?\#]+)#i', $url, $m)) {
            $host = strtolower($m[1]);
            foreach ($propios as $p) {
                if ($host === strtolower($p)) {
                    return array(preg_replace('#^http://#i', 'https://', $url), false);
                }
            }
            return array($url, true);
        }
        return array($url, false);
    }

    /* ── Bloques → HTML de la web ──────────────────────────────────────── */

    /**
     * Opciones (todas opcionales):
     *   clases        clase CSS de cada pieza (ver los valores por defecto)
     *   titulo_base   2 → `##` es un <h2>. En una pantalla cuyo título ya es
     *                 un <h3>, se pone 4 y el orden de títulos no se rompe
     *   miniatura     callable($src, $ancho) → la versión ligera de una imagen
     *   url           callable($url) → reescribe CUALQUIER enlace o imagen ya
     *                 validado. El área privada lo usa para servir los
     *                 documentos del evento por su propio endpoint (con
     *                 permisos) en vez de por `/archivo.php`, que solo sirve
     *                 los de eventos publicados
     *   propios       dominios que no abren pestaña nueva
     */
    function mcm_cuerpo_html($bloques, $opciones = array())
    {
        $cl = array_merge(array(
            'aviso' => 'evento-aviso',
            'boton' => 'evento-boton',
            'boton_linea' => 'evento-boton-linea',
            'imagen' => 'evento-imagen',
            'galeria' => 'evento-galeria',
            'cita' => 'evento-cita',
            'video' => 'evento-video',
        ), (array) ($opciones['clases'] ?? array()));
        $base = max(1, min(5, (int) ($opciones['titulo_base'] ?? 2)));
        $mini = $opciones['miniatura'] ?? null;
        $propios = $opciones['propios'] ?? null;
        $reescribe = mcm_cuerpo_reescritor($opciones);
        $ligera = function ($src, $ancho) use ($mini, $reescribe) {
            $src = $reescribe($src);
            return is_callable($mini) ? (string) call_user_func($mini, $src, $ancho) : $src;
        };
        $e = function ($t) {
            return htmlspecialchars((string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $linea = function ($t) use ($opciones) {
            return str_replace("\n", "<br>\n", mcm_cuerpo_linea($t, $opciones));
        };

        $html = '';
        foreach ((array) $bloques as $b) {
            switch ($b['tipo'] ?? '') {
                case 'titulo':
                    $n = min(6, $base + (((int) ($b['nivel'] ?? 2)) >= 3 ? 1 : 0));
                    $html .= "<h{$n}>" . mcm_cuerpo_linea(mcm_cuerpo_una_linea($b['texto']), $opciones) . "</h{$n}>\n";
                    break;
                case 'parrafo':
                    $html .= '<p>' . $linea($b['texto']) . "</p>\n";
                    break;
                case 'lista':
                    $tag = !empty($b['ordenada']) ? 'ol' : 'ul';
                    $inicio = (int) ($b['inicio'] ?? 1);
                    $html .= '<' . $tag . ($tag === 'ol' && $inicio > 1 ? ' start="' . $inicio . '"' : '') . ">\n";
                    foreach ((array) $b['items'] as $item) {
                        $html .= '<li>' . $linea($item) . "</li>\n";
                    }
                    $html .= "</{$tag}>\n";
                    break;
                case 'cita':
                    $html .= '<blockquote class="' . $e($cl['cita']) . '"><p>' . $linea($b['texto']) . "</p></blockquote>\n";
                    break;
                case 'aviso':
                    $html .= '<p class="' . $e($cl['aviso']) . '">' . $linea($b['texto']) . "</p>\n";
                    break;
                case 'boton':
                    list($url, $fuera) = mcm_cuerpo_destino($reescribe($b['url']), $propios);
                    $html .= '<p class="' . $e($cl['boton_linea']) . '"><a class="' . $e($cl['boton']) . '" href="'
                        . $e($url) . '"' . ($fuera ? ' target="_blank" rel="noopener noreferrer"' : '') . '>'
                        . $e($b['texto']) . "</a></p>\n";
                    break;
                case 'imagen':
                    $pie = trim((string) ($b['pie'] ?? ''));
                    $html .= '<figure class="' . $e($cl['imagen']) . '"><img src="' . $e($ligera($b['src'], 1200))
                        . '" alt="' . $e($pie) . '" loading="lazy">';
                    if ($pie !== '') {
                        $html .= '<figcaption>' . $e($pie) . '</figcaption>';
                    }
                    $html .= "</figure>\n";
                    break;
                case 'galeria':
                    $html .= '<div class="' . $e($cl['galeria']) . '">' . "\n";
                    foreach ((array) $b['imagenes'] as $src) {
                        $html .= '<img src="' . $e($ligera($src, 600)) . '" alt="" loading="lazy">' . "\n";
                    }
                    $html .= "</div>\n";
                    break;
                case 'video':
                    $src = ($b['proveedor'] ?? '') === 'vimeo'
                        ? 'https://player.vimeo.com/video/' . rawurlencode($b['id'])
                        : 'https://www.youtube-nocookie.com/embed/' . rawurlencode($b['id']);
                    $html .= '<div class="' . $e($cl['video']) . '"><iframe src="' . $e($src)
                        . '" title="Vídeo" loading="lazy" allow="autoplay; encrypted-media; picture-in-picture; fullscreen"'
                        . ' allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>' . "\n";
                    break;
                case 'separador':
                    $html .= "<hr>\n";
                    break;
            }
        }
        return $html;
    }

    /** La opción `url` como función: la de quien llama, o la identidad. */
    function mcm_cuerpo_reescritor($opciones)
    {
        $f = $opciones['url'] ?? null;
        return function ($u) use ($f) {
            if (!is_callable($f)) {
                return $u;
            }
            $nuevo = (string) call_user_func($f, $u);
            return $nuevo !== '' ? $nuevo : $u;
        };
    }

    /**
     * Lo de dentro de una línea: enlaces, negrita y cursiva.
     *
     * Se escapa ANTES de tocar nada. Los enlaces se apartan con una marca
     * mientras se aplican la negrita y la cursiva, para que un `_` o un `*` de
     * una URL no se convierta en cursiva y parta el enlace.
     */
    function mcm_cuerpo_linea($texto, $opciones = array())
    {
        $opciones = (array) $opciones;
        $propios = $opciones['propios'] ?? null;
        $reescribe = mcm_cuerpo_reescritor($opciones);
        $e = function ($t) {
            return htmlspecialchars((string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $t = $e($texto);
        $apartados = array();
        $aparta = function ($html) use (&$apartados) {
            $apartados[] = $html;
            return "\x01" . (count($apartados) - 1) . "\x02";
        };
        $enlace = function ($url, $textoHtml) use ($propios, $reescribe) {
            list($destino, $fuera) = mcm_cuerpo_destino($reescribe($url), $propios);
            return '<a href="' . htmlspecialchars($destino, ENT_QUOTES, 'UTF-8') . '"'
                . ($fuera ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $textoHtml . '</a>';
        };
        $marcas = function ($t) {
            $t = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/su', '<strong>$1</strong>', $t);
            $t = preg_replace('/(?<![\w])__(?=\S)(.+?)(?<=\S)__(?![\w])/su', '<strong>$1</strong>', $t);
            $t = preg_replace('/(?<![*\w])\*(?=\S)([^*]+?)(?<=\S)\*(?![*\w])/su', '<em>$1</em>', $t);
            return $t;
        };

        // 1. Enlaces escritos: [texto](url). La URL se valida AQUÍ, que es la
        //    única forma de que un `[pincha](javascript:…)` no pase.
        $t = preg_replace_callback('/\[([^\]]+)\]\(\s*([^)\s]+)\s*\)/u', function ($m) use ($aparta, $enlace, $marcas) {
            $url = mcm_cuerpo_url(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
            if ($url === '') {
                return $m[1];
            }
            return $aparta($enlace($url, $marcas($m[1])));
        }, $t);

        // 2. Lo que se enlaza solo: direcciones, correos y teléfonos. Quien
        //    escribe «Más info en https://…» o «escríbenos a comunica@…» espera
        //    poder tocarlo; en el móvil, el teléfono también.
        $t = preg_replace_callback(
            '~\b(?:https?://|www\.)[^\s<>\x01\x02]+~iu',
            function ($m) use ($aparta, $enlace) {
                $crudo = $m[0];
                $cola = '';
                // La puntuación del final es de la frase, no del enlace.
                while ($crudo !== '' && strpbrk(substr($crudo, -1), '.,;:!?)»"\'') !== false) {
                    $cola = substr($crudo, -1) . $cola;
                    $crudo = substr($crudo, 0, -1);
                }
                $url = mcm_cuerpo_url(html_entity_decode($crudo, ENT_QUOTES, 'UTF-8'), false);
                if ($url === '') {
                    return $m[0];
                }
                $visible = preg_replace('#^https?://#i', '', $crudo);
                return $aparta($enlace($url, $visible)) . $cola;
            },
            $t
        );
        $t = preg_replace_callback(
            '/(?<![\w.+\-\x01])[\w.+\-]+@[\w\-]+(?:\.[\w\-]+)+(?<!\.)/u',
            function ($m) use ($aparta) {
                return $aparta('<a href="mailto:' . $m[0] . '">' . $m[0] . '</a>');
            },
            $t
        );
        $t = preg_replace_callback(
            '/(?<![\w+\x01])(\+34[ .]?)?([6789]\d{2}(?:[ .]?\d{3}[ .]?\d{3}|[ .]?\d{2}[ .]?\d{2}[ .]?\d{2}))(?![\w])/u',
            function ($m) use ($aparta) {
                return $aparta('<a href="tel:+34' . preg_replace('/\D/', '', $m[2]) . '">' . $m[0] . '</a>');
            },
            $t
        );

        // 3. Negrita y cursiva, con los enlaces apartados.
        $t = $marcas($t);

        // 4. Y los enlaces, de vuelta a su sitio.
        return preg_replace_callback('/\x01(\d+)\x02/', function ($m) use ($apartados) {
            return $apartados[(int) $m[1]] ?? '';
        }, $t);
    }

    /* ── Ayudas para quien pinta la página ─────────────────────────────── */

    /**
     * Si el cuerpo EMPIEZA por una imagen, la saca de ahí y la devuelve.
     *
     * Es lo que hace casi todo el mundo —y lo que traía la plantilla—: pegar
     * el cartel como primera línea del cuerpo. Sin esto el cartel se quedaba
     * enterrado debajo de los datos, la tarjeta del listado salía sin imagen y
     * WhatsApp, sin foto al compartir. La página lo sube a la cabecera.
     *
     * Si `$yaHay` trae el cartel de la cabecera y la primera imagen es LA MISMA,
     * también se quita: si no, el cartel salía dos veces seguidas.
     *
     * @return string La imagen sacada, o '' si no había (o si era otra).
     */
    function mcm_cuerpo_sacar_cartel(&$bloques, $yaHay = '')
    {
        if (empty($bloques) || ($bloques[0]['tipo'] ?? '') !== 'imagen') {
            return '';
        }
        $src = (string) $bloques[0]['src'];
        if ($yaHay !== '' && $yaHay !== $src) {
            return '';
        }
        array_shift($bloques);
        return $src;
    }

    /**
     * El nombre del evento, como lo leería una familia.
     *
     * En el CRM los eventos se llaman con la convención interna
     * «ETAPA | Nombre · SEDE» («COM | Convivencia Inicial 2026 · Buñol · CS»),
     * que sirve para encontrarlos en una lista de cien pero que, en grande en
     * la cabecera de una web, se lee como una ficha técnica. Aquí se separa:
     *
     *   etiqueta → «COM»                               (va encima, pequeño)
     *   titulo   → «Convivencia Inicial 2026 · Buñol»  (el título de verdad)
     *
     * La sede final (2-4 mayúsculas: «CS», «VIL») solo se quita cuando el
     * nombre sigue la convención —lleva la barra—: es un código interno y en
     * la ficha ya están el lugar y las fechas. Un nombre sin barra se deja
     * como está: no se adivina nada.
     */
    function mcm_cuerpo_titulo($nombre)
    {
        $nombre = mcm_cuerpo_una_linea(mcm_cuerpo_normalizar($nombre));
        if (preg_match('/^([^|]{1,24}?)\s*\|\s*(.+)$/u', $nombre, $m)) {
            $titulo = preg_replace('/\s*·\s*[A-ZÁÉÍÓÚÑ]{2,4}$/u', '', trim($m[2]));
            if ($titulo !== '') {
                return array('etiqueta' => trim($m[1]), 'titulo' => $titulo);
            }
        }
        return array('etiqueta' => '', 'titulo' => $nombre);
    }
}
