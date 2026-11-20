<?php
/**
 * MONITOR SOLO DE GRUPOS QUE NO PASAN LISTA (20/11/2026).
 * ----------------------------------------------------------------------------
 * Hay monitores que llevan un grupo de adultos (Comser) o de Apoyo —dados de
 * alta como monitores para el seguro—, o que están en formación: tienen la
 * relación de monitor, pero no son monitores del día a día y no tienen por qué
 * ver fichas de chavales, grupos ni asistencias. La casilla «entra en Pasar
 * Lista» del grupo ya dice cuáles son del sistema, y por ella se decide.
 *
 * Es el ESPEJO de `monitorOutsideRollCall` del middleware
 * (comunicaMiddleware:src/authz/principal.ts, test/principal.test.ts y
 * test/mariadb-rollcall.test.ts): lo que sale de aquí tiene que salir igual allí,
 * porque la app lee por el middleware y la web, por aquí. Los casos están a
 * propósito en el mismo orden.
 *
 * Y ante la duda, NO se quita nada: es lo que había.
 */

use PHPUnit\Framework\TestCase;

class MonitorFueraDePasarListaTest extends TestCase
{
    const CS = 'deleg-castellon';
    const BU = 'deleg-burriana';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../inc/stic-formatter.php';
        require_once __DIR__ . '/../inc/stic-formController.php';
        require_once __DIR__ . '/../inc/stic-comunica-roles.php';
        require_once __DIR__ . '/../inc/stic-family.php';
        require_once __DIR__ . '/../inc/stic-pasar-lista.php';
        require_once __DIR__ . '/../inc/stic-pasar-lista-crm.php';
        require_once __DIR__ . '/../inc/stic-equipo.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['__stic_filters'] = array();
        $GLOBALS['__stic_transients'] = array();
        $GLOBALS['__stic_options'] = array();
        $_GET = array();
        $_SESSION = array(
            'scp_user_id' => 'mon',
            'scp_user_assigned_user_id' => self::CS,
            'scp_relationship_raw' => '^grupo^,^monitor^',
            'scp_role' => 'monitor',
            'scp_role_resolved' => true,
        );
    }

    protected function tearDown(): void
    {
        SugarRestApiCall::$objSCP = null;
    }

    private function rel($id, $tipo, $grupo = '', array $extra = array())
    {
        $f = array_merge(array(
            'id' => $id, 'relationship_type' => $tipo, 'assigned_user_id' => self::CS, 'assigned_user_name' => 'MCM Castellón',
            'end_date' => '', 'active' => '1',
            'ajmcm_grupos_stic_contacts_relationshipsajmcm_grupos_ida' => $grupo,
        ), $extra);
        $nvl = new stdClass();
        foreach ($f as $k => $v) {
            $nvl->$k = (object) array('value' => $v);
        }
        return (object) array('name_value_list' => $nvl);
    }

    /**
     * Castellón: C1 pasa lista; Comser y Apoyo no; el comodín tampoco. Burriana: ninguno marcado.
     * @param array $marcados ids de los grupos de Castellón que pasan lista
     */
    private function scp(array $rels, array $marcados = array('g-c1'), $deleg = self::CS)
    {
        $scp = new MonitorFueraFakeScp($rels, $marcados, $deleg);
        SugarRestApiCall::$objSCP = $scp;
        return $scp;
    }

    private function fuera($scp)
    {
        return sticpa_pl_monitor_fuera_de_pasar_lista($scp);
    }

    public function testMonitorSoloDeUnGrupoQueNoPasaListaNoEsMonitor()
    {
        $scp = $this->scp(array($this->rel('r1', 'monitor', 'g-comser')));
        $this->assertTrue($this->fuera($scp));
        // Aunque la ficha lleve la marca: lo que cuenta es dónde está.
        $this->assertSame(array(), sticpa_equipo_papeles());
        $this->assertFalse(sticpa_equipo_es_del_equipo());
    }

    public function testMonitorDeUnGrupoQuePasaListaLoEs()
    {
        $scp = $this->scp(array($this->rel('r1', 'monitor', 'g-c1')));
        $this->assertFalse($this->fuera($scp));
        $this->assertSame(array('monitor'), sticpa_equipo_papeles());
    }

    public function testConUnGrupoDeCadaClaseBastaUnoQuePasaLista()
    {
        $scp = $this->scp(array($this->rel('r1', 'monitor', 'g-comser'), $this->rel('r2', 'monitor', 'g-c1')));
        $this->assertFalse($this->fuera($scp));
    }

    public function testUnaRelacionTerminadaEnUnGrupoQuePasaListaNoLoSalva()
    {
        $scp = $this->scp(array(
            $this->rel('r1', 'monitor', 'g-c1', array('end_date' => '2025-08-31')),
            $this->rel('r2', 'monitor', 'g-comser'),
        ));
        $this->assertTrue($this->fuera($scp));
    }

    public function testSiNoHayNingunGrupoMarcadoNoSeEscondeNada()
    {
        // La regla de seguridad de siempre (como sticpa_pl_groups): casi todas las delegaciones están así hoy.
        $scp = $this->scp(array($this->rel('r1', 'monitor', 'g-comser')), array());
        $this->assertFalse($this->fuera($scp));
        $this->assertSame(array('monitor'), sticpa_equipo_papeles());
    }

    public function testSinGrupoDeVerdadNoSeJuzga()
    {
        $this->assertFalse($this->fuera($this->scp(array($this->rel('r1', 'monitor', '')))));
        $this->setUp();
        // El comodín «POR DEFINIR» es no tener grupo: es lo que coordinación tiene que vincular.
        $this->assertFalse($this->fuera($this->scp(array($this->rel('r1', 'monitor', 'g-pordef')))));
    }

    public function testUnGrupoQueNoEsDeLaDelegacionDeTrabajoNoSeJuzga()
    {
        $this->assertFalse($this->fuera($this->scp(array($this->rel('r1', 'monitor', 'g-de-otra-delegacion')))));
    }

    public function testLaMarcaDeLaFichaSinRelacionesDeMonitorSigueAbriendoLaPuerta()
    {
        $scp = $this->scp(array());
        $this->assertFalse($this->fuera($scp));
        $this->assertSame(array('monitor'), sticpa_equipo_papeles());
    }

    public function testCoordinacionNoSeTocaYQuienCoordinaYSoloLlevaApoyoCoordina()
    {
        $_SESSION['scp_relationship_raw'] = '^grupo^,^monitor^,^coordinacion_mic_com^';
        $this->scp(array($this->rel('r1', 'monitor', 'g-apoyo'), $this->rel('r2', 'coordinacion_mic_com', '')));
        $this->assertSame(array('coordinacion'), sticpa_equipo_papeles());
        $this->assertTrue(sticpa_equipo_es_del_equipo());
    }

    public function testSeDecideEnLaDelegacionDeTrabajo()
    {
        // Monitor de Apoyo en Castellón, pero con un grupo que pasa lista en Burriana.
        $rels = array(
            $this->rel('r1', 'monitor', 'g-apoyo'),
            $this->rel('r2', 'monitor', 'g-bur', array('assigned_user_id' => self::BU, 'assigned_user_name' => 'MCM Burriana')),
        );
        $scp = $this->scp($rels, array('g-c1'));
        $this->assertTrue($this->fuera($scp));
        $scp = $this->scp($rels, array('g-bur'), self::BU);
        $_SESSION['scp_pl_deleg_activa'] = self::BU;
        $_SESSION['scp_pl_deleg_activa_de'] = 'mon';
        $this->assertFalse($this->fuera($scp));
    }

    public function testConUnRawExplicitoLaFuncionSigueSiendoPura()
    {
        // Sin tocar el CRM: los tests de la puerta y quien pregunta por un texto concreto no cambian.
        $this->scp(array($this->rel('r1', 'monitor', 'g-comser')));
        $this->assertSame(array('monitor'), sticpa_equipo_papeles('^grupo^,^monitor^'));
    }

    public function testLoQueNoSeSabeNoEsconde()
    {
        // Sin caché de grupos (de antes de existir esto, o el CRM no contestó): no se juzga.
        $scp = $this->scp(array($this->rel('r1', 'monitor', 'g-comser')));
        $scp->sinGrupos = true;
        $this->assertFalse($this->fuera($scp));
    }

    public function testElMenuLoRecuerdaYNoVuelveAPreguntar()
    {
        $scp = $this->scp(array($this->rel('r1', 'monitor', 'g-comser')));
        $this->assertSame(array(), sticpa_equipo_papeles());
        $llamadas = $scp->llamadas;
        $this->assertGreaterThan(0, $llamadas);
        // El menú pregunta varias veces por página: ni una llamada más.
        sticpa_equipo_papeles();
        sticpa_equipo_es_del_equipo();
        $this->assertSame($llamadas, $scp->llamadas);
        // Y la respuesta se pierde con el tiempo (por si cambia la casilla).
        $GLOBALS['__stic_pl_now'] = time() + 3600;
        $this->assertSame(array(), sticpa_equipo_papeles());
        unset($GLOBALS['__stic_pl_now']);
    }

    public function testQuienNoEsMonitorNoCuestaNada()
    {
        // Las familias pintan el mismo menú: sin la marca de monitor, ni una llamada al CRM.
        $_SESSION['scp_relationship_raw'] = '^grupo^';
        $scp = $this->scp(array($this->rel('r1', 'monitor', 'g-comser')));
        $this->assertSame(array(), sticpa_equipo_papeles());
        $this->assertSame(0, $scp->llamadas);
    }
}

