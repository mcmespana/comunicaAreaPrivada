<?php

use PHPUnit\Framework\TestCase;

/**
 * Doble de SugarRestApiCall: devuelve respuestas con la MISMA forma que la API
 * v4.1 de SuiteCRM (`name_value_list`, `link_list → records → link_value`), que
 * es donde está toda la gracia: esa forma anidada es la que rompe el código si
 * se recorre a la ligera.
 *
 * Cuenta las llamadas, así que también sirve para comprobar que la pantalla de
 * marcado no se va a una consulta por participante.
 */
class FakeSCP
{
    public $calls = array();
    public $writes = array();
    public $relationships = array();
    /** Cuando es distinto de null, el usuario coordina con este alcance. */
    public $coordEtapa = null;
    /** Cuando es true, el usuario acompaña. */
    public $isAcomp = false;
    /** Seguimientos que devuelve el CRM (claves ya del CRM: mcm_*). */
    public $seguimientos = array();

    /** Envuelve un array plano en la forma que devuelve la API. */
    private function nvl($fields, $links = array())
    {
        $row = new stdClass();
        $row->name_value_list = new stdClass();
        foreach ($fields as $k => $v) {
            $row->name_value_list->$k = (object) array('name' => $k, 'value' => $v);
        }
        if (!empty($links)) {
            $link = new stdClass();
            $link->records = array();
            foreach ($links as $linked) {
                $lv = new stdClass();
                foreach ($linked as $k => $v) {
                    $lv->$k = (object) array('name' => $k, 'value' => $v);
                }
                $link->records[] = (object) array('link_value' => $lv);
            }
            $row->link_list = array($link);
        }
        return $row;
    }

    public function getRecordDetail($id, $module, $fields = null)
    {
        $this->calls[] = 'getRecordDetail:' . $module;
        $data = array('id' => $id, 'assigned_user_id' => 'deleg-castellon');
        if (is_array($fields) && in_array('ajmcm_mat_c', $fields, true)) {
            // Los valores tal como están en la instancia de verdad.
            $data = array_merge($data, array(
                'first_name' => 'David', 'last_name' => 'Soler', 'name' => 'David Soler',
                'stic_age_c' => '30', 'phone_mobile' => '608 084 613',
                'email1' => 'david@movimientoconsolacion.com',
                'ajmcm_monitor_desde_c' => '2012-01-01', 'ajmcm_monitor_de_c' => 'com',
                'ajmcm_aut_del_sex_c' => '1', 'ajmcm_cert_del_sex_c' => '1',
                'ajmcm_premonitores1_c' => 'finalizado', 'ajmcm_premonitores2_c' => 'finalizado',
                'ajmcm_premonitores_year_c' => '2012',
                'ajmcm_mat_c' => 'titulado', 'ajmcm_mat_year_c' => '2013', 'ajmcm_mat_file_c' => '1',
                'ajmcm_dat_c' => 'titulado', 'ajmcm_dat_year_c' => '2021 - EADB', 'ajmcm_dat_file_c' => '0',
                'ajmcm_fa_c' => 'no', 'ajmcm_alimentos_c' => '1',
            ));
        } elseif (is_array($fields) && in_array('ajmcm_panuelo_c', $fields, true)) {
            $data = array_merge($data, array(
                'first_name' => 'Solete', 'last_name' => 'Vilarroya', 'name' => 'Solete Vilarroya',
                'birthdate' => '2012-04-18', 'stic_age_c' => '13',
                'phone_mobile' => '600 111 222', 'phone_other' => '964 200 300',
                'ajmcm_etapa_c' => 'COM', 'ajmcm_panuelo_c' => 'verde', 'ajmcm_tallas_c' => 'S',
                'ajmcm_soloacasa_c' => '1', 'ajmcm_menorwhatsapp_c' => '0',
                'ajmcm_cesionimagenes_interne_c' => '1',
                'ajmcm_descripcion_allergies__c' => 'Frutos secos (anafilaxia)',
                'ajmcm_descripcion_intoler_c' => '',
            ));
        }
        $out = new stdClass();
        $out->entry_list = array($this->nvl($data));
        return $out;
    }

    public function getRecordsModule($module, $query = '', $fields = array(), $rel = null)
    {
        $this->calls[] = 'getRecordsModule:' . $module;
        if ($module === 'ajmcm_GRUPOS') {
            // `cursos_c` lleva el CURSO ESCOLAR, que es lo que hay en el CRM de
            // verdad: "1º ESO", "Adultos", "6º Primària"… NO el año académico.
            // Este doble decía "2025-2026" y por eso los tests daban por bueno
            // un filtro que en producción escondía 19 de los 27 grupos.
            return array(
                $this->nvl(array('id' => 'g1', 'name' => 'Los Peques', 'code' => 'C1', 'level' => 'COM', 'cursos_c' => '1º ESO')),
                $this->nvl(array('id' => 'g2', 'name' => 'C2', 'code' => 'C2', 'level' => 'COM', 'cursos_c' => '2º ESO')),
                $this->nvl(array('id' => 'g3', 'name' => 'Los Micos', 'code' => 'M1', 'level' => 'MIC', 'cursos_c' => '5º Primaria')),
                // Sin curso escolar puesto: pasa igual, como en el CRM.
                $this->nvl(array('id' => 'g9', 'name' => 'Ruah', 'code' => '', 'level' => 'LC')),
            );
        }
        if ($module === 'stic_Contacts_Relationships') {
            // getRecordsModule mete el enlace pedido como un campo más.
            return array(
                $this->nvl(array('id' => 'r1', 'relationship_type' => 'participante_mic_com', 'end_date' => '', 'grupo' => 'Los Peques', 'persona' => 'Solete Vilarroya')),
                $this->nvl(array('id' => 'r7', 'relationship_type' => 'participante_mic_com', 'end_date' => '', 'grupo' => '', 'persona' => 'Sol Messeguer')),
                $this->nvl(array('id' => 'r8', 'relationship_type' => 'participante_mic_com', 'end_date' => '', 'grupo' => '', 'persona' => 'Lucia Ripolles')),
                // Monitor sin grupo: no sale en la lista de participantes.
                $this->nvl(array('id' => 'r9', 'relationship_type' => 'monitor', 'end_date' => '', 'grupo' => '', 'persona' => 'Un Monitor')),
                // Curso pasado: ya no falta nada.
                $this->nvl(array('id' => 'r10', 'relationship_type' => 'participante_mic_com', 'end_date' => '2024-06-30', 'grupo' => '', 'persona' => 'Del Curso Pasado')),
            );
        }
        if ($module === 'stic_Events') {
            return array(
                // Un solo evento para MIC y COM, y el nombre NO dice la etapa:
                // así lo unico que puede resolverla es el campo de seleccion
                // multiple, que es lo que se quiere comprobar.
                $this->nvl(array(
                    'id' => 'ev-todo',
                    'name' => 'Sesiones semanales 2025-2026',
                    'ajmcm_etapa_c' => '^MIC^,^COM^',
                )),
                // Sin campo relleno: cae en el nombre, que es lo que pasa con
                // los eventos creados antes de que el campo existiera.
                $this->nvl(array('id' => 'ev-lc', 'name' => 'LC | Sesiones semanales 2025-2026', 'ajmcm_etapa_c' => '')),
                // Trampa: lleva "COM" pero no es el evento de la etapa.
                $this->nvl(array('id' => 'ev-conv', 'name' => 'Convivencia de familias del COM 2025-2026', 'ajmcm_etapa_c' => '')),
            );
        }
        return array();
    }

