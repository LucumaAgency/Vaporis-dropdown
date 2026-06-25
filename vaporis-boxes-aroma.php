<?php
/**
 * Plugin Name: Vaporis · Boxes y Aroma de Regalo
 * Description: Dropdown de aroma de regalo en boxes (línea gratis con control de stock) y círculos de color (swatches) para las variaciones de los boxes variables.
 * Version:     1.2.0
 * Author:      Lucuma Agency
 * Text Domain: vaporis
 * Requires Plugins: woocommerce
 */

if ( ! defined('ABSPATH') ) exit;

/** Slugs de categoría — ajusta si en tu tienda difieren */
if ( ! defined('VAPORIS_CAT_BOX') )    define('VAPORIS_CAT_BOX', 'boxes');
if ( ! defined('VAPORIS_CAT_AROMAS') ) define('VAPORIS_CAT_AROMAS', 'aromas');

/** Nombre del meta ACF del box que define el tamaño del aroma de regalo */
if ( ! defined('VAPORIS_META_GIFT_SIZE') ) define('VAPORIS_META_GIFT_SIZE', 'cantidad_de_aroma_de_regalo');

/** Taxonomía del atributo global de color de los boxes variables */
if ( ! defined('VAPORIS_ATTR_COLOR') ) define('VAPORIS_ATTR_COLOR', 'pa_color');

/** Taxonomía del atributo "Tipo de aroma" (familias: Todos / Solo Scent) */
if ( ! defined('VAPORIS_ATTR_TIPO') ) define('VAPORIS_ATTR_TIPO', 'pa_tipo-aroma');

/** Helper: ¿este producto es un box? */
function vaporis_es_box($product_id) {
    return has_term(VAPORIS_CAT_BOX, 'product_cat', $product_id);
}

/** Helper: tipos de aroma (slugs) que admite este box. Vacío = sin restricción. */
function vaporis_box_tipos($box_id) {
    $t = wp_get_post_terms($box_id, VAPORIS_ATTR_TIPO, ['fields' => 'slugs']);
    return is_wp_error($t) ? [] : $t;
}

/**
 * Helper: ¿el aroma encaja con el/los tipo(s) que admite el box?
 * - Si el box no tiene tipo asignado → sin restricción (muestra todos).
 * - Si lo tiene → el aroma debe compartir al menos un término de tipo.
 */
function vaporis_aroma_encaja_tipo($aroma_id, $box_tipos) {
    if ( empty($box_tipos) ) return true; // box sin tipo: sin filtro extra
    $a = wp_get_post_terms($aroma_id, VAPORIS_ATTR_TIPO, ['fields' => 'slugs']);
    if ( is_wp_error($a) || empty($a) ) return false;
    return (bool) array_intersect($box_tipos, $a);
}

/** Helper: ¿este ID es un aroma válido (categoría correcta)? */
function vaporis_es_aroma($aroma_id) {
    return $aroma_id && has_term(VAPORIS_CAT_AROMAS, 'product_cat', $aroma_id);
}

/** Helper: normaliza un tamaño para comparar ("150 ML" = "150-ml" = "150ml") */
function vaporis_norm_size($s) {
    return preg_replace('/[^a-z0-9]/', '', strtolower((string) $s));
}

/** Helper: tamaño de aroma que regala este box (valor del ACF, p. ej. "150 ML") */
function vaporis_box_gift_size($box_id) {
    $size = get_post_meta($box_id, VAPORIS_META_GIFT_SIZE, true);
    return is_string($size) ? trim($size) : '';
}

/**
 * Helper: dado un aroma (producto variable) y un tamaño, devuelve el ID de la
 * variación que coincide con ese tamaño, o 0 si no existe.
 * El tamaño lo fija el box internamente; el cliente nunca lo elige.
 */
function vaporis_find_aroma_variation($aroma_id, $size) {
    $target = vaporis_norm_size($size);
    if ( '' === $target ) return 0;

    $aroma = wc_get_product($aroma_id);
    if ( ! $aroma ) return 0;

    // Aroma simple (sin variaciones): si el size encaja con el producto, úsalo tal cual.
    if ( ! $aroma->is_type('variable') ) {
        return $aroma_id;
    }

    foreach ( $aroma->get_children() as $variation_id ) {
        $variation = wc_get_product($variation_id);
        if ( ! $variation ) continue;
        foreach ( $variation->get_variation_attributes() as $value ) {
            if ( vaporis_norm_size($value) === $target ) {
                return $variation_id;
            }
        }
    }
    return 0;
}

