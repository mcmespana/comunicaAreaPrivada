<?php
use PHPUnit\Framework\TestCase;

/**
 * LO DE FAMILIAS POR EL MIDDLEWARE (06/11/2026): inscripciones, compromisos y
 * pagos que el plugin lee con `getRelatedElementsForLoggedUser()` salen del
 * recurso `vinculo` de comunicaMiddleware cuando está encendido, y por la API
 * en cuanto algo no cuadra (otra persona, un filtro, un campo que no sabe dar,
 * el middleware caído).
 */
class CrmDeFamilias extends SugarRestApiCall
{
    public $llamadas = 0;

    public function __construct()
    {
        // A propósito sin parent::__construct(): aquel abre sesión con el CRM.
    }

    public function call($method, $parameters, $url, $retry = false)
    {
        $this->llamadas++;
        $o = new stdClass();
        $o->entry_list = array((object) array('id' => 'de-la-api', 'name_value_list' => new stdClass()));
        return $o;
    }
}

class MiddlewareFamiliasTest extends TestCase
{
    const SECRET = 'secreto-de-pruebas-con-mas-de-32-caracteres';

    /** Cuerpos que han llegado al middleware de mentira. */
    private $peticiones = array();
    private $caido = false;

    protected function setUp(): void
    {
        $GLOBALS['__stic_transients'] = array();
        $GLOBALS['__stic_options'] = array('sticpa_mw_modo' => 'todos');
        $GLOBALS['__stic_filters'] = array('sticpa_mw_url' => 'https://mw.test', 'sticpa_mw_secret' => self::SECRET);
        unset($GLOBALS['sticpa_mw']);
        $_SESSION = array('scp_user_id' => 'kid-a', 'scp_tutor_user_id' => 'mom');
        $_GET = array();
        $this->peticiones = array();
        $this->caido = false;
        $test = $this;
        $GLOBALS['sticpa_mw_http'] = function ($url, $raw, $headers) use ($test) {
            return $test->servir($url, $raw);
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['sticpa_mw'], $GLOBALS['sticpa_mw_http']);
        $GLOBALS['__stic_filters'] = array();
        $GLOBALS['__stic_options'] = array();
        $_SESSION = array();
    }

    public function servir($url, $raw)
    {
        $this->assertSame('https://mw.test/plugin/v1/lote', $url);
        $body = json_decode($raw, true);
        $this->peticiones[] = $body;
        if ($this->caido) {
            return array('status' => 0, 'body' => '', 'error' => 'Operation timed out');
        }
        $out = array();
        foreach ($body['peticiones'] as $p) {
            $rows = array(
                array('id' => 'reg-1', 'name' => 'Inscripción 1', 'status' => 'confirmed', 'stic_registrations_stic_events_name' => 'Convivencia'),
                array('id' => 'reg-2', 'name' => 'Inscripción 2', 'status' => 'cancelled', 'stic_registrations_stic_events_name' => 'Campamento'),
            );
            $out[$p['clave']] = array('ok' => true, 'rows' => $rows, 'ms' => 1);
        }
        return array('status' => 200, 'body' => json_encode(array('resultados' => $out)), 'error' => '');
    }

    private function params($extra = array())
    {
        return array_merge(array(
            'module_name' => 'Contacts',
            'module_id' => 'kid-a',
            'link_field_name' => 'stic_registrations_contacts',
            'related_fields' => array('id', 'name', 'status', 'stic_registrations_stic_events_name'),
            'related_module_link_name_to_fields_array' => array(),
            'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
        ), $extra);
    }

    public function testLasInscripcionesDeQuienEntraSalenDelMiddlewareConLaFormaDeLaApi()
    {
        $crm = new CrmDeFamilias();
        $rows = $crm->getRelatedElementsForLoggedUser($this->params());

        $this->assertSame(0, $crm->llamadas, 'ni una llamada al CRM');
        $this->assertCount(2, $rows);
        $this->assertSame('reg-1', $rows[0]->id);
        $this->assertSame('Convivencia', $rows[0]->name_value_list->stic_registrations_stic_events_name->value);
        $this->assertSame('cancelled', $rows[1]->name_value_list->status->value);

        $this->assertCount(1, $this->peticiones);
        $body = $this->peticiones[0];
        $this->assertSame('kid-a', $body['persona']);
        // Es de la persona, no de la delegación: va sin delegación de trabajo.
        $this->assertArrayNotHasKey('delegacion', $body);
        $this->assertSame('vinculo', $body['peticiones'][0]['recurso']);
        $this->assertSame(array(
            'enlace' => 'Contacts:stic_registrations_contacts',
            'id' => 'kid-a',
            'campos' => array('id', 'name', 'status', 'stic_registrations_stic_events_name'),
        ), $body['peticiones'][0]['params']);
    }