/** El CRM de pruebas: sus relaciones, y los grupos de Castellón con la casilla marcada solo en los que se digan. */
class MonitorFueraFakeScp
{
    public $llamadas = 0;
    public $sinGrupos = false;
    private $rels;
    private $marcados;
    private $deleg;

    public function __construct(array $rels, array $marcados, $deleg)
    {
        $this->rels = $rels;
        $this->marcados = $marcados;
        $this->deleg = $deleg;
    }

    public function getRelatedElementsForLoggedUser($params)
    {
        $this->llamadas++;
        return $this->rels;
    }

    private function grupo($id, $nombre, $code)
    {
        $v = new stdClass();
        foreach (array('id' => $id, 'name' => $nombre, 'code' => $code, 'level' => 'COM', 'cursos_c' => '',
            'ajmcm_pasar_lista_c' => in_array($id, $this->marcados, true) ? '1' : '0') as $k => $val) {
            $v->$k = (object) array('value' => $val);
        }
        return (object) array('name_value_list' => $v);
    }

    public function getRecordsModule($module, $query = '', $fields = array())
    {
        $this->llamadas++;
        if ($module !== 'ajmcm_GRUPOS' || $this->sinGrupos) {
            return array();
        }
        if ($this->deleg === MonitorFueraDePasarListaTest::BU) {
            return array($this->grupo('g-bur', 'Burriana 1', 'B1'));
        }
        return array(
            $this->grupo('g-c1', 'Los Peques', 'C1'),
            $this->grupo('g-comser', 'Comser', ''),
            $this->grupo('g-apoyo', 'Apoyo', ''),
            $this->grupo('g-pordef', '⚠️ Grupo monitoreado - POR DEFINIR!', ''),
        );
    }

    public function getRecordDetail($id, $module, $fields = null)
    {
        $this->llamadas++;
        return new stdClass();
    }

    public function getFieldDefinition($module, $fields)
    {
        $this->llamadas++;
        return (object) array('module_fields' => array());
    }
}
