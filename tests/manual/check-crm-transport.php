<?php
/**
 * Valida el cliente REST del plugin (inc/stic-class-6.php) contra un servidor
 * HTTP real, sin necesidad de credenciales ni del CRM de producción.
 * Comprueba: parseo del cuerpo con HTTP/1.1, reutilización de conexión,
 * compresión, timeout y el reintento por sesión caducada.
 */

$URL = getenv('MOCK_URL');

// --- Stubs mínimos de WordPress ---
function get_locale() { return 'es_ES'; }
function apply_filters($tag, $value = null) {
    // El test acorta el timeout para no esperar 20s en la prueba del cuelgue.
    if ($tag === 'sticpa_crm_timeout') { return (int) (getenv('TEST_TIMEOUT') ?: 20); }
    return $value;
}
function get_option($k, $d = false) { return $d; }
function getDestinationModule() { return 'Contacts'; }

$_SESSION = array();

require __DIR__ . '/../../inc/stic-class-6.php';

$fails = 0;
function check($label, $ok, $extra = '') {
    global $fails;
    if (!$ok) { $fails++; }
    printf("%s %s%s\n", $ok ? '  ✓' : '  ✗ FALLO', $label, $extra !== '' ? " — $extra" : '');
}

// getObjSCP() usa get_option(); construimos a mano con reflexión para apuntar al mock.
$ref = new ReflectionClass('SugarRestApiCall');
$ctor = $ref->getConstructor();
$ctor->setAccessible(true);
$api = $ref->newInstanceWithoutConstructor();
$ctor->invoke($api, $URL, 'usuario-test', 'clave-test', 'Contacts');

echo "1) Login y parseo del cuerpo (HTTP/1.1 + CURLOPT_HEADER 0)\n";
$sid = $_SESSION['api_session_id'] ?? null;
check('el login devuelve un session_id', is_string($sid) && $sid !== '', var_export($sid, true));
check('se guarda la marca de tiempo', !empty($_SESSION['api_session_time']));

echo "2) Una llamada normal devuelve JSON decodificado (sin cabeceras pegadas)\n";
$res = $api->call('get_entry_list', array('session' => $sid, 'module_name' => 'Contacts'), $URL);
check('la respuesta es un objeto', is_object($res));
check('trae entry_list con 200 registros', isset($res->entry_list) && count($res->entry_list) === 200);

echo "3) Reutilización de conexión (keep-alive)\n";
$api->call('get_entry_list', array('session' => $sid), $URL);
$handleProp = $ref->getProperty('curlHandle');
$handleProp->setAccessible(true);
$h = $handleProp->getValue($api);
$numConnects = curl_getinfo($h, CURLINFO_NUM_CONNECTS);
$httpVersion = curl_getinfo($h, CURLINFO_HTTP_VERSION);
check('se negoció HTTP/1.1', $httpVersion === CURL_HTTP_VERSION_1_1, "version=$httpVersion");
// OJO: el servidor de pruebas de PHP (php -S) manda SIEMPRE `Connection: close`,
// así que aquí la reutilización NO puede darse y num_connects será 1. No es un
// fallo del cliente. Para comprobar el keep-alive de verdad hace falta un
// servidor que lo soporte: ver el apartado correspondiente de README.md.
printf("  · conexiones nuevas en esta llamada: %d (con php -S siempre 1: manda Connection: close)\n", $numConnects);

echo "4) Compresión\n";
$log = file_get_contents(getenv('MOCK_LOG'));
check('el cliente anunció gzip', strpos($log, 'gzip') !== false);
check('el servidor vio HTTP/1.1', strpos($log, 'HTTP\\/1.1') !== false);

echo "5) Reintento por sesión caducada: manda el session_id NUEVO\n";
@unlink('/tmp/mockcrm-expired.state');
$sidAntes = $_SESSION['api_session_id'];
$res = $api->call('expired_once', array('session' => $sidAntes, 'module_name' => 'Contacts'), $URL);
$sidDespues = $_SESSION['api_session_id'];
check('se hizo re-login (session_id distinto)', $sidAntes !== $sidDespues);
$recibida = $res->entry_list[0]->session_recibida ?? null;
check('el reintento usó el session_id nuevo', $recibida === $sidDespues,
    'recibida=' . var_export($recibida, true) . ' nueva=' . var_export($sidDespues, true));

echo "6) Timeout: una llamada colgada devuelve null en vez de esperar para siempre\n";
$t0 = microtime(true);
$res = $api->call('hang', array('session' => $sidDespues), $URL);
$elapsed = microtime(true) - $t0;
$limite = (int) (getenv('TEST_TIMEOUT') ?: 20);
check('devuelve null', $res === null);
check("cortó cerca del límite ({$limite}s)", $elapsed < $limite + 3, sprintf('tardó %.1fs', $elapsed));

echo "7) Tras el timeout el cliente sigue usable (el handle no queda roto)\n";
$res = $api->call('get_entry_list', array('session' => $sidDespues), $URL);
check('vuelve a responder bien', isset($res->entry_list));

echo $fails === 0 ? "\nTODO OK\n" : "\n$fails COMPROBACIONES FALLIDAS\n";
exit($fails === 0 ? 0 : 1);
