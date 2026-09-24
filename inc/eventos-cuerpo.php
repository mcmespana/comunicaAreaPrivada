<?php
/**
 * EL CUERPO DE UN EVENTO — del editor del CRM a lo que se ve.
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
 * formularios y la ficha del evento en el área privada.
 *
 * No depende de nada: ni de WordPress ni de `crm_proxy.php`. Solo PHP con la
 * extensión DOM (sin ella, el texto sale en párrafos, entero y sin formato).
 *
 * ── Lo que entra: el HTML del editor del CRM ───────────────────────────────
 *
 * El cuerpo es un campo WYSIWYG de Sinergia (TinyMCE): quien escribe ve los
 * títulos en grande, las listas y las negritas mientras escribe. Eso produce
 * HTML, y el HTML de un editor trae de todo (estilos pegados de Word, fuentes,
 * colores, tablas). **No se pinta tal cual.** Se lee con una LISTA BLANCA y se
 * traduce a una docena de piezas con el diseño de la casa:
 *
 *   Título 1 (h1)                       → título de sección
 *   Título 2 a 6 (h2…h6)                → subtítulo dentro de la sección
 *   Párrafo, salto de línea             → párrafo (el salto se respeta)
 *   Negrita, cursiva                    → negrita, cursiva
 *   Enlace dentro de una frase          → enlace
 *   UN ENLACE SOLO en su párrafo        → BOTÓN (un enlace de YouTube o
 *                                         Vimeo solo, → el VÍDEO incrustado)
 *   Lista con puntos / numerada         → lista
 *   Cita (blockquote)                   → AVISO destacado (el plazo, lo urgente)
 *   Línea horizontal (hr)               → separador
 *   Imagen                              → imagen; la PRIMERA del texto, si va
 *                                         al principio, es el CARTEL
 *   Varias imágenes seguidas            → galería
 *   Tabla                               → una línea por fila («Sábado · 10:00»)
 *
 * Todo lo demás —colores, tamaños, fuentes, alineaciones, clases, `style`,
 * `onclick`, `<script>`, `<iframe>` que no sea un vídeo conocido— se tira. El
 * texto que había dentro se conserva. Así un evento no puede salir con la letra
 * de Word, ni romper la página, ni meter JavaScript.
 *
 * (Hasta el 24/09/2026 el cuerpo era Markdown en un TextArea; se retiró a
 * propósito, sin soporte de compatibilidad: los eventos viejos se migraron.)
 *
 * ── Tres pasos, separados a propósito ──────────────────────────────────────
 *
 *   1. mcm_cuerpo_bloques($html)            el HTML → una lista de BLOQUES
 *   2. (quien llama puede quitar o mover bloques: el cartel, por ejemplo)
 *   3. mcm_cuerpo_html($bloques, $opciones)  los bloques → HTML de la web
 *
 * Los bloques son el contrato. El día que el mismo contenido tenga que salir
 * en un CORREO, se escribe un segundo pintor sobre los mismos bloques.
 *
 * ── La regla de seguridad, que no se toca ──────────────────────────────────
 *
 * Del HTML de entrada NO sale ni una etiqueta hacia la página: se extrae el
 * TEXTO (que después se escapa) y la estructura de la lista blanca. Las
 * etiquetas de salida las escribe este código. Todo enlace e imagen pasa por
 * `mcm_cuerpo_url()`: ni `javascript:` ni `data:`.
 */