    public function getRelatedElementsForLoggedUser($p)
    {
        $key = $p['module_name'] . ':' . $p['link_field_name'];
        $this->calls[] = $key;

        switch ($key) {
            // Personas del grupo: participantes y monitor, en UNA llamada.
            case 'ajmcm_GRUPOS:ajmcm_grupos_stic_contacts_relationships':
                // Solo g1 tiene gente. Devolver la misma para todos los grupos
                // haría que un alcance por etapa pareciese incluir monitores
                // que no le tocan, y el test dejaría de comprobar nada.
                if ($p['module_id'] !== 'g1') {
                    return array();
                }
                return array(
                    $this->nvl(
                        array('id' => 'r1', 'relationship_type' => 'participante_mic_com', 'start_date' => '2025-09-01', 'end_date' => ''),
                        array(array('id' => 'c1', 'first_name' => 'Solete', 'last_name' => 'Vilarroya', 'stic_age_c' => '13', 'phone_mobile' => '600111222'))
                    ),
                    $this->nvl(
                        array('id' => 'r2', 'relationship_type' => 'participante_mic_com', 'start_date' => '2025-09-01', 'end_date' => ''),
                        array(array('id' => 'c2', 'first_name' => 'Jaume', 'last_name' => 'Pascual', 'stic_age_c' => '13'))
                    ),
                    // Relación caducada: no debe salir en la lista de hoy.
                    $this->nvl(
                        array('id' => 'r3', 'relationship_type' => 'participante_mic_com', 'end_date' => '2025-10-01'),
                        array(array('id' => 'c9', 'first_name' => 'Se', 'last_name' => 'Fue'))
                    ),
                    $this->nvl(
                        array('id' => 'r4', 'relationship_type' => 'monitor', 'end_date' => ''),
                        array(array('id' => 'm1', 'first_name' => 'David', 'last_name' => 'Soler'))
                    ),
                );

            case 'Contacts:stic_contacts_relationships_contacts':
                $rels = array($this->nvl(
                    array('id' => 'r4', 'relationship_type' => 'monitor', 'end_date' => ''),
                    array(array('id' => 'g1'))
                ));
                if ($this->coordEtapa !== null) {
                    $rels[] = $this->nvl(array(
                        'id' => 'rc1',
                        'relationship_type' => 'coordinacion_mic_com',
                        'end_date' => '',
                        'ajmcm_etapa_relacion_c' => $this->coordEtapa,
                    ));
                }
                if ($this->isAcomp) {
                    $rels[] = $this->nvl(array(
                        'id' => 'ra1',
                        'relationship_type' => 'acompanamiento_mic_com',
                        'end_date' => '',
                    ));
                }
                return $rels;

            case 'stic_Events:stic_sessions_stic_events':
                return array(
                    $this->nvl(array('id' => 's1', 'start_date' => '2025-11-01 16:30:00', 'end_date' => '2025-11-01 18:00:00')),
                    $this->nvl(array('id' => 's2', 'start_date' => '2025-11-08 16:30:00', 'end_date' => '2025-11-08 18:00:00')),
                    $this->nvl(array('id' => 's3', 'start_date' => '2025-11-15 16:30:00', 'end_date' => '2025-11-15 18:00:00')),
                    $this->nvl(array('id' => 's4', 'start_date' => '2025-11-22 16:30:00', 'end_date' => '2025-11-22 18:00:00')),
                );

            case 'stic_Events:stic_registrations_stic_events':
                return array(
                    $this->nvl(array('id' => 'reg1', 'status' => 'confirmed'), array(array('id' => 'c1'))),
                    $this->nvl(array('id' => 'reg2', 'status' => 'confirmed'), array(array('id' => 'c2'))),
                    // Cancelada: su asistencia no debe aparecer.
                    $this->nvl(array('id' => 'reg9', 'status' => 'cancelled'), array(array('id' => 'c9'))),
                );

            case 'stic_Sessions:stic_attendances_stic_sessions':
                return array(
                    $this->nvl(array('id' => 'a1', 'status' => 'yes'), array(array('id' => 'reg1'))),
                    $this->nvl(array('id' => 'a2', 'status' => ''), array(array('id' => 'reg2'))),
                );

            // Histórico de un participante: todas sus asistencias del curso.
            case 'stic_Registrations:stic_attendances_stic_registrations':
                return array(
                    $this->nvl(array('id' => 'a1', 'status' => 'yes'), array(array('id' => 's1'))),
                    $this->nvl(array('id' => 'a2', 'status' => 'no_unjustified'), array(array('id' => 's2'))),
                    $this->nvl(array('id' => 'a3', 'status' => 'partial'), array(array('id' => 's3'))),
                );

            // Familia: la relación puede estar creada en cualquiera de los dos
            // sentidos, así que el doble solo contesta por uno de los enlaces.
            case 'Contacts:stic_personal_environment_contacts':
                return array($this->nvl(
                    array('id' => 'pe1', 'relationship_type' => 'madre', 'reference_contact' => '1', 'authorized_signer' => '1', 'end_date' => ''),
                    array(
                        array('id' => 'c1'),                                    // el propio participante: se descarta
                        array('id' => 'fam1', 'first_name' => 'Solete', 'last_name' => 'Messeguer', 'phone_mobile' => '600 333 444'),
                    )
                ));

            // Seguimientos de una persona (stic_FollowUps).
            // Avisos de comportamiento. Módulo verificado contra el CRM con
            // get_module_fields: existe, y `ajmcm_notificado_el_c` de la
            // especificación NO se creó (solo el booleano). Dos avisos de este
            // curso y uno del curso pasado, que NO tiene que contar: el
            // recuento «de 3» es del curso.
            case 'Contacts:avi_avisos_contacts':
                if ($p['module_id'] !== 'c1') {
                    return array();
                }
                return array(
                    // A propósito en orden inverso: el número sale de ordenar
                    // por fecha, no del orden en que los devuelve el CRM.
                    $this->nvl(array(
                        'id' => 'av2', 'fecha' => '2025-12-13', 'motivo' => 'Faltas de respeto a una compañera',
                        'ajmcm_puesto_por_c' => 'David Soler',
                        'ajmcm_notificado_familia_c' => '0',
                    )),
                    $this->nvl(array(
                        'id' => 'av1', 'fecha' => '2025-11-08', 'motivo' => 'Se fue del local sin avisar',
                        'ajmcm_puesto_por_c' => 'Mercedes',
                        'ajmcm_notificado_familia_c' => '1',
                    )),
                    // Curso 2024-2025: es historia, no cuenta.
                    $this->nvl(array(
                        'id' => 'av0', 'fecha' => '2025-02-10', 'motivo' => 'Del curso pasado',
                        'ajmcm_puesto_por_c' => 'Alguien',
                        'ajmcm_notificado_familia_c' => '1',
                    )),
                );

            case 'Contacts:stic_followups_contacts':
                $out = array();
                foreach ($this->seguimientos as $i => $seg) {
                    $out[] = $this->nvl(array(
                        'id' => 'seg' . $i,
                        'name' => 'x',
                        'description' => $seg['texto'],
                        'type' => $seg['type'],
                        'date_start' => '2026-01-10 12:00:00',
                        'assigned_user_name' => 'MCM Castellón',
                    ));
                }
                return $out;

            case 'stic_Sessions:lis_listas_stic_sessions':
                // Solo la sesión s3 tiene lista pasada: las anteriores están sin
                // pasar, que es lo que el selector tiene que distinguir.
                if ($p['module_id'] !== 's3') {
                    return array();
                }
                return array($this->nvl(
                    array('id' => 'l1', 'estado' => 'pasada', 'pasada_el' => '2025-11-15 18:05:00', 'n_asistieron' => 2, 'n_faltaron' => 0),
                    array(array('id' => 'g1'))
                ));
        }
        return array();
    }

    public function set_entry($module, $data)
    {
        $this->writes[] = array('module' => $module, 'data' => $data);
        return isset($data['id']) ? $data['id'] : 'new-' . count($this->writes);
    }

    public function set_relationship($module, $id, $link, $ids)
    {
        $this->relationships[] = array('module' => $module, 'id' => $id, 'link' => $link, 'ids' => $ids);
        return true;
    }
}

/**
 * Las pantallas de Pasar Lista, ejecutadas de verdad contra un CRM falso.
 *
 * Por qué existe este test: las páginas del plugin son PHP suelto que se
 * incluye, no funciones, así que un `php -l` no dice nada y un error tonto
 * (índice que no existe, función mal escrita) solo aparece cuando un monitor
 * abre la pantalla el sábado. Aquí se ejecutan enteras y se exige que no emitan
 * ni un aviso.
 */
final class PasarListaRenderTest extends TestCase
{
    private $scp;

    protected function setUp(): void
    {
        // Un sábado a las 17:00, en mitad de la sesión: el caso normal.
        $GLOBALS['__stic_pl_now'] = mktime(17, 0, 0, 11, 15, 2025);
        $GLOBALS['__stic_transients'] = array();
        $GLOBALS['__stic_filters'] = array();
        $_SESSION = array(
            'scp_user_id' => 'm1',
            'scp_user_assigned_user_id' => 'deleg-castellon',
            'scp_user_contact_name' => 'David Soler',
        );
        $_REQUEST = array();
        $_POST = array();
        $this->scp = new FakeSCP();
        $GLOBALS['__stic_filters']['sticpa_pl_seguimientos_enabled'] = true;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__stic_pl_now']);
        $_SESSION = array();
        $_REQUEST = array();
        $_POST = array();
    }

