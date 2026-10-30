<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/PasarListaRenderTest.php';

/**
 * PASAR LISTA POR EL MIDDLEWARE (inc/stic-middleware.php, fase 1 del plan de
 * comunicaMiddleware).
 *
 * El middleware de mentira de aquí saca sus filas de los MISMOS datos del doble
 * del CRM (FakeSCP::datosDe…, que no cuentan llamadas) y las manda con la forma
 * del middleware de verdad: campos planos con los nombres de la API, los
 * enlaces de la consulta de relaciones anidados, la salud como marca y ningún
 * bloque `link_list`. Así la prueba que importa es posible: cada pantalla,
 * pintada por la API y por el middleware, tiene que salir IGUAL, y por el
 * middleware sin una sola lectura al CRM.
 */
class MiddlewareTest extends TestCase
{
    const SECRET = 'secreto-de-pruebas-con-mas-de-32-caracteres';

    private $scp;
    /** Los recursos de cada petición al lote. */
    private $lotes = array();
    /** 'ok', 'caido' (no contesta) o 'rechaza' (403). */
    private $modo = 'ok';

    protected function setUp(): void
    {
        $GLOBALS['__stic_pl_now'] = mktime(17, 0, 0, 11, 15, 2025);
        $GLOBALS['__stic_transients'] = array();
        $GLOBALS['__stic_options'] = array();
        $GLOBALS['__stic_filters'] = array('sticpa_pl_seguimientos_enabled' => true);
        unset($GLOBALS['sticpa_mw'], $GLOBALS['sticpa_mw_http']);
        $_SESSION = array('scp_user_id' => 'm1', 'scp_user_assigned_user_id' => 'deleg-castellon', 'scp_user_contact_name' => 'David Soler');
        $_REQUEST = array();
        $_GET = array();
        $_POST = array();
        $this->scp = new FakeSCP();
        $this->lotes = array();
        $this->modo = 'ok';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__stic_pl_now'], $GLOBALS['sticpa_mw'], $GLOBALS['sticpa_mw_http']);
        $GLOBALS['__stic_filters'] = array();
        $_SESSION = array();
        $_REQUEST = array();
        $_GET = array();
    }

    /** Enciende el middleware para todos, contra el de mentira. */
    private function encender()
    {
        $GLOBALS['__stic_filters']['sticpa_mw_url'] = 'https://mw.test';
        $GLOBALS['__stic_filters']['sticpa_mw_secret'] = self::SECRET;
        $GLOBALS['__stic_options']['sticpa_mw_modo'] = 'todos';
        $test = $this;
        $GLOBALS['sticpa_mw_http'] = function ($url, $raw, $headers) use ($test) {
            return $test->servir($url, $raw, $headers);
        };
    }

    private function render($page)
    {
        $objSCP = $this->scp;
        $html = '';
        $pageSettings = array();
        $formSettings = array();
        set_error_handler(function ($no, $str, $file, $line) {
            throw new ErrorException($str, 0, $no, $file, $line);
        });
        try {
            require __DIR__ . '/../pages/' . $page . '.php';
        } finally {
            restore_error_handler();
        }
        return $html;
    }

    // ---- El middleware de mentira ----------------------------------------

