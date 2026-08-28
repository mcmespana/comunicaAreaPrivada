<?php

use PHPUnit\Framework\TestCase;

/**
 * El ensamblado de la respuesta de `get_relationships`.
 *
 * POR QUÉ ESTE ARCHIVO EXISTE. Pasar Lista salía con listas VACÍAS en
 * producción (un grupo con participantes decía "0 participantes") mientras los
 * 175 tests estaban en verde. La causa: `get_relationships` de la API v4.1 no
 * devuelve los enlaces dentro de cada registro, los devuelve en
 * `relationship_list`, un HERMANO de `entry_list` indexado en paralelo — y el
 * transporte se quedaba solo con `entry_list`, tirando a la basura todo lo
 * pedido en `related_module_link_name_to_fields_array`. Sin error: 200, los
 * registros llegan, pero pelados.
 *
 * Los tests no lo cazaron porque el doble de $objSCP colgaba los enlaces
 * directamente del registro, o sea que mentía sobre la forma de la respuesta.
 * Aquí se prueba contra la forma REAL, y con el transporte de verdad.
 */
class TransportLinkListTest extends TestCase
{
    /** Un registro de `entry_list`, pelado. */
    private function entry($id)
    {
        $row = new stdClass();
        $row->name_value_list = new stdClass();
        $row->name_value_list->id = (object) array('name' => 'id', 'value' => $id);
        return $row;
    }

    /** La entrada equivalente de `relationship_list`, con el enlace poblado. */
    private function linked($contactId)
    {
        $lv = new stdClass();
        $lv->id = (object) array('name' => 'id', 'value' => $contactId);
        $link = new stdClass();
        $link->records = array((object) array('link_value' => $lv));
        return (object) array('link_list' => array($link));
    }

    public function testPegaLosEnlacesPorPosicion()
    {
        $out = SugarRestApiCall::attachLinkList(
            array($this->entry('r1'), $this->entry('r2')),
            array($this->linked('c1'), $this->linked('c2'))
        );

        $this->assertSame('c1', $out[0]->link_list[0]->records[0]->link_value->id->value);
        $this->assertSame('c2', $out[1]->link_list[0]->records[0]->link_value->id->value);
    }

    public function testSinEnlacesDevuelveLaListaTalCual()
    {
        $entries = array($this->entry('r1'));
        // Es el caso de casi todo el plugin, que pide el array de enlaces vacío.
        $this->assertSame($entries, SugarRestApiCall::attachLinkList($entries, array()));
        $this->assertSame($entries, SugarRestApiCall::attachLinkList($entries, null));
        $this->assertNull(SugarRestApiCall::attachLinkList(null, null));
    }

    /**
     * Si las dos listas no vinieran alineadas, colgarle a alguien el enlace de
     * otro sería PEOR que no tener enlace: saldría el nombre equivocado en una
     * lista de asistencia. Así que la posición que falta se queda sin enlace.
     */
    public function testUnaPosicionSinEnlaceSeQuedaSinEnlace()
    {
        $out = SugarRestApiCall::attachLinkList(
            array($this->entry('r1'), $this->entry('r2')),
            array($this->linked('c1'), new stdClass())
        );

        $this->assertSame('c1', $out[0]->link_list[0]->records[0]->link_value->id->value);
        $this->assertFalse(isset($out[1]->link_list));
    }

    /**
     * LA PAGINACIÓN, QUE ES LO QUE DEJÓ A C1 SIN PARTICIPANTES.
     *
     * `max_results = 0` no es «sin límite»: SuiteCRM lo ignora y aplica su
     * propio tope (20 por defecto). La delegación tiene 109 relaciones y
     * llegaban las 20 primeras; los grupos que caían fuera salían con cero
     * participantes, igual que un grupo vacío de verdad.
     */
    public function testTraeTodasLasPaginasAunqueElServidorLasCorte()
    {
        $scp = new FakeTransport();
        $scp->todos = array();
        for ($i = 1; $i <= 109; $i++) {
            $scp->todos[] = 'r' . $i;
        }
        $scp->topePagina = 20;   // el servidor no da más de 20, pidas lo que pidas

        $rows = $scp->getRecordsModule('stic_Contacts_Relationships', "q", array('id'));

        $this->assertCount(109, $rows, 'tienen que llegar las 109, no las 20 primeras');
        $this->assertSame('r109', $rows[108]->name_value_list->id->value);
        // Seis páginas de 20: 109 filas no caben en menos.
        $this->assertGreaterThanOrEqual(6, count($scp->llamadas));
    }

