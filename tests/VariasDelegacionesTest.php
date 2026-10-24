<?php
/**
 * Quien colabora con OTRA delegación (23/10/2026).
 * ----------------------------------------------------------------------------
 * David Soler y Jesús Segarra son de Castellón (su ficha) y coordinan Burriana;
 * David además lleva un grupo allí. La delegación de trabajo se elige en un
 * selector, solo entre las suyas, y la coordinación de una delegación no vale
 * en otra. Lo personal (eventos a los que apuntarse) sigue con la de la ficha.
 *
 * Diseño: docs/comunica/PASAR-LISTA-COORDINACION.md, «Varias delegaciones».
 */

use PHPUnit\Framework\TestCase;

class VariasDelegacionesTest extends TestCase
{
    const CS = 'deleg-castellon';
    const BU = 'deleg-burriana';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../inc/stic-formatter.php';
        require_once __DIR__ . '/../inc/stic-formController.php';
        require_once __DIR__ . '/../inc/stic-comunica-roles.php';
        require_once __DIR__ . '/../inc/stic-record-view.php';
        require_once __DIR__ . '/../inc/stic-events.php';
        require_once __DIR__ . '/../inc/stic-event-audience.php';
        require_once __DIR__ . '/../inc/stic-pasar-lista.php';
        require_once __DIR__ . '/../inc/stic-pasar-lista-crm.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['__stic_filters'] = array();
        $GLOBALS['__stic_transients'] = array();
        $_SESSION = array(
            'scp_user_id' => 'david',
            'scp_user_assigned_user_id' => self::CS,
            'scp_relationship_raw' => '^coordinacion_mic_com^,^monitor^',
            'scp_role_resolved' => true,
        );
        $_GET = array();
    }

    private function rel(array $fields)
    {
        $nvl = new stdClass();
        foreach ($fields as $k => $v) {
            $nvl->$k = (object) array('value' => $v);
        }
        return (object) array('name_value_list' => $nvl);
    }

    /** Las relaciones de David: coordina Castellón y Burriana, y lleva el COM 1 de Burriana. */
    private function scp(array $rels = null)
    {
        $rels = $rels !== null ? $rels : array(
            $this->rel(array('id' => 'r1', 'relationship_type' => 'coordinacion_mic_com', 'assigned_user_id' => self::CS, 'assigned_user_name' => 'MCM Castellón', 'end_date' => '', 'active' => '1', 'ajmcm_etapa_relacion_c' => 'COM')),
            $this->rel(array('id' => 'r2', 'relationship_type' => 'coordinacion_mic_com', 'assigned_user_id' => self::BU, 'assigned_user_name' => 'MCM Burriana', 'end_date' => '', 'active' => '1', 'ajmcm_etapa_relacion_c' => '')),
            $this->rel(array('id' => 'r3', 'relationship_type' => 'monitor', 'assigned_user_id' => self::BU, 'assigned_user_name' => 'MCM Burriana', 'end_date' => '', 'active' => '1', 'ajmcm_grupos_stic_contacts_relationshipsajmcm_grupos_ida' => '')),
        );
        return new VariasDelegacionesFakeScp($rels);
    }

    public function testSinElegirNadaSeTrabajaConLaDeLaFicha()
    {
        $scp = $this->scp();
        $this->assertSame(self::CS, sticpa_pl_delegation($scp));
        $this->assertSame(self::CS, sticpa_pl_home_delegation($scp));
    }

    public function testLasDelegacionesDelEquipoSonLaSuyaYLasDeSusRelacionesDeEquipo()
    {
        $this->assertSame(
            array(self::CS => 'Castellón', self::BU => 'Burriana'),
            sticpa_pl_team_delegations($this->scp())
        );
    }

    public function testSerParticipanteOUnaRelacionCerradaNoAbrenOtraDelegacion()
    {
        $scp = $this->scp(array(
            $this->rel(array('id' => 'p', 'relationship_type' => 'participante_mic_com', 'assigned_user_id' => self::BU, 'assigned_user_name' => 'MCM Burriana', 'end_date' => '', 'active' => '1')),
            $this->rel(array('id' => 'v', 'relationship_type' => 'monitor', 'assigned_user_id' => 'deleg-vilareal', 'assigned_user_name' => 'MCM Vila-real', 'end_date' => '2025-06-30', 'active' => '1')),
            $this->rel(array('id' => 'i', 'relationship_type' => 'monitor', 'assigned_user_id' => 'deleg-onda', 'assigned_user_name' => 'MCM Onda', 'end_date' => '', 'active' => '0')),
        ));
        $this->assertSame(array(self::CS), array_keys(sticpa_pl_team_delegations($scp)));
        $this->assertFalse(sticpa_pl_set_active_delegation($scp, self::BU));
        $this->assertSame(self::CS, sticpa_pl_delegation($scp));
        $this->assertSame('', sticpa_pl_deleg_switch_html($scp, 'single_stic_pasar_lista'));
    }

    public function testElegirBurrianaCambiaLaDeTrabajoPeroNoLaPersonal()
    {
        $scp = $this->scp();
        $this->assertTrue(sticpa_pl_set_active_delegation($scp, self::BU));
        $this->assertSame(self::BU, sticpa_pl_delegation($scp));
        $this->assertSame(self::CS, sticpa_pl_home_delegation($scp));
        // Los eventos a los que puede apuntarse siguen siendo los de Castellón.
        $this->assertSame(self::CS, sticpa_viewer_audience($scp, false)['delegacion']);
        // Volver a la suya.
        $this->assertTrue(sticpa_pl_set_active_delegation($scp, self::CS));
        $this->assertSame(self::CS, sticpa_pl_delegation($scp));
    }

    public function testUnaDelegacionQueNoEsSuyaNoSeAceptaNiPorLaUrl()
    {
        $scp = $this->scp();
        $_GET['delegacion'] = 'deleg-madrid';
        sticpa_pl_maybe_refresh($scp);
        $this->assertSame(self::CS, sticpa_pl_delegation($scp));
        $_GET['delegacion'] = self::BU;
        sticpa_pl_maybe_refresh($scp);
        $this->assertSame(self::BU, sticpa_pl_delegation($scp));
    }

    public function testLaElegidaNoPasaAOtroPerfil()
    {
        $scp = $this->scp();
        sticpa_pl_set_active_delegation($scp, self::BU);
        // Una madre que pasa a ver a su hija: la hija no hereda la delegación de trabajo.
        $_SESSION['scp_user_id'] = 'hija';
        $this->assertSame(self::CS, sticpa_pl_delegation($scp));
    }

    public function testLaCoordinacionDeBurrianaNoHaceCoordinadorDeCastellon()
    {
        // Jesús: monitor en Castellón y solo coordina Burriana.
        $_SESSION['scp_user_id'] = 'jesus';
        $scp = $this->scp(array(
            $this->rel(array('id' => 'm', 'relationship_type' => 'monitor', 'assigned_user_id' => self::CS, 'assigned_user_name' => 'MCM Castellón', 'end_date' => '', 'active' => '1')),
            $this->rel(array('id' => 'c', 'relationship_type' => 'coordinacion_mic_com', 'assigned_user_id' => self::BU, 'assigned_user_name' => 'MCM Burriana', 'end_date' => '', 'active' => '1', 'ajmcm_etapa_relacion_c' => '')),
        ));
        $this->assertNull(sticpa_pl_coord_scope($scp));
        sticpa_pl_set_active_delegation($scp, self::BU);
        $this->assertSame(array('etapa' => '', 'segmento' => ''), sticpa_pl_coord_scope($scp));
    }

    public function testEnCadaDelegacionValeSuCoordinacion()
    {
        $scp = $this->scp();
        $this->assertSame(array('etapa' => 'COM', 'segmento' => ''), sticpa_pl_coord_scope($scp));
        sticpa_pl_set_active_delegation($scp, self::BU);
        $this->assertSame(array('etapa' => '', 'segmento' => ''), sticpa_pl_coord_scope($scp));
    }

    public function testUnaRelacionSinDelegacionSoloCuentaEnLaDeLaFicha()
    {
        $scp = $this->scp(array(
            $this->rel(array('id' => 'c', 'relationship_type' => 'coordinacion_mic_com', 'assigned_user_id' => '', 'end_date' => '', 'active' => '1', 'ajmcm_etapa_relacion_c' => 'MIC')),
            $this->rel(array('id' => 'm', 'relationship_type' => 'monitor', 'assigned_user_id' => self::BU, 'assigned_user_name' => 'MCM Burriana', 'end_date' => '', 'active' => '1')),
        ));
        $this->assertSame(array('etapa' => 'MIC', 'segmento' => ''), sticpa_pl_coord_scope($scp));
        sticpa_pl_set_active_delegation($scp, self::BU);
        $this->assertNull(sticpa_pl_coord_scope($scp));
    }

    public function testElSelectorEnseñaLasDosYMarcaLaActual()
    {
        $scp = $this->scp();
        sticpa_pl_set_active_delegation($scp, self::BU);
        $html = sticpa_pl_deleg_switch_html($scp, 'single_stic_coordinacion');
        $this->assertStringContainsString('Castellón', $html);
        $this->assertStringContainsString('aria-current="page">Burriana</a>', $html);
        $this->assertStringContainsString('internalpage=single_stic_coordinacion', $html);
        $this->assertStringContainsString('delegacion=' . self::CS, $html);
    }

    public function testElCalentadorNoMiraElSelector()
    {
        $scp = $this->scp();
        sticpa_pl_set_active_delegation($scp, self::BU);
        sticpa_pl_delegation_forced('deleg-onda');
        try {
            $this->assertSame('deleg-onda', sticpa_pl_delegation($scp));
        } finally {
            sticpa_pl_delegation_forced('');
        }
    }
}

class VariasDelegacionesFakeScp
{
    private $rels;

    public function __construct(array $rels)
    {
        $this->rels = $rels;
    }

    public function getRelatedElementsForLoggedUser($params)
    {
        return $this->rels;
    }

    public function getRecordDetail($id, $module, $fields = null)
    {
        return new stdClass();
    }

    public function getRecordsModule($module, $query = '', $fields = array())
    {
        return array();
    }

    public function getFieldDefinition($module, $fields)
    {
        return (object) array('module_fields' => array());
    }
}