    public function servir($url, $raw, $headers)
    {
        $this->assertContains($url, array('https://mw.test/plugin/v1/lote', 'https://mw.test/plugin/v1/sensible'));
        $ruta = substr($url, strlen('https://mw.test'));
        $h = array();
        foreach ($headers as $line) {
            list($k, $v) = array_map('trim', explode(':', $line, 2));
            $h[strtolower($k)] = $v;
        }
        // La firma que comprueba el middleware (src/authz/plugin-signature.ts).
        $this->assertSame(sticpa_mw_signature(self::SECRET, $h['x-mw-ts'], 'POST', $ruta, $raw), $h['x-mw-sig']);
        $body = json_decode($raw, true);
        $this->assertSame($_SESSION['scp_user_id'], $body['persona']);
        $this->lotes[] = array_column($body['peticiones'], 'recurso');
        // Lo sensible, solo por su ruta; lo demás, nunca por ella.
        foreach ($body['peticiones'] as $pet) {
            $this->assertSame(in_array($pet['recurso'], sticpa_mw_sensibles(), true), $ruta === '/plugin/v1/sensible', $pet['recurso']);
        }

        if ($this->modo === 'caido') {
            return array('status' => 0, 'body' => '', 'error' => 'Operation timed out');
        }
        if ($this->modo === 'rechaza') {
            return array('status' => 403, 'body' => '{"error":{"code":"forbidden","reason":"wrong_delegation"}}', 'error' => '');
        }
        $deleg = isset($body['delegacion']) ? $body['delegacion'] : '';
        $out = array();
        foreach ($body['peticiones'] as $p) {
            $params = is_array($p['params']) ? $p['params'] : array();
            $out[$p['clave']] = array('ok' => true, 'rows' => $this->filas($p['recurso'], $params, $deleg, $body['persona']), 'ms' => 1);
        }
        return array('status' => 200, 'body' => json_encode(array('resultados' => $out)), 'error' => '');
    }

