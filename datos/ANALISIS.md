# Análisis — Aroma de regalo en difusores (WooCommerce)

Snippet que añade un dropdown de "aroma de regalo" en productos de la categoría
*difusor*. Funciona en lo general y sigue los hooks correctos de WooCommerce,
pero tiene varios puntos a mejorar.

## ✅ Lo que está bien hecho

- **Usa los hooks correctos** y en el orden adecuado: mostrar → validar →
  guardar en item del carrito → mostrar en carrito → guardar en pedido. Es el
  flujo canónico de WooCommerce.
- **Escapado de salida correcto**: `esc_attr()` y `esc_html()` en el `<option>`.
- **`intval()`** al guardar el ID evita inyección de tipo.
- **`unique_key` con `md5()`** evita que items con aromas distintos se agrupen.
- **Doble validación**: HTML5 (`required`) + servidor
  (`woocommerce_add_to_cart_validation`).

## 🔴 Problemas importantes

### 1. No se valida que el aroma elegido pertenezca a la categoría "aromas"

Fallo más serio. El usuario podría manipular el valor del `<select>` (DevTools o
petición directa) y enviar **cualquier ID de producto** como "regalo". El código
lo acepta sin verificar:

```php
$aroma_id = intval($_POST['aroma_gift']);
$cart_item_data['aroma_gift_name'] = get_the_title($aroma_id); // ← acepta cualquier ID
```

Alguien podría poner como "regalo" un producto caro. Verificar la categoría:

```php
if ( ! empty($_POST['aroma_gift']) ) {
    $aroma_id = intval($_POST['aroma_gift']);
    if ( ! has_term('aromas', 'product_cat', $aroma_id) ) {
        return $cart_item_data; // ID no válido, ignorar
    }
    $cart_item_data['aroma_gift']      = $aroma_id;
    $cart_item_data['aroma_gift_name'] = get_the_title($aroma_id);
    $cart_item_data['unique_key']      = md5(microtime() . $aroma_id);
}
```

E idealmente lo mismo en la función de validación.

### 2. El "regalo" no se añade realmente como producto — solo se guarda el nombre

El producto aroma nunca entra al pedido; solo se guarda *el nombre* como
metadato. Consecuencias:
- No se descuenta stock del aroma elegido.
- En picking solo se ve una nota de texto, no una línea de producto.

Puede estar bien **si es intencional** (regalo manual). Si quieres control de
inventario, habría que añadir el aroma al carrito como producto con precio 0.
**Pendiente: confirmar intención.**

## 🟡 Mejoras recomendadas

### 3. Difusores quedan bloqueados en páginas de catálogo/tienda
En catálogo el botón "Añadir al carrito" usa AJAX y **no envía** el dropdown.
Como la validación exige `aroma_gift`, esos productos no se podrán añadir desde
el catálogo — solo desde la ficha. Considerar forzar redirección a la ficha.

### 4. Verificar que `'difusor'` y `'aromas'` sean los *slugs* correctos
Deben coincidir con los slugs reales (común que sea `difusores` plural o con
acentos distintos). Si no coinciden, el dropdown no aparece y no da error.

### 5. Falta internacionalización (i18n)
Textos en duro. Usar `__('...', 'tu-textdomain')`.

### 6. Rendimiento (menor)
`get_posts` con `'posts_per_page' => -1` carga todos los aromas en cada visita.
Con muchos aromas, cachear con `get_transient` o usar `'fields' => 'ids'`.

## Resumen

| Severidad | Punto |
|-----------|-------|
| 🔴 Alto   | No valida que el ID sea de la categoría "aromas" (manipulable) |
| 🔴 Medio  | El regalo no entra como producto → sin control de stock (¿intencional?) |
| 🟡 Medio  | Difusores no añadibles desde catálogo (AJAX) |
| 🟡 Bajo   | Verificar slugs `difusor`/`aromas` |
| 🟡 Bajo   | i18n y rendimiento de `get_posts(-1)` |
