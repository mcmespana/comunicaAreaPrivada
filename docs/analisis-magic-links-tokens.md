# Análisis: Autenticación por Token / Magic Links

> **Estado:** propuesta para decidir e implementar
> **Prioridad:** 🔴 **P0 — la más alta** (resuelve a la vez seguridad y UX)
> **Tamaño estimado:** M (núcleo funcional en ~2-4 días; panel admin completo, algo más)
> **Relacionado:** tareas `SEC-*` y `AUTH-*` de [`../TODO.md`](../TODO.md)

---

## 1. TL;DR / Recomendación

**Sí, tu idea es viable y eficiente, y la recomiendo como primer desarrollo.** Pero conviene
mejorarla con un diseño de **dos niveles** para no convertir cada email en una llave maestra
permanente y eterna:

1. **Token permanente por contacto** (campo custom `stic_pa_token_c` en el CRM) → para el enlace
   "siempre disponible" al pie de los emails y para la **impersonación** desde el admin.
2. **Magic links firmados y caducables** (generados en WordPress con HMAC, sin guardar nada en el
   CRM) → para el flujo "introduce tu email y te mando acceso".

Ambos desembocan en lo mismo: al validarse, se crea la **sesión PHP normal** (cookie) y se
**limpia el token de la URL**. Así el token solo viaja una vez.

Esto **NO requiere programar dentro de SinergiaCRM**: el único cambio en el CRM es **crear un
campo custom con Studio** (eso es configuración, no código). Toda la lógica vive en el plugin de
WordPress, que es donde sí puedes desarrollar.

---

## 2. El problema actual

Hoy (ver [`../README.md`](../README.md) §2.4):

- Login con usuario + contraseña en **texto plano** (`stic_pa_username_c` / `stic_pa_password_c`).
- "He olvidado mi contraseña" **envía la contraseña en claro por email**.
- La query de login concatena valores sin escapar → riesgo de **inyección**.
- Para familias que solo quieren inscribir a sus hijos, recordar usuario/contraseña es **fricción
  pura** y fuente de soporte ("no me acuerdo de la contraseña").

Objetivo: que el acceso sea **un clic** y, a la vez, **más seguro** que lo actual.

---

## 3. Tu propuesta (resumen)

- Campo custom token (UID largo) en cada ficha de contacto.
- URL con ese token → reconozco al usuario y le hago login automático.
- En el panel de ajustes del plugin (que ya existe): botón para **regenerar tokens** (individual
  o masivo), **ver** el token de alguien, y **entrar como** ese usuario haciendo clic.

Es buena base. Las mejoras de abajo son para hacerla "lo mejor posible", no para descartarla.

---

## 4. Análisis de seguridad: "token permanente en la URL"

Un token permanente que da acceso total y viaja en la URL es un **bearer token**, y la URL es el
sitio menos privado del mundo. Se filtra por:

- **Historial del navegador** y autocompletado.
- **Cabecera `Referer`** (si la página enlaza a terceros, el token se va con la petición).
- **Logs** del servidor, proxies, CDNs, antivirus corporativos.
- **Reenvío del email** a otra persona, capturas, enlaces compartidos por WhatsApp.

Si ese token es **permanente** y por sí solo concede acceso, un email filtrado = **toma de
control indefinida** de la cuenta. No es catastrófico para un área de inscripciones (no es banca),
pero se mitiga fácil. Mitigaciones clave:

1. **Entropía alta**: 128 bits mínimo. `bin2hex(random_bytes(16))` (32 hex) o `wp_generate_uuid4()`.
   Nunca secuencial ni predecible. Con esa entropía, **adivinarlo por fuerza bruta es inviable**.
2. **Convertir el token en sesión cuanto antes**: al primer clic válido, crear la cookie de sesión
   y **redirigir a una URL limpia** (sin el token). A partir de ahí navega con cookie, no con token.
