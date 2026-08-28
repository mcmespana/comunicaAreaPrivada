<?php

use PHPUnit\Framework\TestCase;

/**
 * La lógica de Pasar Lista que no habla con el CRM (inc/stic-pasar-lista.php).
 *
 * Por qué está cubierta: son las cuatro decisiones que, si fallan, producen un
 * dato FALSO en el CRM o una acusación injusta a un chaval.
 *
 *   · qué sesión se ofrece   → marcar en la sesión equivocada corrompe el curso
 *   · el denominador         → un 82 % sobre el curso entero es mentira en febrero
 *   · las ausencias seguidas → el aviso de tres faltas señala a una persona
 *   · el nombre del grupo    → "C1 · C1" es el bug tonto que se ve mil veces
 */
final class PasarListaTest extends TestCase
{
    /** Un sábado del curso: 15 de noviembre de 2025, sesión de 16:30 a 18:00. */
    private function saturday($hour, $min = 0)
    {
        return mktime($hour, $min, 0, 11, 15, 2025);
    }

    private function sessions()
    {
        return array(
            array('id' => 's1', 'start' => mktime(16, 30, 0, 11, 1, 2025), 'end' => mktime(18, 0, 0, 11, 1, 2025)),
            array('id' => 's2', 'start' => mktime(16, 30, 0, 11, 8, 2025), 'end' => mktime(18, 0, 0, 11, 8, 2025)),
            array('id' => 's3', 'start' => mktime(16, 30, 0, 11, 15, 2025), 'end' => mktime(18, 0, 0, 11, 15, 2025)),
            array('id' => 's4', 'start' => mktime(16, 30, 0, 11, 22, 2025), 'end' => mktime(18, 0, 0, 11, 22, 2025)),
        );
    }

    // ---- El curso ---------------------------------------------------------

    /** Septiembre ya es curso nuevo; agosto sigue siendo el anterior. */
    public function test_curso_arranca_en_septiembre()
    {
        $sept = sticpa_pl_course_for(mktime(12, 0, 0, 9, 1, 2025));
        $this->assertSame('2025-2026', $sept['label']);

        $agosto = sticpa_pl_course_for(mktime(12, 0, 0, 8, 31, 2026));
        $this->assertSame('2025-2026', $agosto['label']);

        $febrero = sticpa_pl_course_for(mktime(12, 0, 0, 2, 10, 2026));
        $this->assertSame('2025-2026', $febrero['label']);
    }

    // ---- Qué sesión se ofrece --------------------------------------------

    /** Sábado por la mañana: la de hoy, avisando de que aún no ha empezado. */
    public function test_sabado_por_la_manana_ofrece_la_de_hoy_sin_empezar()
    {
        $pick = sticpa_pl_pick_session($this->sessions(), $this->saturday(10));
        $this->assertSame('s3', $pick['session']['id']);
        $this->assertSame('today_before', $pick['why']);
        $this->assertSame(0, $pick['days']);
    }

    /** Durante la sesión, sin aviso: es el caso normal. */
    public function test_durante_la_sesion()
    {
        $pick = sticpa_pl_pick_session($this->sessions(), $this->saturday(17));
        $this->assertSame('s3', $pick['session']['id']);
        $this->assertSame('today_now', $pick['why']);
    }

    /** Sábado por la noche, ya acabada: sigue siendo la de hoy. */
    public function test_sabado_por_la_noche_sigue_siendo_la_de_hoy()
    {
        $pick = sticpa_pl_pick_session($this->sessions(), $this->saturday(22));
        $this->assertSame('s3', $pick['session']['id']);
        $this->assertSame('today_after', $pick['why']);
    }

    /** Los seis días siguientes: la del sábado pasado, para poder corregirla. */
    public function test_entre_semana_ofrece_la_del_sabado_pasado()
    {
        $miercoles = mktime(19, 0, 0, 11, 19, 2025);
        $pick = sticpa_pl_pick_session($this->sessions(), $miercoles);
        $this->assertSame('s3', $pick['session']['id']);
        $this->assertSame('recent', $pick['why']);
        $this->assertSame(4, $pick['days']);
    }

    /** Antes de que empiece el curso: la primera que viene, no null. */
    public function test_antes_del_curso_ofrece_la_siguiente()
    {
        $pick = sticpa_pl_pick_session($this->sessions(), mktime(12, 0, 0, 10, 20, 2025));
        $this->assertSame('s1', $pick['session']['id']);
        $this->assertSame('future', $pick['why']);
    }

    /** Sin sesiones no se inventa ninguna. */
    public function test_sin_sesiones_devuelve_null()
    {
        $this->assertNull(sticpa_pl_pick_session(array(), $this->saturday(17)));
        $this->assertNull(sticpa_pl_pick_session(array(array('id' => 'x')), $this->saturday(17)));
    }

    // ---- El denominador --------------------------------------------------

