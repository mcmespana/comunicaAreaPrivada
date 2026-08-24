<?php
/**
 * Los tokens que usa la hoja de Pasar Lista existen de verdad.
 * ----------------------------------------------------------------------------
 * Este test existe por un bug que se repitió: reglas escritas con
 * `var(--surface-1, #fff)`, `var(--text-muted, #6b7280)` o
 * `var(--border-color, #e5e7eb)`. Ninguno de esos tres tokens existe en
 * custom-style.css §1, así que la regla se quedaba SIEMPRE con el valor de
 * reserva —que es el del tema claro— y en oscuro salía una tarjeta blanca o
 * texto gris claro sobre gris claro. Con el valor de reserva puesto, el fallo
 * es invisible: la página se pinta, solo se pinta mal.
 *
 * De ahí las dos reglas que se comprueban aquí:
 *   1. todo `var(--x)` de la hoja tiene que estar definido en alguna hoja,
 *   2. y no se admiten valores de reserva con un color dentro, que es
 *      justamente lo que esconde el punto 1.
 *
 * Y una tercera, del mismo día: en esta área el esquema resuelto se estampa en
 * `data-stic-scheme` (ver inc/stic-theme.php). Había cuatro reglas escritas con
 * `[data-theme]`, que no aplicaban nunca.
 */

use PHPUnit\Framework\TestCase;

class TokensCssTest extends TestCase
{
    /** Hojas donde puede estar DEFINIDO un token. */
    private function stylesheets()
    {
        $dir = dirname(__DIR__) . '/css';
        return array_filter(array(
            $dir . '/custom-style.css',
            $dir . '/pasar-lista.css',
            $dir . '/stic-base.css',
        ), 'is_file');
    }

    private function pasarLista()
    {
        return file_get_contents(dirname(__DIR__) . '/css/pasar-lista.css');
    }

    public function test_todos_los_tokens_que_usa_pasar_lista_estan_definidos()
    {
        preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', $this->pasarLista(), $m);
        $usados = array_unique($m[1]);

        $definidos = array();
        foreach ($this->stylesheets() as $file) {
            preg_match_all('/(--[a-z0-9-]+)\s*:/i', file_get_contents($file), $d);
            $definidos = array_merge($definidos, $d[1]);
        }
        $definidos = array_unique($definidos);

        $faltan = array_values(array_diff($usados, $definidos));
        sort($faltan);
        $this->assertSame(
            array(),
            $faltan,
            "Tokens usados en css/pasar-lista.css que no existen en ninguna hoja: "
                . implode(', ', $faltan)
        );
    }

    public function test_ningun_var_lleva_un_color_de_reserva()
    {
        // Un valor de reserva con color esconde el token que falta: la regla se
        // queda con el valor claro y el tema oscuro no se entera.
        preg_match_all(
            '/var\(\s*--[a-z0-9-]+\s*,\s*(#[0-9a-f]{3,8}|rgba?\(|hsla?\()/i',
            $this->pasarLista(),
            $m
        );
        $this->assertSame(
            array(),
            $m[0],
            'Hay var(--token, <color>) en css/pasar-lista.css: si el token no existe, '
                . 'la regla se queda en el color claro y en oscuro se ve mal. '
                . 'Define el token y quita la reserva. Encontrado: '
                . implode(' | ', array_unique($m[0]))
        );
    }

    public function test_el_tema_oscuro_se_selecciona_por_data_stic_scheme()
    {
        // `data-theme` no lo estampa nadie en esta área (ver inc/stic-theme.php):
        // una regla escrita así no aplica nunca.
        $css = $this->pasarLista();
        // Se quitan los comentarios: el porqué del bug se explica en uno de ellos.
        $sinComentarios = preg_replace('#/\*.*?\*/#s', '', $css);
        $this->assertStringNotContainsString(
            '[data-theme',
            (string) $sinComentarios,
            'El atributo de esta área es data-stic-scheme, no data-theme.'
        );
    }
}