3. **Revocable**: regenerar el token invalida los enlaces antiguos (es tu "cerrar sesión a distancia").
4. **Solo HTTPS**, cookies `Secure` + `HttpOnly` + `SameSite=Lax`.
5. **Caducidad donde se pueda** (ver el nivel 2 de magic links firmados).

---

## 5. Diseño recomendado (dos niveles)

### Nivel 1 — Token permanente (`stic_pa_token_c`)
- Campo custom en Contacts/Accounts. Se genera desde WordPress.
- **Uso A — enlace al pie de los emails de comunicación:** `https://tuweb/area-privada/?stic_token=XXts`.
  Por naturaleza es de larga vida; lo asumimos pero lo hacemos revocable y lo convertimos en sesión al primer clic.
- **Uso B — impersonación desde el admin** (entrar como un usuario).

### Nivel 2 — Magic link firmado y caducable (sin tocar el CRM)
Para el flujo "introduce tu email y te mando acceso" (sustituto del password-reset actual):

- El usuario introduce su email → WordPress busca el contacto → si existe, genera un enlace
  **firmado** y con **caducidad**, y lo envía por email.
- El enlace **no guarda nada nuevo en el CRM**: es autovalidable con un secreto que vive en WordPress.

```php
// Generar (al pedir acceso)
$payload = $contactId . '|' . (time() + 7*DAY_IN_SECONDS);     // id + expiración
$sig     = hash_hmac('sha256', $payload, MAGIC_SECRET);         // secreto guardado en wp_options
$magic   = rtrim(strtr(base64_encode($payload.'|'.$sig), '+/', '-_'), '=');
// → enlace: .../area-privada/?magic={$magic}

// Validar (al hacer clic)
[$contactId, $exp, $sig] = explode('|', base64_decode(...));
if (hash_equals(hash_hmac('sha256', "$contactId|$exp", MAGIC_SECRET), $sig) && time() < $exp) {
    // login del contacto $contactId
}
```

- **Caducidad** integrada (p.ej. 7 días) → un email viejo deja de servir.
- **Revocación masiva**: rotando `MAGIC_SECRET` invalidas todos los magic links de golpe.
- Para **revocación por usuario** + caducidad a la vez: incluir en el payload un contador/versión
  guardado en el CRM (`stic_pa_token_version_c`); si no coincide, el enlace ya no vale.

### Flujo unificado de login (en `sugar_crm_portal_check_user_and_login` o un hook `init`)
```
¿URL trae ?stic_token=  → buscar contacto por stic_pa_token_c
¿URL trae ?magic=       → validar firma + caducidad → contacto
        │ encontrado
        ▼
crear $_SESSION (igual que PortalLogin hoy) → redirect a URL limpia (sin token)
```
Reaprovecha casi todo `PortalLogin()` cambiando solo el `WHERE` de la query.

---

## 6. Implementación en WordPress (sin desarrollar en SinergiaCRM)

1. **CRM (solo Studio, sin código):** crear campo `stic_pa_token_c` (texto, único) en
   Contacts/Accounts. Opcional: `stic_pa_token_version_c` (entero) para revocación fina.
2. **Plugin – login:** nuevo handler en `init` que detecte `?stic_token=` / `?magic=`, valide y
   monte la sesión (reutilizando la lógica de `PortalLogin`). Añadir `wp_safe_redirect` a URL limpia.
3. **Plugin – generación de tokens:** función que para un contacto haga `set_entry` con
   `stic_pa_token_c = bin2hex(random_bytes(16))`.
4. **Plugin – secreto HMAC:** generar y guardar `MAGIC_SECRET` en `wp_options` la primera vez.
5. **Seguridad transversal:** forzar HTTPS, cookies seguras, **escapar** la query del CRM, y
   añadir **nonces/CSRF** a las acciones admin.

> ⚠️ **Cuidado con `CURLOPT_SSL_VERIFYPEER => 0`** en `inc/stic-class-6.php`: hoy la conexión al
> CRM **no verifica el certificado TLS** (vulnerable a man-in-the-middle). Si vamos a apoyar la
> autenticación en esta conexión, conviene arreglarlo (tarea `SEC-04`).