    /** En noviembre el curso lleva 3 sesiones, no las 4 del calendario. */
    public function test_solo_cuentan_las_sesiones_ya_celebradas()
    {
        $elapsed = sticpa_pl_elapsed_sessions($this->sessions(), $this->saturday(17));
        $this->assertCount(3, $elapsed);
        $this->assertSame('s1', $elapsed[0]['id']);
        $this->assertSame('s3', $elapsed[2]['id']);
    }

    /** El porcentaje va sobre lo celebrado y se dice con denominador. */
    public function test_porcentaje_sobre_sesiones_celebradas()
    {
        $marks = array('s1' => 'yes', 's2' => 'no_unjustified', 's3' => 'yes', 's4' => 'yes');
        $a = sticpa_pl_att_track($this->sessions(), $marks, $this->saturday(17));

        // 2 de 3, no 3 de 4: la cuarta sesión aún no ha pasado.
        $this->assertSame(2, $a['attended']);
        $this->assertSame(3, $a['elapsed']);
        $this->assertSame(3, $a['counted']);
        $this->assertSame(67, $a['pct']);
    }

    /** "Parcial" cuenta como haber venido, igual que en el CRM. */
    public function test_parcial_cuenta_como_asistencia()
    {
        $marks = array('s1' => 'partial', 's2' => 'partial', 's3' => 'partial');
        $a = sticpa_pl_att_track($this->sessions(), $marks, $this->saturday(17));
        $this->assertSame(3, $a['attended']);
        $this->assertSame(100, $a['pct']);
    }

    /**
     * Las horas siguen la misma regla que el porcentaje: solo lo marcado.
     *
     * Tres sesiones de 1,5 h marcadas = 4,5 h. Con la cuarta sin marcar (y sin
     * celebrar), ni suma ni resta.
     */
    public function test_horas_hasta_hoy()
    {
        $marks = array('s1' => 'yes', 's2' => 'yes', 's3' => 'yes');
        $a = sticpa_pl_att_track($this->sessions(), $marks, $this->saturday(17));
        $this->assertSame(4.5, $a['hours']);
        $this->assertSame(4.5, $a['hours_total']);

        // Y si una de las tres no está marcada, sale del total: «3 h de 3 h» y
        // no «3 h de 4,5 h», que insinuaría una hora y media perdida.
        $b = sticpa_pl_att_track($this->sessions(), array('s1' => 'yes', 's3' => 'yes'), $this->saturday(17));
        $this->assertSame(3.0, $b['hours']);
        $this->assertSame(3.0, $b['hours_total']);
    }

    /** Sin sesiones celebradas no se enseña un 0 % acusador. */
    public function test_antes_de_la_primera_sesion_no_hay_porcentaje()
    {
        $a = sticpa_pl_att_track($this->sessions(), array(), mktime(12, 0, 0, 10, 1, 2025));
        $this->assertSame(0, $a['elapsed']);
        $this->assertSame(-1, $a['pct'], 'no se sabe, que no es cero');
    }

    // ---- Ausencias seguidas ---------------------------------------------

    /** Tres seguidas al final: se avisa. */
    public function test_tres_ausencias_seguidas()
    {
        $marks = array('s1' => 'no_unjustified', 's2' => 'no_justified', 's3' => 'no_unjustified');
        $this->assertSame(3, sticpa_pl_absence_streak($this->sessions(), $marks, $this->saturday(19)));
    }

    /** Tres repartidas NO son tres seguidas: no se avisa. */
    public function test_ausencias_sueltas_no_son_racha()
    {
        $marks = array('s1' => 'no_unjustified', 's2' => 'yes', 's3' => 'no_unjustified');
        $this->assertSame(1, sticpa_pl_absence_streak($this->sessions(), $marks, $this->saturday(19)));
    }

    /**
     * Un hueco en los datos CORTA la racha en vez de sumar.
     * Es la propiedad importante: no sabemos si vino, y el aviso señala a una
     * persona de verdad. Sin marcar nunca se cuenta como falta.
     */
    public function test_sin_marcar_corta_la_racha()
    {
        $marks = array('s1' => 'no_unjustified', 's2' => 'no_unjustified'); // s3 sin marcar
        $this->assertSame(0, sticpa_pl_absence_streak($this->sessions(), $marks, $this->saturday(19)));
    }

    // ---- El ciclo del toque ---------------------------------------------

    /** Sin marcar → vino → no vino → sin marcar, y nada más. */
    public function test_ciclo_de_tres_estados()
    {
        $this->assertSame('yes', sticpa_pl_next_state(''));
        $this->assertSame('no_unjustified', sticpa_pl_next_state('yes'));
        $this->assertSame('', sticpa_pl_next_state('no_unjustified'));
    }

