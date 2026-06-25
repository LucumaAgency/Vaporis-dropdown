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

## Instalación

1. Descarga/clona este repo.
2. Comprime la carpeta `vaporis-boxes-aroma/` en un `.zip`.
3. En WordPress: *Plugins → Añadir nuevo → Subir plugin* → sube el zip → **Activar**.
4. Si antes pegaste el código en `functions.php`, **bórralo de ahí** para evitar
   funciones duplicadas.

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
