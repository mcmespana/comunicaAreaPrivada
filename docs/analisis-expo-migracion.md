# Análisis: Migración / reconstrucción en Expo (Android · iOS · Web)

> **Estado:** análisis para decidir estrategia (sin implementación todavía)
> **Prioridad:** 🟡 P2 — proyecto grande; decidir dirección antes de invertir
> **Tamaño estimado:** L (proyecto de semanas/meses según alcance)
> **Relacionado:** tareas `PLAT-*` de [`../TODO.md`](../TODO.md)

---

## 1. TL;DR / Recomendación

- **El código PHP actual NO se reaprovecha como código** en Expo (PHP ≠ React Native/TS). **Pero
  sí es un "spec funcional" buenísimo**: te dice exactamente qué llamadas a la API del CRM hacer,
  con qué parámetros, qué campos, y qué reglas de negocio aplicar. Reconstruir partiendo de él es
  mucho más rápido que partir de cero.
- **Hay un obstáculo de arquitectura crítico:** una app Expo (cliente) **no puede llevar dentro el
  usuario/contraseña de servicio del CRM**. Hoy WordPress hace de **proxy de confianza** que
  guarda esas credenciales. En Expo necesitas un **backend intermedio (BFF)** que las custodie.
  La buena noticia: **ese BFF puede ser el propio plugin de WordPress** exponiendo endpoints REST,
  reutilizando el cliente PHP que ya tienes.
- **Sobre tus dos opciones:** ambas son válidas. La **Opción 2** (todo en Expo, monorepo, y un
  *target web "lite"* que solo empaqueta el área privada) **es técnicamente posible** y es la más
  elegante a largo plazo, pero exige disciplina de arquitectura y vigilar el **peso del bundle web**
  (el punto que justo te preocupa para las familias). La **Opción 1** (mantener PHP para web/familias
  + app nativa aparte) es la de **menor esfuerzo y riesgo ahora**.

**Mi recomendación pragmática:** no dupliques la lógica difícil. Extrae un **paquete "core" en
TypeScript** (cliente del CRM + tipos + reglas) compartido, y deja que **WordPress siga siendo la
puerta de acceso segura** al CRM mientras tanto. Decide web ligera (Opción 2 con bundle controlado)
solo si te compensa unificar todo en un ecosistema.

---

## 2. ¿Qué del código actual sirve y qué no?

### ✅ Sirve (como referencia, muy valioso)
- **Mapa de la API del CRM** (`inc/stic-class-6.php`): métodos `login`, `get_entry_list`,
  `set_entry`, `get_relationships`, `get_module_fields`, `set_document_revision`, `set_image`,
  `get_entry`… con sus parámetros exactos. Esto es lo caro de averiguar y ya está resuelto.
- **Modelo de datos y nombres de campos** (`stic_pa_username_c`, `stic_pa_password_c`, módulos
  `Contacts`/`Accounts`, relaciones `stic_personal_environment_*`, etc.).
- **Reglas de negocio**: tutor/menor (`RELATIONSHIP_TUTOR_TYPES`, `check_user_adult`), subida de
  documentos en base64 + `DocumentRevision`, inscripciones, pagos, etc.
- **El concepto del motor de formularios declarativo** (`makeForm` en `inc/stic-formController.php`):
  defines campos como datos y se renderizan solos mezclando con la definición del CRM. Esta idea se
  traslada de maravilla a React (un `<DynamicForm schema={...} />`).
- **Los flujos UX**: login, signup, listados, detalle, cambio de contraseña, recuperación.

### ❌ No sirve (hay que reescribir)
- Todo el **HTML renderizado en PHP** y la capa de presentación (`pages/*.php`, `menu.php`).
- Las **llamadas cURL** → se reescriben con `fetch` en TS.
- El **manejo de sesión** vía `$_SESSION` de PHP → en Expo se usa almacenamiento seguro + tokens.
- El **CSS** (aunque la guía de diseño y la paleta de [`../README.md`](../README.md) §6 sí orientan
  el look en React Native con StyleSheet / NativeWind).

