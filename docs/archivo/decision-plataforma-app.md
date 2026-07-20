# Archivado: análisis y decisión sobre la futura app (Expo / nativo / webview)

> **Este documento está archivado.** Se conserva solo como registro histórico de la
> discusión y las opciones evaluadas. **No es necesario leerlo para desarrollar** en el
> día a día del plugin — de ahí que esté fuera de `docs/` en esta carpeta `archivo/`.

---

## Decisión final (2026-07)

Tras evaluar las opciones de abajo, la decisión tomada es:

> **La futura app será un wrapper Expo con una WebView que carga el área privada PHP
> actual** (este mismo plugin, en modo `?app=1` — ver [`../design-system.md`](../design-system.md)
> y el flag `sticpa_is_app_mode()` en `sinergiacrm-private-area.php`). **No se construye
> ningún BFF, endpoint REST nuevo, ni paquete `core` en TypeScript.** El plugin PHP sigue
> siendo la única implementación funcional, tanto para web como para "app".
>
> Queda abierta, **como posibilidad futura y sin compromiso de fecha**, la opción de
> reconstruir partes en nativo (React Native/Expo de verdad, no webview) si en algún
> momento compensa. Si eso llega a pasar, el análisis de abajo sigue siendo un buen punto
> de partida.

Por tanto:
- Las tareas `PLAT-01` (BFF/endpoints REST), `PLAT-02` (paquete `core` TS) y `PLAT-04`
  (motor de formularios en React) **se retiran del roadmap activo** (`TODO.md`). No se
  necesitan para el enfoque de webview.
- No hay trabajo pendiente de "migración" que hacer ahora mismo. Si se retoma la idea de
  ir a nativo en el futuro, este documento y sus estimaciones siguen siendo válidos como
  punto de partida.

---

## Análisis original (para contexto histórico)

*A partir de aquí, el contenido es el análisis original tal cual se escribió cuando la
decisión todavía estaba abierta. Habla en algunos puntos de "decidir" entre opciones;
eso ya está resuelto arriba.*

### 1. TL;DR / Recomendación (original)

- **El código PHP actual NO se reaprovecha como código** en Expo (PHP ≠ React Native/TS). **Pero
  sí es un "spec funcional" buenísimo**: te dice exactamente qué llamadas a la API del CRM hacer,
  con qué parámetros, qué campos, y qué reglas de negocio aplicar. Reconstruir partiendo de él es
  mucho más rápido que partir de cero.
- **Hay un obstáculo de arquitectura crítico:** una app Expo (cliente) **no puede llevar dentro el
  usuario/contraseña de servicio del CRM**. Hoy WordPress hace de **proxy de confianza** que
  guarda esas credenciales. En Expo necesitas un **backend intermedio (BFF)** que las custodie.
  La buena noticia: **ese BFF puede ser el propio plugin de WordPress** exponiendo endpoints REST,
  reutilizando el cliente PHP que ya tienes.
- **Sobre las opciones evaluadas:** todas eran válidas. La **Opción 2** (todo en Expo, monorepo, y un
  *target web "lite"* que solo empaqueta el área privada) **es técnicamente posible** y era la más
  elegante a largo plazo, pero exige disciplina de arquitectura y vigilar el **peso del bundle web**
  (el punto que justo preocupaba para las familias). La **Opción 1** (mantener PHP para web/familias
  + app nativa aparte) era la de **menor esfuerzo y riesgo inmediato**.

**Recomendación pragmática que se barajó:** no duplicar la lógica difícil. Extraer un **paquete
"core" en TypeScript** (cliente del CRM + tipos + reglas) compartido, y dejar que **WordPress
siguiera siendo la puerta de acceso segura** al CRM mientras tanto. *(Al final, la decisión tomada
fue más simple: webview sobre el PHP existente, sin core ni BFF — ver arriba.)*

### 2. ¿Qué del código actual sirve y qué no?

#### ✅ Sirve (como referencia, muy valioso)
- **Mapa de la API del CRM** (`inc/stic-class-6.php`): métodos `login`, `get_entry_list`,
  `set_entry`, `get_relationships`, `get_module_fields`, `set_document_revision`, `set_image`,
  `get_entry`… con sus parámetros exactos. Esto es lo caro de averiguar y ya está resuelto.
- **Modelo de datos y nombres de campos** (`stic_pa_username_c`, `stic_pa_password_c`, módulos
  `Contacts`/`Accounts`, relaciones `stic_personal_environment_*`, etc.).
- **Reglas de negocio**: tutor/menor (`RELATIONSHIP_TUTOR_TYPES`, `check_user_adult`), subida de
  documentos en base64 + `DocumentRevision`, inscripciones, pagos, etc.
- **El concepto del motor de formularios declarativo** (`makeForm` en `inc/stic-formController.php`):
  defines campos como datos y se renderizan solos mezclando con la definición del CRM. Esta idea se
  trasladaría de maravilla a React (un `<DynamicForm schema={...} />`) si algún día se va a nativo.
- **Los flujos UX**: login, signup, listados, detalle, cambio de contraseña, recuperación.

#### ❌ No serviría (habría que reescribir, si se fuera a nativo)
- Todo el **HTML renderizado en PHP** y la capa de presentación (`pages/*.php`, `menu.php`).
- Las **llamadas cURL** → se reescribirían con `fetch` en TS.
- El **manejo de sesión** vía `$_SESSION` de PHP → en Expo se usaría almacenamiento seguro + tokens.
- El **CSS** (aunque la guía de diseño y la paleta de [`../../README.md`](../../README.md) §6 sí
  orientarían el look en React Native con StyleSheet / NativeWind).

**Conclusión:** el código es un **plano** excelente, no una pieza a copiar. Si algún día se
reconstruye en nativo, partir de él sería bastante más rápido que empezar en blanco.

