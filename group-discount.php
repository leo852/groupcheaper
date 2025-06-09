<?php
/**
 * Plugin Name: BulkBoost Pro
 * Plugin URI: https://eas.hk/plugins/bulkboost-pro
 * Description: Dynamic Group Pricing for WooCommerce - Boost Sales with Community-Driven Discounts
 * Version: 1.2.2
 * Author: Enterprise_Alpha_Solutions
 * Author URI: https://eas.hk
 * Text Domain: group-discount
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0.0
 * WC tested up to: 8.0.0
 * License: GPL v2 or later
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('GD_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('GD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GD_PLUGIN_VERSION', '1.2.2');
define('GD_DEBUG', true); // Set to true to enable detailed debugging

// Special handling for Chinese-only sites
function group_discount_detect_chinese_site() {
    $locale = get_locale();
    $is_chinese = (strpos($locale, 'zh_') === 0);
    
    // Also check for Chinese characters in site title or description
    $site_title = get_bloginfo('name');
    $site_description = get_bloginfo('description');
    
    // Check for Chinese characters in site title or description
    $has_chinese_chars = preg_match('/[\x{4e00}-\x{9fa5}]/u', $site_title . $site_description);
    
    if ($is_chinese || $has_chinese_chars) {
        group_discount_debug_log('Detected Chinese site: locale=' . $locale . ', has_chinese_chars=' . ($has_chinese_chars ? 'yes' : 'no'));
        return true;
    }
    
    return false;
}

// Force Chinese translation if it's a Chinese site
function group_discount_force_chinese_translation() {
    if (!group_discount_detect_chinese_site()) {
        return;
    }
    
    // Try to determine which Chinese variant to use
    $locale = get_locale();
    $mo_file = '';
    
    // Better detection of Traditional Chinese
    $is_traditional = false;
    
    // Check for specific Traditional Chinese locales
    if (in_array($locale, array('zh_TW', 'zh-TW', 'zh_HK', 'zh-HK'))) {
        $is_traditional = true;
        group_discount_debug_log('Traditional Chinese detected from locale: ' . $locale);
    } else {
        // If locale doesn't clearly indicate, check site content for Traditional Chinese characters
        $content = get_bloginfo('name') . ' ' . get_bloginfo('description') . ' ' . get_bloginfo('url');
        
        // Enhanced character set for better detection
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
        
        // Be more aggressive with Traditional Chinese detection
        // If ANY traditional characters are found, assume Traditional Chinese
        if ($traditional_count > 0) {
            $is_traditional = true;
            group_discount_debug_log("Detected Traditional Chinese - found {$traditional_count} traditional characters");
        } else if ($simplified_count > 0) {
            group_discount_debug_log("Detected Simplified Chinese - found {$simplified_count} simplified characters");
        } else {
            // Check for .tw or .hk in the site URL
            $site_url = strtolower(get_bloginfo('url'));
            if (strpos($site_url, '.tw') !== false || strpos($site_url, '.hk') !== false || 
                strpos($site_url, '/tw/') !== false || strpos($site_url, '/hk/') !== false) {
                $is_traditional = true;
                group_discount_debug_log("Detected Traditional Chinese from URL pattern: {$site_url}");
            }
        }
    }
    
    // Set the variant based on detection
    $zh_variant = $is_traditional ? 'zh_TW' : 'zh_CN';
    $mo_file = GD_PLUGIN_PATH . 'languages/group-discount-' . $zh_variant . '.mo';
    
    group_discount_debug_log('Using ' . ($is_traditional ? 'Traditional' : 'Simplified') . ' Chinese: ' . $zh_variant);
    
    // Check if the MO file exists and load it directly
    if (file_exists($mo_file)) {
        load_textdomain('group-discount', $mo_file);
        group_discount_debug_log('Forced loading of Chinese translation: ' . $mo_file);
        
        // Test translation
        $test = __('Original price', 'group-discount');
        group_discount_debug_log('Translation test: "Original price" => "' . $test . '"');
    } else {
        group_discount_debug_log('Chinese MO file not found: ' . $mo_file);
        
        // Try loading both variants if one fails
        $alt_variant = $is_traditional ? 'zh_CN' : 'zh_TW';
        $alt_mo_file = GD_PLUGIN_PATH . 'languages/group-discount-' . $alt_variant . '.mo';
        
        if (file_exists($alt_mo_file)) {
            load_textdomain('group-discount', $alt_mo_file);
            group_discount_debug_log('Loaded alternative Chinese translation as fallback: ' . $alt_mo_file);
        }
    }
}

// Call this function early
group_discount_force_chinese_translation();

// Force load Chinese translations on a very early hook to ensure they're available before WooCommerce loads
function group_discount_load_chinese_translations_early() {
    if (group_discount_detect_chinese_site()) {
        $locale = get_locale();
        
        // Check if it's Traditional Chinese
        $is_traditional = false;
        
        // Check locale first
        if (in_array($locale, array('zh_TW', 'zh-TW', 'zh_HK', 'zh-HK'))) {
            $is_traditional = true;
        } else if (strpos($locale, 'zh') === 0) {
            // Try to analyze site content
            $content = get_bloginfo('name') . ' ' . get_bloginfo('description');
            
            // Enhanced character set for better detection
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
                group_discount_debug_log("Early hook: Detected Traditional Chinese from content (Traditional: $traditional_count, Simplified: $simplified_count)");
            }
        }
        
        // Make sure we try both variants if needed
        $zh_variant = $is_traditional ? 'zh_TW' : 'zh_CN';
        $mo_file = GD_PLUGIN_PATH . 'languages/group-discount-' . $zh_variant . '.mo';
        
        if (file_exists($mo_file)) {
            load_textdomain('group-discount', $mo_file);
            group_discount_debug_log('Early hook: Loaded ' . ($is_traditional ? 'Traditional' : 'Simplified') . ' Chinese translation: ' . $mo_file);
        } else {
            // Try alternative variant if primary fails
            $alt_variant = $is_traditional ? 'zh_CN' : 'zh_TW';
            $alt_mo_file = GD_PLUGIN_PATH . 'languages/group-discount-' . $alt_variant . '.mo';
            
            if (file_exists($alt_mo_file)) {
                load_textdomain('group-discount', $alt_mo_file);
                group_discount_debug_log('Early hook: Loaded alternative Chinese translation as fallback: ' . $alt_mo_file);
            }
        }
        
        // Try one more time with the direct file path
        if (!file_exists($mo_file) && !file_exists(GD_PLUGIN_PATH . 'languages/group-discount-' . $alt_variant . '.mo')) {
            // Try direct zh file
            $zh_mo_file = GD_PLUGIN_PATH . 'languages/group-discount-zh.mo';
            if (file_exists($zh_mo_file)) {
                load_textdomain('group-discount', $zh_mo_file);
                group_discount_debug_log('Early hook: Loaded generic zh translation: ' . $zh_mo_file);
            }
        }
    }
}
// Use very early hooks
add_action('plugins_loaded', 'group_discount_load_chinese_translations_early', 1);
add_action('init', 'group_discount_load_chinese_translations_early', 1);
add_action('after_setup_theme', 'group_discount_load_chinese_translations_early', 1);

// Additional hook for AJAX requests
add_action('admin_init', 'group_discount_load_chinese_translations_early', 1);
add_action('wp_ajax_nopriv_group_discount_refresh_price', 'group_discount_load_chinese_translations_early', 1);
add_action('wp_ajax_group_discount_refresh_price', 'group_discount_load_chinese_translations_early', 1);

// Safer debugging function that works even before other functions are loaded
function group_discount_debug_log($message, $data = null) {
    if (defined('GD_DEBUG') && GD_DEBUG && defined('WP_DEBUG') && WP_DEBUG) {
        $prefix = 'Group Discount Debug - ';
        if ($data !== null) {
            if (is_array($data) || is_object($data)) {
                error_log($prefix . $message . ': ' . print_r($data, true));
            } else {
                error_log($prefix . $message . ': ' . $data);
            }
        } else {
            error_log($prefix . $message);
        }
    }
}

// HPOS compatibility - Must use the before_woocommerce_init hook
function group_discount_declare_hpos_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        if (method_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil', 'declare_compatibility')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            group_discount_debug_log('Declared HPOS compatibility');
        }
    }
}

// Register HPOS compatibility at the correct hook
add_action('before_woocommerce_init', 'group_discount_declare_hpos_compatibility');

// Check if WooCommerce is active
function group_discount_check_woocommerce() {
    $active_plugins = (array) get_option('active_plugins', array());
    if (is_multisite()) {
        $active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
    }
    
    $woocommerce_active = in_array('woocommerce/woocommerce.php', $active_plugins) || 
                          array_key_exists('woocommerce/woocommerce.php', $active_plugins);
    
    if (!$woocommerce_active) {
        add_action('admin_notices', 'group_discount_woocommerce_missing_notice');
        return false;
    }
    
    return true;
}

// Admin notice for WooCommerce missing - avoid using __() at this early stage
function group_discount_woocommerce_missing_notice() {
    // Use plain text instead of translatable strings to avoid loading text domains too early
    echo '<div class="error"><p>Group Discount requires WooCommerce to be installed and active.</p></div>';
}

// Check compatibility with WooCommerce features
function group_discount_check_compatibility() {
    if (!function_exists('WC')) {
        return;
    }
    
    // Check for any issues with hooks
    $hooks_issues = false;
    if (!has_action('woocommerce_checkout_order_processed') || 
        !has_action('woocommerce_new_order') || 
        !has_action('woocommerce_order_status_changed')) {
        $hooks_issues = true;
    }
    
    // Check for HPOS compatibility in a safe way that works with older WooCommerce versions
    $hpos_enabled = false;
    
    // First check if the class exists
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        // Then check if the method exists to avoid fatal errors
        if (method_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil', 'is_feature_enabled')) {
            $hpos_enabled = \Automattic\WooCommerce\Utilities\FeaturesUtil::is_feature_enabled('custom_order_tables');
        }
    }
    
    // If there are issues, show admin notice
    if ($hooks_issues || $hpos_enabled) {
        add_action('admin_notices', 'group_discount_compatibility_notice');
        
        // Log debug info
        if (defined('GD_DEBUG') && GD_DEBUG) {
            error_log('Group Discount - Compatibility issues detected:');
            error_log('Group Discount - HPOS enabled: ' . ($hpos_enabled ? 'Yes' : 'No'));
            error_log('Group Discount - Hooks issues: ' . ($hooks_issues ? 'Yes' : 'No'));
        }
    }
}

// Admin notice for compatibility issues
function group_discount_compatibility_notice() {
    // Use plain text instead of translatable strings to avoid loading text domains too early
    echo '<div class="notice notice-warning is-dismissible">';
    echo '<p>Group Discount has detected potential compatibility issues with your WooCommerce configuration. This may affect the discount functionality. Please check the logs for more details.</p>';
    echo '</div>';
}

// Validate environment before proceeding
if (!group_discount_check_woocommerce()) {
    return;
}

// Load compatibility checks after WooCommerce is initialized
add_action('woocommerce_loaded', 'group_discount_check_compatibility');

// Load textdomain
function group_discount_load_textdomain() {
    $domain = 'group-discount';
    $locale = apply_filters('plugin_locale', determine_locale(), $domain);
    $mofile = $domain . '-' . $locale . '.mo';
    
    // Log the current locale for debugging
    group_discount_debug_log('Loading textdomain with locale: ' . $locale . ' (WordPress locale: ' . get_locale() . ')');
    
    $loaded = false;
    
    // 1. First check standard WordPress location (most reliable)
    $wp_languages_dir = WP_LANG_DIR . '/plugins/';
    if (file_exists($wp_languages_dir . $mofile)) {
        $loaded = load_textdomain($domain, $wp_languages_dir . $mofile);
        group_discount_debug_log('Tried loading from WP languages dir: ' . $wp_languages_dir . $mofile . ' - ' . ($loaded ? 'Success' : 'Failed'));
    }
    
    // 2. Check plugin's languages folder
    if (!$loaded) {
        $plugin_languages_dir = GD_PLUGIN_PATH . 'languages/';
        if (file_exists($plugin_languages_dir . $mofile)) {
            $loaded = load_textdomain($domain, $plugin_languages_dir . $mofile);
            group_discount_debug_log('Tried loading from plugin languages dir: ' . $plugin_languages_dir . $mofile . ' - ' . ($loaded ? 'Success' : 'Failed'));
        }
    }
    
    // 3. Try alternative locale formats (with normalized separators)
    if (!$loaded) {
        $locale_variants = array(
            $locale,
            str_replace('_', '-', $locale),  // Convert fr_FR to fr-FR
            str_replace('-', '_', $locale),  // Convert fr-fr to fr_fr
            strtolower($locale),             // Convert FR_fr to fr_fr
            strtolower(str_replace('_', '-', $locale)), // fr-fr
            substr($locale, 0, 2)            // Just language code: fr
        );
        
        $locale_variants = array_unique($locale_variants);
        
        foreach ($locale_variants as $variant) {
            $variant_mofile = $domain . '-' . $variant . '.mo';
            
            // Try WP languages dir
            if (file_exists($wp_languages_dir . $variant_mofile)) {
                $loaded = load_textdomain($domain, $wp_languages_dir . $variant_mofile);
                group_discount_debug_log('Tried loading variant from WP dir: ' . $variant_mofile . ' - ' . ($loaded ? 'Success' : 'Failed'));
                if ($loaded) break;
            }
            
            // Try plugin languages dir
            if (file_exists($plugin_languages_dir . $variant_mofile)) {
                $loaded = load_textdomain($domain, $plugin_languages_dir . $variant_mofile);
                group_discount_debug_log('Tried loading variant from plugin dir: ' . $variant_mofile . ' - ' . ($loaded ? 'Success' : 'Failed'));
                if ($loaded) break;
            }
        }
    }
    
    // 4. Try standard WordPress method as last resort
    if (!$loaded) {
        $loaded = load_plugin_textdomain($domain, false, dirname(plugin_basename(__FILE__)) . '/languages');
        group_discount_debug_log('Tried standard load_plugin_textdomain method - ' . ($loaded ? 'Success' : 'Failed'));
    }
    
    // Check if translations are working with a test string
    $test_string = __('Original price', 'group-discount');
    $english_match = ($test_string === 'Original price');
    group_discount_debug_log('Translation test: ' . ($english_match ? 'Still in English' : 'Translated') . ' ("Original price" => "' . $test_string . '")');
    
    // Verify MO files on disk - list all language files
    $language_files = glob($plugin_languages_dir . '*.{mo,po}', GLOB_BRACE);
    if (!empty($language_files)) {
        group_discount_debug_log('Found language files: ' . implode(', ', array_map('basename', $language_files)));
    } else {
        group_discount_debug_log('No language files found in plugin directory');
    }
    
    // If everything failed, try to output guidance
    if (!$loaded) {
        group_discount_debug_log('WARNING: Failed to load translations for ' . $locale);
        group_discount_debug_log('Places searched:');
        group_discount_debug_log(' - ' . $wp_languages_dir . $mofile);
        group_discount_debug_log(' - ' . $plugin_languages_dir . $mofile);
        group_discount_debug_log(' - WP and plugin dirs with variants: ' . implode(', ', $locale_variants));
    }
    
    return $loaded;
}
add_action('plugins_loaded', 'group_discount_load_textdomain', 9);
add_action('init', 'group_discount_load_textdomain');

// NEW: Add early hooks to make sure translations are loaded first
add_action('after_setup_theme', 'group_discount_load_textdomain', 5);
add_action('setup_theme', 'group_discount_load_textdomain', 5);
add_action('muplugins_loaded', 'group_discount_load_textdomain', 5);

// Force translation loading directly after plugin is included
group_discount_load_textdomain();

// Delayed file loading on init to ensure WooCommerce is fully loaded
function group_discount_load_files() {
    // Include necessary files
    $files = array(
        'includes/class-group-discount-cache.php',
        'includes/class-group-discount.php'
    );
    
    $all_loaded = true;
    foreach ($files as $file) {
        $path = GD_PLUGIN_PATH . $file;
        if (file_exists($path)) {
            require_once $path;
            group_discount_debug_log('Loaded file: ' . $file);
        } else {
            group_discount_debug_log('Error: Could not load file: ' . $file);
            $all_loaded = false;
        }
    }
    
    return $all_loaded;
}

// Initialize the plugin on woocommerce_init hook to avoid loading too early
function group_discount_init() {
    // Double-check WooCommerce is available
    if (!class_exists('WooCommerce')) {
        group_discount_debug_log('WooCommerce class not available at init, delaying initialization');
        return;
    }
    
    // Load required files
    if (!group_discount_load_files()) {
        group_discount_debug_log('Not all required files could be loaded, aborting initialization');
        return;
    }
    
    // Initialize the plugin
    if (class_exists('Group_Discount')) {
        group_discount_debug_log('Initializing plugin');
        $plugin = new Group_Discount();
        $plugin->init();
    } else {
        group_discount_debug_log('Group_Discount class not available after loading files');
    }
}
add_action('woocommerce_init', 'group_discount_init');

// Enqueue frontend scripts and styles
function group_discount_enqueue_frontend_scripts() {
    // Only load on product pages
    if (!is_product()) {
        return;
    }
    
    // Enqueue CSS
    wp_enqueue_style('group-discount-frontend', GD_PLUGIN_URL . 'assets/css/frontend.css', array(), GD_PLUGIN_VERSION);
}
add_action('wp_enqueue_scripts', 'group_discount_enqueue_frontend_scripts');

// Manual refresh of all caches (useful for debugging)
function group_discount_force_refresh_all() {
    if (!class_exists('Group_Discount_Cache')) {
        group_discount_debug_log('Group_Discount_Cache class not available');
        return;
    }
    
    group_discount_debug_log('Manually refreshing all caches');
    Group_Discount_Cache::force_clear_all_caches();
    
    // Force-refresh all products with group discount enabled
    global $wpdb;
    $product_ids = $wpdb->get_col("
        SELECT post_id FROM $wpdb->postmeta 
        WHERE meta_key = '_group_discount_enabled' 
        AND meta_value = 'yes'
    ");
    
    if (!empty($product_ids)) {
        foreach ($product_ids as $product_id) {
            Group_Discount_Cache::delete('total_sold_' . $product_id);
        }
    }
}

// Only register order status hooks when WooCommerce is fully loaded
function group_discount_register_order_hooks() {
    // Clear cache when order status changes
    function group_discount_clear_cache_on_order_status_change($order_id) {
        if (!class_exists('Group_Discount_Cache')) {
            return;
        }
        
        group_discount_debug_log('Order status change detected', $order_id);
        Group_Discount_Cache::clear_product_cache_on_order($order_id);
    }
    
    // Hook into various order status changes
    add_action('woocommerce_order_status_completed', 'group_discount_clear_cache_on_order_status_change');
    add_action('woocommerce_order_status_processing', 'group_discount_clear_cache_on_order_status_change');
    add_action('woocommerce_order_status_on-hold', 'group_discount_clear_cache_on_order_status_change');
    add_action('woocommerce_order_status_cancelled', 'group_discount_clear_cache_on_order_status_change');
    add_action('woocommerce_order_status_refunded', 'group_discount_clear_cache_on_order_status_change');
    
    // Additional hooks to handle new orders immediately
    add_action('woocommerce_new_order', 'group_discount_clear_cache_on_order_status_change');
    add_action('woocommerce_checkout_order_processed', 'group_discount_clear_cache_on_order_status_change');
    add_action('woocommerce_order_status_pending', 'group_discount_clear_cache_on_order_status_change');
    add_action('woocommerce_payment_complete', 'group_discount_clear_cache_on_order_status_change');
    
    // Additional hook for AJAX add to cart
    add_action('woocommerce_ajax_added_to_cart', 'group_discount_force_refresh_all');
    
    group_discount_debug_log('Registered order hooks');
}
add_action('woocommerce_after_register_post_type', 'group_discount_register_order_hooks');

// Register a scheduled event to clean up transients
register_activation_hook(__FILE__, 'group_discount_activation');
function group_discount_activation() {
    if (!wp_next_scheduled('group_discount_cleanup_transients')) {
        wp_schedule_event(time(), 'hourly', 'group_discount_cleanup_transients');
    }
    
    // Clear all caches on activation
    if (class_exists('Group_Discount_Cache')) {
        Group_Discount_Cache::force_clear_all_caches();
    }
    
    group_discount_debug_log('Plugin activated');
}

// Cleanup transients
add_action('group_discount_cleanup_transients', 'group_discount_do_cleanup_transients');
function group_discount_do_cleanup_transients() {
    // Only run if the cache class is available
    if (class_exists('Group_Discount_Cache')) {
        Group_Discount_Cache::force_clear_all_caches();
        group_discount_debug_log('Transients cleaned up');
    } else {
        group_discount_debug_log('Group_Discount_Cache class not available for cleanup');
    }
}

// Force clear all caches more frequently
add_action('init', 'group_discount_maybe_force_clear_caches');
function group_discount_maybe_force_clear_caches() {
    // Always clear caches on init during debugging
    if (defined('GD_DEBUG') && GD_DEBUG) {
        if (class_exists('Group_Discount_Cache')) {
            Group_Discount_Cache::force_clear_all_caches();
            group_discount_debug_log('Forced cache clear during init');
        }
        
        // Check for any orders in the system
        group_discount_check_for_orders();
    } else {
        // In production, clear caches randomly (5% chance) to ensure fresh data periodically
        if (mt_rand(1, 20) === 1) {
            if (class_exists('Group_Discount_Cache')) {
                Group_Discount_Cache::force_clear_all_caches();
                group_discount_debug_log('Random cache clear triggered');
            }
        }
    }
}

/**
 * Check for any orders directly from the database
 * This helps catch orders that might be missed by our regular counting methods
 */
