<?php
/**
 * Plugin Name: Vaporis · Boxes y Aroma Incluido
 * Description: Dropdown de aroma incluido en boxes (línea a precio 0 con control de stock, filtrado por tipo de aroma y capacidad) y círculos de color (swatches) para las variaciones de los boxes variables.
 * Version:     1.4.0
 * Author:      Lucuma Agency
 * Text Domain: vaporis
 * Requires Plugins: woocommerce
 */

if ( ! defined('ABSPATH') ) exit;

/** Slugs de categoría — ajusta si en tu tienda difieren */
if ( ! defined('VAPORIS_CAT_BOX') )    define('VAPORIS_CAT_BOX', 'boxes');
if ( ! defined('VAPORIS_CAT_AROMAS') ) define('VAPORIS_CAT_AROMAS', 'aromas');

/** Nombre del meta ACF del box que define el tamaño del aroma incluido */
if ( ! defined('VAPORIS_META_INCLUIDO_SIZE') ) define('VAPORIS_META_INCLUIDO_SIZE', 'cantidad_de_aroma_de_regalo');

/** Taxonomía del atributo global de color de los boxes variables */
if ( ! defined('VAPORIS_ATTR_COLOR') ) define('VAPORIS_ATTR_COLOR', 'pa_color');

/** Taxonomía del atributo "Tipo de aroma" (familias: Todos / Solo Scent) */
if ( ! defined('VAPORIS_ATTR_TIPO') ) define('VAPORIS_ATTR_TIPO', 'pa_tipo-de-aroma');

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

/** Helper: ¿el box es variable y usa "tipo de aroma" como eje de variación? */
function vaporis_box_tipo_es_variacion($box_id) {
    $box = wc_get_product($box_id);
    if ( ! $box || ! $box->is_type('variable') ) return false;
    foreach ( array_keys( (array) $box->get_variation_attributes() ) as $akey ) {
        if ( false !== strpos( strtolower($akey), VAPORIS_ATTR_TIPO ) ) return true;
    }
    return false;
}

/**
 * Helper: tipo(s) de aroma que exige el box en ESTA operación.
 * - Box variable con tipo por variación → el término elegido en el POST.
 * - Box simple → el/los término(s) fijos del box.
 */
function vaporis_required_tipos($box_id) {
    if ( vaporis_box_tipo_es_variacion($box_id) ) {
        $key = 'attribute_' . VAPORIS_ATTR_TIPO;
        $sel = isset($_POST[$key]) ? sanitize_title( wp_unslash($_POST[$key]) ) : '';
        return ( '' !== $sel ) ? [ $sel ] : [];
    }
    return vaporis_box_tipos($box_id);
}

/** Helper: ¿este ID es un aroma válido (categoría correcta)? */
function vaporis_es_aroma($aroma_id) {
    return $aroma_id && has_term(VAPORIS_CAT_AROMAS, 'product_cat', $aroma_id);
}

/** Helper: normaliza un tamaño para comparar ("150 ML" = "150-ml" = "150ml") */
function vaporis_norm_size($s) {
    return preg_replace('/[^a-z0-9]/', '', strtolower((string) $s));
}

/** Helper: capacidad del aroma incluido de este box (valor del ACF, p. ej. "150 ML") */
function vaporis_box_incluido_size($box_id) {
    $size = get_post_meta($box_id, VAPORIS_META_INCLUIDO_SIZE, true);
    return is_string($size) ? trim($size) : '';
}

/**
 * Helper: dado un aroma (producto variable) y la capacidad que fija el box,
 * devuelve el ID de la variación que coincide, o 0 si no existe. Si se pasa
 * $tipo y el aroma también varía por tipo de aroma, exige que case por ambos.
 * La capacidad (y el tipo) los fija el box/selección; el cliente no elige el mL.
 */