    /** Desde un estado del gesto largo, el toque lleva a "vino". */
    public function test_desde_parcial_el_toque_va_a_vino()
    {
        $this->assertSame('yes', sticpa_pl_next_state('partial'));
        $this->assertSame('yes', sticpa_pl_next_state('no_justified'));
        $this->assertSame('yes', sticpa_pl_next_state('valor_raro_del_crm'));
    }

    /** Un valor que no conocemos no se pinta: se trata como sin marcar. */
    public function test_estados_desconocidos_no_se_aceptan()
    {
        $this->assertTrue(sticpa_pl_is_state('yes'));
        $this->assertTrue(sticpa_pl_is_state('no_justified'));
        $this->assertFalse(sticpa_pl_is_state(''));
        $this->assertFalse(sticpa_pl_is_state('zzz_valor_inventado'));
        $this->assertFalse(sticpa_pl_is_state(null));
    }

    // ---- El nombre del grupo -------------------------------------------

    /** Código y nombre distintos: los dos. */
    public function test_codigo_y_nombre()
    {
        $l = sticpa_pl_group_label('C1', 'Los Peques');
        $this->assertSame('C1', $l['code']);
        $this->assertSame('Los Peques', $l['name']);
    }

    /** Iguales: solo uno. Nunca "C1 · C1". */
    public function test_codigo_igual_al_nombre_no_se_repite()
    {
        $l = sticpa_pl_group_label('C1', 'C1');
        $this->assertSame('C1', $l['code']);
        $this->assertSame('', $l['name']);

        // Y con mayúsculas o espacios de más, que es como está el CRM de verdad.
        $l2 = sticpa_pl_group_label('C1', ' c1 ');
        $this->assertSame('', $l2['name']);
    }

    /** Sin código, el nombre hace de identificador. */
    public function test_sin_codigo_manda_el_nombre()
    {
        $l = sticpa_pl_group_label('', 'Grupo de los jueves');
        $this->assertSame('Grupo de los jueves', $l['code']);
        $this->assertSame('', $l['name']);
    }

    /** Sin nombre, solo el código. */
    public function test_sin_nombre_solo_codigo()
    {
        $l = sticpa_pl_group_label('M4', '');
        $this->assertSame('M4', $l['code']);
        $this->assertSame('', $l['name']);
    }

    // ---- Coordinación y monitores ---------------------------------------

    /** En monitores el ciclo es verde <-> rojo: no existe el "sin marcar". */
    public function test_el_ciclo_de_monitores_es_de_dos_estados()
    {
        $this->assertSame('no_unjustified', sticpa_pl_next_state_monitor('yes'));
        $this->assertSame('yes', sticpa_pl_next_state_monitor('no_unjustified'));
        // Y desde cualquier otro estado se va a la falta, no al vacío.
        $this->assertSame('no_unjustified', sticpa_pl_next_state_monitor(''));
        $this->assertSame('no_unjustified', sticpa_pl_next_state_monitor('partial'));
    }

    /** Sin etapa ni segmento se coordina TODO: es quien mira el conjunto. */
    public function test_alcance_vacio_es_toda_la_delegacion()
    {
        $scope = array('etapa' => '', 'segmento' => '');
        $this->assertTrue(sticpa_pl_scope_matches($scope, array('etapa' => 'MIC', 'segmento' => '')));
        $this->assertTrue(sticpa_pl_scope_matches($scope, array('etapa' => 'COM', 'segmento' => 'com_3')));
    }

    /** Con etapa, solo esa etapa. */
    public function test_alcance_por_etapa()
    {
        $scope = array('etapa' => 'COM', 'segmento' => '');
        $this->assertTrue(sticpa_pl_scope_matches($scope, array('etapa' => 'COM', 'segmento' => 'com_1')));
        $this->assertFalse(sticpa_pl_scope_matches($scope, array('etapa' => 'MIC', 'segmento' => '')));
    }

    /**
     * Con segmento, un grupo SIN segmento no se cuela.
     * Es la propiedad importante: si se colara, quien coordina COM II vería
     * grupos que no le tocan y los daría por suyos.
     */
    public function test_alcance_por_segmento_no_cuela_grupos_sin_segmento()
    {
        $scope = array('etapa' => 'COM', 'segmento' => 'com_2');
        $this->assertTrue(sticpa_pl_scope_matches($scope, array('etapa' => 'COM', 'segmento' => 'com_2')));
        $this->assertFalse(sticpa_pl_scope_matches($scope, array('etapa' => 'COM', 'segmento' => 'com_1')));
        $this->assertFalse(sticpa_pl_scope_matches($scope, array('etapa' => 'COM', 'segmento' => '')));
    }