### 3. El obstáculo de las credenciales (clave para la decisión)

Hoy el flujo es: **navegador → WordPress (guarda user/pass del CRM) → CRM**. WordPress es un
**servidor de confianza**; las credenciales de servicio nunca llegan al cliente.

En una app Expo *nativa de verdad* (no webview), el cliente es el dispositivo del usuario. Si se
metiera ahí el usuario/contraseña de servicio del CRM, cualquiera podría extraerlos (decompilando
el APK o mirando el bundle web). Por tanto haría falta un **backend intermedio (BFF, *Backend For
Frontend*)** que custodiara las credenciales y expusiera solo endpoints acotados.

*(Con la decisión de webview, este problema no aplica: la WebView sigue cargando el PHP tal cual,
que sigue siendo el único que habla con el CRM.)*

#### Opciones de BFF que se barajaron
| BFF | Pros | Contras |
|-----|------|---------|
| **El plugin WordPress actual exponiendo REST** (`register_rest_route`) | Reutiliza el cliente PHP ya hecho; una sola fuente de acceso al CRM; mínimo trabajo nuevo | Acoplas el backend a WordPress |
| **Serverless** (Expo API Routes, Vercel/Cloudflare Functions, Supabase Edge) | Moderno, escala, cerca del stack Expo | Reescribes el cliente del CRM en TS; otra pieza que mantener |
| **API Node/Nest dedicada** | Control total | Más infra y mantenimiento |

### 4. Las opciones que se evaluaron

#### Opción 1 — Mantener PHP web (familias) + app nativa Expo separada
- **Idea:** la web de inscripciones sigue en el plugin PHP (ligero, ya funciona); la app nativa es
  un repo aparte sin código compartido.
- **Pros:** menor esfuerzo y riesgo inmediato; la web server-rendered es **muy ligera** para
  familias con móviles modestos o datos limitados (ventaja real frente a una SPA); separación clara.
- **Contras:** **dos bases de código** y lógica duplicada (la del CRM); riesgo de **divergencia**
  funcional con el tiempo.

#### Opción 2 — Todo en Expo + extraer un *target web "lite"* del mismo repo
- **Idea:** una sola app Expo (monorepo). De ahí se compila una **web reducida** que solo incluye
  las pantallas del área privada y se despliega como app separada, pero **mantenida en el mismo
  repo**.
- **Pros:** un solo ecosistema, lógica compartida (no se duplica lo caro), coherencia total.
- **Contras / riesgos:**
  - **Peso del bundle web.** Aun "lite", **React Native Web pesa más** que HTML server-rendered.
    Para familias, el *first paint* y el tamaño importan. Mitigable con *code splitting* agresivo,
    *lazy loading*, y **SSR/estático**, pero no llegaría a ser tan liviano como el PHP actual.
  - Disciplina de arquitectura para que la web lite no "arrastre" dependencias de la app completa.

#### Opción 3 — Monorepo con `core` compartido + targets independientes
- Un paquete **`core` (TS)**: cliente del CRM, tipos, validaciones, reglas (tutor/menor, etc.).
- Consumido por: (a) **app nativa Expo**, (b) **web ligera**.
- **El CRM nunca se toca desde el cliente**: detrás está el BFF (WordPress REST o serverless).

### 5. Arquitectura que se dibujó para las opciones 2/3 (no implementada)

```
                 ┌──────────────────────────┐
                 │   Paquete  core  (TS)     │  cliente CRM + tipos + reglas
                 └────────────┬─────────────┘
              ┌───────────────┼────────────────┐
              ▼               ▼                 ▼
     App Expo nativa     Web "lite"        (futuro: otros)
     (iOS/Android)       (familias)
              │               │
              └───────┬───────┘
                      ▼
        BFF seguro  (WordPress REST  ó  serverless)
                      │  custodia credenciales del CRM
                      ▼
               SinergiaCRM (SuiteCRM) — API REST v4.1
```

### 6. Estimación de esfuerzo que se manejó (opciones 2/3, no aplica al webview)

Suponiendo un desarrollador competente y reaprovechando el código actual como spec:

| Bloque | Esfuerzo aprox. |
|--------|-----------------|
| Cliente CRM + tipos en TS (a partir de `stic-class-6.php`) | 1–2 semanas |
| BFF seguro (si se reusa WordPress REST, poco; si serverless, más) | 0.5–1.5 semanas |
| Autenticación token/magic link | ~1 semana |
| Motor de formularios declarativo en React (port de `makeForm`) | 1–2 semanas |
| Pantallas por módulo (eventos, inscripciones, documentos, pagos, perfil…) | 3–5 semanas |
| Subida/descarga de archivos (base64 / DocumentRevision) | 2–4 días |
| i18n + calendario (FullCalendar → `react-native-calendars` u otro) | ~1 semana |
| Target web "lite" + control de bundle/SSR | 1–2 semanas |
| **Total plataforma completa** | **~2–3 meses** |
| **MVP** (login por token + eventos + inscripciones + documentos) | **~3–4 semanas** |

### 7. Riesgos que se identificaron (opciones 2/3)

1. **Peso del bundle web** para familias. Server-rendered gana en ligereza; una SPA RN-Web siempre
   sería más pesada.
2. **Seguridad de credenciales**: imposible sin BFF.
3. **Paridad funcional**: el plugin tiene muchos módulos; replicar todo sería largo.
4. **Mantenimiento doble** si se elegía Opción 1 sin `core` compartido.
5. **`CURLOPT_SSL_VERIFYPEER => 0`** y demás deudas de seguridad heredadas (esto sigue vivo,
   ver `SEC-04` en `TODO.md`, independientemente de la decisión de plataforma).