function vaporis_find_aroma_variation($aroma_id, $size, $tipo = '') {
    $target = vaporis_norm_size($size);
    if ( '' === $target ) return 0;

    $aroma = wc_get_product($aroma_id);
    if ( ! $aroma ) return 0;

    // Los aromas son variables. Si alguno fuese simple no podemos garantizar la
    // capacidad, así que no lo ofrecemos (salvaguarda).
    if ( ! $aroma->is_type('variable') ) {
        return 0;
    }

    $tipo_slug = ( '' !== $tipo ) ? sanitize_title($tipo) : '';

    foreach ( $aroma->get_children() as $variation_id ) {
        $variation = wc_get_product($variation_id);
        if ( ! $variation ) continue;

        $attrs   = $variation->get_variation_attributes(); // p.ej. ['attribute_pa_capacidad' => '250-ml', ...]
        $cap_ok  = false;
        $tipo_ok = ( '' === $tipo_slug );
        $tiene_eje_tipo = false;

        foreach ( $attrs as $akey => $aval ) {
            if ( vaporis_norm_size($aval) === $target ) $cap_ok = true;
            if ( false !== strpos( strtolower($akey), VAPORIS_ATTR_TIPO ) ) {
                $tiene_eje_tipo = true;
                if ( '' !== $tipo_slug && sanitize_title($aval) === $tipo_slug ) $tipo_ok = true;
            }
        }
        // Si el tipo no es eje de variación de este aroma, no lo exigimos aquí.
        if ( '' !== $tipo_slug && ! $tiene_eje_tipo ) $tipo_ok = true;

        if ( $cap_ok && $tipo_ok ) return $variation_id;
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
 * 1) Dropdown en la ficha del box (aromas con capacidad correcta y stock)
 *    Filtro por tipo de aroma: fijo (box simple) en servidor; o dinámico según
 *    la variación de tipo del box (JS) cuando el cliente lo elige.
 * ---------------------------------------------------------------------- */
add_action('woocommerce_before_add_to_cart_button', 'lucia_aroma_incluido_dropdown');
function lucia_aroma_incluido_dropdown() {
    global $product;
    if ( ! $product || ! vaporis_es_box($product->get_id()) ) return;

    $box_id = $product->get_id();

    // Capacidad del aroma incluido: la decide el box (ACF), no el cliente.
    $incluido_size = vaporis_box_incluido_size($box_id);
    if ( '' === $incluido_size ) return; // box sin capacidad configurada: no mostramos dropdown

    $aromas = vaporis_get_aromas();
    if ( empty($aromas) ) return;

    // ¿El tipo de aroma lo elige el cliente en la variación del box?
    $tipo_por_variacion = vaporis_box_tipo_es_variacion($box_id);
    // Filtro por tipo en servidor solo cuando es fijo (box simple).
    $box_tipos = $tipo_por_variacion ? [] : vaporis_box_tipos($box_id);

    // Aromas con la capacidad del box y en stock. data-tipo permite el filtro JS.
    $options = [];
    foreach ( $aromas as $aroma_id ) {
        if ( ! $tipo_por_variacion && ! vaporis_aroma_encaja_tipo($aroma_id, $box_tipos) ) continue;
        $variation_id = vaporis_find_aroma_variation($aroma_id, $incluido_size);
        if ( ! $variation_id ) continue;
        $variation = wc_get_product($variation_id);
        if ( ! $variation || ! $variation->is_in_stock() ) continue;

        $tipos = wp_get_post_terms($aroma_id, VAPORIS_ATTR_TIPO, ['fields' => 'slugs']);
        $options[$aroma_id] = [
            'name'  => get_the_title($aroma_id),
            'fondo' => get_post_meta($aroma_id, 'notas_de_fondo', true),
            'tipo'  => is_wp_error($tipos) ? '' : implode(' ', $tipos),
        ];
    }
    if ( empty($options) ) return;

    echo '<div class="lucia-aroma-incluido" style="margin:1rem 0;">';
    echo '<label for="aroma_incluido" style="display:block;margin-bottom:.4rem;font-weight:600;">'
        . esc_html__('Elige tu aroma incluido', 'vaporis') . '</label>';
    echo '<select name="aroma_incluido" id="aroma_incluido" required style="width:100%;padding:.6rem;">';
    echo '<option value="" data-fondo="" data-tipo="">' . esc_html__('— Selecciona un aroma —', 'vaporis') . '</option>';
    foreach ( $options as $aroma_id => $opt ) {
        echo '<option value="' . esc_attr($aroma_id) . '" data-fondo="' . esc_attr($opt['fondo']) . '" data-tipo="' . esc_attr($opt['tipo']) . '">'
            . esc_html($opt['name']) . '</option>';
    }
    echo '</select>';
    // Contenedor donde aparece la nota de fondo del aroma elegido.
    echo '<p class="lucia-aroma-fondo" style="margin:.5rem 0 0;font-size:.9em;color:#555;display:none;"></p>';
    echo '</div>';

    // JS: nota de fondo al elegir + filtrado por el tipo de aroma de la variación del box.
    $tipo_attr = 'attribute_' . VAPORIS_ATTR_TIPO;
    echo "<script>(function(){
        var s=document.getElementById('aroma_incluido'); if(!s) return;
        var fondoBox=document.querySelector('.lucia-aroma-fondo');
        var fondoLabel=" . wp_json_encode(__('Notas de fondo:', 'vaporis')) . ";
        function showFondo(){
            var o=s.options[s.selectedIndex], f=o?o.getAttribute('data-fondo'):'';
            if(f){ fondoBox.innerHTML='<strong>'+fondoLabel+'</strong> '+f; fondoBox.style.display='block'; }
            else { fondoBox.style.display='none'; fondoBox.textContent=''; }
        }
        s.addEventListener('change', showFondo);
        var tipoSel=document.querySelector('select[name=\"" . esc_js($tipo_attr) . "\"]');
        function filtrar(){
            var sel=tipoSel ? String(tipoSel.value||'').toLowerCase() : '';
            for(var i=0;i<s.options.length;i++){
                var o=s.options[i]; if(o.value==='') continue;
                var tipos=(o.getAttribute('data-tipo')||'').toLowerCase().split(' ');
                var ok = sel==='' ? true : tipos.indexOf(sel)!==-1;
                o.hidden=!ok; o.disabled=!ok;
            }
            if(s.selectedIndex>0 && s.options[s.selectedIndex].hidden){ s.value=''; showFondo(); }
        }
        if(tipoSel){ tipoSel.addEventListener('change', filtrar); filtrar(); }
    })();</script>";
}