    /** Los tres estados del certificado de delitos sexuales. */
    public function test_estado_del_certificado_de_delitos()
    {
        // Automático: autorizó al MCM a pedirlo cada año.
        $this->assertSame('auto', sticpa_pl_ds_state(array('ajmcm_aut_del_sex_c' => '1')));
        // A mano, con archivo archivado.
        $this->assertSame('uploaded', sticpa_pl_ds_state(array('ajmcm_aut_del_sex_c' => '0', 'ajmcm_cert_del_sex_c' => '1')));
        // Ni una cosa ni la otra: falta, y es obligatorio por ley.
        $this->assertSame('missing', sticpa_pl_ds_state(array()));
        $this->assertSame('missing', sticpa_pl_ds_state(array('ajmcm_aut_del_sex_c' => '0', 'ajmcm_cert_del_sex_c' => '0')));
    }

    /**
     * Las titulaciones: solo las que tiene, y avisando del descuadre de
     * "titulado pero sin archivo", que es el que deja a nadie sabiendo si falta
     * el papel o falta el curso.
     */
    public function test_titulaciones_solo_las_que_tiene_y_avisa_del_archivo()
    {
        // Los valores son los reales de la instancia.
        $data = array(
            'ajmcm_premonitores1_c' => 'finalizado',
            'ajmcm_premonitores2_c' => 'finalizado',
            'ajmcm_premonitores_year_c' => '2012',
            'ajmcm_mat_c' => 'titulado',
            'ajmcm_mat_year_c' => '2013',
            'ajmcm_mat_file_c' => '1',
            'ajmcm_dat_c' => 'titulado',
            'ajmcm_dat_year_c' => '2021 - EADB',
            'ajmcm_dat_file_c' => '0',        // titulado y SIN archivo
            'ajmcm_fa_c' => 'no',
            'ajmcm_alimentos_c' => '1',
        );
        $t = sticpa_pl_titulaciones($data);
        $labels = array_map(function ($r) { return $r['label']; }, $t);

        // FA no está: dice 'no'.
        $this->assertNotContains('FA', $labels);
        $this->assertContains('MAT', $labels);
        $this->assertContains('DAT', $labels);
        $this->assertContains('Premonitores I', $labels);
        $this->assertContains('Manipulación de alimentos', $labels);

        $byLabel = array();
        foreach ($t as $row) { $byLabel[$row['label']] = $row; }

        $this->assertFalse($byLabel['MAT']['gap']);
        $this->assertTrue($byLabel['DAT']['gap'], 'DAT dice titulado y no hay archivo');
        // El año del DAT es texto libre en el CRM y se enseña tal cual.
        $this->assertSame('2021 - EADB', $byLabel['DAT']['year']);
    }

    /** Sin ninguna titulación, la lista está vacía y no se pinta la sección. */
    public function test_titulaciones_vacias()
    {
        $this->assertSame(array(), sticpa_pl_titulaciones(array('ajmcm_mat_c' => 'no')));
        $this->assertSame(array(), sticpa_pl_titulaciones(array()));
    }

    /** "Monitor/a desde" es una fecha en el CRM y solo se enseña el año. */
    public function test_monitor_desde_solo_el_ano()
    {
        $this->assertSame('2012', sticpa_pl_monitor_since('2012-01-01'));
        $this->assertSame('', sticpa_pl_monitor_since(''));
        $this->assertSame('', sticpa_pl_monitor_since('vete a saber'));
    }

    // ---- Seguimientos: quién ve qué -------------------------------------
    //
    // Es la parte más delicada del sistema: aquí un filtro mal puesto no enseña
    // un dato de más, enseña a una persona lo que otra escribió sobre ella.

    /** Un monitor sin papeles no ve NADA, ni suyo ni de nadie. */
    public function test_un_monitor_no_ve_ningun_seguimiento()
    {
        $roles = sticpa_pl_seg_roles(false, false);
        $this->assertSame(array(), $roles);
        $this->assertSame(array(), sticpa_pl_seg_readable($roles));
        $this->assertSame(array(), sticpa_pl_seg_writable($roles));
    }

    /** Coordinación ve incidencias y valoraciones, NO acompañamiento. */
    public function test_coordinacion_no_ve_acompanamiento()
    {
        $roles = sticpa_pl_seg_roles(true, false);
        $readable = sticpa_pl_seg_readable($roles);

        $this->assertContains('incidencia', $readable);
        $this->assertContains('valoracion', $readable);
        $this->assertNotContains('acompanamiento', $readable, 'coordinar no da acceso a acompañamiento');

        // Y tampoco puede escribirlo.
        $this->assertNotContains('acompanamiento', sticpa_pl_seg_writable($roles));
    }

    /** Acompañamiento lo ve todo, y solo escribe lo suyo. */
    public function test_acompanamiento_ve_todo_y_escribe_lo_suyo()
    {
        $roles = sticpa_pl_seg_roles(false, true);
        $this->assertContains('acompanamiento', sticpa_pl_seg_readable($roles));
        $this->assertContains('incidencia', sticpa_pl_seg_readable($roles));

        $writable = sticpa_pl_seg_writable($roles);
        $this->assertSame(array('acompanamiento'), $writable);
    }

