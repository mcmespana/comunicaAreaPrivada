<?php
use PHPUnit\Framework\TestCase;

/**
 * El rol de Comunica (`scp_role`) y cuándo se cachea.
 * ----------------------------------------------------------------------------
 * Este test existe por un fallo que se vio en producción: a un monitor le
 * desaparecieron «Pasar lista» y «Mis grupos» y no volvían solos.
 *
 * La causa: `sticpa_store_comunica_role` guardaba el rol en sesión SIEMPRE,
 * también cuando no había podido preguntárselo al CRM. Y `sticpa_get_comunica_role`
 * solo recalculaba si la clave no existía. Así que un único fallo de lectura
 * dejaba `scp_role = ''` clavado —con una cookie de sesión de un año— y el menú
 * de monitor desaparecía en silencio, sin más salida que cerrar sesión.
 *
 * La regla que se fija aquí: se cachea el rol cuando se ha RESUELTO (el CRM ha
 * contestado sobre ese contacto), aunque la respuesta sea «sin rol». No se
 * cachea cuando no se ha podido preguntar.
 */
final class RoleCacheTest extends TestCase
{
    protected function setUp(): void { $_SESSION = array(); }

    /** Entry del CRM con el campo de tipo de relación ya cargado. */
    private function entryCon($valor, $id = 'C-1')
    {
        $obj = new stdClass();
        $obj->id = $id;
        $obj->name_value_list = json_decode(json_encode(array(
            'stic_relationship_type_c' => array('value' => $valor),
        )));
        return $obj;
    }

    /** Entry sin el campo y sin id: no hay forma de preguntar al CRM. */
    private function entrySinNada()
    {
        $obj = new stdClass();
        $obj->id = '';
        $obj->name_value_list = new stdClass();
        return $obj;
    }

    public function test_un_monitor_se_detecta_y_se_cachea(): void
    {
        // El valor real del CRM es multi-select de SuiteCRM, en minúsculas.
        $role = sticpa_store_comunica_role($this->entryCon('^grupo^,^monitor^'), 'Contacts');

        $this->assertSame('monitor', $role);
        $this->assertSame('monitor', $_SESSION['scp_role']);
        $this->assertTrue($_SESSION['scp_role_resolved']);
        $this->assertFalse(sticpa_role_needs_resolution());
    }

    public function test_sin_rol_pero_resuelto_tambien_se_cachea(): void
    {
        // Una persona sin tipo de relación es un dato bueno: no hay que volver a
        // preguntar en cada página.
        $role = sticpa_store_comunica_role($this->entryCon(''), 'Contacts');

        $this->assertSame('', $role);
        $this->assertSame('', $_SESSION['scp_role']);
        $this->assertTrue($_SESSION['scp_role_resolved']);
        $this->assertFalse(sticpa_role_needs_resolution());
    }

    public function test_si_no_se_ha_podido_preguntar_NO_se_cachea(): void
    {
        // ESTE es el caso del bug: sin resolver, no se escribe nada, y la
        // siguiente petición lo vuelve a intentar.
        $role = sticpa_store_comunica_role($this->entrySinNada(), 'Contacts');

        $this->assertSame('', $role);
        $this->assertArrayNotHasKey('scp_role', $_SESSION);
        $this->assertArrayNotHasKey('scp_role_resolved', $_SESSION);
        $this->assertTrue(sticpa_role_needs_resolution());
    }

    public function test_una_sesion_vieja_con_el_rol_pegado_se_vuelve_a_resolver(): void
    {
        // Sesiones creadas ANTES del arreglo: tienen scp_role, pero no la marca.
        // Tienen que volver a resolverse una vez, o el monitor sigue sin menú
        // hasta que caduque la cookie (un año).
        $_SESSION['scp_role'] = '';
        $_SESSION['scp_user_id'] = 'C-1';

        $this->assertTrue(
            sticpa_role_needs_resolution(),
            'Una sesión con el rol vacío y sin marca de resolución tiene que reintentarlo.'
        );
    }

    public function test_un_rol_ya_resuelto_no_se_vuelve_a_preguntar(): void
    {
        $_SESSION['scp_role'] = 'monitor';
        $_SESSION['scp_role_resolved'] = true;

        $this->assertFalse(sticpa_role_needs_resolution());
        $this->assertSame('monitor', sticpa_get_comunica_role());
    }

    /**
     * El mapa de roles, con las claves que hay DE VERDAD en el CRM
     * (verificadas por MCP el 09/09/2026 sobre los 256 contactos):
     * `grupo` 236, `monitor` 150, y sueltas `participante_mic_com`,
     * `familiar_menor`, `acompanamiento_mic_com`, `coordinacion_mic_com`.
     *
     * EL ÚNICO ROL ES 'monitor'. Antes había también 'laico', y este mismo test
     * lo afirmaba con `^Grupo COM-LC^` — una ETIQUETA que no existe en el CRM,
     * porque la API devuelve claves y porque no hay ningún tipo de relación
     * «laico». Todo el que tiene grupo es miembro del MCM y rellena la misma
     * ficha; encima puede ser monitor. Lo único que hay que detectar es eso.
     */
    public function test_deteccion_desde_el_valor_crudo(): void
    {
        $this->assertSame('monitor', sticpa_detect_role_from_relationship('^grupo^,^monitor^'));
        $this->assertSame('monitor', sticpa_detect_role_from_relationship('^Monitor/a^'));
        // David Soler: acompañamiento y coordinación, además de grupo y monitor.
        $this->assertSame('monitor', sticpa_detect_role_from_relationship(
            '^acompanamiento_mic_com^,^coordinacion_mic_com^,^grupo^,^monitor^'
        ));
        // Miembro del MCM con grupo y sin ser monitor: no tiene rol, y no pasa
        // nada — su ficha es la misma que la de todos.
        $this->assertSame('', sticpa_detect_role_from_relationship('^grupo^'));
        $this->assertSame('', sticpa_detect_role_from_relationship('^familiar_menor^,^grupo^'));
        $this->assertSame('', sticpa_detect_role_from_relationship('^participante_mic_com^'));
        $this->assertSame('', sticpa_detect_role_from_relationship(''));
        $this->assertSame('', sticpa_detect_role_from_relationship('^Donante^'));
    }

    /**
     * La coincidencia es por CLAVE EXACTA, no por subcadena. Con subcadena, un
     * `ex_monitor` o un `aspirante_monitor` saldrían monitores — el mismo tipo
     * de falso positivo que ya nos pintó de verde un «no pagado».
     * La subcadena solo se usa con el formato viejo de etiquetas, que se
     * reconoce porque lleva espacios o barras.
     */
    public function test_la_clave_se_compara_entera(): void
    {
        $this->assertSame('', sticpa_detect_role_from_relationship('^ex_monitor^'));
        $this->assertSame('', sticpa_detect_role_from_relationship('^aspirante_monitor^'));
        // Etiqueta antigua: ahí sí vale la subcadena.
        $this->assertSame('monitor', sticpa_detect_role_from_relationship('^Monitor/a de grupo^'));
    }
}
