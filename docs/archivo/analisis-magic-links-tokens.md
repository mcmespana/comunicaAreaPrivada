# Análisis: Autenticación por Token / Magic Links

> ## ✅ IMPLEMENTADO (documento archivado)
> El núcleo de este análisis **ya está desarrollado** (tareas `AUTH-01..04`, `SEC-01` y
> `ADMIN-01..03`). El funcionamiento real y cómo configurarlo está en
> [`../README.md`](../../README.md) §8. Este documento se conserva como **registro del diseño y las
> decisiones** (el porqué). Lo que queda pendiente (endurecer impersonación, retirar el login por
> contraseña, `SEC-02..06`) sigue en [`../TODO.md`](../../TODO.md).
>
> Implementación principal en [`../inc/stic-magic-login.php`](../../inc/stic-magic-login.php).

---

> **Estado:** ✅ implementado (núcleo). Mejoras de endurecimiento pendientes en el TODO.
> **Prioridad:** 🔴 **P0 — la más alta** (resuelve a la vez seguridad y UX)
> **Tamaño estimado:** M (núcleo funcional en ~2-4 días; panel admin completo, algo más)
> **Relacionado:** tareas `SEC-*` y `AUTH-*` de [`../TODO.md`](../../TODO.md)

---

## 1. TL;DR / Recomendación

**Sí, tu idea es viable y eficiente, y la recomiendo como primer desarrollo.** Pero conviene
mejorarla con un diseño de **dos niveles** para no convertir cada email en una llave maestra
permanente y eterna:

1. **Token permanente por contacto** (campo custom `ajmcm_pa_token_c` en el CRM) → para el enlace
   "siempre disponible" al pie de los emails (`?token=xxxxx`) y para la **impersonación** desde el admin.
2. **Acceso mágico firmado y caducable** (generados en WordPress con HMAC, **sin guardar nada en el
   CRM**) → para el flujo "introduce tu email y te mando acceso" (`?acceso_magico=xxxxx`).

Ambos desembocan en lo mismo: al validarse, se crea la **sesión PHP normal** (cookie) y se
**limpia el token de la URL**. Así el token solo viaja una vez.

**Quién genera y dónde vive cada cosa:**
- **WordPress genera los tokens** (un string aleatorio seguro) y los **escribe en la ficha del
  contacto vía API** (`set_entry`, la misma llamada que ya usa el plugin). SinergiaCRM no hace
  nada: solo almacena el valor. Toda la lógica vive en el plugin de WordPress.
- **No hace falta username/password.** El login por token busca el contacto por el campo del token
  (`WHERE ajmcm_pa_token_c = '...'`), **sin mirar** los campos de usuario/contraseña. Es un sistema
  de acceso **completo y alternativo**: de hecho permite **jubilar** el login por contraseña (y con
  él, el problema del password en claro). El único requisito es que cada contacto **tenga su token
  generado** (lo cubre el botón de generación masiva para los existentes + autogeneración para los
  nuevos).

**Naming de los campos:** como esto es **vuestro desarrollo** (no de SinergiaTIC), los campos
llevan el prefijo **`ajmcm_`**, no `stic_`. En SuiteCRM, Studio añade el sufijo `_c`
automáticamente, así que quedan como `ajmcm_pa_token_c`. Esto **NO requiere programar dentro de
SinergiaCRM**: crear campos con Studio es **configuración, no código**.

---

## 2. El problema actual

Hoy (ver [`../README.md`](../../README.md) §2.4):

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
5. **Caducidad donde se pueda**: el permanente no caduca (es su gracia para los emails), pero el
   magic link bajo demanda sí, y pronto (ver el nivel 2).

---

## 5. Diseño recomendado (dos niveles)

### Nivel 1 — Token permanente (`ajmcm_pa_token_c`)
- Campo custom en Contacts/Accounts. **Lo genera WordPress** y lo guarda en el CRM vía API.
- **Uso A — botón "Acceder a mi área privada" al pie de los emails:**
  `https://tuweb/area-privada/?token=XXX`. Un clic y dentro, sin recordar nada. Por
  naturaleza es de larga vida; lo asumimos, pero lo hacemos **revocable** y lo convertimos en sesión
  al primer clic.
- **Uso B — impersonación desde el admin** (entrar como un usuario).

### Nivel 2 — Magic link firmado y caducable (sin tocar el CRM)
Para el flujo "introduce tu email y te mando acceso" (sustituto del password-reset actual):

- El usuario pide acceso → WordPress busca su contacto → coge el `email1` que tiene en el CRM →
  genera un enlace **firmado** y con **caducidad corta**, y se lo envía a ese correo.
- El enlace **no guarda nada nuevo en el CRM**: es autovalidable con un secreto que vive en WordPress.

