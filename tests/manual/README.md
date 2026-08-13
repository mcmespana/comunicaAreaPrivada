# Comprobaciones manuales (no entran en el CI)

Estas comprobaciones NO las ejecuta PHPUnit (sus archivos no acaban en `Test.php`,
así que la suite no las recoge) ni se suben por FTP a producción (`tests/` está
excluido en el workflow de deploy). Se lanzan a mano cuando se toca lo que
prueban, porque necesitan levantar un servidor y eso es demasiado frágil para
tenerlo puerta de despliegue.

---

## Transporte al CRM (`check-crm-transport.php`)

Valida `inc/stic-class-6.php` (la clase `SugarRestApiCall`) contra un servidor
HTTP **de verdad**, sin necesidad de credenciales ni de tocar el CRM real.
Comprueba:

1. El login devuelve `session_id` y se guarda con su marca de tiempo.
2. El cuerpo de la respuesta se decodifica bien con HTTP/1.1 y `CURLOPT_HEADER 0`
   (antes se partía a mano con `explode("\r\n\r\n")`, que es lo que ataba el
   código a HTTP/1.0).
3. Se negocia HTTP/1.1 y se anuncia compresión.
4. El reintento por sesión caducada (`number == 11`) manda el `session_id`
   **nuevo**; antes reenviaba el muerto y volvía a fallar.
5. Un servidor colgado corta por `CURLOPT_TIMEOUT` y devuelve `null` en vez de
   comerse el `max_execution_time`.
6. Tras ese timeout el cliente sigue usable.

```bash
cd tests/manual
rm -f /tmp/mockcrm.log /tmp/mockcrm-expired.state
# PHP_CLI_SERVER_WORKERS es IMPRESCINDIBLE: el servidor de pruebas es monohilo y,
# sin varios workers, el `sleep` de la prueba del timeout bloquea al servidor
# entero y las comprobaciones siguientes fallan por eso (no por el código).
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8803 mock-crm-server.php >/tmp/phpserver.log 2>&1 &
MOCK_URL=http://127.0.0.1:8803/ MOCK_LOG=/tmp/mockcrm.log TEST_TIMEOUT=3 php check-crm-transport.php
kill %1
```

### Lo que este arnés NO puede comprobar: el keep-alive

El servidor de pruebas de PHP responde **siempre** `Connection: close`, así que la
reutilización de conexión no puede ocurrir ahí y `CURLINFO_NUM_CONNECTS` saldrá
1 en cada llamada. Eso **no** es un fallo del cliente.

Para medirlo hace falta un servidor que soporte keep-alive. Basta con apuntar a
cualquier URL del propio sitio (una que dé 404 sirve: no cambia nada) y mirar
`CURLINFO_NUM_CONNECTS`, que vale 0 cuando la conexión se ha reutilizado:

```bash
php -r '
$url = "https://comunica.movimientoconsolacion.com/__sticpa-transport-check";
$h = curl_init();
for ($i = 1; $i <= 3; $i++) {
    curl_reset($h);
    curl_setopt_array($h, [
        CURLOPT_URL => $url, CURLOPT_POST => 1,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_HEADER => 0, CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TCP_KEEPALIVE => 1, CURLOPT_ENCODING => "",
        CURLOPT_POSTFIELDS => ["method" => "ping"],
    ]);
    $t0 = microtime(true);
    curl_exec($h);
    printf("llamada %d: conexiones_nuevas=%d  %.0f ms\n", $i,
        curl_getinfo($h, CURLINFO_NUM_CONNECTS), (microtime(true) - $t0) * 1000);
}'
```

Resultado medido el 2026-08-13 (desde fuera del hosting, así que los tiempos
absolutos no son los de producción, pero la diferencia sí es real):

```
llamada 1: conexiones_nuevas=1  1947 ms
llamada 2: conexiones_nuevas=0   346 ms
llamada 3: conexiones_nuevas=0   371 ms
```

Es decir: el handshake (DNS + TCP + TLS) costaba ~1,6 s **por llamada al CRM**, y
una sola pantalla del área hace entre 2 y 40. Ese es el porqué del plan 027.