    /**
     * Ejecuta una página como lo hace el plugin: con $objSCP y $html en scope.
     * Cualquier aviso de PHP se convierte en fallo del test.
     */
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

    // ---- Home ------------------------------------------------------------

    public function test_home_pinta_el_atajo_de_tu_grupo()
    {
        $html = $this->render('single_stic_pasar_lista');

        $this->assertStringContainsString('pl-hero', $html);
        $this->assertStringContainsString('C1', $html);
        $this->assertStringContainsString('Los Peques', $html);
        // La lista de g1 ya está pasada en el doble, así que el atajo lo dice.
        $this->assertStringContainsString('Revisar la lista', $html);
        $this->assertStringContainsString('single_stic_pasar_lista_grupos', $html);
    }

    // ---- Árbol de grupos -------------------------------------------------

    /**
     * El atajo lleva las dos piezas que contestan "¿qué toca?": la pastilla con
     * el CUÁNDO en relativo y la cápsula de fecha del §11 del sistema de
     * diseño, que es cómo se dice un día en toda el área privada.
     */
    public function test_home_atajo_con_pastilla_y_capsula_de_fecha()
    {
        $html = $this->render('single_stic_pasar_lista');

        // La cápsula es el componente que ya existe, no otro inventado aquí.
        $this->assertStringContainsString('stic-cell-badge pl-hero-date', $html);
        $this->assertStringContainsString('<span class="stic-cell-badge-day">15</span>', $html);
        // En el doble la lista de g1 ya está pasada, así que la pastilla lo dice
        // y la línea de datos enseña el RESULTADO en vez de la hora: cuando ya
        // está hecha, lo que se quiere saber es cómo fue.
        $this->assertStringContainsString('<span class="pl-hero-when">Pasada</span>', $html);
        $this->assertStringContainsString('2 vinieron, 0 ausencias', $html);
        $this->assertStringContainsString('pl-hero--done', $html);
    }

    public function test_arbol_pone_tu_grupo_primero_y_agrupa_por_etapa()
    {
        $html = $this->render('single_stic_pasar_lista_grupos');

        // Tu grupo, con el filete de degradado, antes que cualquier etapa.
        $this->assertStringContainsString('pl-mine', $html);
        $this->assertLessThan(strpos($html, 'pl-etapa-title'), strpos($html, 'pl-mine'));
        // Iniciales del monitor conectado, sacadas de la sesión.
        $this->assertStringContainsString('>DS<', $html);
        // Las dos etapas con grupos.
        $this->assertStringContainsString('>MIC<', $html);
        $this->assertStringContainsString('>COM<', $html);
    }

    /** El grupo de otro curso no aparece: es el filtro de `cursos_c`. */
    /**
     * El árbol dice de qué grupo FALTA la lista, que es para lo que se abre. El
     * círculo sale de una consulta por etapa, no por grupo.
     */
    public function test_arbol_pinta_el_estado_de_la_ultima_lista()
    {
        $html = $this->render('single_stic_pasar_lista_grupos');

        $this->assertStringContainsString('pl-tree-legend', $html);
        $this->assertStringContainsString('Pendiente', $html);
        $this->assertStringContainsString('No hubo', $html);
        // g1 pasó la última (s3): círculo verde.
        $this->assertStringContainsString('pl-done pl-done--yes', $html);
    }

    /**
     * El árbol enseña TODOS los grupos de la delegación: con curso escolar
     * puesto y sin él.
     *
     * Antes había aquí un filtro que descartaba el grupo si su `cursos_c` no
     * contenía el año académico ("2025-2026"). Pero ese campo lleva el curso
     * ESCOLAR ("1º ESO"), así que el filtro escondía todos los grupos que lo
     * tuvieran puesto: en Castellón, 19 de 27. El registro del grupo no tiene
     * ningún campo con el año, así que no hay nada que filtrar aquí; el año lo
     * pone la vigencia de las relaciones de cada persona.
     */
    public function test_arbol_enseña_todos_los_grupos_de_la_delegacion()
    {
        $html = $this->render('single_stic_pasar_lista_grupos');

        $this->assertStringContainsString('C1', $html);
        $this->assertStringContainsString('C2', $html);
        $this->assertStringContainsString('M1', $html);
        // El que no tiene curso escolar puesto también sale.
        $this->assertStringContainsString('Ruah', $html);
    }

    /** Y el curso escolar se enseña como dato, que es para lo que sirve. */
    public function test_arbol_enseña_el_curso_escolar_del_grupo()
    {
        $html = $this->render('single_stic_pasar_lista_grupos');
        $this->assertStringContainsString('1º ESO', $html);
        $this->assertStringContainsString('5º Primaria', $html);
    }

    /** El nombre igual al código no se repite: "C2", no "C2 · C2". */
    public function test_arbol_no_repite_codigo_y_nombre()
    {
        $html = $this->render('single_stic_pasar_lista_grupos');
        $this->assertStringNotContainsString('C2</span><span class="pl-title-name">C2', $html);
    }

    /** El selector de sesión enseña el estado de cada lista. */
    public function test_selector_de_sesiones()
    {
        $_REQUEST = array('grupo' => 'g1', 'sesiones' => '1');
        $html = $this->render('single_stic_pasar_lista_grupos');

        // El historial de listas: el selector rápido de sesión vive ahora en la
        // cabecera de marcado (un <select> nativo); esta pantalla es la que
        // enseña el ESTADO de cada lista, que en un desplegable no cabe.
        $this->assertStringContainsString('Historial de listas', $html);
        // La lista de s3 está pasada con 2 y 0; las otras, sin pasar.
        $this->assertStringContainsString('2 vinieron', $html);
        $this->assertStringContainsString('Sin pasar', $html);
        // La sesión que aún no ha llegado (s4) no se ofrece.
        $this->assertStringNotContainsString('sesion=s4', $html);
    }

    // ---- Marcar ----------------------------------------------------------