```php
// Generar (al pedir acceso) — caducidad corta, p.ej. 1 hora
$payload = $contactId . '|' . (time() + HOUR_IN_SECONDS);      // id + expiración
$sig     = hash_hmac('sha256', $payload, MAGIC_SECRET);         // secreto guardado en wp_options
$magic   = rtrim(strtr(base64_encode($payload.'|'.$sig), '+/', '-_'), '=');
// → enlace: .../area-privada/?acceso_magico={$magic}

// Validar (al hacer clic)
[$contactId, $exp, $sig] = explode('|', base64_decode(...));
if (hash_equals(hash_hmac('sha256', "$contactId|$exp", MAGIC_SECRET), $sig) && time() < $exp) {
    // login del contacto $contactId
}
```

- **Caducidad corta y configurable** (recomendado **~1 hora**, ya que el usuario lo acaba de pedir
  y tiene el correo delante) → un enlace viejo deja de servir enseguida.
- **Revocación masiva**: rotando `MAGIC_SECRET` invalidas todos los magic links de golpe.
- Para **revocación por usuario** + caducidad a la vez: incluir en el payload un contador/versión
  guardado en el CRM (`ajmcm_pa_token_version_c`); si no coincide, el enlace ya no vale.

### Flujo unificado de login (en `sugar_crm_portal_check_user_and_login` o un hook `init`)
```
¿URL trae ?token=  → buscar contacto por ajmcm_pa_token_c
¿URL trae ?acceso_magico=  → validar firma + caducidad → contacto
        │ encontrado
        ▼
crear $_SESSION (igual que PortalLogin hoy) → redirect a URL limpia (sin token)
```
Reaprovecha casi todo `PortalLogin()` cambiando solo el `WHERE` de la query.

---

## 6. Implementación en WordPress (sin desarrollar en SinergiaCRM)

### Campos a crear en el CRM (con Studio, solo configuración)
| Campo | Tipo | ¿Imprescindible? | Para qué |
|-------|------|------------------|----------|
| `ajmcm_pa_token_c` | Texto (único) | ✅ **Sí (el único obligatorio)** | Token permanente del enlace de los emails + impersonación |
| `ajmcm_pa_token_version_c` | Entero | Opcional | Revocar magic links de un usuario concreto sin tocar el permanente |
| `ajmcm_pa_portal_url_c` | Texto/URL | Opcional | URL completa precalculada para arrastrar como mail-merge en plantillas |

> El **segundo token (el magic link) NO necesita campo en el CRM**: va firmado con HMAC y se valida
> solo en WordPress. Por tanto, **con 1 solo campo** (`ajmcm_pa_token_c`) ya tienes el sistema base.

### Pasos en el plugin
1. **CRM (solo Studio, sin código):** crear `ajmcm_pa_token_c` (y opcionalmente los otros dos).
2. **Plugin – login:** nuevo handler en `init` que detecte `?token=` / `?acceso_magico=`,
   valide y monte la sesión (reutilizando la lógica de `PortalLogin`). Añadir `wp_safe_redirect` a
   URL limpia.
3. **Plugin – generación de tokens:** función que para un contacto haga `set_entry` con
   `ajmcm_pa_token_c = bin2hex(random_bytes(16))`. Funciona aunque el contacto **no tenga**
   username/password.
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
- Por cada usuario: **Ver token**, **Regenerar token**, **Entrar como** (abre `?token=` o, mejor,
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
  programar), inserta el campo `ajmcm_pa_token_c` (o un campo "URL de portal" precalculado) como
  **mail-merge** en la plantilla, construyendo el enlace `base + token`.
- Si salen de **WordPress / otra herramienta**: construyes el enlace en el envío.

> Recomendación: guardar en el CRM directamente un campo calculado/poblado con la **URL completa**
> (`ajmcm_pa_portal_url_c`) además del token, para que en las plantillas solo haya que arrastrar un
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
seguros para acceso bajo demanda, todo con **un solo campo nuevo** en el CRM (`ajmcm_pa_token_c`).

---

## 10. Plan de implementación por fases

- **Fase 1 (MVP, P0):** campo `ajmcm_pa_token_c` + login por `?token=` + redirect a URL limpia
  + generar token por usuario desde el admin. *(Ya se puede usar en emails.)*
- **Fase 2 (P0/P1):** magic links firmados con caducidad para "pedir acceso por email"
  (sustituye al forgot-password que manda la contraseña en claro).
- **Fase 3 (P1):** panel admin completo: buscador, ver/regenerar (individual y masivo), "entrar
  como" con enlace de un solo uso, audit log y banner de impersonación.
- **Fase 4 (P1/P2):** retirar (o dejar opcional) el login por contraseña en claro y endurecer
  seguridad transversal (`SEC-*`).

Ver tareas desglosadas en [`../TODO.md`](../../TODO.md).
