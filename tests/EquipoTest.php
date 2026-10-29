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

        // Tres bloques que no se mezclan: Equipo de monitores (todo junto,
        // Coordinación incluida), Actividades (lo de miembro del MCM) y Tu
        // perfil. Para el equipo, sus herramientas PRIMERO (plan 043, CAR-1):
        // pasa lista todos los sábados y se apunta a algo tres veces al curso.
        $this->assertSame(array(
            'single_stic_pasar_lista',
            'single_stic_mis_grupos',
            'single_stic_coordinacion',
            'list_stic_events',
            'list_stic_registrations',
            'single_stic_activities_calendar',
            'list_stic_payments',
            'list_stic_documents',
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
        // Y el bloque del equipo, delante de Actividades (CAR-1): el mismo
        // orden en el desplegable del móvil que en la barra y en la portada.
        $this->assertLessThan($orden('list_stic_events'), $orden('single_stic_coordinacion'));
        $this->assertLessThan($orden('single_stic_comunica_perfil'), $orden('list_stic_documents'));
        $this->assertLessThan($orden('single_stic_pasar_lista'), $orden('single_stic_home'));
        $grupos = array_column($layout['groups'], 'label');
        $this->assertSame(array('', 'Equipo de monitores', 'Actividades', 'Tu perfil'), $grupos);
    }

    /**
     * CAR-1, el otro lado de la puerta: quien NO es del equipo ve exactamente
     * el orden de siempre, en la barra y en el desplegable del móvil.
     */
    public function test_sin_papel_de_equipo_el_orden_no_cambia(): void
    {
        require_once __DIR__ . '/../menu.php';
        $this->conRelacion('^grupo^');

        $claves = array_keys(getSticMenuElements()[0]);
        $this->assertSame(array(
            'list_stic_events',
            'list_stic_registrations',
            'single_stic_activities_calendar',
            'list_stic_payments',
            'list_stic_documents',
            'single_stic_comunica_perfil',
            'single_stic_password_change',
        ), $claves);

        $layout = sticpa_nav_layout(array_merge(array('single_stic_home'), $claves));
        $this->assertSame(array('', 'Actividades', 'Tu perfil'), array_column($layout['groups'], 'label'));
        $this->assertLessThan($layout['items']['single_stic_comunica_perfil']['order'], $layout['items']['list_stic_documents']['order']);
    }

    /**
     * CAR-1. Una familia viendo a su hijo: aunque la madre sea monitora, en la
     * ficha del hijo no hay nada del equipo, y el orden es el de siempre.
     */
    public function test_una_familia_viendo_a_su_hijo_tiene_el_orden_de_siempre(): void
    {
        require_once __DIR__ . '/../menu.php';
        $this->conRelacion('^grupo^,^monitor^');
        $GLOBALS['__stic_filters']['sticpa_profile_audience'] = 'participante';
        $_SESSION['scp_user_contact_name'] = 'Messeguer, Lucía';

        $claves = array_keys(getSticMenuElements()[0]);
        $this->assertSame('list_stic_events', $claves[0]);
        $this->assertNotContains('single_stic_pasar_lista', $claves);
        $layout = sticpa_nav_layout(array_merge(array('single_stic_home'), $claves));
        $this->assertSame(array('', 'Actividades', 'Tu perfil'), array_column($layout['groups'], 'label'));
    }

    /** Pinta la portada de verdad (pages/single_stic_home.php), sin CRM. */
    private function portada()
    {
        require_once __DIR__ . '/../menu.php';
        $_SERVER['REQUEST_URI'] = '/ap/';
        ob_start();
        require __DIR__ . '/../pages/single_stic_home.php';
        return ob_get_clean();
    }

    /**
     * CAR-1, en la portada: para el equipo, su bloque va antes que la rejilla
     * de «Actividades» (en el móvil, «Pasar lista» pasa de y = 986 a la
     * primera pantalla). Para quien no es del equipo no hay bloque.
     */
    public function test_en_la_portada_el_bloque_del_equipo_va_primero(): void
    {
        $this->conRelacion('^grupo^,^monitor^,^coordinacion_mic_com^');
        $html = $this->portada();
        $equipo = strpos($html, 'stic-home-equipo');
        $actividades = strpos($html, 'stic-home-layout');
        $this->assertNotFalse($equipo);
        $this->assertNotFalse($actividades);
        $this->assertLessThan($actividades, $equipo);
        $this->assertLessThan(strpos($html, 'internalpage=list_stic_events'), strpos($html, 'internalpage=single_stic_pasar_lista'));

        $this->conRelacion('^grupo^');
        $html = $this->portada();
        $this->assertStringNotContainsString('stic-home-equipo', $html);
        $this->assertStringNotContainsString('single_stic_pasar_lista', $html);
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

    // -----------------------------------------------------------------
    // La puerta del servidor (28/10/2026): no basta con esconder el menú
    // -----------------------------------------------------------------

    /** Todas las páginas del equipo que hay hoy en pages/. */
    private function paginasDelEquipo(): array
    {
        $out = array();
        foreach (glob(__DIR__ . '/../pages/*.php') as $f) {
            $page = basename($f, '.php');
            if (sticpa_equipo_es_pagina_del_equipo($page)) {
                $out[] = $page;
            }
        }
        return $out;
    }

    public function test_las_paginas_del_equipo_son_las_de_pasar_lista_grupos_y_coordinacion(): void
    {
        $paginas = $this->paginasDelEquipo();
        foreach (array('single_stic_pasar_lista', 'single_stic_pasar_lista_marcar', 'single_stic_pasar_lista_ficha',
            'single_stic_pasar_lista_evento', 'single_stic_pasar_lista_grupos', 'single_stic_mis_grupos',
            'single_stic_coordinacion') as $page) {
            $this->assertContains($page, $paginas);
        }
        // Lo de las familias y los datos propios de monitor quedan fuera.
        foreach (array('single_stic_home', 'list_stic_events', 'single_stic_profile', 'single_stic_comunica_monitor') as $page) {
            $this->assertFalse(sticpa_equipo_es_pagina_del_equipo($page), $page);
        }
    }

    public function test_una_familia_no_abre_ninguna_pagina_del_equipo_aunque_escriba_la_url(): void
    {
        $this->conRelacion('^grupo^');
        foreach ($this->paginasDelEquipo() as $page) {
            $this->assertFalse(sticpa_equipo_puede_abrir($page), $page);
        }
        $this->assertTrue(sticpa_equipo_puede_abrir('single_stic_home'));
        $this->assertTrue(sticpa_equipo_puede_abrir('list_stic_events'));
    }

    public function test_monitor_coordinacion_y_acompanamiento_abren_las_paginas_del_equipo(): void
    {
        foreach (array('^grupo^,^monitor^', '^coordinacion_mic_com^', '^acompanamiento_mic_com^') as $raw) {
            $this->conRelacion($raw);
            foreach ($this->paginasDelEquipo() as $page) {
                $this->assertTrue(sticpa_equipo_puede_abrir($page), "{$raw} → {$page}");
            }
        }
    }

    public function test_un_monitor_viendo_a_su_hijo_tampoco_abre_y_se_le_dice_como_volver(): void
    {
        $this->conRelacion('^grupo^,^monitor^');
        $GLOBALS['__stic_filters']['sticpa_profile_audience'] = 'participante';
        $this->assertFalse(sticpa_equipo_puede_abrir('single_stic_pasar_lista_marcar'));
        // Siempre con una salida: cambiar de perfil (viendo a un hijo) o el inicio.
        $this->assertStringContainsString("<a href='?", sticpa_equipo_puerta_cerrada_html());
    }

    public function test_el_enrutador_pregunta_a_la_puerta_antes_de_incluir(): void
    {
        // El archivo principal no se puede cargar sin WordPress: se comprueba
        // que la llamada sigue ahí, entre resolver la página y el include.
        $src = file_get_contents(__DIR__ . '/../sinergiacrm-private-area.php');
        $resolver = strpos($src, '$pageFile = sticpa_resolve_page_file($currentPage);');
        $puerta = strpos($src, 'sticpa_equipo_puede_abrir($currentPage)');
        $include = strpos($src, 'include $pageFile;');
        $this->assertNotFalse($resolver);
        $this->assertNotFalse($puerta, 'el enrutador ya no pregunta a sticpa_equipo_puede_abrir()');
        $this->assertTrue($resolver < $puerta && $puerta < $include, 'la puerta tiene que ir antes del include');
    }
}
