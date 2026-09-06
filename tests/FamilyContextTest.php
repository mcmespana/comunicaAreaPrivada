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
            'list_stic_documents' => 'Documentos',
            'single_stic_tutor_profile' => 'Mis datos',
            'single_stic_password_change' => 'Contraseña',
        );
    }

    /**
     * A un familiar que SOLO es familiar no se le ofrecen Eventos ni Pagos: no
     * son suyos, él no se apunta a nada, y un menú de secciones vacías es
     * prometer cosas que no va a encontrar.
     */
    public function testAlFamiliarSoloSeLeEnsenanSusDatos()
    {
        $this->sesionFamiliar();
        $_SESSION['scp_tutor_user_id'] = 'f1';
        $_SESSION['scp_tutor_is_user'] = true;
        $_SESSION['scp_tutor_es_miembro'] = false;

        $secciones = sticpa_visible_sections($this->todasLasSecciones());

        $this->assertArrayNotHasKey('list_stic_events', $secciones);
        $this->assertArrayNotHasKey('list_stic_payments', $secciones);
        $this->assertArrayNotHasKey('list_stic_registrations', $secciones);
        $this->assertArrayHasKey('single_stic_tutor_profile', $secciones);
        $this->assertArrayHasKey('single_stic_password_change', $secciones);
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
}
