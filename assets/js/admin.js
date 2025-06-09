/**
 * Group Discount Admin JavaScript
 */
jQuery(document).ready(function($) {
    'use strict';
    
    // Check if the admin page is properly initialized
    console.log('Group Discount: Admin script loaded');
    
    if (typeof group_discount_params === 'undefined') {
        console.log('Group Discount: Missing parameters, some features may not work');
    } else {
        console.log('Group Discount: Parameters loaded, ajax_url: ' + group_discount_params.ajax_url);
    }
    
    // Group Discount Tiers
    var $tiersWrapper = $('.group-discount-tiers');
    
    // Add tier
    $tiersWrapper.on('click', '.add-tier', function(e) {
        e.preventDefault();
        
        var tierIndex = $('.tier-row').length;
        var tierTemplate = `
            <div class="tier-row">
                <div class="tier-quantity">
                    <input type="number" name="_group_discount_tiers[${tierIndex}][quantity]" value="" min="1" step="1" />
                </div>
                <div class="tier-price">
                    <input type="text" name="_group_discount_tiers[${tierIndex}][price]" value="" class="wc_input_price" />
                </div>
                <div class="tier-action">
                    <button type="button" class="button remove-tier">${(group_discount_params && group_discount_params.i18n) ? group_discount_params.i18n.remove : 'Remove'}</button>
                </div>
            </div>
        `;
        
        $tiersWrapper.find('.tier-actions').before(tierTemplate);
    });
    
    // Remove tier
    $tiersWrapper.on('click', '.remove-tier', function(e) {
        e.preventDefault();
        
        $(this).closest('.tier-row').remove();
        
        // Update indices for remaining tiers
        $('.tier-row').each(function(index) {
            $(this).find('input').each(function() {
                var name = $(this).attr('name');
                var newName = name.replace(/\[\d+\]/, '[' + index + ']');
                $(this).attr('name', newName);
            });
        });
    });
    
    // Flush cache and refresh sales count
    $('.flush-cache-button, .diagnostic-refresh').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $spinner = $button.next('.spinner');
        var $message = $button.hasClass('diagnostic-refresh') ? 
                      $button.siblings('.diagnostic-message') : 
                      $button.closest('.group-discount-current-sales').find('.sales-count-message');
        
        var productId = $button.data('product-id');
        
        if (!productId) {
            console.log('Group Discount: No product ID found for refresh button');
            return;
        }
        
        console.log('Group Discount: Refreshing cache for product ' + productId);
        
        // Show spinner
        $spinner.css('visibility', 'visible');
        
        // Set message
        $message.html('Refreshing...').addClass('refreshing');
        
        // Send AJAX request
        $.ajax({
            url: (typeof ajaxurl !== 'undefined') ? ajaxurl : group_discount_params.ajax_url,
            type: 'POST',
            data: {
                action: 'group_discount_flush_cache',
                nonce: group_discount_params.nonce,
                product_id: productId
            },
            success: function(response) {
                // Hide spinner
                $spinner.css('visibility', 'hidden');
                
                if (response.success) {
                    // Update message
                    $message.html(response.data.message).removeClass('refreshing').addClass('success');
                    
                    if ($button.hasClass('diagnostic-refresh')) {
                        // Reload page for diagnostic refresh
                        window.location.reload();
                    } else {
                        // Update sales count display for normal refresh
                        var $salesCount = $button.closest('.group-discount-current-sales').find('p:first strong');
                        $salesCount.text(response.data.total_sold);
                        
                        // Refresh order statistics if debug mode is enabled
                        if ($('.order-statistics').length) {
                            setTimeout(function() {
                                window.location.reload();
                            }, 1000);
                        }
                    }
                } else {
                    $message.html('Error refreshing data').removeClass('refreshing').addClass('error');
                }
                
                // Clear message after 3 seconds
                setTimeout(function() {
                    $message.html('').removeClass('success error');
                }, 3000);
            },
            error: function(xhr, status, error) {
                // Hide spinner
                $spinner.css('visibility', 'hidden');
                
                // Show error message
                $message.html('Error: ' + error).removeClass('refreshing').addClass('error');
                console.log('Group Discount: AJAX error - ' + error);
                
                // Clear message after 3 seconds
                setTimeout(function() {
                    $message.html('').removeClass('error');
                }, 3000);
            }
        });
    });
    
    // Clear all caches
    $('.diagnostic-clear-cache').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $spinner = $button.siblings('.spinner');
        var $message = $button.siblings('.diagnostic-message');
        
        // Show spinner
        $spinner.css('visibility', 'visible');
        
        // Set message
        $message.html('Clearing caches...').addClass('refreshing');
        
        // Send AJAX request
        $.ajax({
            url: (typeof ajaxurl !== 'undefined') ? ajaxurl : group_discount_params.ajax_url,
            type: 'POST',
            data: {
                action: 'group_discount_clear_all_caches',
                nonce: group_discount_params.nonce
            },
            success: function(response) {
                if (response.success) {
                    $message.html('Caches cleared successfully!').removeClass('refreshing').addClass('success');
                    
                    // Reload page after a short delay
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    // Hide spinner
                    $spinner.css('visibility', 'hidden');
                    
                    // Show error message
                    $message.html('Error clearing caches').removeClass('refreshing').addClass('error');
                }
            },
            error: function(xhr, status, error) {
                // Hide spinner
                $spinner.css('visibility', 'hidden');
                
                // Show error message
                $message.html('Error: ' + error).removeClass('refreshing').addClass('error');
                console.log('Group Discount: AJAX error - ' + error);
            }
        });
    });
    
    // Auto-refresh sales count periodically (every 30 seconds)
    if ($('.group-discount-current-sales').length) {
        setInterval(function() {
            // Only refresh if the tab is visible
            if ($('#group_discount_product_data').is(':visible')) {
                $('.flush-cache-button').trigger('click');
            }
        }, 30000);
    }
}); 