    /** Las filas de un recurso, de los datos del doble, con la forma del middleware. */
    private function filas($recurso, $p, $deleg, $persona)
    {
        $d = $deleg;
        $rel = function ($module, $id, $link, $fields, $links = array()) {
            return $this->scp->datosDeRelacion(array(
                'module_name' => $module, 'module_id' => $id, 'link_field_name' => $link,
                'related_fields' => $fields, 'related_module_link_name_to_fields_array' => $links,
                'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
            ));
        };
        switch ($recurso) {
            case 'groups':
                return self::planas($this->scp->datosDeModulo('ajmcm_GRUPOS', "ajmcm_grupos.assigned_user_id = '$d'", array(
                    'id', 'name', 'code', 'level', 'cursos_c', 'ajmcm_n_participantes_c', 'ajmcm_n_monitores_c',
                    'ajmcm_monitores_c', 'ajmcm_recuento_al_c', 'ajmcm_segmento_com_c', 'ajmcm_pasar_lista_c',
                )));
            case 'relations':
                $tipos = "'" . implode("', '", $p['tipos']) . "'";
                $rows = $this->scp->datosDeModulo(
                    'stic_Contacts_Relationships',
                    "stic_contacts_relationships.assigned_user_id = '$d' AND stic_contacts_relationships.relationship_type IN ($tipos)",
                    array('id', 'name', 'relationship_type', 'start_date', 'end_date',
                        'stic_contacts_relationships_contactscontacts_ida', 'stic_contacts_relationships_contacts_name',
                        'ajmcm_grupos_stic_contacts_relationshipsajmcm_grupos_ida', 'ajmcm_grupos_stic_contacts_relationships_name',
                        'ajmcm_curso_escolar_c'),
                    array(
                        'grupo' => array('relationshipName' => 'ajmcm_grupos_stic_contacts_relationships', 'fields' => array('id', 'name')),
                        'persona' => array('relationshipName' => 'stic_contacts_relationships_contacts', 'fields' => array_merge(
                            array('id', 'name', 'first_name', 'last_name', 'birthdate', 'stic_age_c', 'phone_mobile', 'photo', 'date_modified'),
                            sticpa_pl_marcas_campos()
                        )),
                    )
                );
                return self::planas($rows, array('grupo', 'persona'));
            case 'myRels':
                return self::planas($this->scp->datosDeRelacion(sticpa_pl_mis_rels_params($persona)), array(),
                    array('ajmcm_grupos_stic_contacts_relationshipsajmcm_grupos_ida'));
            case 'events':
                return self::planas($this->scp->datosDeModulo('stic_Events', "stic_events.assigned_user_id = '$d'",
                    array('id', 'name', 'start_date', 'end_date', 'ajmcm_etapa_c')));
            case 'eventSessions':
                return self::planas($rel('stic_Events', $p['evento'], 'stic_sessions_stic_events', array('id', 'name', 'start_date', 'end_date')));
            case 'eventRegistrations':
                return self::planas($rel('stic_Events', $p['evento'], 'stic_registrations_stic_events',
                    array('id', 'status', 'stic_registrations_contactscontacts_ida'),
                    array(array('name' => 'stic_registrations_contacts', 'value' => array('id')))
                ), array(), array('stic_registrations_contactscontacts_ida'));
            case 'sessionAttendances':
                return self::planas($rel('stic_Sessions', $p['sesion'], 'stic_attendances_stic_sessions',
                    array('id', 'status', 'description', 'stic_attendances_stic_registrationsstic_registrations_ida'),
                    array(array('name' => 'stic_attendances_stic_registrations', 'value' => array('id')))
                ), array(), array('stic_attendances_stic_registrationsstic_registrations_ida'));
            case 'attendancesForSessions':
                $rows = self::planas($this->scp->datosDeModulo('stic_Attendances', "stic_attendances.assigned_user_id = '$d' AND (1=1)",
                    array('id', 'status', 'description', 'stic_attendances_stic_sessionsstic_sessions_ida',
                        'stic_attendances_stic_registrationsstic_registrations_ida'),
                    array(
                        'sesion' => array('relationshipName' => 'stic_attendances_stic_sessions', 'fields' => array('id', 'name')),
                        'inscripcion' => array('relationshipName' => 'stic_attendances_stic_registrations', 'fields' => array('id', 'name')),
                    )
                ), array(), array(), array(
                    'sesion' => 'stic_attendances_stic_sessionsstic_sessions_ida',
                    'inscripcion' => 'stic_attendances_stic_registrationsstic_registrations_ida',
                ));
                // Por las sesiones pedidas, como el middleware (no por fechas).
                return array_values(array_filter($rows, function ($r) use ($p) {
                    return in_array($r['stic_attendances_stic_sessionsstic_sessions_ida'], $p['sesiones'], true);
                }));
            case 'listas':
                return self::planas($this->scp->datosDeModulo('LIS_listas', "lis_listas.assigned_user_id = '$d'",
                    array('id', 'estado', 'pasada_el', 'n_asistieron', 'n_faltaron', 'ajmcm_tipo_c',
                        'lis_listas_stic_sessionsstic_sessions_ida', 'lis_listas_ajmcm_gruposajmcm_grupos_ida', 'lis_listas_contactscontacts_ida'),
                    array(
                        'sesion' => array('relationshipName' => 'lis_listas_stic_sessions', 'fields' => array('id', 'name')),
                        'grupo' => array('relationshipName' => 'lis_listas_ajmcm_grupos', 'fields' => array('id', 'name')),
                    )
                ), array(), array(), array(
                    'sesion' => 'lis_listas_stic_sessionsstic_sessions_ida',
                    'grupo' => 'lis_listas_ajmcm_gruposajmcm_grupos_ida',
                ));
            // ---- Paso 1c: la ficha del participante ----
            case 'ficha':
                $det = $this->scp->detalleDeRegistro($p['contacto'], 'Contacts', sticpa_pl_ficha_fields());
                return self::planas(isset($det->entry_list) ? $det->entry_list : array());
            case 'familyRels':
            case 'familyContacts':
                // Los dos sentidos, y cada lado resuelto por su tabla de enlace (lo que hace la base).
                $rels = array();
                foreach (array('stic_personal_environment_contacts', 'stic_personal_environment_contacts_1') as $link) {
                    $filas = self::planas($rel('Contacts', $p['contacto'], $link, array('id', 'relationship_type', 'start_date', 'end_date',
                        'date_entered', 'stic_personal_environment_contactscontacts_ida', 'stic_personal_environment_contacts_1contacts_ida')));
                    foreach ($filas as $f) {
                        foreach (array('stic_personal_environment_contacts_1' => 'stic_personal_environment_contacts_1contacts_ida',
                            'stic_personal_environment_contacts' => 'stic_personal_environment_contactscontacts_ida') as $l => $campo) {
                            if (empty($f[$campo])) {
                                $c = $rel('stic_Personal_Environment', $f['id'], $l, array('id', 'first_name', 'last_name', 'name', 'phone_mobile', 'email1'));
                                $f[$campo] = isset($c[0]->name_value_list->id->value) ? (string) $c[0]->name_value_list->id->value : '';
                            }
                        }
                        $rels[$f['id']] = $f;
                    }
                }
                if ($recurso === 'familyRels') {
                    return array_values($rels);
                }
                $ids = array();
                foreach ($rels as $f) {
                    foreach (array('stic_personal_environment_contactscontacts_ida', 'stic_personal_environment_contacts_1contacts_ida') as $campo) {
                        if ($f[$campo] !== '' && $f[$campo] !== $p['contacto']) {
                            $ids[] = "'" . $f[$campo] . "'";
                        }
                    }
                }
                return empty($ids) ? array() : self::planas($this->scp->datosDeModulo('Contacts', 'contacts.id IN (' . implode(',', array_unique($ids)) . ')',
                    array('id', 'first_name', 'last_name', 'name', 'phone_mobile', 'email1')));
            case 'avisos':
                $map = sticpa_pl_avi_map();
                return self::planas($rel('Contacts', $p['contacto'], $map['link_contacts'], array('id', $map['f_name'], $map['f_date'],
                    $map['f_motivo'], $map['f_puesto_por'], $map['f_notificado'], 'date_entered')));
            case 'registrationAttendances':
                return self::planas($rel('stic_Registrations', $p['inscripcion'], 'stic_attendances_stic_registrations',
                    array('id', 'status', 'description', 'stic_attendances_stic_sessionsstic_sessions_ida'),
                    array(array('name' => 'stic_attendances_stic_sessions', 'value' => array('id')))
                ), array(), array('stic_attendances_stic_sessionsstic_sessions_ida'));
            case 'personEvents':
                $out = array();
                foreach (self::planas($rel('Contacts', $p['contacto'], 'stic_registrations_contacts', array('id', 'status'))) as $reg) {
                    $ev = $rel('stic_Registrations', $reg['id'], 'stic_registrations_stic_events', array('id', 'name', 'start_date', 'end_date'));
                    $reg['stic_registrations_stic_eventsstic_events_ida'] = isset($ev[0]->name_value_list->id->value) ? (string) $ev[0]->name_value_list->id->value : '';
                    $reg['asistencias'] = self::planas($rel('stic_Registrations', $reg['id'], 'stic_attendances_stic_registrations',
                        array('id', 'status', 'start_date', 'description')));
                    $out[] = $reg;
                }
                return $out;
            // ---- Paso 1d: coordinación ----
            case 'monitorFicha':
                $det = $this->scp->detalleDeRegistro($p['monitor'], 'Contacts', sticpa_pl_monitor_fields());
                return self::planas(isset($det->entry_list) ? $det->entry_list : array());
            case 'seguimientos':
                $map = sticpa_pl_seg_map();
                return self::planas($rel('Contacts', $p['monitor'], $map['link_contacts'],
                    array('id', $map['f_name'], $map['f_text'], $map['f_type'], $map['f_date'], 'assigned_user_name', 'date_entered')));
        }
        $this->fail('recurso desconocido: ' . $recurso);
    }

