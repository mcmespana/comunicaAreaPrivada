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

    /** CAR-5, punto 3: el comentario de «de momento todo claro» era falso. */
    public function test_el_menu_no_dice_que_el_modo_oscuro_esta_aparcado()
    {
        $menu = file_get_contents(dirname(__DIR__) . '/menu.php');
        $this->assertStringNotContainsString('De momento todo claro', $menu);
        $this->assertStringNotContainsString('El "Salir" va como último item', $menu);
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
}
