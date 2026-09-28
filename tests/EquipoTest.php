<?php

use PHPUnit\Framework\TestCase;

/**
 * EL EQUIPO DE MONITORES (inc/stic-equipo.php).
 * ----------------------------------------------------------------------------
 * Aquí se decide quién ve «Pasar lista», «Mis grupos» y «Coordinación». Es una puerta, y las puertas se prueban por los dos lados: que
 * abra a quien debe y que NO abra a quien no.
 *
 * El fallo que motiva el módulo: la condición era `rol === 'monitor'`, así que
 * quien coordina sin llevar además la marca de monitor no veía ni la puerta.
 * Hoy no le pasa a nadie por casualidad —la única persona con
 * `coordinacion_mic_com` lleva también `monitor`—, y de eso justamente van
 * varios de estos tests.
 */
class EquipoTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../inc/stic-family.php';
        require_once __DIR__ . '/../inc/stic-equipo.php';
    }

    protected function setUp(): void
    {
        $_SESSION = array();
        $GLOBALS['__stic_filters'] = array();
    }

    protected function tearDown(): void
    {
        $GLOBALS['__stic_filters'] = array();
    }

    // -----------------------------------------------------------------
    // Los papeles
    // -----------------------------------------------------------------

    public function test_un_monitor_normal_solo_tiene_el_papel_de_monitor(): void
    {
        // Lo que devuelve DE VERDAD la API: claves, no etiquetas.
        $this->assertSame(array('monitor'), sticpa_equipo_papeles('^grupo^,^monitor^'));
    }

    public function test_un_miembro_sin_nada_no_es_del_equipo(): void
    {
        $this->assertSame(array(), sticpa_equipo_papeles('^grupo^'));
        $this->assertSame(array(), sticpa_equipo_papeles(''));
    }

    public function test_coordinacion_sin_marca_de_monitor_tambien_entra(): void
    {
        // ESTE es el caso que estaba roto: nada de monitor, y aun así coordina.
        $papeles = sticpa_equipo_papeles('^grupo^,^coordinacion_mic_com^');
        $this->assertSame(array('coordinacion'), $papeles);
    }

    public function test_acompanamiento_se_detecta_con_ene_y_sin_ene(): void
    {
        $this->assertSame(array('acompanamiento'), sticpa_equipo_papeles('^acompanamiento_mic_com^'));
        $this->assertSame(array('acompanamiento'), sticpa_equipo_papeles('^acompañamiento_mic_com^'));
    }

    public function test_los_tres_papeles_a_la_vez_y_en_orden_estable(): void
    {
        // El valor REAL de David Soler en el CRM (09/09/2026).
        $papeles = sticpa_equipo_papeles('^acompanamiento_mic_com^,^coordinacion_mic_com^,^grupo^,^monitor^');
        // El orden NO es el del CRM: es el nuestro, porque se pinta en chips.
        $this->assertSame(array('monitor', 'coordinacion', 'acompanamiento'), $papeles);
    }

    public function test_un_ex_no_abre_ninguna_puerta(): void
    {
        // La comparación es por token y por prefijo. Un futuro `ex_coordinacion`
        // o `ex_monitor` NO es coordinación ni monitor: es exactamente el falso
        // positivo por subcadena que ya nos pintó de verde un «no pagado».
        $this->assertSame(array(), sticpa_equipo_papeles('^ex_monitor^,^ex_coordinacion^'));
    }

    public function test_los_papeles_no_se_repiten(): void
    {
        $this->assertSame(
            array('coordinacion'),
            sticpa_equipo_papeles('^coordinacion_mic_com^,^coordinacion^')
        );
    }

    // -----------------------------------------------------------------
    // La puerta
    // -----------------------------------------------------------------

    public function test_un_familiar_viendo_a_su_hijo_no_es_del_equipo(): void
    {
        // Aunque él mismo sea monitor: la sesión está viendo a OTRA persona, y
        // las pantallas de monitor hablan de quien mira.
        $_SESSION['scp_relationship_raw'] = '^grupo^,^monitor^';
        $_SESSION['scp_role'] = 'monitor';
        $_SESSION['scp_role_resolved'] = true;
        $GLOBALS['__stic_filters']['sticpa_profile_audience'] = 'participante';

        $this->assertFalse(sticpa_equipo_es_del_equipo());
    }

    public function test_un_monitor_viendose_a_si_mismo_si_es_del_equipo(): void
    {
        $_SESSION['scp_relationship_raw'] = '^grupo^,^monitor^';
        $_SESSION['scp_role'] = 'monitor';
        $_SESSION['scp_role_resolved'] = true;
        $GLOBALS['__stic_filters']['sticpa_profile_audience'] = 'miembro';

        $this->assertTrue(sticpa_equipo_es_del_equipo());
    }

    public function test_un_miembro_sin_papeles_no_pasa(): void
    {
        $_SESSION['scp_relationship_raw'] = '^grupo^';
        $_SESSION['scp_role'] = '';
        $_SESSION['scp_role_resolved'] = true;
        $GLOBALS['__stic_filters']['sticpa_profile_audience'] = 'miembro';

        $this->assertFalse(sticpa_equipo_es_del_equipo());
    }

    // -----------------------------------------------------------------
    // Las secciones
    // -----------------------------------------------------------------

    /** Prepara la sesión como si esta persona hubiera entrado. */
    private function conRelacion($raw)
    {
        $_SESSION['scp_relationship_raw'] = $raw;
        $_SESSION['scp_role'] = sticpa_detect_role_from_relationship($raw);
        $_SESSION['scp_role_resolved'] = true;
        $GLOBALS['__stic_filters']['sticpa_profile_audience'] = 'miembro';
    }

    public function test_un_monitor_ve_sus_dos_herramientas_y_ninguna_de_coordinacion(): void
    {
        $this->conRelacion('^grupo^,^monitor^');
        $this->assertSame(array(
            'single_stic_pasar_lista',
            'single_stic_mis_grupos',
        ), array_keys(sticpa_equipo_secciones()));
        // Sus datos de monitor van aparte, con «Tu cuenta».
        $this->assertSame(
            array('single_stic_comunica_monitor'),
            array_keys(sticpa_equipo_secciones_de_cuenta())
        );
    }

    public function test_coordinacion_anade_una_sola_entrada(): void
    {
        $this->conRelacion('^grupo^,^monitor^,^coordinacion_mic_com^');
        $claves = array_keys(sticpa_equipo_secciones());
        // Una puerta, no dos: «Monitores» y «Reuniones» eran pasar lista a los
        // monitores y confundían; todo lo de coordinar está en Coordinación.
        $this->assertSame(array(
            'single_stic_pasar_lista',
            'single_stic_mis_grupos',
            'single_stic_coordinacion',
        ), $claves);
        $this->assertNotContains('single_stic_pasar_lista_monitores', $claves);
        $this->assertNotContains('single_stic_pasar_lista_reuniones', $claves);
    }

    public function test_quien_solo_acompana_tambien_tiene_coordinacion(): void
    {
        // Acompañar no es coordinar, pero las fichas y los seguimientos son lo
        // suyo, y están en Coordinación. Lo que no le toca (la lista del
        // sábado, las reuniones) lo esconde la propia pantalla.
        $this->conRelacion('^acompanamiento_mic_com^');
        $claves = array_keys(sticpa_equipo_secciones());
        $this->assertContains('single_stic_coordinacion', $claves);
        // Y sin ser monitor no tiene datos de monitor que rellenar.
        $this->assertSame(array(), sticpa_equipo_secciones_de_cuenta());
    }

    public function test_un_familiar_viendo_a_su_hijo_no_tiene_datos_de_monitor(): void
    {
        $_SESSION['scp_relationship_raw'] = '^grupo^,^monitor^';
        $_SESSION['scp_role'] = 'monitor';
        $_SESSION['scp_role_resolved'] = true;
        $GLOBALS['__stic_filters']['sticpa_profile_audience'] = 'participante';

        $this->assertSame(array(), sticpa_equipo_secciones_de_cuenta());
    }

    // -----------------------------------------------------------------
    // Que se VEA por qué
    // -----------------------------------------------------------------

    public function test_el_chip_de_monitor_no_existe_y_el_de_coordinacion_si(): void
    {
        $this->conRelacion('^grupo^,^monitor^');
        // Un distintivo que lleva todo el mundo no distingue nada.
        $this->assertSame('', sticpa_equipo_chips_html());

        $this->conRelacion('^grupo^,^monitor^,^coordinacion_mic_com^');
        $chips = sticpa_equipo_chips_html();
        $this->assertStringContainsString('Coordinación', $chips);
        $this->assertStringNotContainsString('Monitor/a', $chips);
    }

    public function test_el_papel_que_explica_el_acceso(): void
    {
        $this->conRelacion('^grupo^,^monitor^');
        $this->assertSame('', sticpa_equipo_papel_principal());

        $this->conRelacion('^acompanamiento_mic_com^');
        $this->assertSame('acompanamiento', sticpa_equipo_papel_principal());

        // Las dos cosas: manda coordinación, que es el acceso más amplio.
        $this->conRelacion('^coordinacion_mic_com^,^acompanamiento_mic_com^');
        $this->assertSame('coordinacion', sticpa_equipo_papel_principal());
    }

    // -----------------------------------------------------------------
    // El alcance, dicho en una frase (inc/stic-pasar-lista.php)
    // -----------------------------------------------------------------

    public function test_sin_etapa_ni_segmento_el_alcance_es_la_delegacion(): void
    {
        $this->assertSame(
            'toda la delegación',
            sticpa_pl_coord_scope_label(array('etapa' => '', 'segmento' => ''))
        );
    }

    public function test_el_segmento_no_se_pierde_en_la_etiqueta(): void
    {
        // Antes la home decía «COM» a secas y un coordinador de COM II leía que
        // coordinaba el COM entero.
        $this->assertSame(
            'COM · COM 2',
            sticpa_pl_coord_scope_label(array('etapa' => 'COM', 'segmento' => 'com_2'))
        );
        $this->assertSame(
            'COM',
            sticpa_pl_coord_scope_label(array('etapa' => 'COM', 'segmento' => ''))
        );
    }

    public function test_sin_alcance_no_hay_etiqueta(): void
    {
        $this->assertSame('', sticpa_pl_coord_scope_label(null));
    }

    // -----------------------------------------------------------------
    // El menú de verdad (menu.php)
    // -----------------------------------------------------------------
    //
    // Lo de arriba prueba la decisión; esto prueba que el menú la USA. Son dos
    // cosas distintas y la segunda es la que se rompió: la lógica del rol
    // estaba bien y la condición del menú, escrita a mano al lado, se quedó
    // vieja.

    public function test_el_menu_le_da_a_coordinacion_su_pantalla(): void
    {
        require_once __DIR__ . '/../menu.php';
        $this->conRelacion('^grupo^,^coordinacion_mic_com^');

        list($items, ) = getSticMenuElements();

        // Sin marca de monitor: antes se quedaba sin NADA de esto.
        $this->assertArrayHasKey('single_stic_pasar_lista', $items);
        $this->assertArrayHasKey('single_stic_mis_grupos', $items);
        $this->assertArrayHasKey('single_stic_coordinacion', $items);
        $this->assertSame('Coordinación', $items['single_stic_coordinacion']);
        // Y no se le ofrecen datos de monitor, que no son suyos.
        $this->assertArrayNotHasKey('single_stic_comunica_monitor', $items);
    }

    public function test_el_orden_de_la_barra_va_por_bloques(): void
    {
        require_once __DIR__ . '/../menu.php';
        $this->conRelacion('^grupo^,^monitor^,^coordinacion_mic_com^');

        $claves = array_keys(getSticMenuElements()[0]);

        // Tres bloques que no se mezclan: Actividades (lo de miembro del MCM),
        // Equipo de monitores (todo junto, Coordinación incluida) y Tu perfil.
        $this->assertSame(array(
            'list_stic_events',
            'list_stic_registrations',
            'single_stic_activities_calendar',
            'list_stic_payments',
            'list_stic_documents',
            'single_stic_pasar_lista',
            'single_stic_mis_grupos',
            'single_stic_coordinacion',
            'single_stic_comunica_perfil',
            'single_stic_comunica_monitor',
            'single_stic_password_change',
        ), $claves);
    }

    public function test_los_nombres_dicen_que_hay_dentro(): void
    {
        require_once __DIR__ . '/../menu.php';
        $this->conRelacion('^grupo^,^monitor^');

        list($items, ) = getSticMenuElements();

        // «Monitor/a» era el nombre de un papel, no de una pantalla.
        $this->assertSame('Mis datos de monitor', $items['single_stic_comunica_monitor']);
        $this->assertSame('Grupos y fichas', $items['single_stic_mis_grupos']);
        $this->assertSame('Usuario y contraseña', $items['single_stic_password_change']);
        $this->assertNotContains('Monitor/a', $items);
    }

    public function test_en_el_movil_los_datos_de_monitor_van_con_la_cuenta(): void
    {
        require_once __DIR__ . '/../menu.php';
        $this->conRelacion('^grupo^,^monitor^,^coordinacion_mic_com^');

        $claves = array_merge(array('single_stic_home'), array_keys(getSticMenuElements()[0]));
        $layout = sticpa_nav_layout($claves);
        $orden = function ($k) use ($layout) { return $layout['items'][$k]['order']; };

        // Mismo bloque (centena) que «Mis datos», distinto que «Pasar lista».
        $this->assertSame(
            intdiv($orden('single_stic_comunica_perfil'), 100),
            intdiv($orden('single_stic_comunica_monitor'), 100)
        );
        $this->assertSame(
            intdiv($orden('single_stic_pasar_lista'), 100),
            intdiv($orden('single_stic_coordinacion'), 100)
        );
        $this->assertLessThan($orden('single_stic_pasar_lista'), $orden('list_stic_documents'));
        $this->assertLessThan($orden('single_stic_comunica_perfil'), $orden('single_stic_coordinacion'));
    }

    public function test_el_menu_de_un_miembro_normal_no_tiene_nada_de_monitor(): void
    {
        require_once __DIR__ . '/../menu.php';
        $this->conRelacion('^grupo^');

        list($items, ) = getSticMenuElements();

        $this->assertArrayNotHasKey('single_stic_pasar_lista', $items);
        $this->assertArrayNotHasKey('single_stic_coordinacion', $items);
        $this->assertArrayNotHasKey('single_stic_comunica_monitor', $items);
        // Lo suyo sigue estando.
        $this->assertArrayHasKey('list_stic_events', $items);
    }
}