    /**
     * Filas de la API → filas del middleware. `$anidados`: enlaces que el
     * middleware manda anidados. `$planosDeEnlace`: campos planos que el
     * middleware rellena desde su tabla de enlace (en la API pueden venir solo
     * en `link_list`). `$aPlano`: enlaces que el middleware manda como su campo
     * plano.
     */
    private static function planas($rows, $anidados = array(), $planosDeEnlace = array(), $aPlano = array())
    {
        $out = array();
        foreach ((array) $rows as $row) {
            $v = $row->name_value_list;
            $rec = array();
            $enlaces = array();
            foreach ($v as $k => $o) {
                if (substr($k, -5) === '_link') {
                    $enlaces[] = substr($k, 0, -5);
                }
            }
            foreach ($v as $k => $o) {
                $base = preg_replace('/(_link|_id)$/', '', $k);
                if (in_array($k, $enlaces, true) || (in_array($base, $enlaces, true) && $base !== $k)) {
                    continue;
                }
                $rec[$k] = is_object($o) && isset($o->value) && is_scalar($o->value) ? (string) $o->value : '';
            }
            foreach ($enlaces as $e) {
                $lv = $v->{$e . '_link'};
                if (in_array($e, $anidados, true)) {
                    if (!$lv) {
                        $rec[$e] = null;
                        continue;
                    }
                    $campos = array();
                    foreach ($lv as $k => $o) {
                        $campos[$k] = isset($o->value) ? (string) $o->value : '';
                    }
                    $campos['id'] = (string) $v->{$e . '_id'}->value;
                    $campos['name'] = (string) $v->$e->value;
                    // La salud viaja como marca, nunca el texto (src/reads/pasar-lista.ts).
                    foreach (array('ajmcm_descripcion_allergies__c', 'ajmcm_descripcion_intoler_c', 'ajmcm_descripcion_tratam_c') as $s) {
                        if (isset($campos[$s])) {
                            $campos[$s] = trim($campos[$s]) !== '' ? 'si' : '';
                        }
                    }
                    $rec[$e] = $campos;
                } elseif (isset($aPlano[$e]) && (!isset($rec[$aPlano[$e]]) || $rec[$aPlano[$e]] === '')) {
                    $rec[$aPlano[$e]] = (string) $v->{$e . '_id'}->value;
                }
            }
            foreach ($planosDeEnlace as $campo) {
                if (!isset($rec[$campo]) || $rec[$campo] === '') {
                    $rec[$campo] = sticpa_pl_link_id($row);
                }
            }
            $out[] = $rec;
        }
        return $out;
    }

