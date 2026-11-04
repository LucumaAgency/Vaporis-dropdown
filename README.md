# Vaporis · Boxes y Aroma de Regalo

Plugin de WooCommerce para la tienda Vaporis. Hace dos cosas:

1. **Aroma de regalo en boxes** — en la ficha de cada *box* muestra un desplegable
   para que el cliente elija un aroma de regalo. El aroma entra al carrito como
   **línea de producto real a precio 0**, por lo que WooCommerce descuenta su
   stock, aparece en carrito/checkout/pedido/picking y se vincula al box
   (se añade, sincroniza la cantidad y se elimina junto a él).
2. **Círculos de color (swatches)** — en los boxes *variables* reemplaza el
   `<select>` del atributo de color (`pa_color`) por círculos clicables,
   manteniendo el select oculto para no romper la lógica de variaciones de WC.

El archivo principal del plugin (`vaporis-boxes-aroma.php`) está en la **raíz**
del repo para que herramientas tipo *Git Deployer* / *Git Updater* lo detecten:
WordPress solo reconoce plugins en la raíz de su carpeta o un nivel debajo.

## Instalación

**Opción A · Git Deployer / Git Updater**
Apunta el deployer a este repo. Clona en `wp-content/plugins/<repo>/` y, al estar
el `.php` con la cabecera en la raíz, WordPress lo detecta y se puede activar.

**Opción B · Subida manual**
1. Descarga el repo como ZIP (o clónalo).
2. En WordPress: *Plugins → Añadir nuevo → Subir plugin* → sube el ZIP → **Activar**.

> Si antes pegaste el código en `functions.php`, **bórralo de ahí** para evitar
> funciones duplicadas.

## Cómo funciona el aroma de regalo

```
BOX      → define solo el TAMAÑO del regalo (campo ACF cantidad_de_aroma_de_regalo)
CLIENTE  → elige CUÁL aroma en el desplegable de la ficha
PLUGIN   → cruza aroma + tamaño → encuentra la variación → la añade gratis
```

El tamaño lo decide la tienda (interno); el cliente solo elige el aroma. El
desplegable solo lista aromas que existan en ese tamaño y con stock.

## Configuración (constantes, en la cabecera del plugin)

| Constante | Por defecto | Para qué |
|---|---|---|
| `VAPORIS_CAT_BOX` | `boxes` | Slug de la categoría de boxes |
| `VAPORIS_CAT_AROMAS` | `aromas` | Slug de la categoría de aromas |
| `VAPORIS_META_GIFT_SIZE` | `cantidad_de_aroma_de_regalo` | Campo ACF del box con el tamaño del regalo |
| `VAPORIS_ATTR_COLOR` | `pa_color` | Taxonomía del atributo global de color |

El mapa color→hex de los círculos es filtrable con `vaporis_color_map`.

## Requisitos

- WooCommerce.
- Atributo **global** `pa_color` para los swatches (los atributos personalizados
  por producto no son taxonomía y no funcionan con los círculos).
- ACF para el campo `cantidad_de_aroma_de_regalo` en los boxes y las notas de los aromas.

## Carpeta `datos/`

CSVs de importación y exports de ACF usados para montar productos (boxes y
aromas) y sus imágenes. Material de referencia, no forma parte del plugin.

> Las imágenes de difusores (~50 MB) no se versionan (ver `.gitignore`).

## v1.10.0 (2026-11-04)

- **Varios aromas incluidos por producto.** Meta nuevo `cantidad_aromas_incluidos`
  (vacío = 1, máximo 6). Con 2 la ficha muestra 2 desplegables ("Elige tu aroma
  incluido 1 de 2"…) y el carrito crea una línea a precio 0 por cada aroma, con
  la misma cantidad que el box. Se puede elegir el mismo aroma dos veces.
  Caso de uso: Box Dual Prestige (2 aromas de 150 mL).
- **El aroma incluido se decide solo por el meta `cantidad_de_aroma_de_regalo`.**
  Se quitó el respaldo por la categoría `boxes`: un producto de esa categoría sin
  capacidad configurada (p. ej. Road Trip, con aromas fijos) se compra normal,
  sin desplegable ni validación. Incluye lo de v1.9.0 (no desplegada).
- El aviso de stock del carrito suma las unidades del mismo aroma entre líneas.
- Pedido: `_aromas_incluidos_ids` con todos los IDs cuando hay más de uno.
- Compatible con carritos creados con v1.8.1 (no duplica líneas existentes).

## v1.10.1 (2026-11-04)

- Swatches de color: solo se dibujan los colores asignados al producto (como el
  `<select>` de WooCommerce). Antes aparecía también el color de una variación
  privada (p. ej. Balance Negro oculto hasta tener foto).

## v1.10.2 (2026-11-04)

- El desplegable y la validación del aroma incluido ignoran variaciones no
  publicadas. Antes, Lavanda Francesa 150 mL (privada a propósito) se ofrecía en
  todos los boxes de 150 mL y, al elegirla, el box entraba al carrito sin la línea
  del aroma (no descontaba stock).
