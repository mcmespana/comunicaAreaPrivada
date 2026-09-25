# 040 — El rol de Comunica se quedaba pegado y borraba el menú del monitor

## Status

- **HECHO** (2026-09-01)
- **Prioridad**: P1 (percibida P0: al propietario le desaparecieron los botones)
- **Esfuerzo**: S · **Riesgo**: BAJO
- **Categoría**: bug / sesión
- **Origen**: reporte en caliente — «han desaparecido los botones de Pasar lista
  y Mis grupos»

## El síntoma

A un monitor le desaparecieron **«Pasar lista»** y **«Mis grupos»** del menú.
No volvían solos. La sospecha inicial era que se le hubiera caducado la vigencia
de su relación de tipo monitor en el CRM.

## Qué era en realidad

**No era la vigencia.** La puerta del menú (`menu.php:33`) es:

```php
if ($role === 'monitor' && $audience !== 'participante') { … }
```

y `$role` sale de que el campo **`stic_relationship_type_c` del propio contacto**
contenga la palabra «monitor». No mira `stic_Contacts_Relationships`, ni
`start_date`, ni `end_date`, ni `active`: **las fechas de la relación no
intervienen en esto para nada**.

Tampoco era un borrado en el CRM: se comprobó por MCP que siguen ~150 contactos
con `^grupo^,^monitor^`.

Era la caché de sesión. `sticpa_store_comunica_role` guardaba el rol **siempre**:

```php
$role = sticpa_detect_role_from_relationship($raw);
$_SESSION['scp_role'] = $role;     // ← también cuando $raw venía vacío por un fallo
```

y `sticpa_get_comunica_role` solo lo recalculaba **si la clave no existía**:

```php
if (!isset($_SESSION['scp_role']) && …)
```

Junta las dos: si la lectura al CRM falla una sola vez —un timeout, la sesión de
la API caducada, lo que sea— se cachea `''`, y como la clave ya existe **no se
vuelve a preguntar jamás**. La cookie de sesión dura **un año**. El monitor se
queda sin sus dos pantallas, en silencio, sin ningún mensaje que lo explique y
sin más salida que cerrar sesión, si acierta a probarlo.

Lo peor del fallo no es la pérdida de función: es que **no se parece a su
causa**. Quien lo sufre piensa en permisos, en el CRM o en un despliegue, que es
exactamente lo que pasó.

## El arreglo

Distinguir **«el CRM ha contestado y no tiene rol»** de **«no se ha podido
preguntar»**, que antes eran el mismo `''`.

1. `sticpa_store_comunica_role` lleva una bandera `$resolved`. Se pone a `true`
   cuando el campo venía en el entry, o cuando el CRM devolvió el registro
   (`isset($detail->entry_list[0])`) — aunque el campo venga vacío, que es un
   dato bueno.
2. **Si no está resuelto, no se cachea nada** y se devuelve `''`. La siguiente
   petición lo reintenta.
3. Marca aparte del valor: `$_SESSION['scp_role_resolved']`. Hacía falta porque
   un rol vacío puede ser legítimo (un laico, una familia) y ahí sí hay que
   cachear para no preguntar al CRM en cada página.
4. `sticpa_role_needs_resolution()` mira **la marca, no el valor**, y es lo que
   consulta `sticpa_get_comunica_role`.
5. `scp_role_resolved` se añade a las claves que borra el cierre de sesión.

**Cura sola las sesiones ya rotas**, que era la otra mitad del problema: las
sesiones creadas antes de esto tienen `scp_role` pero **no** tienen la marca, así
que se les recalcula una vez y a partir de ahí quedan bien. Nadie tiene que
cerrar sesión a mano.

**Coste**: mientras el CRM no conteste se repite una llamada por página. Va con
timeout acotado (plan 027) y, si el CRM no contesta, el área está caída de todas
formas. Es infinitamente mejor que un año sin menú.

## Verificación

`tests/RoleCacheTest.php`, seis casos: monitor detectado y cacheado; sin rol pero
resuelto también se cachea; **sin resolver NO se cachea**; una sesión vieja con el
rol pegado se vuelve a resolver; una ya resuelta no se vuelve a preguntar; y el
mapa de detección con los valores que hay hoy en el CRM.

Se comprobó que el test **falla con el código antiguo** (`Failed asserting that
an array does not have the key 'scp_role'`) y pasa con el arreglo. Un test que no
se ha visto fallar no prueba nada.

Suite completa: **366 tests en verde**.

De paso, `tests/SessionTest.php` se volvió hermético: al cargar el fichero de
roles en el bootstrap se descubrió que `sticpa_establish_session` **salía al CRM
de verdad** en dos tests, porque sus entries no traían
`stic_relationship_type_c`. Ahora lo traen —que es lo que trae un login real— y
además comprueban el rol resultante.

## Lo que queda pendiente y no es de este plan

Cuando un monitor se queda sin sus pantallas **no hay ninguna forma de saber por
qué**: ni un aviso, ni una pista en la home, nada. Si vuelve a pasar por otro
motivo (el campo del CRM mal puesto, la audiencia en modo participante), el
diagnóstico será otra vez desde cero. Merece la pena una pista discreta para el
caso «tienes sesión pero no se te reconoce rol». No se hace aquí para no meter
UI nueva en un arreglo de bug.
