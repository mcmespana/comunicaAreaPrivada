<?php

use PHPUnit\Framework\TestCase;

/**
 * QUIÉN ERES Y QUÉ VES (inc/stic-family.php).
 *
 * Esta lógica no se ve: se nota cuando alguien entra y aterriza donde no debe,
 * o cuando le enseñamos secciones que no son suyas. Y es de las que se rompen
 * en silencio, porque nadie mira una sesión.
 *
 * OJO al leer estos tests: `scp_user_adult` NO significa "es mayor de edad".
 * Significa "NO tiene participantes a cargo". Un padre de 45 años es
 * `scp_user_adult = false`. Está explicado en la cabecera de stic-family.php.
 */
class FamilyContextTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // OJO: aquí NO se define RELATIONSHIP_TUTOR_TYPES. Se hizo, y tumbó a
        // SessionTest: una constante es global y para siempre, y aquel test
        // depende de que NO exista (por eso stic-magic-login.php toma su rama
        // `else`). Estos tests no la necesitan — trabajan sobre la caché de
        // sesión, que es lo que se quiere probar: la decisión, no la consulta.
        require_once __DIR__ . '/../inc/stic-family.php';
    }

    protected function setUp(): void
    {
        $_SESSION = array();
        $GLOBALS['__stic_filters'] = array();
    }

    /** Un miembro del MCM normal: entra en su casa. */
    private function sesionMiembro()
    {
        $_SESSION['scp_user_id'] = 'm1';
        $_SESSION['scp_user_contact_name'] = 'Soler, David';
        $_SESSION['scp_user_adult'] = true;      // no tiene a nadie a cargo
        $_SESSION['scp_role'] = 'monitor';
        $_SESSION['scp_role_resolved'] = true;
    }

    /** Un familiar: `scp_user_adult = false` quiere decir que tiene hijos. */
    private function sesionFamiliar($rol = '')
    {
        $_SESSION['scp_user_id'] = 'f1';
        $_SESSION['scp_user_contact_name'] = 'Messeguer, Marta';
        $_SESSION['scp_user_adult'] = false;
        $_SESSION['scp_role'] = $rol;
        $_SESSION['scp_role_resolved'] = true;
    }

    /** Cliente del CRM de mentira que devuelve N participantes. */
    private function crmCon($n)
    {
        $_SESSION['scp_available_profiles'] = array();
        for ($i = 1; $i <= $n; $i++) {
            $_SESSION['scp_available_profiles'][] = array('id' => "h{$i}", 'name' => "Hijo {$i}");
        }
        return null; // con caché en sesión no hace falta tocar el CRM
    }

    /* ------------------------------------------------------- Quién eres */

    public function testScpUserAdultSeLeeAlReves()
    {
        $this->sesionMiembro();
        $this->assertFalse(sticpa_es_familiar(), 'Sin nadie a cargo NO es familiar');

        $this->sesionFamiliar();
        $this->assertTrue(sticpa_es_familiar(), 'Con gente a cargo SÍ es familiar');
    }

    /** Sin el dato todavía, nadie se convierte en familiar por accidente. */
    public function testSinDatoNoSeSuponeNada()
    {
        $this->assertFalse(sticpa_es_familiar());
    }

    /* ----------------------------------------------------- A dónde vas */

    /** Un miembro normal aterriza en su home, como siempre. */
    public function testElMiembroAterrizaEnSuHome()
    {
        $this->sesionMiembro();
        $this->assertSame('single_stic_home', sticpa_landing_page(null));
    }

    /**
     * LA REGLA NUEVA. Un familiar con UN hijo entra directo a lo del hijo: una
     * pantalla de "elige" con una sola opción es un toque para decir algo que
     * ya sabíamos.
     */
    public function testUnFamiliarConUnHijoEntraDirectoAlHijo()
    {
        $this->sesionFamiliar();
        $this->crmCon(1);

        $this->assertSame('single_stic_home', sticpa_landing_page(null));
        $this->assertSame('h1', $_SESSION['scp_user_id'], 'El perfil activo pasa a ser el hijo');
        $this->assertSame('f1', $_SESSION['scp_tutor_user_id'], 'Y se recuerda quién ha entrado');
        $this->assertFalse($_SESSION['scp_tutor_is_user']);
        // El rol en sesión era el del familiar: hay que volver a resolverlo
        // para el hijo, o el hijo hereda el menú de su madre.
        $this->assertArrayNotHasKey('scp_role', $_SESSION);
    }

    /** Con varios hijos sí hay algo que elegir. */
    public function testUnFamiliarConVariosHijosElige()
    {
        $this->sesionFamiliar();
        $this->crmCon(3);
        $this->assertSame('single_stic_profile_selection', sticpa_landing_page(null));
        // Y NO se ha elegido por él.
        $this->assertSame('f1', $_SESSION['scp_user_id']);
    }

    /**
     * EL CASO QUE SE OLVIDA. Una monitora que además es madre ve lo que ve
     * cualquier miembro del MCM: manda el rol, no la familia.
     */
    public function testUnFamiliarQueAdemasEsMiembroVeLoDeMiembro()
    {
        $this->sesionFamiliar('monitor');
        $this->crmCon(2);

        $this->assertSame('single_stic_home', sticpa_landing_page(null));
        $this->assertTrue($_SESSION['scp_tutor_is_user'], 'Se ve a sí misma, no a un hijo');
        $this->assertSame('f1', $_SESSION['scp_user_id']);
        $this->assertSame('miembro', sticpa_viewing_context()['audiencia']);
    }

    /** Familiar sin participantes localizados: no se le deja en el limbo. */
    public function testUnFamiliarSinParticipantesLocalizados()
    {
        $this->sesionFamiliar();
        $_SESSION['scp_available_profiles'] = array();
        $this->assertSame('single_stic_home', sticpa_landing_page(null));
        $this->assertTrue($_SESSION['scp_tutor_is_user']);
    }

    /* --------------------------------------------------- Qué se te enseña */

    private function todasLasSecciones()
    {
        return array(
            'list_stic_events' => 'Eventos',
            'list_stic_registrations' => 'Inscripciones',
            'list_stic_payments' => 'Pagos',
            'list_stic_payment_commitments' => 'Compromisos de pago',
            'list_stic_documents' => 'Documentos',
            'single_stic_tutor_profile' => 'Mis datos',
            'single_stic_password_change' => 'Contraseña',
        );
    }

    /**
     * A un familiar que SOLO es familiar no se le ofrece APUNTARSE a nada:
     * eventos, inscripciones, sesiones y asistencias son del participante y se
     * ven en su ficha. Un menú de secciones vacías es prometer cosas que no va
     * a encontrar.
     */
    public function testAlFamiliarNoSeLeOfreceApuntarseANada()
    {
        $this->sesionFamiliar();
        $_SESSION['scp_tutor_user_id'] = 'f1';
        $_SESSION['scp_tutor_is_user'] = true;
        $_SESSION['scp_tutor_es_miembro'] = false;

        $secciones = sticpa_visible_sections($this->todasLasSecciones());

        $this->assertArrayNotHasKey('list_stic_events', $secciones);
        $this->assertArrayNotHasKey('list_stic_registrations', $secciones);
        $this->assertArrayNotHasKey('list_stic_documents', $secciones);
        $this->assertArrayHasKey('single_stic_tutor_profile', $secciones);
        $this->assertArrayHasKey('single_stic_password_change', $secciones);
    }

    /**
     * PERO EL DINERO SÍ ES SUYO. Un compromiso de pago es de QUIEN PAGA: el
     * IBAN, el mandato SEPA y la autorización son del familiar, no del niño.
     * SinergiaCRM lo modela así a propósito (persona pagadora / persona
     * destinataria) y su documentación pone justo este ejemplo.
     *
     * La primera versión de este filtro le quitaba los pagos junto con todo lo
     * demás. Era un error: le escondía su propia cuenta bancaria.
     */
    public function testAlFamiliarSiSeLeEnsenaSuPropioDinero()
    {
        $this->sesionFamiliar();
        $_SESSION['scp_tutor_user_id'] = 'f1';
        $_SESSION['scp_tutor_is_user'] = true;
        $_SESSION['scp_tutor_es_miembro'] = false;

        $secciones = sticpa_visible_sections($this->todasLasSecciones());
        $this->assertArrayHasKey('list_stic_payments', $secciones);
        $this->assertArrayHasKey('list_stic_payment_commitments', $secciones);
    }

    /** En cuanto mira a un hijo, vuelve el menú entero: ahí sí hay de todo. */
    public function testViendoAUnHijoVuelveElMenuEntero()
    {
        $this->sesionFamiliar();
        $_SESSION['scp_tutor_user_id'] = 'f1';
        $_SESSION['scp_tutor_is_user'] = false;   // viendo a un hijo
        $_SESSION['scp_tutor_es_miembro'] = false;
        $_SESSION['scp_user_id'] = 'h1';

        $ctx = sticpa_viewing_context();
        $this->assertSame('participante', $ctx['audiencia']);
        $this->assertSame($this->todasLasSecciones(), sticpa_visible_sections($this->todasLasSecciones()));
    }

    /** Y a la monitora-que-es-madre nunca se le recorta el menú. */
    public function testAlMiembroNoSeLeRecortaElMenu()
    {
        $this->sesionFamiliar('monitor');
        $_SESSION['scp_tutor_user_id'] = 'f1';
        $_SESSION['scp_tutor_is_user'] = true;
        $_SESSION['scp_tutor_es_miembro'] = true;

        $this->assertSame($this->todasLasSecciones(), sticpa_visible_sections($this->todasLasSecciones()));
    }

    /**
     * La lista de secciones del familiar es BLANCA, no negra: una sección nueva
     * en el menú no aparece sola en el área de un familiar, hay que decidirlo.
     */
    public function testLaListaDelFamiliarEsBlanca()
    {
        $this->sesionFamiliar();
        $_SESSION['scp_tutor_user_id'] = 'f1';
        $_SESSION['scp_tutor_is_user'] = true;
        $_SESSION['scp_tutor_es_miembro'] = false;

        $conNueva = $this->todasLasSecciones();
        $conNueva['list_stic_seccion_nueva'] = 'Sección nueva';
        $this->assertArrayNotHasKey('list_stic_seccion_nueva', sticpa_visible_sections($conNueva));
    }

    /**
     * El rol del FAMILIAR se recuerda aparte del rol en sesión: tras elegir
     * participante, el de la sesión es el del hijo. Si se mirase ese, una
     * monitora dejaría de serlo al abrir la ficha de su hija.
     */
    public function testElRolDelFamiliarNoSePierdeAlMirarAUnHijo()
    {
        $this->sesionFamiliar('monitor');
        sticpa_recordar_si_familiar_es_miembro();
        $this->assertTrue($_SESSION['scp_tutor_es_miembro']);

        // Ahora mira a su hija, cuyo rol es vacío.
        $_SESSION['scp_role'] = '';
        $_SESSION['scp_user_id'] = 'h1';
        $this->assertTrue(sticpa_familiar_es_miembro(), 'Sigue siendo monitora');
    }

    /* ==================================================================
       EL CASO DE SOL MESSEGUER (08/09/2026)
       ------------------------------------------------------------------
       Una madre dejó de poder ver a su hija en producción. El CRM estaba
       BIEN: relación `mother` hacia la hija, con fecha de inicio y sin
       fecha de fin. Eran tres fallos nuestros a la vez.
       ================================================================== */

    /**
     * FALLO 1. "Es miembro del MCM" se decidía con el mapa de roles, que solo
     * sabe de 'monitor' y 'laico' porque existe para el menú de Pasar Lista.
     * El tipo de relación de Sol es `^familiar_menor^,^grupo^`: tiene su grupo,
     * es del Movimiento, y no es ni monitora ni laica. El mapa devolvía '' y la
     * dábamos por "solo familiar", recortándole el menú.
     */
    public function testTenerGrupoTeHaceMiembroAunqueNoSeasMonitorNiLaico()
    {
        // El caso real, tal cual viene del CRM.
        $this->assertTrue(sticpa_es_miembro_por_tipo_de_relacion('^familiar_menor^,^grupo^'));
        // Y el contrario: si SOLO dice que es familiar de un menor, no lo es.
        $this->assertFalse(sticpa_es_miembro_por_tipo_de_relacion('^familiar_menor^'));
        // Sin dato no se supone nada: se supone lo de menos privilegios.
        $this->assertFalse(sticpa_es_miembro_por_tipo_de_relacion(''));
        // Un rol reconocido zanja la pregunta aunque el campo venga vacío.
        $this->assertTrue(sticpa_es_miembro_por_tipo_de_relacion('', 'monitor'));
    }

    /**
     * FALLO 2, Y ES EL QUE LE QUITÓ A LA HIJA DE DELANTE. La rama de "familiar
     * que además es miembro" salía por arriba SIN cargar los participantes, así
     * que `scp_available_profiles` se quedaba vacío — y el selector de la barra,
     * que se pinta igualmente, salía sin su hija dentro.
     */
    public function testElFamiliarQueEsMiembroTAMBIENCargaSusParticipantes()
    {
        $this->sesionFamiliar();
        $_SESSION['scp_relationship_raw'] = '^familiar_menor^,^grupo^';
        unset($_SESSION['scp_tutor_es_miembro']);

        // SIN caché en sesión, a propósito: lo que se prueba es que el
        // aterrizaje LLAMA al cargador. Si se precarga `scp_available_profiles`
        // el test pasa igual con el código roto — pasó, y no valía para nada.
        // Los participantes llegan por el filtro, que es la puerta que usa el
        // cargador cuando no hay CRM.
        $this->assertArrayNotHasKey('scp_available_profiles', $_SESSION);
        $GLOBALS['__stic_filters']['sticpa_familia_participants'] = array(
            array('id' => 'solete', 'name' => 'Messeguer, Solete'),
        );

        $this->assertSame('single_stic_home', sticpa_landing_page(null));
        // Se ve a sí misma, que es lo correcto para un miembro...
        $this->assertTrue($_SESSION['scp_tutor_is_user']);
        // ...PERO su hija tiene que haber quedado cargada para el selector.
        $this->assertArrayHasKey('scp_available_profiles', $_SESSION);
        $this->assertSame('solete', $_SESSION['scp_available_profiles'][0]['id']);
        $this->assertSame(1, sticpa_viewing_context()['participantes']);
    }

    /**
     * FALLO 3. Un vacío que NO se ha podido resolver se cacheaba igual, y la
     * sesión dura un año: un hipo del CRM y esa persona se queda sin hijos para
     * siempre. Es literalmente la lección del plan 040, repetida.
     */
    public function testUnVacioQueNoSeHaPodidoResolverNoSeCachea()
    {
        $_SESSION = array('scp_user_id' => 'f1');

        // Sin cliente del CRM no hay respuesta: no se cachea nada, y a la
        // siguiente se vuelve a intentar.
        $this->assertSame(array(), sticpa_load_family_participants(null));
        $this->assertArrayNotHasKey('scp_available_profiles', $_SESSION);

        // Y en cuanto haya participantes —da igual por dónde lleguen— se
        // guardan y ya no se vuelve a preguntar.
        $GLOBALS['__stic_filters']['sticpa_familia_participants'] = array(
            array('id' => 'solete', 'name' => 'Messeguer, Solete'),
        );
        $this->assertCount(1, sticpa_load_family_participants(null));
        $this->assertArrayHasKey('scp_available_profiles', $_SESSION);
    }

    /**
     * EL ENLACE PROFUNDO. La sesión de familia se montaba DENTRO de
     * sticpa_landing_page(), que solo corre cuando la URL no pide página. Con
     * un enlace profundo —la app abriendo una sección, un marcador, una pestaña
     * que el navegador restaura— la persona entraba sin `scp_tutor_user_id`,
     * sin participantes cargados y, siendo familiar, con el menú recortado y el
     * selector vacío. El mismo síntoma que ya costó un arreglo, por otra puerta.
     */
    public function testConEnlaceProfundoTambienSeMontaLaSesionDeFamilia()
    {
        $this->sesionFamiliar();
        $GLOBALS['__stic_filters']['sticpa_familia_participants'] = array(
            array('id' => 'solete', 'name' => 'Messeguer, Solete'),
        );

        // NO se llama a sticpa_landing_page(): se simula que la URL traía ya
        // ?internalpage=list_stic_payments, así que solo corre el arranque.
        sticpa_bootstrap_family(null);

        $this->assertSame('f1', $_SESSION['scp_tutor_user_id'], 'Se sabe quién ha entrado');
        $this->assertArrayHasKey('scp_available_profiles', $_SESSION, 'Y sus participantes están cargados');
        // Con un solo hijo y siendo solo familiar, se entra a lo del hijo.
        $this->assertSame('solete', $_SESSION['scp_user_id']);
        $this->assertFalse($_SESSION['scp_tutor_is_user']);
    }

    /**
     * El arranque es IDEMPOTENTE: se ejecuta en cada petición, así que no puede
     * deshacer la elección de participante que la persona acaba de hacer.
     */
    public function testElArranqueNoPisaLaEleccionDeParticipante()
    {
        $this->sesionFamiliar();
        $GLOBALS['__stic_filters']['sticpa_familia_participants'] = array(
            array('id' => 'solete', 'name' => 'Messeguer, Solete'),
        );

        // Ha elegido verse a SÍ MISMA en el selector.
        $_SESSION['scp_tutor_user_id'] = 'f1';
        $_SESSION['scp_tutor_is_user'] = true;
        $_SESSION['scp_user_id'] = 'f1';

        sticpa_bootstrap_family(null);

        $this->assertTrue($_SESSION['scp_tutor_is_user'], 'Sigue viéndose a sí misma');
        $this->assertSame('f1', $_SESSION['scp_user_id'], 'No se le mete en la ficha del hijo');
    }

    /** Construye el name_value_list de una relación de stic_Personal_Environment. */
    private function relacion(array $campos)
    {
        $o = new stdClass();
        foreach ($campos as $k => $v) {
            $o->$k = (object) array('value' => $v);
        }
        return $o;
    }

    /**
     * UNA FECHA DE FIN AUSENTE SIGNIFICA «NO TERMINA», Y HAY MUCHAS FORMAS DE
     * ESTAR AUSENTE. Esto estaba en el WHERE del SQL como
     * `end_date >= NOW() OR end_date IS NULL`, que da por hecho que el CRM
     * guarda NULL. Si guarda cadena vacía o '0000-00-00' —lo normal en
     * SuiteCRM— la relación se caía del resultado y la madre se quedaba sin
     * hijos, en silencio. Ante la duda, la relación está VIVA.
     */
    public function testUnaFechaDeFinAusenteNoTerminaLaRelacion()
    {
        foreach (array('', '0000-00-00', '0000-00-00 00:00:00') as $vacia) {
            $this->assertTrue(
                sticpa_relacion_vigente($this->relacion(array(
                    'start_date' => '2026-07-09', 'end_date' => $vacia,
                ))),
                "Una fecha de fin «{$vacia}» no puede terminar la relación"
            );
        }
        // Y si el campo ni siquiera viene (que es lo que hace este CRM).
        $this->assertTrue(sticpa_relacion_vigente($this->relacion(array('start_date' => '2026-07-09'))));
        // Sin ninguna fecha tampoco se descarta a nadie.
        $this->assertTrue(sticpa_relacion_vigente($this->relacion(array())));
    }

    /** Lo que sí termina una relación es una fecha de fin pasada de verdad. */
    public function testUnaRelacionTerminadaOFuturaNoCuenta()
    {
        $this->assertFalse(sticpa_relacion_vigente($this->relacion(array(
            'start_date' => '2020-01-01', 'end_date' => '2021-01-01',
        ))), 'Terminada en 2021');

        $this->assertFalse(sticpa_relacion_vigente($this->relacion(array(
            'start_date' => '2099-01-01',
        ))), 'Todavía no ha empezado');

        $this->assertFalse(sticpa_relacion_vigente(null));
    }

    /**
     * El caso real de Sol Messeguer, tal y como viene del CRM: madre de Solete
     * desde el 09/07/2026, sin fecha de fin.
     */
    public function testLaRelacionRealDeSolSigueViva()
    {
        $this->assertTrue(sticpa_relacion_vigente($this->relacion(array(
            'relationship_type' => 'mother',
            'start_date' => '2026-07-09',
        ))));
    }
}