    // ---- Las pruebas -------------------------------------------------------

    /** Las pantallas de los pasos 1a y 1b. */
    public static function pantallas()
    {
        return array(
            'portada' => array('single_stic_pasar_lista', array()),
            'portada de coordinación' => array('single_stic_pasar_lista', array('__coord' => 'COM')),
            'árbol de grupos' => array('single_stic_pasar_lista_grupos', array()),
            'marcar' => array('single_stic_pasar_lista_marcar', array('grupo' => 'g1')),
            'resumen' => array('single_stic_pasar_lista_resumen', array()),
            'mis grupos' => array('single_stic_mis_grupos', array()),
            'mis grupos, un grupo' => array('single_stic_mis_grupos', array('grupo' => 'g1')),
            'coordinación' => array('single_stic_coordinacion', array('__coord' => 'COM')),
            // Paso 1c.
            'ficha' => array('single_stic_pasar_lista_ficha', array('participante' => 'c1', 'grupo' => 'g1')),
            'ficha con eventos' => array('single_stic_pasar_lista_ficha', array('participante' => 'c1', 'grupo' => 'g1', '__evPersona' => true)),
            // Paso 1d: la ficha de un monitor vista por otra persona que coordina, con seguimientos de los tres tipos.
            'ficha del monitor' => array('single_stic_pasar_lista_monitor', array('__coord' => 'COM', '__como' => 'coord1', '__seg' => true, 'monitor' => 'm1')),
            'lista de monitores' => array('single_stic_pasar_lista_monitores', array('__coord' => 'COM')),
        );
    }

    private function pintar($page, $req, $conMiddleware)
    {
        $this->setUp();
        if ($conMiddleware) {
            $this->encender();
        }
        if (isset($req['__coord'])) {
            $this->scp->coordEtapa = $req['__coord'];
            unset($req['__coord']);
        }
        if (isset($req['__como'])) {
            $_SESSION['scp_user_id'] = $req['__como'];
            unset($req['__como']);
        }
        if (isset($req['__seg'])) {
            $this->scp->seguimientos = array(
                array('type' => 'mcm_incidencia', 'texto' => 'Se fue antes sin avisar'),
                array('type' => 'mcm_valoracion', 'texto' => 'Buen trimestre'),
                array('type' => 'mcm_acompanamiento', 'texto' => 'ESTO ES PRIVADO'),
            );
            unset($req['__seg']);
        }
        if (isset($req['__evPersona'])) {
            $this->scp->conEventos = true;
            $this->scp->evPersona = true;
            unset($req['__evPersona']);
        }
        $_REQUEST = $req;
        $_GET = $req;
        return $this->render($page);
    }

