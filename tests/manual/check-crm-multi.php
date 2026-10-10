<?php
/**
 * Valida las TANDAS EN PARALELO (`SugarRestApiCall::callMany()`, plan 042
 * VEL-6) contra un servidor HTTP real con latencias desiguales.
 *
 * `callMany()` es el transporte de TODAS las lecturas en paralelo del área: si
 * se cuelga o pierde respuestas, se rompe todo. Los tests de PHPUnit lo
 * sustituyen por dobles, así que esto es lo único que lo prueba de verdad.
 * Comprueba:
 *   1. Que todas las respuestas llegan, cada una con SU firma en el memo.
 *   2. Que nunca hay más de 4 en vuelo (ni más de 2 si el filtro
 *      `sticpa_crm_multi_concurrency` dice 2), y que la ventana es deslizante:
 *      con 800/200/200/200/200/200 ms el total es ~800 (en olas de 4, ~1.000).
 *   3. Que un cuelgue (timeout), un cuerpo roto, uno vacío y uno cortado no
 *      cuelgan la tanda ni se llevan por delante a las demás.
 *   4. Que una sesión caducada (error 11) se reintenta como siempre: un login
 *      y cada petición otra vez, con la sesión nueva.
 *   5. Que contra un servidor caído vuelve enseguida, sin bucle.
 *   6. Que los contadores (`$callCount`, `$callLog`) cuentan lo que ha salido.
 *   7. Que no se quedan handles ni conexiones abiertas tras muchas tandas.
 *
 * Ver README.md para lanzarlo (PHP_CLI_SERVER_WORKERS=8 es imprescindible).
 * Con CLASS_FILE se puede apuntar a otra versión de la clase (para comparar).
 */

$URL = getenv('MOCK_URL');
$LOG = getenv('MOCK_LOG') ?: '/tmp/mockcrm.log';
$CLASS = getenv('CLASS_FILE') ?: __DIR__ . '/../../inc/stic-class-6.php';
$TIMEOUT = (int) (getenv('TEST_TIMEOUT') ?: 2);

// --- Stubs mínimos de WordPress ---
function get_locale() { return 'es_ES'; }
$CONCURRENCIA = null;   // null = la de siempre (4)
function apply_filters($tag, $value = null) {
    global $TIMEOUT, $CONCURRENCIA;
    if ($tag === 'sticpa_crm_timeout') { return $TIMEOUT; }
    if ($tag === 'sticpa_crm_multi_concurrency' && $CONCURRENCIA !== null) { return $CONCURRENCIA; }
    return $value;
}
function get_option($k, $d = false) { return $d; }
function getDestinationModule() { return 'Contacts'; }

$_SESSION = array();
// Los error_log() del cliente, aparte: aquí solo se quiere el resultado.
ini_set('error_log', sys_get_temp_dir() . '/check-crm-multi.errors.log');

require $CLASS;

$fails = 0;
function check($label, $ok, $extra = '') {
    global $fails;
    if (!$ok) { $fails++; }
    printf("%s %s%s\n", $ok ? '  ✓' : '  ✗ FALLO', $label, $extra !== '' ? " — $extra" : '');
}

$ref = new ReflectionClass('SugarRestApiCall');
function nuevoCliente($url) {
    global $ref;
    $ctor = $ref->getConstructor();
    $ctor->setAccessible(true);
    $api = $ref->newInstanceWithoutConstructor();
    $ctor->invoke($api, $url, 'usuario-test', 'clave-test', 'Contacts');
    return $api;
}
function firma($method, $params) {
    global $ref;
    $m = $ref->getMethod('signature');
    $m->setAccessible(true);
    return $m->invoke(null, $method, $params);
}
function memo() {
    global $ref;
    $p = $ref->getProperty('memo');
    $p->setAccessible(true);
    return $p->getValue();
}
function vaciarMemo() { SugarRestApiCall::forgetMemo(); }
/** Las entradas de fin del log del servidor, desde la línea $desde. */
function finesDesde($desde) {
    global $LOG;
    clearstatcache();
    $lineas = file_exists($LOG) ? file($LOG, FILE_IGNORE_NEW_LINES) : array();
    $out = array();
    foreach (array_slice($lineas, $desde) as $l) {
        $d = json_decode($l, true);
        if (isset($d['fin'])) { $out[] = $d; }
    }
    return $out;
}
function lineasLog() {
    global $LOG;
    clearstatcache();
    return file_exists($LOG) ? count(file($LOG)) : 0;
}
/** Cuántas peticiones llegó a tener el servidor a la vez. */
function maxSimultaneas(array $fines) {
    $eventos = array();
    foreach ($fines as $f) {
        $eventos[] = array($f['t0'], 1);
        $eventos[] = array($f['t1'], -1);
    }
    usort($eventos, function ($a, $b) {
        return $a[0] == $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0];
    });
    $n = 0; $max = 0;
    foreach ($eventos as $e) { $n += $e[1]; $max = max($max, $n); }
    return $max;
}
function lento($id, $ms, $session) {
    return array('method' => 'lento', 'parameters' => array('session' => $session, 'id' => $id, 'ms' => $ms));
}

