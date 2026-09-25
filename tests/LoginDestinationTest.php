<?php

use PHPUnit\Framework\TestCase;

/**
 * EL DESTINO SOBREVIVE AL LOGIN (TODO EV-8, 25/09/2026).
 *
 * Un enlace a una página del área sin sesión pinta el login; al entrar hay que
 * aterrizar en esa página y no en la portada, por las tres puertas (código,
 * enlace mágico —también pasando por `/app/acceso`— y contraseña).
 *
 * Lo que de verdad importa aquí es que NO se convierta en un redirector: del
 * destino solo viajan `internalpage` y tres parámetros con su forma exacta.
 */
final class LoginDestinationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../inc/stic-app-links.php';
    }

    protected function tearDown(): void
    {
        unset($_REQUEST['scp_current_url'], $_GET['internalpage'], $_GET['id'], $_GET['action'], $_GET['acceso_magico']);
    }

    public function test_el_destino_de_una_ficha_de_evento_se_conserva_entero()
    {
        $args = sticpa_login_destination_args('/ap/?internalpage=single_stic_events&action=detail&id=000006cd-2b27-b8e1-ad34-6ab060f8cb01&stic_auth=1');
        $this->assertSame(array(
            'internalpage' => 'single_stic_events',
            'action' => 'detail',
            'id' => '000006cd-2b27-b8e1-ad34-6ab060f8cb01',
        ), $args);
    }

    public function test_acepta_un_array_como_get()
    {
        $this->assertSame(array('internalpage' => 'list_stic_events'),
            sticpa_login_destination_args(array('internalpage' => 'list_stic_events', 'stic_auth' => '1')));
    }

    public function test_sin_pagina_o_con_una_pagina_con_forma_rara_no_hay_destino()
    {
        $this->assertSame(array(), sticpa_login_destination_args('/ap/?stic_auth=1'));
        $this->assertSame(array(), sticpa_login_destination_args('/ap/?internalpage=../../wp-config'));
        $this->assertSame(array(), sticpa_login_destination_args('/ap/?internalpage=https://malo.example'));
        $this->assertSame(array(), sticpa_login_destination_args(array('internalpage' => array('x'))));
    }

    public function test_los_parametros_que_no_tienen_su_forma_se_tiran()
    {
        $args = sticpa_login_destination_args('/ap/?internalpage=single_stic_events&action=delete&id=<script>&from=stic_events&redirect_to=https://malo.example');
        $this->assertSame(array('internalpage' => 'single_stic_events', 'from' => 'stic_events'), $args,
            'ni una acción que no sea ver/crear/editar, ni un id que no sea un id, ni parámetros ajenos');
    }

    public function test_poner_el_destino_conserva_la_query_y_no_duplica()
    {
        $url = sticpa_url_with_destination('/ap/?stic_auth=1&internalpage=viejo&sticpa_code=1',
            array('internalpage' => 'list_stic_events'));
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(array('stic_auth' => '1', 'sticpa_code' => '1', 'internalpage' => 'list_stic_events'), $q);
        $this->assertSame('/ap/?stic_auth=1', sticpa_url_with_destination('/ap/?stic_auth=1', array()),
            'sin destino, la URL no se toca');
    }

    public function test_la_pantalla_de_acceso_vuelve_con_el_destino_y_sin_hosts_ajenos()
    {
        $_REQUEST['scp_current_url'] = 'https://malo.example/ap/?stic_auth=1&internalpage=single_stic_events&action=detail&id=abcdef0123-45&next=https://malo.example';
        $url = sticpa_auth_return_url();
        $this->assertStringStartsWith('/ap/?', $url, 'solo la ruta: el host del cliente no viaja');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('single_stic_events', $q['internalpage']);
        $this->assertSame('abcdef0123-45', $q['id']);
        $this->assertArrayNotHasKey('next', $q);
    }

    public function test_el_enlace_del_correo_lleva_el_destino_hasta_el_puente_de_la_app()
    {
        $base = sticpa_url_with_destination('https://example.test/ap/',
            sticpa_login_destination_args('/ap/?stic_auth=1&internalpage=single_stic_events&action=detail&id=abcdef0123-45'));
        $link = sticpa_app_link_url(sticpa_generate_magic_link($base, 'Contacts', 'C-1', 3600));
        $this->assertStringStartsWith('https://example.test/app/acceso?', $link);
        parse_str(parse_url($link, PHP_URL_QUERY), $q);
        $this->assertArrayHasKey('acceso_magico', $q);
        $this->assertSame('single_stic_events', $q['internalpage']);
        $this->assertSame('detail', $q['action']);
        $this->assertSame('abcdef0123-45', $q['id']);
        // Y el acceso sigue siendo válido: el destino no toca la firma.
        $this->assertSame(array('Contacts', 'C-1'), sticpa_validate_magic_link($q['acceso_magico']));
    }

    public function test_sin_destino_el_enlace_del_correo_es_el_de_siempre()
    {
        $link = sticpa_app_link_url(sticpa_generate_magic_link('https://example.test/ap/', 'Contacts', 'C-1', 3600));
        parse_str(parse_url($link, PHP_URL_QUERY), $q);
        $this->assertSame(array('acceso_magico'), array_keys($q));
    }
}