    #[PHPUnit\Framework\Attributes\DataProvider('pantallas')]
    public function testCadaPantallaSaleIgualPorElMiddleware($page, $req)
    {
        $porApi = $this->pintar($page, $req, false);
        $llamadasApi = $this->scp->calls;
        $this->assertNotEmpty($llamadasApi, 'la pantalla tiene que leer algo');

        $porMw = $this->pintar($page, $req, true);
        $this->assertSame($porApi, $porMw, 'la pantalla cambia al leer por el middleware');
        $this->assertNotEmpty($this->lotes, 'no ha pedido nada al middleware');
        $this->assertSame('sql', sticpa_mw_estado());

        // Lo que sigue yendo al CRM son solo los cargadores de pasos posteriores
        // (fichas, seguimientos…), nunca uno de los de 1a y 1b.
        $deFase1 = array('getRecordsModule:ajmcm_GRUPOS', 'getRecordsModule:stic_Contacts_Relationships',
            'getRecordsModule:stic_Events', 'getRecordsModule:LIS_listas', 'getRecordsModule:stic_Attendances');
        $this->assertSame(array(), array_values(array_intersect($this->scp->calls, $deFase1)),
            'una lectura de la fase 1 ha ido a la API');
        $this->assertLessThan(count($llamadasApi), count($this->scp->calls));
    }

