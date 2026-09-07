<?php

use PHPUnit\Framework\TestCase;

/**
 * La ficha de registro (inc/stic-record-view.php) es el vocabulario que
 * comparten Eventos, Inscripciones, Pagos, Compromisos, Documentos, Sesiones y
 * Asistencias. Lo que se prueba aquí no es "que pinte HTML": son las REGLAS DE
 * DISEÑO que el componente hace cumplir por quien lo llama, y que se rompen
 * solas en cuanto alguien añade un módulo más:
 *
 *   · Una sola acción principal por pantalla (design.md §6.2).
 *   · Ni una etiqueta sin dato detrás (nada de "Lugar: —").
 *   · Todo escapado salvo lo declarado 'raw' a propósito.
 *
 * Lo que un test NO puede ver —que quepa en 375px, que se lea en oscuro, que
 * se pueda tocar— se verifica capturando: tests/manual/render-record-view.php.
 */
class RecordViewTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../inc/stic-record-view.php';
    }

    /** Dos acciones marcadas como principales: solo la primera se queda con el degradado. */
    public function testSoloUnaAccionPrincipal()
    {
        $html = sticpa_record_detail_html(array(
            'title'   => 'Registro',
            'actions' => array(
                array('label' => 'Pagar', 'url' => '#', 'primary' => true),
                array('label' => 'Anular', 'url' => '#', 'primary' => true),
                array('label' => 'Volver', 'url' => '#', 'primary' => true),
            ),
        ));
        $this->assertSame(1, substr_count($html, 'stic-rec-btn--primary'), 'Solo puede haber UN botón de marca');
        $this->assertSame(2, substr_count($html, 'stic-rec-btn--ghost'));
    }

    /** Lo mismo dentro de una tarjeta del listado. */
    public function testSoloUnaAccionPrincipalEnLaTarjeta()
    {
        $html = sticpa_record_card_html(array(
            'name'    => 'Registro',
            'actions' => array(
                array('label' => 'A', 'url' => '#', 'primary' => true),
                array('label' => 'B', 'url' => '#', 'primary' => true),
            ),
        ));
        $this->assertSame(1, substr_count($html, 'stic-rec-btn--primary'));
    }

    /** Un dato vacío no deja su etiqueta huérfana en pantalla. */
    public function testLosDatosVaciosNoSePintan()
    {
        $html = sticpa_record_detail_html(array(
            'title' => 'Registro',
            'facts' => array(
                array('icon' => 'pin', 'label' => 'Lugar', 'text' => ''),
                array('icon' => 'pin', 'label' => 'Ciudad', 'text' => '   '),
                array('icon' => 'euro', 'label' => 'Precio', 'text' => '10,00 €'),
            ),
        ));
        $this->assertStringNotContainsString('Lugar', $html);
        $this->assertStringNotContainsString('Ciudad', $html);
        $this->assertStringContainsString('Precio', $html);
        // '0' SÍ es un dato: "0 plazas" es información, no un hueco.
        $conCero = sticpa_record_detail_html(array(
            'title' => 'R',
            'facts' => array(array('label' => 'Plazas', 'text' => '0')),
        ));
        $this->assertStringContainsString('Plazas', $conCero);
    }

    /** Una sección de texto vacía no deja un <h4> colgando. */
    public function testLasSeccionesVaciasNoSePintan()
    {
        $html = sticpa_record_detail_html(array(
            'title'    => 'Registro',
            'sections' => array(array('title' => 'Sobre esto', 'body' => '')),
        ));
        $this->assertStringNotContainsString('Sobre esto', $html);
        $this->assertStringNotContainsString('stic-rec-desc', $html);
    }

    /** El contenido del CRM se escapa: lo pinta gente que no controlamos. */
    public function testElContenidoDelCrmSeEscapa()
    {
        $html = sticpa_record_detail_html(array(
            'title'    => '<script>alert(1)</script>',
            'facts'    => array(array('label' => 'X', 'text' => '<img onerror=alert(1)>')),
            'sections' => array(array('title' => 'T', 'body' => '<b>negrita</b>')),
        ));
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img onerror', $html);
        $this->assertStringNotContainsString('<b>negrita</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** 'raw' es la puerta de escape, y solo se abre pidiéndola. */
    public function testLaSeccionRawNoSeEscapa()
    {
        $html = sticpa_record_detail_html(array(
            'title'    => 'R',
            'sections' => array(array('title' => 'T', 'body' => '<a href="#">enlace</a>', 'raw' => true)),
        ));
        $this->assertStringContainsString('<a href="#">enlace</a>', $html);
    }

    /** Un registro sin nombre es una fila basura del CRM: no se pinta. */
    public function testSinTituloNoHayFicha()
    {
        $this->assertSame('', sticpa_record_detail_html(array('title' => '   ')));
        $this->assertSame('', sticpa_record_card_html(array('name' => '')));
        $this->assertSame('', sticpa_record_list_html(array(array('name' => ''))));
    }

    /** Fechas en lenguaje humano: nunca "01/07/2026 – 01/07/2026". */
    public function testLaLineaDeFechas()
    {
        $unDia = strtotime('2026-07-01');
        $this->assertStringNotContainsString(
            ' al ',
            sticpa_record_date_line($unDia, $unDia),
            'Un evento de un día no se lee como un rango'
        );
        // Mismo mes: el mes no se repite ("del 1 al 10 de julio de 2026").
        $mismoMes = sticpa_record_date_line($unDia, strtotime('2026-07-10'));
        $this->assertStringContainsString(' al ', $mismoMes);
        $this->assertSame(1, substr_count($mismoMes, 'July') + substr_count($mismoMes, 'julio'));
        $this->assertSame('', sticpa_record_date_line(null));
    }

    /** El estado es un chip con tono; un tono inventado no ensucia la clase. */
    public function testElChipDeEstado()
    {
        $this->assertStringContainsString('stic-rec-chip--ok', sticpa_record_chip('Confirmada', 'ok'));
        $this->assertStringNotContainsString('stic-rec-chip--', sticpa_record_chip('Sin tono', 'morado'));
        $this->assertSame('', sticpa_record_chip('   ', 'ok'));
    }

    /** Sin fecha, la cápsula cae a un icono y la rejilla no se descuadra. */
    public function testLaCapsulaDeFechaSinFecha()
    {
        $this->assertStringContainsString('stic-rec-badge--empty', sticpa_record_date_badge(null));
        $this->assertStringContainsString('stic-rec-badge-day', sticpa_record_date_badge(strtotime('2026-07-01')));
    }

    /** Una tarjeta sin enlace no finge que se puede abrir. */
    public function testTarjetaSinEnlace()
    {
        $html = sticpa_record_card_html(array('name' => 'Sin ficha'));
        $this->assertStringContainsString('stic-rec-main--static', $html);
        $this->assertStringNotContainsString('<a class=\'stic-rec-main', $html);
    }

    /** Un listado vacío ofrece por dónde seguir, no un callejón sin salida. */
    public function testElEstadoVacioLlevaASitio()
    {
        $html = sticpa_record_empty_html('card', 'Nada aún', 'Ya llegará', array('label' => 'Ir', 'url' => '#'));
        $this->assertStringContainsString('stic-empty-state', $html);
        $this->assertStringContainsString('Ir', $html);
    }

    /** La barra de progreso no se sale de su carril aunque el CRM sume de más. */
    public function testElProgresoSeRecortaAlCienPorCien()
    {
        $html = sticpa_record_detail_html(array(
            'title' => 'R',
            'progress' => array('label' => 'Este año', 'value' => 300, 'max' => 240,
                'value_txt' => '300,00 €', 'max_txt' => '240,00 €'),
        ));
        $this->assertStringContainsString('width:100%', $html);
        $this->assertStringContainsString('100%<', $html);
    }

    /** Sin total no hay barra: una barra sobre un máximo de cero no dice nada. */
    public function testSinMaximoNoHayBarra()
    {
        foreach (array(array('value' => 10, 'max' => 0), array('value' => 10)) as $pr) {
            $html = sticpa_record_detail_html(array('title' => 'R', 'progress' => $pr));
            $this->assertStringNotContainsString('stic-rec-progress', $html);
        }
    }

    /** El importe de la tarjeta se pinta a la derecha, y solo si lo hay. */
    public function testElImporteDeLaTarjeta()
    {
        $con = sticpa_record_card_html(array('name' => 'X', 'amount' => '20,00 €', 'amount_note' => 'Mensual'));
        $this->assertStringContainsString('stic-rec-amount-fig', $con);
        $this->assertStringContainsString('Mensual', $con);

        $sin = sticpa_record_card_html(array('name' => 'X'));
        $this->assertStringNotContainsString('stic-rec-amount', $sin);
    }

    /**
     * El tono del chip sale de la CLAVE interna, nunca de la etiqueta, y ante
     * la duda es neutro: un "cobrado" en verde que en realidad no lo está es
     * peor que un chip gris.
     */
    public function testElTonoSaleDeLaClaveYAnteLaDudaEsNeutro()
    {
        $this->assertSame('danger', sticpa_record_status_tone('returned'));
        $this->assertSame('danger', sticpa_record_status_tone('cancelled'));
        $this->assertSame('ok', sticpa_record_status_tone('settled'));
        $this->assertSame('ok', sticpa_record_status_tone('Confirmed'));
        $this->assertSame('warn', sticpa_record_status_tone('pending'));
        $this->assertSame('', sticpa_record_status_tone('un_estado_que_nadie_ha_visto'));
        $this->assertSame('', sticpa_record_status_tone(''));
    }

    /** Nunca se enseña la clave cruda de un desplegable. */
    public function testLaEtiquetaDeUnEnum()
    {
        $def = array('status' => array('options' => array('settled' => array('value' => 'Cobrado'))));
        $this->assertSame('Cobrado', sticpa_record_enum_label($def, 'status', 'settled'));
        $this->assertSame('', sticpa_record_enum_label($def, 'status', 'inventado'));
        $this->assertSame('', sticpa_record_enum_label(array(), 'status', 'settled'));
    }

    /* ------------------------------------------------------------------
       Comprobaciones MECÁNICAS del CSS de la familia (design.md §9). Son
       las que se olvidan al añadir el módulo número nueve.
       ------------------------------------------------------------------ */

    private function css()
    {
        return file_get_contents(dirname(__DIR__) . '/css/custom-style.css');
    }

    /** Ni un color escrito a mano fuera de los tokens... */
    public function testElCssDeLaFichaSoloUsaTokens()
    {
        // ...salvo el degradado de marca, que design.md §3 EXIGE con hex fijos:
        // en oscuro los tokens de acento se aclaran para que se lea el texto, y
        // la firma tiene que seguir siendo la de la marca.
        $seccion56 = substr($this->css(), strpos($this->css(), '56. FICHA DE REGISTRO'));
        $hexes = array();
        preg_match_all('/#[0-9a-fA-F]{6}/', $seccion56, $hexes);
        $permitidos = array('#1c6fb3', '#6c4b9e', '#9d1e74');
        foreach ($hexes[0] as $hex) {
            $this->assertContains(
                strtolower($hex),
                $permitidos,
                "El hex {$hex} no es del degradado de marca: créalo como token"
            );
        }
    }

    /**
     * Ningún `:hover` suelto. En táctil el navegador lo simula al tocar y se
     * queda PEGADO: el feedback real es `:active`.
     */
    public function testNingunHoverSinSuMediaQuery()
    {
        $css = $this->css();
        $seccion56 = substr($css, strpos($css, '56. FICHA DE REGISTRO'));
        $sueltos = 0;
        // Se cuentan los `:hover` que NO estén dentro de un bloque
        // `@media (hover: hover)`.
        foreach (preg_split('/@media \(hover: hover\) \{/', $seccion56) as $i => $trozo) {
            if ($i > 0) {
                // Dentro del media query: se salta hasta su llave de cierre.
                $trozo = substr($trozo, strpos($trozo, "\n}") !== false ? strpos($trozo, "\n}") : 0);
            }
            $sueltos += substr_count($trozo, ':hover');
        }
        $this->assertSame(0, $sueltos, 'Un :hover fuera de @media (hover: hover) se queda pegado en táctil');
    }

    /** Todo `!important` nuevo lleva escrito al lado por qué hace falta. */
    public function testCadaImportantSeExplica()
    {
        $css = $this->css();
        $seccion56 = substr($css, strpos($css, '56. FICHA DE REGISTRO'));
        foreach (explode("\n", $seccion56) as $linea) {
            if (strpos($linea, '!important') === false) {
                continue;
            }
            // O lo explica su propia línea, o es el `::after { display: none }`
            // que mata la flecha que el tema de WordPress añade a los enlaces.
            $seExplica = strpos($linea, '/*') !== false
                || strpos($linea, '::after') !== false;
            $this->assertTrue($seExplica, "!important sin explicar: {$linea}");
        }
    }

    /**
     * El botón rápido NO puede estar dentro del enlace de la tarjeta. Un <a>
     * dentro de otro <a> es HTML inválido: el navegador cierra el de fuera y
     * el botón se sale de la tarjeta. Ya pasó una vez.
     */
    public function testElBotonRapidoNoAnidaEnlaces()
    {
        $html = sticpa_record_card_html(array(
            'name'  => 'X',
            'url'   => '?internalpage=x',
            'quick' => array('label' => 'Descargar X', 'url' => '#', 'icon' => 'download'),
        ));
        // La posición del botón rápido tiene que ser POSTERIOR al cierre del
        // enlace principal.
        $cierreMain = strpos($html, '</a>');
        $this->assertLessThan(strpos($html, 'stic-rec-quick'), $cierreMain);
        $this->assertStringContainsString('stic-rec-row', $html);
    }

    /** El botón rápido es solo icono: sin nombre accesible no existe. */
    public function testElBotonRapidoTieneNombreAccesible()
    {
        $html = sticpa_record_card_html(array(
            'name'  => 'X',
            'quick' => array('label' => 'Descargar la autorización', 'url' => '#', 'icon' => 'download'),
        ));
        $this->assertStringContainsString("aria-label='Descargar la autorización'", $html);
        // Sin etiqueta no se pinta: mejor sin botón que con un botón mudo.
        $this->assertStringNotContainsString('stic-rec-quick', sticpa_record_card_html(array(
            'name' => 'X', 'quick' => array('url' => '#'),
        )));
    }
}
