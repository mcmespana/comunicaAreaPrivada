<?php
// Servidor que imita lo justo de la API REST v4_1 de SuiteCRM para validar el
// cliente cURL del plugin: responde JSON por POST, soporta gzip y puede simular
// una sesión caducada (error 11) y un cuelgue.

$log = getenv('MOCK_LOG') ?: '/tmp/mockcrm.log';

$method = $_POST['method'] ?? '';
$restData = json_decode($_POST['rest_data'] ?? 'null', true);

file_put_contents($log, json_encode([
    'method'   => $method,
    'session'  => is_array($restData) ? ($restData['session'] ?? null) : null,
    'proto'    => $_SERVER['SERVER_PROTOCOL'] ?? '?',
    'encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
]) . "\n", FILE_APPEND);

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
} else {
    // Respuesta grande para que la compresión se note.
    $payload = ['entry_list' => array_fill(0, 200, ['id' => 'reg', 'name' => str_repeat('Consolación ', 12)])];
}

$body = json_encode($payload);

// gzip si el cliente lo acepta (es lo que hace un servidor real con mod_deflate).
if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false) {
    header('Content-Encoding: gzip');
    $body = gzencode($body);
}
header('Content-Type: application/json');
echo $body;
