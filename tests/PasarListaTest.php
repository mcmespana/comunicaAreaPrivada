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
        $a = sticpa_pl_attendance($this->sessions(), $marks, $this->saturday(17));

        // 2 de 3, no 3 de 4: la cuarta sesión aún no ha pasado.
        $this->assertSame(2, $a['attended']);
        $this->assertSame(3, $a['elapsed']);
        $this->assertSame(67, $a['pct']);
        $this->assertStringContainsString('3 sesiones', $a['text']);
    }

    /** "Parcial" cuenta como haber venido, igual que en el CRM. */
    public function test_parcial_cuenta_como_asistencia()
    {
        $marks = array('s1' => 'partial', 's2' => 'partial', 's3' => 'partial');
        $a = sticpa_pl_attendance($this->sessions(), $marks, $this->saturday(17));
        $this->assertSame(3, $a['attended']);
        $this->assertSame(100, $a['pct']);
    }

    /** Las horas también se cuentan hasta hoy: 3 sesiones × 1,5 h. */
    public function test_horas_hasta_hoy()
    {
        $marks = array('s1' => 'yes', 's2' => 'yes', 's3' => 'yes');
        $a = sticpa_pl_attendance($this->sessions(), $marks, $this->saturday(17));
        $this->assertSame(4.5, $a['hours']);
        $this->assertSame(4.5, $a['hours_total']);
    }

    /** Sin sesiones celebradas no se enseña un 0 % acusador. */
    public function test_antes_de_la_primera_sesion_no_hay_porcentaje()
    {
        $a = sticpa_pl_attendance($this->sessions(), array(), mktime(12, 0, 0, 10, 1, 2025));
        $this->assertSame(0, $a['elapsed']);
        $this->assertStringNotContainsString('%', $a['text']);
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
}
