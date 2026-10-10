<?php
/**
 * Texto blanco sobre el color de marca: en oscuro, con el fondo FIJO.
 * ----------------------------------------------------------------------------
 * En oscuro, `--primary-color` y `--accent-color` se aclaran (#3f95d6, #9a7ed6)
 * para leerse COMO TEXTO sobre el fondo oscuro (design.md §7). Usados como
 * FONDO de un texto blanco dejaban la pastilla «Inscríbete» de la portada a
 * 3,3:1, el «Mes» elegido del calendario a 3,2:1 y los hover rellenos
 * («Descargar», el paginador, el enlace legal) igual. Plan 042, A11Y-6.
 *
 * La regla: un relleno con texto blanco encima usa `--brand-blue-fixed` o
 * `--accent-fixed`, que §44 no toca. Y de paso, el correo de acceso: su letra
 * pequeña iba en #9ca3af (2,5:1) justo en «Caduca en… Si no lo has pedido tú…».
 */

use PHPUnit\Framework\TestCase;

class ContrasteMarcaTest extends TestCase
{
    /** Tokens que §44 aclara para leerse como texto sobre oscuro. */
    private $aclarados = array(
        'primary-color', 'primary-light', 'primary-dark',
        'secondary-color', 'secondary-light', 'secondary-dark',
        'accent-color',
    );

    private function css($file)
    {
        $s = file_get_contents(dirname(__DIR__) . '/css/' . $file);
        // Los comentarios fuera, conservando los saltos de línea.
        return preg_replace_callback('#/\*.*?\*/#s', function ($m) {
            return preg_replace('/[^\n]/', ' ', $m[0]);
        }, $s);
    }

    private function luminancia($hex)
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $c = array();
        foreach (array(0, 2, 4) as $i) {
            $v = hexdec(substr($hex, $i, 2)) / 255;
            $c[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private function contraste($a, $b)
    {
        $la = $this->luminancia($a);
        $lb = $this->luminancia($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public function test_ningun_texto_blanco_va_sobre_un_token_de_marca_que_se_aclara()
    {
        $malos = array();
        foreach (array('custom-style.css', 'pasar-lista.css') as $file) {
            $css = $this->css($file);
            preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $bloques, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            foreach ($bloques as $b) {
                $sel = trim(preg_replace('/\s+/', ' ', $b[1][0]));
                $decl = $b[2][0];
                // Los bloques del tema oscuro redefinen tokens, no pintan.
                if (strpos($sel, 'dark') !== false) {
                    continue;
                }
                if (!preg_match('/(?<![-\w])color\s*:\s*(#fff\b|#ffffff\b|white\b|var\(--on-brand\)|var\(--pl-on-brand\))/i', $decl)) {
                    continue;
                }
                if (preg_match('/background(?:-color)?\s*:\s*var\(--(' . implode('|', $this->aclarados) . ')\)/', $decl, $m)) {
                    $linea = substr_count(substr($css, 0, $b[0][1]), "\n") + 1;
                    $malos[] = "{$file}:{$linea} `{$sel}` pinta blanco sobre --{$m[1]}";
                }
            }
        }
        $this->assertSame(array(), $malos,
            "En oscuro esos tokens se aclaran y el blanco encima se queda en ~3,2:1. "
            . "Usa --brand-blue-fixed / --accent-fixed como fondo y --on-brand como letra:\n"
            . implode("\n", $malos));
    }

    public function test_los_fondos_fijos_dan_aa_con_blanco_y_el_oscuro_no_los_toca()
    {
        $css = $this->css('custom-style.css');
        foreach (array('brand-blue-fixed', 'accent-fixed') as $token) {
            $this->assertSame(1, preg_match_all('/--' . $token . '\s*:\s*(#[0-9a-fA-F]{3,6})\s*;/', $css, $m),
                "--{$token} se define una vez, en §1, y §44 no lo redefine: es el fondo FIJO.");
            $this->assertGreaterThanOrEqual(4.5, $this->contraste($m[1][0], '#ffffff'),
                "--{$token} ({$m[1][0]}) tiene que dar AA con la letra blanca de encima.");
        }
    }

    public function test_la_letra_gris_del_correo_de_acceso_se_lee_sobre_blanco()
    {
        $html = sticpa_magic_email_html('Marta Villarroya', 'https://example.org/ap/?t=1', 'Comunica', '123456');
        preg_match_all('/(?<![-\w])color:\s*(#[0-9a-fA-F]{6})/', $html, $m);
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $color) {
            if (strtolower($color) === '#ffffff') {
                continue; // va encima del degradado de la cabecera y del botón
            }
            $this->assertGreaterThanOrEqual(4.5, $this->contraste($color, '#ffffff'),
                "El correo de acceso pinta texto en {$color} sobre blanco: por debajo de AA.");
        }
    }
}
