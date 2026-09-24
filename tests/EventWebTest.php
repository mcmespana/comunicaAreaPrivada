<?php

use PHPUnit\Framework\TestCase;

/**
 * LA INFORMACIÓN DE LA WEB DE UN EVENTO, en el área privada.
 *
 * inc/eventos-cuerpo.php (el renderizador compartido con la página pública) e
 * inc/stic-event-web.php (lo que lo lleva a la ficha del evento).
 *
 * El renderizador tiene su batería grande en el repo de formularios
 * (`inicio/pruebas/eventos.test.php`), que es donde más se usa. Aquí se prueba
 * lo que es de aquí —el reparto del cartel y los documentos, que los enlaces a
 * los documentos pasen por NUESTRO endpoint y que la ficha no cuente el texto
 * dos veces— y las reglas de seguridad del renderizador, que se prueban en los
 * dos sitios porque son las que no pueden romperse nunca.
 */
class EventWebTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../inc/stic-formatter.php';
        require_once __DIR__ . '/../inc/stic-formController.php';
        require_once __DIR__ . '/../inc/stic-record-view.php';
        require_once __DIR__ . '/../inc/stic-events.php';
        require_once __DIR__ . '/../inc/eventos-cuerpo.php';
        require_once __DIR__ . '/../inc/stic-event-web.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['__stic_filters'] = array();
        $GLOBALS['__stic_transients'] = array();
    }

    private function nvl(array $fields)
    {
        $o = new stdClass();
        foreach ($fields as $k => $v) {
            $o->$k = (object) array('value' => $v);
        }
        return $o;
    }

    /** Un CRM de mentira que solo sabe de los documentos de un evento. */
    private function crm(array $docs, &$llamadas = 0)
    {
        $self = $this;
        return new class($docs, $llamadas, $self) {
            private $docs;
            private $llamadas;
            private $t;
            public function __construct($docs, &$llamadas, $t)
            {
                $this->docs = $docs;
                $this->llamadas = &$llamadas;
                $this->t = $t;
            }
            public function getRelatedElementsForLoggedUser($p)
            {
                $this->llamadas++;
                $salida = array();
                foreach ($this->docs as $d) {
                    $o = new stdClass();
                    foreach ($d as $k => $v) {
                        $o->$k = (object) array('value' => $v);
                    }
                    $salida[] = (object) array('name_value_list' => $o);
                }
                return $salida;
            }
        };
    }

    // ── El renderizador: lo que no puede romperse ─────────────────────────

    public function test_un_script_escrito_en_el_crm_sale_como_texto()
    {
        $h = mcm_cuerpo_html(mcm_cuerpo_bloques('&lt;script&gt;alert(1)&lt;/script&gt;'));
        $this->assertStringNotContainsString('<script', $h);
        $this->assertStringContainsString('&lt;script&gt;', $h);
    }

    public function test_un_enlace_javascript_se_cae_y_se_queda_el_texto()
    {
        $h = mcm_cuerpo_html(mcm_cuerpo_bloques('Pincha [aquí](javascript:alert(1)) porfa'));
        $this->assertStringNotContainsString('javascript', $h);
        $this->assertStringContainsString('aquí', $h);
    }

    public function test_el_html_de_un_editor_se_sanea()
    {
        $h = mcm_cuerpo_html(mcm_cuerpo_bloques(
            '<p style="color:red" onclick="x()">Hola <b>mundo</b></p><img src="x" onerror="alert(1)"><script>alert(2)</script>'
        ));
        $this->assertStringContainsString('<p>Hola <strong>mundo</strong></p>', $h);
        $this->assertStringNotContainsString('onclick', $h);
        $this->assertStringNotContainsString('onerror', $h);
        $this->assertStringNotContainsString('alert(2)', $h);
        $this->assertStringNotContainsString('style=', $h);
    }

    public function test_un_salto_de_linea_es_un_salto_de_linea()
    {
        $h = mcm_cuerpo_html(mcm_cuerpo_bloques("60 € \nIncluye el alojamiento"));
        $this->assertStringContainsString("60 €<br>\nIncluye", $h);
    }

    public function test_los_titulos_del_cuerpo_van_por_debajo_de_los_de_la_ficha()
    {
        $h = mcm_cuerpo_html(mcm_cuerpo_bloques("## Sección\n### Sub"), array('titulo_base' => 5));
        $this->assertStringContainsString('<h5>Sección</h5>', $h);
        $this->assertStringContainsString('<h6>Sub</h6>', $h);
    }

    public function test_el_nombre_con_la_convencion_del_crm()
    {
        $t = mcm_cuerpo_titulo('COM | Convivencia Inicial 2026 · Buñol · CS');
        $this->assertSame('COM', $t['etiqueta']);
        $this->assertSame('Convivencia Inicial 2026 · Buñol', $t['titulo']);
        $this->assertSame('Congreso · MCM', mcm_cuerpo_titulo('Congreso · MCM')['titulo']);
    }

    // ── La ficha ──────────────────────────────────────────────────────────

    public function test_el_cartel_del_cuerpo_sube_y_no_sale_dos_veces()
    {
        $nvl = $this->nvl(array(
            'web_cartel_c' => 'http://',   // lo que pone SuiteCRM en un URL vacío
            'web_cuerpo_c' => "![Cartel](https://i.imgur.com/a.png)\n\n## De qué va\nAlgo",
        ));
        $v = sticpa_event_web_view($this->crm(array()), 'e1', $nvl);
        $this->assertSame('https://i.imgur.com/a.png', $v['cartel']);
        $this->assertStringNotContainsString('<img', $v['cuerpo_html']);
        $this->assertStringContainsString('<h5>De qué va</h5>', $v['cuerpo_html']);
    }

    public function test_los_documentos_se_reparten_y_van_por_nuestro_endpoint()
    {
        $docs = array(
            array('id' => '0000aaaa-1111', 'document_name' => 'Autorización', 'filename' => 'aut.pdf'),
            array('id' => '0000bbbb-1111', 'document_name' => 'Cartel', 'filename' => 'cartel.jpg'),
            array('id' => '0000cccc-1111', 'document_name' => 'Foto', 'filename' => 'foto.png'),
            array('id' => '0000dddd-1111', 'document_name' => 'Peligro', 'filename' => 'x.svg'),
        );
        $v = sticpa_event_web_view($this->crm($docs), '0000eeee-1111', $this->nvl(array('web_cuerpo_c' => 'Texto')));

        $this->assertStringContainsString('action=sticpa_evento_archivo', $v['cartel'], 'la primera imagen subida es el cartel');
        $this->assertStringContainsString('d=0000bbbb-1111', $v['cartel']);
        $this->assertCount(1, $v['galeria'], 'el resto de imágenes, a la galería');
        $this->assertCount(1, $v['adjuntos'], 'el PDF, a descargar; el SVG no se anuncia');
        $this->assertSame('Autorización', $v['adjuntos'][0]['titulo']);
        $this->assertStringContainsString('e=0000eeee-1111', $v['adjuntos'][0]['url']);
    }

    public function test_un_archivo_php_del_cuerpo_de_este_evento_pasa_a_nuestro_endpoint()
    {
        $docs = array(array('id' => '0000aaaa-2222', 'document_name' => 'Info', 'filename' => 'info.pdf'));
        $v = sticpa_event_web_view($this->crm($docs), '0000eeee-2222', $this->nvl(array(
            'web_cuerpo_c' => "[boton] Info | /archivo.php?d=0000aaaa-2222\n\n[boton] Otro | /archivo.php?d=0000ffff-9999",
        )));
        $this->assertStringContainsString('action=sticpa_evento_archivo', $v['cuerpo_html']);
        // El de otro evento NO se reescribe: nuestro endpoint no lo serviría.
        $this->assertStringContainsString('/archivo.php?d=0000ffff-9999', $v['cuerpo_html']);
    }

    public function test_los_documentos_se_piden_una_vez_cada_diez_minutos()
    {
        $n = 0;
        $crm = $this->crm(array(array('id' => '0000aaaa-3333', 'document_name' => 'A', 'filename' => 'a.pdf')), $n);
        sticpa_event_documents($crm, 'ev-cache');
        sticpa_event_documents($crm, 'ev-cache');
        $this->assertSame(1, $n);
    }

    public function test_la_pagina_publica_solo_si_esta_publicado()
    {
        $v = sticpa_event_web_view($this->crm(array()), 'e1', $this->nvl(array('web_cuerpo_c' => 'x', 'web_publicar_c' => '0')));
        $this->assertSame('', $v['pagina']);
        $v = sticpa_event_web_view($this->crm(array()), 'e1', $this->nvl(array(
            'web_cuerpo_c' => 'x', 'web_publicar_c' => '1', 'web_slug_c' => 'convivencia26')));
        $this->assertStringEndsWith('?e=convivencia26', $v['pagina']);
    }

    public function test_con_la_web_la_ficha_no_repite_la_descripcion()
    {
        $nvl = $this->nvl(array(
            'id' => 'e1', 'name' => 'Convivencia', 'start_date' => date('Y-m-d', strtotime('+10 days')),
            'status' => 'registration', 'description' => 'Nota interna del equipo',
            'web_cuerpo_c' => '## De qué va' . "\n" . 'Un fin de semana.', 'web_lema_c' => 'Sin Rodeos',
        ));
        $html = sticpa_event_detail_html(sticpa_event_view_model($nvl), '', true, '',
            sticpa_event_web_view($this->crm(array()), 'e1', $nvl));
        $this->assertStringContainsString('Un fin de semana.', $html);
        $this->assertStringContainsString('Sin Rodeos', $html);
        $this->assertStringNotContainsString('Nota interna del equipo', $html);
    }

    public function test_sin_la_web_la_ficha_sigue_como_siempre()
    {
        $nvl = $this->nvl(array(
            'id' => 'e1', 'name' => 'Convivencia', 'start_date' => date('Y-m-d', strtotime('+10 days')),
            'status' => 'registration', 'description' => 'La descripción de siempre',
        ));
        $html = sticpa_event_detail_html(sticpa_event_view_model($nvl), '', true, '',
            sticpa_event_web_view($this->crm(array()), 'e1', $nvl));
        $this->assertStringContainsString('La descripción de siempre', $html);
        $this->assertStringContainsString('Sobre esta actividad', $html);
        $this->assertStringNotContainsString('stic-rec-cover', $html);
    }

    public function test_el_listado_no_pide_el_cuerpo_de_la_web_y_la_ficha_si()
    {
        // Un CRM en el que los campos de la web EXISTEN.
        $crm = new class {
            public function getFieldDefinition($module, $fields)
            {
                $def = array();
                foreach ($fields as $f) {
                    $def[$f] = array('name' => $f);
                }
                return (object) array('module_fields' => $def);
            }
        };
        $this->assertNotContains('web_cuerpo_c', sticpa_event_fields_to_request($crm),
            'el listado no se trae el cuerpo largo de cada evento');
        $this->assertContains('web_cuerpo_c', sticpa_event_fields_to_request($crm, true),
            'la ficha, sí');
    }
}