    public function test_marcar_pinta_la_lista_con_el_estado_del_crm()
    {
        $_REQUEST = array('grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringContainsString('data-pl-marcar', $html);
        // c1 viene con 'yes' del CRM; c2 sin marcar.
        $this->assertStringContainsString('data-state="yes" data-contact="c1"', $html);
        $this->assertStringContainsString('data-state="" data-contact="c2"', $html);
        // El monitor sale en la cabecera, no en la lista de marcar.
        $this->assertStringContainsString('David Soler', $html);
        $this->assertStringNotContainsString('data-contact="m1"', $html);
        // La relación caducada no está.
        $this->assertStringNotContainsString('data-contact="c9"', $html);
    }

    public function test_marcar_incluye_leyenda_gesto_largo_y_hoja()
    {
        $_REQUEST = array('grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_marcar');

        // Sin el chip, parcial y justificada son invisibles para el usuario.
        $this->assertStringContainsString('Mantén pulsado', $html);
        $this->assertStringContainsString('pl-hold-ring', $html);
        // La hoja, con los cuatro estados del CRM.
        $this->assertStringContainsString('data-pl-sheet', $html);
        foreach (array('yes', 'partial', 'no_justified', 'no_unjustified') as $key) {
            $this->assertStringContainsString('data-value="' . $key . '"', $html);
        }
    }

    /** Sin sesión en curso, la de hoy: y a las 17:00 no hay aviso ninguno. */
    public function test_marcar_en_plena_sesion_no_avisa_de_nada()
    {
        $_REQUEST = array('grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_marcar');
        $this->assertStringNotContainsString('aún no han llegado', $html);
    }

    /** Un sábado a las 10:00 sí avisa de que la sesión no ha empezado. */
    public function test_marcar_por_la_manana_avisa_de_que_no_ha_empezado()
    {
        $GLOBALS['__stic_pl_now'] = mktime(10, 0, 0, 11, 15, 2025);
        $_REQUEST = array('grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_marcar');
        $this->assertStringContainsString('aún no han llegado', $html);
        $this->assertStringContainsString('16:30', $html);
    }

    /**
     * La propiedad que importa del rendimiento: marcar NO hace una consulta por
     * participante. Si alguien añade un bucle con una llamada dentro, esto salta.
     *
     * Las asistencias se piden por SESIÓN: una para la sesión que se marca y
     * hasta `umbral` más hacia atrás para las ausencias seguidas. Ese techo es
     * el que se fija aquí, y lo importante es que no depende de cuánta gente
     * haya en el grupo — que es justo lo que el test de abajo comprueba.
     */
    public function test_marcar_no_consulta_una_vez_por_participante()
    {
        $_REQUEST = array('grupo' => 'g1');
        $this->render('single_stic_pasar_lista_marcar');

        $people = array_filter($this->scp->calls, function ($c) {
            return $c === 'ajmcm_GRUPOS:ajmcm_grupos_stic_contacts_relationships';
        });
        $att = array_filter($this->scp->calls, function ($c) {
            return $c === 'stic_Sessions:stic_attendances_stic_sessions';
        });
        $this->assertCount(1, $people, 'las personas del grupo salen en UNA llamada');
        $this->assertLessThanOrEqual(
            1 + sticpa_pl_streak_threshold(),
            count($att),
            'las asistencias se piden por sesión, no por participante'
        );
    }


    /** Un grupo que no existe no pinta media pantalla: manda al árbol. */
    public function test_marcar_sin_grupo_valido_manda_al_arbol()
    {
        $_REQUEST = array('grupo' => 'no-existe');
        $html = $this->render('single_stic_pasar_lista_marcar');
        $this->assertStringContainsString('Ver los grupos', $html);
        $this->assertStringNotContainsString('data-pl-marcar', $html);
    }

    // ---- El selector de sesión: un <select> nativo ------------------------

    /**
     * En el móvil —el 99 % de los usos— el desplegable nativo es una rueda a
     * pulgar y se abre en la misma pantalla. Antes esto era un viaje al árbol
     * para volver con una fecha: tres toques de más cada sábado.
     */
    public function test_marcar_lleva_el_selector_de_sesion_nativo()
    {
        $_REQUEST = array('grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringContainsString('data-pl-session-jump', $html);
        // Cada opción es "número · fecha corta": el número es como se habla de
        // una sesión, la fecha es como se comprueba que es la buena.
        $this->assertStringContainsString('3 · 15 Nov', $html);
        $this->assertStringContainsString('1 · 1 Nov', $html);
        // El valor de la opción ES la url: elegir es ir.
        $this->assertStringContainsString('sesion=s2', $html);
        // La sesión que aún no ha llegado no se ofrece: no se pasa lista del futuro.
        $this->assertStringNotContainsString('sesion=s4', $html);
    }

    // ---- Guardado --------------------------------------------------------

    /**
     * El motivo de la hoja de estados va al campo `description` de la
     * asistencia, que es donde el CRM lo espera y donde se puede leer luego.
     */
    public function test_guardar_escribe_el_motivo_en_description()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c1' => 'no_justified')),
            // JSON_UNESCAPED_UNICODE porque es lo que manda el navegador:
            // JSON.stringify deja los acentos tal cual, sin escapes \uXXXX.
            'pl_notes' => json_encode(array('c1' => 'Avisó la madre por la mañana'), JSON_UNESCAPED_UNICODE),
        );
        $this->render('single_stic_pasar_lista_marcar');

        $attWrites = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_Attendances';
        }));
        $this->assertCount(1, $attWrites);
        $this->assertSame('Avisó la madre por la mañana', $attWrites[0]['data']['description']);
    }

    /**
     * Y si el motivo NO cambia, no se manda: repetirlo en cada guardado llena
     * el registro de auditoría del CRM de cambios que no son cambios.
     */
    public function test_guardar_no_reescribe_un_motivo_que_no_cambia()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c1' => 'yes')),
            'pl_notes' => json_encode(array('c1' => '')),
        );
        $this->render('single_stic_pasar_lista_marcar');

        $attWrites = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_Attendances';
        }));
        $this->assertCount(1, $attWrites);
        $this->assertArrayNotHasKey('description', $attWrites[0]['data']);
    }

    /** Un motivo de un contacto que no es del grupo no se escribe. */
    public function test_guardar_ignora_motivos_de_fuera_del_grupo()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c1' => 'yes')),
            'pl_notes' => json_encode(array('c99' => 'de otro grupo')),
        );
        $this->render('single_stic_pasar_lista_marcar');

        foreach ($this->scp->writes as $w) {
            if ($w['module'] === 'stic_Attendances') {
                $this->assertNotSame('de otro grupo', isset($w['data']['description']) ? $w['data']['description'] : null);
            }
        }
    }



    public function test_guardar_escribe_asistencias_y_la_lista()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c1' => 'yes', 'c2' => 'no_unjustified')),
        );
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringContainsString('Lista guardada', $html);

        $attWrites = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_Attendances';
        }));
        $this->assertCount(2, $attWrites);
        $this->assertSame('yes', $attWrites[0]['data']['status']);
        $this->assertSame('a1', $attWrites[0]['data']['id']);
        $this->assertSame('no_unjustified', $attWrites[1]['data']['status']);

        $listWrites = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'LIS_listas';
        }));
        $this->assertCount(1, $listWrites);
        $this->assertSame('pasada', $listWrites[0]['data']['estado']);
        $this->assertSame(1, $listWrites[0]['data']['n_asistieron']);
        $this->assertSame(1, $listWrites[0]['data']['n_faltaron']);
        // Ya existía: se actualiza, no se duplica.
        $this->assertSame('l1', $listWrites[0]['data']['id']);
    }

    /**
     * Sin marcar NO se escribe. Es la propiedad importante: un hueco en los
     * datos no es una falta, y escribirlo pondría una ausencia falsa en el
     * porcentaje que ve la familia.
     */
    public function test_guardar_no_escribe_los_sin_marcar()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c1' => 'yes', 'c2' => '')),
        );
        $this->render('single_stic_pasar_lista_marcar');

        $attWrites = array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_Attendances';
        });
        $this->assertCount(1, $attWrites);
    }

    /** Lo que venga del navegador no manda: ni personas de fuera ni estados raros. */
    public function test_guardar_ignora_contactos_ajenos_y_estados_inventados()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array(
                'c1' => 'yes',
                'c-de-otra-delegacion' => 'yes',
                'c2' => 'zzz_valor_inventado',
            )),
        );
        $this->render('single_stic_pasar_lista_marcar');

        $attWrites = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_Attendances';
        }));
        $this->assertCount(1, $attWrites);
        $this->assertSame('a1', $attWrites[0]['data']['id']);
    }

    /** Sin nonce válido no se escribe NADA. */
    public function test_guardar_sin_nonce_no_escribe()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => 'falso',
            'pl_marks' => json_encode(array('c1' => 'yes')),
        );
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringContainsString('caducado', $html);
        $this->assertSame(array(), $this->scp->writes);
    }

    /** "Sin registro" marca la lista como omitida y no toca las asistencias. */
    public function test_sin_registro_no_escribe_asistencias()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'skip',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c1' => 'yes', 'c2' => 'yes')),
        );
        $this->render('single_stic_pasar_lista_marcar');

        $attWrites = array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_Attendances';
        });
        $this->assertCount(0, $attWrites);

        $listWrites = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'LIS_listas';
        }));
        $this->assertSame('omitida', $listWrites[0]['data']['estado']);
    }

    // ---- La etapa del evento --------------------------------------------

    // ---- Ficha ----------------------------------------------------------

    public function test_ficha_pone_los_telefonos_primero()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_ficha');

        // Es lo primero después de la cabecera: la ficha se abre para llamar.
        $this->assertLessThan(strpos($html, 'Asistencia'), strpos($html, 'Teléfonos'));
        $this->assertStringContainsString('tel:600111222', $html);
        // La madre, marcada como referencia.
        $this->assertStringContainsString('Solete Messeguer', $html);
        $this->assertStringContainsString('REF', $html);
        $this->assertStringContainsString('wa.me/34600333444', $html);
    }

    /**
     * Sin permiso de WhatsApp del menor NO se pinta su botón de WhatsApp, pero
     * sí el de llamar: son dos cosas distintas.
     */
    /**
     * El nombre es la cara de la ficha y va en su bloque, no apretado en la
     * cabecera. Y encima de todo, los dos botones grandes: la ficha se abre
     * para llamar a una casa, así que llamar es lo primero que se puede tocar.
     */
    public function test_ficha_identidad_y_contacto_rapido()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_ficha');

        $this->assertStringContainsString('pl-ident-name', $html);
        $this->assertStringContainsString('pl-ident-avatar', $html);
        // Los botones grandes van ANTES de la lista de teléfonos.
        $this->assertLessThan(strpos($html, 'Teléfonos'), strpos($html, 'pl-contact-btn'));
        // Y apuntan al contacto de REFERENCIA de la familia, no al primero que salga.
        $this->assertStringContainsString('Llamar a Solete Messeguer', $html);
    }

    /** El porcentaje también se ve sin leer números. */
    public function test_ficha_asistencia_lleva_barra()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_ficha');

        $this->assertStringContainsString('pl-att-fill', $html);
        // El ancho es un dato, así que va en línea, y con su etiqueta accesible.
        $this->assertMatchesRegularExpression('/pl-att-fill" style="width:\d+%/', $html);
        $this->assertStringContainsString('de asistencia', $html);
    }

    // ---- Avisos de comportamiento ----------------------------------------

    /**
     * Un registro por aviso, con su fecha y quién lo puso: es justo lo que las
     * tres casillas de AppSheet no guardaban.
     */
    public function test_ficha_pinta_los_avisos_numerados_y_en_orden()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_ficha');

        $this->assertStringContainsString('Avisos de comportamiento', $html);
        // El número sale de ordenar por FECHA, no del orden del CRM: el doble
        // los devuelve al revés a propósito.
        $this->assertLessThan(
            strpos($html, 'Faltas de respeto'),
            strpos($html, 'Se fue del local'),
            'el aviso más antiguo va primero'
        );
        // Y con el color de su nivel: ámbar el 1, naranja el 2.
        $this->assertStringContainsString('#f59e0b', $html);
        $this->assertStringContainsString('#c2410c', $html);
        // Sobre el ámbar el número va en tinta oscura (con blanco eran 2,2:1);
        // sobre el naranja, blanco. Relleno fijo, tinta fija.
        $this->assertStringContainsString('background:#f59e0b;color:#451a03', $html);
        $this->assertStringContainsString('background:#c2410c;color:#fff', $html);
        // El quién y el cuándo, en la línea gris.
        $this->assertStringContainsString('lo puso Mercedes', $html);
        // El chip de la familia, uno de cada.
        $this->assertStringContainsString('Familia avisada', $html);
        $this->assertStringContainsString('Familia sin avisar', $html);
    }

    /** El recuento es DEL CURSO: un aviso del curso pasado no suma. */
    public function test_ficha_avisos_no_cuenta_los_de_otro_curso()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_ficha');

        $this->assertStringContainsString('2 de 3', $html);
        $this->assertStringNotContainsString('Del curso pasado', $html);
    }

    /**
     * Con dos de tres puestos, se avisa ANTES de poner el tercero: decirlo
     * después no sirve de nada.
     */
    public function test_ficha_avisos_avisa_de_que_el_tercero_es_la_salida()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_ficha');

        $this->assertStringContainsString('implica la salida del grupo', $html);
        $this->assertStringContainsString('pl-avi-warn', $html);
        // Va DEBAJO del último aviso y ANTES del botón de añadir.
        $this->assertLessThan(strpos($html, 'Añadir un aviso'), strpos($html, 'pl-avi-warn'));
    }

    /** El formulario sale oculto y con confirmación: poner un aviso no es un roce. */
    public function test_ficha_avisos_formulario_oculto_y_con_confirmacion()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_ficha');

        $this->assertStringContainsString('data-pl-aviso-form hidden', $html);
        $this->assertStringContainsString('data-pl-confirm', $html);
    }

    public function test_ficha_registra_un_aviso()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $_POST = array(
            'pl_nonce' => wp_create_nonce('pl_ficha_c1'),
            'pl_aviso_motivo' => 'Tiró una silla',
            'pl_aviso_fecha' => '2025-11-15',
            'pl_aviso_notificado' => '1',
        );
        $html = $this->render('single_stic_pasar_lista_ficha');

        $this->assertStringContainsString('Aviso registrado', $html);
        $writes = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'AVI_avisos';
        }));
        $this->assertCount(1, $writes);
        $this->assertSame('Tiró una silla', $writes[0]['data']['motivo']);
        $this->assertSame('2025-11-15', $writes[0]['data']['fecha']);
        $this->assertSame('1', $writes[0]['data']['ajmcm_notificado_familia_c']);
        // `ajmcm_notificado_el_c` (cuándo se avisó) NO se escribe: verificado
        // contra el CRM que ese campo de la especificación no se creó, solo el
        // booleano. Escribir una clave que no existe la ignora la API en
        // silencio, así que aquí se comprueba que el código ni lo intenta.
        $this->assertArrayNotHasKey('ajmcm_notificado_el_c', $writes[0]['data']);
        // Y queda relacionado con la persona, que es la única relación real.
        $rels = array_values(array_filter($this->scp->relationships, function ($r) {
            return $r['link'] === 'avi_avisos_contacts';
        }));
        $this->assertCount(1, $rels);
        $this->assertSame(array('c1'), $rels[0]['ids']);
        // Y el campo que fija la relación con el monitor es `contact_id_c`
        // (confirmado con get_module_fields), no una suposición.
        $this->assertSame('m1', $writes[0]['data']['contact_id_c']);
    }

    /** Sin motivo no hay aviso: un aviso vacío no le sirve a nadie. */
    public function test_ficha_no_registra_un_aviso_sin_motivo()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $_POST = array(
            'pl_nonce' => wp_create_nonce('pl_ficha_c1'),
            'pl_aviso_motivo' => '   ',
        );
        $this->render('single_stic_pasar_lista_ficha');

        $writes = array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'AVI_avisos';
        });
        $this->assertCount(0, $writes);
    }

    /** Sin nonce no se escribe: un enlace de fuera no pone avisos a nadie. */
    public function test_ficha_no_registra_un_aviso_sin_nonce()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $_POST = array('pl_aviso_motivo' => 'Sin nonce');
        $this->render('single_stic_pasar_lista_ficha');

        $writes = array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'AVI_avisos';
        });
        $this->assertCount(0, $writes);
    }

    /** Una fecha en el futuro se recorta a hoy: el dedo falla en un móvil. */
    public function test_ficha_un_aviso_no_se_pone_en_el_futuro()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $_POST = array(
            'pl_nonce' => wp_create_nonce('pl_ficha_c1'),
            'pl_aviso_motivo' => 'Algo',
            'pl_aviso_fecha' => '2099-01-01',
        );
        $this->render('single_stic_pasar_lista_ficha');

        $writes = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'AVI_avisos';
        }));
        $this->assertCount(1, $writes);
        $this->assertSame('2025-11-15', $writes[0]['data']['fecha']);
    }

    /** Apagado el módulo, la sección no existe y no se consulta. */
    public function test_ficha_sin_modulo_de_avisos_no_pinta_la_seccion()
    {
        $GLOBALS['__stic_filters']['sticpa_pl_avisos_enabled'] = false;
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_ficha');

        $this->assertStringNotContainsString('Avisos de comportamiento', $html);
        $this->assertNotContains('Contacts:avi_avisos_contacts', $this->scp->calls);
    }

    public function test_ficha_respeta_el_permiso_de_whatsapp_del_menor()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_ficha');
        $this->assertStringNotContainsString('wa.me/34600111222', $html);
        $this->assertStringContainsString('tel:600111222', $html);
    }

    /** El porcentaje se dice con denominador, y sobre sesiones celebradas. */
    public function test_ficha_asistencia_con_denominador()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_ficha');

        // yes + partial cuentan: 2 de las 3 sesiones celebradas (s4 no ha llegado).
        $this->assertStringContainsString('2 de 3 sesiones', $html);
        $this->assertStringContainsString('67', $html);
        $this->assertStringContainsString('hasta hoy', $html);
    }

    /** Solo los campos de salud con contenido: nada de etiquetas vacías. */
    public function test_ficha_salud_solo_lo_que_tiene_contenido()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_ficha');
        $this->assertStringContainsString('Alergias', $html);
        $this->assertStringContainsString('Frutos secos', $html);
        $this->assertStringNotContainsString('Intolerancias', $html);
    }

    /** El pañuelo va DESPUÉS de permisos, y cambiarlo pide confirmación. */
    public function test_ficha_panuelo_va_abajo_y_pide_confirmacion()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_ficha');

        $this->assertLessThan(strpos($html, 'Pañuelo'), strpos($html, 'Permisos'));
        $this->assertStringContainsString('Verde', $html);
        $this->assertStringContainsString('data-pl-confirm', $html);
        // Las opciones salen ocultas: cambiarlo cuesta dos toques a propósito.
        $this->assertStringContainsString('data-pl-panuelo-opts hidden', $html);
    }

    public function test_ficha_cambia_el_panuelo()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $_POST = array('pl_panuelo' => 'azul', 'pl_nonce' => wp_create_nonce('pl_ficha_c1'));
        $html = $this->render('single_stic_pasar_lista_ficha');

        $this->assertStringContainsString('Pañuelo actualizado', $html);
        $writes = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'Contacts';
        }));
        $this->assertCount(1, $writes);
        $this->assertSame('azul', $writes[0]['data']['ajmcm_panuelo_c']);
    }

    /** Un valor de pañuelo que no está en CAMPOS.md no se escribe. */
    public function test_ficha_no_escribe_panuelos_inventados()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $_POST = array('pl_panuelo' => 'fucsia', 'pl_nonce' => wp_create_nonce('pl_ficha_c1'));
        $this->render('single_stic_pasar_lista_ficha');
        $this->assertSame(array(), $this->scp->writes);
    }

    /** Un participante de otro grupo no se abre cambiando la URL. */
    public function test_ficha_de_alguien_que_no_es_del_grupo()
    {
        $_REQUEST = array('participante' => 'c-de-otro-sitio', 'grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_ficha');
        $this->assertStringContainsString('no está en el grupo', $html);
        $this->assertStringNotContainsString('Teléfonos', $html);
    }

    // ---- Resumen de coordinación ----------------------------------------

    public function test_resumen_pinta_la_tira_de_listas()
    {
        $html = $this->render('single_stic_pasar_lista_resumen');

        $this->assertStringContainsString('Resumen de grupos', $html);
        // La tira: s3 pasada (verde), s1 y s2 sin pasar (huecos).
        $this->assertStringContainsString('pl-cell--ok', $html);
        $this->assertStringContainsString('pl-cell--gap', $html);
        // Y el número de huecos, dicho con palabras.
        $this->assertStringContainsString('sin pasar', $html);
        // Cuántas sesiones entran en la tira, dicho en vez de recortado en silencio.
        $this->assertStringContainsString('últimas', $html);
    }

    /**
     * La primera pregunta de coordinación: "¿pasaron lista el sábado?". Va
     * arriba y con numerador Y denominador: "1" a secas no dice si van bien.
     */
    public function test_resumen_pinta_la_ultima_sesion_arriba()
    {
        $html = $this->render('single_stic_pasar_lista_resumen');

        $this->assertStringContainsString('pl-lasthero', $html);
        $this->assertStringContainsString('Última sesión', $html);
        // Cuatro grupos en el doble y solo g1 pasó la última: 1 de 4, y el
        // resto se dice en claro en vez de dejarlo a la resta.
        $this->assertStringContainsString('1 de 4 listas', $html);
        $this->assertStringContainsString('3 grupos sin pasarla todavía', $html);
        $this->assertStringContainsString('25%', $html);
        // Y va ANTES de las tiras por etapa, que es el orden en que se lee.
        $this->assertLessThan(strpos($html, 'pl-strip'), strpos($html, 'pl-lasthero'));
    }

    public function test_resumen_lista_los_participantes_sin_grupo()
    {
        $html = $this->render('single_stic_pasar_lista_resumen');

        $this->assertStringContainsString('2 participantes sin grupo asignado', $html);
        $this->assertStringContainsString('Sol Messeguer', $html);
        $this->assertStringContainsString('Lucia Ripolles', $html);
        // Un monitor sin grupo no es un participante sin grupo.
        $this->assertStringNotContainsString('Un Monitor', $html);
        // Una relación de un curso pasado no falta.
        $this->assertStringNotContainsString('Del Curso Pasado', $html);
    }

    /**
     * Un monitor VE los datos por revisar y no los edita. Es la regla que pidió
     * el proyecto, y el defecto correcto: sin sitio donde guardar quién es
     * coordinador, nadie edita.
     */
    public function test_un_monitor_ve_pero_no_edita()
    {
        $html = $this->render('single_stic_pasar_lista_resumen');
        $this->assertStringContainsString('Sol Messeguer', $html);
        $this->assertStringNotContainsString('pl-review-select', $html);
        $this->assertStringContainsString('no editarlo', $html);
    }

    /** Coordinación sí tiene el desplegable y el botón de asignar. */
    public function test_coordinacion_puede_asignar_grupo()
    {
        $GLOBALS['__stic_filters']['sticpa_pl_is_coordinator'] = true;

        $html = $this->render('single_stic_pasar_lista_resumen');
        $this->assertStringContainsString('pl-review-select', $html);
        $this->assertStringContainsString('Asignar', $html);

        unset($GLOBALS['__stic_filters']['sticpa_pl_is_coordinator']);
    }

    /** Un POST de asignación sin ser coordinación no escribe nada. */
    public function test_un_monitor_no_puede_asignar_por_post()
    {
        $_POST = array(
            'pl_assign_rel' => 'r7',
            'pl_assign_group' => 'g1',
            'pl_nonce' => wp_create_nonce('pl_resumen'),
        );
        $html = $this->render('single_stic_pasar_lista_resumen');

        $this->assertStringContainsString('Solo coordinación', $html);
        $this->assertSame(array(), $this->scp->relationships);
    }

    // ---- Fase 4: sin conexión, y el pulido del gesto -------------------

    /**
     * La pantalla de marcado lleva todo lo que el JS necesita para guardar sin
     * cobertura: sesión, grupo y los textos. Si esto falta, el borrador no sabe
     * de qué lista es y la cola no sabe qué reenviar.
     */
    public function test_marcar_lleva_los_datos_del_modo_sin_conexion()
    {
        $_REQUEST = array('grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringContainsString('data-session="s3"', $html);
        $this->assertStringContainsString('data-group="g1"', $html);
        // Los textos van en el HTML, no dentro del JS: así se traducen con el
        // resto de la interfaz en vez de quedarse en castellano.
        foreach (array('data-msg-draft', 'data-msg-offline', 'data-msg-queued', 'data-msg-sync', 'data-msg-sent') as $attr) {
            $this->assertStringContainsString($attr, $html);
        }
        // Y el hueco donde se pinta el aviso.
        $this->assertStringContainsString('data-pl-status', $html);
    }

    /** El overlay de carga del área privada, al enviar la lista. */
    public function test_marcar_pinta_carga_al_enviar()
    {
        $_REQUEST = array('grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_marcar');
        $this->assertStringContainsString('stic-loading-form', $html);
        $this->assertStringContainsString('data-label-saving', $html);
    }

    /**
     * El anillo del gesto largo se pinta SIEMPRE, no se inserta al empezar a
     * pulsar: meter un nodo en medio de un gesto es lo que produce el tirón del
     * primer fotograma.
     */
    public function test_el_anillo_del_gesto_largo_ya_esta_en_el_html()
    {
        $_REQUEST = array('grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_marcar');
        $this->assertStringContainsString('pl-hold-ring-svg', $html);
    }

    /**
     * El modo sin conexión completo viene ENCENDIDO, y se puede apagar con un
     * filtro: un service worker manda sobre todas las peticiones del sitio y
     * esto se instala en WordPress con cachés y plugins que no controlamos.
     */
    public function test_el_service_worker_se_puede_apagar()
    {
        $this->assertTrue(sticpa_pl_offline_enabled());

        $GLOBALS['__stic_filters']['sticpa_pl_offline_enabled'] = false;
        $html = $this->render('single_stic_pasar_lista');
        $this->assertStringNotContainsString('serviceWorker', $html);
        $this->assertFalse(sticpa_pl_offline_enabled());
    }

    /** Encendido, se registra con alcance de sitio y con la clave de usuario. */
    public function test_el_service_worker_se_registra_cuando_se_enciende()
    {
        $GLOBALS['__stic_filters']['sticpa_pl_offline_enabled'] = true;
        $html = $this->render('single_stic_pasar_lista');

        $this->assertStringContainsString('serviceWorker.register', $html);
        $this->assertStringContainsString('sticpa_sw=1', $html);
        // Alcance de sitio: si no, el service worker no puede controlar el área
        // privada, que está fuera de la carpeta del plugin.
        $this->assertStringContainsString("scope: '/'", $html);
        // La caché va nombrada por usuario, y con un hash, no con el id del CRM.
        $this->assertStringContainsString("type: 'sticpa:user'", $html);
        $this->assertStringNotContainsString("key: 'm1'", $html);
        // Y al cerrar sesión se borra todo.
        $this->assertStringContainsString("type: 'sticpa:logout'", $html);
    }

    // ---- Coordinación: monitores, ficha y reuniones ---------------------

    /** Sin relación de coordinación, la sección no existe en la home. */
    public function test_la_home_no_enseña_coordinacion_a_un_monitor()
    {
        $html = $this->render('single_stic_pasar_lista');
        $this->assertStringNotContainsString('Coordinación', $html);
        $this->assertStringNotContainsString('single_stic_pasar_lista_monitores', $html);
    }

    /** Con ella, aparece DEBAJO de los grupos y dice el alcance. */
    public function test_la_home_enseña_coordinacion_debajo_de_los_grupos()
    {
        $this->scp->coordEtapa = 'COM';
        $html = $this->render('single_stic_pasar_lista');

        $this->assertStringContainsString('Coordinación', $html);
        $this->assertStringContainsString('single_stic_pasar_lista_monitores', $html);
        $this->assertStringContainsString('single_stic_pasar_lista_reuniones', $html);
        // El alcance, visible sin abrir nada.
        $this->assertStringContainsString('pl-scope', $html);
        // Y debajo: los grupos van antes.
        $this->assertLessThan(strpos($html, 'Coordinación'), strpos($html, 'Ver todos los grupos'));
    }

    /** Un monitor que entra a la pantalla de monitores no ve media pantalla. */
    public function test_monitores_no_se_abre_sin_coordinar()
    {
        $html = $this->render('single_stic_pasar_lista_monitores');
        $this->assertStringContainsString('es de coordinación', $html);
        $this->assertStringNotContainsString('data-pl-form', $html);
    }

    /**
     * La lista de monitores arranca TODO EN VERDE. Es lo contrario que en los
     * chavales y es la propiedad que define esta pantalla.
     */
    public function test_monitores_arranca_todo_en_verde()
    {
        $this->scp->coordEtapa = 'COM';
        $html = $this->render('single_stic_pasar_lista_monitores');

        $this->assertStringContainsString('data-pl-monitores', $html);
        $this->assertStringContainsString('data-state="yes" data-contact="m1"', $html);
        // Y la regla se dice en la pantalla, antes de guardar.
        $this->assertStringContainsString('Marca solo a quien no vino', $html);
        // Sin contador de "sin marcar": aquí no existe ese estado.
        $this->assertStringNotContainsString('data-pl-count-none-wrap', $html);
    }

    /**
     * Al guardar se escribe `yes` EXPLÍCITO para los no marcados. Si se dejaran
     * vacíos, el porcentaje de un monitor que ha venido a todo saldría a cero.
     */
    public function test_monitores_escribe_el_verde_explicito()
    {
        $this->scp->coordEtapa = 'COM';
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_monitores'),
            'pl_marks' => json_encode(array()),      // nadie marcado
        );
        $this->render('single_stic_pasar_lista_monitores');

        $writes = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_Attendances';
        }));
        $this->assertCount(1, $writes, 'un monitor en el grupo del doble');
        $this->assertSame('yes', $writes[0]['data']['status']);
    }

    /** Y la falta marcada sí se escribe como falta. */
    public function test_monitores_escribe_la_falta_marcada()
    {
        $this->scp->coordEtapa = 'COM';
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_monitores'),
            'pl_marks' => json_encode(array('m1' => 'no_unjustified')),
        );
        $this->render('single_stic_pasar_lista_monitores');

        $writes = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_Attendances';
        }));
        $this->assertSame('no_unjustified', $writes[0]['data']['status']);
    }

    /** La ficha del monitor: certificado primero, y sin familia ni salud. */
    public function test_ficha_del_monitor()
    {
        $this->scp->coordEtapa = 'COM';
        $_REQUEST = array('monitor' => 'm1');
        $html = $this->render('single_stic_pasar_lista_monitor');

        // Lo primero es el certificado, porque es lo que se reclama.
        $this->assertStringContainsString('Certificado de delitos sexuales', $html);
        $this->assertLessThan(strpos($html, 'Titulaciones'), strpos($html, 'Certificado de delitos'));
        $this->assertStringContainsString('Automático', $html);
        // Titulaciones, con el descuadre del DAT sin archivo.
        $this->assertStringContainsString('2021 - EADB', $html);
        $this->assertStringContainsString('sin archivo', $html);
        // FA dice 'no', así que no sale.
        $this->assertStringNotContainsString('>FA', $html);
        // Un monitor es un adulto: ni familia ni salud.
        $this->assertStringNotContainsString('Salud', $html);
        $this->assertStringNotContainsString('Alergias', $html);
        // Y el año de "monitor desde", sin el 1 de enero.
        $this->assertStringContainsString('2012', $html);
        $this->assertStringNotContainsString('2012-01-01', $html);
    }

    /** No se abre la ficha de un monitor fuera del alcance cambiando la URL. */
    public function test_ficha_de_monitor_fuera_del_alcance()
    {
        $this->scp->coordEtapa = 'MIC';   // el monitor del doble está en un grupo COM
        $_REQUEST = array('monitor' => 'm1');
        $html = $this->render('single_stic_pasar_lista_monitor');
        $this->assertStringContainsString('no está en los grupos de tu alcance', $html);
    }

    /** Reuniones: se crean con nombre, día, hora y duración. */
    public function test_crear_una_reunion()
    {
        $this->scp->coordEtapa = 'COM';
        $_POST = array(
            'pl_nonce' => wp_create_nonce('pl_reuniones'),
            'pl_reunion_name' => 'Programación 2.º trimestre',
            'pl_reunion_date' => '2026-01-17',
            'pl_reunion_time' => '19:00',
            'pl_reunion_hours' => '2',
        );
        $html = $this->render('single_stic_pasar_lista_reuniones');

        $this->assertStringContainsString('Reunión creada', $html);

        // Se crea el evento de reuniones (no existía en el doble) y la sesión.
        $events = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_Events';
        }));
        $this->assertCount(1, $events);
        $this->assertStringContainsString('Reuniones de programación', $events[0]['data']['name']);

        $sessions = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_Sessions';
        }));
        $this->assertCount(1, $sessions);
        $this->assertSame('Programación 2.º trimestre', $sessions[0]['data']['name']);
        // Hora LOCAL con formato Y-m-d H:i:s: en ISO con desplazamiento la API
        // la ignora y pone la hora actual.
        $this->assertSame('2026-01-17 19:00:00', $sessions[0]['data']['start_date']);
        $this->assertSame('2026-01-17 21:00:00', $sessions[0]['data']['end_date']);
    }

    /** Un monitor no puede crear reuniones por POST. */
    public function test_un_monitor_no_crea_reuniones()
    {
        $_POST = array(
            'pl_nonce' => wp_create_nonce('pl_reuniones'),
            'pl_reunion_name' => 'La mía',
            'pl_reunion_date' => '2026-01-17',
        );
        $html = $this->render('single_stic_pasar_lista_reuniones');
        $this->assertStringContainsString('es de coordinación', $html);
        $this->assertSame(array(), $this->scp->writes);
    }

    /** Una fecha inválida no crea nada. */
    public function test_reunion_con_fecha_invalida()
    {
        $this->scp->coordEtapa = 'COM';
        $_POST = array(
            'pl_nonce' => wp_create_nonce('pl_reuniones'),
            'pl_reunion_name' => 'Sin fecha',
            'pl_reunion_date' => 'mañana',
        );
        $html = $this->render('single_stic_pasar_lista_reuniones');
        $this->assertStringContainsString('Revisa la fecha', $html);
        $this->assertSame(array(), $this->scp->writes);
    }

    // ---- Seguimientos en la ficha del monitor ---------------------------

    /**
     * Quien mira es coordinación, y NO es el monitor de la ficha. Importa: si el
     * usuario conectado fuera m1, saltaría la regla de "sobre uno mismo, nada" y
     * el test pasaría por el motivo equivocado.
     */
    private function asOtherPerson()
    {
        $_SESSION['scp_user_id'] = 'coord1';
    }

    private function seedSeguimientos()
    {
        $this->scp->seguimientos = array(
            array('type' => 'mcm_incidencia', 'texto' => 'Se fue antes sin avisar'),
            array('type' => 'mcm_valoracion', 'texto' => 'Buen trimestre'),
            array('type' => 'mcm_acompanamiento', 'texto' => 'ESTO ES PRIVADO'),
        );
    }

    /**
     * Coordinación NO ve las notas de acompañamiento. Es la propiedad que hay
     * que romper para que esto sea un problema de verdad.
     */
    public function test_coordinacion_no_ve_acompanamiento_en_la_ficha()
    {
        $this->asOtherPerson();
        $this->scp->coordEtapa = 'COM';
        $this->seedSeguimientos();
        $_REQUEST = array('monitor' => 'm1');
        $html = $this->render('single_stic_pasar_lista_monitor');

        $this->assertStringContainsString('Se fue antes sin avisar', $html);
        $this->assertStringContainsString('Buen trimestre', $html);
        $this->assertStringNotContainsString('ESTO ES PRIVADO', $html);
        // Y tampoco tiene la opción de escribirlo.
        $this->assertStringNotContainsString('value="acompanamiento"', $html);
    }

    /** Quien acompaña sí las ve, y puede escribirlas. */
    public function test_acompanamiento_ve_sus_notas()
    {
        $this->asOtherPerson();
        $this->scp->isAcomp = true;
        $this->seedSeguimientos();
        $_REQUEST = array('monitor' => 'm1');
        $html = $this->render('single_stic_pasar_lista_monitor');

        $this->assertStringContainsString('ESTO ES PRIVADO', $html);
        $this->assertStringContainsString('value="acompanamiento"', $html);
        // Y el aviso de privacidad se lee al escribir, no en un pie de página.
        $this->assertStringContainsString('solo las ve quien acompaña', $html);
    }

    /** Un monitor sin papeles no ve la sección siquiera. */
    public function test_un_monitor_no_ve_la_seccion_de_seguimientos()
    {
        $this->seedSeguimientos();
        $_REQUEST = array('monitor' => 'm1');
        $html = $this->render('single_stic_pasar_lista_monitor');
        // Sin coordinar ni acompañar, la ficha entera no se abre.
        $this->assertStringContainsString('es de coordinación', $html);
        $this->assertStringNotContainsString('Seguimientos', $html);
    }

    /** Coordinación no puede escribir acompañamiento aunque lo fuerce por POST. */
    public function test_coordinacion_no_puede_escribir_acompanamiento_por_post()
    {
        $this->asOtherPerson();
        $this->scp->coordEtapa = 'COM';
        $_REQUEST = array('monitor' => 'm1');
        $_POST = array(
            'pl_nonce' => wp_create_nonce('pl_seg_m1'),
            'pl_seg_tipo' => 'acompanamiento',
            'pl_seg_texto' => 'Intento colarlo',
            'pl_seg_fecha' => '2026-01-10',
        );
        $html = $this->render('single_stic_pasar_lista_monitor');

        $this->assertStringContainsString('No se ha podido guardar', $html);
        $this->assertSame(array(), $this->scp->writes);
    }

    /** Y sí puede escribir una incidencia. */
    public function test_coordinacion_escribe_una_incidencia()
    {
        $this->asOtherPerson();
        $this->scp->coordEtapa = 'COM';
        $_REQUEST = array('monitor' => 'm1');
        $_POST = array(
            'pl_nonce' => wp_create_nonce('pl_seg_m1'),
            'pl_seg_tipo' => 'incidencia',
            'pl_seg_texto' => 'Llegó tarde tres sábados',
            'pl_seg_fecha' => '2026-01-10',
        );
        $html = $this->render('single_stic_pasar_lista_monitor');

        $this->assertStringContainsString('Guardado', $html);
        $writes = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_FollowUps';
        }));
        $this->assertCount(1, $writes);
        // La clave del CRM, no la nuestra.
        $this->assertSame('mcm_incidencia', $writes[0]['data']['type']);
        $this->assertSame('Llegó tarde tres sábados', $writes[0]['data']['description']);
        // La fecha del HECHO, no la de hoy.
        $this->assertStringContainsString('2026-01-10', $writes[0]['data']['date_start']);
    }

    /**
     * NADIE escribe un seguimiento sobre sí mismo, ni coordinando. Si no se
     * puede leer, escribirlo solo serviría para dejarlo invisible.
     */
    public function test_nadie_escribe_un_seguimiento_sobre_si_mismo()
    {
        $this->scp->coordEtapa = 'COM';
        // El usuario conectado del doble es m1, y m1 es el monitor de la ficha.
        $_REQUEST = array('monitor' => 'm1');
        $_POST = array(
            'pl_nonce' => wp_create_nonce('pl_seg_m1'),
            'pl_seg_tipo' => 'incidencia',
            'pl_seg_texto' => 'Me pongo una nota a mí mismo',
            'pl_seg_fecha' => '2026-01-10',
        );
        $html = $this->render('single_stic_pasar_lista_monitor');

        $this->assertStringContainsString('No se ha podido guardar', $html);
        $this->assertSame(array(), $this->scp->writes);
    }

    /** Y tampoco los lee: la lista sale vacía aunque el CRM los devuelva. */
    public function test_nadie_lee_seguimientos_sobre_si_mismo()
    {
        $this->scp->coordEtapa = 'COM';
        $this->seedSeguimientos();
        $_REQUEST = array('monitor' => 'm1');    // m1 es el usuario conectado
        $html = $this->render('single_stic_pasar_lista_monitor');

        $this->assertStringNotContainsString('Se fue antes sin avisar', $html);
        $this->assertStringNotContainsString('Buen trimestre', $html);
    }

    /**
     * Encendidos, pero con salida de emergencia: los nombres de campo del módulo
     * no están verificados contra la instancia, así que si fallan hay que poder
     * apagar la sección sin tocar código ni dejar media pantalla rota.
     */
    public function test_los_seguimientos_se_pueden_apagar()
    {
        $GLOBALS['__stic_filters']['sticpa_pl_seguimientos_enabled'] = false;
        $this->scp->coordEtapa = 'COM';
        $this->seedSeguimientos();
        $_REQUEST = array('monitor' => 'm1');
        $html = $this->render('single_stic_pasar_lista_monitor');

        $this->assertFalse(sticpa_pl_seguimientos_enabled());
        $this->assertStringNotContainsString('Seguimientos', $html);
        $this->assertStringNotContainsString('Se fue antes sin avisar', $html);
    }

    /**
     * "Convivencia de familias del COM" NO es el evento de sesiones del COM.
     * Solo cuenta lo que hay antes del "|", que es la convención del proyecto.
     */
    public function test_solo_el_prefijo_antes_de_la_barra_marca_etapa()
    {
        $this->assertSame('COM', sticpa_pl_etapa_from_name('COM | Sesiones semanales 2025-2026'));
        $this->assertSame('MIC', sticpa_pl_etapa_from_name('MIC | Sesiones semanales 2025-2026'));
        $this->assertSame('', sticpa_pl_etapa_from_name('Convivencia de familias del COM 2025-2026'));
    }

    public function testEtapasDeSeleccionMultiple()
    {
        // El formato de SuiteCRM.
        $this->assertSame(array('MIC', 'COM'), sticpa_pl_etapas_from_multi('^MIC^,^COM^'));
        // Una sola, y en minusculas: el desplegable puede tener cualquier caja.
        $this->assertSame(array('COM'), sticpa_pl_etapas_from_multi('^com^'));
        // Lista pelada, array, y con espacios de mas.
        $this->assertSame(array('MIC', 'COM'), sticpa_pl_etapas_from_multi('MIC, COM'));
        $this->assertSame(array('MIC', 'LC'), sticpa_pl_etapas_from_multi(array('MIC', '^LC^')));
        // Repetida: la pantalla no tiene por que enterarse.
        $this->assertSame(array('COM'), sticpa_pl_etapas_from_multi('^COM^,^COM^'));
        // Vacio y basura: nada, y sin avisos de PHP.
        $this->assertSame(array(), sticpa_pl_etapas_from_multi(''));
        $this->assertSame(array(), sticpa_pl_etapas_from_multi('^^'));
        $this->assertSame(array(), sticpa_pl_etapas_from_multi('familias'));
        $this->assertSame(array(), sticpa_pl_etapas_from_multi(null));
    }

    public function testEventoMultietapaSirveALasDosEtapas()
    {
        $events = sticpa_pl_etapa_events(new FakeSCP());
        // El mismo evento bajo las dos claves, resuelto solo por el campo.
        $this->assertSame('ev-todo', $events['MIC']['id']);
        $this->assertSame('ev-todo', $events['COM']['id']);
        // Y el que no tiene campo sigue saliendo por el nombre.
        $this->assertSame('ev-lc', $events['LC']['id']);
        // La trampa no entra por ninguna via.
        $this->assertCount(3, $events);
    }
}
