<?php
/**
 * Plugin Name: WooCommerce Customer Pricing
 * Plugin URI: https://fyrware.com/
 * Description: Allow customers to set custom prices for specified products (Could be used for donations)
 * Author: Fyrware
 * Version: 1.0.0
 * License: MIT
 * Author URI: https://fyrware.com
 * Text Domain: woocommerce_customer_pricing
 */

/**********************************************************************************************************************/

if (!function_exists('wcp_render_pricing_options')) {
    /**
     * Render custom fields on product edit page
     * @return void
     */
    function wcp_render_pricing_options(): void {
        global $product_object;
        $allow_customer_price = get_post_meta($product_object->get_id(), '_wcp_allow_customer_set_price', true);
        $minimum_price = get_post_meta($product_object->get_id(), '_wcp_minimum_price', true); ?>

        <?php wcp_horizontal_rule(); ?>

        <?php woocommerce_wp_checkbox(
            array(
                'id' => '_wcp_allow_customer_set_price',
                'label' => __('Allow customer to set product price?', 'woocommerce_customer_pricing'),
                'description' => __('If enabled, customers will be able to set a custom price before adding product to cart.', 'woocommerce_customer_pricing'),
                'desc_tip' => true,
                'value' => $allow_customer_price,
            )
        ); ?>

        <?php woocommerce_wp_text_input(
            array(
                'id' => '_wcp_minimum_price',
                'label' => __('Minimum price', 'woocommerce_customer_pricing') . ' (' . get_woocommerce_currency_symbol() . ')',
                'data_type' => 'price',
                'value' => $minimum_price,
                'custom_attributes' => $allow_customer_price !== 'yes' ? array(
                    'disabled' => true,
                ) : array()
            )
        ); ?>

        <script>
            document.getElementById('_wcp_allow_customer_set_price').addEventListener('change', function(event) {
                document.getElementById('_wcp_minimum_price').disabled = !event.target.checked;
            });
        </script>
    <?php }
}

add_action('woocommerce_product_options_pricing', 'wcp_render_pricing_options', 10, 0);

/**********************************************************************************************************************/

if (!function_exists('wcp_save_product_meta_fields')) {
    /**
     * Save custom fields on product edit page
     */
    function wcp_save_product_meta_fields($post_id): void {
        $allow_customer_set_price = esc_attr($_POST['_wcp_allow_customer_set_price']);
        $minimum_price = esc_attr($_POST['_wcp_minimum_price']);

        update_post_meta($post_id, '_wcp_allow_customer_set_price', $allow_customer_set_price);
        update_post_meta($post_id, '_wcp_minimum_price', $minimum_price);
    }
}

add_action('woocommerce_process_product_meta', 'wcp_save_product_meta_fields', 10, 1);

/**********************************************************************************************************************/

if (!function_exists('wcp_set_dynamic_price')) {
    /**
     * Set dynamic price on cart item metadata
     * @param $cart_item_data
     * @param $cart_item_key
     * @return mixed
     */
    function wcp_set_dynamic_price($cart_item_data, $cart_item_key): mixed {
        if (isset($_POST['wcp_custom_price'])) {
            $cart_item_data['wcp_custom_price'] = $_POST['wcp_custom_price'];
        }
        return $cart_item_data;
    }
}

add_filter('woocommerce_add_cart_item', 'wcp_set_dynamic_price', 10, 2);

/**********************************************************************************************************************/

if (!function_exists('wcp_set_dynamic_price_in_cart')) {
    /**
     * Apply custom price from item metadata to item price
     * @param $wc_cart
     */
    function wcp_set_dynamic_price_in_cart($wc_cart): void {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        foreach ($wc_cart->get_cart() as $cart_item) {
            if (!empty($cart_item['wcp_custom_price'])) {
                $cart_item['data']->set_price($cart_item['wcp_custom_price']);
            }
        }
    }
}