    /** Y lo mismo por `get_relationships`, que tiene su propio camino. */
    public function testGetRelationshipsTambienPagina()
    {
        $scp = new FakeTransport();
        $scp->todos = array();
        for ($i = 1; $i <= 45; $i++) {
            $scp->todos[] = 'x' . $i;
        }
        $scp->topePagina = 20;

        $rows = $scp->getRelatedElementsForLoggedUser(array(
            'module_name' => 'ajmcm_GRUPOS', 'module_id' => 'g1',
            'link_field_name' => 'ajmcm_grupos_stic_contacts_relationships',
            'related_fields' => array('id'),
            'related_module_link_name_to_fields_array' => array(),
            'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
        ));

        $this->assertCount(45, $rows);
    }

    /**
     * Sin `total_count` también funciona: se para cuando llega una página
     * vacía. Hay instancias que no lo mandan.
     */
    public function testSinTotalCountParaCuandoSeAcaban()
    {
        $scp = new FakeTransport();
        $scp->todos = array('a', 'b', 'c', 'd', 'e');
        $scp->topePagina = 2;
        $scp->conTotal = false;

        $rows = $scp->getRecordsModule('ajmcm_GRUPOS', '', array('id'));
        $this->assertCount(5, $rows);
    }

    /**
     * Y si el servidor IGNORA el offset y contesta siempre lo mismo, se para en
     * vez de girar hasta el tope. Sin este seguro, un servidor así costaría
     * veinticinco llamadas idénticas por pantalla.
     */
    public function testUnServidorQueNoAvanzaNoHaceGirarElBucle()
    {
        $scp = new FakeTransport();
        $scp->canned = (object) array(
            'entry_list' => array($this->entry('r1'), $this->entry('r2')),
            'relationship_list' => array($this->linked('c1'), $this->linked('c2')),
        );

        $rows = $scp->getRecordsModule('ajmcm_GRUPOS', '', array('id'));

        $this->assertCount(2, $rows, 'las dos filas, sin repetirlas');
        $this->assertLessThanOrEqual(2, count($scp->llamadas), 'y sin girar');
    }

    /**
     * El de verdad: que el MÉTODO DEL TRANSPORTE junte las dos listas. Sin
     * esto, `attachLinkList` podría estar perfecta y no usarse — que es
     * exactamente el fallo que había.
     */
    public function testElTransporteDevuelveLosEnlacesYaPegados()
    {
        $scp = new FakeTransport();
        $scp->canned = (object) array(
            'entry_list' => array($this->entry('r1')),
            'relationship_list' => array($this->linked('c1')),
        );

        $rows = $scp->getRelatedElementsForLoggedUser(array(
            'module_name' => 'ajmcm_GRUPOS',
            'module_id' => 'g1',
            'link_field_name' => 'ajmcm_grupos_stic_contacts_relationships',
            'related_fields' => array('id'),
            'related_module_link_name_to_fields_array' => array(
                array('name' => 'stic_contacts_relationships_contacts', 'value' => array('id')),
            ),
            'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
        ));

        $this->assertCount(1, $rows);
        $this->assertSame('c1', $rows[0]->link_list[0]->records[0]->link_value->id->value);
    }

    // -----------------------------------------------------------------------
    // El aplanado de enlaces de get_entry_list (getRecordsModule)
    // -----------------------------------------------------------------------

    /** Un enlace con nombre y un registro dentro. */
    private function namedLink($linkName, $recordName)
    {
        $lv = new stdClass();
        $lv->name = (object) array('name' => 'name', 'value' => $recordName);
        $link = new stdClass();
        $link->name = $linkName;
        $link->records = array((object) array('link_value' => $lv));
        return $link;
    }

    /**
     * El bug de datos cruzados: se leia SIEMPRE link_list[0], asi que pidiendo
     * dos enlaces los dos campos recibian el valor del primero. En la pantalla
     * de resumen eso significaba que "el grupo" y "la persona" salian iguales.
     */
    public function testCadaCampoRecibeSuPropioEnlace()
    {
        $entry = $this->entry('r1');
        $out = SugarRestApiCall::flattenRelationshipFields(
            array($entry),
            array((object) array('link_list' => array(
                $this->namedLink('ajmcm_grupos_stic_contacts_relationships', 'C1'),
                $this->namedLink('stic_contacts_relationships_contacts', 'Solete Vilarroya'),
            ))),
            array(
                'grupo' => array('relationshipName' => 'ajmcm_grupos_stic_contacts_relationships'),
                'persona' => array('relationshipName' => 'stic_contacts_relationships_contacts'),
            )
        );

        $this->assertSame('C1', $out[0]->name_value_list->grupo->value);
        $this->assertSame('Solete Vilarroya', $out[0]->name_value_list->persona->value);
    }

