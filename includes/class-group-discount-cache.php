<?php
/**
 * Cache helper class for Group Discount
 */
class Group_Discount_Cache {
    
    /**
     * Cache key prefix
     */
    const CACHE_PREFIX = 'gd_';
    
    /**
     * Cache expiration in seconds (reduced to 2 minutes)
     */
    const CACHE_EXPIRATION = 120;
    
    /**
     * Get cached value
     *
     * @param string $key Cache key
     * @return mixed|false Cached value or false if not found
     */
    public static function get($key) {
        // For total_sold keys, reduce the chance of using cache in case of new orders
        if (strpos($key, 'total_sold_') === 0) {
            // 50% chance of bypassing cache for fresher results
            if (mt_rand(1, 2) === 1) {
                if (defined('GD_DEBUG') && GD_DEBUG) {
                    error_log('Group Discount Cache - Bypassing cache for ' . $key);
                }
                return false;
            }
        }
        
        $value = get_transient(self::CACHE_PREFIX . $key);
        
        if (defined('GD_DEBUG') && GD_DEBUG) {
            if ($value === false) {
                error_log('Group Discount Cache - Cache miss for ' . $key);
            } else {
                error_log('Group Discount Cache - Cache hit for ' . $key . ': ' . $value);
            }
        }
        
        return $value;
    }
    
    /**
     * Set cached value
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $expiration Optional. Expiration time in seconds. Default is 2 minutes.
     * @return bool True on success, false on failure
     */
    public static function set($key, $value, $expiration = self::CACHE_EXPIRATION) {
        if (defined('GD_DEBUG') && GD_DEBUG) {
            error_log('Group Discount Cache - Setting cache for ' . $key . ': ' . $value);
        }
        return set_transient(self::CACHE_PREFIX . $key, $value, $expiration);
    }
    
    /**
     * Delete cached value
     *
     * @param string $key Cache key
     * @return bool True on success, false on failure
     */
    public static function delete($key) {
        if (defined('GD_DEBUG') && GD_DEBUG) {
            error_log('Group Discount Cache - Deleting cache for ' . $key);
        }
        return delete_transient(self::CACHE_PREFIX . $key);
    }
    
    /**
     * Clear all product quantity cache on order status change
     *
     * @param int $order_id Order ID
     */
    public static function clear_product_cache_on_order($order_id) {
        // Force clear all product caches immediately on any order change
        self::force_clear_all_caches();
        
        // Get order object based on whether HPOS is enabled
        $order = null;
        
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
        }
        
        if (!$order) {
            if (defined('GD_DEBUG') && GD_DEBUG) {
                error_log('Group Discount Cache - Order not found for ID: ' . $order_id);
            }
            return;
        }
        
        // Log for debugging
        if (defined('GD_DEBUG') && GD_DEBUG) {
            error_log('Group Discount Cache - Clearing cache for order #' . $order_id . ' with status: ' . $order->get_status());
        }
        
        // Loop through all order items
        try {
            foreach ($order->get_items() as $item) {
                // Get product ID using methods compatible with both legacy and HPOS
                $product_id = 0;
                
                if (method_exists($item, 'get_product_id')) {
                    $product_id = $item->get_product_id();
                } else if (method_exists($item, 'get_product')) {
                    $product = $item->get_product();
                    if ($product && is_object($product)) {
                        $product_id = $product->get_id();
                    }
                }
                
                if ($product_id) {
                    if (defined('GD_DEBUG') && GD_DEBUG) {
                        error_log('Group Discount Cache - Clearing cache for product #' . $product_id . ' in order #' . $order_id);
                    }
                    self::delete('total_sold_' . $product_id);
                    
                    // Also clear cache for all products to be safe
                    self::clear_all_product_caches();
                }
            }
        } catch (Exception $e) {
            if (defined('GD_DEBUG') && GD_DEBUG) {
                error_log('Group Discount Cache - Error processing order #' . $order_id . ': ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Clear cache for all products
     */
    public static function clear_all_product_caches() {
        global $wpdb;
        
        try {
            // Get all product IDs with group discount enabled
            $query = "
                SELECT post_id FROM $wpdb->postmeta 
                WHERE meta_key = '_group_discount_enabled' 
                AND meta_value = 'yes'
            ";
            
            if (defined('GD_DEBUG') && GD_DEBUG) {
                error_log('Group Discount Cache - Running query: ' . $query);
            }
            
            $product_ids = $wpdb->get_col($query);
            
            if (defined('GD_DEBUG') && GD_DEBUG) {
                error_log('Group Discount Cache - Found ' . count($product_ids) . ' products with group discount enabled');
            }
            
            if (!empty($product_ids)) {
                foreach ($product_ids as $product_id) {
                    self::delete('total_sold_' . $product_id);
                    
                    // Log for debugging
                    if (defined('GD_DEBUG') && GD_DEBUG) {
                        error_log('Group Discount Cache - Cleared cache for product #' . $product_id);
                    }
                }
            }
        } catch (Exception $e) {
            if (defined('GD_DEBUG') && GD_DEBUG) {
                error_log('Group Discount Cache - Error clearing product caches: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Force clear all caches by directly querying the database
     */
    public static function force_clear_all_caches() {
        global $wpdb;
        
        try {
            // Delete all group discount transients immediately
            $query = "
                DELETE FROM $wpdb->options 
                WHERE option_name LIKE '_transient_" . self::CACHE_PREFIX . "%'
                   OR option_name LIKE '_transient_timeout_" . self::CACHE_PREFIX . "%'
            ";
            
            if (defined('GD_DEBUG') && GD_DEBUG) {
                error_log('Group Discount Cache - Force clearing all caches with query: ' . $query);
            }
            
            $result = $wpdb->query($query);
            
            // Log for debugging
            if (defined('GD_DEBUG') && GD_DEBUG) {
                error_log('Group Discount Cache - Force cleared all caches. Result: ' . $result);
            }
            
            return $result;
        } catch (Exception $e) {
            if (defined('GD_DEBUG') && GD_DEBUG) {
                error_log('Group Discount Cache - Error force clearing caches: ' . $e->getMessage());
            }
            return false;
        }
    }
} 