    /** Las dos cosas a la vez es la unión, no una jerarquía. */
    public function test_coordinar_y_acompanar_es_la_union()
    {
        $roles = sticpa_pl_seg_roles(true, true);
        $writable = sticpa_pl_seg_writable($roles);
        $this->assertContains('incidencia', $writable);
        $this->assertContains('acompanamiento', $writable);
        $this->assertCount(3, sticpa_pl_seg_readable($roles));
    }

    /** El filtro deja pasar solo los tipos permitidos. */
    public function test_el_filtro_quita_lo_que_no_toca()
    {
        $items = array(
            array('tipo' => 'incidencia'),
            array('tipo' => 'valoracion'),
            array('tipo' => 'acompanamiento'),
        );
        $coord = sticpa_pl_seg_filter($items, sticpa_pl_seg_roles(true, false));
        $this->assertCount(2, $coord);
        foreach ($coord as $it) {
            $this->assertNotSame('acompanamiento', $it['tipo']);
        }

        $acomp = sticpa_pl_seg_filter($items, sticpa_pl_seg_roles(false, true));
        $this->assertCount(3, $acomp);
    }

    /**
     * SOBRE UNO MISMO, NADA. Ni siendo coordinador.
     * Es la regla de encuadre: una valoración escrita para hablarla en persona
     * deja de servir si se lee antes en una pantalla.
     */
    public function test_nadie_ve_seguimientos_de_si_mismo()
    {
        $items = array(array('tipo' => 'incidencia'), array('tipo' => 'valoracion'));

        // Coordinador mirando a otro: ve.
        $this->assertCount(2, sticpa_pl_seg_filter($items, array('coordinacion'), 'yo', 'otro'));
        // Coordinador mirándose a sí mismo: nada.
        $this->assertSame(array(), sticpa_pl_seg_filter($items, array('coordinacion'), 'yo', 'yo'));
        // Y quien acompaña, tampoco.
        $this->assertSame(array(), sticpa_pl_seg_filter($items, array('coordinacion', 'acompanamiento'), 'yo', 'yo'));
    }

    /** Un tipo que no conocemos NO se enseña: el defecto es ocultar. */
    public function test_un_tipo_desconocido_no_se_enseña()
    {
        $items = array(
            array('tipo' => 'incidencia'),
            array('tipo' => 'zzz_lo_que_sea'),
            array('tipo' => ''),
        );
        $out = sticpa_pl_seg_filter($items, sticpa_pl_seg_roles(true, true));
        $this->assertCount(1, $out);
        $this->assertSame('incidencia', $out[0]['tipo']);
    }

    /** Los trimestres van por curso escolar, no por año natural. */
    public function test_trimestres_del_curso()
    {
        $this->assertSame(1, sticpa_pl_seg_trimestre(mktime(12, 0, 0, 10, 15, 2025)));
        $this->assertSame(1, sticpa_pl_seg_trimestre(mktime(12, 0, 0, 12, 20, 2025)));
        $this->assertSame(2, sticpa_pl_seg_trimestre(mktime(12, 0, 0, 2, 10, 2026)));
        $this->assertSame(3, sticpa_pl_seg_trimestre(mktime(12, 0, 0, 5, 10, 2026)));
    }

    // ---- La tira del resumen -------------------------------------------

    /** Pasada, omitida, y el hueco que solo es hueco si la sesión ya pasó. */
    public function test_marcas_de_la_tira_de_listas()
    {
        $ayer = mktime(16, 30, 0, 11, 15, 2025);
        $manana = mktime(16, 30, 0, 11, 22, 2025);
        $hoy = mktime(12, 0, 0, 11, 19, 2025);

        $this->assertSame('ok', sticpa_pl_list_mark('pasada', $ayer, $hoy));
        $this->assertSame('skip', sticpa_pl_list_mark('omitida', $ayer, $hoy));
        $this->assertSame('gap', sticpa_pl_list_mark('', $ayer, $hoy));
        // Una sesión que viene no está "pendiente de pasar".
        $this->assertSame('future', sticpa_pl_list_mark('', $manana, $hoy));
    }

    // -----------------------------------------------------------------------
    // Orden de los grupos
    // -----------------------------------------------------------------------

    /**
     * El sintoma real: en Castellon el arbol sacaba M4.3, M5.3, M6.2, M4.1...
     * porque nadie ordenaba nada.
     */
    public function testLosGruposSeOrdenanPorCodigo()
    {
        $groups = array(
            'g1' => array('code' => 'M5.3', 'name' => 'MIC 5.3'),
            'g2' => array('code' => 'M4.1', 'name' => 'MIC 4.1'),
            'g3' => array('code' => 'M6.2', 'name' => 'MIC 6.2'),
            'g4' => array('code' => 'M4.3', 'name' => 'MIC 4.3'),
        );
        uasort($groups, 'sticpa_pl_cmp_group');

        $this->assertSame(
            array('M4.1', 'M4.3', 'M5.3', 'M6.2'),
            array_column(array_values($groups), 'code')
        );
        // Y se conserva la clave: las pantallas navegan por id.
        $this->assertSame(array('g2', 'g4', 'g1', 'g3'), array_keys($groups));
    }