/** Helper: lista de aromas (IDs, cacheada) */
function vaporis_get_aromas() {
    $cache = get_transient('vaporis_aromas_list');
    if ( false !== $cache ) return $cache;

    $aromas = get_posts([
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
        'tax_query'      => [[
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => VAPORIS_CAT_AROMAS,
        ]],
    ]);

    set_transient('vaporis_aromas_list', $aromas, HOUR_IN_SECONDS);
    return $aromas;
}

/** Invalidar caché cuando cambian los productos */
add_action('save_post_product', 'vaporis_clear_aromas_cache');
add_action('delete_post', 'vaporis_clear_aromas_cache');
function vaporis_clear_aromas_cache() {
    delete_transient('vaporis_aromas_list');
}


/* -------------------------------------------------------------------------
 * 1) Dropdown en la ficha del box (solo aromas con stock disponible)
 * ---------------------------------------------------------------------- */
add_action('woocommerce_before_add_to_cart_button', 'lucia_aroma_gift_dropdown');
function lucia_aroma_gift_dropdown() {
    global $product;
    if ( ! $product || ! vaporis_es_box($product->get_id()) ) return;

    // Tamaño del aroma de regalo: lo decide el box internamente, no el cliente.
    $gift_size = vaporis_box_gift_size($product->get_id());
    if ( '' === $gift_size ) return; // box sin tamaño configurado: no mostramos dropdown

    $aromas = vaporis_get_aromas();
    if ( empty($aromas) ) return;

    // Tipo(s) de aroma que admite este box (Todos / Solo Scent). Vacío = todos.
    $box_tipos = vaporis_box_tipos($product->get_id());

    // Solo aromas que: encajen con el tipo del box, tengan la variación de ese
    // tamaño y estén en stock. Guardamos la nota de fondo para mostrarla al elegir.
    $options = [];
    foreach ( $aromas as $aroma_id ) {
        if ( ! vaporis_aroma_encaja_tipo($aroma_id, $box_tipos) ) continue;
        $variation_id = vaporis_find_aroma_variation($aroma_id, $gift_size);
        if ( ! $variation_id ) continue;
        $variation = wc_get_product($variation_id);
        if ( ! $variation || ! $variation->is_in_stock() ) continue;
        $options[$aroma_id] = [
            'name'  => get_the_title($aroma_id),
            'fondo' => get_post_meta($aroma_id, 'notas_de_fondo', true),
        ];
    }
    if ( empty($options) ) return;

    echo '<div class="lucia-aroma-gift" style="margin:1rem 0;">';
    echo '<label for="aroma_gift" style="display:block;margin-bottom:.4rem;font-weight:600;">'
        . esc_html__('Elige tu aroma de regalo', 'vaporis') . '</label>';
    echo '<select name="aroma_gift" id="aroma_gift" required style="width:100%;padding:.6rem;">';
    echo '<option value="" data-fondo="">' . esc_html__('— Selecciona un aroma —', 'vaporis') . '</option>';
    foreach ( $options as $aroma_id => $opt ) {
        echo '<option value="' . esc_attr($aroma_id) . '" data-fondo="' . esc_attr($opt['fondo']) . '">'
            . esc_html($opt['name']) . '</option>';
    }
    echo '</select>';
    // Contenedor donde aparece la nota de fondo del aroma elegido.
    echo '<p class="lucia-aroma-fondo" style="margin:.5rem 0 0;font-size:.9em;color:#555;display:none;"></p>';
    echo '</div>';

    // JS: al cambiar el select, muestra la nota de fondo del aroma seleccionado.
    echo "<script>(function(){
        var s=document.getElementById('aroma_gift');
        if(!s) return;
        var box=document.querySelector('.lucia-aroma-fondo');
        var label=" . wp_json_encode(__('Notas de fondo:', 'vaporis')) . ";
        s.addEventListener('change',function(){
            var f=s.options[s.selectedIndex].getAttribute('data-fondo')||'';
            if(f){ box.innerHTML='<strong>'+label+'</strong> '+f; box.style.display='block'; }
            else { box.style.display='none'; box.textContent=''; }
        });
    })();</script>";
}


