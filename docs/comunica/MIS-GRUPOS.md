# Mis grupos — leer las fichas sin pasar lista

Una sección del área privada, al lado de Pasar Lista y con la misma gente vista
de otra manera. Existe para una queja concreta: **para mirar un teléfono o leer
la ficha de un chaval había que entrar a marcar una lista**, que es un flujo
para otra cosa y que además ata lo que ves a una sesión concreta.

> Pedida y construida el 28/08/2026. Cierra el melón «navegador de fichas» del
> [roadmap](PASAR-LISTA-ROADMAP.md).

**Archivo:** `pages/single_stic_mis_grupos.php`
**Menú:** `menu.php`, con la misma condición que Pasar Lista — monitor, y no
mirando la ficha de un participante.

---

## 0. Cómo se llega

Por el **menú**, al lado de Pasar Lista, y por una línea al **pie del árbol de
grupos** («¿Solo quieres mirar las fichas?»). Esa línea va al pie y no como un
segundo botón en cada fila: veintiocho filas con dos acciones cada una
convierten un árbol que se escanea en un menú que se lee, y lo que se hace ahí
el 95 % de las veces es entrar a marcar.

---

## 1. Lo que hace

Tres vistas de la misma gente, que son tres formas de buscar a alguien, y dos
poblaciones:

| URL | Qué enseña |
|---|---|
| `?ver=grupos` *(por defecto)* | Tus grupos primero, luego los demás por etapa. Con recuentos. |
| `?ver=cursos` | Por curso escolar, que **cruza los grupos**: «todos los de 1.º de ESO» están repartidos en C1 y C2 y por grupo no se ven juntos nunca. |
| `?ver=az` | Toda la gente seguida, por apellido. |
| `?grupo=<id>` | La gente de un grupo: monitores arriba, participantes debajo. |
| `?quien=monitores` | **Solo coordinación.** Los monitores del alcance, agrupados por etapa. |
| `?ver=sueltos` | Quien no está en ningún grupo, y el control para vincularlo. Se llega por la tarjeta del final del índice, que solo sale si hay alguien. |

Más un buscador que filtra lo ya pintado, sin acentos ni mayúsculas, y sin ir
al servidor.

De cada ficha se sale con la flecha de atrás **al sitio del que viniste**, y
desde el pie de la ficha se pasa a la siguiente persona del grupo sin volver al
índice: es lo que permite leerse un grupo entero de una sentada.

---

## 2. La regla que la define: NO TIENE NI UN CARGADOR PROPIO

Todo sale de los mismos cargadores que ya usa Pasar Lista:

- `sticpa_pl_groups()` — los grupos de la delegación, con la etapa, el curso y
  el recuento nocturno ya resueltos.
- `sticpa_pl_my_groups()` — cuáles son los tuyos.
- `sticpa_pl_all_relationships()` — **toda** la gente de la delegación con su
  grupo, su papel y su vigencia, en una consulta.
- `sticpa_pl_coord_scope()`, `sticpa_pl_scoped_groups()`,
  `sticpa_pl_monitors_of()` — el alcance de coordinación, tal cual.

Medido en `tests/CosteLlamadasTest.php`: **3 llamadas en una sola tanda**
partiendo de frío, y **cero** con la caché caliente. Es la pantalla más barata
de todas. El tope está puesto en 4 a propósito: cualquier consulta nueva aquí
es casi seguro una que ya se ha hecho en otro sitio.

La única excepción es `?grupo=<id>`, que puede llegar a 5 y solo cuando el mapa
de la delegación viene inservible: entonces cae al respaldo por grupo de
`sticpa_pl_group_people()`, igual que marcar y la ficha. Es una vez, no una por
grupo — el índice usa `sticpa_pl_group_people_bulk()`, que nunca cae.

---

## 3. Lo que se extrajo para compartirlo

«Reutilizarlo todo» significó sacar de las pantallas que ya existían lo que
esta necesitaba igual. Todo vive en `inc/stic-pasar-lista-ui.php`:

| Función | Qué es | Quién la usa |
|---|---|---|
| `sticpa_pl_buscador_html()` | El cuadro de búsqueda con su lupa | El árbol de grupos y esta |
| `sticpa_pl_buscador_vacio_html()` | El «nada coincide», oculto hasta que hace falta | Las dos |
| `sticpa_pl_grupos_ocultos_html()` | La nota al pie de los grupos sin marcar | Las dos |
| `sticpa_pl_avatar_html()` | El círculo con la foto o las iniciales | Las listas y las dos fichas |
| `sticpa_pl_person_link_html()` | Una persona como **enlace** a su ficha | Esta |
| `sticpa_pl_vengo_url()` | A dónde vuelve la flecha de atrás | Las dos fichas |
| `sticpa_pl_vecinos()` / `sticpa_pl_pager_html()` | Anterior/siguiente | La ficha del participante |