    public function testLoDeOtraPersonaSigueVaLaApi()
    {
        // Viendo a su hija, la sesión es la hija: lo de la madre va por la API.
        $crm = new CrmDeFamilias();
        $rows = $crm->getRelatedElementsForLoggedUser($this->params(array('module_id' => 'mom', 'limit' => 5)));
        $this->assertSame(1, $crm->llamadas);
        $this->assertSame('de-la-api', $rows[0]->id);
        $this->assertSame(array(), $this->peticiones);
    }

    #[PHPUnit\Framework\Attributes\DataProvider('casosQueSiguenPorLaApi')]
    public function testLoQueNoSabeContestarSigueVaLaApi($extra)
    {
        $this->assertNull(sticpa_mw_related(new CrmDeFamilias(), $this->params($extra)));
        $this->assertSame(array(), $this->peticiones);
    }

    public static function casosQueSiguenPorLaApi()
    {
        return array(
            'un campo que no sabe dar' => array(array('related_fields' => array('id', 'stic_payment_commitments_contacts_1_name'))),
            'un filtro' => array(array('related_module_query' => "stic_registrations.status = 'confirmed'")),
            'un orden' => array(array('order_by' => 'name')),
            'otra página' => array(array('offset' => 20)),
            'enlaces anidados' => array(array('related_module_link_name_to_fields_array' => array(array('name' => 'x', 'value' => array('id'))))),
            'los borrados' => array(array('deleted' => 1)),
            'un enlace que no está en la lista' => array(array('link_field_name' => 'stic_personal_environment_contacts_1')),
            'un id raro' => array(array('module_name' => 'stic_Registrations', 'module_id' => "x' OR 1=1")),
        );
    }

    public function testLoQueCuelgaDeUnaInscripcionOUnCompromisoVaPorElMiddlewareConSuId()
    {
        $rows = sticpa_mw_related(new CrmDeFamilias(), $this->params(array(
            'module_name' => 'stic_Registrations', 'module_id' => 'reg-1', 'link_field_name' => 'stic_registrations_stic_events',
            'related_fields' => array('id'), 'limit' => 1,
        )));
        // El límite se respeta (la portada pide uno).
        $this->assertCount(1, $rows);
        $this->assertSame('stic_Registrations:stic_registrations_stic_events', $this->peticiones[0]['peticiones'][0]['params']['enlace']);
        $this->assertSame('reg-1', $this->peticiones[0]['peticiones'][0]['params']['id']);
    }

    public function testSiElMiddlewareNoContestaSeLeePorLaApi()
    {
        $this->caido = true;
        $crm = new CrmDeFamilias();
        $rows = $crm->getRelatedElementsForLoggedUser($this->params(array('limit' => 5)));
        $this->assertSame('de-la-api', $rows[0]->id);
        $this->assertSame(1, $crm->llamadas);
        // Y en pausa: la siguiente ni se intenta.
        $crm->getRelatedElementsForLoggedUser($this->params(array('limit' => 5)));
        $this->assertCount(1, $this->peticiones);
    }

    public function testApagadoNoSeUsa()
    {
        $GLOBALS['__stic_options']['sticpa_mw_modo'] = 'off';
        $crm = new CrmDeFamilias();
        $crm->getRelatedElementsForLoggedUser($this->params(array('limit' => 5)));
        $this->assertSame(1, $crm->llamadas);
        $this->assertSame(array(), $this->peticiones);
    }

    public function testLosCamposQueSabeDarSonLosDelMiddleware()
    {
        require_once __DIR__ . '/../inc/stic-registrations.php';
        require_once __DIR__ . '/../inc/stic-payments.php';
        // Copia de LINKS en comunicaMiddleware:src/reads/vinculos.ts: si alguien
        // añade un enlace aquí, que lo añada allí (y al revés).
        $this->assertSame(array(
            'Contacts:stic_registrations_contacts',
            'Contacts:stic_payment_commitments_contacts',
            'Contacts:stic_payment_commitments_contacts_1',
            'Contacts:stic_payments_contacts',
            'stic_Payment_Commitments:stic_payments_stic_payment_commitments',
            'stic_Payment_Commitments:stic_payment_commitments_stic_registrations',
            'stic_Registrations:stic_registrations_stic_events',
            'stic_Registrations:stic_payment_commitments_stic_registrations',
        ), array_keys(sticpa_mw_vinculos()));
        // Lo que piden las pantallas de familias cabe en lo que sabe dar.
        $v = sticpa_mw_vinculos();
        $this->assertSame(array(), array_diff(sticpa_registration_list_fields(), $v['Contacts:stic_registrations_contacts']));
        $this->assertSame(array(), array_diff(sticpa_payment_list_fields(), $v['Contacts:stic_payments_contacts']));
        $this->assertSame(array(), array_diff(sticpa_payments_commitment_fields(), $v['Contacts:stic_payment_commitments_contacts']));
        $this->assertSame(array(), array_diff(sticpa_commitment_list_fields(), $v['Contacts:stic_payment_commitments_contacts']));
    }
}