/* -------------------------------------------------------------------------
 * 2) Validación: aroma elegido, de la categoría correcta y con stock
 * ---------------------------------------------------------------------- */
add_filter('woocommerce_add_to_cart_validation', 'lucia_validate_aroma_gift', 10, 2);
function lucia_validate_aroma_gift($passed, $product_id) {
    if ( ! vaporis_es_box($product_id) ) return $passed;

    if ( empty($_POST['aroma_gift']) ) {
        wc_add_notice(__('Por favor, elige un aroma de regalo antes de añadir al carrito.', 'vaporis'), 'error');
        return false;
    }

    $aroma_id = intval($_POST['aroma_gift']);
    if ( ! vaporis_es_aroma($aroma_id) ) {
        wc_add_notice(__('El aroma seleccionado no es válido.', 'vaporis'), 'error');
        return false;
    }

    // El aroma debe pertenecer al tipo que admite este box (anti-manipulación).
    if ( ! vaporis_aroma_encaja_tipo($aroma_id, vaporis_box_tipos($product_id)) ) {
        wc_add_notice(__('Ese aroma no está disponible para este box.', 'vaporis'), 'error');
        return false;
    }

    // El tamaño lo fija el box; debe existir esa variación del aroma.
    $gift_size    = vaporis_box_gift_size($product_id);
    $variation_id = vaporis_find_aroma_variation($aroma_id, $gift_size);
    if ( ! $variation_id ) {
        wc_add_notice(__('Ese aroma no está disponible en el tamaño de regalo de este box.', 'vaporis'), 'error');
        return false;
    }

    $variation = wc_get_product($variation_id);
    if ( ! $variation || ! $variation->is_in_stock() || ! $variation->has_enough_stock(1) ) {
        wc_add_notice(__('El aroma seleccionado está agotado. Elige otro, por favor.', 'vaporis'), 'error');
        return false;
    }

    return $passed;
}


/* -------------------------------------------------------------------------
 * 3) Guardar la elección en el item del box (revalidando la categoría)
 * ---------------------------------------------------------------------- */
add_filter('woocommerce_add_cart_item_data', 'lucia_add_aroma_to_cart_item', 10, 2);
function lucia_add_aroma_to_cart_item($cart_item_data, $product_id) {
    // Solo boxes; nunca tocar la propia línea de regalo que añadimos luego.
    if ( ! vaporis_es_box($product_id) ) return $cart_item_data;
    if ( empty($_POST['aroma_gift']) ) return $cart_item_data;

    $aroma_id = intval($_POST['aroma_gift']);
    if ( ! vaporis_es_aroma($aroma_id) ) return $cart_item_data; // no confiar en el cliente

    $cart_item_data['aroma_gift']      = $aroma_id;
    $cart_item_data['aroma_gift_name'] = get_the_title($aroma_id);
    $cart_item_data['aroma_gift_size'] = vaporis_box_gift_size($product_id); // tamaño fijado por el box
    $cart_item_data['unique_key']      = md5(microtime(true) . $aroma_id); // evita agrupar boxes con aromas distintos

    return $cart_item_data;
}


/* -------------------------------------------------------------------------
 * 4) Añadir el aroma como LÍNEA DE PRODUCTO REAL (gratis) vinculada al box
 * ---------------------------------------------------------------------- */
