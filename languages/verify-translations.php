<?php
/**
 * Simple script to verify translations are loaded properly
 * Place this file in the plugin's languages directory and access it directly via browser
 */

// Load WordPress environment
define('WP_USE_THEMES', false);
require_once('../../../wp-load.php');

// Function to display the header
function display_header() {
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Group Discount Translation Test</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; }
            .success { color: green; font-weight: bold; }
            .error { color: red; font-weight: bold; }
        </style>
    </head>
    <body>
        <h1>Group Discount Translation Test</h1>';
}

// Function to display the footer
function display_footer() {
    echo '</body></html>';
}

// Show basic environment info
function show_environment_info() {
    echo '<h2>Environment Information</h2>';
    echo '<ul>';
    echo '<li>Current locale: <strong>' . get_locale() . '</strong></li>';
    echo '<li>WordPress version: <strong>' . get_bloginfo('version') . '</strong></li>';
    echo '<li>Is Chinese site: <strong>' . (function_exists('group_discount_detect_chinese_site') && group_discount_detect_chinese_site() ? 'Yes' : 'No') . '</strong></li>';
    echo '</ul>';
}

// Test function to load translations and display results
function test_translations() {
    $strings_to_test = array(
        'Original price',
        'Current price',
        'You save',
        'per unit',
        '%s units already sold',
        'Save %s',
        'Next discount at %s units',
        'Only %1$s more units needed to unlock price: %2$s per unit'
    );
    
    echo '<h2>Simplified Chinese (zh_CN) Test</h2>';
    
    // Try to load Simplified Chinese translations
    $zh_cn_loaded = load_textdomain('group-discount', dirname(__FILE__) . '/group-discount-zh_CN.mo');
    echo '<p>Loading status: ' . ($zh_cn_loaded ? '<span class="success">Success</span>' : '<span class="error">Failed</span>') . '</p>';
    
    echo '<table>';
    echo '<tr><th>Original String</th><th>Translated String</th></tr>';
    
    foreach ($strings_to_test as $string) {
        $translated = __($string, 'group-discount');
        echo '<tr>';
        echo '<td>' . htmlspecialchars($string) . '</td>';
        echo '<td>' . htmlspecialchars($translated) . '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    
    // Unload domain to test the other language
    unload_textdomain('group-discount');
    
    echo '<h2>Traditional Chinese (zh_TW) Test</h2>';
    
    // Try to load Traditional Chinese translations
    $zh_tw_loaded = load_textdomain('group-discount', dirname(__FILE__) . '/group-discount-zh_TW.mo');
    echo '<p>Loading status: ' . ($zh_tw_loaded ? '<span class="success">Success</span>' : '<span class="error">Failed</span>') . '</p>';
    
    echo '<table>';
    echo '<tr><th>Original String</th><th>Translated String</th></tr>';
    
    foreach ($strings_to_test as $string) {
        $translated = __($string, 'group-discount');
        echo '<tr>';
        echo '<td>' . htmlspecialchars($string) . '</td>';
        echo '<td>' . htmlspecialchars($translated) . '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
}

// Main execution
display_header();
show_environment_info();
test_translations();
display_footer(); 