`sticpa_pl_person_link_html()` **no** reutiliza `sticpa_pl_row_html()`, y es
deliberado: aquella es un `<button>` que marca asistencia y lleva su gesto
largo, su anillo y su hoja de estados. Aquí no se marca nada. Meter un modo
dentro de aquella sería arriesgar la pantalla que de verdad importa un sábado
para ahorrarse veinte líneas. Lo que sí se reutiliza es todo lo visible: mismas
clases, mismo avatar, misma tipografía, misma flecha.

### `sticpa_es_pantalla_pl()`

Quién se lleva `pasar-lista.css`, `stic-pasar-lista.js` y el service worker
estaba escrito como `strpos($page, 'single_stic_pasar_lista') === 0` en **tres**
sitios. En cuanto apareció una pantalla de la misma familia con otro nombre, los
tres se quedaron cortos a la vez. Ahora es una función en
`inc/stic-pasar-lista.php`, y añadir una pantalla es añadir una línea.

⚠️ El service worker tiene su propia copia de la lista en
`js/stic-pasar-lista-sw.js` (no ve PHP). **Si se toca una, se toca la otra.**

---

## 4. Las fotos

El circuito ya existía entero —descarga del CRM, miniatura, caché en disco 24 h,
404 → placeholder— pero el endpoint del perfil sirve **solo la foto de quien
está en sesión**: «el id nunca viene del request», y eso es a propósito.

Para verlas en las fichas hay un endpoint nuevo, `stic_pl_photo`
(`inc/stic-action.php`), con su propia autorización:

1. Hay sesión del área privada.
2. El rol es `monitor` y la audiencia no es `participante`.
3. **La persona está en el mapa de relaciones de la delegación**
   (`sticpa_pl_persona_de_mi_delegacion()`, que no cuesta ninguna llamada:
   recorre el mapa que ya está en caché).

Fuera de ahí devuelve **403 y no 404**, a propósito: un 404 confirmaría de
rebote que esa persona existe y que lo que le falta es la foto.

El cuerpo que sirve la imagen se extrajo a `sticpa_serve_contact_photo()` y lo
comparten los dos endpoints. **Esa función NO autoriza** — autoriza quien la
llama. Está escrito en su docblock; si alguien la usa desde un tercer sitio, es
suyo comprobar quién pide qué.

En pantalla, la foto va **dentro** del círculo de las iniciales y encima: si no
hay foto, o el CRM tarda, o falla, debajo quedan las iniciales de siempre. Sin
hueco, sin salto y sin JavaScript se ven las iniciales.

**Dónde SÍ y dónde NO.** Cada foto es una petición. En las dos fichas y en las
listas que acota un grupo (veinte o veinticinco personas) van. En las vistas
A-Z y por curso **no**: ahí la lista es toda la delegación, y aunque
`loading="lazy"` solo baje las que se ven, recorrerla entera serían trescientos
viajes en una webview con datos móviles. El parámetro que lo decide es el
`$conFoto` de `sticpa_pl_avatar_html()`.

---

## 5. El color de la vista por curso

Cada cabecera de curso lleva un punto **más intenso cuanto más mayores son**. En
una lista de trescientos, los títulos se leen uno a uno; el color se ve de un
vistazo y dice por dónde vas.

Sale de `sticpa_pl_curso_intensidad()`, que a su vez sale del **mismo**
`sticpa_pl_curso_rank()` que ordena la vista: el color y el orden no pueden
contradecirse. Si un curso va antes, su punto es más claro.

Tres decisiones que lo sostienen:

- **La escala es ABSOLUTA**, no relativa a lo que haya en pantalla. «1.º de ESO»
  tiene que ser del mismo color en Castellón y en cualquier otra delegación, y
  no cambiar porque este año no haya grupo de infantil.
- **Es opacidad sobre `--primary-color`, no un color fijo.** En oscuro el azul
  ya viene aclarado por el token, así que «más intenso» se sigue leyendo como
  «más mayores» sin mantener dos escalas de color.
- **Lo que no se reconoce no se colorea.** «Sin curso» va en gris: un color
  inventado sobre un dato que no se entiende miente.

La conversión rank → intensidad es una **tabla de anclajes interpolada**, con dos
por etapa (su principio y su final) para que 1.º y 6.º de primaria no salgan del
mismo color. Se hace así y no descomponiendo el rank en «base + curso» porque
las bases de `sticpa_pl_curso_rank()` no son todas múltiplos de cien —FP es
350—, y dividir por cien colaba FP por delante de universidad. Está cubierto por
un test que recorre la escalera entera y exige que sea monótona.

---

## 6. Los monitores: orden y flechas

`sticpa_pl_monitors_of()` ordena por **curso → grupo → apellido**:

- Por **curso**, porque es como empieza el sábado: los pequeños primero.
- Por **grupo** dentro del curso, para que los dos o tres monitores del mismo
  grupo salgan **seguidos**. Sin esto, con dos grupos del mismo curso salían
  intercalados por apellido y no había forma de ver quién lleva qué. El código
  se compara con `strnatcasecmp`, o C10 se cuela entre C1 y C2.
- Por **apellido** al final, que es como se lee una lista de personas.

Es el mismo orden que recorre el anterior/siguiente de la ficha del monitor, así
que la lista y las flechas no pueden ir cada una por su lado.

**Las flechas se ciñen a de dónde vienes.** Llegando desde un grupo concreto, la
lista son los monitores **de ese grupo**; si no, los de todo el alcance. Pasar de
la ficha de un monitor de MIC a la de uno de LC porque alfabéticamente tocaba no
es leer una lista, es perderse.

Los monitores **no** tienen vista por curso —se agrupan por etapa, que es como
los mira coordinación—, pero el dato está: `sticpa_pl_monitors_of()` ya devuelve
`curso` y `rank`.

---

## 7. La gente sin grupo

Quien tiene relación con la delegación pero no está en ningún grupo **no sale en
ninguna lista**: ni en el árbol, ni al pasar lista, ni aquí. Es una de las
razones de que «falte gente» un sábado.

La tarjeta del final del índice lo dice y lleva a `?ver=sueltos`, donde
**coordinación lo arregla en dos gestos**: un desplegable con los grupos —con su
curso al lado, que es lo que hace falta para decidir— y un botón. Un monitor
raso ve la lista pero no el control, y se le dice a quién avisar.

Escribe `sticpa_pl_assign_group()`, que es **el mismo** que ya usaba el resumen
de grupos: comprueba por su cuenta que quien llama coordina y que el grupo es de
su delegación, así que no hay una segunda copia de la regla que pueda quedarse
desfasada. Lo que se comprueba en la pantalla es el nonce, que es suyo.

Dos detalles que importan:

- **El POST va antes de la tanda que lee.** `sticpa_pl_assign_group()` vacía la
  caché al escribir; si se leyera primero, la persona recién vinculada seguiría
  saliendo suelta hasta recargar a mano.
- **Aquí no se enlaza a la ficha.** Sin grupo no hay ficha que enseñar: la ficha
  comprueba que la persona esté en el grupo de la URL, y ese es justo el dato que
  falta. Lo que se hace aquí es ponerle grupo, que es lo que desbloquea el resto.

⚠️ **Lo que esto NO hace:** dar de alta a alguien que no tiene *ninguna*
relación con la delegación. Aquí solo se le pone grupo a una relación que ya
existe. Crear la relación desde cero es otra cosa —y otra conversación—, porque
implica elegir el tipo de relación y la fecha de inicio, y eso hoy se hace en el
CRM.

---

## 8. Decisiones que parecen detalles y no lo son

**El cero inventado.** Si el mapa de relaciones viene a medias, contar da cero,
y un «0 chavales» al lado de un grupo que tiene doce **se lee como un dato**, no
como un fallo. Cuando no hay a quién contar se usa el recuento que deja el
Guardián por la noche ([`PASAR-LISTA-RECUENTOS.md`](PASAR-LISTA-RECUENTOS.md)),
y si tampoco ese está fresco, un hueco.

**Los recuentos del índice se cuentan una vez.** La tentación era llamar a
`sticpa_pl_group_people()` por grupo: no cuesta llamadas al CRM, pero recorre el
mapa entero una vez por grupo — con 28 grupos y 600 relaciones son diecisiete
mil vueltas para pintar veintiocho números. Se recorre una vez y se cuenta todo.

**`vengo` solo acepta valores conocidos.** Lo que venga en la URL nunca se
convierte en un enlace tal cual, y si no se reconoce **se tira**: si solo se
ignorara al construir el enlace de atrás, el pie de «siguiente» lo seguiría
arrastrando de ficha en ficha.

**El anterior/siguiente no da la vuelta.** Llegar al último y volver al primero
sin avisar es releer creyendo que avanzas. El hueco del lado que falta se
mantiene, para que el botón que queda no se descoloque al centro.

**Los enlaces del pie no llevan `sesion`.** Si se está leyendo, no se está
marcando. Era justo la pega de llegar a la ficha por la pantalla de marcar.

---

## 9. Qué queda por decidir

- ~~El formulario de «datos por revisar» del resumen es un duplicado.~~
  **Hecho el 01/09/2026:** el resumen se queda con el aviso —que es lo que sabe
  hacer bien— y un enlace a `?ver=sueltos`. El manejador del POST se deja en pie
  para que un enlace viejo o un botón atrás no den un error raro.
- **No hay flechas en las vistas A-Z y por curso.** Recorrer trescientas fichas
  con el pie es un gesto raro; hoy las flechas recorren el grupo, que es la
  lista que alguien se lee entera de verdad.
- **Nadie da de alta a una persona nueva desde aquí** (ver §7).
