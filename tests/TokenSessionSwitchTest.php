<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('StiRedirect')) {
    /** El handler «redirige»: se corta aquí, antes del `exit`, y se mira a dónde. */
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
if (!function_exists('is_admin')) {
    function is_admin()
    {
        return false;
    }
}
if (!function_exists('remove_query_arg')) {
    function remove_query_arg($keys, $url = false)
    {
        $url = $url !== false ? $url : (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $parts = explode('?', $url, 2);
        parse_str($parts[1] ?? '', $q);
        foreach ((array) $keys as $k) {
            unset($q[$k]);
        }
        return $parts[0] . ($q ? '?' . http_build_query($q) : '');
    }
}

/**
 * UN ENLACE CON TOKEN MANDA SOBRE LA SESIÓN QUE HAYA (01/11/2026).
 *
 * Antes, con una sesión abierta el enlace se ignoraba y seguías siendo quien eras: la madre que abría su correo en un
 * navegador donde ya había entrado otra persona (o donde ella misma había elegido otro perfil) veía lo de la otra.
 * Ahora: enlace de OTRA persona → se entra como ella sin heredar nada; de la MISMA → no se toca la sesión; inválido →
 * la sesión se queda como estaba.
 */
final class TokenSessionSwitchTest extends TestCase
{
    private const TOKEN_MARTA = '6f9c7ed0d3893d1940e110c99b37a87f';
    private const TOKEN_JUAN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var object */
    private $crm;

    protected function setUp(): void
    {
        $GLOBALS['__stic_filters'] = array();
        $_SESSION = array();
        $_REQUEST = array();
        $_GET = array();
        $_SERVER['REQUEST_URI'] = '/ap/?internalpage=list_stic_events';
        $this->crm = new class {
            public $logins = array();
            public $detailCalls = 0;
            public $people = array();
            public function __construct()
            {
                $this->people = array(
                    '6f9c7ed0d3893d1940e110c99b37a87f' => array('id' => 'marta', 'name' => 'Marta Paradells'),
                    'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => array('id' => 'juan', 'name' => 'Juan Soler'),
                );
            }
            private function entry($p)
            {
                $nvl = new stdClass();
                $nvl->name = (object) array('value' => $p['name']);
                $nvl->assigned_user_id = (object) array('value' => 'del-cs');
                $nvl->stic_relationship_type_c = (object) array('value' => 'madre');
                return (object) array('id' => $p['id'], 'name_value_list' => $nvl);
            }
            public function PortalLoginByToken($token, $module)
            {
                $this->logins[] = $token;
                return (object) array('entry_list' => isset($this->people[$token]) ? array($this->entry($this->people[$token])) : array());
            }
            public function getRecordDetail($id, $module, $fields = null)
            {
                $this->detailCalls++;
                foreach ($this->people as $p) {
                    if ($p['id'] === $id) {
                        return (object) array('entry_list' => array($this->entry($p)));
                    }
                }
                return (object) array('entry_list' => array());
            }
        };
        SugarRestApiCall::$objSCP = $this->crm;
    }

    protected function tearDown(): void
    {
        SugarRestApiCall::$objSCP = null;
        $_SESSION = array();
        $_REQUEST = array();
    }

    /** Pasa por el handler del acceso con ese parámetro; devuelve a dónde redirige (null si no redirige). */
    private function entrarCon(array $params)
    {
        $_REQUEST = $params;
        try {
            sticpa_process_passwordless_login();
        } catch (StiRedirect $r) {
            return $r->url;
        }
        return null;
    }

    /** Una sesión de OTRA persona (Juan), con todo lo que se hereda: perfil elegido, «es miembro», delegación en caché… */
    private function conSesionDeJuan()
    {
        $_SESSION = array(
            'scp_user_id' => 'hijo-juan',
            'scp_user_contact_name' => 'Pablo Soler',
            'scp_user_adult' => false,
            'scp_tutor_user_id' => 'juan',
            'scp_tutor_user_contact_name' => 'Juan Soler',
            'scp_tutor_is_user' => false,
            'scp_tutor_es_miembro' => true,
            'scp_available_profiles' => array(array('id' => 'hijo-juan', 'name' => 'Pablo Soler')),
            'scp_role' => 'monitor',
            'scp_role_resolved' => true,
            'scp_relationship_raw' => 'monitor',
            'scp_pl_delegation' => 'del-otra',
            'scp_pl_deleg_activa' => 'del-otra',
            'api_session_id' => 'sesion-crm-de-juan',
            'api_session_time' => 123,
        );
    }

    public function test_sin_sesion_el_token_entra_como_siempre_y_limpia_la_url()
    {
        $url = $this->entrarCon(array('token' => self::TOKEN_MARTA, 'internalpage' => 'list_stic_events'));
        $this->assertSame('marta', $_SESSION['scp_user_id']);
        $this->assertSame('/ap/?internalpage=list_stic_events', $url, 'el token sale de la dirección y el destino se queda');
        $this->assertStringNotContainsString('token', (string) $url);
    }

    public function test_con_la_sesion_de_otra_persona_el_enlace_entra_como_la_del_enlace_y_no_hereda_nada()
    {
        $this->conSesionDeJuan();
        $url = $this->entrarCon(array('token' => self::TOKEN_MARTA));

        $this->assertNotNull($url, 'se ha entrado y se ha limpiado la URL');
        $this->assertSame('marta', $_SESSION['scp_user_id'], 'ahora es la del enlace');
        $this->assertSame('Marta Paradells', $_SESSION['scp_user_contact_name']);
        // Nada de lo de Juan: ni a quién miraba, ni si es miembro, ni su delegación o su sesión del CRM.
        foreach (array('scp_tutor_user_id', 'scp_tutor_user_contact_name', 'scp_tutor_is_user', 'scp_tutor_es_miembro', 'scp_available_profiles', 'scp_pl_delegation', 'scp_pl_deleg_activa', 'api_session_id', 'api_session_time') as $k) {
            $this->assertArrayNotHasKey($k, $_SESSION, "«{$k}» se hereda de la persona anterior");
        }
        $this->assertNotSame('monitor', $_SESSION['scp_role'] ?? '', 'el rol de Juan no se queda');
    }

    public function test_con_la_sesion_de_la_misma_persona_no_se_toca_nada_aunque_estuviera_viendo_a_un_hijo()
    {
        // Marta entró, tiene un hijo y estaba viendo al hijo: el mismo enlace no la devuelve a sí misma.
        $_SESSION = array(
            'scp_user_id' => 'hija-marta',
            'scp_user_contact_name' => 'Lucía',
            'scp_tutor_user_id' => 'marta',
            'scp_tutor_is_user' => false,
            'scp_tutor_es_miembro' => false,
        );
        $antes = $_SESSION;
        $url = $this->entrarCon(array('token' => self::TOKEN_MARTA));

        $this->assertNotNull($url, 'se limpia la URL igualmente');
        $this->assertSame($antes, $_SESSION, 'la sesión queda exactamente como estaba');
    }

    public function test_la_misma_persona_sin_perfil_de_hijo_tampoco_se_toca()
    {
        $_SESSION = array('scp_user_id' => 'marta', 'scp_user_contact_name' => 'Marta Paradells', 'scp_tutor_is_user' => true);
        $antes = $_SESSION;
        $this->entrarCon(array('token' => self::TOKEN_MARTA));
        $this->assertSame($antes, $_SESSION);
    }

    public function test_un_token_que_no_vale_deja_la_sesion_como_estaba()
    {
        $this->conSesionDeJuan();
        $antes = $_SESSION;
        $url = $this->entrarCon(array('token' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'));
        $this->assertNull($url, 'no redirige: el área sigue con quien estaba');
        $this->assertSame($antes, $_SESSION);
    }

    public function test_sin_parametros_de_acceso_no_se_pregunta_nada_al_crm()
    {
        $this->conSesionDeJuan();
        $this->assertNull($this->entrarCon(array('internalpage' => 'list_stic_events')));
        $this->assertSame(array(), $this->crm->logins);
        $this->assertSame(0, $this->crm->detailCalls);
    }

    public function test_un_enlace_magico_de_la_misma_persona_ni_siquiera_va_al_crm()
    {
        $_SESSION = array('scp_user_id' => 'hija-marta', 'scp_tutor_user_id' => 'marta', 'scp_tutor_is_user' => false);
        $antes = $_SESSION;
        parse_str((string) parse_url(sticpa_generate_magic_link('https://x.test/ap/', 'Contacts', 'marta', 3600), PHP_URL_QUERY), $q);
        $url = $this->entrarCon(array('acceso_magico' => $q['acceso_magico']));

        $this->assertNotNull($url);
        $this->assertSame($antes, $_SESSION);
        $this->assertSame(0, $this->crm->detailCalls, 'es la misma persona: nada que preguntar');
    }

    public function test_un_enlace_magico_de_otra_persona_tambien_cambia_la_sesion()
    {
        $this->conSesionDeJuan();
        parse_str((string) parse_url(sticpa_generate_magic_link('https://x.test/ap/', 'Contacts', 'marta', 3600), PHP_URL_QUERY), $q);
        $this->entrarCon(array('acceso_magico' => $q['acceso_magico']));
        $this->assertSame('marta', $_SESSION['scp_user_id']);
        $this->assertArrayNotHasKey('scp_tutor_es_miembro', $_SESSION);
        $this->assertArrayNotHasKey('scp_tutor_user_id', $_SESSION);
    }

    /* ---- La lista de lo que identifica a alguien (cierre de sesión incluido) ---- */

    public function test_el_cierre_de_sesion_y_el_cambio_de_persona_borran_lo_mismo_y_nada_mas()
    {
        $_SESSION = array_fill_keys(sticpa_identity_session_keys(), 'x');
        $_SESSION['sticpa_otp_email'] = 'a@b.c'; // del acceso, no de la persona
        sticpa_clear_identity_session();
        $this->assertSame(array('sticpa_otp_email' => 'a@b.c'), $_SESSION);
    }

    public function test_la_lista_incluye_lo_que_antes_se_heredaba_al_cerrar_sesion()
    {
        $keys = sticpa_identity_session_keys();
        // `scp_tutor_es_miembro` se calcula UNA vez por sesión: sin borrarla, quien entraba después heredaba la respuesta.
        $this->assertContains('scp_tutor_es_miembro', $keys);
        $this->assertContains('scp_pl_delegation', $keys);
        $this->assertContains('scp_user_id', $keys);
        $this->assertContains('scp_tutor_user_id', $keys);
        $this->assertContains('api_session_id', $keys);
    }

    public function test_toda_clave_de_identidad_que_usa_el_codigo_sale_en_la_lista()
    {
        // Si alguien añade un `$_SESSION['scp_…']` nuevo y no lo pone en la lista, esto lo dice (y la siguiente persona lo heredaría).
        $listadas = sticpa_identity_session_keys();
        $sinListar = array();
        foreach (array('inc', 'pages') as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../' . $dir));
            foreach ($it as $f) {
                if (!$f->isFile() || $f->getExtension() !== 'php') {
                    continue;
                }
                if (preg_match_all("/\\\$_SESSION\\['((?:scp_|api_session)[a-z_]*)'\\]/", (string) file_get_contents($f->getPathname()), $m)) {
                    foreach ($m[1] as $k) {
                        if (!in_array($k, $listadas, true)) {
                            $sinListar[$k] = true;
                        }
                    }
                }
            }
        }
        $this->assertSame(array(), array_keys($sinListar), 'claves de sesión sin borrar al cerrar sesión: ' . implode(', ', array_keys($sinListar)));
    }
}
