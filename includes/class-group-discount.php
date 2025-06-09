<?php
/**
 * Main plugin class
 */
class Group_Discount {

    /**
     * Initialize the plugin
     */
    public function init() {
        // Check if we have the required classes
        if (!class_exists('WooCommerce')) {
            group_discount_debug_log('WooCommerce not found, aborting initialization');
            return;
        }

        group_discount_debug_log('Initializing plugin hooks');

        // Add product tab
        add_filter('woocommerce_product_data_tabs', array($this, 'add_group_discount_product_tab'));
        
        // Add product tab content
        add_action('woocommerce_product_data_panels', array($this, 'add_group_discount_product_tab_content'));
        
        // Save custom fields
        add_action('woocommerce_process_product_meta', array($this, 'save_group_discount_fields'));

        // Load admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Modify product prices - use later hook to avoid early loading issues
        add_filter('woocommerce_product_get_price', array($this, 'apply_group_discount'), 15, 2);
        add_filter('woocommerce_product_get_regular_price', array($this, 'apply_group_discount'), 15, 2);
        
        // Modify product variation prices
        add_filter('woocommerce_product_variation_get_price', array($this, 'apply_group_discount'), 15, 2);
        add_filter('woocommerce_product_variation_get_regular_price', array($this, 'apply_group_discount'), 15, 2);
        
        // Show discount label on product page
        add_action('woocommerce_single_product_summary', array($this, 'show_discount_label'), 11);
        
        // Add sale badge for discounted products
        add_filter('woocommerce_product_is_on_sale', array($this, 'is_product_on_sale'), 15, 2);
        
        // Handle AJAX requests
        add_action('wp_ajax_group_discount_flush_cache', array($this, 'ajax_flush_cache'));
        add_action('wp_ajax_nopriv_group_discount_refresh_price', array($this, 'ajax_refresh_price'));
        add_action('wp_ajax_group_discount_refresh_price', array($this, 'ajax_refresh_price'));
        add_action('wp_ajax_group_discount_clear_all_caches', array($this, 'ajax_clear_all_caches'));
        
        // Add admin notices
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // Add diagnostic information
        add_action('admin_notices', array($this, 'add_diagnostic_info'));
        
        // Enqueue frontend scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        
        group_discount_debug_log('Plugin initialization complete');
    }
    
    /**
     * Add Group Discount product tab
     * 
     * @param array $tabs Product data tabs array
     * @return array Modified tabs array
     */
    public function add_group_discount_product_tab($tabs) {
        $tabs['group_discount'] = array(
            'label'     => __('Group Discount', 'group-discount'),
            'target'    => 'group_discount_product_data',
            'class'     => array('show_if_simple'), // Only show for simple products
            'priority'  => 21, // After inventory tab
        );
        return $tabs;
    }

