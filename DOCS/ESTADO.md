# Vaporis · Boxes y Aroma de Regalo — Documentación

> Estado del proyecto, arquitectura del plugin, convenciones y pendientes.
> Última actualización: junio 2026 · Plugin v1.2.1

---

## 1. Resumen

Tienda WooCommerce (tema **Bricks child**) que vende **boxes aromáticos** (difusor + un
**aroma de regalo** que elige el cliente). El plugin `vaporis-boxes-aroma.php`:

1. Muestra un **dropdown de aroma de regalo** en la ficha de cada box. El aroma entra
   al carrito como **línea de producto real a precio 0** (descuenta stock, se sincroniza
   y elimina junto al box).
2. Filtra qué aromas aparecen según el **tamaño** y la **familia ("Tipo de aroma")** del box.
3. Reemplaza el `<select>` de color por **círculos (swatches)** en los boxes variables.

---

## 2. Modelo de datos

### Boxes (categoría `boxes`)
- **Simples**: un difusor de un solo color.
- **Variables**: difusor con varios colores → atributo global **Color** (`pa_color`),
  una variación por color, cada una con su imagen.
- **No tienen SKU** → se actualizan por **ID**.
- Campos ACF: `difusor`, `color` (en migración → atributo), `cobertura`, `control`,
  `app`, `capacidad`, `cantidad_de_aroma_de_regalo` (tamaño del aroma de regalo).
- Atributo **Tipo de aroma** (`pa_tipo-de-aroma`): familia que admite el box (Todos / Solo Scent).

### Aromas (categoría `aromas`)
- **Producto variable** con atributo global **Capacidad** (150 ML / 250 ML / 500 ML / 1 L),
  una variación por tamaño con su precio.
- SKU: `AR-<NOMBRE>` (ej. `AR-AURA`); variaciones `AR-AURA-150`, etc.
- Notas ACF: `notas_de_salida`, `notas_de_corazon`, `notas_de_fondo` (van en el padre).
- Atributo **Tipo de aroma** (`pa_tipo-de-aroma`): familias a las que pertenece.

### Familias de aroma ("Tipo de aroma")
- **Todos** = 15 aromas (subconjunto curado).
- **Solo Scent** = 44 aromas (catálogo completo).
- **Todos ⊂ Solo Scent**.
- Reparto de boxes: **5 son "Todos"**, **23 son "Solo Scent"**.

---

## 3. Cómo funciona el aroma de regalo (lógica del plugin)

```
BOX     → fija el TAMAÑO (ACF cantidad_de_aroma_de_regalo) y la FAMILIA (pa_tipo-de-aroma)
CLIENTE → elige CUÁL aroma en el dropdown de la ficha
PLUGIN  → muestra solo aromas que: (a) son de la familia del box,
          (b) existen en ese tamaño, (c) están en stock
        → al añadir, mete la variación del aroma (ese tamaño) a precio 0
```

- El **tamaño** lo decide la tienda, no el cliente.
- Box **sin familia asignada** → sin restricción de familia (muestra todos).
- Validación server-side anti-manipulación (tamaño + familia + categoría + stock).
- Los **borradores y agotados nunca se muestran** (por eso un box "Todos" puede mostrar
  14 en vez de 15 si un aroma de esa familia está en borrador).

---

## 4. Constantes configurables (cabecera del plugin)

| Constante | Valor | Para qué |
|---|---|---|
| `VAPORIS_CAT_BOX` | `boxes` | Slug categoría de boxes |
| `VAPORIS_CAT_AROMAS` | `aromas` | Slug categoría de aromas |
| `VAPORIS_META_GIFT_SIZE` | `cantidad_de_aroma_de_regalo` | ACF del box con el tamaño del regalo |
| `VAPORIS_ATTR_COLOR` | `pa_color` | Atributo global de color (swatches) |
| `VAPORIS_ATTR_TIPO` | `pa_tipo-de-aroma` | Atributo global "Tipo de aroma" (familias) |

Mapa color→hex de los círculos: filtro `vaporis_color_map` (Black, White, Wood, Gold, Silver, Beige).

---

## 5. Convenciones

