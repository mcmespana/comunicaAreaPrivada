<?php
/**
 * La carcasa del área: la barra, el menú y el toque (plan 042, auditoría de la
 * carcasa). No hay navegador en los tests, así que lo que se fija aquí son las
 * reglas que ya costaron un fallo de verdad, leídas del CSS y del JS reales.
 * Lo visual se mide con capturas (design.md §9); esto evita que vuelva.
 */

use PHPUnit\Framework\TestCase;

class CarcasaTest extends TestCase
{
    private function css($file)
    {
        return file_get_contents(dirname(__DIR__) . '/css/' . $file);
    }

    private function js($file)
    {
        return file_get_contents(dirname(__DIR__) . '/js/' . $file);
    }

    /** Las declaraciones de la PRIMERA regla cuyo selector es exactamente $sel. */
    private function rule($css, $sel)
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        if (!preg_match('/(^|\})\s*' . preg_quote($sel, '/') . '\s*\{([^}]*)\}/', $css, $m)) {
            $this->fail("No encuentro la regla «{$sel}».");
        }
        return $m[2];
    }

    /**
     * CAR-4. stic-base da `min-width: 140px` a todo <button> a partir de 640 px,
     * sin acotar: «Más» medía 140 px con 32 de texto y a 1024 se salía de la
     * barra. Su regla lo anula, y una clase gana a `button` sin `!important`.
     */
    public function test_el_mas_de_escritorio_no_hereda_los_140_px()
    {
        $this->assertMatchesRegularExpression('/min-width:\s*0\s*;/', $this->rule($this->css('custom-style.css'), '.stic-nav-more'));
    }

    /**
     * CAR-4. Y el reparto cuenta con el ancho de DENTRO de la lista: con
     * `clientWidth` a secas (que incluye el relleno) cabía un elemento de más
     * y «Más» quedaba fuera de la caja, recortado.
     */
    public function test_el_reparto_de_la_barra_descuenta_el_relleno()
    {
        $js = $this->js('stic-ui.js');
        $ini = strpos($js, 'function layoutNav()');
        $this->assertNotFalse($ini);
        $layout = substr($js, $ini, strpos($js, 'function bindNavMore()') - $ini);
        $this->assertStringContainsString('paddingLeft', $layout);
        $this->assertStringContainsString('paddingRight', $layout);
        $this->assertDoesNotMatchRegularExpression('/var available = list\.clientWidth;/', $layout);
    }

    /**
     * CAR-5. En el móvil, «Salir» y la hamburguesa a 44 px y separados: iban
     * de 36×36 y 38×32 a 6 px uno del otro, y un toque desviado cerraba la
     * sesión. Va dentro del breakpoint de la navegación colapsada.
     */
    public function test_salir_y_menu_miden_44_y_no_van_pegados()
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $this->css('custom-style.css'));
        $ini = strpos($css, '.stic-nav-actions { gap: 0.75rem; }');
        $this->assertNotFalse($ini, 'Falta el hueco de 0,75 rem entre «Salir» y «Menú».');
        $media = substr($css, strrpos(substr($css, 0, $ini), '@media'), 400);
        $this->assertStringStartsWith('@media (max-width: 767px)', $media);
        $this->assertMatchesRegularExpression(
            '/\.stic-nav-actions :is\(\.stic-iconbtn, \.stic-nav-toggle\) \{[^}]*min-width: 44px;[^}]*min-height: 44px;/',
            $media
        );
    }

    /** La barra de verdad (menu()) de alguien con sesión. */
    private function barra($conMenu)
    {
        require_once dirname(__DIR__) . '/menu.php';
        $_SESSION = array(
            'scp_user_id' => 'm1',
            'scp_user_contact_name' => 'Soler, David',
            'scp_role' => 'laico',
            'scp_role_resolved' => true,
            'scp_relationship_raw' => '^grupo^',
        );
        // Sin `scp_user_adult` ni sesión de familiar no hay menú (ni hamburguesa).
        if ($conMenu) {
            $_SESSION['scp_user_adult'] = true;
        }
        $_REQUEST = array();
        $_SERVER['REQUEST_URI'] = '/ap/';
        $html = menu();
        $_SESSION = array();
        return $html;
    }

    /**
     * CAR-5, punto 2 (plan 043). En el móvil «Salir» va dentro del menú, el
     * último: el de la barra lleva la clase que lo esconde por debajo de 768
     * y el desplegable lleva su elemento, después de todo lo demás.
     */
    public function test_con_menu_salir_va_el_ultimo_del_desplegable()
    {
        $html = $this->barra(true);
        $this->assertMatchesRegularExpression("/class='stic-iconbtn stic-logout stic-logout--escritorio' href='\?logout=true'/", $html);
        $ultimo = strrpos($html, '<li ');
        $this->assertNotFalse($ultimo);
        $li = substr($html, $ultimo);
        $this->assertStringStartsWith("<li class='stic-nav-item stic-nav-item--wide stic-nav-logout-item' style='--stic-order:9999'>", $li);
        $this->assertStringContainsString("href='?logout=true'", $li);
        $this->assertStringContainsString('Salir', $li);
    }

    /** Sin menú no hay hamburguesa: «Salir» se queda en la barra, también en el móvil. */
    public function test_sin_menu_salir_se_queda_en_la_barra()
    {
        $html = $this->barra(false);
        $this->assertStringContainsString("class='stic-iconbtn stic-logout' href='?logout=true'", $html);
        $this->assertStringNotContainsString('stic-logout--escritorio', $html);
        $this->assertStringNotContainsString('stic-nav-logout-item', $html);
        $this->assertStringNotContainsString('stic-nav-toggle', $html);
    }

    /**
     * CAR-5, punto 2: el CSS que lo hace. En escritorio el elemento del menú
     * no se pinta; en el móvil se pinta y el de la barra se esconde.
     */
    public function test_el_css_lleva_salir_al_menu_solo_en_el_movil()
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $this->css('custom-style.css'));
        $this->assertSame('display: none;', trim($this->rule($css, '.stic-nav-logout-item')));
        $ini = strpos($css, '.stic-nav-actions .stic-logout--escritorio { display: none; }');
        $this->assertNotFalse($ini, 'Falta esconder el «Salir» de la barra en el móvil.');
        $media = substr($css, strrpos(substr($css, 0, $ini), '@media'), 40);
        $this->assertStringStartsWith('@media (max-width: 767px)', $media);
        // Y dentro del bloque del móvil (que lleva un @media anidado, así que
        // se mira cuál de los dos breakpoints abre más cerca).
        $pos = strpos($css, '.stic-nav-logout-item { display: block;');
        $this->assertNotFalse($pos);
        $antes = substr($css, 0, $pos);
        $this->assertGreaterThan((int) strrpos($antes, '@media (min-width'), (int) strrpos($antes, '@media (max-width: 767px)'));
    }

    /** CAR-5, punto 3: el comentario de «de momento todo claro» era falso. */
    public function test_el_menu_no_dice_que_el_modo_oscuro_esta_aparcado()
    {
        $menu = file_get_contents(dirname(__DIR__) . '/menu.php');
        $this->assertStringNotContainsString('De momento todo claro', $menu);
        $this->assertStringNotContainsString('El "Salir" va como último item', $menu);
        // Y desde el plan 043 (CAR-5, punto 2) tampoco va SIEMPRE arriba.
        $this->assertStringNotContainsString('"Salir" SIEMPRE arriba a la derecha', $menu);
    }

    /**
     * CAR-7. El destello gris de WebKit se quita en UN sitio: la propiedad se
     * hereda, así que basta con los contenedores del área. Veinte
     * declaraciones sueltas dejaban fuera justo lo que más se toca.
     */
    public function test_el_destello_se_quita_en_un_solo_sitio()
    {
        $custom = preg_replace('#/\*.*?\*/#s', '', $this->css('custom-style.css'));
        $pl = preg_replace('#/\*.*?\*/#s', '', $this->css('pasar-lista.css'));
        $this->assertSame(1, substr_count($custom, '-webkit-tap-highlight-color'));
        $this->assertSame(0, substr_count($pl, '-webkit-tap-highlight-color'));
        $this->assertMatchesRegularExpression(
            '/:is\(\.stic-container, \.stic-auth-shell, \.stic-modal-overlay\) \{\s*-webkit-tap-highlight-color: transparent;\s*\}/',
            $custom
        );
    }

    /**
     * CAR-7. Y sin destello, lo que no tenía `:active` se quedaba mudo al
     * dedo. Estas piezas lo tienen (medido con :active forzado en Chromium).
     */
    public function test_lo_que_se_toca_responde_al_dedo()
    {
        $css = $this->css('custom-style.css') . $this->css('pasar-lista.css');
        foreach (array(
            'stic-iconbtn', 'stic-nav-toggle', 'stic-rec-back', 'stic-rec-fact-a', 'stic-evweb-doc',
            'stic-auth-tab', 'stic-appearance-btn', 'pl-back', 'pl-group', 'pl-mine-inner',
            'pl-session-pick', 'pl-orphans', 'pl-pending-row', 'pl-bucket', 'pl-all-groups',
            'pl-grouprow-top', 'pl-review-link',
        ) as $clase) {
            $this->assertMatchesRegularExpression('/\.' . preg_quote($clase, '/') . ':active\b/', $css, $clase);
        }
    }

    /**
     * FAM-a2, revisión 042. Con cartel, en móvil «Inscribirme» va dentro de
     * .stic-rec-split-main, que empieza debajo del cartel, y un sticky no sube
     * por encima de su contenedor: no salía en la primera pantalla. Por debajo
     * de 768 las dos cajas se quitan (display: contents) y el contenedor del
     * sticky es la ficha entera.
     */
    public function test_inscribirme_pegado_no_queda_atado_a_la_columna_del_cartel()
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $this->css('custom-style.css'));
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 767px\) \{\s*\.stic-rec-detail--cta-sticky :is\(\.stic-rec-split, \.stic-rec-split-main\) \{ display: contents; \}\s*\}/',
            $css
        );
    }

    /**
     * FAM-a2, revisión 042. La franja de «Inscribirme» pegado era un bloque
     * macizo de --surface-2: en oscuro, una losa gris sobre la página. Lleva
     * el fondo de la botonera de §28, que se funde por arriba.
     */
    public function test_la_franja_de_inscribirme_se_funde_como_la_de_los_formularios()
    {
        $css = $this->css('custom-style.css');
        $fila = $this->rule($css, '.stic-rec-detail--cta-sticky .stic-rec-cta-row');
        $botonera = $this->rule($css, '.stic-form > form > ul.stic-ctabs-list');
        $this->assertMatchesRegularExpression('/background: linear-gradient\([^;]+;/', $botonera);
        preg_match('/background: linear-gradient\([^;]+;/', $botonera, $m);
        $this->assertStringContainsString($m[0], $fila);
        $this->assertStringContainsString('backdrop-filter: blur(4px);', $fila);
    }

    /**
     * A11Y-1 (plan 043, fila 5). El foco de teclado se marca con `--focus-ring`
     * (anillo sólido, ≥3:1), no con el halo al 16 % de `--shadow-glow`, que
     * sobre blanco se quedaba en 1,25:1. El halo sigue en el `:focus` de los
     * campos, que no se toca. Y el token se repite en el tema oscuro: si solo
     * estuviera en :root, leería la superficie y el azul claros.
     */
    public function test_el_foco_de_teclado_usa_el_anillo_y_no_el_halo()
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $this->css('custom-style.css'));
        $anillo = '--focus-ring: 0 0 0 2px var(--surface), 0 0 0 4px var(--primary-color);';
        $this->assertSame(2, substr_count($css, $anillo), 'En §1 y en §44.a, con el mismo valor.');
        $oscuro = strpos($css, '--surface: #1c1d21;');
        $this->assertNotFalse($oscuro);
        $this->assertGreaterThan($oscuro, strrpos($css, $anillo), 'El segundo va en el bloque oscuro.');

        preg_match_all('/([^{}]*):focus-visible([^{}]*)\{([^{}]*)\}/', $css, $reglas, PREG_SET_ORDER);
        $this->assertGreaterThan(15, count($reglas));
        foreach ($reglas as $r) {
            $this->assertStringNotContainsString('var(--shadow-glow)', $r[3], 'Halo en un :focus-visible: ' . trim($r[0]));
        }
        $this->assertStringContainsString('var(--shadow-glow)', $this->rule($css, '.stic-field input:focus'));
    }

    /**
     * A11Y-1. Las reglas de «elegido» llevan su sombra con `!important` y tanta
     * o más especificidad que la del foco, y se comían el anillo: la pestaña
     * «Por correo», el «Auto» de Apariencia y «Volver» / «Eliminar» de los
     * formularios no cambiaban nada al tabular. Cada una tiene su :focus-visible.
     */
    public function test_lo_elegido_no_se_come_el_anillo()
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $this->css('custom-style.css'));
        foreach (array(
            '.stic-auth[data-mode="magic"] .stic-auth-tab[data-auth-toggle="magic"]:focus-visible',
            '.stic-auth[data-mode="password"] .stic-auth-tab[data-auth-toggle="password"]:focus-visible',
            '.stic-appearance-btn[aria-pressed="true"]:focus-visible',
            '.stic-send input.stic-button[type="button"]:focus-visible',
            '.stic-send input.stic-button.stic-danger-button:focus-visible',
        ) as $sel) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($sel, '/') . '\s*(,[^{]*)?\{[^}]*box-shadow: var\(--focus-ring\) !important;/',
                $css,
                $sel
            );
        }
    }

    /**
     * CAR-6 (plan 043, fila 6). La cabecera de las fichas (evento, pago,
     * inscripción) iba en el degradado de marca justo debajo de la barra, que
     * también lo es: dos bloques de marca seguidos que no se cierran (design.md
     * §3). Va en superficie neutra, el título en tinta y los chips con el tono
     * de las tarjetas del listado, sin la versión de cristal.
     */
    public function test_la_cabecera_de_la_ficha_no_lleva_el_degradado()
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $this->css('custom-style.css'));
        $hero = $this->rule($css, '.stic-rec-hero');
        $this->assertStringContainsString('background: var(--surface);', $hero);
        $this->assertStringNotContainsString('grad-brand', $hero);
        $this->assertStringNotContainsString('.stic-rec-hero::after', $css);
        $this->assertStringNotContainsString('.stic-rec-hero-meta .stic-rec-chip', $css, 'Los chips, con su tono normal.');
        $titulo = $this->rule($css, '.stic-container .stic-rec-hero-title,
.stic-rec-hero-title');
        $this->assertStringContainsString('-webkit-text-fill-color: var(--gray-900) !important;', $titulo);
        $this->assertStringNotContainsString('on-brand', $this->rule($css, '.stic-rec-back'));
        $this->assertStringNotContainsString('on-brand', $this->rule($css, '.stic-rec-hero-sub'));
    }

    /**
     * FAM-a13 (plan 043, fila 7). Los iconos de acceso de la portada iban en
     * el degradado, siete u ocho más la barra y el saludo: «degradado en todas
     * partes» (design.md §8). Van en tinta suave, el mismo par que el icono de
     * los datos de una ficha, que en oscuro se recalcula solo (§44.a).
     */
    public function test_los_iconos_de_la_portada_van_en_tinta_suave()
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $this->css('custom-style.css'));
        $icono = $this->rule($css, '.stic-dash-icon');
        $this->assertStringContainsString('background: var(--primary-50);', $icono);
        $this->assertStringContainsString('color: var(--primary-color);', $icono);
        $this->assertDoesNotMatchRegularExpression('/\.stic-dash-icon\s*\{[^}]*grad-brand/', $css);
    }

    /**
     * FAM-a11 (plan 043, fila 8). El selector de hijo de la barra medía 31 px
     * de alto con un área táctil ampliada por un ::before invisible. El
     * propietario quiso que lo que se ve mida 44: el botón lleva su
     * min-height, y el ::before sobra (ampliaría el toque por encima de 44).
     */
    public function test_el_selector_de_hijo_mide_44_a_la_vista()
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $this->css('custom-style.css'));
        $this->assertMatchesRegularExpression('/\.stic-part-switch-btn \{ min-height: 44px; \}/', $css);
        $this->assertStringNotContainsString('.stic-part-switch-btn::before', $css);
    }
}