if (!function_exists('mcm_cuerpo_bloques')) {

    /**
     * El HTML del CRM → lista de bloques.
     *
     *   titulo     nivel (2|3), contenido
     *   parrafo    contenido
     *   lista      ordenada (bool), inicio (int), items [contenido…]
     *   aviso      contenido
     *   boton      texto, url
     *   imagen     src, pie
     *   galeria    imagenes [src…]
     *   video      proveedor (youtube|vimeo), id, url
     *   separador
     *
     * El `contenido` es la línea troceada en piezas (ver mcm_cuerpo_piezas()):
     * texto, negrita, cursiva, enlace y salto. Nunca HTML.
     */
    function mcm_cuerpo_bloques($texto)
    {
        $texto = mcm_cuerpo_entrada($texto);
        if ($texto === '') {
            return array();
        }
        // Sin una sola etiqueta es texto a secas (un evento escrito antes de
        // tener el editor, o pegado sin formato): párrafos por líneas en blanco
        // y un salto por cada Intro.
        if (!preg_match('/<[a-z][^>]*>/i', $texto)) {
            $texto = str_replace("\xC2\xA0", ' ', $texto);
            $html = '';
            foreach (preg_split("/\n\s*\n/", $texto) as $trozo) {
                $html .= '<p>' . nl2br(htmlspecialchars(trim($trozo), ENT_QUOTES, 'UTF-8'), false) . '</p>';
            }
            $texto = $html;
        }
        $body = mcm_cuerpo_dom($texto);
        if ($body === null) {
            // Sin DOM: el texto, sin etiquetas, en párrafos.
            $plano = trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/h\d|/li|/div)[^>]*>#i', "\n", $texto)), ENT_QUOTES, 'UTF-8'));
            $bloques = array();
            foreach (preg_split("/\n\s*\n|\n/", $plano) as $l) {
                if (trim($l) !== '') {
                    $bloques[] = array('tipo' => 'parrafo', 'contenido' => array(array('texto', trim($l))));
                }
            }
            return $bloques;
        }
        return mcm_cuerpo_agrupa_imagenes(mcm_cuerpo_recorre($body));
    }

    /**
     * Un texto CORTO del CRM (nombre, lugar, lema) como lo escribió la persona.
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
        // Word o de WhatsApp no se ven, pero se cuelan en las comparaciones.
        $t = str_replace(array("\xEF\xBB\xBF", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D"), '', $t);
        // El espacio duro de `&nbsp;` es un espacio para todo lo de aquí.
        $t = str_replace("\xC2\xA0", ' ', $t);
        $t = str_replace("\t", '    ', $t);
        return trim(preg_replace('/\R/u', "\n", $t));
    }

    /**
     * El cuerpo tal como llega del CRM → el HTML que escribió el editor.
     *
     * ⚠️ SuiteCRM guarda el campo HTML CODIFICADO: `<p>Hola &amp; adiós</p>`
     * llega como `&lt;p&gt;Hola &amp;amp; adiós&lt;/p&gt;`. Se deshace UNA vez,
     * y solo si viene codificado. Dos veces sería convertir un «<» que alguien
     * escribió como texto en una etiqueta de verdad. (No es el mismo trato que
     * los textos cortos, `mcm_cuerpo_normalizar()`, que no llevan etiquetas.)
     */
    function mcm_cuerpo_entrada($texto)
    {
        $t = (string) $texto;
        if (preg_match('#&lt;/?[a-z][a-z0-9]*[\s&/]|&lt;/?[a-z][a-z0-9]*&gt;#i', $t)) {
            $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $t = str_replace(array("\xEF\xBB\xBF", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D"), '', $t);
        $t = preg_replace('/\R/u', "\n", $t);
        if (!preg_match('/<[a-z][^>]*>/i', $t)) {
            // Texto sin etiquetas: sus entidades (&quot;) son texto codificado.
            $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return trim($t);
    }

    /** El HTML dentro de un DOM, y su <body>. Null si no hay extensión DOM. */
    function mcm_cuerpo_dom($html)
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
        return $dom->getElementsByTagName('body')->item(0);
    }

    /** Lo que se tira CON todo su contenido: no es texto de nadie. */
    function mcm_cuerpo_se_tira($tag)
    {
        return in_array($tag, array('script', 'style', 'noscript', 'template', 'head', 'title', 'object',
            'embed', 'form', 'button', 'select', 'textarea', 'input', 'svg', 'math', 'audio', 'canvas'), true);
    }

    /** Las etiquetas que abren un bloque (lo demás es texto en línea). */
    function mcm_cuerpo_es_bloque($tag)
    {
        return in_array($tag, array('p', 'div', 'section', 'article', 'header', 'footer', 'main', 'aside',
            'center', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'hr', 'img',
            'figure', 'iframe', 'table', 'pre', 'address', 'dl', 'dt', 'dd'), true);
    }

    /**
     * Recorre un nodo de bloque y devuelve sus bloques.
     *
     * El texto suelto (o en línea) que va apareciendo se junta en un párrafo
     * hasta el siguiente bloque: es lo que pasa cuando el editor no envuelve en
     * <p> la primera línea, o cuando se pega texto a mano en el código.
     */
    function mcm_cuerpo_recorre($nodo)
    {
        $bloques = array();
        $suelto = array();   // nodos en línea pendientes de ser un párrafo

        $suelta = function () use (&$suelto, &$bloques) {
            if ($suelto) {
                foreach (mcm_cuerpo_parrafo($suelto) as $b) {
                    $bloques[] = $b;
                }
                $suelto = array();
            }
        };

        foreach ($nodo->childNodes as $hijo) {
            if ($hijo->nodeType === XML_TEXT_NODE) {
                $suelto[] = $hijo;
                continue;
            }
            if ($hijo->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $tag = strtolower($hijo->nodeName);
            if (mcm_cuerpo_se_tira($tag)) {
                continue;
            }
            if ($tag === 'br') {
                // Un <br> suelto entre bloques no es nada; dentro de texto
                // suelto, un salto.
                if ($suelto) {
                    $suelto[] = $hijo;
                }
                continue;
            }
            if (!mcm_cuerpo_es_bloque($tag)) {
                $suelto[] = $hijo;
                continue;
            }

            $suelta();
            switch ($tag) {
                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                case 'h5':
                case 'h6':
                    $contenido = mcm_cuerpo_sin_saltos(mcm_cuerpo_piezas($hijo));
                    if (mcm_cuerpo_tiene_texto($contenido)) {
                        $bloques[] = array('tipo' => 'titulo', 'nivel' => $tag === 'h1' ? 2 : 3,
                            'contenido' => $contenido);
                    }
                    break;
                case 'ul':
                case 'ol':
                    $lista = mcm_cuerpo_lista($hijo, $tag === 'ol');
                    if ($lista !== null) {
                        $bloques[] = $lista;
                    }
                    break;
                case 'blockquote':
                    // La «Cita» del editor es el recuadro de AVISO: es el botón
                    // que está a mano en la barra, y en un evento lo que hay que
                    // destacar es el plazo, no una cita.
                    $partes = array();
                    foreach (mcm_cuerpo_recorre($hijo) as $b) {
                        if (isset($b['contenido'])) {
                            if ($partes) {
                                $partes[] = array('salto');
                            }
                            $partes = array_merge($partes, $b['contenido']);
                        } elseif (isset($b['items'])) {
                            foreach ($b['items'] as $item) {
                                if ($partes) {
                                    $partes[] = array('salto');
                                }
                                $partes = array_merge($partes, $item);
                            }
                        }
                    }
                    if (mcm_cuerpo_tiene_texto($partes)) {
                        $bloques[] = array('tipo' => 'aviso', 'contenido' => $partes);
                    }
                    break;
                case 'hr':
                    $bloques[] = array('tipo' => 'separador');
                    break;
                case 'img':
                    $img = mcm_cuerpo_imagen($hijo, '');
                    if ($img !== null) {
                        $bloques[] = $img;
                    }
                    break;
                case 'figure':
                    $pie = '';
                    foreach ($hijo->getElementsByTagName('figcaption') as $fc) {
                        $pie = mcm_cuerpo_una_linea($fc->textContent);
                    }
                    foreach ($hijo->getElementsByTagName('img') as $i) {
                        $img = mcm_cuerpo_imagen($i, $pie);
                        if ($img !== null) {
                            $bloques[] = $img;
                        }
                    }
                    break;
                case 'iframe':
                    $video = mcm_cuerpo_video($hijo->getAttribute('src'));
                    if ($video !== null) {
                        $bloques[] = $video;
                    }
                    break;
                case 'table':
                    // Una tabla no cabe en un móvil de 375px ni en un correo: se
                    // lee fila a fila, con las celdas separadas por un punto.
                    foreach ($hijo->getElementsByTagName('tr') as $tr) {
                        $fila = array();
                        foreach ($tr->childNodes as $td) {
                            if ($td->nodeType === XML_ELEMENT_NODE && in_array(strtolower($td->nodeName), array('td', 'th'), true)) {
                                $celda = mcm_cuerpo_sin_saltos(mcm_cuerpo_piezas($td));
                                if (mcm_cuerpo_tiene_texto($celda)) {
                                    if ($fila) {
                                        $fila[] = array('texto', ' · ');
                                    }
                                    $fila = array_merge($fila, $celda);
                                }
                            }
                        }
                        if ($fila) {
                            $bloques[] = array('tipo' => 'parrafo', 'contenido' => $fila);
                        }
                    }
                    break;
                default:
                    // p, div, li suelto, pre…: un bloque que lleva más bloques
                    // dentro se recorre; uno que solo lleva texto es un párrafo.
                    if (mcm_cuerpo_tiene_bloques($hijo)) {
                        foreach (mcm_cuerpo_recorre($hijo) as $b) {
                            $bloques[] = $b;
                        }
                    } else {
                        foreach (mcm_cuerpo_parrafo(iterator_to_array($hijo->childNodes)) as $b) {
                            $bloques[] = $b;
                        }
                    }
            }
        }
        $suelta();
        return $bloques;
    }

    /** ¿Lleva dentro algo que sea un bloque (y no solo texto)? */
    function mcm_cuerpo_tiene_bloques($nodo)
    {
        foreach ($nodo->childNodes as $hijo) {
            if ($hijo->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($hijo->nodeName);
                if ($tag !== 'img' && mcm_cuerpo_es_bloque($tag)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Unos nodos en línea → el bloque (o bloques) que les toca.
     *
     *   · solo imágenes                → una imagen por cada una
     *   · solo UN enlace               → un BOTÓN (o el vídeo, si es de YouTube)
     *   · imágenes y texto mezclados   → las imágenes aparte y el texto párrafo
     *   · lo demás                     → párrafo
     */
    function mcm_cuerpo_parrafo($nodos)
    {
        $imagenes = array();
        $resto = array();
        foreach ($nodos as $n) {
            if ($n->nodeType === XML_ELEMENT_NODE && strtolower($n->nodeName) === 'img') {
                $img = mcm_cuerpo_imagen($n, '');
                if ($img !== null) {
                    $imagenes[] = $img;
                }
                continue;
            }
            // Una imagen metida dentro de un enlace o de una negrita también es
            // una imagen: se saca a su sitio.
            if ($n->nodeType === XML_ELEMENT_NODE) {
                foreach ($n->getElementsByTagName('img') as $i) {
                    $img = mcm_cuerpo_imagen($i, '');
                    if ($img !== null) {
                        $imagenes[] = $img;
                    }
                }
            }
            $resto[] = $n;
        }

        $contenido = array();
        foreach ($resto as $n) {
            $contenido = array_merge($contenido, mcm_cuerpo_pieza($n));
        }
        $contenido = mcm_cuerpo_recorta($contenido);

        $bloques = $imagenes;
        if (!mcm_cuerpo_tiene_texto($contenido)) {
            return $bloques;
        }

        // UN ENLACE SOLO EN SU PÁRRAFO ES UN BOTÓN. Es la forma de hacer un
        // botón con el editor sin aprenderse nada: se escribe el texto, se le
        // pone el enlace y se deja en su propia línea.
        if (count($contenido) === 1 && $contenido[0][0] === 'enlace') {
            $url = $contenido[0][1];
            $video = mcm_cuerpo_video($url);
            if ($video !== null) {
                $bloques[] = $video;
                return $bloques;
            }
            $texto = mcm_cuerpo_una_linea(mcm_cuerpo_texto_de($contenido[0][2]));
            // Si el texto del enlace es la propia dirección, no es un botón
            // escrito a propósito: es una URL pegada, y va de párrafo.
            if ($texto !== '' && !preg_match('#^(https?://|www\.)#i', $texto)) {
                $bloques[] = array('tipo' => 'boton', 'texto' => $texto, 'url' => $url);
                return $bloques;
            }
        }

        $bloques[] = array('tipo' => 'parrafo', 'contenido' => $contenido);
        return $bloques;
    }

    /** Las piezas de TODO lo que hay dentro de un nodo. */
    function mcm_cuerpo_piezas($nodo)
    {
        $piezas = array();
        foreach ($nodo->childNodes as $hijo) {
            $piezas = array_merge($piezas, mcm_cuerpo_pieza($hijo));
        }
        return mcm_cuerpo_recorta($piezas);
    }

    /**
     * UN nodo en línea → sus piezas.
     *
     *   array('texto', 'Hola ')
     *   array('fuerte', [piezas])     array('cursiva', [piezas])
     *   array('enlace', url, [piezas])
     *   array('salto')
     *
     * Lo que no está en la lista (span, font, u, sup…) deja pasar su texto y
     * nada más.
     */
    function mcm_cuerpo_pieza($n)
    {
        if ($n->nodeType === XML_TEXT_NODE) {
            // El `&nbsp;` que el editor siembra por todas partes es un espacio.
            $t = preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $n->nodeValue));
            return $t === '' ? array() : array(array('texto', $t));
        }
        if ($n->nodeType !== XML_ELEMENT_NODE) {
            return array();
        }
        $tag = strtolower($n->nodeName);
        if (mcm_cuerpo_se_tira($tag) || $tag === 'img' || $tag === 'iframe') {
            return array();
        }
        if ($tag === 'br') {
            return array(array('salto'));
        }
        $dentro = array();
        foreach ($n->childNodes as $h) {
            $dentro = array_merge($dentro, mcm_cuerpo_pieza($h));
        }
        switch ($tag) {
            case 'strong':
            case 'b':
                return mcm_cuerpo_tiene_texto($dentro) ? mcm_cuerpo_envuelve('fuerte', $dentro) : $dentro;
            case 'em':
            case 'i':
                return mcm_cuerpo_tiene_texto($dentro) ? mcm_cuerpo_envuelve('cursiva', $dentro) : $dentro;
            case 'a':
                $url = mcm_cuerpo_url(trim($n->getAttribute('href')));
                if ($url === '' || !mcm_cuerpo_tiene_texto($dentro)) {
                    return $dentro;   // un enlace que no vale se queda en su texto
                }
                return array(array('enlace', $url, mcm_cuerpo_recorta($dentro)));
        }
        return $dentro;
    }

    /**
     * Negrita alrededor, pero con los espacios FUERA. El editor deja a menudo
     * «<strong>Nos vamos: </strong>viernes»; con el espacio dentro, el pintor de
     * correo de mañana lo perdería.
     */
    function mcm_cuerpo_envuelve($tipo, $piezas)
    {
        $antes = array();
        $despues = array();
        if ($piezas && $piezas[0][0] === 'texto' && preg_match('/^\s+/u', $piezas[0][1])) {
            $antes[] = array('texto', ' ');
        }
        $ultima = count($piezas) - 1;
        if ($ultima >= 0 && $piezas[$ultima][0] === 'texto' && preg_match('/\s+$/u', $piezas[$ultima][1])) {
            $despues[] = array('texto', ' ');
        }
        return array_merge($antes, array(array($tipo, mcm_cuerpo_recorta($piezas))), $despues);
    }

    /** Sin espacios ni saltos sobrantes al principio y al final. */
    function mcm_cuerpo_recorta($piezas)
    {
        while ($piezas && ($piezas[0][0] === 'salto' || ($piezas[0][0] === 'texto' && trim($piezas[0][1]) === ''))) {
            array_shift($piezas);
        }
        while ($piezas) {
            $u = count($piezas) - 1;
            if ($piezas[$u][0] === 'salto' || ($piezas[$u][0] === 'texto' && trim($piezas[$u][1]) === '')) {
                array_pop($piezas);
                continue;
            }
            break;
        }
        if ($piezas && $piezas[0][0] === 'texto') {
            $piezas[0][1] = ltrim($piezas[0][1]);
        }
        $u = count($piezas) - 1;
        if ($u >= 0 && $piezas[$u][0] === 'texto') {
            $piezas[$u][1] = rtrim($piezas[$u][1]);
        }
        // Nunca dos saltos seguidos: el editor mete <br><br> para «separar» y
        // eso ya lo hace el espacio entre párrafos.
        // Y sin el espacio que queda pegado a un salto por cada lado.
        $limpio = array();
        foreach ($piezas as $p) {
            $prev = end($limpio);
            if ($p[0] === 'salto' && $prev && $prev[0] === 'salto') {
                continue;
            }
            if ($p[0] === 'salto' && $prev && $prev[0] === 'texto') {
                $limpio[count($limpio) - 1][1] = rtrim($prev[1]);
            }
            if ($p[0] === 'texto' && $prev && $prev[0] === 'salto') {
                $p[1] = ltrim($p[1]);
            }
            $limpio[] = $p;
        }
        return $limpio;
    }

    /** Un título no lleva saltos: se cambian por un espacio. */
    function mcm_cuerpo_sin_saltos($piezas)
    {
        $salida = array();
        foreach ($piezas as $p) {
            if ($p[0] === 'salto') {
                $salida[] = array('texto', ' ');
            } elseif ($p[0] === 'enlace') {
                $salida[] = array('enlace', $p[1], mcm_cuerpo_sin_saltos($p[2]));
            } elseif ($p[0] === 'fuerte' || $p[0] === 'cursiva') {
                $salida[] = array($p[0], mcm_cuerpo_sin_saltos($p[1]));
            } else {
                $salida[] = $p;
            }
        }
        return $salida;
    }

    /** ¿Hay texto de verdad, o solo espacios y saltos? */
    function mcm_cuerpo_tiene_texto($piezas)
    {
        return trim(mcm_cuerpo_texto_de($piezas)) !== '';
    }

    /** El texto plano de unas piezas (para un título de botón, un resumen…). */
    function mcm_cuerpo_texto_de($piezas)
    {
        $t = '';
        foreach ((array) $piezas as $p) {
            switch ($p[0]) {
                case 'texto':
                    $t .= $p[1];
                    break;
                case 'salto':
                    $t .= "\n";
                    break;
                case 'enlace':
                    $t .= mcm_cuerpo_texto_de($p[2]);
                    break;
                default:
                    $t .= mcm_cuerpo_texto_de($p[1]);
            }
        }
        return $t;
    }

    /** Una lista del editor. Las anidadas se aplanan: en móvil no se leen mejor. */
    function mcm_cuerpo_lista($lista, $ordenada)
    {
        $items = array();
        $inicio = max(1, (int) $lista->getAttribute('start'));
        foreach ($lista->childNodes as $li) {
            if ($li->nodeType !== XML_ELEMENT_NODE || strtolower($li->nodeName) !== 'li') {
                continue;
            }
            $propio = array();
            $anidadas = array();
            foreach ($li->childNodes as $parte) {
                $tag = $parte->nodeType === XML_ELEMENT_NODE ? strtolower($parte->nodeName) : '';
                if ($tag === 'ul' || $tag === 'ol') {
                    $anidadas[] = $parte;
                } elseif ($tag === 'p' || $tag === 'div') {
                    if ($propio) {
                        $propio[] = array('salto');
                    }
                    $propio = array_merge($propio, mcm_cuerpo_piezas($parte));
                } else {
                    $propio = array_merge($propio, mcm_cuerpo_pieza($parte));
                }
            }
            $propio = mcm_cuerpo_recorta($propio);
            if (mcm_cuerpo_tiene_texto($propio)) {
                $items[] = $propio;
            }
            foreach ($anidadas as $sub) {
                $b = mcm_cuerpo_lista($sub, $ordenada);
                if ($b !== null) {
                    $items = array_merge($items, $b['items']);
                }
            }
        }
        if (!$items) {
            return null;
        }
        return array('tipo' => 'lista', 'ordenada' => $ordenada, 'inicio' => $inicio, 'items' => $items);
    }

    /** Un <img> → bloque imagen, o null si su dirección no vale. */
    function mcm_cuerpo_imagen($img, $pie)
    {
        $src = mcm_cuerpo_url(trim($img->getAttribute('src')), false);
        if ($src === '') {
            return null;
        }
        // El `alt` NO es un pie de foto: el editor lo rellena a veces con el
        // nombre del fichero. Pie solo si viene de un <figcaption>.
        return array('tipo' => 'imagen', 'src' => $src, 'pie' => (string) $pie,
            'alt' => mcm_cuerpo_una_linea($img->getAttribute('alt')));
    }

    /**
     * Varias imágenes seguidas → una galería.
     *
     * Salvo la PRIMERA del texto si va al principio: esa es el cartel, y quien
     * pinta la página la sube a la cabecera (mcm_cuerpo_sacar_cartel()).
     */
    function mcm_cuerpo_agrupa_imagenes($bloques)
    {
        $salida = array();
        $racha = array();
        $cierra = function () use (&$racha, &$salida) {
            if (count($racha) >= 2) {
                $salida[] = array('tipo' => 'galeria', 'imagenes' => array_map(function ($b) {
                    return $b['src'];
                }, $racha));
            } else {
                foreach ($racha as $b) {
                    $salida[] = $b;
                }
            }
            $racha = array();
        };
        foreach ($bloques as $i => $b) {
            if ($b['tipo'] === 'imagen' && $b['pie'] === '' && !($i === 0 && empty($salida))) {
                $racha[] = $b;
                continue;
            }
            $cierra();
            $salida[] = $b;
        }
        $cierra();
        return $salida;
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

    /**
     * EL CAMPO del cuerpo en `stic_Events`. Un solo sitio para los dos repos.
     *
     * Es un campo WYSIWYG (editor TinyMCE de Sinergia) creado para sustituir a
     * `web_cuerpo_c`, que era un TextArea en Markdown: Studio no deja cambiar
     * el tipo de un campo ya creado. Ver CAMPOS.md §1 → Eventos.
     *
     * ⚠️ Pedir a la API un campo que no existe se lleva por delante la consulta
     * entera (400). Si se renombra, se cambia AQUÍ y en ningún otro sitio.
     */
    function mcm_cuerpo_campo()
    {
        return 'web_cuerpo_html_c';
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
     *   titulo_base   2 → un título de sección es un <h2>. En una pantalla
     *                 cuyo título ya es un <h3>, se sube para no romper el orden
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
        $linea = function ($piezas) use ($opciones) {
            return mcm_cuerpo_linea($piezas, $opciones);
        };

        $html = '';
        foreach ((array) $bloques as $b) {
            switch ($b['tipo'] ?? '') {
                case 'titulo':
                    $n = min(6, $base + (((int) ($b['nivel'] ?? 2)) >= 3 ? 1 : 0));
                    $html .= "<h{$n}>" . $linea($b['contenido']) . "</h{$n}>\n";
                    break;
                case 'parrafo':
                    $html .= '<p>' . $linea($b['contenido']) . "</p>\n";
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
                case 'aviso':
                    $html .= '<p class="' . $e($cl['aviso']) . '">' . $linea($b['contenido']) . "</p>\n";
                    break;
                case 'boton':
                    list($url, $fuera) = mcm_cuerpo_destino($reescribe($b['url']), $propios);
                    $html .= '<p class="' . $e($cl['boton_linea']) . '"><a class="' . $e($cl['boton']) . '" href="'
                        . $e($url) . '"' . ($fuera ? ' target="_blank" rel="noopener noreferrer"' : '') . '>'
                        . $e($b['texto']) . "</a></p>\n";
                    break;
                case 'imagen':
                    $pie = trim((string) ($b['pie'] ?? ''));
                    $alt = $pie !== '' ? $pie : trim((string) ($b['alt'] ?? ''));
                    $html .= '<figure class="' . $e($cl['imagen']) . '"><img src="' . $e($ligera($b['src'], 1200))
                        . '" alt="' . $e($alt) . '" loading="lazy">';
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
     * Unas piezas → HTML de línea.
     *
     * El texto se escapa SIEMPRE, y dentro del texto suelto (no del que ya es
     * un enlace) se enlazan solas las direcciones, los correos y los teléfonos:
     * quien escribe «escríbenos a comunica@…» o «al 649 949 583» espera poder
     * tocarlo en el móvil.
     */
    function mcm_cuerpo_linea($piezas, $opciones = array())
    {
        $opciones = (array) $opciones;
        $propios = $opciones['propios'] ?? null;
        $reescribe = mcm_cuerpo_reescritor($opciones);
        $html = '';
        foreach ((array) $piezas as $p) {
            switch ($p[0]) {
                case 'texto':
                    $html .= mcm_cuerpo_autoenlaza($p[1], $opciones);
                    break;
                case 'salto':
                    $html .= "<br>\n";
                    break;
                case 'fuerte':
                    $html .= '<strong>' . mcm_cuerpo_linea($p[1], $opciones) . '</strong>';
                    break;
                case 'cursiva':
                    $html .= '<em>' . mcm_cuerpo_linea($p[1], $opciones) . '</em>';
                    break;
                case 'enlace':
                    list($destino, $fuera) = mcm_cuerpo_destino($reescribe($p[1]), $propios);
                    // Dentro de un enlace NO se autoenlaza: sería un enlace
                    // dentro de otro.
                    $dentro = mcm_cuerpo_linea_sin_enlaces($p[2]);
                    $html .= '<a href="' . htmlspecialchars($destino, ENT_QUOTES, 'UTF-8') . '"'
                        . ($fuera ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $dentro . '</a>';
                    break;
            }
        }
        return $html;
    }

    /** Lo de dentro de un enlace: negrita y cursiva sí, enlaces no. */
    function mcm_cuerpo_linea_sin_enlaces($piezas)
    {
        $html = '';
        foreach ((array) $piezas as $p) {
            switch ($p[0]) {
                case 'texto':
                    $html .= htmlspecialchars($p[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    break;
                case 'salto':
                    $html .= '<br>';
                    break;
                case 'fuerte':
                    $html .= '<strong>' . mcm_cuerpo_linea_sin_enlaces($p[1]) . '</strong>';
                    break;
                case 'cursiva':
                    $html .= '<em>' . mcm_cuerpo_linea_sin_enlaces($p[1]) . '</em>';
                    break;
                case 'enlace':
                    $html .= mcm_cuerpo_linea_sin_enlaces($p[2]);
                    break;
            }
        }
        return $html;
    }

    /** Un trozo de texto, escapado y con sus direcciones, correos y teléfonos enlazados. */
    function mcm_cuerpo_autoenlaza($texto, $opciones = array())
    {
        $propios = $opciones['propios'] ?? null;
        $reescribe = mcm_cuerpo_reescritor($opciones);
        $t = htmlspecialchars((string) $texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $apartados = array();
        $aparta = function ($html) use (&$apartados) {
            $apartados[] = $html;
            return "\x01" . (count($apartados) - 1) . "\x02";
        };

        $t = preg_replace_callback(
            '~\b(?:https?://|www\.)[^\s<>\x01\x02]+~iu',
            function ($m) use ($aparta, $propios, $reescribe) {
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
                list($destino, $fuera) = mcm_cuerpo_destino($reescribe($url), $propios);
                return $aparta('<a href="' . htmlspecialchars($destino, ENT_QUOTES, 'UTF-8') . '"'
                    . ($fuera ? ' target="_blank" rel="noopener noreferrer"' : '') . '>'
                    . preg_replace('#^https?://#i', '', $crudo) . '</a>') . $cola;
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
