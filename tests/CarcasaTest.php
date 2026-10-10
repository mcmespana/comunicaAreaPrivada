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
}