add_action('woocommerce_add_to_cart', 'vaporis_add_aroma_gift_line', 10, 6);
function vaporis_add_aroma_gift_line($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    if ( empty($cart_item_data['aroma_gift']) ) return;        // el box no trae aroma
    if ( ! empty($cart_item_data['_is_aroma_gift']) ) return;  // guard anti-recursión

    $aroma_id = intval($cart_item_data['aroma_gift']);
    if ( ! vaporis_es_aroma($aroma_id) ) return;

    // Resolver la VARIACIÓN del tamaño que regala este box (no el cliente).
    $gift_size    = isset($cart_item_data['aroma_gift_size']) ? $cart_item_data['aroma_gift_size'] : vaporis_box_gift_size($product_id);
    $variation_id = vaporis_find_aroma_variation($aroma_id, $gift_size);
    if ( ! $variation_id ) return; // sin variación válida no añadimos regalo

    // Si el aroma es variable, pasamos sus atributos de variación a add_to_cart.
    $variation = wc_get_product($variation_id);
    $var_attrs = ( $variation && $variation->is_type('variation') ) ? $variation->get_variation_attributes() : [];
    $parent_id = ( $variation && $variation->is_type('variation') ) ? $variation->get_parent_id() : $aroma_id;
    $real_variation_id = ( $variation_id !== $aroma_id ) ? $variation_id : 0;

    // Evitar recursión: añadir el aroma no debe volver a disparar este hook.
    remove_action('woocommerce_add_to_cart', 'vaporis_add_aroma_gift_line', 10);

    WC()->cart->add_to_cart($parent_id, $quantity, $real_variation_id, $var_attrs, [
        '_is_aroma_gift' => true,
        '_gift_for'      => $cart_item_key, // vínculo con la línea del box
        'aroma_gift_size'=> $gift_size,
        'unique_key'     => md5('gift' . microtime(true) . $aroma_id),
    ]);

    add_action('woocommerce_add_to_cart', 'vaporis_add_aroma_gift_line', 10, 6);
}


/* -------------------------------------------------------------------------
 * 5) Precio 0 para la línea de regalo
 * ---------------------------------------------------------------------- */
add_action('woocommerce_before_calculate_totals', 'vaporis_zero_gift_price', 20, 1);
function vaporis_zero_gift_price($cart) {
    if ( is_admin() && ! defined('DOING_AJAX') ) return;
    if ( did_action('woocommerce_before_calculate_totals') >= 2 ) return;

    foreach ( $cart->get_cart() as $item ) {
        if ( ! empty($item['_is_aroma_gift']) && isset($item['data']) ) {
            $item['data']->set_price(0);
        }
    }
}


/* -------------------------------------------------------------------------
 * 6) UI del carrito: etiqueta "regalo", precio "Gratis", cantidad fija, sin quitar
 * ---------------------------------------------------------------------- */
add_filter('woocommerce_cart_item_name', 'vaporis_gift_label', 10, 3);
function vaporis_gift_label($name, $cart_item, $cart_item_key) {
    if ( ! empty($cart_item['_is_aroma_gift']) ) {
        $name .= ' <span class="aroma-gift-badge" style="color:#c0392b;font-weight:600;">'
               . esc_html__('🎁 Aroma de regalo', 'vaporis') . '</span>';
    }
    return $name;
}

add_filter('woocommerce_cart_item_price', 'vaporis_gift_price_label', 10, 3);
add_filter('woocommerce_cart_item_subtotal', 'vaporis_gift_price_label', 10, 3);
function vaporis_gift_price_label($price, $cart_item, $cart_item_key) {
    if ( ! empty($cart_item['_is_aroma_gift']) ) {
        return '<span class="aroma-gift-free">' . esc_html__('Gratis', 'vaporis') . '</span>';
    }
    return $price;
}

add_filter('woocommerce_cart_item_quantity', 'vaporis_lock_gift_qty', 10, 3);
function vaporis_lock_gift_qty($html, $cart_item_key, $cart_item) {
    if ( ! empty($cart_item['_is_aroma_gift']) ) {
        return '1 <input type="hidden" name="cart[' . esc_attr($cart_item_key) . '][qty]" value="1" />';
    }
    return $html;
}

add_filter('woocommerce_cart_item_remove_link', 'vaporis_hide_gift_remove', 10, 2);
function vaporis_hide_gift_remove($link, $cart_item_key) {
    $cart = WC()->cart ? WC()->cart->get_cart() : [];
    if ( ! empty($cart[$cart_item_key]['_is_aroma_gift']) ) {
        return ''; // la línea de regalo no se quita por sí sola
    }
    return $link;
}


/* -------------------------------------------------------------------------
 * 7) Sincronizar el regalo con su box (cantidad y eliminación)
 * ---------------------------------------------------------------------- */
add_action('woocommerce_after_cart_item_quantity_update', 'vaporis_sync_gift_qty', 10, 4);
function vaporis_sync_gift_qty($cart_item_key, $quantity, $old_quantity, $cart) {
    foreach ( $cart->get_cart() as $key => $item ) {
        if ( ! empty($item['_gift_for']) && $item['_gift_for'] === $cart_item_key ) {
            $cart->set_quantity($key, $quantity, false);
        }
    }
}