    /** Natural, no alfabetico: M4.10 va DESPUES de M4.2, no antes. */
    public function testElOrdenEsNaturalConLosNumeros()
    {
        $groups = array(
            array('code' => 'M4.10', 'name' => ''),
            array('code' => 'M4.2', 'name' => ''),
            array('code' => 'M4.1', 'name' => ''),
        );
        usort($groups, 'sticpa_pl_cmp_group');
        $this->assertSame(array('M4.1', 'M4.2', 'M4.10'), array_column($groups, 'code'));
    }

    /** Sin codigo se usa el nombre, y sin ninguno de los dos se va al final. */
    public function testGrupoSinCodigoSeOrdenaPorNombreYElVacioAlFinal()
    {
        $groups = array(
            array('code' => '', 'name' => ''),
            array('code' => 'C2', 'name' => 'Los Mayores'),
            array('code' => '', 'name' => 'Aula abierta'),
        );
        usort($groups, 'sticpa_pl_cmp_group');
        $this->assertSame('Aula abierta', $groups[0]['name']);
        $this->assertSame('C2', $groups[1]['code']);
        $this->assertSame('', $groups[2]['name']);
    }

    // -----------------------------------------------------------------------
    // El recuento nocturno y la regla de callarse si es viejo
    // -----------------------------------------------------------------------

    public function testUnRecuentoDeAnocheEsFresco()
    {
        $now = strtotime('2026-02-10 12:00:00');
        $this->assertTrue(sticpa_pl_recuento_fresco('2026-02-10 01:30:00', $now));
        $this->assertTrue(sticpa_pl_recuento_fresco('2026-02-08 01:30:00', $now));
    }

    /** PASAR-LISTA-RECUENTOS.md 6: si el dato es viejo, la pantalla se calla. */
    public function testUnRecuentoViejoNoEsFresco()
    {
        $now = strtotime('2026-02-10 12:00:00');
        $this->assertFalse(sticpa_pl_recuento_fresco('2026-01-10 01:30:00', $now));
        $this->assertFalse(sticpa_pl_recuento_fresco('', $now));
        $this->assertFalse(sticpa_pl_recuento_fresco('no es una fecha', $now));
    }

    /** Una fecha en el futuro es un reloj mal puesto: tampoco es de fiar. */
    public function testUnRecuentoDelFuturoNoEsFresco()
    {
        $now = strtotime('2026-02-10 12:00:00');
        $this->assertFalse(sticpa_pl_recuento_fresco('2026-03-01 01:30:00', $now));
    }

    /** La linea del artboard: monitores, curso y participantes. Sin la etapa. */
    public function testLaLineaDelGrupoLlevaMonitoresCursoYRecuento()
    {
        $now = strtotime('2026-02-10 12:00:00');
        $meta = sticpa_pl_group_meta(array(
            'monitores' => 'Mercedes, Jaime',
            'cursos' => '1º ESO',
            'n_participantes' => 11,
            'recuento_al' => '2026-02-10 01:30:00',
            'level' => 'com',
        ), $now);

        $this->assertSame(array('Mercedes, Jaime', '1º ESO', '11 participantes'), $meta);
        // La etapa NO va: el arbol ya agrupa por etapa.
        $this->assertStringNotContainsStringIgnoringCase('com', implode(' ', $meta));
    }

    /** Con el recuento viejo, sale todo lo demas y el numero desaparece. */
    public function testConRecuentoViejoLaLineaOmiteElNumero()
    {
        $now = strtotime('2026-02-10 12:00:00');
        $meta = sticpa_pl_group_meta(array(
            'monitores' => 'Mercedes',
            'cursos' => '1º ESO',
            'n_participantes' => 11,
            'recuento_al' => '2025-11-01 01:30:00',
        ), $now);

        $this->assertSame(array('Mercedes', '1º ESO'), $meta);
    }

    /** Un grupo recien creado, sin recuento todavia: ni numero ni error. */
    public function testGrupoSinRecuentoNoRompeLaLinea()
    {
        $this->assertSame(array(), sticpa_pl_group_meta(array()));
        $this->assertSame(
            array('2º ESO'),
            sticpa_pl_group_meta(array('cursos' => '2º ESO', 'n_participantes' => -1, 'recuento_al' => ''))
        );
    }

    /** Cero participantes es un dato, no un hueco: un grupo vacio hay que verlo. */
    public function testCeroParticipantesSeDice()
    {
        $now = strtotime('2026-02-10 12:00:00');
        $meta = sticpa_pl_group_meta(array(
            'cursos' => '1º ESO',
            'n_participantes' => 0,
            'recuento_al' => '2026-02-10 01:30:00',
        ), $now);
        $this->assertSame(array('1º ESO', '0 participantes'), $meta);
    }

