<?php

use PHPUnit\Framework\TestCase;

/**
 * VOLVER AL ÁREA DESDE FUERA (30/09/2026).
 *
 * El pago con tarjeta volvía a `https://…/?internalpage=…` —la raíz de la web—
 * porque el plugin original usa `home_url()` y da por hecho que el área es la
 * portada. Aquí vive en `/ap/`. Se prueba la URL que se manda al CRM y la red
 * de seguridad que reenvía lo que aún llegue a la raíz.
 */
final class AreaReturnUrlTest extends TestCase
{
    private $option;
    private $server;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../inc/stic-app-links.php';
    }

    protected function setUp(): void
    {
        $this->option = $GLOBALS['__stic_options']['sticpa_scp_area_url'] ?? null;
        $this->server = $_SERVER;
        $GLOBALS['__stic_options']['sticpa_scp_area_url'] = 'https://example.test/ap/';
    }

    protected function tearDown(): void
    {
        $GLOBALS['__stic_options']['sticpa_scp_area_url'] = $this->option;
        $_SERVER = $this->server;
        $_GET = array();
    }

    private function peticion($uri, $method = 'GET')
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET = array();
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $_GET);
    }

    public function test_la_vuelta_del_pago_va_al_area_y_no_a_la_raiz()
    {
        $this->assertSame('https://example.test/ap/?internalpage=single_stic_registrations&action=detail&id=x&msg=pagado',
            sticpa_area_absolute_url('internalpage=single_stic_registrations&action=detail&id=x&msg=pagado'));
        $this->assertSame('https://example.test/ap/', sticpa_area_absolute_url(''));
    }

    public function test_sin_area_configurada_es_la_raiz_como_antes()
    {
        $GLOBALS['__stic_options']['sticpa_scp_area_url'] = '';
        $this->assertSame('https://example.test/?internalpage=list_stic_payments', sticpa_area_absolute_url('internalpage=list_stic_payments'));
    }

    public function test_una_pantalla_pedida_en_la_raiz_se_reenvia_al_area_con_sus_parametros()
    {
        $this->peticion('/?internalpage=single_stic_registrations&action=detail&id=00000145-53f0-4a12-a43e-6ab69b60856e&msg=pagado');
        $this->assertSame('https://example.test/ap/?internalpage=single_stic_registrations&action=detail&id=00000145-53f0-4a12-a43e-6ab69b60856e&msg=pagado',
            sticpa_root_internalpage_target());
    }

    public function test_no_se_reenvia_lo_que_ya_esta_en_el_area_ni_un_post_ni_lo_que_no_es_del_area()
    {
        $this->peticion('/ap/?internalpage=list_stic_payments');
        $this->assertSame('', sticpa_root_internalpage_target());
        $this->peticion('/?internalpage=list_stic_payments', 'POST');
        $this->assertSame('', sticpa_root_internalpage_target());
        $this->peticion('/?p=12');
        $this->assertSame('', sticpa_root_internalpage_target());
        // Si el área ES la portada, no hay bucle.
        $GLOBALS['__stic_options']['sticpa_scp_area_url'] = 'https://example.test/';
        $this->peticion('/?internalpage=list_stic_payments');
        $this->assertSame('', sticpa_root_internalpage_target());
    }
}