- **Imágenes de difusor**: `Difusor-Dif_<MODELO sin guion, MAYÚS><COLOR?>.png`
  en `/wp-content/uploads/2026/06/`. Conserva mayúsculas, sin acentos/ñ, espacios→guiones.
  Ej.: `Difusor-Dif_OV1BLACK.png`, `Difusor-Dif_PRO300.png`.
- **Imágenes de aroma**: nombre del aroma minúsculas, espacios→guiones, sin acentos
  (excepción: algunas conservan mayúscula: `Delicia.png`, `Rosa-pasional.png`).
- **Atributos globales** (no personalizados) para que sean taxonomía y se puedan filtrar
  y mostrar en Bricks con `{post_terms_pa_...}`.

---

## 6. Instalación / despliegue del plugin

- El `.php` va en la **raíz del repo** (Git Deployer lo clona en `wp-content/plugins/<repo>/`;
  WordPress solo detecta plugins a 1 nivel de profundidad).
- Despliegue con **Git Deployer / Git Updater** (pull del repo) o subiendo ZIP manual.
- No dejar el código también en `functions.php` (provoca *fatal error* por funciones duplicadas).

---

## 7. Cómo importar CSVs (regla de oro)

| Acción | Check "Actualizar productos existentes" |
|---|---|
| **Crear** productos nuevos | ☐ SIN check |
| **Actualizar** existentes | ☑ CON check |

- Boxes: emparejan por **ID** (no tienen SKU). Importar nuevos **una sola vez** (sin SKU = se duplican).
- Aromas: emparejan por **SKU** (`AR-*`).
- Imágenes: WooCommerce las **descarga desde la URL**, así que deben estar subidas a Medios antes.

### CSVs de referencia (carpeta `datos/`)
- `aromas-import-full.csv` — alta de los 51 aromas (padre + variaciones + notas).
- `boxes-import.csv` / `boxes-faltantes-import.csv` — alta de boxes.
- `boxes-variables-import.csv` — los 4 boxes variables (color).
- `boxes-imagenes-update.csv` — imágenes de boxes simples por ID.
- `aromas-tipo-update.csv` — añade atributo Tipo de aroma a los aromas.
- `boxes-tipo-update.csv` — asigna Tipo de aroma a los 28 boxes por ID.
- `relacion.csv` — clasificación Tipo Aroma por box.

---

## 8. Estado actual

### Funcionando ✅
- Plugin v1.2.1 desplegado (aroma de regalo + filtro tamaño + filtro familia + swatches).
- 28 boxes creados y etiquetados (5 Todos / 23 Solo Scent).
- 51 aromas; 44 clasificados y completos.
- Filtro verificado: box "Todos" → 14 aromas visibles; box "Solo Scent" → 39 visibles
  (la diferencia con 15/44 son aromas en borrador, que no se muestran).

### Pendiente

**A. Boxes sin imagen (en borrador)**
| Box | Difusor | Solución |
|---|---|---|
| Impacto integral | OW-010 | ✅ usar imagen de OW-030 (misma foto) → `box-impacto-integral-img.csv` |
| Esencia viva | ND-110 | ❌ pedir imagen al cliente (es box "Todos") |
| Eternidad aromática | ECO | ❌ pedir imagen al cliente |
| Impacto exclusivo | Mini AD-Scent | ❌ pedir imagen al cliente |

**B. Aromas en borrador (12)**
- *Grupo A — solo falta IMAGEN* (ya tienen notas, precio, tipo):
  Fresh, Moca, Red Carpet, Shea, Wild Adventure.
- *Grupo B — faltan NOTAS + PRECIOS + FAMILIA* (ya tienen imagen, NO están en las listas):
  Coral, Dulce Bouquet, Frescura Oriental, Herbal Paradise, Nice, Otoño, Versalles.

**C. Migración de color (boxes simples)** 🟣
- Mover el color de ACF `color` → atributo `pa_color` en los boxes simples.
- En Bricks, cambiar el dato dinámico `{acf_color}` → `{post_terms_pa_color}`.
- Luego eliminar el campo ACF `color`.

---

## 9. Qué pedir al cliente

| Para completar | Material |
|---|---|
| 3 boxes (ND-110, ECO, Mini AD-Scent) | imágenes de difusor |
| 5 aromas (Grupo A) | imágenes |
| 7 aromas (Grupo B) | 3 notas + 4 precios + familia (Todos/Solo Scent) de cada uno |
