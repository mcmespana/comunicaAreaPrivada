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
    /**
     * Los botones de ICONO del área se defienden del tema de WordPress.
     *
     * El tema estila `.entry-content button`, que le gana por especificidad a
     * una clase suelta. Un botón de icono es una caja cuadrada con un radio del
     * 50 %: si el tema le impone su ancho, el círculo se convierte en una
     * ELIPSE enorme. Le pasó a `.stic-hero-close` —una equis de 280 px en la
     * esquina del saludo, en escritorio y siempre— mientras que
     * `.stic-pass-toggle`, que es el mismo patrón, sí estaba defendido desde el
     * principio.
     *
     * La regla: si un botón de icono declara `width` y `border-radius: 50%`,
     * los dos van con `!important`. No es paranoia de estilo, es que sin eso el
     * fallo no se ve en el CSS del plugin —que está bien— sino solo en
     * producción, encima del tema.
     */
    public function test_los_botones_de_icono_fijan_su_tamano_con_important()
    {
        $css = file_get_contents(dirname(__DIR__) . '/css/custom-style.css');

        $botones = array('stic-hero-close', 'stic-pass-toggle');
        foreach ($botones as $clase) {
            // El bloque de la regla principal: desde el selector hasta su `}`.
            $ok = preg_match('/\.' . preg_quote($clase, '/') . '[^{}]*\{([^}]*)\}/', $css, $m);
            $this->assertSame(1, $ok, 'no se encuentra la regla de .' . $clase);
            $bloque = $m[1];

            $this->assertMatchesRegularExpression(
                '/width:\s*[^;]*!important/',
                $bloque,
                '.' . $clase . ' no fija su `width` con !important: el tema de '
                    . 'WordPress se lo puede llevar por delante y, con el radio '
                    . 'del 50 %, el círculo sale como una elipse.'
            );
            $this->assertMatchesRegularExpression(
                '/border-radius:\s*50%\s*!important/',
                $bloque,
                '.' . $clase . ' no fija su `border-radius` con !important.'
            );
        }
    }


    /**
     * EL SANGRADO LATERAL Y LOS DOS RECORTES, que van juntos.
     *
     * `.stic-container` se estira hasta el viewport con `calc(50% - 50vw)` para
     * comerse el relleno que mete el tema de WordPress.
     *
     * EL PRIMER INTENTO SANGRABA EL HIJO (`.stic-tab-content`) y no funcionaba:
     * `overflow-x: clip` recorta a los hijos que se salen, así que
     * `.stic-container` le cortaba el sangrado entero y la página se veía igual
     * que antes. Este test existe para que no se vuelva a mover ahí.
     *
     * Los dos recortes hacen cosas distintas y los dos hacen falta:
     *   - el del `.stic-container` contiene a los bloques de DENTRO que sangran
     *     por su cuenta (plan 035);
     *   - el del `body` contiene al propio contenedor, porque `50vw` incluye la
     *     barra de desplazamiento en los escritorios que la pintan encima.
     */
    public function test_el_sangrado_lateral_va_en_el_contenedor()
    {
        $css = $this->pasarLista();

        $this->assertMatchesRegularExpression(
            '/\.stic-container\s*\{[^}]*margin-inline:\s*calc\(50%\s*-\s*50vw\)/',
            $css,
            'El sangrado va en `.stic-container`, no en un hijo suyo: el '
                . '`overflow-x: clip` del contenedor recorta a los hijos que se salen.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.stic-tab-content\s*\{[^}]*margin-inline:\s*calc/',
            $css,
            'Sangrar `.stic-tab-content` NO funciona: su padre lo recorta. Ya pasó una vez.'
        );
        $this->assertMatchesRegularExpression(
            '/\.stic-container\s*\{[^}]*overflow-x:\s*clip/',
            $css,
            'Hace falta para contener los bloques que sangran por su cuenta (plan 035).'
        );
        $this->assertMatchesRegularExpression(
            '/body:has\(\.stic-container\)\s*\{[^}]*overflow-x:\s*clip/',
            $css,
            'Sin el recorte del body, la barra de scroll del escritorio asoma ~15 px.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.stic-container\s*\{[^}]*overflow-x:\s*hidden/',
            $css,
            '`hidden` crea un contenedor de scroll y rompe el sticky de la barra '
                . 'de guardar. Tiene que ser `clip`.'
        );
    }

    /** El aire lateral se toca en UN sitio, no pantalla por pantalla. */
    public function test_el_aire_lateral_sale_de_un_token()
    {
        $css = $this->pasarLista();

        $this->assertStringContainsString('--pl-gutter:', $css);
        $this->assertMatchesRegularExpression(
            '/\.stic-container\s*\{[^}]*padding-inline:\s*var\(--pl-gutter\)/',
            $css,
            'El relleno lateral tiene que salir de `--pl-gutter`, para poder '
                . 'ajustarlo en las ocho pantallas a la vez.'
        );
    }

    /**
     * LA ESCALA DE ANCHOS DEL PLAN 039 (fila 2).
     *
     * Dos productos que son el mismo producto llegaron a tener quince anchos
     * distintos entre los dos. El plan fijó cinco y solo cinco, y la regla es
     * que un ancho nuevo se justifica por escrito al lado o no entra.
     *
     * Este test existe porque la primera pantalla que se escribió DESPUÉS de
     * cerrar el plan ya metió dos anchos fuera de la escala —un `48rem` y un
     * `26rem` inventado— sin que nada se quejara. Una escala que solo vive en
     * un documento se erosiona en la siguiente sesión.
     *
     * En `rem` tampoco: la escala está en px y los dos no son lo mismo, porque
     * `rem` se mueve si alguien cambia el tamaño de letra del navegador.
     */
    public function test_los_anchos_estan_en_la_escala_del_plan_039()
    {
        $escala = array('340px', '640px', '767px', '768px', '1024px');

        preg_match_all(
            '/@media[^{]*\((?:max|min)-width:\s*([^)]+)\)/',
            $this->pasarLista(),
            $m
        );

        $fuera = array_values(array_unique(array_diff(
            array_map('trim', $m[1]),
            $escala
        )));

        $this->assertSame(
            array(),
            $fuera,
            'Anchos fuera de la escala del plan 039: ' . implode(', ', $fuera)
                . '. La escala es ' . implode(' · ', $escala) . ', en px. '
                . 'Si de verdad hace falta uno nuevo, se añade AQUÍ con el porqué.'
        );
    }

}