add_action('woocommerce_before_calculate_totals', 'wcp_set_dynamic_price_in_cart', 10, 1);

/**********************************************************************************************************************/

if (!function_exists('wcp_allow_purchase_if_customer_price_allowed')) {
    /**
     * Allow item to still be purchased if customer is allowed to set the price
     * @param bool $purchasable
     * @param WC_Product $product
     * @return bool
     */
    function wcp_allow_purchase_if_customer_price_allowed(bool $purchasable, WC_Product $product): bool {
        return $purchasable
            || $product->exists()
            && ($product->get_status() === 'publish' || current_user_can('edit_post', $product->get_id()))
            && ($product->get_price() !== '' || $product->get_meta('_wcp_allow_customer_set_price') == 'yes');
    }
}

add_filter('woocommerce_is_purchasable', 'wcp_allow_purchase_if_customer_price_allowed', 10, 2);

/**********************************************************************************************************************/

if (!function_exists('wcp_render_dynamic_price_input')) {
    /**
     * Render custom price input on product page
     * @return void
     */
    function wcp_render_dynamic_price_input(): void {
        global $product;
        $allow_customer_price = get_post_meta($product->get_id(), '_wcp_allow_customer_set_price', true);
        $minimum_price = get_post_meta($product->get_id(), '_wcp_minimum_price', true);

        if ($allow_customer_price !== 'yes') {
            return;
        } ?>

        <div class="wcp-custom-price">
            <label for="wcp_custom_price">
                <?php echo __('Price', 'woocommerce_customer_pricing') . ' (' . get_woocommerce_currency_symbol() . ')'; ?>
            </label>
            <input
                type="number"
                min="<?php echo $minimum_price ?>"
                value="<?php echo $minimum_price ?>"
                id="wcp_custom_price"
                name="wcp_custom_price"
            />
        </div>
    <?php }
}

add_action('woocommerce_before_add_to_cart_button', 'wcp_render_dynamic_price_input', 10, 1);

/**********************************************************************************************************************/

if (!function_exists('wcp_validate_customer_price_against_minimum')) {
    /**
     * Validate cart price against minimum price to protect against users modifying HTML in dev tools
     * @param $valid
     * @param $product_id
     * @param $quantity
     * @return bool|mixed
     */
    function wcp_validate_customer_price_against_minimum($valid, $product_id, $quantity) {
        $cart = WC()->cart;
        $product = wc_get_product($product_id);
        $allow_customer_price = $product->get_meta('_wcp_allow_customer_set_price');
        $minimum_price = $product->get_meta('_wcp_minimum_price');

        if ($valid && $allow_customer_price && !empty($minimum_price)) {
            $customer_price = $_POST['wcp_custom_price'];

            if ($customer_price < $minimum_price) {
                wc_add_notice(__('Minimum price requirement not met', 'woocommerce_customer_pricing'),'error');
            }

            return $customer_price >= $minimum_price;
        }

        return $valid;
    }
}

add_filter('woocommerce_add_to_cart_validation', 'wcp_validate_customer_price_against_minimum', 10, 3);

/**********************************************************************************************************************/

if (!function_exists('wcp_use_customer_price_in_cart')) {
    /**
     * Use customer price in the cart interface instead of the default price field
     * @param string $price
     * @param array $cart_item
     * @param $cart_item_key
     * @return string
     */
    function wcp_use_customer_price_in_cart(string $price, array $cart_item, $cart_item_key): string {
        if (!empty($cart_item['wcp_custom_price'])) {
            return wc_price($cart_item['wcp_custom_price']);
        }
        return $price;
    }
}

add_filter('woocommerce_cart_item_price', 'wcp_use_customer_price_in_cart', 10, 3);

/**********************************************************************************************************************/

function wcp_horizontal_rule(): void {
    echo '<hr style="border-top-color: #ffffff; border-bottom-color: #eeeeee;"/>';
}