    /**
     * GUARDAR CON EL MIDDLEWARE ENCENDIDO. Escribir sigue por la API; la
     * relectura que confirma «Lista guardada» va a la base (decisión
     * `relectura`), sin memo: tiene que ver lo que se acaba de escribir.
     */
    public function testGuardarEscribePorLaApiYReleePorElMiddleware()
    {
        $this->setUp();
        $this->encender();
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c1' => 'no_justified')),
        );
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringContainsString('Lista guardada', $html);
        $escritas = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_Attendances';
        }));
        $this->assertCount(1, $escritas);
        $this->assertSame('no_justified', $escritas[0]['data']['status']);
        // Después de escribir, la relectura de la sesión ha salido del middleware.
        $despues = array_merge(...array_slice($this->lotes, 1));
        $this->assertContains('sessionAttendances', $despues);
        $this->assertSame(array(), array_values(array_intersect($this->scp->calls, array('getRecordsModule:LIS_listas'))));
    }

    public function testLaPortadaEsUnLotePorTanda()
    {
        $this->pintar('single_stic_pasar_lista', array(), true);
        $this->assertSame(array(), $this->scp->calls, 'la portada no debería tocar el CRM');
        $this->assertCount(2, $this->lotes, 'una petición por tanda: ' . json_encode($this->lotes));
        $primera = $this->lotes[0];
        sort($primera);
        $this->assertSame(array('events', 'groups', 'listas', 'myRels', 'relations'), $primera);
        $this->assertSame(array('eventSessions'), array_unique($this->lotes[1]));
    }

    /**
     * EL COSTE CON EL MIDDLEWARE: peticiones al middleware por pantalla (cada
     * una es un viaje y una conexión a la base) y ninguna lectura al CRM. Es
     * el CosteLlamadasTest de este camino: el número no sube sin que se vea.
     */
    public function testTopesDePeticionesAlMiddleware()
    {
        $topes = array(
            'portada' => 2, 'portada de coordinación' => 2, 'árbol de grupos' => 2, 'marcar' => 3,
            'resumen' => 2, 'mis grupos' => 1, 'mis grupos, un grupo' => 1, 'coordinación' => 2,
            // La ficha: lo sensible va en su propia petición dentro de la misma tanda.
            'ficha' => 5, 'ficha con eventos' => 5, 'ficha del monitor' => 5, 'lista de monitores' => 3,
        );
        foreach (self::pantallas() as $nombre => $p) {
            $this->pintar($p[0], $p[1], true);
            $this->assertLessThanOrEqual($topes[$nombre], count($this->lotes), $nombre . ': ' . json_encode($this->lotes));
            $this->assertSame(array(), $this->scp->calls, $nombre . ' ha leído del CRM');
        }
    }

    public function testMarcarSinTocarElCrm()
    {
        $this->pintar('single_stic_pasar_lista_marcar', array('grupo' => 'g1'), true);
        $this->assertSame(array(), $this->scp->calls);
        $recursos = array_merge(...$this->lotes);
        foreach (array('eventSessions', 'eventRegistrations', 'sessionAttendances', 'attendancesForSessions') as $r) {
            $this->assertContains($r, $recursos);
        }
    }

    public function testSiNoContestaSeLeePorLaApiYSePausa()
    {
        $porApi = $this->pintar('single_stic_pasar_lista', array(), false);
        $this->setUp();
        $this->encender();
        $this->modo = 'caido';
        $_REQUEST = $_GET = array();
        $html = $this->render('single_stic_pasar_lista');
        $this->assertSame($porApi, $html);
        $this->assertCount(1, $this->lotes, 'tras el primer fallo no se vuelve a intentar en la misma petición');
        $this->assertNotEmpty($this->scp->calls);
        $this->assertNotFalse(get_transient('sticpa_mw_caido'), 'queda en pausa para las siguientes');
        $this->assertStringContainsString('API', sticpa_mw_badge_html() ?: 'API');

        // La siguiente petición ni lo intenta.
        unset($GLOBALS['sticpa_mw']);
        $this->lotes = array();
        $this->scp = new FakeSCP();
        $this->render('single_stic_pasar_lista');
        $this->assertSame(array(), $this->lotes);
    }

    public function testUnRechazoEsDeEstaPersonaYNoPausaALosDemas()
    {
        $porApi = $this->pintar('single_stic_pasar_lista', array(), false);
        $this->setUp();
        $this->encender();
        $this->modo = 'rechaza';
        $this->assertSame($porApi, $this->render('single_stic_pasar_lista'));
        $this->assertCount(1, $this->lotes);
        $this->assertFalse(get_transient('sticpa_mw_caido'));
    }

    public function testSinConfigurarOEnModoAdminsNoSeUsa()
    {
        // Sin URL ni secreto: nada.
        $this->assertFalse(sticpa_mw_on('groups'));
        // Configurado pero en modo «admins» y quien entra no lo es.
        $this->encender();
        $GLOBALS['__stic_options']['sticpa_mw_modo'] = 'admins';
        unset($GLOBALS['sticpa_mw']);
        $this->assertFalse(sticpa_mw_on('groups'));
        // En la lista de quienes lo prueban.
        $GLOBALS['__stic_options']['sticpa_mw_admins'] = 'otro, m1';
        unset($GLOBALS['sticpa_mw']);
        $this->assertTrue(sticpa_mw_on('groups'));
        // Y con ?mw=0 un administrador ve la pantalla por la API.
        $_GET['mw'] = '0';
        $this->assertFalse(sticpa_mw_on('groups'));
        // Un secreto corto deja la puerta cerrada.
        $_GET = array();
        $GLOBALS['__stic_filters']['sticpa_mw_secret'] = 'corto';
        $this->assertFalse(sticpa_mw_on('groups'));
    }

    public function testElCalentadorNuncaVaPorElMiddleware()
    {
        $this->encender();
        $this->assertTrue(sticpa_mw_on('groups'));
        sticpa_pl_delegation_forced('deleg-castellon');
        try {
            $this->assertFalse(sticpa_mw_on('groups'));
        } finally {
            sticpa_pl_delegation_forced('');
        }
    }

    public function testLaFirmaEsLaDelMiddleware()
    {
        // Calculada con src/authz/plugin-signature.ts (`sign`) para estos mismos datos.
        $this->assertSame(
            '6f16987064b7cc140e6d53acf893ce15a5583d0465da5e08752ebd3dfdfbbe37',
            sticpa_mw_signature(self::SECRET, '1793268000', 'post', '/plugin/v1/lote', '{"persona":"m1"}')
        );
    }

    public function testLasFilasTomanLaFormaDeLaApi()
    {
        $rows = sticpa_mw_api_rows(array(array(
            'id' => 'r1',
            'relationship_type' => 'monitor',
            'grupo' => array('id' => 'g1', 'name' => 'C1'),
            'persona' => null,
        )));
        $v = $rows[0]->name_value_list;
        $this->assertSame('r1', $rows[0]->id);
        $this->assertSame('monitor', $v->relationship_type->value);
        $this->assertSame('C1', $v->grupo->value);
        $this->assertSame('g1', $v->grupo_id->value);
        $this->assertSame('C1', $v->grupo_link->name->value);
        $this->assertSame('', $v->persona->value);
        $this->assertNull($v->persona_link);
        $this->assertSame(array(), $rows[0]->link_list);
    }

    /** El modo comparar de los administradores, con la ficha de una persona: todo igual por los dos lados. */
    public function testElModoCompararNoEncuentraDiferencias()
    {
        $this->encender();
        $this->scp->conEventos = true;
        $this->scp->evPersona = true;
        $_GET = array('participante' => 'c1', 'mw_comparar' => '1');
        $html = sticpa_mw_comparar_html($this->scp);
        foreach (array('groups', 'relations', 'myRels', 'events', 'listas', 'ficha', 'familyRels', 'avisos', 'personEvents') as $caso) {
            $this->assertStringContainsString('<code>' . $caso . '</code>', $html);
        }
        $this->assertStringContainsString('<code>registrationAttendances ', $html);
        $this->assertStringNotContainsString('diferencias', strip_tags($html), $html);
        $this->assertStringNotContainsString('no ha contestado', $html);
        // Y sin un solo dato de la persona en pantalla: solo ids y formas.
        $this->assertStringNotContainsString('Solete', $html);
    }

    /** Y con la ficha de un monitor vista por quien coordina. */
    public function testElModoCompararIncluyeLaFichaDelMonitor()
    {
        $this->encender();
        $this->scp->coordEtapa = 'COM';
        $_SESSION['scp_user_id'] = 'coord1';
        $this->scp->seguimientos = array(array('type' => 'mcm_incidencia', 'texto' => 'Se fue antes sin avisar'));
        $_GET = array('monitor' => 'm1', 'mw_comparar' => '1');
        $html = sticpa_mw_comparar_html($this->scp);
        $this->assertStringContainsString('<code>monitorFicha</code>', $html);
        $this->assertStringContainsString('<code>seguimientos</code>', $html);
        $this->assertStringNotContainsString('diferencias', strip_tags($html), $html);
        $this->assertStringNotContainsString('Se fue antes', $html);
    }

    public function testGuardarOlvidaLoTraido()
    {
        $this->encender();
        $st = &sticpa_mw_state();
        $st['memo']['x'] = array('ok' => true, 'rows' => array());
        sticpa_pl_flush($this->scp, 'state');
        $this->assertSame(array(), $st['memo']);
    }

    public function testLaEtiquetaSoloLaVenLosAdministradores()
    {
        $this->encender();
        $this->assertSame('', sticpa_mw_badge_html(), 'en modo todos, quien no es administrador no la ve');
        $GLOBALS['__stic_options']['sticpa_mw_admins'] = 'm1';
        unset($GLOBALS['sticpa_mw']);
        $this->pintar('single_stic_pasar_lista', array(), true);
        $GLOBALS['__stic_options']['sticpa_mw_admins'] = 'm1';
        $st = &sticpa_mw_state();
        $st['admin'] = null;
        $this->assertStringContainsString('SQL', sticpa_mw_badge_html());
    }
}
