<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('StiRedirect')) {
    class StiRedirect extends Exception
    {
        public $url;
        public function __construct($url)
        {
            parent::__construct('redirect: ' . $url);
            $this->url = (string) $url;
        }
    }
}
if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect($url, $status = 302)
    {
        throw new StiRedirect($url);
    }
}

/**
 * USUARIO Y CONTRASEÑA (28/09/2026).
 *
 * Casi todo el mundo entra por el enlace del correo, así que casi nadie sabe su
 * usuario ni tiene contraseña. Lo que se prueba:
 *
 *   · la pantalla ENSEÑA el usuario, y si no hay, el DNI, sin poder editarlo;
 *   · la contraseña antigua solo se pide si existe;
 *   · al guardar sin usuario, el DNI queda como usuario (si no, la contraseña
 *     nueva no serviría para entrar);
 *   · un DNI que ya es el usuario de otra ficha NO se pisa.
 */
class PasswordChangeTest extends TestCase
{
    private $crm;

    protected function setUp(): void
    {
        $GLOBALS['__stic_filters'] = array();
        $_SESSION = array('scp_user_id' => 'c1', 'scp_module' => 'Contacts', 'scp_user_adult' => true);
        $_REQUEST = array();
        $this->crm = $this->crm(array());
        SugarRestApiCall::$objSCP = $this->crm;
    }

    protected function tearDown(): void
    {
        SugarRestApiCall::$objSCP = null;
        $_REQUEST = array();
        $_SESSION = array();
    }

    private function crm(array $ficha, $otroConEseUsuario = '')
    {
        return new class($ficha, $otroConEseUsuario) {
            public $ficha;
            public $otro;
            public $writes = array();
            public function __construct($ficha, $otro) { $this->ficha = $ficha; $this->otro = $otro; }
            public function getUserInformation($id)
            {
                $nvl = new stdClass();
                foreach ($this->ficha as $k => $v) {
                    $nvl->$k = (object) array('name' => $k, 'value' => $v);
                }
                return (object) array('entry_list' => array((object) array('id' => $id, 'name_value_list' => $nvl)));
            }
            public function getUserInformationByUsername($u)
            {
                return ($this->otro !== '')
                    ? (object) array('entry_list' => array((object) array('id' => $this->otro)))
                    : false;
            }
            public function set_entry($module, $data)
            {
                $this->writes[] = $data;
                return (object) array('id' => $data['id']);
            }
        };
    }

    private function post(array $fields)
    {
        $_REQUEST = $fields;
        $_REQUEST['stic_form_fields'] = sticpa_form_token('single_stic_password_change', array());
        $_REQUEST['scp_current_url'] = '/ap/?internalpage=single_stic_password_change';
        try {
            prefix_admin_single_stic_password_change();
        } catch (StiRedirect $r) {
            return $r->url;
        }
        $this->fail('el handler no redirigió');
    }

    private function render()
    {
        $objSCP = $this->crm;
        $html = '';
        $_SERVER['REQUEST_URI'] = '/ap/?internalpage=single_stic_password_change';
        require __DIR__ . '/../pages/single_stic_password_change.php';
        return $html;
    }

    // ---- La pantalla -------------------------------------------------------

    public function test_sin_usuario_se_ensena_el_dni_y_no_se_puede_editar()
    {
        $this->crm->ficha = array('stic_identification_number_c' => '12345678-z', 'stic_pa_password_c' => '');
        $html = $this->render();

        $this->assertStringContainsString("value='12345678Z' readonly", $html);
        $this->assertStringContainsString('Tu usuario es tu DNI', $html);
        // Nunca ha tenido contraseña: no se le pide la «actual».
        $this->assertStringNotContainsString('add-profile-old-password', $html);
    }

    public function test_con_usuario_y_contrasena_se_pide_la_actual()
    {
        $this->crm->ficha = array('stic_pa_username_c' => 'lucia', 'stic_pa_password_c' => 'vieja1');
        $html = $this->render();

        $this->assertStringContainsString("value='lucia' readonly", $html);
        $this->assertStringContainsString('add-profile-old-password', $html);
    }

    public function test_sin_usuario_ni_dni_no_hay_formulario()
    {
        $this->crm->ficha = array();
        $html = $this->render();

        $this->assertStringContainsString('No tenemos tu DNI', $html);
        $this->assertStringNotContainsString('add-profile-new-password', $html);
    }

    // ---- El handler --------------------------------------------------------

    public function test_primera_contrasena_guarda_el_dni_como_usuario()
    {
        $this->crm->ficha = array('stic_identification_number_c' => '12345678Z', 'stic_pa_password_c' => '');
        $url = $this->post(array('add-profile-new-password' => 'secreta', 'add-profile-confirm-password' => 'secreta'));

        $this->assertStringContainsString('success=true', $url);
        $this->assertSame(array(
            'id' => 'c1',
            'stic_pa_password_c' => 'secreta',
            'stic_pa_username_c' => '12345678Z',
        ), $this->crm->writes[0]);
    }

    public function test_con_usuario_no_se_toca_el_usuario()
    {
        $this->crm->ficha = array('stic_pa_username_c' => 'lucia', 'stic_pa_password_c' => 'vieja1');
        $url = $this->post(array(
            'add-profile-old-password' => 'vieja1',
            'add-profile-new-password' => 'nueva12',
            'add-profile-confirm-password' => 'nueva12',
        ));

        $this->assertStringContainsString('success=true', $url);
        $this->assertArrayNotHasKey('stic_pa_username_c', $this->crm->writes[0]);
    }

    public function test_si_hay_contrasena_la_actual_tiene_que_coincidir()
    {
        $this->crm->ficha = array('stic_pa_username_c' => 'lucia', 'stic_pa_password_c' => 'vieja1');
        $url = $this->post(array('add-profile-new-password' => 'nueva12', 'add-profile-confirm-password' => 'nueva12'));

        $this->assertStringContainsString('error=2', $url);
        $this->assertSame(array(), $this->crm->writes);
    }

    public function test_un_dni_que_ya_es_usuario_de_otra_ficha_no_se_pisa()
    {
        $this->crm = $this->crm(array('stic_identification_number_c' => '12345678Z'), 'c-otra');
        SugarRestApiCall::$objSCP = $this->crm;
        $url = $this->post(array('add-profile-new-password' => 'secreta', 'add-profile-confirm-password' => 'secreta'));

        $this->assertStringContainsString('error=5', $url);
        $this->assertSame(array(), $this->crm->writes);
    }

    public function test_sin_dni_no_se_guarda_una_contrasena_inservible()
    {
        $this->crm->ficha = array();
        $url = $this->post(array('add-profile-new-password' => 'secreta', 'add-profile-confirm-password' => 'secreta'));

        $this->assertStringContainsString('error=3', $url);
        $this->assertSame(array(), $this->crm->writes);
    }

    public function test_contrasena_corta_o_distinta_no_se_guarda()
    {
        $this->crm->ficha = array('stic_pa_username_c' => 'lucia');
        $this->assertStringContainsString('error=4', $this->post(array('add-profile-new-password' => 'abc', 'add-profile-confirm-password' => 'abc')));
        $this->assertStringContainsString('error=1', $this->post(array('add-profile-new-password' => 'abcdef', 'add-profile-confirm-password' => 'abcdeg')));
        $this->assertSame(array(), $this->crm->writes);
    }
}