**Conclusión:** el código es un **plano** excelente, no una pieza a copiar. Reconstruir con él
delante es bastante más rápido que empezar en blanco.

---

## 3. El obstáculo de las credenciales (clave para decidir)

Hoy el flujo es: **navegador → WordPress (guarda user/pass del CRM) → CRM**. WordPress es un
**servidor de confianza**; las credenciales de servicio nunca llegan al cliente.

En una app Expo, **el cliente es el dispositivo del usuario**. Si metes ahí el usuario/contraseña
de servicio del CRM, **cualquiera puede extraerlos** (decompilando el APK o mirando el bundle web).
Por tanto **necesitas un backend intermedio (BFF, *Backend For Frontend*)** que:

- Custodie las credenciales del CRM.
- Exponga solo endpoints **seguros y acotados** a la app (login por token, listar mis
  inscripciones, subir documento…), aplicando permisos por usuario.
- Sea el sitio natural para implementar **tokens/magic links** (ver
  [`analisis-magic-links-tokens.md`](analisis-magic-links-tokens.md)), rate limiting, etc.

### Opciones de BFF
| BFF | Pros | Contras |
|-----|------|---------|
| **El plugin WordPress actual exponiendo REST** (`register_rest_route`) | Reutiliza el cliente PHP ya hecho; una sola fuente de acceso al CRM; mínimo trabajo nuevo | Acoplas el backend a WordPress |
| **Serverless** (Expo API Routes, Vercel/Cloudflare Functions, Supabase Edge) | Moderno, escala, cerca del stack Expo | Reescribes el cliente del CRM en TS; otra pieza que mantener |
| **API Node/Nest dedicada** | Control total | Más infra y mantenimiento |

> 💡 A corto plazo, **WordPress como BFF** es lo más barato: ya tiene el cliente del CRM y guarda
> las credenciales. La app Expo (nativa y web) consumiría esos endpoints REST.

---

## 4. Tus dos opciones, evaluadas

### Opción 1 — Mantener PHP web (familias) + app nativa Expo separada
- **Idea:** la web de inscripciones sigue en el plugin PHP (ligero, ya funciona); la app nativa es
  un repo aparte sin código compartido.
- **Pros:** menor esfuerzo y riesgo inmediato; la web server-rendered es **muy ligera** para
  familias con móviles modestos o datos limitados (ventaja real frente a una SPA); separación clara.
- **Contras:** **dos bases de código** y lógica duplicada (la del CRM); riesgo de **divergencia**
  funcional con el tiempo. Asumible **si la parte web se mantiene bastante estática**, como dices.

### Opción 2 — Todo en Expo + extraer un *target web "lite"* del mismo repo
- **Idea:** una sola app Expo (monorepo). De ahí se compila una **web reducida** que solo incluye
  las pantallas del área privada y se despliega como app separada, pero **mantenida en el mismo
  repo**.
- **¿Se puede?** **Sí.** Patrones para lograrlo:
  - **Monorepo** (workspaces) con un paquete `core` (cliente CRM + tipos + lógica) y paquetes de UI.
  - **Varios *targets* de build**: bien con **Expo Router** y *code splitting* por rutas, bien con
    **dos apps Expo** en el monorepo que comparten `core` (una "completa", otra "lite-web").
  - La web lite importa **solo** el módulo del área privada → bundle pequeño.
- **Pros:** un solo ecosistema, lógica compartida (no se duplica lo caro), coherencia total.
- **Contras / riesgos:**
  - **Peso del bundle web.** Aun "lite", **React Native Web pesa más** que HTML server-rendered. Es
    exactamente tu preocupación: para familias, el *first paint* y el tamaño importan. Mitigable con
    *code splitting* agresivo, *lazy loading*, y **SSR/estático** (Expo Router soporta render web),
    pero no llegará a ser tan liviano como el PHP actual.
  - Disciplina de arquitectura para que la web lite no "arrastre" dependencias de la app completa.