---

## 7. Panel de administración (regenerar / ver / impersonar)

En el panel de ajustes existente del plugin (o una subpágina nueva), añadir:

- **Buscador de usuarios** (por nombre/email) que consulte el CRM (`get_entry_list`).
- Por cada usuario: **Ver token**, **Regenerar token**, **Entrar como** (abre `?stic_token=` o, mejor,
  genera un magic link de un solo uso y corta vida).
- **Regenerar todos** (masivo) — con aviso claro: *invalida todos los enlaces de emails ya enviados*.

### Seguridad de la impersonación (importante)
- Restringir a capability `manage_options` / rol administrador (`current_user_can`).
- **Registrar (audit log)** cada impersonación: quién, a quién, cuándo.
- Mostrar un **banner visible** "Estás viendo el área como NOMBRE" durante la sesión impersonada.
- Preferir generar un **enlace de un solo uso y caducidad corta** para "entrar como", en vez de
  exponer el token permanente en una tabla del admin (cualquier admin podría copiarlo).
- Proteger las acciones con **nonce** para evitar CSRF.

---

## 8. Integración con los emails de comunicación

El "enlace al pie de todos los emails" depende de **desde dónde** envías esos emails:

- Si salen de **campañas/plantillas de SinergiaCRM**: como puedes usar **campos** (aunque no
  programar), inserta el campo `stic_pa_token_c` (o un campo "URL de portal" precalculado) como
  **mail-merge** en la plantilla, construyendo el enlace `base + token`.
- Si salen de **WordPress / otra herramienta**: construyes el enlace en el envío.

> Recomendación: guardar en el CRM directamente un campo calculado/poblado con la **URL completa**
> (`stic_pa_portal_url_c`) además del token, para que en las plantillas solo haya que arrastrar un
> campo y no concatenar.

---

## 9. Alternativas valoradas (y por qué la propuesta gana)

| Opción | UX | Seguridad | Encaje (no-code en CRM) | Veredicto |
|--------|----|-----------|--------------------------|-----------|
| **Token permanente + magic links firmados** (propuesta) | ⭐⭐⭐ 1 clic | ⭐⭐ buena con mitigaciones | ✅ solo campo Studio | **Recomendada** |
| OTP por email (código de 6 dígitos) | ⭐⭐ algo de fricción | ⭐⭐⭐ alta (corta vida, un uso) | ✅ | Buena para acciones sensibles; peor UX para "inscribir hijo" |
| Magic link HMAC **sin** campo en CRM | ⭐⭐⭐ | ⭐⭐⭐ caduca solo | ✅ no necesita ni campo | Genial para "pedir acceso", pero **sin** enlace permanente para emails |
| IdP externo (Auth0/Supabase/Clerk) | ⭐⭐ | ⭐⭐⭐ | ❌ sobra complejidad, CRM sigue siendo la fuente | Descartada (overkill) |

La propuesta combina lo mejor: enlace permanente para comunicaciones masivas **y** magic links
seguros para acceso bajo demanda, todo con un solo campo nuevo en el CRM.

---

## 10. Plan de implementación por fases

- **Fase 1 (MVP, P0):** campo `stic_pa_token_c` + login por `?stic_token=` + redirect a URL limpia
  + generar token por usuario desde el admin. *(Ya se puede usar en emails.)*
- **Fase 2 (P0/P1):** magic links firmados con caducidad para "pedir acceso por email"
  (sustituye al forgot-password que manda la contraseña en claro).
- **Fase 3 (P1):** panel admin completo: buscador, ver/regenerar (individual y masivo), "entrar
  como" con enlace de un solo uso, audit log y banner de impersonación.
- **Fase 4 (P1/P2):** retirar (o dejar opcional) el login por contraseña en claro y endurecer
  seguridad transversal (`SEC-*`).

Ver tareas desglosadas en [`../TODO.md`](../TODO.md).