/* -------------------------------------------------------------------------
 * 2) Validación: aroma elegido, de la categoría correcta y con stock
 * ---------------------------------------------------------------------- */
add_filter('woocommerce_add_to_cart_validation', 'lucia_validate_aroma_incluido', 10, 2);
function lucia_validate_aroma_incluido($passed, $product_id) {
    if ( ! vaporis_es_box($product_id) ) return $passed;

    if ( empty($_POST['aroma_incluido']) ) {
        wc_add_notice(__('Por favor, elige un aroma incluido antes de añadir al carrito.', 'vaporis'), 'error');
        return false;
    }

    $aroma_id = intval($_POST['aroma_incluido']);
    if ( ! vaporis_es_aroma($aroma_id) ) {
        wc_add_notice(__('El aroma seleccionado no es válido.', 'vaporis'), 'error');
        return false;
    }

    // El aroma debe pertenecer al tipo que exige este box (fijo o el elegido en la variación).
    $req_tipos = vaporis_required_tipos($product_id);
    if ( ! vaporis_aroma_encaja_tipo($aroma_id, $req_tipos) ) {
        wc_add_notice(__('Ese aroma no está disponible para este box.', 'vaporis'), 'error');
        return false;
    }

    // La capacidad la fija el box; debe existir esa variación del aroma (capacidad + tipo).
    $incluido_tipo    = $req_tipos ? reset($req_tipos) : '';
    $incluido_size    = vaporis_box_incluido_size($product_id);
    $variation_id = vaporis_find_aroma_variation($aroma_id, $incluido_size, $incluido_tipo);
    if ( ! $variation_id ) {
        wc_add_notice(__('Ese aroma no está disponible en la capacidad incluida de este box.', 'vaporis'), 'error');
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
    // Solo boxes; nunca tocar la propia línea del aroma incluido que añadimos luego.
    if ( ! vaporis_es_box($product_id) ) return $cart_item_data;
    if ( empty($_POST['aroma_incluido']) ) return $cart_item_data;

    $aroma_id = intval($_POST['aroma_incluido']);
    if ( ! vaporis_es_aroma($aroma_id) ) return $cart_item_data; // no confiar en el cliente

    $req_tipos = vaporis_required_tipos($product_id);
    $cart_item_data['aroma_incluido']      = $aroma_id;
    $cart_item_data['aroma_incluido_name'] = get_the_title($aroma_id);
    $cart_item_data['aroma_incluido_size'] = vaporis_box_incluido_size($product_id);      // capacidad fijada por el box
    $cart_item_data['aroma_incluido_tipo'] = $req_tipos ? reset($req_tipos) : '';     // tipo (fijo o elegido en la variación)
    $cart_item_data['unique_key']      = md5(microtime(true) . $aroma_id); // evita agrupar boxes con aromas distintos

    return $cart_item_data;
}


/* -------------------------------------------------------------------------
 * 4) Añadir el aroma como LÍNEA DE PRODUCTO REAL (gratis) vinculada al box
 * ---------------------------------------------------------------------- */
add_action('woocommerce_add_to_cart', 'vaporis_add_aroma_incluido_line', 10, 6);
function vaporis_add_aroma_incluido_line($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    if ( empty($cart_item_data['aroma_incluido']) ) return;        // el box no trae aroma
    if ( ! empty($cart_item_data['_is_aroma_incluido']) ) return;  // guard anti-recursión

    $aroma_id = intval($cart_item_data['aroma_incluido']);
    if ( ! vaporis_es_aroma($aroma_id) ) return;

    // Resolver la VARIACIÓN por capacidad (y tipo) que incluye este box (no el cliente).
    $incluido_size    = isset($cart_item_data['aroma_incluido_size']) ? $cart_item_data['aroma_incluido_size'] : vaporis_box_incluido_size($product_id);
    $incluido_tipo    = isset($cart_item_data['aroma_incluido_tipo']) ? $cart_item_data['aroma_incluido_tipo'] : '';
    $variation_id = vaporis_find_aroma_variation($aroma_id, $incluido_size, $incluido_tipo);
    if ( ! $variation_id ) return; // sin variación válida no añadimos el aroma

    // Si el aroma es variable, pasamos sus atributos de variación a add_to_cart.
    $variation = wc_get_product($variation_id);
    $var_attrs = ( $variation && $variation->is_type('variation') ) ? $variation->get_variation_attributes() : [];
    $parent_id = ( $variation && $variation->is_type('variation') ) ? $variation->get_parent_id() : $aroma_id;
    $real_variation_id = ( $variation_id !== $aroma_id ) ? $variation_id : 0;

    // Evitar recursión: añadir el aroma no debe volver a disparar este hook.
    remove_action('woocommerce_add_to_cart', 'vaporis_add_aroma_incluido_line', 10);

    WC()->cart->add_to_cart($parent_id, $quantity, $real_variation_id, $var_attrs, [
        '_is_aroma_incluido' => true,
        '_incluido_for'      => $cart_item_key, // vínculo con la línea del box
        'aroma_incluido_size'=> $incluido_size,
        'unique_key'     => md5('incluido' . microtime(true) . $aroma_id),
    ]);

    add_action('woocommerce_add_to_cart', 'vaporis_add_aroma_incluido_line', 10, 6);
}


/* -------------------------------------------------------------------------
 * 5) Precio 0 para la línea del aroma incluido
 * ---------------------------------------------------------------------- */
add_action('woocommerce_before_calculate_totals', 'vaporis_zero_incluido_price', 20, 1);
function vaporis_zero_incluido_price($cart) {
    if ( is_admin() && ! defined('DOING_AJAX') ) return;
    if ( did_action('woocommerce_before_calculate_totals') >= 2 ) return;

    foreach ( $cart->get_cart() as $item ) {
        if ( ! empty($item['_is_aroma_incluido']) && isset($item['data']) ) {
            $item['data']->set_price(0);
        }
    }
}


/* -------------------------------------------------------------------------
 * 6) UI del carrito: etiqueta "incluido", precio 0.00, cantidad = la del box, sin quitar
 * ---------------------------------------------------------------------- */
add_filter('woocommerce_cart_item_name', 'vaporis_incluido_label', 10, 3);
function vaporis_incluido_label($name, $cart_item, $cart_item_key) {
    if ( ! empty($cart_item['_is_aroma_incluido']) ) {
        $name .= ' <span class="aroma-incluido-badge" style="color:#c0392b;font-weight:600;">'
               . esc_html__('Aroma incluido', 'vaporis') . '</span>';
    }
    return $name;
}

add_filter('woocommerce_cart_item_price', 'vaporis_incluido_price_label', 10, 3);
add_filter('woocommerce_cart_item_subtotal', 'vaporis_incluido_price_label', 10, 3);
function vaporis_incluido_price_label($price, $cart_item, $cart_item_key) {
    if ( ! empty($cart_item['_is_aroma_incluido']) ) {
        return '<span class="aroma-incluido-free">' . wc_price(0) . '</span>';
    }
    return $price;
}

add_filter('woocommerce_cart_item_quantity', 'vaporis_lock_incluido_qty', 10, 3);
function vaporis_lock_incluido_qty($html, $cart_item_key, $cart_item) {
    if ( ! empty($cart_item['_is_aroma_incluido']) ) {
        // Cantidad de solo lectura: sigue a su box (1 aroma por box). Sin input
        // editable para que "Actualizar carrito" no la altere; la reconciliación manda.
        $qty = isset($cart_item['quantity']) ? intval($cart_item['quantity']) : 1;
        return '<span class="aroma-incluido-qty">' . esc_html($qty) . '</span>';
    }
    return $html;
}

add_filter('woocommerce_cart_item_remove_link', 'vaporis_hide_incluido_remove', 10, 2);
function vaporis_hide_incluido_remove($link, $cart_item_key) {
    $cart = WC()->cart ? WC()->cart->get_cart() : [];
    if ( ! empty($cart[$cart_item_key]['_is_aroma_incluido']) ) {
        return ''; // la línea del aroma incluido no se quita por sí sola
    }
    return $link;
}


/* -------------------------------------------------------------------------
 * 7) Sincronizar el aroma incluido con su box (cantidad y eliminación)
 * ---------------------------------------------------------------------- */
add_action('woocommerce_after_cart_item_quantity_update', 'vaporis_sync_incluido_qty', 10, 4);
function vaporis_sync_incluido_qty($cart_item_key, $quantity, $old_quantity, $cart) {
    foreach ( $cart->get_cart() as $key => $item ) {
        if ( ! empty($item['_incluido_for']) && $item['_incluido_for'] === $cart_item_key ) {
            $cart->set_quantity($key, $quantity, false);
        }
    }
}

add_action('woocommerce_remove_cart_item', 'vaporis_remove_linked_incluido', 10, 2);
function vaporis_remove_linked_incluido($cart_item_key, $cart) {
    foreach ( $cart->get_cart() as $key => $item ) {
        if ( ! empty($item['_incluido_for']) && $item['_incluido_for'] === $cart_item_key ) {
            $cart->remove_cart_item($key);
        }
    }
}

/**
 * Reconciliación autoritativa: cada aroma incluido iguala la cantidad de su box
 * (1 aroma por box). Cubre cualquier vía de cambio (carrito, mini-cart, etc.).
 */
add_action('woocommerce_cart_updated', 'vaporis_reconcile_incluido_qty');
function vaporis_reconcile_incluido_qty() {
    $cart = ( function_exists('WC') && WC()->cart ) ? WC()->cart : null;
    if ( ! $cart ) return;

    static $running = false;
    if ( $running ) return; // evita reentradas
    $running = true;

    foreach ( $cart->get_cart() as $key => $item ) {
        if ( empty($item['_incluido_for']) ) continue;
        $box = $cart->get_cart_item($item['_incluido_for']);
        if ( ! $box ) {                       // box eliminado → quitar su aroma
            $cart->remove_cart_item($key);
            continue;
        }
        $box_qty = intval($box['quantity']);
        if ( intval($item['quantity']) !== $box_qty ) {
            $cart->set_quantity($key, $box_qty, false);
        }
    }

    $running = false;
}


/* -------------------------------------------------------------------------
 * 11) Aviso claro de stock del aroma incluido en el carrito (antes del checkout)
 *     WooCommerce ya valida stock; esto añade un mensaje más claro y de marca.
 * ---------------------------------------------------------------------- */
add_action('woocommerce_check_cart_items', 'vaporis_check_incluido_stock');
function vaporis_check_incluido_stock() {
    $cart = ( function_exists('WC') && WC()->cart ) ? WC()->cart : null;
    if ( ! $cart ) return;

    foreach ( $cart->get_cart() as $item ) {
        if ( empty($item['_is_aroma_incluido']) || empty($item['data']) ) continue;
        $product = $item['data'];
        $needed  = intval($item['quantity']);
        $nombre  = $product->get_name();

        if ( $product->managing_stock() && ! $product->backorders_allowed() ) {
            $disponible = $product->get_stock_quantity();
            if ( null !== $disponible && $disponible < $needed ) {
                wc_add_notice( sprintf(
                    /* translators: 1: nombre del aroma, 2: stock disponible, 3: cantidad necesaria */
                    __('El aroma incluido "%1$s" solo tiene %2$d en stock y tu pedido necesita %3$d. Reduce la cantidad del box o elige otro aroma.', 'vaporis'),
                    $nombre, intval($disponible), $needed
                ), 'error' );
            }
        } elseif ( ! $product->is_in_stock() ) {
            wc_add_notice( sprintf(
                /* translators: %s: nombre del aroma */
                __('El aroma incluido "%s" está agotado. Elige otro aroma en el box.', 'vaporis'),
                $nombre
            ), 'error' );
        }
    }
}


/* -------------------------------------------------------------------------
 * 8) Metadatos de trazabilidad
 *    - En la línea del box: qué aroma se incluyó (visible) + ID (oculto).
 *    - En la línea del aroma: marcarla como aroma incluido en el pedido.
 * ---------------------------------------------------------------------- */
add_filter('woocommerce_get_item_data', 'lucia_display_aroma_in_cart', 10, 2);
function lucia_display_aroma_in_cart($item_data, $cart_item) {
    if ( ! empty($cart_item['aroma_incluido_name']) ) {
        $value = $cart_item['aroma_incluido_name'];
        if ( ! empty($cart_item['aroma_incluido_size']) ) {
            $value .= ' (' . $cart_item['aroma_incluido_size'] . ')';
        }
        $item_data[] = [
            'key'   => __('Aroma incluido', 'vaporis'),
            'value' => $value,
        ];
    }
    return $item_data;
}

add_action('woocommerce_checkout_create_order_line_item', 'lucia_save_aroma_to_order', 10, 3);
function lucia_save_aroma_to_order($item, $cart_item_key, $values) {
    // Línea del box: referencia al aroma incluido.
    if ( ! empty($values['aroma_incluido_name']) ) {
        $item->add_meta_data(__('Aroma incluido', 'vaporis'), $values['aroma_incluido_name']);
    }
    if ( ! empty($values['aroma_incluido']) ) {
        $item->add_meta_data('_aroma_incluido_id', intval($values['aroma_incluido']), true);
    }
    // Línea del aroma: marcarla como aroma incluido.
    if ( ! empty($values['_is_aroma_incluido']) ) {
        $item->add_meta_data(__('Tipo', 'vaporis'), __('Aroma incluido en el box', 'vaporis'));
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
