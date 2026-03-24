# Flow Integration Fix — Vue 2/3 Mismatch

## Context

De MetaVox Flow integratie werkt niet: na selectie van "MetaVox metadata" + "where" verschijnt geen veldselector. Onderzocht op 3dev (NC33, 145.38.188.218).

## Root Cause (bevestigd op server)

**Nextcloud's WorkflowEngine draait op Vue 2** — bevestigd door `Vue.extend()`, `Vue.use()`, `Vue.set()`, `Vue.prototype` in de compiled bundle.

**MetaVox's flow bundle bevat Vue 3 render functions** — bevestigd: `openBlock()`, `createElementBlock()`, `createElementVNode()`, `withDirectives()`, `"onUpdate:modelValue"` (Vue 3 v-model).

WorkflowEngine rendert plugin checks met Vue 2's `createElement()`:
```javascript
h(currentOption.component, { tag: "component", attrs: { check, disabled }, on: { input: updateCheck } })
```
Een Vue 3 compiled component object werkt niet als argument voor Vue 2's `createElement`.

## Oplossing: Plain JS component met template string

Converteer `MetadataCheck.vue` naar een **plain JavaScript bestand** (`MetadataCheck.js`) dat een Vue 2-compatible Options API object exporteert met een inline `template` string. Dit vermijdt vue-loader template compilatie en werkt met elke Vue versie die runtime template compilatie ondersteunt.

**Waarom dit de beste aanpak is:**
- Geen Vue 2 dependency nodig in het project
- Geen aparte webpack config nodig
- De component wordt door WorkflowEngine's eigen Vue 2 instance gerenderd
- Template wordt at runtime gecompileerd door Vue 2 (dat standaard de full build met compiler is)
- Options API (`data`, `computed`, `methods`, `mounted`) is identiek in Vue 2 en Vue 3
- Toekomstbestendig: als NC WorkflowEngine naar Vue 3 migreert, werkt dezelfde code

## Implementatie

### Stap 1: Converteer MetadataCheck.vue → MetadataCheck.js

Maak `src/flow/MetadataCheck.js` met:
- `template: '<div class="metavox-check">...</div>'` — dezelfde HTML als de huidige .vue template
- Alle `data()`, `computed`, `methods`, `mounted`, `watch` — exact overgenomen
- CSS als string, geïnjecteerd via een `mounted()` hook of een aparte style inject
- Geen vue-loader nodig — plain JS object

### Stap 2: Update main.js

```javascript
import { translate as t } from '@nextcloud/l10n'
import MetadataCheck from './MetadataCheck.js'  // .js in plaats van .vue
```

### Stap 3: Verwijder flow entry uit vue-loader scope (optioneel)

De webpack config hoeft niet aangepast te worden als we geen .vue bestanden meer gebruiken in de flow entry. De js wordt gewoon door babel-loader verwerkt.

## Bestanden te wijzigen

| Bestand | Actie |
|---------|-------|
| `src/flow/MetadataCheck.js` | **Nieuw** — plain JS component met inline template |
| `src/flow/MetadataCheck.vue` | **Verwijderen** of behouden als referentie |
| `src/flow/main.js` | Import wijzigen naar .js, `t()` import toevoegen |

## Verificatie

1. `npm run build` succesvol
2. `bash deploy.sh` naar 3dev
3. Login als admin op 3dev → Settings → Flow
4. Selecteer "MetaVox metadata" → veldselector moet verschijnen
5. Configureer regel: field = publicatiestatus, operator = is, value = Open
6. Sla op en verifieer dat de regel werkt