function group_discount_check_for_orders() {
    if (!function_exists('WC') || !class_exists('Group_Discount_Cache')) {
        return;
    }
    
    global $wpdb;
    
    // Get all products with group discount enabled
    $enabled_products = $wpdb->get_col("
        SELECT post_id FROM $wpdb->postmeta 
        WHERE meta_key = '_group_discount_enabled' 
        AND meta_value = 'yes'
    ");
    
    if (empty($enabled_products)) {
        return;
    }
    
    group_discount_debug_log('Checking for orders for ' . count($enabled_products) . ' products with Group Discount enabled');
    
    foreach ($enabled_products as $product_id) {
        // Direct query to find any orders for this product
        $query = $wpdb->prepare("
            SELECT COUNT(DISTINCT oi.order_id) as order_count, SUM(woi.meta_value) as total_qty
            FROM {$wpdb->prefix}woocommerce_order_items oi
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta woi ON oi.order_item_id = woi.order_item_id AND woi.meta_key = '_qty'
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta woip ON oi.order_item_id = woip.order_item_id 
            WHERE woip.meta_key = '_product_id' AND woip.meta_value = %d
        ", $product_id);
        
        $result = $wpdb->get_row($query);
        
        if (!empty($result) && $result->order_count > 0) {
            group_discount_debug_log("Product #{$product_id} has {$result->order_count} orders with total quantity {$result->total_qty}");
            
            // Store this count in the cache
            $cache_key = 'total_sold_' . $product_id;
            $current_cache = Group_Discount_Cache::get($cache_key);
            
            // Only update if the new count is higher or there's no cached value
            if ($current_cache === false || $result->total_qty > $current_cache) {
                Group_Discount_Cache::set($cache_key, $result->total_qty, 60);
                group_discount_debug_log("Updated cache for product #{$product_id} to {$result->total_qty} units");
            }
        }
    }
}

// Add REST API support for WooCommerce block checkout
add_action('rest_api_init', 'group_discount_register_rest_hooks');
function group_discount_register_rest_hooks() {
    // Only if WooCommerce exists
    if (!function_exists('WC')) {
        return;
    }
    
    // Clear caches after API order creation
    add_action('woocommerce_rest_insert_shop_order_object', 'group_discount_clear_cache_on_order_status_change');
    group_discount_debug_log('Registered REST API hooks');
}

// Clean up on deactivation
register_deactivation_hook(__FILE__, 'group_discount_deactivation');
function group_discount_deactivation() {
    wp_clear_scheduled_hook('group_discount_cleanup_transients');
    group_discount_debug_log('Plugin deactivated');
} 