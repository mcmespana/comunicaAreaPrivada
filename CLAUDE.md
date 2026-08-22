# Notas para agentes — comunicaAreaPrivada

Plugin de WordPress que monta el **área privada de Comunica (MCM)** sobre una
instancia de SinergiaCRM (SuiteCRM deslucrativizado). Se accede desde el
navegador y desde **MCM App**, que es una webview: lo que se rompa en móvil se
rompe en la app.

---

## Los campos del CRM: `docs/comunica/CAMPOS.md` es la fuente de la verdad

**Antes de dar por bueno el nombre de un campo del CRM, mira
[`docs/comunica/CAMPOS.md`](docs/comunica/CAMPOS.md).** Es EL documento de los
campos: nombres técnicos, etiquetas, tipos y los valores internos de cada
desplegable. Se mantiene a mano y nos fiamos de lo que dice.

Reglas:

- **No inventes ni supongas nombres de campo.** Si no está en `CAMPOS.md`,
  pregunta antes de usarlo.
- **No crees un campo nuevo sin comprobar que no existe ya.** Es fácil acabar
  con dos campos para lo mismo (p. ej. el nivel personal del COM ya existe como
  `ajmcm_nivel_com_c`; un «segmento» del grupo es otra cosa distinta).
- **Si `CAMPOS.md` cambia, hay que subir el cambio también al repo
  `comunicaFormularios`**, que es donde viven los formularios públicos que
  escriben en esos mismos campos. Si se toca aquí y no allí, los formularios
  empiezan a escribir en campos que ya no son.
- Cuando encuentres una contradicción entre `CAMPOS.md` y los datos reales del
  CRM, **dilo** en vez de elegir por tu cuenta.

Algún día verificaremos `CAMPOS.md` contra el CRM por MCP. Hasta entonces, el
documento manda.

---

## Consultas al CRM por MCP: con cuidado

El MCP de SinergiaCRM devuelve respuestas enormes y **consume muchísimo
contexto**. Reglas:

- Usa siempre `fields` para acotar lo que pides. Sin `fields`, la API devuelve
  todos los campos del módulo.
- Agrupa: una llamada por colección, nunca una por fila.
- Si hace falta explorar mucho, **lanza un subagente** (Sonnet, esfuerzo bajo)
  que haga las llamadas y devuelva solo el dato mínimo, en vez de meter cientos
  de líneas de JSON en el hilo principal.
- No hay herramienta de borrado: se borra con `update_entry` poniendo
  `deleted: true` (borrado lógico de SuiteCRM).
- **La API no valida los desplegables**: acepta cualquier cadena. Si no conoces
  la clave interna exacta de un enum, no te la inventes — mírala en `CAMPOS.md`
  o pregunta.

---

## Convenciones del proyecto

- **Diseño**: [`docs/design-system.md`](docs/design-system.md) es de lectura
  obligada antes de tocar pantallas. Tokens en `css/custom-style.css` §1;
  componentes que ya existen, se reutilizan.
- **Todo lo que se cree en el CRM va asignado a su delegación**
  (`assigned_user_id` = el usuario de la delegación), porque de ahí cuelga el
  grupo de seguridad y así cada delegación controla lo suyo. Un monitor solo ve
  lo de su delegación.
- **Nada interdelegacional.**

---

## Documentación por funcionalidad

| Tema | Documento |
|---|---|
| Campos del CRM (**la fuente de la verdad**) | `docs/comunica/CAMPOS.md` |
| Eventos e inscripciones | `docs/comunica/EVENTOS.md` |
| Pasar Lista — diseño funcional | `docs/comunica/PASAR-LISTA.md` |
| Pasar Lista — campos del CRM | `docs/comunica/PASAR-LISTA-CAMPOS-CRM.md` |
| Pasar Lista — fases y futuro | `docs/comunica/PASAR-LISTA-ROADMAP.md` |
| Contrato con MCM App (webview) | `docs/comunica/CONTRATO-APP-WEBVIEW.md` |
| Sistema de diseño | `docs/design-system.md` |