add_action('woocommerce_remove_cart_item', 'vaporis_remove_linked_gift', 10, 2);
function vaporis_remove_linked_gift($cart_item_key, $cart) {
    foreach ( $cart->get_cart() as $key => $item ) {
        if ( ! empty($item['_gift_for']) && $item['_gift_for'] === $cart_item_key ) {
            $cart->remove_cart_item($key);
        }
    }
}


/* -------------------------------------------------------------------------
 * 8) Metadatos de trazabilidad
 *    - En la línea del box: qué aroma se regaló (visible) + ID (oculto).
 *    - En la línea del aroma: marcarla como regalo en el pedido.
 * ---------------------------------------------------------------------- */
add_filter('woocommerce_get_item_data', 'lucia_display_aroma_in_cart', 10, 2);
function lucia_display_aroma_in_cart($item_data, $cart_item) {
    if ( ! empty($cart_item['aroma_gift_name']) ) {
        $value = $cart_item['aroma_gift_name'];
        if ( ! empty($cart_item['aroma_gift_size']) ) {
            $value .= ' (' . $cart_item['aroma_gift_size'] . ')';
        }
        $item_data[] = [
            'key'   => __('Aroma de regalo', 'vaporis'),
            'value' => $value,
        ];
    }
    return $item_data;
}

add_action('woocommerce_checkout_create_order_line_item', 'lucia_save_aroma_to_order', 10, 3);
function lucia_save_aroma_to_order($item, $cart_item_key, $values) {
    // Línea del box: referencia al aroma regalado.
    if ( ! empty($values['aroma_gift_name']) ) {
        $item->add_meta_data(__('Aroma de regalo', 'vaporis'), $values['aroma_gift_name']);
    }
    if ( ! empty($values['aroma_gift']) ) {
        $item->add_meta_data('_aroma_gift_id', intval($values['aroma_gift']), true);
    }
    // Línea del aroma: marcarla como regalo.
    if ( ! empty($values['_is_aroma_gift']) ) {
        $item->add_meta_data(__('Tipo', 'vaporis'), __('Aroma de regalo (incluido en el box)', 'vaporis'));
    }
}


/* -------------------------------------------------------------------------
 * 9) Catálogo/tienda: el botón de boxes lleva a la ficha (el AJAX no
 *    envía el dropdown y la validación lo bloquearía).
 * ---------------------------------------------------------------------- */
add_filter('woocommerce_loop_add_to_cart_link', 'lucia_box_loop_button', 10, 2);
function lucia_box_loop_button($html, $product) {
    if ( $product && vaporis_es_box($product->get_id()) ) {
        $html = sprintf(
            '<a href="%s" class="button">%s</a>',
            esc_url($product->get_permalink()),
            esc_html__('Elegir aroma', 'vaporis')
        );
    }
    return $html;
}


/* -------------------------------------------------------------------------
 * 10) Círculos de color (swatches) para la variación de color de los boxes
 *     Reemplaza el <select> de pa_color por círculos clicables, manteniendo
 *     el select oculto para que la lógica de variaciones de WooCommerce siga
 *     funcionando intacta.
 * ---------------------------------------------------------------------- */

/** Mapa color → hex. Filtrable con 'vaporis_color_map' por si añades colores. */
function vaporis_color_map() {
    return apply_filters('vaporis_color_map', [
        'black'    => '#1a1a1a',
        'white'    => '#ffffff',
        'wood'     => '#9b6a3f',
        'gold'     => '#d4af37',
        'silver'   => '#c0c0c0',
        'beige'    => '#e7d8bd',
        // alias en español por si los términos están en español
        'negro'    => '#1a1a1a',
        'blanco'   => '#ffffff',
        'madera'   => '#9b6a3f',
        'dorado'   => '#d4af37',
        'plateado' => '#c0c0c0',
        'beis'     => '#e7d8bd',
    ]);
}

