<?php
// Servidor que imita lo justo de la API REST v4_1 de SuiteCRM para validar el
// cliente cURL del plugin: responde JSON por POST, soporta gzip y puede simular
// una sesión caducada (error 11) y un cuelgue. Para las tandas en paralelo
// (`check-crm-multi.php`, plan 042 VEL-6) también responde con la latencia que
// se le pida, con un cuerpo roto, vacío o cortado, y apunta cuándo empezó y
// acabó cada petición (así se ve cuántas había a la vez).

$log = getenv('MOCK_LOG') ?: '/tmp/mockcrm.log';

// Las cabeceras pasan por aquí para que `mock-crm-fork.php` (que atiende por
// la CLI, donde header() no deja rastro) pueda saber cuáles se han mandado.
if (!function_exists('mock_header')) {
    function mock_header($h)
    {
        $GLOBALS['__mock_headers'][] = $h;
        header($h);
    }
}

$method = $_POST['method'] ?? '';
$restData = json_decode($_POST['rest_data'] ?? 'null', true);

$t0 = microtime(true);
register_shutdown_function(function () use ($log, $method, $restData, $t0) {
    file_put_contents($log, json_encode([
        'fin'    => $method,
        'id'     => is_array($restData) ? ($restData['id'] ?? null) : null,
        't0'     => $t0,
        't1'     => microtime(true),
    ]) . "\n", FILE_APPEND | LOCK_EX);
});

file_put_contents($log, json_encode([
    'method'   => $method,
    'session'  => is_array($restData) ? ($restData['session'] ?? null) : null,
    'proto'    => $_SERVER['SERVER_PROTOCOL'] ?? '?',
    'encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
]) . "\n", FILE_APPEND | LOCK_EX);

// Cuelgue deliberado para probar el timeout.
if ($method === 'hang') {
    sleep(8);
}

$payload = null;
if ($method === 'login') {
    $payload = ['id' => 'sesion-nueva-' . substr(md5((string) microtime(true)), 0, 6)];
} elseif ($method === 'expired_once') {
    // La primera vez responde "sesión caducada"; después, bien. Así se comprueba
    // que el reintento manda el session_id NUEVO y no el muerto.
    $stateFile = '/tmp/mockcrm-expired.state';
    if (!file_exists($stateFile)) {
        file_put_contents($stateFile, '1');
        $payload = ['name' => 'Invalid Session ID', 'number' => 11, 'description' => 'caducada'];
    } else {
        $payload = ['entry_list' => [['id' => 'ok', 'session_recibida' => $restData['session'] ?? null]]];
    }
} elseif ($method === 'lento') {
    // La latencia que pida la petición (`ms`) y el eco de QUIÉN es: así se
    // comprueba que cada respuesta de una tanda acaba con su firma y no con la
    // de otra. Con la sesión 'sesion-muerta' contesta «caducada» (error 11).
    if (($restData['session'] ?? null) === 'sesion-muerta') {
        $payload = ['name' => 'Invalid Session ID', 'number' => 11, 'description' => 'caducada'];
    } else {
        usleep(max(0, (int) ($restData['ms'] ?? 0)) * 1000);
        $payload = ['eco' => $restData['id'] ?? null, 'session_recibida' => $restData['session'] ?? null];
    }
} elseif ($method === 'roto') {
    mock_header('Content-Type: application/json');
    echo '{"entry_list": [ esto no es JSON';
    exit;
} elseif ($method === 'vacio') {
    exit;   // 200 sin cuerpo
} elseif ($method === 'cortado') {
    // Un JSON entero, pero la conexión se cierra antes de lo prometido: cURL
    // lo da por fallido (CURLE_PARTIAL_FILE) aunque el texto se decodifique.
    mock_header('Content-Type: application/json');
    mock_header('Content-Length: 500');
    echo '{"entry_list":[]}';
    exit;
} else {
    // Respuesta grande para que la compresión se note.
    $payload = ['entry_list' => array_fill(0, 200, ['id' => 'reg', 'name' => str_repeat('Consolación ', 12)])];
}

$body = json_encode($payload);

// gzip si el cliente lo acepta (es lo que hace un servidor real con mod_deflate).
if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false) {
    mock_header('Content-Encoding: gzip');
    $body = gzencode($body);
}
mock_header('Content-Type: application/json');
echo $body;