### Opción 3 (recomendada de fondo) — Monorepo con `core` compartido + targets independientes
- Un paquete **`core` (TS)**: cliente del CRM, tipos, validaciones, reglas (tutor/menor, etc.).
- Consumido por: (a) **app nativa Expo**, (b) **web ligera** (Expo Router web *o* incluso un
  Next.js mínimo si quieres SSR muy liviano para familias).
- **El CRM nunca se toca desde el cliente**: detrás está el BFF (WordPress REST o serverless).
- Te da lo mejor: **no duplicas la lógica difícil** y eliges la tecnología de cada *target* según
  su público (web familias = lo más liviano posible; app nativa = experiencia rica).

---

## 5. Arquitectura recomendada (vista de pájaro)

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

---

## 6. Estimación de esfuerzo (orientativa)

Suponiendo un desarrollador competente y reaprovechando el código actual como spec:

| Bloque | Esfuerzo aprox. |
|--------|-----------------|
| Cliente CRM + tipos en TS (a partir de `stic-class-6.php`) | 1–2 semanas |
| BFF seguro (si se reusa WordPress REST, poco; si serverless, más) | 0.5–1.5 semanas |
| Autenticación token/magic link (ver doc dedicado) | ~1 semana |
| Motor de formularios declarativo en React (port de `makeForm`) | 1–2 semanas |
| Pantallas por módulo (eventos, inscripciones, documentos, pagos, perfil…) | 3–5 semanas |
| Subida/descarga de archivos (base64 / DocumentRevision) | 2–4 días |
| i18n + calendario (FullCalendar → `react-native-calendars` u otro) | ~1 semana |
| Target web "lite" + control de bundle/SSR | 1–2 semanas |
| **Total parmeaselidad completa** | **~2–3 meses** |
| **MVP** (login por token + eventos + inscripciones + documentos) | **~3–4 semanas** |

---

## 7. Riesgos principales

1. **Peso del bundle web** para familias (el motivo de tu duda). Server-rendered gana en ligereza;
   una SPA RN-Web siempre será más pesada. → Si la web para familias es prioritaria y debe ser
   ultraligera, **Opción 1** (mantener PHP) o un **target web con SSR** muy podado.
2. **Seguridad de credenciales**: imposible sin BFF. No saltarse este paso.
3. **Paridad funcional**: el plugin tiene muchos módulos; replicar todo es largo. Priorizar MVP.
4. **Mantenimiento doble** si se elige Opción 1 sin `core` compartido.
5. **`CURLOPT_SSL_VERIFYPEER => 0`** y demás deudas de seguridad heredadas: arreglar antes de
   construir encima.

---

## 8. Decisión recomendada y primeros pasos

1. **No empieces por la migración completa.** Primero resuelve **autenticación por token/magic
   link** (P0) en el plugin actual: mejora ya la web de familias y deja el login listo para
   reutilizar desde cualquier cliente.
2. **Convierte el plugin en BFF** poco a poco: expón 2-3 endpoints REST (`/login-token`,
   `/me`, `/mis-inscripciones`) reutilizando el cliente PHP. Esto te permite **probar Expo contra
   datos reales sin reescribir el backend**.
3. **Crea el paquete `core` en TS** portando `stic-class-6.php`, y monta un **MVP Expo** (login por
   token + 1-2 módulos) para medir esfuerzo y, sobre todo, **el peso real del bundle web**.
4. Con esa medición decides entre **Opción 1** (web PHP se queda) u **Opción 2/3** (web lite desde
   Expo). La decisión será **basada en datos**, no en intuición.

> Resumen: el código actual es una **base conceptual excelente**, la reconstrucción en Expo es
> **viable**, y tu idea de "extraer una web lite del mismo repo" **se puede hacer** — el único
> "pero" serio es el **peso del bundle** para el público de familias, que es justo lo que hay que
> medir antes de comprometerse.
