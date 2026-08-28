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

    /**
     * ESTE DOBLE GUARDA. Lo que se escribe con set_entry se ve al volver a
     * leer, como en el CRM de verdad.
     *
     * Antes no: una escritura se apuntaba en `$writes` y las lecturas seguían
     * devolviendo lo de siempre. Con eso no se puede probar lo único que
     * importaba —«¿está de verdad guardado?»— y es la misma trampa que dejó 175
     * tests en verde con la producción rota (parte de estado §3.2).
     */
    public $attStatus = array();       // id de asistencia => status escrito
    public $attSession = array();      // id de asistencia => sesión enlazada
    public $attReg = array();          // id de asistencia => inscripción enlazada
    public $listas = array();          // id de lista => campos escritos
    public $avisos = array();          // id de aviso => campos escritos
    public $avisosCiegos = false;      // el CRM acepta el aviso y no lo devuelve
    /** m1 lleva ADEMÁS un grupo de MIC: el caso del monitor de dos etapas. */
    public $monitorDeDosEtapas = false;

    /** Módulos en los que set_entry falla, como cuando el CRM lo rechaza. */
    public $failWrites = array();
    /** Cuando es true, set_relationship falla. */
    public $failRelationships = false;
    /** Lo que el transporte deja ahí para que arriba se pueda decir por qué. */
    public $lastError = '';

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
            // OJO: los enlaces NO se cuelgan del registro. La API v4.1 los
            // devuelve en `relationship_list`, HERMANO de `entry_list`, y este
            // doble antes mentia colgandolos aqui: por eso los tests pasaban
            // en verde mientras la pantalla salia vacia en produccion. Se
            // guardan aparte y apiShape() los coloca donde el CRM los pone.
            $row->__links = array($link);
        }
        return $row;
    }

    /**
     * La forma REAL de `get_entry_list` con `link_name_to_fields_array`, pasada
     * por el aplanado del plugin. Cada fila del array de entrada lleva
     * 'fields' y, opcionalmente, un array por cada enlace ('grupo', 'persona').
     */
    private function entryListShape($rows, $relationshipFields)
    {
        // Las filas condicionales llegan como null: se caen aqui y no en cada
        // sitio que arma la lista.
        $rows = array_values(array_filter($rows));
        $entries = array();
        $relationshipList = array();

        foreach ($rows as $row) {
            $entries[] = $this->nvl($row['fields']);

            $linkList = array();
            // Cualquier clave de enlace, no solo grupo/persona: el cargador de
            // listas pide 'sesion' y 'grupo', y el de inscripciones 'evento'.
            $claves = is_array($relationshipFields) ? array_keys($relationshipFields) : array();
            foreach ($claves as $which) {
                if (empty($row[$which]) || !is_array($relationshipFields) || !isset($relationshipFields[$which])) {
                    continue;
                }
                $spec = $relationshipFields[$which];
                $lv = new stdClass();
                foreach ($row[$which] as $k => $v) {
                    $lv->$k = (object) array('name' => $k, 'value' => $v);
                }
                $link = new stdClass();
                $link->name = is_array($spec) ? $spec['relationshipName'] : (string) $spec;
                $link->records = array((object) array('link_value' => $lv));
                $linkList[] = $link;
            }
            $relationshipList[] = (object) array('link_list' => $linkList);
        }

        return SugarRestApiCall::flattenRelationshipFields($entries, $relationshipList, $relationshipFields);
    }

    /**
     * Devuelve las filas con la forma REAL de `get_relationships` de la v4.1:
     * `entry_list` pelado y los enlaces aparte en `relationship_list`, y las
     * junta con el MISMO ensamblado que usa el plugin de verdad. Asi, si
     * alguien vuelve a tirar `relationship_list` a la basura, estos tests
     * se ponen rojos en vez de seguir mintiendo.
     */
    private function apiShape($rows)
    {
        $entries = array();
        $relationshipList = array();
        foreach ($rows as $row) {
            $links = isset($row->__links) ? $row->__links : null;
            unset($row->__links);
            $entries[] = $row;
            $relationshipList[] = ($links === null)
                ? new stdClass()
                : (object) array('link_list' => $links);
        }
        return SugarRestApiCall::attachLinkList($entries, $relationshipList);
    }

    public function getRecordDetail($id, $module, $fields = null)
    {
        // POR `servir()`, como todo lo demás. Contando la llamada a mano se
        // saltaba la recolecta: la ficha se pedía una vez al recolectar y otra
        // de verdad, o sea que el doble contaba DOS llamadas donde producción
        // hace una... y, peor, un `prime()` que no metiera la ficha en la tanda
        // habría pasado por bueno.
        $self = $this;
        return $this->servir(
            'gd|' . md5(serialize(array($id, $module, $fields))),
            'getRecordDetail:' . $module,
            function () use ($self, $id, $module, $fields) {
                return $self->detalleDeRegistro($id, $module, $fields);
            }
        );
    }

    public function detalleDeRegistro($id, $module, $fields = null)
    {
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
                'ajmcm_congreso_monis_c' => '^2019_godelleta^,^2022_burriana^',
                'phone_other' => '964 200 300',
                // «En regla»: casi todo bien y UNA cosa que falta, que es el
                // caso que la pantalla tiene que saber contar. Y un permiso
                // sin dar, que NO es lo mismo que una obligación incumplida.
                'ajmcm_form_intera_proteccion_c' => '1',
                'stic_conduct_code_c' => '0',
                'stic_confidentiality_agreement_c' => '1',
                'ajmcm_vol_acuerdo_c' => '1', 'ajmcm_compromiso_c' => '1',
                'ajmcm_acepta_lopd_c' => '1', 'ajmcm_cesionimagenes_interne_c' => '0',
                // Trayectoria y datos personales.
                'ajmcm_nivel_com_c' => 'opcion_responsable', 'ajmcm_etapa_c' => 'LC',
                'ajmcm_mcm_desde_c' => '2005-09-01',
                'stic_gender_c' => 'male',
                'stic_identification_type_c' => 'nif',
                'stic_identification_number_c' => '12345678Z',
                'primary_address_city' => 'Castelló de la Plana',
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

    /** Simula una instancia que NO devuelve los enlaces anidados. */
    public $sinEnlaces = false;

    /* ---- Recolecta y tanda paralela: el MISMO contrato que el transporte ----
     *
     * El doble tiene que modelar esto o los tests mienten: sin ello, la pasada
     * de recolecta ejecutaría las consultas de verdad y cada pantalla contaría
     * el doble de llamadas. Y al contrario: si el doble se limitara a devolver
     * los datos ignorando la recolecta, un `prime()` roto pasaría por bueno.
     *
     * Modelo, igual que en producción: recolectar NO llama a nadie; la tanda
     * ES la llamada (cuenta N); y después cada cargador encuentra su respuesta
     * ya traída y no cuenta ninguna.
     */
    /**
     * Qué grupos llevan marcada la casilla de «entra en Pasar Lista».
     * null = el campo no está relleno en ninguno, que es como está el CRM el
     * día que se crea: ahí el filtro NO debe esconder nada.
     */
    public $gruposActivos = null;

    /** Cuántas peticiones ha llevado cada tanda paralela. */
    public $batches = array();

    private $recolectando = false;
    private $recolectado = array();
    private $traido = array();

    public function collectRequests(callable $fn)
    {
        $this->recolectando = true;
        $this->recolectado = array();
        try {
            // Se enciende TAMBIÉN el interruptor del transporte real, que es el
            // que leen `sticpa_pl_collecting()`, la caché y los respaldos. Sin
            // esto, la pasada de recolecta cachearía los vacíos que devuelve a
            // propósito y la pantalla se quedaría sin datos — que es
            // exactamente el fallo que este doble tiene que poder detectar.
            SugarRestApiCall::collect(function () use ($fn) { $fn(); });
        } finally {
            $this->recolectando = false;
        }
        $out = $this->recolectado;
        $this->recolectado = array();
        return array_values($out);
    }

    public function callMany($requests)
    {
        $this->batches[] = count((array) $requests);
        $listas = 0;
        foreach ((array) $requests as $req) {
            // La tanda SÍ llama: se apunta la llamada, como en producción.
            $this->calls[] = $req['label'];
            $this->traido[$req['sig']] = call_user_func($req['producer']);
            $listas++;
        }
        return $listas;
    }

    /**
     * El paso por el que entran las dos lecturas: recolecta, memo o llamada.
     */
    private function servir($sig, $label, callable $producer)
    {
        if ($this->recolectando) {
            if (!isset($this->recolectado[$sig])) {
                $this->recolectado[$sig] = array(
                    'sig' => $sig, 'label' => $label, 'producer' => $producer,
                );
            }
            return null;
        }
        if (array_key_exists($sig, $this->traido)) {
            $datos = $this->traido[$sig];
            unset($this->traido[$sig]);
            return $datos;   // ya la trajo la tanda: no cuenta como llamada
        }
        $this->calls[] = $label;
        return call_user_func($producer);
    }

    public function getRecordsModule($module, $query = '', $fields = array(), $rel = null)
    {
        $self = $this;
        return $this->servir(
            'gr|' . md5(serialize(array($module, $query, $fields, $rel))),
            'getRecordsModule:' . $module,
            function () use ($self, $module, $query, $fields, $rel) {
                return $self->datosDeModulo($module, $query, $fields, $rel);
            }
        );
    }

    public function datosDeModulo($module, $query = '', $fields = array(), $rel = null)
    {
        if ($module === 'ajmcm_GRUPOS') {
            $marcar = function ($fila) {
                if (!is_array($this->gruposActivos)) {
                    return $fila;   // campo vacío en todos, como recién creado
                }
                $fila['ajmcm_pasar_lista_c'] = in_array($fila['id'], $this->gruposActivos, true) ? '1' : '0';
                return $fila;
            };
            // `cursos_c` lleva el CURSO ESCOLAR, que es lo que hay en el CRM de
            // verdad: "1º ESO", "Adultos", "6º Primària"… NO el año académico.
            // Este doble decía "2025-2026" y por eso los tests daban por bueno
            // un filtro que en producción escondía 19 de los 27 grupos.
            // El recuento nocturno del Guardián: `ajmcm_recuento_al_c` es de
            // anoche respecto al «ahora» de los tests (15/11/2025), así que el
            // número se pinta. g2 lo tiene VIEJO a propósito: ahí la pantalla
            // tiene que callarse el número y enseñar el resto de la línea.
            return array(
                $this->nvl($marcar(array(
                    'id' => 'g1', 'name' => 'Los Peques', 'code' => 'C1', 'level' => 'COM',
                    'cursos_c' => '1º ESO',
                    'ajmcm_n_participantes_c' => '11', 'ajmcm_n_monitores_c' => '2',
                    'ajmcm_monitores_c' => 'David Soler', 'ajmcm_recuento_al_c' => '2025-11-15 01:30:00',
                ))),
                $this->nvl($marcar(array(
                    'id' => 'g2', 'name' => 'C2', 'code' => 'C2', 'level' => 'COM',
                    'cursos_c' => '2º ESO',
                    'ajmcm_n_participantes_c' => '10', 'ajmcm_n_monitores_c' => '1',
                    'ajmcm_monitores_c' => 'Mercedes', 'ajmcm_recuento_al_c' => '2025-09-01 01:30:00',
                ))),
                $this->nvl($marcar(array(
                    'id' => 'g3', 'name' => 'Los Micos', 'code' => 'M1', 'level' => 'MIC',
                    'cursos_c' => '5º Primaria',
                    'ajmcm_n_participantes_c' => '9', 'ajmcm_n_monitores_c' => '1',
                    'ajmcm_monitores_c' => 'Jaime', 'ajmcm_recuento_al_c' => '2025-11-14 23:40:00',
                ))),
                // Sin curso escolar y sin recuento: pasa igual, como en el CRM.
                $this->nvl($marcar(array('id' => 'g9', 'name' => 'Ruah', 'code' => '', 'level' => 'LC'))),
            );
        }
        if ($module === 'stic_Contacts_Relationships') {
            // La forma REAL de get_entry_list con `link_name_to_fields_array`:
            // los enlaces vienen en `relationship_list`, aparte, y el plugin los
            // aplana. Se pasa por el aplanado de verdad para que estos tests
            // comprueben el contrato y no una version inventada.
            //
            // La vigencia se filtra EN PHP, no en SQL (ver el comentario largo
            // de `sticpa_pl_all_relationships_raw()`), así que el CRM DEVUELVE
            // también las relaciones terminadas y el doble tiene que hacer lo
            // mismo. Si no las devolviera, el histórico «por dónde ha pasado»
            // saldría vacío en los tests y lleno en producción.
            if ($this->sinEnlaces) {
                // Ni enlaces anidados ni campos planos: solo el registro. Es lo
                // que hace la instancia real con `get_relationships`, y lo que
                // obliga a tener respaldo.
                return array(
                    $this->nvl(array('id' => 'r1', 'name' => 'Solete Vilarroya - Participante MIC-COM', 'relationship_type' => 'participante_mic_com', 'end_date' => '')),
                );
            }
            return $this->entryListShape(array(
                array(
                    'fields' => array('id' => 'r1', 'relationship_type' => 'participante_mic_com', 'start_date' => '2025-09-01', 'end_date' => ''),
                    'grupo' => array('id' => 'g1', 'name' => 'Los Peques'),
                    'persona' => array('id' => 'c1', 'name' => 'Solete Vilarroya', 'first_name' => 'Solete', 'last_name' => 'Vilarroya', 'stic_age_c' => '13', 'phone_mobile' => '600111222'),
                ),
                array(
                    'fields' => array('id' => 'r2', 'relationship_type' => 'participante_mic_com', 'start_date' => '2025-09-01', 'end_date' => ''),
                    'grupo' => array('id' => 'g1', 'name' => 'Los Peques'),
                    'persona' => array('id' => 'c2', 'name' => 'Jaume Pascual', 'first_name' => 'Jaume', 'last_name' => 'Pascual', 'stic_age_c' => '13'),
                ),
                // Un monitor del grupo g3, que es MIC: sin él la pantalla de
                // monitores tendría una sola etapa y no se podría comprobar que
                // se parte en secciones (ni que los del MIC van arriba).
                array(
                    'fields' => array('id' => 'r10', 'relationship_type' => 'monitor', 'end_date' => ''),
                    'grupo' => array('id' => 'g3', 'name' => 'Los Micos'),
                    'persona' => array('id' => 'm10', 'name' => 'Jaime Bort', 'first_name' => 'Jaime', 'last_name' => 'Bort'),
                ),
                // El monitor del grupo g1: David Soler, que es quien esta en sesion.
                array(
                    'fields' => array('id' => 'r4', 'relationship_type' => 'monitor', 'end_date' => ''),
                    'grupo' => array('id' => 'g1', 'name' => 'Los Peques'),
                    'persona' => array('id' => 'm1', 'name' => 'David Soler', 'first_name' => 'David', 'last_name' => 'Soler'),
                ),
                // Y, cuando se pide, ese MISMO monitor lleva ademas un grupo de
                // MIC: es el caso del monitor de dos etapas, que tiene que
                // salir UNA vez y ser nombrado por la seccion que no lo tiene.
                $this->monitorDeDosEtapas ? array(
                    'fields' => array('id' => 'r4b', 'relationship_type' => 'monitor', 'end_date' => ''),
                    'grupo' => array('id' => 'g3', 'name' => 'Los Micos'),
                    'persona' => array('id' => 'm1', 'name' => 'David Soler', 'first_name' => 'David', 'last_name' => 'Soler'),
                ) : null,
                // El rol `grupo` de los +18: cuenta como participante.
                array(
                    'fields' => array('id' => 'r5', 'relationship_type' => 'grupo', 'end_date' => ''),
                    'grupo' => array('id' => 'g1', 'name' => 'Los Peques'),
                    'persona' => array('id' => 'c3', 'name' => 'Marta Adulta', 'first_name' => 'Marta', 'last_name' => 'Adulta'),
                ),
                // Sin grupo: son los de "datos por revisar".
                array(
                    'fields' => array('id' => 'r7', 'relationship_type' => 'participante_mic_com', 'end_date' => ''),
                    'persona' => array('id' => 'c7', 'name' => 'Sol Messeguer', 'first_name' => 'Sol', 'last_name' => 'Messeguer'),
                ),
                array(
                    'fields' => array('id' => 'r8', 'relationship_type' => 'participante_mic_com', 'end_date' => ''),
                    'persona' => array('id' => 'c8', 'name' => 'Lucia Ripolles', 'first_name' => 'Lucia', 'last_name' => 'Ripolles'),
                ),
                // Monitor sin grupo: no sale en la lista de participantes.
                array(
                    'fields' => array('id' => 'r9', 'relationship_type' => 'monitor', 'end_date' => ''),
                    'persona' => array('id' => 'm9', 'name' => 'Un Monitor', 'first_name' => 'Un', 'last_name' => 'Monitor'),
                ),
                // EL CURSO PASADO, ya cerrado. David llevaba el grupo de los
                // MIC, y con él estaba Jaime. Es lo que pinta el histórico de la
                // ficha: «en 2024-2025 llevaba M1, con Jaime Bort».
                array(
                    'fields' => array('id' => 'r20', 'relationship_type' => 'monitor', 'start_date' => '2024-09-01', 'end_date' => '2025-07-31'),
                    'grupo' => array('id' => 'g3', 'name' => 'Los Micos'),
                    'persona' => array('id' => 'm1', 'name' => 'David Soler', 'first_name' => 'David', 'last_name' => 'Soler'),
                ),
                array(
                    'fields' => array('id' => 'r21', 'relationship_type' => 'monitor', 'start_date' => '2024-09-01', 'end_date' => '2025-07-31'),
                    'grupo' => array('id' => 'g3', 'name' => 'Los Micos'),
                    'persona' => array('id' => 'm10', 'name' => 'Jaime Bort', 'first_name' => 'Jaime', 'last_name' => 'Bort'),
                ),
                // Y su relación `grupo` COM-LC, abierta desde 2022: es el grupo
                // al que PERTENECE, distinto del que lleva.
                array(
                    'fields' => array('id' => 'r22', 'relationship_type' => 'grupo', 'start_date' => '2022-09-01', 'end_date' => ''),
                    'grupo' => array('id' => 'g9', 'name' => 'Ruah'),
                    'persona' => array('id' => 'm1', 'name' => 'David Soler', 'first_name' => 'David', 'last_name' => 'Soler'),
                ),
            ), $rel);
        }
        if ($module === 'stic_Attendances') {
            // El cargador por rango de fechas: UNA llamada para las tres
            // sesiones que mira la racha.
            return $this->entryListShape(array(
                array(
                    'fields' => array('id' => 'a1', 'status' => 'yes'),
                    'sesion' => array('id' => 's1', 'name' => 'S1'),
                    'inscripcion' => array('id' => 'reg1', 'name' => 'R1'),
                ),
                array(
                    'fields' => array('id' => 'a2', 'status' => 'no_unjustified'),
                    'sesion' => array('id' => 's2', 'name' => 'S2'),
                    'inscripcion' => array('id' => 'reg1', 'name' => 'R1'),
                ),
                array(
                    'fields' => array('id' => 'a3', 'status' => 'no_unjustified'),
                    'sesion' => array('id' => 's3', 'name' => 'S3'),
                    'inscripcion' => array('id' => 'reg1', 'name' => 'R1'),
                ),
                // Y las del MONITOR. Sin ellas, la pantalla de monitores no
                // tiene de donde sacar ni el aviso de seguimiento ni el
                // porcentaje, y la mitad de esa pantalla no se podia probar:
                // los tests pasaban porque no habia dato, no porque el codigo
                // estuviera bien.
                array(
                    'fields' => array('id' => 'am1', 'status' => 'yes'),
                    'sesion' => array('id' => 's1', 'name' => 'S1'),
                    'inscripcion' => array('id' => 'regm1', 'name' => 'RM1'),
                ),
                array(
                    'fields' => array('id' => 'am2', 'status' => 'no_unjustified'),
                    'sesion' => array('id' => 's2', 'name' => 'S2'),
                    'inscripcion' => array('id' => 'regm1', 'name' => 'RM1'),
                ),
                array(
                    'fields' => array('id' => 'am3', 'status' => 'yes'),
                    'sesion' => array('id' => 's3', 'name' => 'S3'),
                    'inscripcion' => array('id' => 'regm1', 'name' => 'RM1'),
                ),
            ), $rel);
        }
        if ($module === 'LIS_listas') {
            // El cargador comun de listas: UNA llamada para toda la delegacion.
            // Solo la sesion s3 tiene lista pasada, igual que antes.
            $rows = array(
                array(
                    'fields' => array(
                        'id' => 'l1', 'estado' => 'pasada', 'pasada_el' => '2025-11-15 18:05:00',
                        'n_asistieron' => 2, 'n_faltaron' => 0, 'ajmcm_tipo_c' => 'participantes',
                        // Quién la pasó, en el campo PLANO. Es la forma real:
                        // verificado contra el CRM que `lis_listas_contactscontacts_ida`
                        // viene poblado con el id del monitor. El enlace anidado
                        // esta instancia no lo devuelve (trampa §3.1).
                        'lis_listas_contactscontacts_ida' => 'm1',
                    ),
                    'sesion' => array('id' => 's3', 'name' => 'Sesion 3'),
                    'grupo' => array('id' => 'g1', 'name' => 'Los Peques'),
                ),
            );
            // Lo escrito manda sobre lo de fábrica, como en el CRM.
            foreach ($rows as $i => $row) {
                $id = $row['fields']['id'];
                if (!isset($this->listas[$id])) {
                    continue;
                }
                foreach (array('estado', 'pasada_el', 'n_asistieron', 'n_faltaron', 'ajmcm_tipo_c') as $campo) {
                    if (array_key_exists($campo, $this->listas[$id])) {
                        $rows[$i]['fields'][$campo] = $this->listas[$id][$campo];
                    }
                }
            }
            // Y las creadas en esta prueba, con los enlaces que se les hayan
            // puesto: sin sesión y sin grupo, el CRM las devuelve igual y es el
            // plugin quien no puede colocarlas (eso también se prueba así).
            foreach ($this->listas as $id => $l) {
                if ($id === 'l1') {
                    continue;
                }
                $fields = array('id' => $id, 'ajmcm_tipo_c' => 'participantes');
                foreach (array('estado', 'pasada_el', 'n_asistieron', 'n_faltaron', 'ajmcm_tipo_c') as $campo) {
                    if (array_key_exists($campo, $l)) {
                        $fields[$campo] = $l[$campo];
                    }
                }
                $row = array('fields' => $fields);
                if (!empty($l['__sesion'])) {
                    $row['sesion'] = array('id' => $l['__sesion'], 'name' => 'Sesion');
                }
                if (!empty($l['__grupo'])) {
                    $row['grupo'] = array('id' => $l['__grupo'], 'name' => 'Grupo');
                }
                $rows[] = $row;
            }
            return $this->entryListShape($rows, $rel);
        }
        if ($module === 'Contacts') {
            // Contactos por id: la consulta de la familia (UNA para todos los
            // familiares) y la de la gente de un grupo (UNA para todo el
            // grupo, en vez de una por chaval, que era el 1+N que hacía lento
            // cambiar de fecha).
            $fam = array(
                'fam1' => array(
                    'id' => 'fam1', 'first_name' => 'Solete', 'last_name' => 'Messeguer',
                    'name' => 'Solete Messeguer', 'phone_mobile' => '600 333 444',
                    'email1' => 'sol@example.com',
                ),
                'c1' => array(
                    'id' => 'c1', 'first_name' => 'Solete', 'last_name' => 'Vilarroya',
                    'name' => 'Solete Vilarroya', 'stic_age_c' => '13',
                    'phone_mobile' => '600111222', 'birthdate' => '2012-04-18',
                ),
                'c2' => array(
                    'id' => 'c2', 'first_name' => 'Jaume', 'last_name' => 'Pascual',
                    'name' => 'Jaume Pascual', 'stic_age_c' => '13',
                ),
                'c3' => array(
                    'id' => 'c3', 'first_name' => 'Marta', 'last_name' => 'Adulta',
                    'name' => 'Marta Adulta',
                ),
                'c9' => array(
                    'id' => 'c9', 'first_name' => 'Se', 'last_name' => 'Fue', 'name' => 'Se Fue',
                ),
                'm1' => array(
                    'id' => 'm1', 'first_name' => 'David', 'last_name' => 'Soler',
                    'name' => 'David Soler',
                ),
            );
            $out = array();
            foreach ($fam as $id => $datos) {
                if (strpos((string) $query, "'" . $id . "'") !== false) {
                    $out[] = $this->nvl($datos);
                }
            }
            return $out;
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
                // El evento de reuniones de programación. El nombre tiene que
                // ser EXACTAMENTE el que compone `sticpa_pl_reuniones_event_name()`:
                // así es como lo encuentra el plugin, por nombre y no por id.
                $this->nvl(array('id' => 'ev-reu', 'name' => 'Monitores | Reuniones de programación 2025-2026', 'ajmcm_etapa_c' => '')),
            );
        }
        return array();
    }

    public function getRelatedElementsForLoggedUser($p)
    {
        $self = $this;
        return $this->servir(
            'rel|' . md5(serialize($p)),
            $p['module_name'] . ':' . $p['link_field_name'],
            function () use ($self, $p) {
                return $self->datosDeRelacion($p);
            }
        );
    }

    public function datosDeRelacion($p)
    {
        $key = $p['module_name'] . ':' . $p['link_field_name'];

        switch ($key) {
            // Personas del grupo: participantes y monitor, en UNA llamada.
            case 'ajmcm_GRUPOS:ajmcm_grupos_stic_contacts_relationships':
                // Solo g1 tiene gente. Devolver la misma para todos los grupos
                // haría que un alcance por etapa pareciese incluir monitores
                // que no le tocan, y el test dejaría de comprobar nada.
                if ($p['module_id'] !== 'g1') {
                    return array();
                }
                // EL CAMPO PLANO VA SIEMPRE, con enlace anidado o sin él. Es
                // lo que dice §3.1 del parte de estado y lo que hace el CRM de
                // verdad: `..._ida` existe por relación y llega aunque el
                // enlace no. Sin esto el doble mentía en el sentido contrario
                // al de siempre —escondía un dato que sí está— y hacía
                // parecer inevitable una llamada por persona.
                return $this->apiShape(array(
                    $this->nvl(
                        array('id' => 'r1', 'relationship_type' => 'participante_mic_com', 'start_date' => '2025-09-01', 'end_date' => '', 'stic_contacts_relationships_contactscontacts_ida' => 'c1'),
                        array(array('id' => 'c1', 'first_name' => 'Solete', 'last_name' => 'Vilarroya', 'stic_age_c' => '13', 'phone_mobile' => '600111222'))
                    ),
                    $this->nvl(
                        array('id' => 'r2', 'relationship_type' => 'participante_mic_com', 'start_date' => '2025-09-01', 'end_date' => '', 'stic_contacts_relationships_contactscontacts_ida' => 'c2'),
                        array(array('id' => 'c2', 'first_name' => 'Jaume', 'last_name' => 'Pascual', 'stic_age_c' => '13'))
                    ),
                    // Relación caducada: no debe salir en la lista de hoy.
                    $this->nvl(
                        array('id' => 'r3', 'relationship_type' => 'participante_mic_com', 'end_date' => '2025-10-01', 'stic_contacts_relationships_contactscontacts_ida' => 'c9'),
                        array(array('id' => 'c9', 'first_name' => 'Se', 'last_name' => 'Fue'))
                    ),
                    $this->nvl(
                        array('id' => 'r4', 'relationship_type' => 'monitor', 'end_date' => '', 'stic_contacts_relationships_contactscontacts_ida' => 'm1'),
                        array(array('id' => 'm1', 'first_name' => 'David', 'last_name' => 'Soler'))
                    ),
                    // `grupo`: el papel de los +18 en su grupo de referencia. No
                    // lleva "participante_mic_com" pero cuenta igual como
                    // participante del grupo.
                    $this->nvl(
                        array('id' => 'r5', 'relationship_type' => 'grupo', 'end_date' => '', 'stic_contacts_relationships_contactscontacts_ida' => 'c3'),
                        array(array('id' => 'c3', 'first_name' => 'Marta', 'last_name' => 'Adulta'))
                    ),
                ));

            case 'Contacts:stic_contacts_relationships_contacts':
                $rels = array();
                // La relacion de monitor es de m1, no de todo el mundo: sin
                // esto el doble le daba un grupo a cualquiera que preguntase.
                if ($p['module_id'] === 'm1') {
                    $rels[] = $this->nvl(
                        array('id' => 'r4', 'relationship_type' => 'monitor', 'end_date' => ''),
                        array(array('id' => 'g1'))
                    );
                }
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
                return $this->apiShape($rels);

            // El respaldo: el contacto de una relacion, una llamada por
            // relacion. Sin enlaces anidados, solo el registro.
            case 'stic_Contacts_Relationships:stic_contacts_relationships_contacts':
                $map = array(
                    'r1' => array('id' => 'c1', 'first_name' => 'Solete', 'last_name' => 'Vilarroya', 'stic_age_c' => '13', 'phone_mobile' => '600111222'),
                    'r2' => array('id' => 'c2', 'first_name' => 'Jaume', 'last_name' => 'Pascual'),
                    'r4' => array('id' => 'm1', 'first_name' => 'David', 'last_name' => 'Soler'),
                );
                $rid = $p['module_id'];
                if (!isset($map[$rid])) {
                    return array();
                }
                return array($this->nvl($map[$rid]));

            // El respaldo de "mis grupos": el grupo de una relacion.
            case 'stic_Contacts_Relationships:ajmcm_grupos_stic_contacts_relationships':
                return ($p['module_id'] === 'r4')
                    ? array($this->nvl(array('id' => 'g1', 'name' => 'Los Peques')))
                    : array();

            case 'stic_Events:stic_sessions_stic_events':
                // POR EVENTO, no un juego de sesiones para todos: el de
                // reuniones y el de los sábados son dos eventos distintos y la
                // ficha del monitor los pinta en dos filas separadas. Un doble
                // que devolviera lo mismo para los dos daría las dos filas
                // iguales y no probaría nada.
                if (isset($p['module_id']) && $p['module_id'] === 'ev-reu') {
                    return $this->apiShape(array(
                        $this->nvl(array('id' => 'ru1', 'name' => 'Programación del 1.er trimestre', 'start_date' => '2025-09-20 10:00:00', 'end_date' => '2025-09-20 13:00:00')),
                        $this->nvl(array('id' => 'ru2', 'name' => 'Programación del 2.º trimestre', 'start_date' => '2025-11-08 10:00:00', 'end_date' => '2025-11-08 13:00:00')),
                        // Futura: no cuenta todavía.
                        $this->nvl(array('id' => 'ru3', 'name' => 'Programación del 3.er trimestre', 'start_date' => '2026-02-14 10:00:00', 'end_date' => '2026-02-14 13:00:00')),
                    ));
                }
                return $this->apiShape(array(
                    $this->nvl(array('id' => 's1', 'start_date' => '2025-11-01 16:30:00', 'end_date' => '2025-11-01 18:00:00')),
                    $this->nvl(array('id' => 's2', 'start_date' => '2025-11-08 16:30:00', 'end_date' => '2025-11-08 18:00:00')),
                    $this->nvl(array('id' => 's3', 'start_date' => '2025-11-15 16:30:00', 'end_date' => '2025-11-15 18:00:00')),
                    $this->nvl(array('id' => 's4', 'start_date' => '2025-11-22 16:30:00', 'end_date' => '2025-11-22 18:00:00')),
                ));

            case 'stic_Events:stic_registrations_stic_events':
                if (isset($p['module_id']) && $p['module_id'] === 'ev-reu') {
                    return $this->apiShape(array(
                        $this->nvl(array('id' => 'regr1', 'status' => 'confirmed'), array(array('id' => 'm1'))),
                    ));
                }
                return $this->apiShape(array(
                    $this->nvl(array('id' => 'reg1', 'status' => 'confirmed'), array(array('id' => 'c1'))),
                    $this->nvl(array('id' => 'reg2', 'status' => 'confirmed'), array(array('id' => 'c2'))),
                    // El monitor del g1 también está inscrito al evento semanal:
                    // sin inscripción no hay asistencias suyas que contar, y la
                    // fila de sábados de su ficha diría «no está inscrito».
                    $this->nvl(array('id' => 'regm1', 'status' => 'confirmed'), array(array('id' => 'm1'))),
                    // Cancelada: su asistencia no debe aparecer.
                    $this->nvl(array('id' => 'reg9', 'status' => 'cancelled'), array(array('id' => 'c9'))),
                ));

            case 'stic_Sessions:stic_attendances_stic_sessions':
                $filas = array(
                    'a1' => array('status' => 'yes', 'reg' => 'reg1'),
                    // Con MOTIVO ya escrito: hace falta para probar el caso de
                    // borrarlo, que es donde la tanda y la escritura de verdad
                    // se separaban y se pagaba dos veces.
                    'a2' => array('status' => '', 'reg' => 'reg2', 'desc' => 'Se fue antes'),
                );
                // Lo escrito se ve al releer.
                foreach ($filas as $id => $fila) {
                    if (isset($this->attStatus[$id])) {
                        $filas[$id]['status'] = $this->attStatus[$id];
                    }
                }
                // Y las asistencias creadas y enlazadas a ESTA sesión.
                $sesion = isset($p['module_id']) ? (string) $p['module_id'] : '';
                foreach ($this->attSession as $id => $sid) {
                    if ($sid !== $sesion || isset($filas[$id])) {
                        continue;
                    }
                    $filas[$id] = array(
                        'status' => isset($this->attStatus[$id]) ? $this->attStatus[$id] : '',
                        'reg' => isset($this->attReg[$id]) ? $this->attReg[$id] : '',
                    );
                }
                $out = array();
                foreach ($filas as $id => $fila) {
                    $out[] = $this->nvl(
                        array(
                            'id' => $id,
                            'status' => $fila['status'],
                            'description' => isset($fila['desc']) ? $fila['desc'] : '',
                        ),
                        array(array('id' => $fila['reg']))
                    );
                }
                return $this->apiShape($out);

            // Histórico de un participante: todas sus asistencias del curso.
            case 'stic_Registrations:stic_attendances_stic_registrations':
                // Las del monitor en el evento semanal: una sin marcar a
                // propósito (s2), que es el hueco que NO puede contar como
                // falta en el porcentaje.
                if (isset($p['module_id']) && $p['module_id'] === 'regm1') {
                    return $this->apiShape(array(
                        $this->nvl(array('id' => 'am1', 'status' => 'yes'), array(array('id' => 's1'))),
                        $this->nvl(array('id' => 'am3', 'status' => 'no_unjustified'), array(array('id' => 's3'))),
                    ));
                }
                // Y las del mismo monitor en las reuniones: vino a la primera y
                // faltó a la segunda.
                if (isset($p['module_id']) && $p['module_id'] === 'regr1') {
                    return $this->apiShape(array(
                        $this->nvl(array('id' => 'ar1', 'status' => 'yes'), array(array('id' => 'ru1'))),
                        $this->nvl(array('id' => 'ar2', 'status' => 'no_unjustified'), array(array('id' => 'ru2'))),
                    ));
                }
                return $this->apiShape(array(
                    $this->nvl(array('id' => 'a1', 'status' => 'yes'), array(array('id' => 's1'))),
                    $this->nvl(array('id' => 'a2', 'status' => 'no_unjustified'), array(array('id' => 's2'))),
                    $this->nvl(array('id' => 'a3', 'status' => 'partial'), array(array('id' => 's3'))),
                ));

            // Familia, CON LA FORMA REAL (verificada en el CRM el 27/08/2026):
            //
            //  - Los dos lados de la relación son campos planos y los DOS
            //    acaban en `_ida`. El código pedía `..._1contacts_idb`, que no
            //    existe, y por eso la familia salía vacía en todas las fichas.
            //  - El enlace anidado NO trae los datos del contacto: ni nombre
            //    completo ni teléfono. Hay que leer el contacto aparte.
            //  - La relación solo contesta por el primer enlace; desde
            //    Contacts, el `_1` devuelve cero.
            //
            // Este doble tiene que mentir lo mismo que el CRM o el arreglo no
            // se puede probar.
            case 'Contacts:stic_personal_environment_contacts':
                return $this->apiShape(array($this->nvl(array(
                    'id' => 'pe1',
                    'relationship_type' => 'mother',
                    'reference_contact' => '1',
                    'authorized_signer' => '1',
                    'end_date' => '',
                    'stic_personal_environment_contactscontacts_ida' => 'c1',
                    'stic_personal_environment_contacts_1contacts_ida' => 'fam1',
                ))));

            // Seguimientos de una persona (stic_FollowUps).
            // Avisos de comportamiento. Módulo verificado contra el CRM con
            // get_module_fields: existe, y `ajmcm_notificado_el_c` de la
            // especificación NO se creó (solo el booleano). Dos avisos de este
            // curso y uno del curso pasado, que NO tiene que contar: el
            // recuento «de 3» es del curso.
            case 'Contacts:avi_avisos_contacts':
                // Los que se hayan creado en esta misma petición, que el CRM
                // devuelve desde la persona en cuanto se guarda el campo plano.
                $nuevos = array();
                foreach (($this->avisosCiegos ? array() : $this->avisos) as $id => $a) {
                    $suyo = isset($a['avi_avisos_contactscontacts_ida'])
                        ? (string) $a['avi_avisos_contactscontacts_ida'] : '';
                    if ($suyo !== '' && $suyo === (string) $p['module_id']) {
                        $nuevos[] = $this->nvl($a);
                    }
                }
                if ($p['module_id'] !== 'c1') {
                    return $nuevos ? $this->apiShape($nuevos) : array();
                }
                return $this->apiShape(array_merge($nuevos, array(
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
                )));

            case 'Contacts:stic_followups_contacts':
                $out = array();
                foreach ($this->seguimientos as $i => $seg) {
                    $out[] = $this->nvl(array(
                        'id' => 'seg' . $i,
                        'name' => 'x',
                        'description' => $seg['texto'],
                        'type' => $seg['type'],
                        // La fecha manda: la ficha enseña por defecto solo los
                        // de ESTE curso, así que un seguimiento con `curso`
                        // puesto se coloca en el que diga.
                        'start_date' => isset($seg['fecha']) ? $seg['fecha'] : '2026-01-10 12:00:00',
                        'assigned_user_name' => 'MCM Castellón',
                    ));
                }
                return $this->apiShape($out);

            case 'stic_Sessions:lis_listas_stic_sessions':
                // Solo la sesión s3 tiene lista pasada: las anteriores están sin
                // pasar, que es lo que el selector tiene que distinguir.
                if ($p['module_id'] !== 's3') {
                    return array();
                }
                return $this->apiShape(array($this->nvl(
                    array('id' => 'l1', 'estado' => 'pasada', 'pasada_el' => '2025-11-15 18:05:00', 'n_asistieron' => 2, 'n_faltaron' => 0),
                    array(array('id' => 'g1'))
                )));
        }
        return array();
    }

    public function set_entry($module, $data)
    {
        // UNA ESCRITURA EN MODO RECOLECTA NO ESCRIBE NADA.
        //
        // Es lo que hace el transporte de verdad: `call()` mira `$collecting`,
        // apunta la petición y sale sin tocar el CRM. El doble no lo modelaba y
        // contaba la pasada de recolecta como una escritura más: una lista de
        // doce habría parecido veinticuatro escrituras, y —peor— un `prime()`
        // que se colara antes de un `set_entry` de verdad habría pasado por
        // bueno escribiendo dos veces.
        if ($this->recolectando) {
            $sig = 'se|' . md5(serialize(array($module, $data)));
            if (!isset($this->recolectado[$sig])) {
                $self = $this;
                $this->recolectado[$sig] = array(
                    'sig' => $sig,
                    'label' => 'set_entry:' . $module,
                    'producer' => function () use ($self, $module, $data) {
                        return $self->escribir($module, $data);
                    },
                );
            }
            return null;
        }
        $sig = 'se|' . md5(serialize(array($module, $data)));
        if (array_key_exists($sig, $this->traido)) {
            $datos = $this->traido[$sig];
            unset($this->traido[$sig]);
            return $datos;   // ya la escribió la tanda: no se repite
        }
        return $this->escribir($module, $data);
    }

    /** La escritura de verdad, para que la tanda y la llamada suelta compartan. */
    public function escribir($module, $data)
    {
        $this->writes[] = array('module' => $module, 'data' => $data);

        if (in_array($module, $this->failWrites, true)) {
            // La forma real de un rechazo: el CRM contesta 200 con un cuerpo de
            // error y el transporte lo deja en lastError y devuelve null.
            $this->lastError = 'Access Denied — el usuario no tiene acceso a ' . $module . ' — #40';
            return null;
        }
        $this->lastError = '';

        $id = isset($data['id']) ? $data['id'] : 'new-' . count($this->writes);
        if ($module === 'stic_Attendances' && array_key_exists('status', $data)) {
            $this->attStatus[$id] = (string) $data['status'];
        }
        if ($module === 'LIS_listas') {
            $previo = isset($this->listas[$id]) ? $this->listas[$id] : array();
            $this->listas[$id] = array_merge($previo, $data, array('id' => $id));
        }
        // Un aviso creado con su persona SE VE desde esa persona. El CRM crea
        // la fila de la relación al guardar el campo plano, y el doble tiene
        // que hacer lo mismo o mentiría sobre justo lo que se quiere probar:
        // que el aviso queda a nombre de alguien.
        if ($module === 'AVI_avisos') {
            $previo = isset($this->avisos[$id]) ? $this->avisos[$id] : array();
            $this->avisos[$id] = array_merge($previo, $data, array('id' => $id));
        }
        return $id;
    }

    public function set_relationship($module, $id, $link, $ids)
    {
        $this->relationships[] = array('module' => $module, 'id' => $id, 'link' => $link, 'ids' => $ids);

        if ($this->failRelationships) {
            $this->lastError = 'set_relationship(' . $link . ') ha fallado en 1 de 1';
            return false;
        }
        $this->lastError = '';

        $primero = isset($ids[0]) ? (string) $ids[0] : '';
        if ($module === 'stic_Attendances' && $link === 'stic_attendances_stic_sessions') {
            $this->attSession[$id] = $primero;
        }
        if ($module === 'stic_Attendances' && $link === 'stic_attendances_stic_registrations') {
            $this->attReg[$id] = $primero;
        }
        if ($module === 'LIS_listas' && $link === 'lis_listas_stic_sessions') {
            $this->listas[$id]['__sesion'] = $primero;
        }
        if ($module === 'LIS_listas' && $link === 'lis_listas_ajmcm_grupos') {
            $this->listas[$id]['__grupo'] = $primero;
        }
        // La forma real: {created, failed, deleted}.
        return (object) array('created' => count((array) $ids), 'failed' => 0, 'deleted' => 0);
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
        // El diario de guardados vive en wp_options y se arrastraría entre
        // pruebas.
        unset($GLOBALS['__stic_options']['sticpa_pl_save_log']);
        unset($_SERVER['REQUEST_METHOD']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__stic_pl_now']);
        unset($_SERVER['REQUEST_METHOD']);
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
        // c3 es `grupo` (un +18 en su grupo de referencia): también es
        // participante de la lista, aunque no lleve "participante_mic_com".
        $this->assertStringContainsString('data-contact="c3"', $html);
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

        // Las personas ya NO se piden por grupo: salen del mapa comun de la
        // delegacion, que es una sola llamada para las 28 filas del arbol.
        $porGrupo = array_filter($this->scp->calls, function ($c) {
            return $c === 'ajmcm_GRUPOS:ajmcm_grupos_stic_contacts_relationships';
        });
        $mapa = array_filter($this->scp->calls, function ($c) {
            return $c === 'getRecordsModule:stic_Contacts_Relationships';
        });
        $att = array_filter($this->scp->calls, function ($c) {
            return $c === 'stic_Sessions:stic_attendances_stic_sessions';
        });
        $this->assertCount(0, $porGrupo, 'ya no se pide una vez por grupo');
        $this->assertLessThanOrEqual(1, count($mapa), 'el mapa de relaciones se pide UNA vez');
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

    /**
     * BORRAR UN MOTIVO NO PUEDE ESCRIBIR DOS VECES.
     *
     * Es el caso que separaba la tanda de la escritura de verdad. Con un motivo
     * ya puesto en el CRM y el campo vacío en pantalla, la escritura manda
     * `description => ''` para borrarlo; si la tanda no lo mandaba también, los
     * dos payloads dejaban de ser el mismo, el memo no acertaba, y la misma
     * asistencia se escribía dos veces. El memo va por la FIRMA de la petición:
     * un campo de diferencia y no vale de nada.
     */
    public function test_borrar_un_motivo_no_duplica_la_escritura()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            /* c2 tiene «Se fue antes» en el CRM y aquí se ha borrado.
             * OJO CON LA FORMA: el navegador NO manda `c2 => ''`, manda un
             * `pl_notes` SIN c2 — `collectNotes()` solo mete las filas cuyo
             * motivo no está vacío. Probarlo con la cadena vacía dentro no
             * ejercita el caso real y deja pasar el fallo. */
            'pl_marks' => json_encode(array('c1' => 'yes', 'c2' => 'yes')),
            'pl_notes' => json_encode(array()),
        );
        $this->render('single_stic_pasar_lista_marcar');

        $attWrites = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_Attendances';
        }));
        $this->assertCount(2, $attWrites, 'una escritura por persona, ni una más');

        // Y el borrado va de verdad.
        $porId = array();
        foreach ($attWrites as $w) {
            $porId[$w['data']['id']] = $w['data'];
        }
        $this->assertArrayHasKey('description', $porId['a2']);
        $this->assertSame('', $porId['a2']['description']);
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



    // ---- EL bug: la orden de guardar tiene que llegar --------------------

    /**
     * LA REGRESIÓN QUE IMPORTA. «Pasar lista» no pasaba lista desde el primer
     * día por esto: el JS deshabilitaba el botón de enviar DENTRO del manejador
     * de `submit`, y un control deshabilitado no se serializa. `pl_action` no
     * salía del navegador, PHP se saltaba el guardado entero y la pantalla no
     * decía ni una palabra. Cero rastro en el CRM.
     *
     * El arreglo de fondo está en el JS (no deshabilitar hasta el tic
     * siguiente), pero eso ningún test de PHP lo puede ver. Lo que sí se puede
     * exigir es el cinturón: que la acción viaje también en un campo oculto,
     * que no depende de ningún botón.
     */
    public function test_el_formulario_manda_la_accion_en_un_campo_oculto()
    {
        $_REQUEST = array('grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringContainsString(
            '<input type="hidden" name="pl_action" value="save" data-pl-action>',
            $html
        );
        // Y los botones siguen llevándola: sin JS son los que distinguen
        // guardar de «sin registro».
        $this->assertStringContainsString('name="pl_action" value="skip"', $html);
        $this->assertStringContainsString('name="pl_action" value="save"', $html);
    }

    /** La misma garantía en la pantalla de monitores. */
    public function test_monitores_tambien_manda_la_accion_en_un_campo_oculto()
    {
        $this->scp->coordEtapa = 'COM';
        $html = $this->render('single_stic_pasar_lista_monitores');

        $this->assertStringContainsString(
            '<input type="hidden" name="pl_action" value="save" data-pl-action>',
            $html
        );
    }

    /**
     * Y si aun así llega un POST sin acción, NO se traga en silencio: se dice y
     * se apunta. Es la diferencia entre «no se guarda y no sé por qué» y un
     * diagnóstico de treinta segundos.
     */
    public function test_un_post_sin_accion_no_se_traga_el_guardado()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c1' => 'yes')),
        );
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringContainsString('llegó sin la orden de guardar', $html);
        $this->assertStringNotContainsString('Lista guardada', $html);
        $this->assertSame(array(), $this->scp->writes);

        $log = sticpa_pl_save_log();
        $this->assertNotEmpty($log);
        $this->assertSame('post_sin_accion', $log[0]['motivo']);
        // Con el tamaño de lo que sí llegó: prueba de que el POST no venía vacío.
        $this->assertGreaterThan(0, $log[0]['marcas_post']);
    }

    // ---- «Lista guardada» solo si es verdad ------------------------------

    /** Un guardado bueno deja la señal que el JS necesita para tirar el borrador. */
    public function test_un_guardado_bueno_marca_la_senal_para_el_borrador()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c1' => 'yes')),
        );
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringContainsString('Lista guardada', $html);
        $this->assertStringContainsString('data-pl-saved-ok', $html);

        $log = sticpa_pl_save_log();
        $this->assertSame('ok', $log[0]['motivo']);
        $this->assertSame(1, $log[0]['saved']);
        $this->assertSame(0, $log[0]['failed']);
    }

    /**
     * Si el CRM rechaza la LISTA, la pantalla no puede felicitar. Este era el
     * agujero: el fallo de la lista no se contaba en `failed`, así que decía
     * «Lista guardada» con el CRM sin lista.
     */
    public function test_si_el_crm_rechaza_la_lista_no_se_dice_guardada()
    {
        $this->scp->failWrites = array('LIS_listas');
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c1' => 'yes')),
        );
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringNotContainsString('Lista guardada', $html);
        $this->assertStringNotContainsString('data-pl-saved-ok', $html);
        $this->assertStringContainsString('no se ha guardado del todo', mb_strtolower($html));

        // Y el motivo del CRM queda apuntado, con el paso exacto.
        $log = sticpa_pl_save_log();
        $pasos = array_column($log[0]['errores'], 'paso');
        $this->assertContains('lista_actualizar', $pasos);
        $this->assertStringContainsString('Access Denied', $log[0]['errores'][0]['error']);
    }

    /** Si rechaza las ASISTENCIAS, tampoco. */
    public function test_si_el_crm_rechaza_las_asistencias_no_se_dice_guardada()
    {
        $this->scp->failWrites = array('stic_Attendances');
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c1' => 'yes')),
        );
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringNotContainsString('Lista guardada', $html);
        $log = sticpa_pl_save_log();
        $this->assertContains('asistencia_actualizar', array_column($log[0]['errores'], 'paso'));
    }

    /**
     * Una asistencia CREADA a la que le falla el enlace queda huérfana: el CRM
     * no la cuenta en el porcentaje. Antes se lanzaba el enlace y nadie miraba
     * el resultado.
     */
    /**
     * GUARDAR NO PUEDE SER UNA ESCRITURA DETRÁS DE OTRA.
     *
     * Un C1 de doce eran doce `set_entry` en fila con el monitor mirando la
     * rueda: en móvil, seis segundos largos por pulsar Guardar. Son doce filas
     * distintas de la misma tabla, independientes entre sí, así que salen en
     * UNA tanda. Se escriben las mismas doce veces; lo que cambia es que no se
     * esperan una a otra.
     */
    public function test_guardar_manda_las_asistencias_en_una_tanda()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c1' => 'yes', 'c2' => 'no_unjustified')),
        );
        $this->render('single_stic_pasar_lista_marcar');

        // Las dos asistencias, escritas una vez cada una.
        $att = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_Attendances';
        }));
        $this->assertCount(2, $att);

        // Y las dos en la MISMA tanda: alguna de las tandas lleva 2 peticiones.
        $this->assertContains(2, $this->scp->batches, 'las asistencias no van juntas');
    }

    /**
     * SI NO SE PUEDE ATAR LA ASISTENCIA, NO SE ESCRIBE.
     *
     * Antes se creaba igual y se apuntaba el fallo del enlace. Esa asistencia
     * huérfana es la que llenó el CRM de «Unknown - Unknown»: sin inscripción
     * detrás no tiene nombre, no la cuenta el CRM y —lo grave— no se puede
     * volver a encontrar, así que el guardado siguiente creaba OTRA. Escribir
     * basura irrecuperable es peor que no escribir: no se nota.
     */
    public function test_una_relacion_fallida_cuenta_como_fallo()
    {
        $this->scp->failRelationships = true;
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            // c3 no tiene asistencia previa ni inscripción: es el camino de CREAR.
            'pl_marks' => json_encode(array('c3' => 'yes')),
        );
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringNotContainsString('Lista guardada', $html);
        $log = sticpa_pl_save_log();
        $pasos = array_column($log[0]['errores'], 'paso');
        $this->assertContains('sin_inscripcion', $pasos);

        // Y NINGUNA asistencia escrita: ni una huérfana.
        $att = array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_Attendances';
        });
        $this->assertCount(0, $att, 'no se escribe una asistencia que no se puede atar');
    }

    /**
     * Una asistencia nueva nace con TODO lo que necesita para existir: los dos
     * enlaces en el propio registro (el CRM compone el nombre al guardar, así
     * que llegar tarde con los enlaces deja «Unknown - Unknown» para siempre) y
     * la fecha de la sesión, que es la columna por la que se consulta.
     */
    public function test_una_asistencia_nueva_nace_atada_y_con_fecha()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c3' => 'yes')),
        );
        $this->render('single_stic_pasar_lista_marcar');

        $att = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_Attendances' && !isset($w['data']['id']);
        }));
        $this->assertCount(1, $att);
        $d = $att[0]['data'];
        $this->assertNotSame('', $d['stic_attendances_stic_sessionsstic_sessions_ida']);
        $this->assertNotSame('', $d['stic_attendances_stic_registrationsstic_registrations_ida']);
        $this->assertArrayHasKey('start_date', $d);
        // `end_date` NO existe en stic_Attendances: la API contesta 400 si se
        // manda (verificado contra el CRM).
        $this->assertArrayNotHasKey('end_date', $d);
    }

    /**
     * A quien no está inscrito se le crea la inscripción, que es de donde
     * cuelga la asistencia. Es el caso NORMAL en monitores, no la excepción.
     */
    public function test_a_quien_no_esta_inscrito_se_le_crea_la_inscripcion()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c3' => 'yes')),
        );
        $this->render('single_stic_pasar_lista_marcar');

        $regs = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_Registrations';
        }));
        $this->assertCount(1, $regs);
        $this->assertSame('c3', $regs[0]['data']['stic_registrations_contactscontacts_ida']);
    }

    /**
     * El caso traicionero: el CRM acepta la escritura y al releer no está el
     * dato. Sin relectura eso se cuenta como éxito y la pantalla felicita.
     */
    public function test_si_al_releer_no_esta_no_se_dice_guardada()
    {
        // Falta el estado de quien se marcó: no está guardado.
        $problemas = sticpa_pl_check_saved(
            array('c1' => 'yes'),
            array('id' => 'l1', 'estado' => 'pasada'),
            array('c1' => array('id' => 'a1', 'status' => '')),
            false
        );
        $this->assertNotEmpty($problemas);
        $this->assertStringContainsString('no ha quedado guardada', $problemas[0]);

        // Con todo en su sitio, ni un problema.
        $this->assertSame(array(), sticpa_pl_check_saved(
            array('c1' => 'yes'),
            array('id' => 'l1', 'estado' => 'pasada'),
            array('c1' => array('id' => 'a1', 'status' => 'yes')),
            false
        ));

        // Una lista que no aparece al releer también lo es.
        $this->assertNotEmpty(sticpa_pl_check_saved(array(), null, array(), false));

        // Y en «sin registro» se comprueba el estado de la lista, no las marcas.
        $this->assertSame(array(), sticpa_pl_check_saved(
            array(),
            array('id' => 'l1', 'estado' => 'omitida'),
            array(),
            true
        ));
        $this->assertNotEmpty(sticpa_pl_check_saved(
            array(),
            array('id' => 'l1', 'estado' => 'pasada'),
            array(),
            true
        ));
    }

    // ---- El diario de intentos -------------------------------------------

    /** Un nonce caducado no escribe nada, y eso también se apunta. */
    public function test_el_diario_apunta_el_nonce_caducado()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => 'no-vale',
            'pl_marks' => json_encode(array('c1' => 'yes')),
        );
        $this->render('single_stic_pasar_lista_marcar');

        $log = sticpa_pl_save_log();
        $this->assertSame('nonce', $log[0]['motivo']);
        $this->assertSame(array(), $this->scp->writes);
    }

    /**
     * «Sin marcas» se apunta con el tamaño del campo crudo: es lo que distingue
     * «no marcó a nadie» de «las marcas llegaron y el filtrado se las comió»,
     * que son dos bugs distintos y desde fuera se ven igual.
     */
    public function test_el_diario_distingue_sin_marcas_de_filtrado()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            // Marcas de gente que no es del grupo: llegan y el filtrado las tira.
            'pl_marks' => json_encode(array('c99' => 'yes')),
        );
        $this->render('single_stic_pasar_lista_marcar');

        $log = sticpa_pl_save_log();
        $this->assertSame('sin_marcas', $log[0]['motivo']);
        $this->assertGreaterThan(0, $log[0]['marcas_post']);
        $this->assertSame(0, $log[0]['marcas_usadas']);
    }

    // ---- El tipo de lista -----------------------------------------------

    /** `ajmcm_tipo_c` es REQUERIDO en el CRM: se manda, no se deja al defecto. */
    public function test_la_lista_lleva_el_tipo_participantes()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c1' => 'yes')),
        );
        $this->render('single_stic_pasar_lista_marcar');

        $listWrites = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'LIS_listas';
        }));
        $this->assertSame('participantes', $listWrites[0]['data']['ajmcm_tipo_c']);
    }

    /**
     * Una lista de MONITORES vive en la misma sesión que la del grupo (para eso
     * está `ajmcm_tipo_c`). No puede colarse como la lista del grupo: diría que
     * el grupo tiene la lista pasada cuando no la tiene.
     */
    public function test_una_lista_de_monitores_no_se_toma_por_la_del_grupo()
    {
        $id = $this->scp->set_entry('LIS_listas', array(
            'estado' => 'pasada', 'ajmcm_tipo_c' => 'monitores',
            'n_asistieron' => 3, 'n_faltaron' => 0,
        ));
        $this->scp->set_relationship('LIS_listas', $id, 'lis_listas_stic_sessions', array('s2'));
        $this->scp->set_relationship('LIS_listas', $id, 'lis_listas_ajmcm_grupos', array('g1'));

        $this->assertNull(sticpa_pl_lista($this->scp, 's2', 'g1'));
        // Y la de participantes de siempre sigue encontrándose.
        $this->assertNotNull(sticpa_pl_lista($this->scp, 's3', 'g1'));

        // Pero SÍ se encuentra en su propio índice, que es de donde la lee
        // coordinación para no duplicarla al volver a guardar.
        $monitores = sticpa_pl_all_listas_monitores($this->scp);
        $this->assertArrayHasKey('s2', $monitores);
        $this->assertSame($id, $monitores['s2']['id']);
        $this->assertSame(3, $monitores['s2']['n_asistieron']);
    }

    // ---- Caché: un vacío no vale doce horas ------------------------------

    /**
     * Un resultado VACÍO puede ser «no hay nada» o «el CRM no ha contestado», y
     * se guardaban igual: 12 horas. Un hipo del CRM dejaba el grupo «sin
     * participantes» hasta la madrugada — y el mapa de inscripciones vacío, que
     * es lo que impide escribir cualquier asistencia.
     */
    public function test_una_coleccion_vacia_caduca_enseguida()
    {
        $largo = sticpa_pl_ttl_structure();
        sticpa_pl_cache_put('k_vacia', array(), $largo);
        sticpa_pl_cache_put('k_llena', array('algo'), $largo);

        $expiraVacia = $GLOBALS['__stic_transients']['k_vacia']['expires'];
        $expiraLlena = $GLOBALS['__stic_transients']['k_llena']['expires'];
        $this->assertLessThan($expiraLlena, $expiraVacia);
        $this->assertLessThanOrEqual(
            stic_test_now() + sticpa_pl_ttl_empty(),
            $expiraVacia
        );
        // Y lo lleno conserva su TTL completo.
        $this->assertSame(stic_test_now() + $largo, $expiraLlena);
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
        // Los botones grandes van ANTES de la lista de contacto.
        $this->assertLessThan(strpos($html, '>Contacto<'), strpos($html, 'pl-contact-btn'));
        // Y apuntan al contacto de REFERENCIA de la familia, no al primero que salga.
        $this->assertStringContainsString('Llamar a Solete Messeguer', $html);
    }

    /**
     * LOS CUADRADITOS, AGRUPADOS POR MES Y CON EL MES ESCRITO.
     *
     * A veinticuatro sesiones la fila corrida solo decía «hay rojos», no
     * cuándo — y cuándo es el dato: cuatro faltas seguidas en enero y cuatro
     * repartidas por el curso no son el mismo chaval.
     */
    public function test_las_sesiones_se_agrupan_por_mes_con_su_etiqueta()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_ficha');

        $this->assertStringContainsString('pl-sq-mon', $html);
        $this->assertStringContainsString('pl-sq-mlabel', $html);
        // Y el hueco mudo de antes ya no se pinta.
        $this->assertStringNotContainsString('pl-sq-gap', $html);
        // El último lleva su anillo: «cómo va últimamente» sin contar hasta el
        // final.
        $this->assertStringContainsString('pl-sq--last', $html);
    }

    /**
     * «Formación» va plegada, pero con los títulos en la solapa: es la sección
     * más larga de la ficha y la que menos se abre, y aun así el «tiene el MAT»
     * se tiene que leer sin desplegar nada.
     */
    public function test_formacion_se_pliega_sin_esconder_los_titulos()
    {
        $this->scp->coordEtapa = 'COM';
        $_REQUEST = array('monitor' => 'm1');
        $html = $this->render('single_stic_pasar_lista_monitor');

        $this->assertMatchesRegularExpression(
            '/<summary class="pl-fold-sum">Formación<span class="pl-fold-count">[^<]*MAT/u',
            $html
        );
        // Y el contenido sigue estando, no se ha perdido por el camino.
        $this->assertStringContainsString('Director/a de tiempo libre', $html);
    }

    /**
     * EL PORCENTAJE DE CADA MONITOR, EN LA LISTA (ROADMAP «ausencias»).
     *
     * Coordinación quiere el número sin abrir treinta fichas. Va pequeño y al
     * final de la línea de los grupos: lo que salta a la vista sigue siendo la
     * nota roja, que solo sale cuando hay algo que mirar.
     */
    public function test_la_lista_de_monitores_enseña_el_porcentaje()
    {
        // El doble trae tres sesiones, y el mínimo real para opinar son cuatro
        // marcadas: se baja aquí para poder probar el pintado. El umbral de
        // verdad se prueba en el test de al lado.
        $GLOBALS['__stic_filters']['sticpa_pl_seguimiento_umbrales'] = array(
            'pct_minimo' => 60, 'seguidas' => 3, 'minimo_para_opinar' => 1,
        );
        $this->scp->coordEtapa = 'COM';
        $html = $this->render('single_stic_pasar_lista_monitores');

        $this->assertStringContainsString('pl-rowpct', $html);
    }

    /**
     * Y NO se pinta con cuatro datos: un porcentaje sobre dos sesiones es una
     * anécdota, y una anécdota con pinta de dato es peor que ningún dato.
     */
    public function test_sin_sesiones_suficientes_no_se_pinta_porcentaje()
    {
        $GLOBALS['__stic_filters']['sticpa_pl_seguimiento_umbrales'] = array(
            'pct_minimo' => 60, 'seguidas' => 3, 'minimo_para_opinar' => 99,
        );
        $this->scp->coordEtapa = 'COM';
        $html = $this->render('single_stic_pasar_lista_monitores');

        $this->assertStringNotContainsString('pl-rowpct', $html);
    }

    /**
     * UN MONITOR DE DOS ETAPAS SALE UNA VEZ, Y LA OTRA SECCIÓN LO DICE.
     *
     * Dos filas de la misma persona en una lista de marcar acaban
     * contradiciéndose, así que sale una sola vez —en la etapa de su curso más
     * bajo—. Pero entonces quien mira la otra sección da por hecho que no está,
     * y eso es peor: por eso la sección que no lo tiene lo nombra.
     */
    public function test_un_monitor_de_dos_etapas_sale_una_vez_y_se_dice()
    {
        $this->scp->coordEtapa = '';        // alcance ancho: entran las dos etapas
        $this->scp->monitorDeDosEtapas = true;
        $html = $this->render('single_stic_pasar_lista_monitores');

        // Una sola fila suya.
        $this->assertSame(1, substr_count($html, 'data-contact="m1"'));
        // Y la otra sección lo nombra, CON SU NOMBRE, que es lo que se busca.
        $this->assertStringContainsString('También lleva grupo de esta etapa', $html);
        $this->assertStringContainsString('David Soler (MIC)', $html);
        // EL CASO PEOR: en esta prueba la sección de COM se queda sin ninguna
        // fila propia. Antes desaparecía entera y la pantalla decía, sin
        // decirlo, «en COM no hay monitores». Tiene que seguir estando.
        $this->assertStringContainsString('>COM<', $html);
    }

    /**
     * «CAMBIOS SIN GUARDAR» TIENE QUE SER OTRA COSA QUE «GUARDADO».
     *
     * Uno es una promesa y el otro un hecho, y hasta ahora eran el mismo
     * párrafo gris. El texto viaja desde el servidor —el JS de esta área no
     * tiene puente de traducción— y lo pinta la barra de guardar.
     */
    public function test_las_dos_listas_traen_el_aviso_de_sin_guardar()
    {
        $_REQUEST = array('grupo' => 'g1');
        $marcar = $this->render('single_stic_pasar_lista_marcar');
        $this->assertStringContainsString('data-msg-dirty=', $marcar);
        $this->assertStringContainsString('solo en tu móvil', $marcar);

        $this->setUp();
        $this->scp->coordEtapa = 'COM';
        $monitores = $this->render('single_stic_pasar_lista_monitores');
        $this->assertStringContainsString('data-msg-dirty=', $monitores);
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

    /**
     * LA PERSONA VA EN EL PROPIO REGISTRO, no solo en la relación.
     *
     * Los cuatro primeros avisos reales llegaron al CRM con «Persona del aviso»
     * vacía: se creaba el registro y se ataba después con `set_relationship`,
     * que escribe la tabla puente por detrás y deja el campo sin rellenar. El
     * campo plano de la relación es el camino que usa la propia pantalla del
     * CRM, y es el que hace que el aviso se vea a nombre de alguien.
     */
    public function test_ficha_el_aviso_lleva_la_persona_en_el_registro()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $_POST = array(
            'pl_nonce' => wp_create_nonce('pl_ficha_c1'),
            'pl_aviso_motivo' => 'Algo',
        );
        $this->render('single_stic_pasar_lista_ficha');

        $writes = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'AVI_avisos';
        }));
        $this->assertSame('c1', $writes[0]['data']['avi_avisos_contactscontacts_ida']);
    }

    /**
     * LA SESIÓN VA EN SU CAMPO DE ID, no en el que se pinta.
     *
     * `ajmcm_sesion_c` es el campo que se muestra; meterle un id de 36
     * caracteres deja el registro con un texto ilegible y, aun así, sin sesión.
     * El id vive en `stic_sessions_id_c`, igual que `contact_id_c` para «puesto
     * por».
     */
    public function test_ficha_el_aviso_guarda_la_sesion_en_su_campo()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1', 'sesion' => 's2');
        $_POST = array(
            'pl_nonce' => wp_create_nonce('pl_ficha_c1'),
            'pl_aviso_motivo' => 'Algo',
        );
        $this->render('single_stic_pasar_lista_ficha');

        $writes = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'AVI_avisos';
        }));
        $this->assertSame('s2', $writes[0]['data']['stic_sessions_id_c']);
        $this->assertArrayNotHasKey('ajmcm_sesion_c', $writes[0]['data']);
    }

    /**
     * Y la sesión llega hasta ahí sola: la lista la pone en el enlace de la
     * ficha, porque un aviso puesto un sábado es un aviso DE ese sábado.
     */
    public function test_marcar_pasa_la_sesion_al_enlace_de_la_ficha()
    {
        $_REQUEST = array('grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertMatchesRegularExpression(
            '/single_stic_pasar_lista_ficha&participante=c1&grupo=g1&sesion=[^"&]+/',
            $html
        );
    }

    /**
     * SI EL AVISO NO QUEDA A NOMBRE DE NADIE, SE DICE.
     *
     * El caso real: el CRM acepta el registro, contesta un id, y el aviso se
     * queda sin persona. Antes la pantalla decía «Aviso registrado» y el
     * monitor se iba tan tranquilo; el aviso no existía para nadie.
     */
    public function test_ficha_avisa_si_el_aviso_no_queda_enlazado()
    {
        $this->scp->failRelationships = true;
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $_POST = array(
            'pl_nonce' => wp_create_nonce('pl_ficha_c1'),
            'pl_aviso_motivo' => 'Algo',
        );
        // El doble deja de reconocer el campo plano: es el escenario de «se ha
        // escrito y no está».
        $this->scp->avisosCiegos = true;
        $html = $this->render('single_stic_pasar_lista_ficha');

        $this->assertStringNotContainsString('Aviso registrado', $html);
        $this->assertStringContainsString('no ha quedado registrado', $html);
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

    /**
     * El porcentaje se dice con denominador, y el denominador son las sesiones
     * MARCADAS.
     *
     * Si el grupo pasó tres listas de diez, un chaval que vino a las tres no
     * tiene un 30 %: tiene un 100 % de lo que se sabe y siete sábados sin
     * datos. Los sábados sin lista se cuentan aparte y la ficha los dice.
     */
    public function test_ficha_asistencia_con_denominador()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_ficha');

        // yes + partial cuentan: 2 de las 3 sesiones marcadas (s4 no ha llegado).
        $this->assertStringContainsString('2 de 3 sesiones marcadas', $html);
        $this->assertStringContainsString('67', $html);
        // Y los cuadraditos, el mismo idioma que en la ficha de un monitor.
        $this->assertStringContainsString('pl-sq--yes', $html);
        $this->assertStringContainsString('pl-sq--partial', $html);
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

    /**
     * EL DETALLE DEL FALLO LO VE TODO EL MUNDO. Estaba reservado a
     * coordinación, y eso convertía cada fallo en un teléfono escacharrado: el
     * monitor decía «no se guarda» y la respuesta del CRM —la que dice qué
     * pasa— no la leía nadie hasta que otra persona lo reproducía.
     */
    public function test_el_detalle_del_fallo_lo_ve_cualquiera()
    {
        // Sin alcance de coordinación: un monitor normal.
        $this->scp->coordEtapa = null;
        $this->scp->failWrites = array('LIS_listas');
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c1' => 'yes')),
        );
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringContainsString('Detalle técnico del fallo', $html);
        $this->assertStringContainsString('Access Denied', $html);
        // Dentro de un `details` cerrado: quien no quiera abrirlo ve el aviso
        // en castellano y ya está.
        $this->assertStringContainsString('<details', $html);
    }

    /** Y se puede apagar sin tocar código si algún día molesta. */
    public function test_el_detalle_se_puede_apagar_con_un_filtro()
    {
        $GLOBALS['__stic_filters']['sticpa_pl_debug_allowed'] = false;
        $this->scp->failWrites = array('LIS_listas');
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c1' => 'yes')),
        );
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringNotContainsString('Detalle técnico del fallo', $html);
        // Pero el aviso en castellano sigue estando: eso no se esconde nunca.
        $this->assertStringContainsString('no se ha guardado del todo', mb_strtolower($html));
    }

    // ---- UX: el paso siguiente y la salida a la ficha --------------------

    /**
     * Tras guardar bien, la pantalla ofrece qué hacer ahora. Antes se quedaba
     * igual que estaba: el monitor había terminado y la aplicación no le decía
     * nada, con el resumen y el árbol a tres toques.
     */
    public function test_tras_guardar_bien_se_ofrece_el_paso_siguiente()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c1' => 'yes')),
        );
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringContainsString('pl-next', $html);
        $this->assertStringContainsString('Ver el resumen', $html);
        $this->assertStringContainsString('Otro grupo', $html);
    }

    /** Y si el guardado FALLA, no se ofrece nada: sería una burla. */
    public function test_si_falla_el_guardado_no_se_ofrece_el_paso_siguiente()
    {
        $this->scp->failWrites = array('LIS_listas');
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => json_encode(array('c1' => 'yes')),
        );
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringNotContainsString('pl-next-btn', $html);
    }

    /**
     * La hoja de estados lleva salida a la ficha. Al marcar una falta, lo
     * siguiente que se quiere es el teléfono de casa — y la hoja tapa la
     * pantalla, así que la flecha de la fila queda detrás.
     */
    public function test_la_hoja_lleva_salida_a_la_ficha()
    {
        $_REQUEST = array('grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringContainsString('data-pl-sheet-ficha', $html);
        $this->assertStringContainsString('Ficha y teléfonos', $html);
    }

    // ---- La lista de monitores: por etapa y por curso --------------------

    /**
     * Treinta monitores seguidos no se leen. Se parten en las mismas secciones
     * que el árbol y con el mismo punto de color: MIC arriba (rojo), COM
     * debajo (verde).
     */
    public function test_monitores_se_parte_por_etapa_con_los_mic_arriba()
    {
        $this->scp->coordEtapa = '';   // alcance amplio: MIC y COM
        $html = $this->render('single_stic_pasar_lista_monitores');

        $mic = strpos($html, '>MIC<');
        $com = strpos($html, '>COM<');
        if ($mic === false || $com === false) {
            $this->markTestSkipped('el doble no tiene monitores de las dos etapas');
        }
        $this->assertLessThan($com, $mic, 'los del MIC van arriba');
        $this->assertStringContainsString('pl-etapa-dot', $html);
    }

    /**
     * El curso escolar es TEXTO LIBRE en el CRM, así que ordenar alfabéticamente
     * pone «1º ESO» antes que «4º Primaria» — justo al revés de como se lee una
     * lista de grupos.
     */
    public function test_el_curso_se_ordena_como_se_lee()
    {
        $r = function ($t) { return sticpa_pl_curso_rank($t); };

        // Primaria antes que ESO, y dentro por número.
        $this->assertLessThan($r('5º Primaria'), $r('4º Primaria'));
        $this->assertLessThan($r('1º ESO'), $r('6º Primaria'));
        $this->assertLessThan($r('1º Bachillerato'), $r('4º ESO'));
        // Valenciano y castellano, lo mismo.
        $this->assertSame($r('6º Primaria'), $r('6é Primària'));
        $this->assertSame($r('2º Bachillerato'), $r('2n Batxillerat'));
        // Lo que no se reconoce va al final, pero no se pierde.
        $this->assertGreaterThan($r('2º Bachillerato'), $r('Adultos'));
        $this->assertGreaterThan($r('Adultos'), $r('Vete a saber'));
        // Y sin curso, al final del todo pero antes que lo ilegible.
        $this->assertGreaterThan($r('2º Bachillerato'), $r(''));
    }

    // ---- La casilla de «este grupo entra en Pasar Lista» -----------------

    /**
     * LA REGLA DE SEGURIDAD. El día que se cree el campo en el CRM estará vacío
     * en los ~150 grupos: si el filtro actuara, Pasar Lista se quedaría SIN UN
     * SOLO GRUPO y parecería que se ha roto todo.
     */
    public function test_sin_ninguna_casilla_marcada_no_se_esconde_nada()
    {
        $this->scp->gruposActivos = null;   // el campo, vacío en todos
        $groups = sticpa_pl_groups($this->scp);

        $this->assertCount(4, $groups);
        $this->assertSame(0, sticpa_pl_grupos_ocultos($this->scp));
    }

    /** Y en cuanto alguien marca, salen solo los marcados. */
    public function test_con_casillas_marcadas_solo_salen_esos()
    {
        $this->scp->gruposActivos = array('g1', 'g3');
        $groups = sticpa_pl_groups($this->scp);

        $this->assertSame(array('g1', 'g3'), array_keys($groups));
        // Y se sabe cuántos quedan fuera, para poder decirlo.
        $this->assertSame(2, sticpa_pl_grupos_ocultos($this->scp));
    }

    /**
     * El árbol lo dice, en vez de dejar que alguien busque un grupo que no ve.
     * Pero como NOTA AL PIE: gris, pequeña y al final. Es un dato que hay que
     * poder saber, no un aviso que compita con la lista de grupos.
     */
    public function test_el_arbol_avisa_de_los_grupos_no_marcados()
    {
        $this->scp->gruposActivos = array('g1');
        $html = $this->render('single_stic_pasar_lista_grupos');

        $this->assertStringContainsString('sin marcar para Pasar Lista', $html);
        $this->assertStringContainsString('3 grupos', $html);
        $this->assertStringContainsString('pl-footnote', $html);
        // Y al final: después de la lista de grupos, no antes.
        $this->assertGreaterThan(strpos($html, 'pl-group'), strpos($html, 'pl-footnote'));
    }

    /**
     * Una casilla de SuiteCRM llega de muchas formas según por dónde salga.
     * Tratar solo `'1'` como sí escondería grupos que están marcados.
     */
    public function test_la_casilla_se_entiende_en_todas_sus_formas()
    {
        foreach (array('1', 'on', 'true', 'yes', 'checked', 'On', 'TRUE') as $si) {
            $this->assertTrue(sticpa_pl_bool_crm($si), $si . ' es sí');
        }
        foreach (array('', '0', 'off', 'false', 'no', null) as $no) {
            $this->assertFalse(sticpa_pl_bool_crm($no), var_export($no, true) . ' es no');
        }
    }

    /** Y se puede apagar del todo mientras el campo no exista en el CRM. */
    public function test_el_campo_se_puede_apagar_con_un_filtro()
    {
        $GLOBALS['__stic_filters']['sticpa_pl_has_grupo_activo'] = false;
        $this->scp->gruposActivos = array('g1');
        $groups = sticpa_pl_groups($this->scp);

        $this->assertCount(4, $groups, 'apagado, no filtra nada');
    }

    /**
     * LA FAMILIA, POR LOS CAMPOS PLANOS. Sin esto la ficha no sirve para nada
     * un sábado: sin familia no hay teléfonos, y el teléfono es lo que se busca
     * cuando un chaval no ha venido.
     *
     * El código pedía `stic_personal_environment_contacts_1contacts_idb`, que
     * NO EXISTE (los dos lados acaban en `_ida`), y leía los datos solo del
     * enlace anidado, que esta instancia no puebla. Resultado: bloque de
     * familia vacío en TODAS las fichas, sin un aviso.
     */
    public function test_la_familia_sale_por_los_campos_planos()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_ficha');

        $this->assertStringContainsString('Solete Messeguer', $html, 'la familia tiene que salir');
        $this->assertStringContainsString('600 333 444', $html, 'y con su teléfono');
        // El parentesco, traducido: el CRM lo guarda en inglés.
        $this->assertStringContainsString('Madre', $html);
        $this->assertStringNotContainsString('mother', $html);
    }

    /** Y los familiares se leen en UNA consulta, no una por persona. */
    public function test_la_familia_se_lee_en_una_sola_consulta()
    {
        $_REQUEST = array('participante' => 'c1', 'grupo' => 'g1');
        $this->render('single_stic_pasar_lista_ficha');

        $contactos = array_filter($this->scp->calls, function ($c) {
            return $c === 'getRecordsModule:Contacts';
        });
        $this->assertLessThanOrEqual(1, count($contactos));
    }

    /**
     * LO QUE DE VERDAD SE PAGA SON LOS VIAJES DE IDA Y VUELTA, no las consultas.
     *
     * Ocho consultas en fila a 400 ms son más de tres segundos de espera pura.
     * Las mismas ocho en dos tandas son menos de uno. `CosteLlamadasTest` cuenta
     * consultas —que también importan— y este test cuenta TANDAS, que es lo que
     * nota un monitor el sábado.
     */
    public function test_marcar_agrupa_sus_consultas_en_dos_tandas()
    {
        $_REQUEST = array('grupo' => 'g1');
        $this->render('single_stic_pasar_lista_marcar');

        $this->assertCount(2, $this->scp->batches, 'dos tandas: lo independiente y lo que depende del evento');
        $this->assertSame(4, $this->scp->batches[0], 'grupos, relaciones, eventos y listas van juntos');
        $this->assertSame(2, $this->scp->batches[1], 'sesiones e inscripciones van juntas');

        // Y el total de consultas no ha subido por paralelizar.
        $this->assertLessThanOrEqual(10, count($this->scp->calls));
    }

    /** Lo mismo en la pantalla de monitores, que era la más lenta. */
    public function test_monitores_agrupa_sus_consultas_en_tandas()
    {
        $this->scp->coordEtapa = 'COM';
        $this->render('single_stic_pasar_lista_monitores');

        $this->assertNotEmpty($this->scp->batches);
        // Tres tandas: lo independiente, lo que depende del evento, y las dos
        // lecturas de asistencias —la de la sesión y la del curso entero para
        // los avisos—, que van DESPUÉS del guardado y por eso no caben en la
        // segunda. Tres viajes, no siete consultas en fila.
        $this->assertLessThanOrEqual(3, count($this->scp->batches));
        // Y la mayoría de sus consultas viajan agrupadas.
        $this->assertGreaterThanOrEqual(7, array_sum($this->scp->batches));
    }

    /**
     * Y con la paralelización apagada todo sigue funcionando: es la red de
     * seguridad para un hosting sin `curl_multi`.
     */
    public function test_sin_paralelizar_la_pantalla_sigue_pintando()
    {
        $GLOBALS['__stic_filters']['sticpa_pl_paralelo'] = false;
        $_REQUEST = array('grupo' => 'g1');
        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertSame(array(), $this->scp->batches, 'ni una tanda');
        $this->assertStringContainsString('data-pl-marcar', $html);
        $this->assertStringContainsString('Solete', $html);
    }

    /**
     * EL 1+N QUE HACÍA ETERNA LA PANTALLA DE MONITORES.
     *
     * `sticpa_pl_group_people()` caía al respaldo por grupo cuando ESE grupo
     * salía vacío, y coordinación recorre todos los grupos de su alcance: con
     * ~150 grupos en el CRM (casi todos históricos y vacíos) eran decenas de
     * llamadas para pintar doce monitores.
     *
     * La condición correcta es «el mapa de relaciones no sirve», no «este grupo
     * está vacío». Este test lo fija: con el mapa bueno, NO se pregunta por
     * ningún grupo, aunque haya grupos vacíos en el alcance (g2 lo está).
     */
    public function test_monitores_no_pregunta_grupo_a_grupo()
    {
        $this->scp->coordEtapa = 'COM';
        $this->render('single_stic_pasar_lista_monitores');

        $porGrupo = array_filter($this->scp->calls, function ($c) {
            return $c === 'ajmcm_GRUPOS:ajmcm_grupos_stic_contacts_relationships';
        });
        $this->assertSame(array(), array_values($porGrupo), 'ni una consulta por grupo');
        // Y ninguna llamada repetida: todas son de colección.
        $this->assertSame(
            array(),
            array_filter(array_count_values($this->scp->calls), function ($n) { return $n > 1; }),
            'ninguna consulta se repite: sería un 1+N'
        );
    }

    /**
     * Un grupo que se PINTA y sale vacío sí vuelve a preguntar: cuesta UNA
     * llamada y es lo que rescata a un grupo que existe pero cuya gente no vino
     * en el mapa de la delegación. Quitar esto dejó a C1 sin participantes un
     * sábado; el problema nunca fue el respaldo, fue el bucle.
     */
    public function test_un_grupo_vacio_que_se_pinta_vuelve_a_preguntar()
    {
        $antes = count($this->scp->calls);
        $people = sticpa_pl_group_people($this->scp, 'g2');
        $nuevas = array_slice($this->scp->calls, $antes);

        $this->assertSame(array(), $people['participants']);
        $this->assertContains('ajmcm_GRUPOS:ajmcm_grupos_stic_contacts_relationships', $nuevas);
    }

    /** Pero la puerta del bucle NO pregunta nunca: ahí es donde era un 1+N. */
    public function test_la_puerta_del_bucle_no_pregunta_por_grupo()
    {
        $antes = count($this->scp->calls);
        sticpa_pl_group_people_bulk($this->scp, 'g2');
        $nuevas = array_slice($this->scp->calls, $antes);

        $this->assertNotContains('ajmcm_GRUPOS:ajmcm_grupos_stic_contacts_relationships', $nuevas);
    }

    /** Y el filtro vuelve a su sitio: el siguiente grupo sí puede preguntar. */
    public function test_la_puerta_del_bucle_no_deja_el_filtro_puesto()
    {
        sticpa_pl_group_people_bulk($this->scp, 'g2');

        $antes = count($this->scp->calls);
        sticpa_pl_group_people($this->scp, 'g2');
        $nuevas = array_slice($this->scp->calls, $antes);
        $this->assertContains('ajmcm_GRUPOS:ajmcm_grupos_stic_contacts_relationships', $nuevas);
    }

    /**
     * Pero si el mapa NO sirve (la instancia no devuelve enlaces ni campos
     * planos), el respaldo tiene que seguir saltando: sin él un monitor se
     * queda sin poder pasar lista un sábado.
     */
    public function test_si_el_mapa_no_sirve_el_respaldo_sigue_saltando()
    {
        $this->scp->sinEnlaces = true;
        $people = sticpa_pl_group_people($this->scp, 'g1');
        $this->assertNotEmpty($people['participants'], 'el respaldo por grupo tiene que salvar la pantalla');
        $this->assertContains('ajmcm_GRUPOS:ajmcm_grupos_stic_contacts_relationships', $this->scp->calls);
    }

    /**
     * LA LISTA DE MONITORES SE ESCRIBE. Antes se guardaban las asistencias y no
     * quedaba constancia de que la lista se hubiera pasado: no había forma de
     * saber si un sábado nadie la pasó o si la pasaron y no vino nadie.
     *
     * Lleva `ajmcm_tipo_c = monitores` y NO lleva grupo: el alcance de
     * coordinación es la etapa, no un grupo.
     */
    public function test_la_lista_de_monitores_se_escribe_con_su_tipo()
    {
        $this->scp->coordEtapa = 'COM';
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_monitores'),
            'pl_marks' => json_encode(array('m1' => 'no_unjustified')),
        );
        $this->render('single_stic_pasar_lista_monitores');

        $listas = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'LIS_listas';
        }));
        $this->assertCount(1, $listas);
        $this->assertSame('monitores', $listas[0]['data']['ajmcm_tipo_c']);
        $this->assertSame('pasada', $listas[0]['data']['estado']);
        $this->assertSame(0, $listas[0]['data']['n_asistieron']);
        $this->assertSame(1, $listas[0]['data']['n_faltaron']);

        // Enlazada a la sesión, y a NINGÚN grupo.
        $links = array_values(array_filter($this->scp->relationships, function ($r) {
            return $r['module'] === 'LIS_listas';
        }));
        $cuales = array_column($links, 'link');
        $this->assertContains('lis_listas_stic_sessions', $cuales);
        $this->assertNotContains('lis_listas_ajmcm_grupos', $cuales);
    }

    /** Y al volver a guardar se ACTUALIZA la misma, no se duplica. */
    public function test_la_lista_de_monitores_no_se_duplica()
    {
        $this->scp->coordEtapa = 'COM';
        $post = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_monitores'),
            'pl_marks' => json_encode(array('m1' => 'no_unjustified')),
        );

        $_POST = $post;
        $this->render('single_stic_pasar_lista_monitores');
        $primera = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'LIS_listas';
        }));
        $this->assertArrayNotHasKey('id', $primera[0]['data'], 'la primera se crea');

        // Segundo guardado, ahora sin faltas.
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_monitores'),
            'pl_marks' => json_encode(array()),
        );
        $this->render('single_stic_pasar_lista_monitores');

        $listas = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'LIS_listas';
        }));
        $this->assertCount(2, $listas, 'dos escrituras, no dos listas');
        $this->assertNotEmpty($listas[1]['data']['id'], 'la segunda actualiza la primera');
        $this->assertSame(1, $listas[1]['data']['n_asistieron']);
        $this->assertSame(0, $listas[1]['data']['n_faltaron']);
    }

    /** Y la pantalla lo dice al volver a entrar: evita pasarla dos veces. */
    public function test_monitores_dice_que_la_lista_ya_esta_pasada()
    {
        $this->scp->coordEtapa = 'COM';
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_monitores'),
            'pl_marks' => json_encode(array()),
        );
        $this->render('single_stic_pasar_lista_monitores');

        // Segunda visita, sin guardar nada.
        $_POST = array();
        $html = $this->render('single_stic_pasar_lista_monitores');
        $this->assertStringContainsString('ya está pasada', $html);
        $this->assertStringContainsString('1 vinieron, 0 faltas', $html);
    }

    /**
     * La ficha del monitor: el ORDEN es el diseño.
     *
     * Primero cómo va, y el certificado de delitos sexuales dentro de «En
     * regla», no abriendo la pantalla. Es lo que pidió el propietario y es lo
     * que distingue esta ficha del CRM, así que se prueba el orden y no solo
     * que los textos estén.
     */
    public function test_ficha_del_monitor()
    {
        $this->scp->coordEtapa = 'COM';
        $_REQUEST = array('monitor' => 'm1');
        $html = $this->render('single_stic_pasar_lista_monitor');

        /* EL ORDEN COMPLETO, que es el que dictó el propietario:
         *   cómo va → sus grupos → seguimientos → por dónde ha pasado → papeleo
         * El papeleo va al final entero, y dentro de él «En regla» abre y los
         * datos de padrón cierran. */
        /* Con el `>` delante: son CABECERAS de sección, no texto suelto. Sin él,
         * «Formación» acertaba en «Formación en protección del menor», que está
         * dentro de «En regla», y el test daba por desordenada una pantalla que
         * estaba bien. */
        $orden = array(
            '>Cómo va este curso<',
            '>Sus grupos<',
            '>Seguimientos<',
            '>Por dónde ha pasado<',
            '>En regla<',
            '>Datos MCM<',
            '>Formación<',
            '>Datos personales<',
        );
        $anterior = -1;
        foreach ($orden as $seccion) {
            $donde = strpos($html, $seccion);
            $this->assertNotFalse($donde, 'falta la sección «' . $seccion . '»');
            $this->assertGreaterThan(
                $anterior,
                $donde,
                '«' . $seccion . '» está fuera de sitio'
            );
            $anterior = $donde;
        }
        // Y el certificado, dentro del papeleo y no abriendo la pantalla.
        $this->assertLessThan(
            strpos($html, 'Certificado de delitos sexuales'),
            strpos($html, 'Cómo va este curso'),
            'el certificado ya no abre la ficha'
        );

        // Formación, con el descuadre del DAT sin archivo.
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

    /** «En regla»: cuenta lo que falta, y un permiso no dado NO es una falta. */
    public function test_ficha_del_monitor_en_regla()
    {
        $this->scp->coordEtapa = 'COM';
        $_REQUEST = array('monitor' => 'm1');
        $html = $this->render('single_stic_pasar_lista_monitor');

        // Solo el código de conducta está a cero de las OBLIGACIONES.
        $this->assertStringContainsString('falta 1', $html);
        $this->assertStringContainsString('pl-chk--bad', $html);
        // La cesión de imágenes tampoco está dada, pero es un permiso: gris, no
        // rojo, y con su explicación. Si esto se pintara en rojo, la ficha
        // diría que alguien incumple algo por haber dicho que no a una foto.
        $this->assertStringContainsString('pl-chk--opt', $html);
        $this->assertStringContainsString('No lo ha autorizado', $html);
        // Lo que sí está, con su check: la lista se enseña entera.
        $this->assertStringContainsString('Autorizó a pedirlo cada año', $html);
        $this->assertStringContainsString('Acuerdo de confidencialidad', $html);
    }

    /**
     * Las tres pistas de cuadraditos, y la regla que las gobierna.
     *
     * El monitor del doble tiene: sábados con una sesión sin marcar (s2), dos
     * reuniones (vino a una) y una lista pasada por él de tres sábados.
     */
    public function test_ficha_del_monitor_pistas_de_seguimiento()
    {
        $this->scp->coordEtapa = 'COM';
        $_REQUEST = array('monitor' => 'm1');
        $html = $this->render('single_stic_pasar_lista_monitor');

        // SÁBADOS: vino a 1 y faltó a 1 de las DOS marcadas → 50 %. El hueco de
        // s2 no cuenta: si contara serían 33 % y sería una falta que nadie ha
        // registrado.
        $this->assertStringContainsString('Sábados', $html);
        $this->assertStringContainsString('50<i>%</i>', $html);
        $this->assertStringContainsString('Vino a 1 de 2', $html);
        $this->assertStringContainsString('sin marcar, fuera de la cuenta', $html);
        $this->assertStringContainsString('pl-sq--none', $html);

        // REUNIONES: separadas y con fracción, no con porcentaje. Con cuatro al
        // año, un 75 % suena a nota y es una sola falta.
        $this->assertStringContainsString('Reuniones', $html);
        $this->assertStringContainsString('1 de 2', $html);

        // LISTAS: la que pasó él en verde, y el aviso de que la puede pasar
        // cualquiera que cubra el sábado.
        $this->assertStringContainsString('Listas del C1', $html);
        $this->assertStringContainsString('pl-sq--suya', $html);
        $this->assertStringContainsString('quien cubra ese sábado', $html);
    }

    /** Sus grupos: el que lleva y el suyo, con los números calculados en vivo. */
    public function test_ficha_del_monitor_sus_grupos()
    {
        $this->scp->coordEtapa = 'COM';
        $_REQUEST = array('monitor' => 'm1');
        $html = $this->render('single_stic_pasar_lista_monitor');

        $this->assertStringContainsString('Sus grupos', $html);
        // El que lleva, con enlace a su lista y los recuentos del mapa de
        // relaciones (3 participantes en g1: c1, c2 y la del rol `grupo`).
        $this->assertStringContainsString('pl-grp-role--lleva', $html);
        $this->assertStringContainsString('3 participantes', $html);
        $this->assertStringContainsString('single_stic_pasar_lista_marcar&grupo=g1', $html);
        // Y el suyo, el COM-LC, que en el CRM está en otra pestaña y aquí no.
        $this->assertStringContainsString('pl-grp-role--suyo', $html);
        $this->assertStringContainsString('Ruah', $html);
        $this->assertStringContainsString('desde 2022', $html);
    }

    /**
     * El histórico: curso a curso, con quién estaba, y SIN la relación de
     * pertenencia repetida en cada año.
     */
    public function test_ficha_del_monitor_historico()
    {
        $this->scp->coordEtapa = 'COM';
        $_REQUEST = array('monitor' => 'm1');
        $html = $this->render('single_stic_pasar_lista_monitor');

        $this->assertStringContainsString('Por dónde ha pasado', $html);
        $this->assertStringContainsString('2025-2026', $html);
        $this->assertStringContainsString('2024-2025', $html);
        // El curso pasado llevaba los MIC, y con Jaime.
        $this->assertStringContainsString('con Jaime Bort', $html);
        // Su relación `grupo` va de 2022 a hoy: si el histórico la incluyera,
        // habría un 2022-2023 y un 2023-2024 con solo «Ruah», que es ruido.
        $this->assertStringNotContainsString('2022-2023', $html);
        $this->assertStringNotContainsString('2023-2024', $html);
        // El curso de ahora, marcado.
        $this->assertStringContainsString('pl-hist-item--now', $html);
    }

    /**
     * El contacto no repite el móvil, y el otro teléfono no ocupa una fila.
     *
     * Los dos botones grandes de arriba YA son el móvil. Una fila más con el
     * mismo número debajo es el mismo dato dos veces empujando hacia abajo lo
     * que sí se viene a leer.
     */
    public function test_ficha_del_monitor_contacto_en_una_linea()
    {
        $this->scp->coordEtapa = 'COM';
        $_REQUEST = array('monitor' => 'm1');
        $html = $this->render('single_stic_pasar_lista_monitor');

        // Los dos botones grandes, sí.
        $this->assertStringContainsString('pl-contact-btn--wa', $html);
        $this->assertStringContainsString('tel:608084613', $html);
        // Pero NO una fila «Móvil» repitiendo el número.
        $this->assertStringNotContainsString('>Móvil<', $html);

        // El correo se LEE: va en texto, en su propia fila y con etiqueta.
        $this->assertStringContainsString('pl-contactrow', $html);
        $this->assertStringContainsString('david@movimientoconsolacion.com', $html);
        // Y SE COPIA DE UN TOQUE, que es lo que se hace con un correo: pegarlo
        // en otro sitio. El valor va en el `data-`, no se lee del DOM, porque
        // ahí está recortado con puntos suspensivos.
        $this->assertStringContainsString('data-pl-copy="david@movimientoconsolacion.com"', $html);

        // El otro teléfono, EN SU PROPIA FILA Y CON SU ETIQUETA. Antes era un
        // botón redondo sin texto al lado del correo: se leía como «llamar a
        // esta persona» cuando es justo lo contrario —es el teléfono de una
        // urgencia— y de paso partía el correo en dos líneas.
        $this->assertStringContainsString('tel:964200300', $html);
        $this->assertStringContainsString('964 200 300', $html);
        $this->assertStringContainsString('Otro teléfono', $html);
        $this->assertStringNotContainsString('>Emergencias<', $html);
    }

    /**
     * Sin seguimientos: un vacío tranquilo, y el formulario detrás de un botón.
     *
     * Cuatro campos desplegados al final de la ficha son cuatro campos que hay
     * que pasar cada vez que se abre a alguien, y se escribe una nota de cada
     * veinte visitas.
     */
    public function test_ficha_del_monitor_seguimientos_vacios()
    {
        $this->asOtherPerson();
        $this->scp->coordEtapa = 'COM';
        $_REQUEST = array('monitor' => 'm1');
        $html = $this->render('single_stic_pasar_lista_monitor');

        $this->assertStringContainsString('pl-empty', $html);
        $this->assertStringContainsString('Nada apuntado este curso', $html);
        // Ni icono de aviso ni la frase de antes, que sonaba a que faltaba algo.
        $this->assertStringNotContainsString('Todavía no hay seguimientos', $html);
        // El formulario existe, pero escondido detrás del botón.
        $this->assertStringContainsString('Escribir un seguimiento', $html);
        $this->assertStringContainsString('id="pl-seg-form"', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*id="pl-seg-form"[^>]*hidden/', $html);
    }

    /**
     * Solo los de ESTE curso, y un enlace para traer los del anterior.
     *
     * El CRM los devuelve todos en la misma lectura, así que traer los de antes
     * no cuesta ninguna consulta: lo único que cambia es el filtro.
     */
    public function test_ficha_del_monitor_seguimientos_solo_de_este_curso()
    {
        $this->asOtherPerson();
        $this->scp->coordEtapa = 'COM';
        $this->scp->seguimientos = array(
            array('type' => 'mcm_incidencia', 'texto' => 'LO DE ESTE CURSO', 'fecha' => '2025-11-08 12:00:00'),
            array('type' => 'mcm_incidencia', 'texto' => 'LO DEL CURSO PASADO', 'fecha' => '2025-02-14 12:00:00'),
        );

        $_REQUEST = array('monitor' => 'm1');
        $html = $this->render('single_stic_pasar_lista_monitor');
        $this->assertStringContainsString('LO DE ESTE CURSO', $html);
        $this->assertStringNotContainsString('LO DEL CURSO PASADO', $html);
        $this->assertStringContainsString('Ver también los de 2024-2025', $html);

        // Y con el enlace pulsado, los dos.
        $_REQUEST = array('monitor' => 'm1', 'seg' => 'antes');
        $html = $this->render('single_stic_pasar_lista_monitor');
        $this->assertStringContainsString('LO DE ESTE CURSO', $html);
        $this->assertStringContainsString('LO DEL CURSO PASADO', $html);
        $this->assertStringContainsString('Ver solo los de 2025-2026', $html);
    }

    /** Sin nada de antes, no se ofrece traer nada de antes. */
    public function test_ficha_del_monitor_sin_cursos_anteriores_no_hay_enlace()
    {
        $this->asOtherPerson();
        $this->scp->coordEtapa = 'COM';
        $this->scp->seguimientos = array(
            array('type' => 'mcm_incidencia', 'texto' => 'Solo esto', 'fecha' => '2025-11-08 12:00:00'),
        );
        $_REQUEST = array('monitor' => 'm1');
        $html = $this->render('single_stic_pasar_lista_monitor');
        $this->assertStringNotContainsString('Ver también los de', $html);
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

        // El evento de reuniones del curso YA existe —es lo normal a partir de
        // la segunda reunión—, así que no se crea otro. Crear un segundo evento
        // con el mismo nombre partiría el histórico de asistencia en dos.
        $events = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'stic_Events';
        }));
        $this->assertSame(array(), $events, 'no se duplica el evento del curso');

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
        $this->assertStringContainsString('2026-01-10', $writes[0]['data']['start_date']);
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

    // -----------------------------------------------------------------------
    // Arbol de grupos: buscador y linea de datos del artboard
    // -----------------------------------------------------------------------

    /**
     * La linea del artboard `Grupos`: monitores, curso y participantes. Antes
     * ponia la ETAPA, que ya esta en la cabecera de la seccion.
     */
    public function testElArbolPintaMonitoresYRecuento()
    {
        $html = $this->render('single_stic_pasar_lista_grupos');

        $this->assertStringContainsString('David Soler', $html);
        $this->assertStringContainsString('11 participantes', $html);
        // g2 tiene el recuento VIEJO: su numero no se pinta, su monitor si.
        $this->assertStringContainsString('Mercedes', $html);
        $this->assertStringNotContainsString('10 participantes', $html);
    }

    /**
     * La etapa ya no se repite en cada fila. Se comprueba sobre la linea de
     * datos concreta, no sobre la pagina: "COM" aparece legitimamente en la
     * cabecera de la seccion.
     */
    public function testLaLineaDeDatosNoRepiteLaEtapa()
    {
        $html = $this->render('single_stic_pasar_lista_grupos');
        if (preg_match_all('/<span class="pl-group-meta">(.*?)<\/span>/s', $html, $m)) {
            foreach ($m[1] as $meta) {
                $this->assertStringNotContainsString('MIC ·', $meta);
                $this->assertStringNotContainsString('COM ·', $meta);
            }
        } else {
            $this->fail('No se ha pintado ninguna linea de datos de grupo.');
        }
    }

    /** El buscador solo aparece cuando hay bastantes grupos para necesitarlo. */
    public function testElBuscadorNoSalePorCuatroGrupos()
    {
        // El doble devuelve 4 grupos, por debajo del umbral.
        $html = $this->render('single_stic_pasar_lista_grupos');
        $this->assertStringNotContainsString('data-pl-filter', $html);
    }

    // -----------------------------------------------------------------------
    // La invalidacion de caches (el boton de refrescar)
    // -----------------------------------------------------------------------

    /**
     * El fallo real: refrescar borraba cuatro claves fijas de las doce que se
     * usan, asi que las personas de un grupo seguian viniendo de la cache. Y
     * esa clave lleva DENTRO el id del grupo, asi que no se puede borrar por
     * nombre: hay que invalidarla por generacion.
     */
    public function testRefrescarInvalidaLasPersonasDeUnGrupo()
    {
        $antes = sticpa_pl_cache_key('people', $this->scp, 'g1');

        sticpa_pl_flush($this->scp, 'all');

        $this->assertNotSame($antes, sticpa_pl_cache_key('people', $this->scp, 'g1'));
    }

    /** Y lo mismo con las que no llevan id: grupos, coordinacion, monitores. */
    public function testRefrescarInvalidaTodaLaEstructura()
    {
        $antes = array();
        foreach (array('structure', 'mygroups', 'coord', 'acomp', 'events', 'nogroup') as $what) {
            $antes[$what] = sticpa_pl_cache_key($what, $this->scp);
        }

        sticpa_pl_flush($this->scp, 'all');

        foreach ($antes as $what => $key) {
            $this->assertNotSame($key, sticpa_pl_cache_key($what, $this->scp), $what . ' seguia cacheada');
        }
    }

    /**
     * Guardar una lista invalida el ESTADO, no la estructura: si tirase los
     * grupos y las personas, cada guardado costaria volver a pedirlo todo.
     */
    public function testGuardarSoloInvalidaElEstado()
    {
        $estructura = sticpa_pl_cache_key('people', $this->scp, 'g1');
        $estado = sticpa_pl_cache_key('state', $this->scp);

        sticpa_pl_flush($this->scp, 'state');

        $this->assertSame($estructura, sticpa_pl_cache_key('people', $this->scp, 'g1'));
        $this->assertNotSame($estado, sticpa_pl_cache_key('state', $this->scp));
    }

    /**
     * EL BOTÓN DE REFRESCAR, EN LAS CUATRO PANTALLAS.
     *
     * Estaba pintado en cuatro y el `flush` solo en dos: en el árbol de grupos
     * —justo donde más se toca, porque es donde se ve que falta alguien— el
     * botón no hacía NADA. Se pulsaba, no cambiaba nada, y la conclusión era
     * que el dato del CRM estaba mal. Se comprueba pantalla por pantalla,
     * porque el fallo era exactamente "en esta sí y en esta no".
     */
    public function testElBotonDeRefrescarRefrescaEnTodasLasPantallas()
    {
        $paginas = array(
            'single_stic_pasar_lista',
            'single_stic_pasar_lista_grupos',
            'single_stic_pasar_lista_resumen',
            'single_stic_pasar_lista_marcar',
        );
        foreach ($paginas as $pagina) {
            $_REQUEST = array('refrescar' => '1', 'grupo' => 'g1');
            $antes = sticpa_pl_cache_key('structure', $this->scp);
            $this->render($pagina);
            $this->assertNotSame(
                $antes,
                sticpa_pl_cache_key('structure', $this->scp),
                $pagina . ': el botón de refrescar no tiró la caché'
            );
        }
    }

    /** Y sin `?refrescar=1` NO se tira: si no, no habría caché nunca. */
    public function testSinPedirloNoSeTiraLaCache()
    {
        $_REQUEST = array('grupo' => 'g1');
        $antes = sticpa_pl_cache_key('structure', $this->scp);
        $this->render('single_stic_pasar_lista_grupos');
        $this->assertSame($antes, sticpa_pl_cache_key('structure', $this->scp));
    }

    /**
     * El grupo sin participantes ofrece volver a mirar. Es el caso de "lo he
     * arreglado en el CRM ahora mismo": sin este enlace hay que salir a la
     * portada, refrescar allí y volver a entrar.
     */
    public function testUnGrupoVacioOfreceVolverAMirar()
    {
        // g3 es el grupo sin nadie del doble.
        $_REQUEST = array('grupo' => 'g3');
        $html = $this->render('single_stic_pasar_lista_marcar');
        $this->assertStringContainsString('no tiene participantes', $html);
        $this->assertStringContainsString('refrescar=1', $html);
        $this->assertStringContainsString('Ya lo he arreglado', $html);
    }

    /** Las rachas se calculan sobre las asistencias: caducan con ellas. */
    public function testLasRachasCaducanConElEstado()
    {
        $antes = sticpa_pl_cache_key('streaks', $this->scp);
        sticpa_pl_flush($this->scp, 'state');
        $this->assertNotSame($antes, sticpa_pl_cache_key('streaks', $this->scp));
    }

    // -----------------------------------------------------------------------
    // El mapa comun de relaciones (una llamada para toda la delegacion)
    // -----------------------------------------------------------------------

    /**
     * EL BUG GRAVE, fijado donde de verdad ocurria: un grupo con gente decia
     * "0 participantes". La causa final no era solo donde vienen los enlaces,
     * es que `get_relationships` NO los devuelve en esta instancia. Ahora las
     * personas salen de `get_entry_list`, que es la via probada.
     */
    public function testUnGrupoConGenteNoSaleVacio()
    {
        $people = sticpa_pl_group_people($this->scp, 'g1');

        $nombres = array_column($people['participants'], 'name');
        $this->assertContains('Solete Vilarroya', $nombres);
        $this->assertContains('Jaume Pascual', $nombres);
        // El rol `grupo` de los +18 cuenta como participante.
        $this->assertContains('Marta Adulta', $nombres);
        // Y el monitor va en su cubo, no con los participantes.
        $this->assertSame(array('David Soler'), array_column($people['monitors'], 'name'));
    }

    /** Los datos de la persona llegan enteros, no solo el nombre. */
    public function testLaPersonaLlegaConSusDatos()
    {
        $people = sticpa_pl_group_people($this->scp, 'g1');
        $solete = null;
        foreach ($people['participants'] as $p) {
            if ($p['name'] === 'Solete Vilarroya') { $solete = $p; }
        }
        $this->assertNotNull($solete);
        $this->assertSame('c1', $solete['id']);
        $this->assertSame('13', $solete['age']);
        $this->assertSame('600111222', $solete['mobile']);
        $this->assertSame('SV', $solete['initials']);
    }

    /** Un grupo sin nadie sigue devolviendo la forma, no un aviso. */
    public function testUnGrupoSinGenteDevuelveLosDosCubosVacios()
    {
        // g2, que en el doble no tiene ninguna relación. (g3 sí tiene monitor
        // desde que la pantalla de monitores necesita dos etapas para probarse.)
        $people = sticpa_pl_group_people($this->scp, 'g2');
        $this->assertSame(array(), $people['participants']);
        $this->assertSame(array(), $people['monitors']);
    }

    /** "Tu grupo": el monitor en sesion es m1, y su grupo es g1. */
    public function testMisGruposSaleDelMapa()
    {
        $_SESSION['scp_user_id'] = 'm1';
        $this->assertSame(array('g1'), sticpa_pl_my_groups($this->scp));
    }

    /** Y quien no es monitor de nada no tiene atajo, sin inventarse uno. */
    public function testSinRelacionDeMonitorNoHayGrupos()
    {
        $_SESSION['scp_user_id'] = 'nadie';
        $this->assertSame(array(), sticpa_pl_my_groups($this->scp));
    }

    /** Los que no tienen grupo, para "datos por revisar". */
    public function testParticipantesSinGrupo()
    {
        $sin = sticpa_pl_participants_without_group($this->scp);
        $nombres = array_column($sin, 'name');

        $this->assertContains('Sol Messeguer', $nombres);
        $this->assertContains('Lucia Ripolles', $nombres);
        // Un MONITOR sin grupo no es un participante sin grupo.
        $this->assertNotContains('Un Monitor', $nombres);
        // Ni los que si tienen grupo.
        $this->assertNotContains('Solete Vilarroya', $nombres);
    }

    /**
     * Lo que se prometio: UNA llamada para toda la delegacion, no una por
     * grupo. Se pintan las 4 filas del arbol y se cuenta.
     */
    public function testElArbolNoPideLasPersonasGrupoAGrupo()
    {
        $this->render('single_stic_pasar_lista_grupos');

        $mapa = array_filter($this->scp->calls, function ($c) {
            return $c === 'getRecordsModule:stic_Contacts_Relationships';
        });
        $this->assertLessThanOrEqual(1, count($mapa));
    }

    // -----------------------------------------------------------------------
    // El respaldo: una instancia que NO devuelve enlaces anidados
    // -----------------------------------------------------------------------

    /**
     * ESTE es el test que importa. En la instancia real ni `get_relationships`
     * ni los campos planos traen la persona, y el sintoma era "0 participantes"
     * en un grupo con gente: un monitor sin poder pasar lista un sabado.
     *
     * Con el respaldo, Solete aparece igual.
     */
    public function testSinEnlacesAnidadosSoleteSigueSaliendo()
    {
        $this->scp->sinEnlaces = true;

        $people = sticpa_pl_group_people($this->scp, 'g1');

        $this->assertNotEmpty($people['participants'], 'el grupo NO puede salir vacio');

        $porId = array();
        foreach ($people['participants'] as $person) {
            $porId[$person['id']] = $person;
        }
        // Solete, con su id: es lo que hace falta para guardar la asistencia.
        $this->assertArrayHasKey('c1', $porId);
        $this->assertSame('Solete Vilarroya', $porId['c1']['name']);
        $this->assertSame('SV', $porId['c1']['initials']);
        // Y el monitor en su cubo.
        $this->assertSame(array('David Soler'), array_column($people['monitors'], 'name'));
        // Alfabetico por apellido: Adulta, Pascual, Vilarroya.
        $this->assertSame(
            array('Marta Adulta', 'Jaume Pascual', 'Solete Vilarroya'),
            array_column($people['participants'], 'name')
        );
    }

    /**
     * LOS DOS CAMINOS TIENEN QUE DAR LA MISMA LISTA.
     *
     * El respaldo resolvía a la gente una llamada por persona, y esa consulta
     * no encontraba a quien entra al grupo por el papel `grupo` (los +18 en su
     * grupo de referencia): con enlaces salían tres participantes y sin ellos
     * dos. Un grupo que cambia de tamaño según cómo conteste el CRM es un grupo
     * en el que no se puede pasar lista.
     */
    public function testLosDosCaminosDanLaMismaGente()
    {
        $conEnlaces = sticpa_pl_group_people(new FakeSCP(), 'g1');

        $GLOBALS['__stic_transients'] = array();
        $otro = new FakeSCP();
        $otro->sinEnlaces = true;
        $sinEnlaces = sticpa_pl_group_people($otro, 'g1');

        $this->assertSame(
            array_column($conEnlaces['participants'], 'id'),
            array_column($sinEnlaces['participants'], 'id')
        );
        $this->assertSame(
            array_column($conEnlaces['monitors'], 'id'),
            array_column($sinEnlaces['monitors'], 'id')
        );
    }

    /**
     * Y CUESTA UNA LLAMADA, NO UNA POR CHAVAL.
     *
     * Era un 1+N: `sticpa_pl_contact_of_relationship()` una vez por persona.
     * Un C1 de doce son doce viajes al CRM, y en móvil eso son seis segundos
     * con la pantalla quieta — el «cambiar de fecha es lentísimo».
     */
    public function testElRespaldoResuelveALaGenteEnUnaSolaConsulta()
    {
        $scp = new FakeSCP();
        $scp->sinEnlaces = true;
        sticpa_pl_group_people($scp, 'g1');

        $porPersona = array_filter($scp->calls, function ($c) {
            return $c === 'stic_Contacts_Relationships:stic_contacts_relationships_contacts';
        });
        $this->assertCount(0, $porPersona, 'ni una llamada por persona');

        $bulk = array_filter($scp->calls, function ($c) {
            return $c === 'getRecordsModule:Contacts';
        });
        $this->assertLessThanOrEqual(1, count($bulk), 'los contactos se piden juntos');
    }

    /** El respaldo tambien pinta la pantalla de marcado entera. */
    public function testSinEnlacesLaPantallaDeMarcadoPintaALaGente()
    {
        $this->scp->sinEnlaces = true;
        $_REQUEST = array('grupo' => 'g1');

        $html = $this->render('single_stic_pasar_lista_marcar');

        $this->assertStringContainsString('Solete Vilarroya', $html);
        $this->assertStringNotContainsString('no tiene participantes con relación vigente', $html);
    }

    /**
     * El nombre de la relacion es «Persona - Papel». Partirlo por el ultimo
     * " - " no puede romper un apellido con guion.
     */
    public function testElNombreDeLaRelacionSePartePorElPapel()
    {
        $v = new stdClass();
        $v->name = (object) array('name' => 'name', 'value' => 'Ana Perez-Gil - Participante MIC-COM');
        $person = sticpa_pl_person_from_rel_row($v);
        $this->assertSame('Ana Perez-Gil', $person['name']);
    }

    /**
     * Sin enlaces anidados, "Tu grupo" tambien tiene que salir: si no, la
     * portada le dice "no tienes ningun grupo asignado" a un monitor con su
     * relacion vigente, y el atajo del sabado desaparece.
     */
    public function testSinEnlacesMisGruposSigueSaliendo()
    {
        $this->scp->sinEnlaces = true;
        $_SESSION['scp_user_id'] = 'm1';

        $this->assertSame(array('g1'), sticpa_pl_my_groups($this->scp));
    }

    /** Y la portada pinta el atajo en vez del aviso de que no hay grupo. */
    public function testSinEnlacesLaPortadaPintaElAtajo()
    {
        $this->scp->sinEnlaces = true;
        $_SESSION['scp_user_id'] = 'm1';

        $html = $this->render('single_stic_pasar_lista');

        $this->assertStringNotContainsString('No tienes ningún grupo asignado', $html);
    }

    // -----------------------------------------------------------------------
    // El guardado
    // -----------------------------------------------------------------------

    /**
     * Guardar SIN marcar a nadie no escribe nada. Antes escribia la lista con
     * "0 vinieron, 0 ausencias": una afirmacion falsa en el CRM —«esta pasada y
     * no vino nadie»— provocada por un roce en el boton.
     */
    public function testGuardarSinMarcasNoEscribeNada()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => '{}',
        );

        $html = $this->render('single_stic_pasar_lista_marcar');

        $listas = array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'LIS_listas';
        });
        $this->assertCount(0, $listas, 'no se puede escribir una lista sin marcas');
        $this->assertStringContainsString('No has marcado a nadie', $html);
    }

    /** Con una marca si se escribe, y con el recuento de verdad. */
    public function testGuardarConUnaMarcaEscribeLaLista()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'save',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => '{"c1":"yes"}',
        );

        $this->render('single_stic_pasar_lista_marcar');

        $listas = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'LIS_listas';
        }));
        $this->assertCount(1, $listas);
        $this->assertSame(1, $listas[0]['data']['n_asistieron']);
        $this->assertSame(0, $listas[0]['data']['n_faltaron']);
        $this->assertSame('pasada', $listas[0]['data']['estado']);
    }

    /**
     * «Sin registro» SI escribe con cero marcas: ahi el cero es la afirmacion
     * («no hubo sesion»), no un descuido.
     */
    public function testSinRegistroEscribeOmitidaConCero()
    {
        $_REQUEST = array('grupo' => 'g1');
        $_POST = array(
            'pl_action' => 'skip',
            'pl_nonce' => wp_create_nonce('pl_save_g1'),
            'pl_marks' => '{}',
        );

        $this->render('single_stic_pasar_lista_marcar');

        $listas = array_values(array_filter($this->scp->writes, function ($w) {
            return $w['module'] === 'LIS_listas';
        }));
        $this->assertCount(1, $listas);
        $this->assertSame('omitida', $listas[0]['data']['estado']);
    }

    // ---- Mis grupos ------------------------------------------------------

    /**
     * El índice por grupos: los tuyos primero, con su etiqueta, y los demás por
     * etapa. Es lo que se pidió y lo que se busca casi siempre.
     */
    public function test_mis_grupos_pone_tus_grupos_primero()
    {
        $html = $this->render('single_stic_mis_grupos');

        $this->assertStringContainsString('Tus grupos', $html);
        $this->assertStringContainsString('Tu grupo', $html);
        // g1 es el de David, que es quien está en sesión.
        $this->assertStringContainsString('Los Peques', $html);
        // Y va DELANTE de los demás, no en su sección de etapa.
        $this->assertLessThan(
            strpos($html, 'Los Micos'),
            strpos($html, 'Los Peques'),
            'Tu grupo tiene que ir el primero de la pantalla.'
        );
        // Enlaza dentro de «Mis grupos», no a marcar: aquí no se marca nada.
        $this->assertStringContainsString('internalpage=single_stic_mis_grupos', $html);
        $this->assertStringNotContainsString('single_stic_pasar_lista_marcar', $html);
    }

    /** Los recuentos que se pidieron, contados de la gente que hay de verdad. */
    public function test_mis_grupos_enseña_los_recuentos_de_cada_grupo()
    {
        $html = $this->render('single_stic_mis_grupos');

        // g1 tiene tres participantes vigentes (c1, c2 y la adulta c3) y un
        // monitor (m1). Se cuentan del mapa, no del recuento nocturno.
        $this->assertStringContainsString('3 chavales', $html);
        $this->assertStringContainsString('1 monitor', $html);
    }

    /**
     * EL CERO INVENTADO. Con el mapa de la delegación inservible, un grupo no
     * puede decir «0 chavales»: se lee como un dato y es un fallo. Sale el
     * recuento que dejó el Guardián, o no sale nada.
     */
    public function test_mis_grupos_no_inventa_un_cero_cuando_el_mapa_falla()
    {
        $this->scp->sinEnlaces = true;
        $html = $this->render('single_stic_mis_grupos');

        $this->assertStringNotContainsString('0 chavales', $html);
        // g2 lleva `ajmcm_n_participantes_c = 10` con recuento del 1/9, que a
        // 15/11 ya no es fresco; g3 lo tiene del 14/11, o sea de anoche.
        $this->assertStringContainsString('9 chavales', $html);
    }

    /** La ficha de un grupo: su gente, en dos secciones y sin marcar nada. */
    public function test_mis_grupos_de_un_grupo_lista_a_su_gente()
    {
        $_REQUEST = array('grupo' => 'g1');
        $html = $this->render('single_stic_mis_grupos');

        $this->assertStringContainsString('Solete Vilarroya', $html);
        $this->assertStringContainsString('Jaume Pascual', $html);
        $this->assertStringContainsString('Monitores', $html);
        $this->assertStringContainsString('Participantes', $html);
        // Filas que son ENLACES a la ficha, no botones de marcar.
        $this->assertStringContainsString('pl-rowlink', $html);
        $this->assertStringContainsString('single_stic_pasar_lista_ficha', $html);
        $this->assertStringNotContainsString('pl-mark', $html);
    }

    /** La foto, que es lo que se pidió: en el avatar y por el endpoint propio. */
    public function test_mis_grupos_pide_la_foto_de_cada_persona()
    {
        $_REQUEST = array('grupo' => 'g1');
        $html = $this->render('single_stic_mis_grupos');

        $this->assertStringContainsString('action=stic_pl_photo', $html);
        $this->assertStringContainsString('persona=c1', $html);
        // Y las iniciales siguen debajo: si no hay foto, no queda un hueco.
        $this->assertStringContainsString('pl-avatar', $html);
        $this->assertStringContainsString('SV', $html);
    }

    /** La vista A-Z: toda la gente seguida y ordenada por apellido. */
    public function test_mis_grupos_az_ordena_por_apellido_y_no_repite()
    {
        $_REQUEST = array('ver' => 'az');
        $html = $this->render('single_stic_mis_grupos');

        // Adulta, Pascual, Vilarroya: por apellido.
        $this->assertLessThan(strpos($html, 'Jaume Pascual'), strpos($html, 'Marta Adulta'));
        $this->assertLessThan(strpos($html, 'Solete Vilarroya'), strpos($html, 'Jaume Pascual'));
        // Una sola ficha por persona, aunque tenga dos relaciones vigentes.
        $this->assertSame(1, substr_count($html, 'Solete Vilarroya'));
        // Los que no están en ningún grupo de Pasar Lista no salen aquí: esta
        // pantalla es «mis grupos», y para esos está el resumen.
        $this->assertStringNotContainsString('Sol Messeguer', $html);
    }

    /** La vista por curso, que cruza los grupos: era lo que faltaba. */
    public function test_mis_grupos_por_curso_agrupa_cruzando_grupos()
    {
        $_REQUEST = array('ver' => 'cursos');
        $html = $this->render('single_stic_mis_grupos');

        // g1 es «1º ESO» en el doble: su gente sale bajo ese título, junta,
        // aunque estuvieran repartidos en varios grupos.
        $this->assertStringContainsString('1º ESO', $html);
        $this->assertStringContainsString('Solete Vilarroya', $html);
        // Y el título lleva su recuento.
        $this->assertStringContainsString('participantes', $html);
    }

    /** El buscador: solo se pinta una vez y con su mensaje de «nada coincide». */
    public function test_mis_grupos_lleva_buscador()
    {
        $html = $this->render('single_stic_mis_grupos');

        $this->assertSame(1, substr_count($html, 'data-pl-filter '));
        $this->assertStringContainsString('data-pl-filter-empty', $html);
    }

    /**
     * Los monitores son de COORDINACIÓN. Un monitor raso que escriba
     * `?quien=monitores` a mano no ve la lista de nadie: se le devuelve a los
     * chavales, que es lo suyo.
     */
    public function test_mis_grupos_monitores_solo_con_alcance_de_coordinacion()
    {
        $_REQUEST = array('quien' => 'monitores');
        $html = $this->render('single_stic_mis_grupos');

        // Sin alcance no hay ni pestaña de monitores.
        $this->assertStringNotContainsString('pl-tabs--quien', $html);
        // Y lo que se pinta son los chavales.
        $this->assertStringContainsString('Tus grupos', $html);
    }

    /** Con alcance, los monitores agrupados por etapa, como se pidió. */
    public function test_mis_grupos_monitores_por_etapa_para_coordinacion()
    {
        $this->scp->coordEtapa = 'COM';
        $_REQUEST = array('quien' => 'monitores');
        $html = $this->render('single_stic_mis_grupos');

        $this->assertStringContainsString('pl-tabs--quien', $html);
        $this->assertStringContainsString('David Soler', $html);
        // Enlaza a la ficha del monitor, no a la del participante.
        $this->assertStringContainsString('single_stic_pasar_lista_monitor&monitor=', $html);
    }

    /** Un modo inventado en la URL no rompe nada: se cae al de por defecto. */
    public function test_mis_grupos_ignora_un_modo_inventado()
    {
        $_REQUEST = array('ver' => '<script>');
        $html = $this->render('single_stic_mis_grupos');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('Tus grupos', $html);
    }
}
