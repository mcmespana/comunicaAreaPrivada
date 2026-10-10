<?php
/**
 * El MISMO servidor de pruebas (`mock-crm-server.php`), pero con un proceso
 * por conexión. Hace falta para medir TIEMPOS de tandas en paralelo.
 *
 * Por qué no basta `php -S` con PHP_CLI_SERVER_WORKERS: cada worker acepta
 * conexiones en su propio bucle y a veces se queda con dos que llegan juntas,
 * y las atiende EN FILA. Medido el 10/10/2026: la tanda 800/200/200/200/200/200
 * salía en 801 ms dos veces de tres y en 1.204 la otra, sin tocar el cliente.
 * Para comprobar que algo va bien sirve; para medir cuánto tarda, no.
 *
 * Solo entiende lo que manda el cliente del plugin: un POST multipart/form-data
 * por conexión (HTTP/1.1, y responde con `Connection: close`). Necesita pcntl.
 *
 *   MOCK_LOG=/tmp/mockcrm.log php mock-crm-fork.php 127.0.0.1:8814
 */

if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "Hace falta la extensión pcntl.\n");
    exit(2);
}

$addr = $argv[1] ?? '127.0.0.1:8814';
$srv = stream_socket_server('tcp://' . $addr, $errno, $errstr);
if (!$srv) {
    fwrite(STDERR, "No se puede escuchar en $addr: $errstr\n");
    exit(2);
}
pcntl_signal(SIGCHLD, SIG_IGN);   // los hijos se recogen solos
fwrite(STDERR, "Escuchando en $addr\n");

while (true) {
    $c = @stream_socket_accept($srv, -1);
    if (!$c) {
        continue;
    }
    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($srv);
        atender($c);
        exit(0);
    }
    fclose($c);
}

function atender($c)
{
    $cabecera = '';
    while (($linea = fgets($c)) !== false && $linea !== "\r\n") {
        $cabecera .= $linea;
    }
    $largo = preg_match('/^content-length:\s*(\d+)/mi', $cabecera, $m) ? (int) $m[1] : 0;
    if (preg_match('/^expect:\s*100-continue/mi', $cabecera)) {
        fwrite($c, "HTTP/1.1 100 Continue\r\n\r\n");
    }
    $cuerpo = '';
    while (strlen($cuerpo) < $largo && !feof($c)) {
        $cuerpo .= fread($c, $largo - strlen($cuerpo));
    }

    $_POST = array();
    if (preg_match('/boundary=(?:"([^"]+)"|([^;\r\n]+))/i', $cabecera, $b)) {
        $frontera = $b[1] !== '' ? $b[1] : $b[2];
        foreach (explode('--' . $frontera, $cuerpo) as $parte) {
            if (!preg_match('/name="([^"]+)"\r\n(?:[^\r\n]+\r\n)*\r\n(.*)\r\n$/s', $parte, $p)) {
                continue;
            }
            $_POST[$p[1]] = $p[2];
        }
    }
    $_SERVER['SERVER_PROTOCOL'] = preg_match('#HTTP/[\d.]+#', $cabecera, $v) ? $v[0] : 'HTTP/1.1';
    $_SERVER['HTTP_ACCEPT_ENCODING'] = preg_match('/^accept-encoding:\s*(.*)$/mi', $cabecera, $e) ? trim($e[1]) : '';
    $GLOBALS['__mock_headers'] = array();

    // El mock termina con `exit` a veces: la respuesta se escribe al apagar.
    register_shutdown_function(function () use ($c) {
        $cuerpo = (string) ob_get_clean();
        $cabeceras = array();
        foreach ($GLOBALS['__mock_headers'] as $h) {
            list($k) = explode(':', $h, 2);
            $cabeceras[strtolower($k)] = $h;
        }
        if (!isset($cabeceras['content-length'])) {
            $cabeceras['content-length'] = 'Content-Length: ' . strlen($cuerpo);
        }
        $cabeceras['connection'] = 'Connection: close';
        @fwrite($c, "HTTP/1.1 200 OK\r\n" . implode("\r\n", $cabeceras) . "\r\n\r\n" . $cuerpo);
        @fclose($c);
    });
    ob_start();
    include __DIR__ . '/mock-crm-server.php';
}
