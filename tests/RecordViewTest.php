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
}