    // -----------------------------------------------------------------------
    // El nombre corto de la lista
    // -----------------------------------------------------------------------

    /** Nombre y PRIMER apellido: el segundo no distingue a nadie en un grupo. */
    public function testElNombreDeLaListaLlevaUnSoloApellido()
    {
        $this->assertSame(
            'Solete Vilarroya',
            sticpa_pl_short_name('Solete', 'Vilarroya Messguerr')
        );
    }

    /** Un nombre COMPUESTO se respeta entero: ahi las dos palabras son el nombre. */
    public function testUnNombreCompuestoNoSeRecorta()
    {
        $this->assertSame(
            'Jose Maria Pardo',
            sticpa_pl_short_name('Jose Maria', 'Pardo Aragones')
        );
    }

    /** Con un solo apellido no cambia nada. */
    public function testConUnApellidoSeQuedaIgual()
    {
        $this->assertSame('Marta Adulta', sticpa_pl_short_name('Marta', 'Adulta'));
    }

    /** Sin nombre y apellido separados se parte el completo por las dos primeras. */
    public function testSinCamposSeparadosSePorteElCompleto()
    {
        $this->assertSame('Ana Perez', sticpa_pl_short_name('', '', 'Ana Perez Gil'));
        $this->assertSame('Ana', sticpa_pl_short_name('', '', 'Ana'));
        $this->assertSame('', sticpa_pl_short_name('', '', ''));
    }
    // ---- Seguimiento de monitores: las pistas -----------------------------

    /**
     * UN HUECO NO ES UNA FALTA.
     *
     * Es la regla del bloque entero. Si el sábado que faltó nadie pasó la lista
     * de monitores, ese hueco no es una ausencia suya: es un dato que no
     * existe. Meterlo en el denominador acusa a alguien por un fallo de
     * registro, y encima con un número que parece objetivo.
     */
    public function test_pista_de_asistencia_no_cuenta_los_huecos()
    {
        $marks = array('s1' => 'yes', 's3' => 'no_unjustified');   // s2 sin marcar
        $t = sticpa_pl_att_track($this->sessions(), $marks, $this->saturday(20));

        $this->assertSame(3, $t['elapsed'], 's4 todavía no ha pasado');
        $this->assertSame(1, $t['attended']);
        $this->assertSame(1, $t['missed']);
        $this->assertSame(1, $t['unknown']);
        $this->assertSame(2, $t['counted']);
        $this->assertSame(50, $t['pct'], '1 de 2 marcadas, no 1 de 3');

        // Y un cuadrado por sesión celebrada, en orden y con su estado.
        $this->assertCount(3, $t['squares']);
        $this->assertSame(array('yes', '', 'no_unjustified'), array_column($t['squares'], 'state'));
    }

    /** Sin nada marcado el porcentaje es «no se sabe», no un cero. */
    public function test_pista_de_asistencia_sin_datos_no_es_cero()
    {
        $t = sticpa_pl_att_track($this->sessions(), array(), $this->saturday(20));
        $this->assertSame(-1, $t['pct'], 'un 0 % diría que no vino nunca');
        $this->assertSame(3, $t['unknown']);
    }

    /**
     * La pista de listas distingue QUIÉN la pasó.
     *
     * No es un verde y un rojo: una lista de grupo la puede pasar cualquiera
     * que cubra ese sábado, así que «la pasó otro» es un resultado correcto y
     * tiene su propio color.
     */
    public function test_pista_de_listas_distingue_quien_la_paso()
    {
        $listas = array(
            's1' => array('estado' => 'pasada', 'monitor_id' => 'm1'),
            's2' => array('estado' => 'pasada', 'monitor_id' => 'm7'),
            's3' => array('estado' => 'omitida', 'monitor_id' => 'm1'),
        );
        $t = sticpa_pl_listas_track($this->sessions(), $listas, 'm1', $this->saturday(20));

        $this->assertSame(array('suya', 'otra', 'omitida'), array_column($t['squares'], 'state'));
        $this->assertSame(1, $t['suyas']);
        $this->assertSame(1, $t['otras']);
        $this->assertSame(1, $t['omitidas']);
        $this->assertSame(2, $t['con_lista']);
        // Un sábado omitido NO es una lista que falte: sale del denominador.
        $this->assertSame(2, $t['esperadas']);
    }

    /** Un sábado sin lista ninguna se ve, y cuenta como pendiente. */
    public function test_pista_de_listas_marca_las_que_faltan()
    {
        $t = sticpa_pl_listas_track($this->sessions(), array(), 'm1', $this->saturday(20));
        $this->assertSame(array('sin', 'sin', 'sin'), array_column($t['squares'], 'state'));
        $this->assertSame(0, $t['con_lista']);
        $this->assertSame(3, $t['esperadas']);
    }

