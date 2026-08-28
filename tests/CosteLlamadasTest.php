<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/PasarListaRenderTest.php';

/**
 * Cuantas llamadas al CRM cuesta cada pantalla.
 *
 * No es un test de exito/fallo estetico: es una MEDIDA. El area privada se usa
 * en una webview con datos moviles un sabado por la tarde, y cada llamada al
 * CRM es un round-trip. Este archivo existe para que el numero no suba sin que
 * nadie se de cuenta.
 */
class CosteLlamadasTest extends TestCase
{
    private $scp;

    protected function setUp(): void
    {
        $GLOBALS['__stic_pl_now'] = mktime(17, 0, 0, 11, 15, 2025);
        $GLOBALS['__stic_transients'] = array();
        $GLOBALS['__stic_options'] = array();
        $GLOBALS['__stic_filters'] = array();
        $_SESSION = array('scp_user_id' => 'm1', 'scp_user_assigned_user_id' => 'deleg-castellon', 'scp_user_contact_name' => 'David Soler');
        $_REQUEST = array();
        $_POST = array();
        $this->scp = new FakeSCP();
        // El modo REALISTA: esta instancia no devuelve enlaces anidados.
    }

    private function render($page)
    {
        $html = '';
        $objSCP = $this->scp;
        $pageSettings = array();
        if (!defined('ABSPATH')) { define('ABSPATH', '/'); }
        require __DIR__ . '/../pages/' . $page . '.php';
        return $html;
    }

    public function testCosteDeCadaPantalla()
    {
        $modos = array('enlaces OK' => false, 'sin enlaces (respaldo)' => true);

        $pantallas = array(
            'single_stic_pasar_lista' => array(),
            'single_stic_pasar_lista_grupos' => array(),
            'single_stic_pasar_lista_marcar' => array('grupo' => 'g1'),
            'single_stic_pasar_lista_resumen' => array(),
            // OJO: los parámetros tienen que ser los DE VERDAD. Esta línea
            // decía `persona` en vez de `participante` y la ficha salía por la
            // puerta de «no se ha indicado ningún participante»: el test medía
            // 0 llamadas de una pantalla que no se pintaba.
            'single_stic_pasar_lista_ficha' => array('participante' => 'c1', 'grupo' => 'g1'),
            // Monitores hace falta AQUÍ: era la pantalla más lenta de todas y
            // nadie le contaba las llamadas.
            'single_stic_pasar_lista_monitores' => array('__coord' => 'COM'),
            // Y la ficha de un monitor, que ahora enseña el seguimiento
            // completo, sus grupos y el histórico: todo lo nuevo sale de
            // cargadores de colección, así que su coste NO puede depender de
            // cuántos grupos ni cuántos cursos tenga detrás.
            'single_stic_pasar_lista_monitor' => array('__coord' => 'COM', 'monitor' => 'm1'),
        );

        $lineas = array();
        foreach ($modos as $etiqueta => $sinEnlaces) {
            $lineas[] = '--- ' . $etiqueta;
            foreach ($pantallas as $page => $req) {
                $this->setUp();
                $this->scp->sinEnlaces = $sinEnlaces;
                if (isset($req['__coord'])) {
                    $this->scp->coordEtapa = $req['__coord'];
                    unset($req['__coord']);
                }
                $_REQUEST = $req;
                $this->render($page);
                $n = count($this->scp->calls);
                // Las TANDAS son lo que se nota: diez llamadas en tres tandas
                // paralelas son tres viajes de ida y vuelta, no diez. Es el
                // numero que hay que mirar cuando alguien dice «va lento».
                $lineas[] = sprintf(
                    '%-34s %3d llamadas en %d tandas (%s) + %d sueltas',
                    str_replace('single_stic_pasar_lista', 'PL', $page),
                    $n,
                    count($this->scp->batches),
                    implode('+', $this->scp->batches) ?: '-',
                    $n - array_sum($this->scp->batches)
                );
                $cuenta = array_count_values($this->scp->calls);
                arsort($cuenta);
                foreach ($cuenta as $q => $veces) {
                    $lineas[] = sprintf('      %2dx %s', $veces, $q);
                }
            }
        }
        fwrite(STDERR, "\n" . implode("\n", $lineas) . "\n");
        $this->assertTrue(true);
    }

