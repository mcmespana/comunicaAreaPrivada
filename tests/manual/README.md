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

---

## Tandas en paralelo (`check-crm-multi.php`)

Valida `SugarRestApiCall::callMany()`, el transporte por el que salen TODAS las
lecturas en paralelo del área (plan 034; ventana deslizante desde el plan 042,
VEL-6). Los tests de PHPUnit lo cambian por dobles, así que esto es lo único que
lo prueba de verdad. Comprueba:

1. Que llegan todas, y cada respuesta queda en el memo con **su** firma.
2. Que nunca hay más de 4 en vuelo (ni más de 2 con el filtro a 2), y que la
   ventana es deslizante: 800/200/200/200/200/200 ms tardan ~800, no ~1.000.
3. Que un cuelgue (timeout), un cuerpo roto, uno vacío y uno cortado no cuelgan
   la tanda ni pierden las demás, y que ninguno se memoriza.
4. Que la sesión caducada (`number == 11`) se reintenta: un login y cada una otra
   vez, con la sesión nueva.
5. Que contra un servidor caído vuelve al momento.
6. Que los contadores de `pl_diag` (`$callCount`, `$callLog`) cuentan lo que sale.
7. Que no se quedan descriptores ni memoria tras 40 tandas.

**Para medir tiempos hace falta `mock-crm-fork.php`** (el mismo mock, con un
proceso por conexión; necesita pcntl). Con `php -S` cada worker acepta a veces
dos conexiones a la vez y las atiende en fila: la tanda de arriba salía en 801 ms
dos veces de cada tres y en 1.204 la otra, sin tocar el cliente.

```bash
cd tests/manual
rm -f /tmp/mockcrm.log
MOCK_LOG=/tmp/mockcrm.log php mock-crm-fork.php 127.0.0.1:8814 &
MOCK_URL=http://127.0.0.1:8814/ MOCK_LOG=/tmp/mockcrm.log TEST_TIMEOUT=2 php check-crm-multi.php
kill %1
```

Con `CLASS_FILE=/ruta/a/otra/stic-class-6.php` se lanza contra otra versión de la
clase (por ejemplo la de antes de un cambio, sacada con `git show`): la de olas
de 4 da ~1.010 ms en la prueba 2 y memorizaba la respuesta cortada.