    // ---- El curso escolar de una relación ---------------------------------

    /**
     * El CRM no guarda «2024-2025» en ninguna parte de una relación: el campo
     * existe y está vacío en todas. Así que el curso se deduce de las fechas.
     */
    public function test_cursos_de_una_relacion_cerrada()
    {
        $inicio = mktime(0, 0, 0, 9, 1, 2024);
        $fin = mktime(0, 0, 0, 7, 31, 2025);
        $this->assertSame(
            array('2024-2025'),
            sticpa_pl_rel_cursos($inicio, $fin, $this->saturday(12))
        );
    }

    /** Una relación de tres años sale en los tres cursos: los tres estuvo. */
    public function test_cursos_de_una_relacion_larga()
    {
        $inicio = mktime(0, 0, 0, 9, 1, 2023);
        $this->assertSame(
            array('2023-2024', '2024-2025', '2025-2026'),
            sticpa_pl_rel_cursos($inicio, 0, $this->saturday(12))
        );
    }

    /** Sin fechas, el curso de hoy: es lo único que se puede afirmar. */
    public function test_cursos_de_una_relacion_sin_fechas()
    {
        $this->assertSame(
            array('2025-2026'),
            sticpa_pl_rel_cursos(0, 0, $this->saturday(12))
        );
    }

    /** Sin inicio pero con fin, el curso en que se cerró. */
    public function test_cursos_de_una_relacion_solo_con_fin()
    {
        $fin = mktime(0, 0, 0, 6, 30, 2023);
        $this->assertSame(
            array('2022-2023'),
            sticpa_pl_rel_cursos(0, $fin, $this->saturday(12))
        );
    }

    // ---- Los bloques de datos del monitor ---------------------------------

    /** Los multienum de SuiteCRM vienen con acentos circunflejos de adorno. */
    public function test_multienum_quita_los_circunflejos()
    {
        $this->assertSame(
            array('2019_godelleta', '2022_burriana'),
            sticpa_pl_multienum('^2019_godelleta^,^2022_burriana^')
        );
        $this->assertSame(array(), sticpa_pl_multienum(''));
    }

    /**
     * El orden de los bloques ES el diseño, así que se prueba.
     *
     * Lo pidió el propietario con estas palabras: «poniendo más arriba los
     * datos de más interés (por ejemplo el de delitos sexuales pues el primero
     * no hace falta)».
     */
    public function test_bloques_del_monitor_en_orden_de_interes()
    {
        $bloques = sticpa_pl_monitor_bloques(array(
            'ajmcm_aut_del_sex_c' => '1',
            'ajmcm_mat_c' => 'titulado', 'ajmcm_mat_file_c' => '1', 'ajmcm_mat_year_c' => '2013',
            'ajmcm_nivel_com_c' => 'crecimiento',
            'stic_identification_number_c' => '12345678Z',
            'stic_identification_type_c' => 'nif',
        ));
        $this->assertSame(
            array('regla', 'formacion', 'trayectoria', 'personales'),
            array_column($bloques, 'key')
        );
        // Y los datos de padrón, plegados: se miran una vez al año.
        $this->assertTrue($bloques[3]['plegado']);
    }

    /**
     * Un permiso no dado no es un incumplimiento.
     *
     * La cesión de imágenes a «no» es una decisión de la persona. Pintarla como
     * una obligación pendiente convierte un derecho en una deuda.
     */
    public function test_bloque_en_regla_separa_obligaciones_de_permisos()
    {
        $bloques = sticpa_pl_monitor_bloques(array('ajmcm_cesionimagenes_interne_c' => '0'));
        $regla = $bloques[0]['rows'];

        $porEtiqueta = array();
        foreach ($regla as $r) {
            $porEtiqueta[$r['label']] = $r;
        }
        $this->assertTrue($porEtiqueta['Código de conducta']['req'], 'es obligatorio');
        $this->assertFalse($porEtiqueta['Cesión de imágenes']['req'], 'es un permiso');
        $this->assertFalse($porEtiqueta['Cesión de imágenes']['ok']);
    }

    /** Lo que no tiene no se lista: se pregunta qué formación tiene. */
    public function test_formacion_solo_lista_lo_que_tiene()
    {
        $bloques = sticpa_pl_monitor_bloques(array(
            'ajmcm_mat_c' => 'titulado', 'ajmcm_mat_file_c' => '0',
            'ajmcm_dat_c' => 'no',
        ));
        $formacion = null;
        foreach ($bloques as $b) {
            if ($b['key'] === 'formacion') {
                $formacion = $b;
            }
        }
        $this->assertNotNull($formacion);
        $this->assertCount(1, $formacion['rows'], 'el DAT dice no, así que no sale');
        // Titulado pero sin el archivo del título: el descuadre que hay que ver.
        $this->assertSame('sin archivo', $formacion['rows'][0]['warn']);
    }

}
