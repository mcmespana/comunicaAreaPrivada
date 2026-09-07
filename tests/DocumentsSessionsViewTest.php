<?php

use PHPUnit\Framework\TestCase;

/**
 * DOCUMENTOS, SESIONES Y ASISTENCIAS
 * (inc/stic-documents.php, inc/stic-sessions.php).
 */
class DocumentsSessionsViewTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../inc/stic-record-view.php';
        require_once __DIR__ . '/../inc/stic-formatter.php';
        require_once __DIR__ . '/../inc/stic-documents.php';
        require_once __DIR__ . '/../inc/stic-sessions.php';
    }

    private function nvl(array $fields)
    {
        $o = new stdClass();
        foreach ($fields as $k => $v) {
            $o->$k = (object) array('value' => $v);
        }
        return $o;
    }

    private function row(array $fields)
    {
        $r = new stdClass();
        $r->name_value_list = $this->nvl($fields);
        return $r;
    }

    /* ----------------------------------------------------------- Documentos */

    /** La acción de un documento es descargarlo, y está a UN toque. */
    public function testLaDescargaEstaEnLaTarjeta()
    {
        $html = sticpa_documents_list_html(array(
            $this->row(array('id' => 'd1', 'document_name' => 'Autorización',
                'filename' => 'a.pdf', 'active_date' => '2026-09-01')),
        ), array());

        $this->assertStringContainsString('stic-rec-quick', $html);
        $this->assertStringContainsString('download=true', $html);
        $this->assertStringContainsString('id=d1', $html);
    }

    /**
     * Un documento del CRM puede ser una ficha a la que aún no se ha subido
     * nada. Un botón que baja un fichero vacío es peor que no tener botón.
     */
    public function testSinArchivoNoSeOfreceDescarga()
    {
        $html = sticpa_documents_list_html(array(
            $this->row(array('id' => 'd1', 'document_name' => 'Ficha sin archivo', 'filename' => '')),
        ), array());
        $this->assertStringNotContainsString('stic-rec-quick', $html);
        $this->assertStringContainsString('Ficha sin archivo', $html);
    }

    /**
     * La tarjeta abre la FICHA, nunca la descarga: tocar sin querer y que
     * empiece a bajar un archivo es de lo que más molesta en móvil.
     */
    public function testLaTarjetaNoDescargaAlTocarla()
    {
        $html = sticpa_documents_list_html(array(
            $this->row(array('id' => 'd1', 'document_name' => 'X', 'filename' => 'a.pdf')),
        ), array());
        $this->assertMatchesRegularExpression("/class='stic-rec-main' href='[^']*internalpage=single_stic_documents/", $html);
    }

    /** El tipo de archivo sale de la extensión, y solo si es creíble. */
    public function testElTipoDeArchivo()
    {
        $this->assertSame('PDF', sticpa_document_kind('autorizacion.pdf'));
        $this->assertSame('JPG', sticpa_document_kind('foto.JPEG'));
        $this->assertSame('DOC', sticpa_document_kind('carta.docx'));
        $this->assertSame('HEIC', sticpa_document_kind('IMG_2026.heic'));
        // Sin extensión, o con una que no lo parece, mejor nada que inventar.
        $this->assertSame('', sticpa_document_kind('archivo_sin_extension'));
        $this->assertSame('', sticpa_document_kind('cosa.extensionlarguisima'));
        $this->assertSame('', sticpa_document_kind(''));
    }

    /** Un documento caducado es justo el que hay que renovar: se marca. */
    public function testUnDocumentoCaducadoSeMarcaYSeVaAbajo()
    {
        $html = sticpa_documents_list_html(array(
            $this->row(array('id' => 'viejo', 'document_name' => 'El caducado', 'filename' => 'a.pdf',
                'active_date' => '2026-01-01', 'exp_date' => '2025-06-01')),
            $this->row(array('id' => 'nuevo', 'document_name' => 'El vigente', 'filename' => 'b.pdf',
                'active_date' => '2024-01-01')),
        ), array());

        $this->assertStringContainsString('Caducado', $html);
        $this->assertStringContainsString('stic-rec-chip--danger', $html);
        $this->assertLessThan(strpos($html, 'El caducado'), strpos($html, 'El vigente'));
    }

    /* ------------------------------------------------------------- Sesiones */

    /** El día en la cápsula y la hora en una línea: no dos fechas con segundos. */
    public function testLaSesionDiceLaHoraYNoDosFechas()
    {
        $html = sticpa_sessions_list_html(array(
            $this->row(array('id' => 's1', 'name' => 'Sesión del martes',
                'start_date' => '2026-11-04 17:30:00', 'end_date' => '2026-11-04 19:00:00')),
        ));
        $this->assertStringContainsString('17:30', $html);
        $this->assertStringContainsString('19:00', $html);
        $this->assertStringNotContainsString(':00:00', $html);
    }

    /** Un tramo que cruza la medianoche no se lee sin la fecha: solo el inicio. */
    public function testElTramoHorario()
    {
        $this->assertSame('17:30 – 19:00', sticpa_time_range_text(
            strtotime('2026-11-04 17:30'), strtotime('2026-11-04 19:00')));
        // Días distintos: solo el inicio.
        $this->assertSame('10:00', sticpa_time_range_text(
            strtotime('2026-11-08 10:00'), strtotime('2026-11-09 18:00')));
        // Fin antes que el inicio (dato sucio del CRM): no se pinta un rango absurdo.
        $this->assertSame('19:00', sticpa_time_range_text(
            strtotime('2026-11-04 19:00'), strtotime('2026-11-04 17:00')));
        $this->assertSame('', sticpa_time_range_text(null));
    }

    /** La sesión enlaza a la ACTIVIDAD, no a una ficha que la repita. */
    public function testLaSesionEnlazaAlEvento()
    {
        $html = sticpa_sessions_list_html(array(
            $this->row(array('id' => 's1', 'name' => 'Sesión', 'start_date' => '2026-11-04 17:30:00',
                'stic_sessions_stic_eventsstic_events_ida' => 'ev-1')),
        ));
        $this->assertStringContainsString('single_stic_events', $html);
        $this->assertStringNotContainsString('single_stic_sessions', $html);
    }

    /** Sin evento al que ir, la tarjeta no finge que se puede abrir. */
    public function testLaSesionSinEventoNoEsPulsable()
    {
        $html = sticpa_sessions_list_html(array(
            $this->row(array('id' => 's1', 'name' => 'Sesión suelta', 'start_date' => '2026-11-04 17:30:00')),
        ));
        $this->assertStringContainsString('stic-rec-main--static', $html);
    }

    /* ---------------------------------------------------------- Asistencias */

    /** `duration` es un decimal del CRM; nadie dice "una coma cinco horas". */
    public function testLaDuracionEnLenguajeHumano()
    {
        $this->assertSame('1 h 30 min', sticpa_duration_text('1.5'));
        $this->assertSame('45 min', sticpa_duration_text('0.75'));
        $this->assertSame('2 h', sticpa_duration_text('2'));
        $this->assertSame('', sticpa_duration_text('0'));
        $this->assertSame('', sticpa_duration_text(''));
    }

    /**
     * EL FALLO QUE COSTÓ UN CHIP VERDE. El tono se busca por raíces dentro de
     * la clave, y `no_attended` CONTIENE `attended`, igual que `not_paid`
     * contiene `paid`: "no vino" salía verde y "no pagado" salía de cobrado.
     * En una pantalla de dinero eso es decirle a alguien que está al día
     * cuando no lo está.
     */
    public function testUnEstadoNEGADONuncaSePintaDeVerde()
    {
        foreach (array('no_attended', 'not_paid', 'non_attended', 'sin_pagar', 'no attended') as $clave) {
            $this->assertNotSame('ok', sticpa_record_status_tone($clave), "«{$clave}» no puede ser 'ok'");
        }
        // Y lo afirmativo sigue funcionando.
        $this->assertSame('ok', sticpa_record_status_tone('attended'));
        $this->assertSame('ok', sticpa_record_status_tone('paid'));
        // Si además dice algo malo, manda lo malo.
        $this->assertSame('danger', sticpa_record_status_tone('no_pagado_rechazado'));
    }

    /** Se enseña la SESIÓN a la que fuiste, no el código del registro. */
    public function testLaAsistenciaEnsenaLaSesion()
    {
        $html = sticpa_attendances_list_html(array(
            $this->row(array('id' => 'a1', 'name' => 'AST-000123', 'status' => 'attended',
                'start_date' => '2026-08-25 17:30:00', 'duration' => '1.5',
                'stic_attendances_stic_sessions_name' => 'Sesión del martes')),
        ), array('status' => array('options' => array('attended' => array('value' => 'Vino')))));

        $this->assertStringContainsString('Sesión del martes', $html);
        $this->assertStringNotContainsString('AST-000123', $html);
        $this->assertStringContainsString('1 h 30 min', $html);
    }

    /** Una asistencia no abre ficha: la tarjeta ya lo dice todo. */
    public function testLaAsistenciaNoAbreFicha()
    {
        $html = sticpa_attendances_list_html(array(
            $this->row(array('id' => 'a1', 'name' => 'X', 'status' => 'attended',
                'start_date' => '2026-08-25 17:30:00',
                'stic_attendances_stic_sessions_name' => 'Sesión')),
        ), array());
        $this->assertStringContainsString('stic-rec-main--static', $html);
        $this->assertStringNotContainsString('single_stic_attendances', $html);
    }

    /** Los tres estados vacíos explican qué se vería aquí. */
    public function testLosEstadosVacios()
    {
        $this->assertStringContainsString('stic-empty-state', sticpa_documents_list_html(array(), array()));
        $this->assertStringContainsString('stic-empty-state', sticpa_sessions_list_html(array()));
        $this->assertStringContainsString('stic-empty-state', sticpa_attendances_list_html(array(), array()));
    }
}