    /**
     * Add Group Discount tab content
     */
    public function add_group_discount_product_tab_content() {
        global $post;
        
        // Get saved values
        $enabled = get_post_meta($post->ID, '_group_discount_enabled', true);
        $tiers = get_post_meta($post->ID, '_group_discount_tiers', true);
        $debug_mode = get_post_meta($post->ID, '_group_discount_debug_mode', true);
        
        if (empty($tiers) || !is_array($tiers)) {
            $tiers = array(
                array('quantity' => 100, 'price' => ''),
                array('quantity' => 1000, 'price' => ''),
            );
        }
        
        // Get current sales count
        $total_sold = $this->get_total_quantity_sold($post->ID);
        
        // Get order statistics for debugging
        $order_stats = $this->get_order_statistics($post->ID);
        
        ?>
        <div id="group_discount_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <?php
                woocommerce_wp_checkbox(array(
                    'id'          => '_group_discount_enabled',
                    'label'       => __('Enable Group Discount', 'group-discount'),
                    'desc_tip'    => true,
                    'description' => __('Enable tiered pricing based on total sales quantity.', 'group-discount'),
                    'value'       => $enabled,
                ));
                
                woocommerce_wp_checkbox(array(
                    'id'          => '_group_discount_debug_mode',
                    'label'       => __('Debug Mode', 'group-discount'),
                    'desc_tip'    => true,
                    'description' => __('Enable debug mode to show detailed order statistics.', 'group-discount'),
                    'value'       => $debug_mode,
                ));
                ?>
                
                <div class="group-discount-tiers-wrapper" style="padding: 0 12px;">
                    <h4><?php _e('Quantity Tiers', 'group-discount'); ?></h4>
                    <p class="description"><?php _e('Set price tiers based on total quantity sold. Lower prices for higher quantities.', 'group-discount'); ?></p>
                    
                    <div class="group-discount-current-sales">
                        <p><strong><?php _e('Current Sales Count:', 'group-discount'); ?></strong> <?php echo $total_sold; ?> <?php _e('units', 'group-discount'); ?></p>
                        <p>
                            <a href="#" class="button flush-cache-button" data-product-id="<?php echo esc_attr($post->ID); ?>"><?php _e('Refresh Sales Count', 'group-discount'); ?></a>
                            <span class="spinner" style="float: none; margin-top: 0;"></span>
                        </p>
                        <div class="sales-count-message"></div>
                        
                        <?php if ($debug_mode === 'yes') : ?>
                            <div class="order-statistics">
                                <h4><?php _e('Order Statistics (Debug Mode)', 'group-discount'); ?></h4>
                                <table class="widefat">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Order Status', 'group-discount'); ?></th>
                                            <th><?php _e('Quantity', 'group-discount'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($order_stats as $status => $count) : ?>
                                            <?php if ($status !== 'counted_total') : ?>
                                                <tr <?php echo (in_array($status, array('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'cart-current'))) ? 'class="included"' : ''; ?>>
                                                    <td>
                                                        <?php if ($status === 'cart-current') : ?>
                                                            <?php _e('Current Cart', 'group-discount'); ?>
                                                        <?php else : ?>
                                                            <?php echo ucfirst(str_replace('wc-', '', $status)); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo $count; ?></td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <tr class="total">
                                            <td><strong><?php _e('Counted Total (Completed + Processing + On-hold + Pending + Cart)', 'group-discount'); ?></strong></td>
                                            <td><strong><?php echo $order_stats['counted_total']; ?></strong></td>
                                        </tr>
                                    </tbody>
                                </table>
                                <p class="description"><?php _e('Note: Only Completed, Processing, and On-hold orders are counted for pricing tiers.', 'group-discount'); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="group-discount-tiers">
                        <div class="tier-header">
                            <div class="tier-quantity"><?php _e('Quantity', 'group-discount'); ?></div>
                            <div class="tier-price"><?php _e('Price', 'group-discount'); ?></div>
                            <div class="tier-action"></div>
                        </div>
                        
                        <?php foreach ($tiers as $i => $tier) : ?>
                        <div class="tier-row">
                            <div class="tier-quantity">
                                <input type="number" name="_group_discount_tiers[<?php echo $i; ?>][quantity]" 
                                    value="<?php echo esc_attr($tier['quantity']); ?>" min="1" step="1" />
                            </div>
                            <div class="tier-price">
                                <input type="text" name="_group_discount_tiers[<?php echo $i; ?>][price]" 
                                    value="<?php echo esc_attr($tier['price']); ?>" class="wc_input_price" />
                            </div>
                            <div class="tier-action">
                                <button type="button" class="button remove-tier"><?php _e('Remove', 'group-discount'); ?></button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <div class="tier-actions">
                            <button type="button" class="button add-tier"><?php _e('Add Tier', 'group-discount'); ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Save Group Discount fields
     * 
     * @param int $post_id Product ID
     */
    public function save_group_discount_fields($post_id) {
        // Save enabled status
        $enabled = isset($_POST['_group_discount_enabled']) ? 'yes' : 'no';
        update_post_meta($post_id, '_group_discount_enabled', $enabled);
        
        // Save debug mode
        $debug_mode = isset($_POST['_group_discount_debug_mode']) ? 'yes' : 'no';
        update_post_meta($post_id, '_group_discount_debug_mode', $debug_mode);
        
        // Save tiers
        $tiers = array();
        
        if (isset($_POST['_group_discount_tiers']) && is_array($_POST['_group_discount_tiers'])) {
            foreach ($_POST['_group_discount_tiers'] as $tier) {
                if (!empty($tier['quantity'])) {
                    $tiers[] = array(
                        'quantity' => absint($tier['quantity']),
                        'price'    => wc_format_decimal($tier['price']),
                    );
                }
            }
        }
        
        // Sort tiers by quantity (ascending)
        usort($tiers, function($a, $b) {
            return $a['quantity'] - $b['quantity'];
        });
        
        update_post_meta($post_id, '_group_discount_tiers', $tiers);
        
        // Clear cache for this product
        Group_Discount_Cache::delete('total_sold_' . $post_id);
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only on product edit page
        if (!in_array($hook, array('post.php', 'post-new.php'))) {
            return;
        }
        
        global $post;
        
        // Only for product post type
        if (isset($post) && $post->post_type != 'product') {
            return;
        }
        
        // Enqueue styles
        wp_enqueue_style('group-discount-admin', GD_PLUGIN_URL . 'assets/css/admin.css', array(), GD_PLUGIN_VERSION);
        
        // Enqueue scripts
        wp_enqueue_script('group-discount-admin', GD_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), GD_PLUGIN_VERSION, true);
        
        // Localize script
        wp_localize_script('group-discount-admin', 'group_discount_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('group-discount-nonce'),
            'i18n' => array(
                'quantity' => __('Quantity', 'group-discount'),
                'price'    => __('Price', 'group-discount'),
                'remove'   => __('Remove', 'group-discount'),
                'refreshing' => __('Refreshing...', 'group-discount'),
                'refresh_success' => __('Sales count refreshed!', 'group-discount'),
                'refresh_error' => __('Error refreshing sales count.', 'group-discount'),
            ),
        ));
    }
    
    /**
     * Apply group discount to product price
     *
     * @param float $price Product price
     * @param object $product WC_Product object
     * @return float Modified price
     */
    public function apply_group_discount($price, $product) {
        // Check if group discount is enabled for this product
        $enabled = get_post_meta($product->get_id(), '_group_discount_enabled', true);
        
        if ($enabled !== 'yes' || empty($price)) {
            return $price;
        }
        
        // Get tier prices
        $tiers = get_post_meta($product->get_id(), '_group_discount_tiers', true);
        
        if (empty($tiers) || !is_array($tiers)) {
            return $price;
        }
        
        // Get total quantity sold
        $total_sold = $this->get_total_quantity_sold($product->get_id());
        
        // Find applicable tier price
        $tier_price = $this->get_tier_price($tiers, $total_sold);
        
        // Return tier price or original price if no tier applies
        return $tier_price !== false ? $tier_price : $price;
    }
    
    /**
     * Get total quantity sold for a product
     *
     * @param int $product_id Product ID
     * @return int Total quantity sold
     */
    private function get_total_quantity_sold($product_id) {
        // Log for debugging
        group_discount_debug_log('Getting total quantity sold for product', $product_id);
        
        // Generate a consistent cache key
        $cache_key = 'total_sold_' . $product_id;
        
        // Always bypass cache to ensure we get fresh counts while debugging
        $bypass_cache = true;
        
        // Check cache first if not bypassing
        if (!$bypass_cache) {
            $total_sold = Group_Discount_Cache::get($cache_key);
            if ($total_sold !== false) {
                group_discount_debug_log('Returning cached total for product ' . $product_id, $total_sold);
                return $total_sold;
            }
        } else {
            group_discount_debug_log('Bypassing cache for product ' . $product_id);
        }
        
        // Get order statistics directly - this includes all query methods
        $order_stats = $this->get_order_statistics($product_id);
        
        // Sum up all the order quantities from valid statuses
        $db_total = 0;
        $counted_statuses = array('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending');
        
        foreach ($counted_statuses as $status) {
            if (isset($order_stats[$status])) {
                $db_total += $order_stats[$status];
                group_discount_debug_log("Adding {$order_stats[$status]} from status {$status}");
            }
        }
        
        group_discount_debug_log("Total from order database: {$db_total}");
        
        // Use WC_Data_Store for HPOS compatibility if available
        $hpos_total = $this->get_total_quantity_hpos($product_id);
        group_discount_debug_log("Total from HPOS method: {$hpos_total}");
        
        // Fall back to direct database query if HPOS method returns 0 or fails
        $direct_total = $this->get_total_quantity_direct($product_id);
        group_discount_debug_log("Total from direct query: {$direct_total}");
        
        // Get current cart quantities for this product
        $cart_total = $this->get_cart_quantity_for_product($product_id);
        group_discount_debug_log("Total from current cart: {$cart_total}");
        
        // Also check the current order being processed (if any)
        $current_order_total = $this->get_current_order_quantity($product_id);
        group_discount_debug_log("Total from current order: {$current_order_total}");
        
        // Use the highest value from all methods
        $total_sold = max($db_total, $hpos_total, $direct_total);
        
        // Combine totals and ensure it's a positive number
        $total_sold = max(0, $total_sold + $cart_total + $current_order_total);
        
        // Extremely direct count from database
        $direct_count = $this->count_orders_directly($product_id);
        group_discount_debug_log("Extremely direct count from database: {$direct_count}");
        
        // If we have a direct count and it's higher, use it
        if ($direct_count > $total_sold) {
            $total_sold = $direct_count;
            group_discount_debug_log("Using direct count as it's higher: {$direct_count}");
        }
        
        // For debugging
        group_discount_debug_log('Final total for product #' . $product_id . ': ' . $total_sold . ' (Database: ' . $total_sold - $cart_total - $current_order_total . ', Cart: ' . $cart_total . ', Current Order: ' . $current_order_total . ')');
        
        // Cache the result, but for a very short time (10 seconds during debugging)
        Group_Discount_Cache::set($cache_key, $total_sold, 10);
        
        return $total_sold;
    }
    
    /**
     * Get total quantity using WC_Data_Store (HPOS compatible)
     * 
     * @param int $product_id Product ID
     * @return int Total quantity
     */
    private function get_total_quantity_hpos($product_id) {
        try {
            if (!function_exists('WC') || !class_exists('WC_Data_Store')) {
                return 0;
            }
            
            group_discount_debug_log('Trying HPOS method for product ' . $product_id);
            
            // Get all statuses we want to include
            $statuses = array('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending');
            
            // Use data store to get orders
            $data_store = WC_Data_Store::load('report-orders-stats');
            
            if (!$data_store) {
                return 0;
            }
            
            $args = array(
                'status' => $statuses,
                'products' => array($product_id),
            );
            
            $report_data = $data_store->get_data($args);
            
            if (!empty($report_data->data) && isset($report_data->data[0]->items_sold)) {
                group_discount_debug_log('HPOS method returned items sold for product ' . $product_id, $report_data->data[0]->items_sold);
                return absint($report_data->data[0]->items_sold);
            }
            
            return 0;
        } catch (Exception $e) {
            group_discount_debug_log('Error using HPOS method for product ' . $product_id, $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get total quantity using direct database query
     * 
     * @param int $product_id Product ID
     * @return int Total quantity
     */
    private function get_total_quantity_direct($product_id) {
        global $wpdb;
        
        try {
            group_discount_debug_log('Using direct DB query for product ' . $product_id);
            
            // Get completed, processing, on-hold, and pending orders for this product
            $completed_query = $wpdb->prepare("
                SELECT SUM(order_item_meta.meta_value) as total
                FROM {$wpdb->prefix}woocommerce_order_items as order_items
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as product_id_meta ON order_items.order_item_id = product_id_meta.order_item_id
                LEFT JOIN {$wpdb->posts} as posts ON posts.ID = order_items.order_id
                WHERE posts.post_type = 'shop_order'
                AND posts.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending')
                AND order_items.order_item_type = 'line_item'
                AND order_item_meta.meta_key = '_qty'
                AND product_id_meta.meta_key IN ('_product_id', '_variation_id')
                AND product_id_meta.meta_value = %d
            ", $product_id);
            
            // Also try to get HPOS orders if the custom order tables are being used
            $hpos_query = "";
            $hpos_enabled = false;
            
            // Check for HPOS in a way that's compatible with older WooCommerce versions
            if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil') && 
                method_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil', 'is_feature_enabled') &&
                \Automattic\WooCommerce\Utilities\FeaturesUtil::is_feature_enabled('custom_order_tables')) {
                
                $hpos_enabled = true;
                
                // This query is for HPOS (High-Performance Order Storage)
                $hpos_query = $wpdb->prepare("
                    SELECT SUM(order_item_meta.meta_value) as total
                    FROM {$wpdb->prefix}woocommerce_order_items as order_items
                    LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
                    LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as product_id_meta ON order_items.order_item_id = product_id_meta.order_item_id
                    LEFT JOIN {$wpdb->prefix}wc_orders as wc_orders ON wc_orders.id = order_items.order_id
                    WHERE wc_orders.type = 'shop_order'
                    AND wc_orders.status IN ('completed', 'processing', 'on-hold', 'pending')
                    AND order_items.order_item_type = 'line_item'
                    AND order_item_meta.meta_key = '_qty'
                    AND product_id_meta.meta_key IN ('_product_id', '_variation_id')
                    AND product_id_meta.meta_value = %d
                ", $product_id);
            }
            
            // Get total from regular orders
            $completed_total = $wpdb->get_var($completed_query);
            $completed_total = absint($completed_total);
            
            // Get total from HPOS orders if applicable
            $hpos_total = 0;
            if (!empty($hpos_query)) {
                $hpos_total = $wpdb->get_var($hpos_query);
                $hpos_total = absint($hpos_total);
                group_discount_debug_log('HPOS query total for product #' . $product_id, $hpos_total);
            }
            
            // Combine the totals
            $total = $completed_total + $hpos_total;
            
            group_discount_debug_log('Direct DB query total for product #' . $product_id, $total . ' (Regular: ' . $completed_total . ', HPOS: ' . $hpos_total . ')');
            
            return $total;
            
        } catch (Exception $e) {
            group_discount_debug_log('Error in direct DB query for product ' . $product_id, $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get quantity in the order currently being processed
     *
     * @param int $product_id Product ID
     * @return int Order quantity
     */
    private function get_current_order_quantity($product_id) {
        global $woocommerce;
        
        try {
            // If we're not in checkout or processing an order, return 0
            if (!function_exists('WC') || !is_checkout()) {
                return 0;
            }
            
            group_discount_debug_log('Checking current order quantity for product ' . $product_id);
            
            // If WC session is available, check for order awaiting payment
            if (isset($woocommerce->session)) {
                $order_id = $woocommerce->session->get('order_awaiting_payment');
                if ($order_id) {
                    $order = wc_get_order($order_id);
                    if ($order) {
                        $qty = 0;
                        foreach ($order->get_items() as $item) {
                            if ($item->get_product_id() == $product_id) {
                                $qty += $item->get_quantity();
                            }
                        }
                        
                        group_discount_debug_log('Current order quantity for product ' . $product_id, $qty);
                        return $qty;
                    }
                }
            }
            
            return 0;
        } catch (Exception $e) {
            group_discount_debug_log('Error getting current order quantity for product ' . $product_id, $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get current cart quantity for a product
     *
     * @param int $product_id Product ID
     * @return int Cart quantity
     */
    private function get_cart_quantity_for_product($product_id) {
        // Validate product ID
        if (empty($product_id) || !is_numeric($product_id)) {
            return 0;
        }
        
        // Check if WooCommerce is active and cart is available
        if (!function_exists('WC') || !isset(WC()->cart) || !is_object(WC()->cart) || WC()->cart->is_empty()) {
            return 0;
        }
        
        try {
            $cart_qty = 0;
            
            foreach (WC()->cart->get_cart() as $cart_item) {
                if (isset($cart_item['product_id']) && $cart_item['product_id'] == $product_id) {
                    $cart_qty += isset($cart_item['quantity']) ? (int)$cart_item['quantity'] : 0;
                }
            }
            
            return $cart_qty;
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Group Discount - Error getting cart quantity: ' . $e->getMessage());
            }
            return 0;
        }
    }
    
    /**
     * Get applicable tier price based on quantity sold
     *
     * @param array $tiers Tier data
     * @param int $quantity_sold Total quantity sold
     * @return float|bool Tier price or false if no tier applies
     */
    private function get_tier_price($tiers, $quantity_sold) {
        // Sort tiers by quantity (descending)
        usort($tiers, function($a, $b) {
            return $b['quantity'] - $a['quantity'];
        });
        
        // Find applicable tier
        foreach ($tiers as $tier) {
            if ($quantity_sold >= $tier['quantity'] && !empty($tier['price'])) {
                return $tier['price'];
            }
        }
        
        return false;
    }
    
    /**
     * Show discount label on product page
     */
    public function show_discount_label() {
        global $product;
        
        if (!$product) {
            return;
        }
        
        // Check if group discount is enabled
        $enabled = get_post_meta($product->get_id(), '_group_discount_enabled', true);
        
        if ($enabled !== 'yes') {
            return;
        }
        
        // Get tier prices
        $tiers = get_post_meta($product->get_id(), '_group_discount_tiers', true);
        
        if (empty($tiers) || !is_array($tiers)) {
            return;
        }
        
        // Ensure translations are properly loaded
        $this->ensure_translations_loaded();
        
        // Check if this is a Traditional Chinese site
        $is_traditional_chinese = false;
        $locale = get_locale();
        if (in_array($locale, array('zh_TW', 'zh-TW', 'zh_HK', 'zh-HK'))) {
            $is_traditional_chinese = true;
            group_discount_debug_log('Traditional Chinese site detected in show_discount_label');
        }
        
        // Force load Chinese translations if needed
        if (function_exists('group_discount_detect_chinese_site') && group_discount_detect_chinese_site()) {
            $locale = get_locale();
            $zh_variant = (strpos($locale, 'zh_TW') === 0 || strpos($locale, 'zh-TW') === 0) ? 'zh_TW' : 'zh_CN';
            $mo_file = GD_PLUGIN_PATH . 'languages/group-discount-' . $zh_variant . '.mo';
            
            if (file_exists($mo_file)) {
                load_textdomain('group-discount', $mo_file);
                group_discount_debug_log('Force-loaded Chinese translation in show_discount_label: ' . $mo_file);
            }
        }
        
        // Get total quantity sold
        $total_sold = $this->get_total_quantity_sold($product->get_id());
        
        // Get the true regular price using our new method
        $regular_price = $this->get_true_regular_price($product);
        
        // Format the regular price
        $formatted_regular_price = wc_price($regular_price);
        
        // Sort tiers by quantity (ascending)
        usort($tiers, function($a, $b) {
            return $a['quantity'] - $b['quantity'];
        });
        
        // Get current tier
        $current_tier = false;
        $next_tier = false;
        
        foreach ($tiers as $tier) {
            if ($total_sold >= $tier['quantity'] && !empty($tier['price'])) {
                $current_tier = $tier;
            } elseif (!$next_tier && !empty($tier['price'])) {
                $next_tier = $tier;
            }
        }
        
        // Display current tier info
        if ($current_tier) {
            $tier_price = $current_tier['price'];
            $savings = $regular_price - $tier_price;
            $savings_percent = ($savings / $regular_price) * 100;
            
            echo '<div class="group-discount-label">';
            
            // First paragraph - ONLY units sold info, no price info here
            printf(
                '<p class="group-discount-units-sold">%s <span class="group-discount-savings-badge">%s</span></p>',
                sprintf(
                    /* translators: %s: number of units sold */
                    __('%s units already sold', 'group-discount'),
                    '<strong>' . number_format_i18n($total_sold) . '</strong>'
                ),
                sprintf(
                    /* translators: %s: discount percentage */
                    __('Save %s', 'group-discount'),
                    '<strong>' . number_format_i18n($savings_percent, 2) . '%</strong>'
                )
            );
            
            // Second paragraph - ONLY price comparison info with enhanced HTML structure - TWO ROW FORMAT
            // First row - Original price
            $original_price_label = __('Original price', 'group-discount');
            $original_price_html = '<span class="original-price">' . $formatted_regular_price . '</span>';
            
            // Second row - Current price
            $current_price_label = __('Current price', 'group-discount');
            $current_price_html = '<strong>' . wc_price($tier_price) . '</strong>';
            
            // Split the savings text into parts for better translation support
            $you_save_text = __('You save', 'group-discount');
            $per_unit_text = __('per unit', 'group-discount');
            $savings_text_html = '<span class="savings-text">(' . $you_save_text . ' ' . wc_price($savings) . ' ' . $per_unit_text . ')</span>';
            
            // Two-row display for better user experience
            if ($is_traditional_chinese) {
                // Direct Traditional Chinese strings for immediate display without translation loading
                $original_price_label = '原價';
                $current_price_label = '現價';
                $savings_text_html = '<span class="savings-text">(每件節省 ' . wc_price($savings) . ')</span>';
                
                group_discount_debug_log('Using direct Traditional Chinese strings in show_discount_label');
                
                printf(
                    '<p class="group-discount-price-comparison gd-two-row">' .
                    '<span class="gd-original-price-row">%s: %s</span>' .
                    '<span class="gd-current-price-row">%s: %s %s</span>' .
                    '</p>',
                    $original_price_label,
                    $original_price_html,
                    $current_price_label,
                    $current_price_html,
                    $savings_text_html
                );
            } else {
                // Normal display for other languages
                printf(
                    '<p class="group-discount-price-comparison gd-two-row">' .
                    '<span class="gd-original-price-row">%s: %s</span>' .
                    '<span class="gd-current-price-row">%s: %s %s</span>' .
                    '</p>',
                    $original_price_label,
                    $original_price_html,
                    $current_price_label,
                    $current_price_html,
                    $savings_text_html
                );
            }
            
            echo '</div>';
        }
        
        // Display next tier info
        if ($next_tier) {
            $remaining = $next_tier['quantity'] - $total_sold;
            $next_savings = $regular_price - $next_tier['price'];
            $next_savings_percent = ($next_savings / $regular_price) * 100;
            
            echo '<div class="group-discount-next-tier">';
            
            // First paragraph - Next discount tier
            // Check if we're dealing with Traditional Chinese
            if ($is_traditional_chinese) {
                // Direct hardcoded Traditional Chinese to prevent duplication
                echo '<p class="group-discount-next-tier-info">';
                echo '下一個折扣在 <strong>' . number_format_i18n($next_tier['quantity']) . '</strong> 件 ';
                echo '<span class="group-discount-savings-badge group-discount-next-savings-badge">';
                echo '節省 <strong>' . number_format_i18n($next_tier_savings_percent, 2) . '%</strong>';
                echo '</span></p>';
            } else {
                printf(
                    '<p class="group-discount-next-tier-info">%s <span class="group-discount-savings-badge group-discount-next-savings-badge">%s</span></p>',
                    sprintf(
                        /* translators: %s: quantity needed for next tier */
                        __('Next discount at %s units', 'group-discount'),
                        '<strong>' . number_format_i18n($next_tier['quantity']) . '</strong>'
                    ),
                    sprintf(
                        /* translators: %s: next tier discount percentage */
                        __('Save %s', 'group-discount'),
                        '<strong>' . number_format_i18n($next_tier_savings_percent, 2) . '%</strong>'
                    )
                );
            }
            
            // Second paragraph - Units needed
            // Check if we're dealing with Traditional Chinese
            if ($is_traditional_chinese) {
                // Direct hardcoded Traditional Chinese to prevent duplication
                echo '<p class="group-discount-next-tier-price">';
                echo '只需再購買 <strong>' . number_format_i18n($remaining) . '</strong> 件即可解鎖價格：每件 <strong>' . wc_price($next_tier['price']) . '</strong>';
                echo '</p>';
            } else {
                printf(
                    '<p class="group-discount-next-tier-price">%s</p>',
                    sprintf(
                        /* translators: %1$s: number of units needed, %2$s: discounted price */
                        __('Only %1$s more units needed to unlock price: %2$s per unit', 'group-discount'),
                        '<strong>' . number_format_i18n($remaining) . '</strong>',
                        '<strong>' . wc_price($next_tier['price']) . '</strong>'
                    )
                );
            }
            
            echo '</div>';
        }
    }
    
    /**
     * Mark product as on sale when group discount applies
     *
     * @param bool $on_sale Whether the product is on sale
     * @param object $product WC_Product object
     * @return bool Modified on sale status
     */
    public function is_product_on_sale($on_sale, $product) {
        if ($on_sale) {
            return $on_sale;
        }
        
        // Check if group discount is enabled
        $enabled = get_post_meta($product->get_id(), '_group_discount_enabled', true);
        
        if ($enabled !== 'yes') {
            return $on_sale;
        }
        
        // Get tier prices
        $tiers = get_post_meta($product->get_id(), '_group_discount_tiers', true);
        
        if (empty($tiers) || !is_array($tiers)) {
            return $on_sale;
        }
        
        // Get total quantity sold
        $total_sold = $this->get_total_quantity_sold($product->get_id());
        
        // Find applicable tier price
        $tier_price = $this->get_tier_price($tiers, $total_sold);
        
        if ($tier_price === false) {
            return $on_sale;
        }
        
        // Get regular price
        $regular_price = $product->get_regular_price();
        
        // Check if tier price is less than regular price
        return $tier_price < $regular_price;
    }
    
    /**
     * Handle AJAX request to flush cache
     */
    public function ajax_flush_cache() {
        // Start output buffering to catch any errors
        ob_start();
        
        try {
            // Check nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'group-discount-nonce')) {
                group_discount_debug_log('Invalid nonce in flush_cache AJAX request');
                wp_send_json_error('Invalid nonce');
                exit;
            }
            
            // Check product ID
            if (!isset($_POST['product_id']) || empty($_POST['product_id'])) {
                group_discount_debug_log('Invalid product ID in flush_cache AJAX request');
                wp_send_json_error('Invalid product ID');
                exit;
            }
            
            $product_id = absint($_POST['product_id']);
            group_discount_debug_log('Processing flush_cache AJAX request for product', $product_id);
            
            // Delete all caches for this product
            Group_Discount_Cache::delete('total_sold_' . $product_id);
            
            // Clear WooCommerce cache as well
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product_id);
                group_discount_debug_log('Cleared WooCommerce transients for product ' . $product_id);
            }
            
            // Force clear the object cache
            if (function_exists('wp_cache_delete')) {
                $cache_keys = array(
                    'total_sold_' . $product_id,
                    'wc_product_' . $product_id,
                    'product_' . $product_id . '_orders',
                );
                
                foreach ($cache_keys as $key) {
                    wp_cache_delete($key);
                }
                group_discount_debug_log('Cleared object cache keys for product ' . $product_id);
            }
            
            // Perform a direct database query to check for orders
            global $wpdb;
            $direct_query = $wpdb->prepare("
                SELECT SUM(woi.meta_value) as total_qty
                FROM {$wpdb->prefix}woocommerce_order_items oi
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta woi ON oi.order_item_id = woi.order_item_id AND woi.meta_key = '_qty'
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta woip ON oi.order_item_id = woip.order_item_id 
                WHERE woip.meta_key = '_product_id' AND woip.meta_value = %d
            ", $product_id);
            
            $direct_result = $wpdb->get_var($direct_query);
            $direct_count = absint($direct_result);
            
            group_discount_debug_log("Direct query for product {$product_id}: {$direct_query}");
            group_discount_debug_log("Direct query result: {$direct_result}");
            
            // Get fresh count through our normal method
            $total_sold = $this->get_total_quantity_sold($product_id);
            
            // If direct count is higher, override
            if ($direct_count > $total_sold) {
                $total_sold = $direct_count;
                group_discount_debug_log("Using direct count as it's higher: {$direct_count}");
            }
            
            // Get detailed order statistics for debugging
            $order_stats = $this->get_order_statistics($product_id);
            $debug_info = array(
                'total_sold' => $total_sold,
                'direct_count' => $direct_count,
                'order_stats' => $order_stats,
                'sql_query' => $direct_query
            );
            
            // Check for special debug parameter
            $debug_mode = isset($_POST['debug_mode']) && $_POST['debug_mode'] === 'true';
            
            $response_data = array(
                'total_sold' => $total_sold,
                'message' => sprintf(__('Sales count refreshed: %d units sold', 'group-discount'), $total_sold),
                'debug_info' => $debug_mode ? $debug_info : null
            );
            
            group_discount_debug_log("Sending AJAX response with total_sold: {$total_sold}");
            
            wp_send_json_success($response_data);
        } catch (Exception $e) {
            $error_message = 'Error in flush_cache AJAX: ' . $e->getMessage();
            group_discount_debug_log($error_message);
            wp_send_json_error($error_message);
        }
        
        // Catch any output
        $output = ob_get_clean();
        if (!empty($output)) {
            group_discount_debug_log('Unexpected output in flush_cache AJAX', $output);
        }
        
        exit;
    }
    
    /**
     * Handle AJAX request to refresh price
     */
    public function ajax_refresh_price() {
        // Start output buffering to catch any errors
        ob_start();
        
        try {
            // Check nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'group-discount-nonce')) {
                group_discount_debug_log('Invalid nonce in refresh_price AJAX request');
                wp_send_json_error('Invalid nonce');
                exit;
            }
            
            // Check product ID
            if (!isset($_POST['product_id']) || empty($_POST['product_id'])) {
                group_discount_debug_log('Invalid product ID in refresh_price AJAX request');
                wp_send_json_error('Invalid product ID');
                exit;
            }
            
            $product_id = absint($_POST['product_id']);
            group_discount_debug_log('Processing refresh_price AJAX request for product', $product_id);
            
            // Get language from request if available
            $lang = isset($_POST['lang']) ? sanitize_text_field($_POST['lang']) : '';
            
            // IMPORTANT: Force Chinese translations loading at the start
            $is_chinese = false;
            
            // Check for Chinese site and force Chinese translations if needed
            $is_chinese_site = function_exists('group_discount_detect_chinese_site') && group_discount_detect_chinese_site();
            if ($is_chinese_site) {
                $is_chinese = true;
                $locale = get_locale();
                
                if (empty($lang)) {
                    // Default to Traditional Chinese for these locales
                    if (in_array($locale, array('zh_TW', 'zh-TW', 'zh_HK', 'zh-HK'))) {
                        $lang = 'zh_TW';
                    } else {
                        // Otherwise default to Simplified
                        $lang = 'zh_CN';
                    }
                }
                
                group_discount_debug_log('Chinese site detected, forcing language: ' . $lang);
            }
            
            // Also check for Chinese in the passed language parameter
            if (strpos($lang, 'zh') === 0) {
                $is_chinese = true;
                group_discount_debug_log('Chinese language requested in AJAX: ' . $lang);
                
                // Be more specific about the Chinese variant
                if (in_array($lang, array('zh-tw', 'zh_TW', 'tw', 'zh-hk', 'zh_HK', 'hk'))) {
                    $lang = 'zh_TW'; // Normalize to Traditional Chinese
                    group_discount_debug_log('Normalized language code to Traditional Chinese (zh_TW)');
                } else if (in_array($lang, array('zh-cn', 'zh_CN', 'cn'))) {
                    $lang = 'zh_CN'; // Normalize to Simplified Chinese
                    group_discount_debug_log('Normalized language code to Simplified Chinese (zh_CN)');
                }
            }
            
            // For Chinese, try to load both translation files to ensure we have the translations
            if ($is_chinese) {
                // Always try Traditional Chinese first
                $mo_files_to_try = array(
                    GD_PLUGIN_PATH . 'languages/group-discount-zh_TW.mo',
                    GD_PLUGIN_PATH . 'languages/group-discount-zh_CN.mo',
                    GD_PLUGIN_PATH . 'languages/group-discount-zh.mo'
                );
                
                // If specifically requesting Traditional Chinese, prioritize it more strongly
                if ($lang === 'zh_TW') {
                    group_discount_debug_log('Prioritizing Traditional Chinese (zh_TW) translations');
                    // Load TW first, then try CN as fallback
                    foreach ($mo_files_to_try as $mo_file) {
                        if (file_exists($mo_file)) {
                            load_textdomain('group-discount', $mo_file);
                            group_discount_debug_log('AJAX: Loaded Chinese translation: ' . $mo_file);
                            
                            // For zh_TW, if we successfully loaded it, we can break
                            if (strpos($mo_file, 'zh_TW.mo') !== false) {
                                group_discount_debug_log('Successfully loaded Traditional Chinese translations');
                                break;
                            }
                        }
                    }
                } else {
                    // For other cases, try all files
                    foreach ($mo_files_to_try as $mo_file) {
                        if (file_exists($mo_file)) {
                            load_textdomain('group-discount', $mo_file);
                            group_discount_debug_log('AJAX: Loaded Chinese translation: ' . $mo_file);
                        }
                    }
                }
                
                // Test translation to verify it's working
                $test = __('Original price', 'group-discount');
                group_discount_debug_log('AJAX Translation test: "Original price" => "' . $test . '"');
            }
            
            // Handle multilanguage - if using WPML, Polylang or other translation plugin
            if (!empty($lang)) {
                group_discount_debug_log('Received language parameter in AJAX request: ' . $lang);
                
                // CRITICAL FIX: Load translations at the earliest possible point
                // Force switch WP locale first
                $locale = $lang;
                
                // Convert 2-letter codes to proper locales
                if (strlen($lang) === 2) {
                    $locales_map = array(
                        'en' => 'en_US',
                        'fr' => 'fr_FR',
                        'de' => 'de_DE',
                        'es' => 'es_ES',
                        'it' => 'it_IT',
                        'nl' => 'nl_NL',
                        'pt' => 'pt_PT',
                        'ru' => 'ru_RU',
                        'zh' => 'zh_CN',
                        'ja' => 'ja',
                        'ar' => 'ar',
                        'he' => 'he_IL'
                    );
                    if (isset($locales_map[$lang])) {
                        $locale = $locales_map[$lang];
                    }
                }
                
                // Special handling for Chinese variants
                if ($lang === 'zh' || $lang === 'zh_CN' || $lang === 'zh-CN' || $lang === 'cn') {
                    $locale = 'zh_CN';
                    $is_chinese = true;
                } elseif ($lang === 'zh_TW' || $lang === 'zh-TW' || $lang === 'tw' || 
                          $lang === 'zh_HK' || $lang === 'zh-HK' || $lang === 'hk') {
                    $locale = 'zh_TW';
                    $is_chinese = true;
                }
                
                // Normalize locale format
                if (preg_match('/^[a-z]{2}-[a-z]{2}$/i', $locale)) {
                    // Convert format like en-us to en_US
                    $parts = explode('-', $locale);
                    if (count($parts) === 2) {
                        $locale = strtolower($parts[0]) . '_' . strtoupper($parts[1]);
                    }
                }
                
                group_discount_debug_log('Using locale for translations: ' . $locale);
                
                // Set global locale
                global $wp_locale;
                $wp_locale = new WP_Locale();
                
                // Force-set the locale for this request
                add_filter('locale', function() use ($locale) {
                    return $locale;
                }, 9999);
                
                // Direct loading of MO files to ensure translations work
                $domain = 'group-discount';
                
                // Try multiple file locations with multiple formats
                $mo_files_to_try = array(
                    WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale . '.mo',
                    GD_PLUGIN_PATH . 'languages/' . $domain . '-' . $locale . '.mo',
                    GD_PLUGIN_PATH . 'languages/' . $domain . '-' . substr($locale, 0, 2) . '.mo'
                );
                
                // For Chinese specifically, also try both variants
                if ($is_chinese) {
                    $mo_files_to_try[] = GD_PLUGIN_PATH . 'languages/' . $domain . '-zh_CN.mo';
                    $mo_files_to_try[] = GD_PLUGIN_PATH . 'languages/' . $domain . '-zh_TW.mo';
                    $mo_files_to_try[] = GD_PLUGIN_PATH . 'languages/' . $domain . '-zh.mo';
                }
                
                $translations_loaded = false;
                foreach ($mo_files_to_try as $mo_file) {
                    if (file_exists($mo_file)) {
                        $result = load_textdomain($domain, $mo_file);
                        if ($result) {
                            $translations_loaded = true;
                            group_discount_debug_log('AJAX: Directly loaded MO file: ' . $mo_file);
                        }
                    }
                }
                
                if (!$translations_loaded) {
                    group_discount_debug_log('AJAX: Could not find any suitable MO files for locale ' . $locale);
                }
                
                // Force reload of translations using load_plugin_textdomain
                load_plugin_textdomain($domain, false, dirname(plugin_basename(__FILE__)) . '/../languages');
                
                // Try to set the language for this request if WPML is active
                if (function_exists('icl_object_id') && function_exists('wpml_switch_language_action')) {
                    do_action('wpml_switch_language', $lang);
                    group_discount_debug_log('Switched WPML language to: ' . $lang);
                }
                
                // Try to set language for Polylang
                if (function_exists('pll_current_language') && function_exists('pll_switch_language')) {
                    pll_switch_language($lang);
                    group_discount_debug_log('Switched Polylang language to: ' . $lang);
                }
                
                // Try TranslatePress
                if (function_exists('trp_get_languages') && function_exists('trp_get_translation') && defined('TRP_LANGUAGE')) {
                    // Load translated strings - TP specific approach
                    global $TRP_LANGUAGE;
                    $TRP_LANGUAGE = $lang;
                    group_discount_debug_log('Set TranslatePress language to: ' . $lang);
                }
                
                // Try to support QTranslate X
                if (function_exists('qtranxf_getLanguage') && function_exists('qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage')) {
                    // Attempt to apply qTranslate language
                    global $q_config;
                    if (isset($q_config) && isset($q_config['language'])) {
                        $original_lang = $q_config['language'];
                        $q_config['language'] = $lang;
                        group_discount_debug_log('Set QTranslate language from ' . $original_lang . ' to: ' . $lang);
                    }
                }
                
                // Final language debugging check
                group_discount_debug_log('Current WordPress locale after all language switching: ' . get_locale());
                
                // Do one more load of textdomain to be safe
                $domain = 'group-discount';
                load_plugin_textdomain($domain, false, dirname(plugin_basename(__FILE__)) . '/../languages');
                
                // For Chinese sites, try to force load the appropriate MO file directly one more time
                if (strpos($locale, 'zh_') === 0 || $is_chinese_site || $is_chinese) {
                    $zh_variants = array('zh_CN', 'zh_TW');
                    foreach ($zh_variants as $zh_variant) {
                        $mo_file = GD_PLUGIN_PATH . 'languages/group-discount-' . $zh_variant . '.mo';
                        if (file_exists($mo_file)) {
                            $result = load_textdomain($domain, $mo_file);
                            group_discount_debug_log('AJAX: Force-loaded Chinese translation: ' . $mo_file . ' - Result: ' . ($result ? 'Success' : 'Failed'));
                        }
                    }
                    
                    // Test translations one more time
                    $test = __('Original price', 'group-discount');
                    group_discount_debug_log('AJAX Final translation test: "Original price" => "' . $test . '"');
                }
            }
            
            // Force clear cache for this product
            Group_Discount_Cache::delete('total_sold_' . $product_id);
            
            // Get product safely with error checking
            $product = wc_get_product($product_id);
            
            if (!$product || !is_a($product, 'WC_Product')) {
                group_discount_debug_log('Product not found or invalid in refresh_price AJAX', $product_id);
                wp_send_json_error('Product not found or invalid');
                exit;
            }
            
            // Get fresh count
            $total_sold = $this->get_total_quantity_sold($product_id);
            
            // Get tier prices
            $tiers = get_post_meta($product_id, '_group_discount_tiers', true);
            
            // Sort tiers by quantity (descending)
            if (!empty($tiers) && is_array($tiers)) {
                usort($tiers, function($a, $b) {
                    return $b['quantity'] - $a['quantity'];
                });
            }
            
            // Find applicable tier price
            $tier_price = false;
            $current_tier = false;
            $next_tier = false;
            
            if (!empty($tiers) && is_array($tiers)) {
                // First pass: find current tier
                foreach ($tiers as $tier) {
                    if ($total_sold >= $tier['quantity'] && isset($tier['price']) && $tier['price'] !== '') {
                        $tier_price = $tier['price'];
                        $current_tier = $tier;
                        group_discount_debug_log('Found applicable tier for product ' . $product_id, $tier);
                        break;
                    }
                }
                
                // Second pass: find next tier (the first tier with a higher quantity than current total)
                // Sort tiers by quantity (ascending) for next tier search
                usort($tiers, function($a, $b) {
                    return $a['quantity'] - $b['quantity'];
                });
                
                foreach ($tiers as $tier) {
                    if ($total_sold < $tier['quantity'] && isset($tier['price']) && $tier['price'] !== '') {
                        $next_tier = $tier;
                        break;
                    }
                }
            }
            
            // Get the true regular price using our new method
            $regular_price = $this->get_true_regular_price($product);
            
            // Debug log the prices to help troubleshoot
            group_discount_debug_log('Regular price: ' . $regular_price);
            group_discount_debug_log('Tier price: ' . $tier_price);
            
            // Format the regular price
            $formatted_regular_price = wc_price($regular_price);
            
            // Calculate savings for display
            $savings = 0;
            $savings_percent = 0;
            
            if ($tier_price && $regular_price > $tier_price) {
                $savings = $regular_price - $tier_price;
                $savings_percent = ($savings / $regular_price) * 100;
            }
            
            // Format savings in various ways for the JavaScript to use
            $formatted_savings_amount = wc_price($savings);
            $formatted_savings_numeric = wc_format_decimal($savings, wc_get_price_decimals());
            
            // Calculate next tier information if available
            $next_tier_quantity = 0;
            $units_needed = 0;
            $next_tier_price = 0;
            $formatted_next_tier_price = '';
            $next_tier_savings = 0;
            $next_tier_savings_percent = 0;
            
            if ($next_tier) {
                $next_tier_quantity = $next_tier['quantity'];
                $units_needed = $next_tier['quantity'] - $total_sold;
                $next_tier_price = $next_tier['price'];
                $formatted_next_tier_price = wc_price($next_tier_price);
                
                if ($regular_price > $next_tier_price) {
                    $next_tier_savings = $regular_price - $next_tier_price;
                    $next_tier_savings_percent = ($next_tier_savings / $regular_price) * 100;
                }
            }
            
            // Get decimal separator for the current locale
            $decimal_separator = wc_get_price_decimal_separator();
            
            // Determine if we need to force a page reload
            $significant_change = false;
            $force_reload = false;
            
            // Force reload if there was a significant change in total sold (more than 10 units)
            if (isset($_POST['last_total']) && absint($_POST['last_total']) > 0) {
                $last_total = absint($_POST['last_total']);
                if (abs($total_sold - $last_total) > 10) {
                    $significant_change = true;
                    // Only force reload on very significant changes (more than 50 units)
                    if (abs($total_sold - $last_total) > 50) {
                        $force_reload = true;
                    }
                }
            }
            
            // Generate fully translated versions of the labels with proper HTML structure for styling
            // Units sold text
            $units_sold_text = sprintf(
                /* translators: %s: number of units sold */
                __('%s units already sold', 'group-discount'),
                '<strong>' . number_format_i18n($total_sold) . '</strong>'
            );
            
            // Savings badge text
            $savings_badge_text = sprintf(
                /* translators: %s: discount percentage */
                __('Save %s', 'group-discount'),
                '<strong>' . number_format_i18n($savings_percent, 2) . '%</strong>'
            );
            
            // Enhanced price comparison text with proper translation and consistent HTML structure
            // The structure here must match the HTML in the show_discount_label method
            $original_price_html = '<span class="original-price">' . $formatted_regular_price . '</span>';
            $current_price_html = '<strong>' . ($tier_price ? wc_price($tier_price) : $formatted_regular_price) . '</strong>';
            
            // Split the savings text into parts for better translation support
            $you_save_text = __('You save', 'group-discount');
            $per_unit_text = __('per unit', 'group-discount');
            $savings_text_html = '<span class="savings-text">(' . $you_save_text . ' ' . $formatted_savings_amount . ' ' . $per_unit_text . ')</span>';
            
            // Individual translatable components
            $original_price_label = __('Original price', 'group-discount');
            $current_price_label = __('Current price', 'group-discount');
            
            // Two-row display format
            $price_comparison_text = 
                '<span class="gd-original-price-row">' . $original_price_label . ': ' . $original_price_html . '</span>' .
                '<span class="gd-current-price-row">' . $current_price_label . ': ' . $current_price_html . ' ' . $savings_text_html . '</span>';
            
            // Next tier text if available
            $next_tier_text = '';
            $next_tier_price_text = '';
            $next_tier_savings_badge = '';
            
            if ($next_tier) {
                $next_tier_text = sprintf(
                    /* translators: %s: quantity needed for next tier */
                    __('Next discount at %s units', 'group-discount'),
                    '<strong>' . number_format_i18n($next_tier_quantity) . '</strong>'
                );
                
                $next_tier_savings_badge = sprintf(
                    /* translators: %s: next tier discount percentage */
                    __('Save %s', 'group-discount'),
                    '<strong>' . number_format_i18n($next_tier_savings_percent, 2) . '%</strong>'
                );
                
                $next_tier_price_text = sprintf(
                    /* translators: %1$s: number of units needed, %2$s: discounted price */
                    __('Only %1$s more units needed to unlock price: %2$s per unit', 'group-discount'),
                    '<strong>' . number_format_i18n($units_needed) . '</strong>',
                    '<strong>' . $formatted_next_tier_price . '</strong>'
                );
            }
            
            // Format response data
            $response_data = array(
                'total_sold' => $total_sold,
                'regular_price' => wc_format_decimal($regular_price, wc_get_price_decimals()),
                'formatted_regular_price' => $formatted_regular_price,
                'tier_price' => $tier_price ? wc_format_decimal($tier_price, wc_get_price_decimals()) : false,
                'current_tier' => $current_tier,
                'formatted_price' => $tier_price ? wc_price($tier_price) : wc_price($regular_price),
                'decimal_separator' => $decimal_separator,
                'savings_amount' => $formatted_savings_numeric,
                'formatted_savings_amount' => $formatted_savings_amount,
                'savings_percent' => $savings_percent,
                'price_comparison_text' => $price_comparison_text,
                'significant_change' => $significant_change,
                'force_reload' => $force_reload,
                'message' => sprintf(__('Sales count refreshed: %d units sold', 'group-discount'), $total_sold),
                // Enhanced data for improved multilingual support
                'units_sold_text' => $units_sold_text,
                'savings_badge_text' => $savings_badge_text,
                'next_tier' => $next_tier,
                'next_tier_quantity' => $next_tier_quantity,
                'units_needed' => $units_needed,
                'next_tier_price' => $next_tier_price ? wc_format_decimal($next_tier_price, wc_get_price_decimals()) : false,
                'formatted_next_tier_price' => $formatted_next_tier_price,
                'next_tier_savings_percent' => $next_tier_savings_percent,
                'next_tier_text' => $next_tier_text,
                'next_tier_savings_badge' => $next_tier_savings_badge,
                'next_tier_price_text' => $next_tier_price_text,
                'language' => $lang, // Include the language in the response
                // Add individual translatable components for better language support
                'original_price_label' => $original_price_label,
                'current_price_label' => $current_price_label,
                'you_save_text' => $you_save_text,
                'per_unit_text' => $per_unit_text
            );
            
            // Special handling for Traditional Chinese
            if ($lang === 'zh_TW') {
                group_discount_debug_log('Applying special Traditional Chinese (zh_TW) labels');
                
                // Direct override for Traditional Chinese labels 
                $response_data['original_price_label'] = '原價';
                $response_data['current_price_label'] = '現價';
                $response_data['you_save_text'] = '每件節省';
                $response_data['per_unit_text'] = '';
                
                // Regenerate the price_comparison_text with Traditional Chinese labels
                $original_price_html = '<span class="original-price">' . $formatted_regular_price . '</span>';
                $current_price_html = '<strong>' . ($tier_price ? wc_price($tier_price) : $formatted_regular_price) . '</strong>';
                $savings_text_html = '<span class="savings-text">(' . $response_data['you_save_text'] . ' ' . $formatted_savings_amount . ')</span>';
                
                $price_comparison_text = 
                    '<span class="gd-original-price-row">' . $response_data['original_price_label'] . ': ' . $original_price_html . '</span>' .
                    '<span class="gd-current-price-row">' . $response_data['current_price_label'] . ': ' . $current_price_html . ' ' . $savings_text_html . '</span>';
                
                $response_data['price_comparison_text'] = $price_comparison_text;
                
                // Also add special Traditional Chinese text for next tier information
                if ($next_tier) {
                    // Direct Traditional Chinese strings for next tier info
                    $response_data['next_tier_text'] = '下一個折扣在 <strong>' . number_format_i18n($next_tier_quantity) . '</strong> 件';
                    $response_data['next_tier_savings_badge'] = '節省 <strong>' . number_format_i18n($next_tier_savings_percent, 2) . '%</strong>';
                    $response_data['next_tier_price_text'] = '只需再購買 <strong>' . number_format_i18n($units_needed) . '</strong> 件即可解鎖價格：每件 <strong>' . $formatted_next_tier_price . '</strong>';
                    
                    // Add these properties to make them directly available to the JavaScript
                    $response_data['next_tier_price_formatted'] = $formatted_next_tier_price;
                }
            }
            
            group_discount_debug_log('Sending AJAX response for product ' . $product_id, $response_data);
            
            wp_send_json_success($response_data);
        } catch (Exception $e) {
            $error_message = 'Error in refresh_price AJAX: ' . $e->getMessage();
            group_discount_debug_log($error_message);
            wp_send_json_error($error_message);
        }
        
        // Catch any output
        $output = ob_get_clean();
        if (!empty($output)) {
            group_discount_debug_log('Unexpected output in refresh_price AJAX', $output);
        }
        
        exit;
    }
    
    /**
     * Add admin notices
     */
    public function admin_notices() {
        global $pagenow, $post;
        
        // Only on product edit page
        if ($pagenow != 'post.php' || !isset($post) || $post->post_type != 'product') {
            return;
        }
        
        // Check if group discount is enabled
        $enabled = get_post_meta($post->ID, '_group_discount_enabled', true);
        
        if ($enabled !== 'yes') {
            return;
        }
        
        // Get total quantity sold
        $total_sold = $this->get_total_quantity_sold($post->ID);
        
        if ($total_sold > 0) {
            // Get tier prices
            $tiers = get_post_meta($post->ID, '_group_discount_tiers', true);
            
            if (!empty($tiers) && is_array($tiers)) {
                // Sort tiers by quantity (ascending)
                usort($tiers, function($a, $b) {
                    return $a['quantity'] - $b['quantity'];
                });
                
                // Get current tier
                $current_tier = false;
                
                foreach ($tiers as $tier) {
                    if ($total_sold >= $tier['quantity'] && !empty($tier['price'])) {
                        $current_tier = $tier;
                    }
                }
                
                if ($current_tier) {
                    echo '<div class="notice notice-info is-dismissible">';
                    echo '<p>' . sprintf(
                        __('Group Discount: This product has %d units sold. Current tier price: $%s (Tier: %d units)', 'group-discount'),
                        $total_sold,
                        wc_format_decimal($current_tier['price'], wc_get_price_decimals()),
                        $current_tier['quantity']
                    ) . '</p>';
                    echo '</div>';
                }
            }
        }
    }

    /**
     * Get order statistics for debugging
     *
     * @param int $product_id Product ID
     * @return array Order statistics
     */
    private function get_order_statistics($product_id) {
        global $wpdb;
        
        group_discount_debug_log('Getting order statistics for product ' . $product_id);
        
        $statuses = array(
            'wc-completed'  => 0,
            'wc-processing' => 0,
            'wc-on-hold'    => 0,
            'wc-pending'    => 0,
            'wc-cancelled'  => 0,
            'wc-refunded'   => 0,
            'wc-failed'     => 0,
            'cart-current'  => 0,
        );
        
        // First, try using the new WC HPOS approach if available
        if (function_exists('wc_get_orders')) {
            group_discount_debug_log('Trying WC API to get order statistics');
            
            // Try to use the WooCommerce API to get order counts
            try {
                // Get all orders with all statuses
                $wc_statuses = array_keys(wc_get_order_statuses());
                $order_query = array(
                    'status' => $wc_statuses,
                    'limit' => -1,
                    'return' => 'ids',
                );
                
                $order_ids = wc_get_orders($order_query);
                
                if (!empty($order_ids)) {
                    group_discount_debug_log('Found ' . count($order_ids) . ' orders to check for product ' . $product_id);
                    
                    foreach ($order_ids as $order_id) {
                        $order = wc_get_order($order_id);
                        if (!$order) continue;
                        
                        foreach ($order->get_items() as $item) {
                            if ($item->get_product_id() == $product_id) {
                                $status = 'wc-' . $order->get_status();
                                if (isset($statuses[$status])) {
                                    $statuses[$status] += (int)$item->get_quantity();
                                    group_discount_debug_log("Order #{$order_id} with status {$status} has {$item->get_quantity()} units of product {$product_id}");
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                group_discount_debug_log('Error getting orders via WC API: ' . $e->getMessage());
            }
        }
        
        // Also try direct database queries as a fallback
        foreach ($statuses as $status => $count) {
            if ($status === 'cart-current') {
                // Get current cart quantity
                $statuses[$status] = $this->get_cart_quantity_for_product($product_id);
                continue;
            }
            
            // First try with the 'wc-' prefix
            $query = $wpdb->prepare("
                SELECT SUM(order_item_meta.meta_value) as total
                FROM {$wpdb->prefix}woocommerce_order_items as order_items
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as product_id_meta ON order_items.order_item_id = product_id_meta.order_item_id
                LEFT JOIN {$wpdb->posts} as posts ON posts.ID = order_items.order_id
                WHERE posts.post_type = 'shop_order'
                AND posts.post_status = %s
                AND order_items.order_item_type = 'line_item'
                AND order_item_meta.meta_key = '_qty'
                AND product_id_meta.meta_key IN ('_product_id', '_variation_id')
                AND product_id_meta.meta_value = %d
            ", $status, $product_id);
            
            $result = $wpdb->get_var($query);
            $count_with_prefix = absint($result);
            
            // Also try without the 'wc-' prefix
            $status_no_prefix = str_replace('wc-', '', $status);
            $query_no_prefix = $wpdb->prepare("
                SELECT SUM(order_item_meta.meta_value) as total
                FROM {$wpdb->prefix}woocommerce_order_items as order_items
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as product_id_meta ON order_items.order_item_id = product_id_meta.order_item_id
                LEFT JOIN {$wpdb->posts} as posts ON posts.ID = order_items.order_id
                WHERE posts.post_type = 'shop_order'
                AND posts.post_status = %s
                AND order_items.order_item_type = 'line_item'
                AND order_item_meta.meta_key = '_qty'
                AND product_id_meta.meta_key IN ('_product_id', '_variation_id')
                AND product_id_meta.meta_value = %d
            ", $status_no_prefix, $product_id);
            
            $result_no_prefix = $wpdb->get_var($query_no_prefix);
            $count_no_prefix = absint($result_no_prefix);
            
            // Also try with variation ID
            $query_variation = $wpdb->prepare("
                SELECT SUM(order_item_meta.meta_value) as total
                FROM {$wpdb->prefix}woocommerce_order_items as order_items
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta as product_id_meta ON order_items.order_item_id = product_id_meta.order_item_id
                JOIN {$wpdb->posts} as posts ON posts.ID = order_items.order_id
                WHERE posts.post_type = 'shop_order'
                AND posts.post_status = %s
                AND order_items.order_item_type = 'line_item'
                AND order_item_meta.meta_key = '_qty'
                AND product_id_meta.meta_key = '_variation_id'
                AND product_id_meta.meta_value = %d
            ", $status, $product_id);
            
            $result_variation = $wpdb->get_var($query_variation);
            $count_variation = absint($result_variation);
            
            // Use the highest count found
            $statuses[$status] = max($count_with_prefix, $count_no_prefix, $count_variation, $statuses[$status]);
            
            group_discount_debug_log("Status {$status} count: {$statuses[$status]} (With prefix: {$count_with_prefix}, No prefix: {$count_no_prefix}, Variation: {$count_variation})");
        }
        
        // Add a broader search for any orders containing this product ID, regardless of status
        $fallback_query = $wpdb->prepare("
            SELECT SUM(woi.meta_value) as total_qty
            FROM {$wpdb->prefix}woocommerce_order_items oi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta woi ON oi.order_item_id = woi.order_item_id AND woi.meta_key = '_qty'
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta woip ON oi.order_item_id = woip.order_item_id 
            WHERE woip.meta_key = '_product_id' AND woip.meta_value = %d
        ", $product_id);
        
        $fallback_total = absint($wpdb->get_var($fallback_query));
        $statuses['fallback_total'] = $fallback_total;
        group_discount_debug_log("Fallback total (all orders) for product {$product_id}: {$fallback_total}");
        
        // Check specifically for order #101 which the user mentioned
        if ($product_id == 341) {
            $specific_check = $wpdb->get_results("
                SELECT 
                    p.ID as order_id,
                    p.post_status,
                    oi.order_item_id,
                    oi.order_item_name,
                    SUM(woi.meta_value) as qty,
                    woip.meta_value as product_id
                FROM {$wpdb->posts} p
                JOIN {$wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta woi ON oi.order_item_id = woi.order_item_id AND woi.meta_key = '_qty'
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta woip ON oi.order_item_id = woip.order_item_id AND woip.meta_key = '_product_id'
                WHERE p.post_type = 'shop_order'
                GROUP BY oi.order_item_id
                ORDER BY p.ID DESC
                LIMIT 10
            ");
            
            if (!empty($specific_check)) {
                group_discount_debug_log("Recent orders check: ", $specific_check);
            }
        }
        
        // Add a total for counted statuses
        $statuses['counted_total'] = $statuses['wc-completed'] + $statuses['wc-processing'] + $statuses['wc-on-hold'] + $statuses['wc-pending'] + $statuses['cart-current'];
        
        // If fallback total is much higher, something might be wrong with status-specific queries
        if ($fallback_total > ($statuses['counted_total'] * 1.5) && $fallback_total > 0) {
            group_discount_debug_log("Warning: Fallback total ({$fallback_total}) is much higher than counted total ({$statuses['counted_total']})");
            
            // In this case, consider using the fallback total if our main counting logic is missing orders
            if ($statuses['counted_total'] == 0) {
                $statuses['counted_total'] = $fallback_total;
                group_discount_debug_log("Using fallback total as counted_total since regular count is 0");
            }
        }
        
        return $statuses;
    }

    /**
     * Enqueue frontend scripts
     */
    public function enqueue_frontend_scripts() {
        // Only enqueue on single product pages
        if (!is_product()) {
            return;
        }
        
        // Get the product object safely
        global $post;
        
        if (!$post || !is_object($post)) {
            return;
        }
        
        $product_id = $post->ID;
        $product = wc_get_product($product_id);
        
        if (!$product || !is_a($product, 'WC_Product')) {
            return;
        }
        
        // Check if group discount is enabled for this product
        $enabled = get_post_meta($product_id, '_group_discount_enabled', true);
        
        if ($enabled !== 'yes') {
            return;
        }
        
        // Enqueue frontend scripts
        wp_enqueue_script('group-discount-frontend', GD_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), GD_PLUGIN_VERSION, true);
        
        // Localize script
        wp_localize_script('group-discount-frontend', 'group_discount_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('group-discount-nonce'),
            'product_id' => $product_id,
            'refresh_interval' => 180000, // Refresh price every 3 minutes (180 seconds) instead of 30 seconds
            'i18n' => array(
                'refreshing' => __('Refreshing price...', 'group-discount'),
                'refresh_success' => __('Price updated!', 'group-discount'),
                'refresh_error' => __('Error refreshing price.', 'group-discount'),
            ),
        ));
    }

    /**
     * Add diagnostic information to the admin page
     */
    public function add_diagnostic_info() {
        global $pagenow, $post;
        
        // Only on product edit page
        if ($pagenow != 'post.php' || !isset($post) || $post->post_type != 'product') {
            return;
        }
        
        // Check if group discount is enabled
        $enabled = get_post_meta($post->ID, '_group_discount_enabled', true);
        
        if ($enabled !== 'yes') {
            return;
        }
        
        // Get debug info
        $debug_info = $this->get_diagnostic_data($post->ID);
        
        // Direct database check for any orders
        global $wpdb;
        $direct_sql = $wpdb->prepare("
            SELECT 
                oi.order_id,
                p.post_status,
                woi.meta_value as qty,
                p.post_date,
                woip.meta_value as product_id
            FROM 
                {$wpdb->prefix}woocommerce_order_items as oi
            JOIN 
                {$wpdb->prefix}woocommerce_order_itemmeta as woi 
                ON oi.order_item_id = woi.order_item_id AND woi.meta_key = '_qty'
            JOIN 
                {$wpdb->prefix}woocommerce_order_itemmeta as woip 
                ON oi.order_item_id = woip.order_item_id AND woip.meta_key = '_product_id'
            JOIN 
                {$wpdb->posts} as p 
                ON oi.order_id = p.ID
            WHERE 
                woip.meta_value = %d
                AND p.post_type = 'shop_order'
            ORDER BY 
                p.post_date DESC
            LIMIT 5
        ", $post->ID);
        
        $recent_orders = $wpdb->get_results($direct_sql);
        
        // Get fallback total (a direct count regardless of status)
        $fallback_sql = $wpdb->prepare("
            SELECT SUM(woi.meta_value) as total_qty
            FROM {$wpdb->prefix}woocommerce_order_items oi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta woi ON oi.order_item_id = woi.order_item_id AND woi.meta_key = '_qty'
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta woip ON oi.order_item_id = woip.order_item_id AND woip.meta_key = '_product_id'
            WHERE woip.meta_value = %d
        ", $post->ID);
        
        $fallback_total = $wpdb->get_var($fallback_sql);
        
        // Output the debug information using plain text instead of translations
        ?>
        <div class="notice notice-info">
            <h3>Group Discount Diagnostic Information</h3>
            <p>This information can help debug any issues with the Group Discount plugin.</p>
            
            <table class="widefat striped" style="margin-bottom: 10px;">
                <tbody>
                    <tr>
                        <td><strong>WooCommerce Version</strong></td>
                        <td><?php echo $debug_info['wc_version']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>HPOS Enabled</strong></td>
                        <td><?php echo $debug_info['hpos_enabled'] ? 'Yes' : 'No'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Current Product ID</strong></td>
                        <td><?php echo $post->ID; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Group Discount Enabled</strong></td>
                        <td><?php echo $enabled === 'yes' ? 'Yes' : 'No'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Total Quantity Sold</strong></td>
                        <td><?php echo $debug_info['total_sold']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Direct Database Count</strong></td>
                        <td><?php echo absint($fallback_total); ?> units (this is a raw count of all orders regardless of status)</td>
                    </tr>
                    <tr>
                        <td><strong>Current Order Status Counts</strong></td>
                        <td>
                            <?php foreach ($debug_info['order_statuses'] as $status => $count) : ?>
                                <?php if ($status !== 'counted_total' && $status !== 'fallback_total') : ?>
                                    <div><?php echo ucfirst(str_replace('wc-', '', $status)); ?>: <?php echo $count; ?></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <div><strong>Counted Total: <?php echo isset($debug_info['order_statuses']['counted_total']) ? $debug_info['order_statuses']['counted_total'] : '0'; ?></strong></div>
                            <?php if (isset($debug_info['order_statuses']['fallback_total'])) : ?>
                                <div><strong>Fallback Total: <?php echo $debug_info['order_statuses']['fallback_total']; ?></strong></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Current Cart Quantity</strong></td>
                        <td><?php echo $debug_info['cart_quantity']; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Active Price Tiers</strong></td>
                        <td>
                            <?php if (!empty($debug_info['tiers'])) : ?>
                                <ul style="margin: 0; padding-left: 20px;">
                                <?php foreach ($debug_info['tiers'] as $tier) : ?>
                                    <li>Quantity: <?php echo $tier['quantity']; ?>, Price: <?php echo wc_price($tier['price']); ?></li>
                                <?php endforeach; ?>
                                </ul>
                            <?php else : ?>
                                No tiers configured
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Current Applied Tier</strong></td>
                        <td>
                            <?php if ($debug_info['current_tier']) : ?>
                                Quantity: <?php echo $debug_info['current_tier']['quantity']; ?>, Price: <?php echo wc_price($debug_info['current_tier']['price']); ?>
                            <?php else : ?>
                                No tier currently applied
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (!empty($recent_orders)) : ?>
                    <tr>
                        <td><strong>Recent Orders</strong></td>
                        <td>
                            <table class="widefat">
                                <thead>
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Quantity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_orders as $order) : ?>
                                    <tr>
                                        <td><a href="<?php echo admin_url('post.php?post=' . $order->order_id . '&action=edit'); ?>">#<?php echo $order->order_id; ?></a></td>
                                        <td><?php echo ucfirst(str_replace('wc-', '', $order->post_status)); ?></td>
                                        <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($order->post_date)); ?></td>
                                        <td><?php echo $order->qty; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <p>
                <button type="button" class="button diagnostic-refresh" data-product-id="<?php echo esc_attr($post->ID); ?>">
                    Refresh Diagnostic Data
                </button>
                <button type="button" class="button diagnostic-clear-cache" data-product-id="<?php echo esc_attr($post->ID); ?>">
                    Clear All Caches
                </button>
                <button type="button" class="button force-recalculate" data-product-id="<?php echo esc_attr($post->ID); ?>" data-debug="true">
                    Force Recalculate (Debug Mode)
                </button>
                <span class="spinner" style="float: none; margin-top: 0;"></span>
                <span class="diagnostic-message"></span>
            </p>
            
            <div class="debug-output" style="display:none; margin-top: 10px; padding: 10px; background: #f8f8f8; border: 1px solid #ddd; max-height: 300px; overflow: auto;"></div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('.diagnostic-refresh').on('click', function() {
                var $button = $(this);
                var $spinner = $button.siblings('.spinner');
                var $message = $button.siblings('.diagnostic-message');
                var productId = $button.data('product-id');
                
                $spinner.css('visibility', 'visible');
                $message.text('Refreshing...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'group_discount_flush_cache',
                        nonce: group_discount_params.nonce,
                        product_id: productId
                    },
                    success: function(response) {
                        if (response.success) {
                            window.location.reload();
                        } else {
                            $spinner.css('visibility', 'hidden');
                            $message.text('Error refreshing data.');
                        }
                    },
                    error: function() {
                        $spinner.css('visibility', 'hidden');
                        $message.text('Error refreshing data.');
                    }
                });
            });
            
            $('.diagnostic-clear-cache').on('click', function() {
                var $button = $(this);
                var $spinner = $button.siblings('.spinner');
                var $message = $button.siblings('.diagnostic-message');
                
                $spinner.css('visibility', 'visible');
                $message.text('Clearing caches...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'group_discount_clear_all_caches',
                        nonce: group_discount_params.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            window.location.reload();
                        } else {
                            $spinner.css('visibility', 'hidden');
                            $message.text('Error clearing caches.');
                        }
                    },
                    error: function() {
                        $spinner.css('visibility', 'hidden');
                        $message.text('Error clearing caches.');
                    }
                });
            });
            
            $('.force-recalculate').on('click', function() {
                var $button = $(this);
                var $spinner = $button.siblings('.spinner');
                var $message = $button.siblings('.diagnostic-message');
                var $debugOutput = $('.debug-output');
                var productId = $button.data('product-id');
                var debug = $button.data('debug');
                
                $spinner.css('visibility', 'visible');
                $message.text('Recalculating with debug info...');
                $debugOutput.html('').show();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'group_discount_flush_cache',
                        nonce: group_discount_params.nonce,
                        product_id: productId,
                        debug_mode: debug
                    },
                    success: function(response) {
                        $spinner.css('visibility', 'hidden');
                        
                        if (response.success) {
                            $message.text('Recalculation complete. Total: ' + response.data.total_sold);
                            
                            // Display debug information
                            if (response.data.debug_info) {
                                var debugHtml = '<h4>Debug Information</h4>';
                                
                                debugHtml += '<p><strong>Total Sold:</strong> ' + response.data.debug_info.total_sold + '</p>';
                                debugHtml += '<p><strong>Direct Count:</strong> ' + response.data.debug_info.direct_count + '</p>';
                                
                                if (response.data.debug_info.order_stats) {
                                    debugHtml += '<h5>Order Statistics</h5><ul>';
                                    for (var status in response.data.debug_info.order_stats) {
                                        debugHtml += '<li><strong>' + status + ':</strong> ' + response.data.debug_info.order_stats[status] + '</li>';
                                    }
                                    debugHtml += '</ul>';
                                }
                                
                                debugHtml += '<p><strong>SQL Query:</strong> ' + response.data.debug_info.sql_query + '</p>';
                                
                                $debugOutput.html(debugHtml);
                            }
                        } else {
                            $message.text('Error recalculating: ' + response.data);
                        }
                    },
                    error: function() {
                        $spinner.css('visibility', 'hidden');
                        $message.text('Error during recalculation request.');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get diagnostic data
     *
     * @param int $product_id Product ID
     * @return array Diagnostic data
     */
    private function get_diagnostic_data($product_id) {
        $data = array(
            'wc_version' => function_exists('WC') ? WC()->version : 'Unknown',
            'hpos_enabled' => false,
            'total_sold' => $this->get_total_quantity_sold($product_id),
            'order_statuses' => $this->get_order_status_counts($product_id),
            'cart_quantity' => $this->get_cart_quantity_for_product($product_id),
            'tiers' => get_post_meta($product_id, '_group_discount_tiers', true),
            'current_tier' => false
        );
        
        // Check HPOS in a safe way
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil') && 
            method_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil', 'is_feature_enabled')) {
            $data['hpos_enabled'] = \Automattic\WooCommerce\Utilities\FeaturesUtil::is_feature_enabled('custom_order_tables');
        }
        
        // Get current tier
        if (!empty($data['tiers']) && is_array($data['tiers'])) {
            usort($data['tiers'], function($a, $b) {
                return $b['quantity'] - $a['quantity'];
            });
            
            foreach ($data['tiers'] as $tier) {
                if ($data['total_sold'] >= $tier['quantity'] && isset($tier['price']) && $tier['price'] !== '') {
                    $data['current_tier'] = $tier;
                    break;
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Get order status counts for a product
     *
     * @param int $product_id Product ID
     * @return array Order status counts
     */
    private function get_order_status_counts($product_id) {
        global $wpdb;
        
        $statuses = array(
            'wc-completed'  => 0,
            'wc-processing' => 0,
            'wc-on-hold'    => 0,
            'wc-pending'    => 0,
            'wc-cancelled'  => 0,
            'wc-refunded'   => 0,
            'wc-failed'     => 0
        );
        
        group_discount_debug_log('Getting order status counts for product ' . $product_id);
        
        // Check that we have a valid product ID
        if (!$product_id || !is_numeric($product_id)) {
            group_discount_debug_log('Invalid product ID: ' . $product_id);
            return $statuses;
        }
        
        // Try both with and without 'wc-' prefix as some stores might use different prefix formats
        foreach ($statuses as $status => $count) {
            // With 'wc-' prefix
            $query_with_prefix = $wpdb->prepare("
                SELECT SUM(order_item_meta.meta_value) as total
                FROM {$wpdb->prefix}woocommerce_order_items as order_items
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as product_id_meta ON order_items.order_item_id = product_id_meta.order_item_id
                LEFT JOIN {$wpdb->posts} as posts ON posts.ID = order_items.order_id
                WHERE posts.post_type = 'shop_order'
                AND posts.post_status = %s
                AND order_items.order_item_type = 'line_item'
                AND order_item_meta.meta_key = '_qty'
                AND product_id_meta.meta_key IN ('_product_id', '_variation_id')
                AND product_id_meta.meta_value = %d
            ", $status, $product_id);
            
            // Without 'wc-' prefix
            $status_no_prefix = str_replace('wc-', '', $status);
            $query_no_prefix = $wpdb->prepare("
                SELECT SUM(order_item_meta.meta_value) as total
                FROM {$wpdb->prefix}woocommerce_order_items as order_items
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
                LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as product_id_meta ON order_items.order_item_id = product_id_meta.order_item_id
                LEFT JOIN {$wpdb->posts} as posts ON posts.ID = order_items.order_id
                WHERE posts.post_type = 'shop_order'
                AND posts.post_status = %s
                AND order_items.order_item_type = 'line_item'
                AND order_item_meta.meta_key = '_qty'
                AND product_id_meta.meta_key IN ('_product_id', '_variation_id')
                AND product_id_meta.meta_value = %d
            ", $status_no_prefix, $product_id);
            
            // Get results for both queries
            $result_with_prefix = $wpdb->get_var($query_with_prefix);
            $result_no_prefix = $wpdb->get_var($query_no_prefix);
            
            // Debug info
            group_discount_debug_log("SQL Query with prefix '{$status}': " . $query_with_prefix);
            group_discount_debug_log("SQL Query result with prefix: " . $result_with_prefix);
            group_discount_debug_log("SQL Query without prefix '{$status_no_prefix}': " . $query_no_prefix);
            group_discount_debug_log("SQL Query result without prefix: " . $result_no_prefix);
            
            // Use the higher value from the two queries
            $statuses[$status] = max(absint($result_with_prefix), absint($result_no_prefix));
        }
        
        // Also try a completely different query approach using INNER JOIN and meta_key='_product_id'
        $alternative_query = $wpdb->prepare("
            SELECT p.post_status, SUM(woi.meta_value) as total_qty
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON p.ID = oi.order_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta woi ON oi.order_item_id = woi.order_item_id AND woi.meta_key = '_qty'
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta woip ON oi.order_item_id = woip.order_item_id AND woip.meta_key = '_product_id' AND woip.meta_value = %d
            WHERE p.post_type = 'shop_order'
            GROUP BY p.post_status
        ", $product_id);
        
        $alternative_results = $wpdb->get_results($alternative_query);
        group_discount_debug_log("Alternative SQL Query: " . $alternative_query);
        group_discount_debug_log("Alternative SQL Query results: ", $alternative_results);
        
        if (!empty($alternative_results)) {
            foreach ($alternative_results as $result) {
                $status = 'wc-' . $result->post_status;
                if (isset($statuses[$status])) {
                    // If the alternative query found more, use that
                    if ($result->total_qty > $statuses[$status]) {
                        $statuses[$status] = absint($result->total_qty);
                        group_discount_debug_log("Using alternative result for {$status}: {$result->total_qty}");
                    }
                }
                // Also store without prefix
                if (array_key_exists('wc-' . $result->post_status, $statuses)) {
                    $alt_status = 'wc-' . $result->post_status;
                    if ($result->total_qty > $statuses[$alt_status]) {
                        $statuses[$alt_status] = absint($result->total_qty);
                    }
                }
            }
        }
        
        // Add direct WooCommerce API check
        if (function_exists('wc_get_orders')) {
            group_discount_debug_log("Trying direct WooCommerce API to find orders");
            
            // Get orders containing this product
            $order_query = array(
                'status' => array('completed', 'processing', 'on-hold', 'pending'),
                'limit' => -1,
                'return' => 'ids',
            );
            
            try {
                $order_ids = wc_get_orders($order_query);
                
                if (!empty($order_ids)) {
                    group_discount_debug_log("Found " . count($order_ids) . " orders to check");
                    
                    foreach ($order_ids as $order_id) {
                        $order = wc_get_order($order_id);
                        if (!$order) continue;
                        
                        foreach ($order->get_items() as $item) {
                            if ($item->get_product_id() == $product_id) {
                                $status = 'wc-' . $order->get_status();
                                if (isset($statuses[$status])) {
                                    $statuses[$status] += absint($item->get_quantity());
                                    group_discount_debug_log("Found order #{$order_id} with status {$status} containing {$item->get_quantity()} units");
                                }
                            }
                        }
                    }
                } else {
                    group_discount_debug_log("No orders found via WooCommerce API");
                }
            } catch (Exception $e) {
                group_discount_debug_log("Error using WooCommerce API: " . $e->getMessage());
            }
        }
        
        // Check the database directly for order data
        $this->debug_database_for_product_orders($product_id);
        
        return $statuses;
    }
    
    /**
     * Debug function to check the database directly for orders containing a product
     *
     * @param int $product_id Product ID
     */
    private function debug_database_for_product_orders($product_id) {
        global $wpdb;
        
        group_discount_debug_log("Running database debug for product ID: " . $product_id);
        
        // First check if the product exists
        $product_check = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'product'", $product_id));
        group_discount_debug_log("Product check: " . ($product_check ? "Found" : "Not found"));
        
        // Direct query to find order items with this product ID
        $order_items_query = $wpdb->prepare("
            SELECT 
                oi.order_id,
                oi.order_item_id,
                oi.order_item_name,
                p.post_status,
                p.post_date
            FROM 
                {$wpdb->prefix}woocommerce_order_items AS oi
            JOIN 
                {$wpdb->prefix}woocommerce_order_itemmeta AS oim 
                ON oi.order_item_id = oim.order_item_id
            JOIN 
                {$wpdb->posts} AS p 
                ON oi.order_id = p.ID
            WHERE 
                oi.order_item_type = 'line_item'
                AND oim.meta_key = '_product_id'
                AND oim.meta_value = %d
                AND p.post_type = 'shop_order'
            ORDER BY 
                p.post_date DESC
            LIMIT 20
        ", $product_id);
        
        $order_items = $wpdb->get_results($order_items_query);
        group_discount_debug_log("Order items query: " . $order_items_query);
        group_discount_debug_log("Found " . count($order_items) . " order items containing this product");
        
        if (!empty($order_items)) {
            foreach ($order_items as $item) {
                // Get quantity for this order item
                $qty_query = $wpdb->prepare("
                    SELECT meta_value 
                    FROM {$wpdb->prefix}woocommerce_order_itemmeta 
                    WHERE order_item_id = %d 
                    AND meta_key = '_qty'
                ", $item->order_item_id);
                
                $qty = $wpdb->get_var($qty_query);
                
                group_discount_debug_log("Order #{$item->order_id} ({$item->post_status}) from {$item->post_date} contains item '{$item->order_item_name}' with quantity: {$qty}");
            }
        }
        
        // Also check order counts by status
        $order_count_query = "
            SELECT 
                p.post_status,
                COUNT(*) as order_count
            FROM 
                {$wpdb->posts} AS p
            WHERE 
                p.post_type = 'shop_order'
            GROUP BY 
                p.post_status
        ";
        
        $order_counts = $wpdb->get_results($order_count_query);
        group_discount_debug_log("Overall order counts in the system:");
        
        if (!empty($order_counts)) {
            foreach ($order_counts as $count) {
                group_discount_debug_log("Status {$count->post_status}: {$count->order_count} orders");
            }
        }
    }
    
    /**
     * Count orders directly using a simpler approach
     * 
     * @param int $product_id Product ID
     * @return int Total quantity
     */
    private function count_orders_directly($product_id) {
        global $wpdb;
        
        // Simple query to count all orders for this product regardless of status
        $query = $wpdb->prepare("
            SELECT SUM(woi.meta_value) as total_qty
            FROM {$wpdb->prefix}woocommerce_order_items oi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta woi ON oi.order_item_id = woi.order_item_id AND woi.meta_key = '_qty'
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta woip ON oi.order_item_id = woip.order_item_id 
            WHERE woip.meta_key = '_product_id' AND woip.meta_value = %d
        ", $product_id);
        
        $result = $wpdb->get_var($query);
        group_discount_debug_log("Direct count query: " . $query);
        group_discount_debug_log("Direct count result: " . $result);
        
        return absint($result);
    }
    
    /**
     * Handle AJAX request to clear all caches
     */
    public function ajax_clear_all_caches() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'group-discount-nonce')) {
            wp_send_json_error('Invalid nonce');
            exit;
        }
        
        // Clear all caches
        Group_Discount_Cache::force_clear_all_caches();
        
        wp_send_json_success(array(
            'message' => __('All caches cleared successfully.', 'group-discount')
        ));
        exit;
    }

    /**
     * Get the true regular price for a product, bypassing any active discounts
     * 
     * @param int|WC_Product $product_or_id Product object or ID
     * @return float The regular price
     */
    private function get_true_regular_price($product_or_id) {
        $product_id = is_object($product_or_id) ? $product_or_id->get_id() : intval($product_or_id);
        $product = is_object($product_or_id) ? $product_or_id : wc_get_product($product_id);
        
        if (!$product) {
            return 0;
        }
        
        // First try to get the regular price directly from post meta
        $meta_regular_price = get_post_meta($product_id, '_regular_price', true);
        
        if (!empty($meta_regular_price)) {
            group_discount_debug_log("Using meta regular price for product {$product_id}: {$meta_regular_price}");
            return floatval($meta_regular_price);
        }
        
        // If that doesn't work, try the WooCommerce API
        $regular_price = $product->get_regular_price();
        
        if (!empty($regular_price)) {
            group_discount_debug_log("Using WC API regular price for product {$product_id}: {$regular_price}");
            return floatval($regular_price);
        }
        
        // If that's still empty, it might be a variable product
        if ($product->is_type('variable')) {
            $regular_price = $product->get_variation_regular_price('min');
            if (!empty($regular_price)) {
                group_discount_debug_log("Using variable product regular price for product {$product_id}: {$regular_price}");
                return floatval($regular_price);
            }
        }
        
        // Last resort - use the current price
        $price = $product->get_price();
        group_discount_debug_log("Using fallback price for product {$product_id}: {$price}");
        return floatval($price);
    }

    /**
     * Ensure translations are properly loaded
     * This method attempts to load translations directly and with multiple approaches
     */
    private function ensure_translations_loaded() {
        // Get current locale
        $locale = determine_locale();
        group_discount_debug_log('Ensuring translations are loaded for locale: ' . $locale);
        
        // Track if this is a Chinese site
        $is_chinese = false;
        
        // Special handling for Chinese sites
        $is_chinese_site = function_exists('group_discount_detect_chinese_site') && group_discount_detect_chinese_site();
        if ($is_chinese_site) {
            $is_chinese = true;
            // Check if it's Traditional Chinese (Taiwan or Hong Kong)
            $is_traditional = false;
            
            // First check locale
            if (in_array($locale, array('zh_TW', 'zh-TW', 'zh_HK', 'zh-HK'))) {
                $is_traditional = true;
            } else {
                // Try to detect from content if locale isn't definitive
                $content = get_bloginfo('name') . ' ' . get_bloginfo('description');
                
                // Characters that differ between Traditional and Simplified
                $traditional_chars = array('說', '時', '國', '會', '東', '語', '學', '關', '車', '書', '實', '點', 
                                           '萬', '樣', '發', '經', '處', '產', '見', '號', '長', '親', '務', '熱');
                $simplified_chars = array('说', '时', '国', '会', '东', '语', '学', '关', '车', '书', '实', '点', 
                                          '万', '样', '发', '经', '处', '产', '见', '号', '长', '亲', '务', '热');
                
                $traditional_count = 0;
                $simplified_count = 0;
                
                foreach ($traditional_chars as $char) {
                    if (mb_strpos($content, $char) !== false) {
                        $traditional_count++;
                    }
                }
                
                foreach ($simplified_chars as $char) {
                    if (mb_strpos($content, $char) !== false) {
                        $simplified_count++;
                    }
                }
                
                if ($traditional_count > $simplified_count) {
                    $is_traditional = true;
                }
            }
            
            $zh_variant = $is_traditional ? 'zh_TW' : 'zh_CN';
            group_discount_debug_log('Chinese site detected, using ' . ($is_traditional ? 'Traditional' : 'Simplified') . ' Chinese: ' . $zh_variant);
            $locale = $zh_variant;
        } else if (strpos($locale, 'zh') === 0) {
            // If the locale is Chinese but not detected as a Chinese site
            $is_chinese = true;
            group_discount_debug_log('Chinese locale detected: ' . $locale);
        }
        
        // Try multiple methods to load translations
        $domain = 'group-discount';
        
        // Build an array of MO files to try
        $mo_files_to_try = array();
        
        // WP languages directory
        $mo_files_to_try[] = WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale . '.mo';
        
        // Plugin languages directory
        $mo_files_to_try[] = GD_PLUGIN_PATH . 'languages/' . $domain . '-' . $locale . '.mo';
        
        // Language-only file if full locale file doesn't exist
        $lang_only = substr($locale, 0, 2);
        if ($lang_only !== $locale) {
            $mo_files_to_try[] = GD_PLUGIN_PATH . 'languages/' . $domain . '-' . $lang_only . '.mo';
        }
        
        // For Chinese, make sure we try both variants
        if ($is_chinese) {
            $mo_files_to_try[] = GD_PLUGIN_PATH . 'languages/' . $domain . '-zh_CN.mo';
            $mo_files_to_try[] = GD_PLUGIN_PATH . 'languages/' . $domain . '-zh_TW.mo';
            $mo_files_to_try[] = GD_PLUGIN_PATH . 'languages/' . $domain . '-zh.mo';
        }
        
        // Remove duplicates
        $mo_files_to_try = array_unique($mo_files_to_try);
        
        // Try each MO file
        $translations_loaded = false;
        foreach ($mo_files_to_try as $mo_file) {
            if (file_exists($mo_file)) {
                $result = load_textdomain($domain, $mo_file);
                if ($result) {
                    $translations_loaded = true;
                    group_discount_debug_log('Loaded textdomain from: ' . $mo_file);
                    
                    // Test translation
                    $test = __('Original price', 'group-discount');
                    group_discount_debug_log('Translation test after loading ' . basename($mo_file) . ': "Original price" => "' . $test . '"');
                    
                    // If it's a Chinese translation and we've successfully loaded it, log the details
                    if (strpos(basename($mo_file), 'zh_CN') !== false) {
                        group_discount_debug_log('Successfully loaded Simplified Chinese translation');
                    } else if (strpos(basename($mo_file), 'zh_TW') !== false) {
                        group_discount_debug_log('Successfully loaded Traditional Chinese translation');
                    }
                }
            }
        }
        
        // Finally use standard WP method as fallback
        if (!$translations_loaded) {
            load_plugin_textdomain($domain, false, dirname(plugin_basename(GD_PLUGIN_PATH)) . '/languages');
            group_discount_debug_log('Used standard load_plugin_textdomain method');
            
            // Test if it worked
            $test = __('Original price', 'group-discount');
            $is_english = ($test === 'Original price');
            group_discount_debug_log('Translation test after standard method: ' . ($is_english ? 'Still in English' : 'Translated successfully') . ' ("Original price" => "' . $test . '")');
        }
    }
} 