add_filter('woocommerce_dropdown_variation_attribute_options_html', 'vaporis_color_swatches', 20, 2);
function vaporis_color_swatches($html, $args) {
    $taxonomy = isset($args['attribute']) ? $args['attribute'] : '';
    if ( $taxonomy !== VAPORIS_ATTR_COLOR ) return $html; // solo el atributo de color

    $product  = isset($args['product']) ? $args['product'] : false;
    $options  = isset($args['options']) ? $args['options'] : [];
    $selected = isset($args['selected']) ? $args['selected'] : '';
    $name     = ! empty($args['name']) ? $args['name'] : 'attribute_' . sanitize_title($taxonomy);

    if ( $product && empty($options) ) {
        $attrs   = $product->get_variation_attributes();
        $options = isset($attrs[$taxonomy]) ? $attrs[$taxonomy] : [];
    }
    if ( empty($options) ) return $html;

    $map = vaporis_color_map();

    // Nombres legibles (label) desde los términos de la taxonomía.
    $labels = [];
    if ( $product && taxonomy_exists($taxonomy) ) {
        $terms = wc_get_product_terms($product->get_id(), $taxonomy, ['fields' => 'all']);
        foreach ( $terms as $t ) $labels[$t->slug] = $t->name;
    }

    $sw = '<div class="vaporis-swatches" data-attribute_name="' . esc_attr($name) . '">';
    foreach ( $options as $slug ) {
        $label = isset($labels[$slug]) ? $labels[$slug] : $slug;
        $hex   = isset($map[strtolower($label)]) ? $map[strtolower($label)]
               : ( isset($map[strtolower($slug)]) ? $map[strtolower($slug)] : '#cccccc' );
        $is_sel = ( sanitize_title($selected) === $slug ) ? ' selected' : '';
        $sw .= '<span class="vaporis-swatch' . $is_sel . '" role="button" tabindex="0"'
             . ' data-value="' . esc_attr($slug) . '"'
             . ' title="' . esc_attr($label) . '"'
             . ' style="--swatch:' . esc_attr($hex) . '"></span>';
    }
    $sw .= '</div>';

    // Mantén el <select> original (lo ocultamos por CSS) para que WC siga operando.
    return '<div class="vaporis-swatches-wrap">' . $sw . $html . '</div>';
}

/** CSS + JS de los swatches, solo en fichas de producto. */
add_action('wp_enqueue_scripts', 'vaporis_swatches_assets');
function vaporis_swatches_assets() {
    if ( ! function_exists('is_product') || ! is_product() ) return;

    wp_register_style('vaporis-boxes', false);
    wp_enqueue_style('vaporis-boxes');
    wp_add_inline_style('vaporis-boxes',
        '.vaporis-swatches-wrap select{position:absolute!important;width:1px;height:1px;opacity:0;pointer-events:none;}'
      . '.vaporis-swatches{display:flex;gap:.55rem;flex-wrap:wrap;align-items:center;margin:.2rem 0;}'
      . '.vaporis-swatch{width:30px;height:30px;border-radius:50%;cursor:pointer;background:var(--swatch,#ccc);'
      . 'border:2px solid #fff;box-shadow:0 0 0 1px #bbb;transition:transform .12s,box-shadow .12s;}'
      . '.vaporis-swatch:hover{transform:scale(1.08);}'
      . '.vaporis-swatch.selected{box-shadow:0 0 0 2px #222;}'
    );

    wp_register_script('vaporis-boxes', false, ['jquery'], null, true);
    wp_enqueue_script('vaporis-boxes');
    wp_add_inline_script('vaporis-boxes',
        'jQuery(function($){'
      . '$(document).on("click keypress",".vaporis-swatch",function(e){'
      . 'if(e.type==="keypress"&&e.which!==13&&e.which!==32)return;'
      . 'var sw=$(this),val=String(sw.data("value"));'
      . 'var name=sw.closest(".vaporis-swatches").data("attribute_name");'
      . 'var sel=$(\'select[name="\'+name+\'"]\');'
      . 'if(sel.val()===val){sel.val("").trigger("change");}'        // reclic = deseleccionar
      . 'else{sel.val(val).trigger("change");}'
      . '});'
      . '$(document).on("change",".variations select",function(){'    // reflejar estado (incluye reset de WC)
      . 'var name=$(this).attr("name"),val=$(this).val();'
      . 'var wrap=$(\'.vaporis-swatches[data-attribute_name="\'+name+\'"]\');'
      . 'if(!wrap.length)return;'
      . 'wrap.find(".vaporis-swatch").removeClass("selected");'
      . 'if(val)wrap.find(\'.vaporis-swatch[data-value="\'+val+\'"]\').addClass("selected");'
      . '});'
      . '});'
    );
}