$api = nuevoCliente($URL);
$sid = $_SESSION['api_session_id'] ?? null;
if (!is_string($sid) || $sid === '') {
    echo "No hay login contra $URL: ¿está levantado el servidor?\n";
    exit(2);
}

// Calentar (el primer arranque de los workers de php -S mete ruido).
$api->callMany(array(lento('w1', 0, $sid), lento('w2', 0, $sid)));
vaciarMemo();

echo "1-2) Seis peticiones de 800/200/200/200/200/200 ms\n";
$reqs = array(lento('a', 800, $sid));
foreach (array('b', 'c', 'd', 'e', 'f') as $id) {
    $reqs[] = lento($id, 200, $sid);
}
$desde = lineasLog();
$antes = SugarRestApiCall::$callCount;
$logAntes = count(SugarRestApiCall::$callLog);
$t0 = microtime(true);
$listas = $api->callMany($reqs);
$ms = (microtime(true) - $t0) * 1000;
$fines = array_values(array_filter(finesDesde($desde), function ($f) { return $f['fin'] === 'lento'; }));
check('callMany() dice que las 6 están listas', $listas === 6, "listas=$listas");
check('el servidor recibió las 6', count($fines) === 6, 'recibidas=' . count($fines));
$todas = true;
foreach ($reqs as $r) {
    $f = firma($r['method'], $r['parameters']);
    $m = memo();
    $eco = isset($m[$f]->eco) ? $m[$f]->eco : null;
    if ($eco !== $r['parameters']['id']) {
        $todas = false;
        printf("      · %s: en el memo hay %s\n", $r['parameters']['id'], var_export($eco, true));
    }
}
check('cada respuesta está en el memo con SU firma', $todas);
$maxVuelo = maxSimultaneas($fines);
check('nunca más de 4 a la vez en el servidor', $maxVuelo <= 4, "máximo=$maxVuelo");
check('ventana deslizante: ~800 ms y no ~1.000 (olas de 4)', $ms >= 790 && $ms < 940, sprintf('%.0f ms', $ms));
// La quinta tiene que arrancar al acabar una de 200, no al acabar la de 800.
$a = null; $quinta = null;
foreach ($fines as $f) {
    if ($f['id'] === 'a') { $a = $f; }
    if ($f['id'] === 'e') { $quinta = $f; }
}
check('la quinta arranca antes de que acabe la de 800', $a && $quinta && $quinta['t0'] < $a['t1'] - 0.3,
    $a && $quinta ? sprintf('quinta empieza %.0f ms antes', ($a['t1'] - $quinta['t0']) * 1000) : 'sin datos');
check('el contador suma 6 llamadas', SugarRestApiCall::$callCount - $antes === 6,
    'suma=' . (SugarRestApiCall::$callCount - $antes));
$ultima = end(SugarRestApiCall::$callLog);
check('el diario apunta UNA tanda(6) con el tiempo de la tanda',
    count(SugarRestApiCall::$callLog) === $logAntes + 1 && $ultima['method'] === 'tanda(6)' && abs($ultima['ms'] - $ms) < 50,
    json_encode($ultima));
// Y quien la consume no vuelve a salir a la red.
$desde = lineasLog();
$r = $api->call('lento', $reqs[3]['parameters'], $URL);
check('el cargador consume la respuesta del memo sin salir a la red', isset($r->eco) && $r->eco === 'd' && lineasLog() === $desde);
vaciarMemo();

echo "2b) Con el filtro de concurrencia a 2: seis de 100 ms, nunca más de 2 a la vez\n";
$CONCURRENCIA = 2;
$reqs = array();
foreach (range(1, 6) as $i) {
    $reqs[] = lento("c$i", 100, $sid);
}
$desde = lineasLog();
$t0 = microtime(true);
$listas = $api->callMany($reqs);
$ms = (microtime(true) - $t0) * 1000;
$fines = array_values(array_filter(finesDesde($desde), function ($f) { return $f['fin'] === 'lento'; }));
check('las 6 listas', $listas === 6, "listas=$listas");
check('nunca más de 2 a la vez', maxSimultaneas($fines) <= 2, 'máximo=' . maxSimultaneas($fines));
check('tres esperas de 100 ms (~300 ms)', $ms >= 290 && $ms < 420, sprintf('%.0f ms', $ms));
$CONCURRENCIA = null;
vaciarMemo();