    /**
     * El caso que la pantalla quiere DETECTAR: una relacion sin grupo. Antes
     * reventaba en avisos de PHP encadenados; ahora es cadena vacia, que es el
     * dato correcto y lo que hace que la persona salga en "datos por revisar".
     */
    public function testEnlaceAusenteEsCadenaVaciaYNoUnAviso()
    {
        $out = SugarRestApiCall::flattenRelationshipFields(
            array($this->entry('r7')),
            array((object) array('link_list' => array(
                $this->namedLink('stic_contacts_relationships_contacts', 'Sol Messeguer'),
            ))),
            array(
                'grupo' => array('relationshipName' => 'ajmcm_grupos_stic_contacts_relationships'),
                'persona' => array('relationshipName' => 'stic_contacts_relationships_contacts'),
            )
        );

        $this->assertSame('', $out[0]->name_value_list->grupo->value);
        $this->assertSame('Sol Messeguer', $out[0]->name_value_list->persona->value);
    }

    /** Sin relationship_list ninguno: todos los campos vacios, sin morir. */
    public function testSinRelationshipListNoRevienta()
    {
        $out = SugarRestApiCall::flattenRelationshipFields(
            array($this->entry('r1')),
            null,
            array('grupo' => array('relationshipName' => 'ajmcm_grupos_stic_contacts_relationships'))
        );
        $this->assertSame('', $out[0]->name_value_list->grupo->value);
    }

    /**
     * Pasar el nombre del enlace a pelo en vez del array documentado era un
     * TypeError FATAL en PHP 8 ("Cannot access offset of type string on
     * string"): se llevaba la pantalla entera, no solo el campo.
     */
    public function testElNombreDelEnlaceAPeloNoEsUnFatal()
    {
        $out = SugarRestApiCall::flattenRelationshipFields(
            array($this->entry('r1')),
            array((object) array('link_list' => array(
                $this->namedLink('ajmcm_grupos_stic_contacts_relationships', 'C1'),
            ))),
            array('grupo' => 'ajmcm_grupos_stic_contacts_relationships')
        );
        $this->assertSame('C1', $out[0]->name_value_list->grupo->value);
    }

    /** Un contacto sin `name` se compone con nombre y apellidos. */
    public function testNombreCompuestoCuandoNoHayCampoName()
    {
        $lv = new stdClass();
        $lv->first_name = (object) array('name' => 'first_name', 'value' => 'Solete');
        $lv->last_name = (object) array('name' => 'last_name', 'value' => 'Vilarroya');
        $link = new stdClass();
        $link->name = 'stic_contacts_relationships_contacts';
        $link->records = array((object) array('link_value' => $lv));

        $out = SugarRestApiCall::flattenRelationshipFields(
            array($this->entry('r1')),
            array((object) array('link_list' => array($link))),
            array('persona' => array('relationshipName' => 'stic_contacts_relationships_contacts'))
        );
        $this->assertSame('Solete Vilarroya', $out[0]->name_value_list->persona->value);
    }

    /** Un bloque de enlace SIN nombre, con un registro dentro. */
    private function unnamedLink($recordName, $recordId)
    {
        $lv = new stdClass();
        $lv->id = (object) array('name' => 'id', 'value' => $recordId);
        $lv->name = (object) array('name' => 'name', 'value' => $recordName);
        $link = new stdClass();
        // A proposito SIN ->name: es lo que hace la instancia real.
        $link->records = array((object) array('link_value' => $lv));
        return $link;
    }

    /**
     * EL BUG QUE LO EXPLICABA TODO. Pidiendo DOS enlaces, si la API no dice de
     * cual es cada bloque, se cogia el PRIMERO para los dos campos: «grupo» se
     * quedaba con el id de la PERSONA. No coincidia con ningun grupo, la
     * consulta de una sola llamada se daba por fallida, y todo caia a los
     * respaldos de 1+N llamadas. De ahi la lentitud.
     *
     * Sin nombres, el orden de `link_list` es el orden en que se pidieron.
     */
    public function testDosEnlacesSinNombreSeResuelvenPorPosicion()
    {
        $out = SugarRestApiCall::flattenRelationshipFields(
            array($this->entry('r1')),
            array((object) array('link_list' => array(
                $this->unnamedLink('C1', 'g1'),
                $this->unnamedLink('Solete Vilarroya', 'c1'),
            ))),
            array(
                'grupo' => array('relationshipName' => 'ajmcm_grupos_stic_contacts_relationships'),
                'persona' => array('relationshipName' => 'stic_contacts_relationships_contacts'),
            )
        );

        $v = $out[0]->name_value_list;
        $this->assertSame('g1', $v->grupo_id->value, 'el grupo NO puede llevarse el id de la persona');
        $this->assertSame('C1', $v->grupo->value);
        $this->assertSame('c1', $v->persona_id->value);
        $this->assertSame('Solete Vilarroya', $v->persona->value);
    }