    /**
     * TOPES. Aqui si se falla: son los numeros que costo bajar y no pueden
     * subir sin que alguien lo vea.
     *
     * El margen sobre el numero real es de una o dos llamadas, no del doble: un
     * tope holgado no protege de nada. Si un cambio los pasa, o esta justificado
     * y se sube el tope a mano, o es una regresion de rendimiento.
     */
    public function testElCosteNoSePasaDeLosTopes()
    {
        /* Los topes BAJARON el 28/08/2026 al juntar los dos cargadores que
         * preguntaban lo mismo (los eventos de la delegación, y las relaciones
         * de quien está conectado). Se bajan a la vez que se arregla: un tope
         * que se queda holgado deja de proteger nada. */
        // LOS NUMEROS SON LOS DEL MODO REALISTA (sin enlaces anidados), que
        // es el unico que ocurre en esta instancia. Bajaron el 28/08/2026 al
        // matar el 1+N que resolvia a la gente de un grupo una llamada por
        // persona: la portada de 14 a 9, marcar de 13 a 10, la ficha de 16 a
        // 13. El margen es de una llamada, no del doble.
        $topes = array(
            'single_stic_pasar_lista' => array(array(), 10),
            'single_stic_pasar_lista_grupos' => array(array(), 8),
            'single_stic_pasar_lista_marcar' => array(array('grupo' => 'g1'), 11),
            'single_stic_pasar_lista_resumen' => array(array(), 8),
            // La ficha del participante. ANTES NO SE MEDIA: el test la pedia
            // con el parametro equivocado y salia por la puerta de «no se ha
            // indicado ningun participante», o sea 0 llamadas de una pantalla
            // que no se pintaba. Y era la mas lenta de todas: TRECE viajes al
            // CRM en fila, sin agrupar ni uno.
            'single_stic_pasar_lista_ficha' => array(array('participante' => 'c1', 'grupo' => 'g1'), 14),
            // Monitores: el tope importa MÁS que en las demás. Su coste no
            // puede depender de cuántos grupos haya en el CRM (hay ~150, casi
            // todos históricos), y eso es justo lo que pasaba: el respaldo por
            // grupo se disparaba en cada grupo vacío.
            'single_stic_pasar_lista_monitores' => array(array('__coord' => 'COM'), 11),
            // La ficha del monitor. Sube respecto a la versión de antes porque
            // ahora lee TAMBIÉN el evento de reuniones, sus sesiones, sus
            // inscripciones y las asistencias de esa persona a las dos cosas:
            // son datos nuevos en la pantalla, no consultas repetidas. Lo que
            // este tope protege es que el histórico y los grupos sigan
            // costando CERO: si alguien mete un `foreach` con una consulta
            // dentro, salta aquí.
            'single_stic_pasar_lista_monitor' => array(array('__coord' => 'COM', 'monitor' => 'm1'), 15),
        );

        // Viajes de ida y vuelta: ninguna pantalla puede pasar de esto.
        $topeViajes = 8;

        foreach ($topes as $page => $spec) {
            list($req, $tope) = $spec;
            // LOS DOS MODOS, Y EL QUE MANDA ES EL SEGUNDO.
            //
            // El tope solo se comprobaba con los enlaces anidados puestos, que
            // es el modo que esta instancia NO tiene (§3.1). O sea: se medía el
            // caso que no ocurre. Marcar costaba 8 llamadas medidas y 13 de
            // verdad, y por ahí se iba el «cambiar de fecha es lentisimo».
            foreach (array(false, true) as $sinEnlaces) {
                $this->setUp();
                $this->scp->sinEnlaces = $sinEnlaces;
                $r = $req;
                if (isset($r['__coord'])) {
                    $this->scp->coordEtapa = $r['__coord'];
                    unset($r['__coord']);
                }
                $_REQUEST = $r;
                $this->render($page);
                $n = count($this->scp->calls);
                $this->assertLessThanOrEqual(
                    $tope,
                    $n,
                    $page . ' hace ' . $n . ' llamadas al CRM (tope ' . $tope . ')'
                        . ($sinEnlaces ? ' SIN enlaces anidados, que es como responde esta instancia' : '')
                        . '. Cada una cuesta medio segundo largo en movil.'
                );

                // Y LOS VIAJES DE IDA Y VUELTA, que es lo que se NOTA. Diez
                // llamadas en dos tandas paralelas son dos esperas; diez en
                // fila son diez. La ficha del participante hacia TRECE en fila
                // y por eso «va lentisimo» — la del monitor agrupaba, y por eso
                // «la velocidad de monitores va bien».
                $viajes = count($this->scp->batches)
                    + ($n - array_sum($this->scp->batches));
                $this->assertLessThanOrEqual(
                    $topeViajes,
                    $viajes,
                    $page . ' espera ' . $viajes . ' veces al CRM (tope ' . $topeViajes . '). '
                        . 'Lo que se nota no son las llamadas, son las esperas: '
                        . 'lo que no dependa de nada tiene que ir en la misma tanda.'
                );
            }
        }
    }
}