echo "3) Un cuelgue, un cuerpo roto, uno vacío y uno cortado en medio de tres buenas\n";
$reqs = array(
    lento('g', 200, $sid),
    array('method' => 'hang', 'parameters' => array('session' => $sid, 'id' => 'colgada')),
    array('method' => 'roto', 'parameters' => array('session' => $sid, 'id' => 'rota')),
    array('method' => 'vacio', 'parameters' => array('session' => $sid, 'id' => 'vacia')),
    lento('h', 200, $sid),
    array('method' => 'cortado', 'parameters' => array('session' => $sid, 'id' => 'cortada')),
    lento('i', 200, $sid),
);
$t0 = microtime(true);
$listas = $api->callMany($reqs);
$s = microtime(true) - $t0;
check("vuelve en cuanto corta el timeout ({$TIMEOUT} s), sin colgarse", $s < $TIMEOUT + 1.5, sprintf('%.1f s', $s));
check('las tres buenas, listas', $listas === 3, "listas=$listas");
$m = memo();
$bien = true;
foreach (array(0 => 'g', 4 => 'h', 6 => 'i') as $i => $id) {
    $f = firma($reqs[$i]['method'], $reqs[$i]['parameters']);
    if (!isset($m[$f]->eco) || $m[$f]->eco !== $id) { $bien = false; }
}
check('cada buena en el memo con su firma', $bien);
$malas = 0;
foreach (array(1, 2, 3, 5) as $i) {
    if (isset($m[firma($reqs[$i]['method'], $reqs[$i]['parameters'])])) { $malas++; }
}
check('ninguna de las malas en el memo (las pedirá el cargador en serie)', $malas === 0, "en el memo: $malas");
vaciarMemo();
$r = $api->call('lento', array('session' => $sid, 'id' => 'despues', 'ms' => 0), $URL);
check('el cliente sigue usable después', isset($r->eco) && $r->eco === 'despues');

echo "4) Sesión caducada (error 11): un login y otra vez cada una, con la sesión nueva\n";
$api->session_id = 'sesion-muerta';
$reqs = array();
foreach (array('j', 'k', 'l', 'm', 'n') as $id) {
    $reqs[] = lento($id, 100, 'sesion-muerta');
}
$desde = lineasLog();
$antes = SugarRestApiCall::$callCount;
$listas = $api->callMany($reqs);
$nueva = $api->session_id;
$logins = count(array_filter(finesDesde($desde), function ($f) { return $f['fin'] === 'login'; }));
check('re-login hecho una sola vez', $logins === 1 && $nueva !== 'sesion-muerta', "logins=$logins sesión=$nueva");
check('las 5 listas tras el reintento', $listas === 5, "listas=$listas");
$m = memo();
$bien = true;
foreach ($reqs as $r) {
    $f = firma($r['method'], $r['parameters']);
    if (!isset($m[$f]->eco) || $m[$f]->eco !== $r['parameters']['id'] || $m[$f]->session_recibida !== $nueva) {
        $bien = false;
    }
}
check('cada una en el memo con su firma y respondida con la sesión NUEVA', $bien);
check('contadores: 5 de la tanda + 1 login + 5 reintentos', SugarRestApiCall::$callCount - $antes === 11,
    'suma=' . (SugarRestApiCall::$callCount - $antes));
vaciarMemo();
$sid = $nueva;

echo "5) Servidor caído: vuelve enseguida y sin nada en el memo\n";
$caido = nuevoCliente('http://127.0.0.1:9/');
$reqs = array();
foreach (range(1, 6) as $i) {
    $reqs[] = lento("x$i", 0, 's');
}
$t0 = microtime(true);
$listas = $caido->callMany($reqs);
$s = microtime(true) - $t0;
check('0 listas', $listas === 0, "listas=$listas");
check('en menos de un segundo', $s < 1.0, sprintf('%.2f s', $s));
check('nada en el memo', count(memo()) === 0);

echo "7) Sin fugas: 40 tandas de 6 seguidas\n";
$fds = function () { return is_dir('/proc/self/fd') ? count(scandir('/proc/self/fd')) : -1; };
gc_collect_cycles();
$fdAntes = $fds();
$memAntes = memory_get_usage();
for ($i = 0; $i < 40; $i++) {
    $reqs = array();
    foreach (range(1, 6) as $j) {
        $reqs[] = lento("z$i-$j", 0, $sid);
    }
    $api->callMany($reqs);
    vaciarMemo();
}
gc_collect_cycles();
$fdDespues = $fds();
$memDespues = memory_get_usage();
check('los descriptores abiertos no crecen', $fdDespues <= $fdAntes + 2, "antes=$fdAntes después=$fdDespues");
check('la memoria no crece', $memDespues - $memAntes < 256 * 1024, sprintf('+%d KB', ($memDespues - $memAntes) / 1024));

echo $fails === 0 ? "\nTODO OK\n" : "\n$fails COMPROBACIONES FALLIDAS\n";
exit($fails === 0 ? 0 : 1);