    /** Con nombres, manda el nombre y el orden da igual. */
    public function testConNombresElOrdenDaIgual()
    {
        $out = SugarRestApiCall::flattenRelationshipFields(
            array($this->entry('r1')),
            array((object) array('link_list' => array(
                // Al reves de como se piden.
                $this->namedLink('stic_contacts_relationships_contacts', 'Solete Vilarroya'),
                $this->namedLink('ajmcm_grupos_stic_contacts_relationships', 'C1'),
            ))),
            array(
                'grupo' => array('relationshipName' => 'ajmcm_grupos_stic_contacts_relationships'),
                'persona' => array('relationshipName' => 'stic_contacts_relationships_contacts'),
            )
        );

        $v = $out[0]->name_value_list;
        $this->assertSame('C1', $v->grupo->value);
        $this->assertSame('Solete Vilarroya', $v->persona->value);
    }

    /**
     * Y si hay nombres pero el nuestro no esta, el campo se queda VACIO: no se
     * cae a la posicion. Colgarle a un campo el valor de otro enlace es
     * exactamente el fallo que se acaba de arreglar.
     */
    public function testConNombresElQueNoEstaSeQuedaVacio()
    {
        $out = SugarRestApiCall::flattenRelationshipFields(
            array($this->entry('r1')),
            array((object) array('link_list' => array(
                $this->namedLink('stic_contacts_relationships_contacts', 'Solete Vilarroya'),
            ))),
            array(
                'grupo' => array('relationshipName' => 'ajmcm_grupos_stic_contacts_relationships'),
                'persona' => array('relationshipName' => 'stic_contacts_relationships_contacts'),
            )
        );

        $v = $out[0]->name_value_list;
        $this->assertSame('', $v->grupo->value);
        $this->assertSame('', $v->grupo_id->value);
        $this->assertSame('Solete Vilarroya', $v->persona->value);
    }
}

/**
 * El cliente del CRM con la llamada de red sustituida. El constructor de la
 * clase real es privado y abre sesión; este NO llama al padre a propósito, así
 * que no hay red ni sesión, solo el método que se quiere probar.
 */
class FakeTransport extends SugarRestApiCall
{
    public $canned;

    /**
     * Un CRM QUE PAGINA, que es lo que hace el de verdad.
     *
     * Se le da una lista completa de ids y un tope de página, y contesta como
     * SuiteCRM: la porción que toca según el `offset`, `total_count` con el
     * total, y —lo importante— **ignorando un `max_results` mayor que su propio
     * tope**. Ese detalle es el que convirtió «pedimos todo» en «nos dan 20»:
     * `max_results = 0` era falsy y el servidor aplicaba su límite.
     */
    public $todos = null;      // array de ids
    public $topePagina = 20;   // lo que el servidor da como mucho
    public $conTotal = true;   // ¿manda `total_count`?
    public $llamadas = array();

    public function __construct()
    {
        // A propósito vacío: ver arriba.
    }

    public function call($method, $parameters, $url, $retry = false)
    {
        $this->llamadas[] = array(
            'method' => $method,
            'offset' => isset($parameters['offset']) ? (int) $parameters['offset'] : 0,
            'pide' => isset($parameters['max_results'])
                ? (int) $parameters['max_results']
                : (isset($parameters['limit']) ? (int) $parameters['limit'] : 0),
        );

        if ($this->todos === null) {
            return $this->canned;
        }

        $offset = isset($parameters['offset']) ? (int) $parameters['offset'] : 0;
        $pide = isset($parameters['max_results'])
            ? (int) $parameters['max_results']
            : (isset($parameters['limit']) ? (int) $parameters['limit'] : 0);
        // El servidor manda: nunca da más de su tope, pidas lo que pidas.
        $cuantos = ($pide > 0) ? min($pide, $this->topePagina) : $this->topePagina;

        $trozo = array_slice($this->todos, $offset, $cuantos);
        $res = new stdClass();
        $res->entry_list = array();
        $res->relationship_list = array();
        foreach ($trozo as $id) {
            $fila = new stdClass();
            $fila->name_value_list = new stdClass();
            $fila->name_value_list->id = (object) array('name' => 'id', 'value' => $id);
            $res->entry_list[] = $fila;
            $res->relationship_list[] = new stdClass();
        }
        if ($this->conTotal) {
            $res->total_count = count($this->todos);
        }
        return $res;
    }
}
