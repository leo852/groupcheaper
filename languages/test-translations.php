<?php
/**
 * Test file to verify translations are working properly
 * Place this file in the plugin directory and access it directly to test
 */

// Set up WordPress environment
define('WP_USE_THEMES', false);
require_once('../../../wp-load.php');

// Make sure the plugin is defined
if (!defined('GD_PLUGIN_PATH')) {
    echo "Plugin not loaded. Please make sure Group Discount plugin is activated.<br>";
    exit;
}

// Define test strings to translate
$test_strings = array(
    'Original price',
    'Current price',
    'You save',
    'per unit',
    '%s units already sold',
    'Save %s',
    'Next discount at %s units',
    'Only %1$s more units needed to unlock price: %2$s per unit'
);

// Try to detect if this is a Chinese site
function is_chinese_site() {
    $locale = get_locale();
    $is_chinese = (strpos($locale, 'zh_') === 0);
    
    echo "<p>Current locale: {$locale}</p>";
    echo "<p>Is Chinese locale: " . ($is_chinese ? 'Yes' : 'No') . "</p>";
    
    // Test Chinese character detection
    if (function_exists('group_discount_detect_chinese_site')) {
        $is_chinese_site = group_discount_detect_chinese_site();
        echo "<p>group_discount_detect_chinese_site(): " . ($is_chinese_site ? 'Yes' : 'No') . "</p>";
    }
    
    return $is_chinese;
}

// Function to attempt to load translations
function try_load_translations() {
    $domain = 'group-discount';
    $locale = get_locale();
    
    echo "<h3>Attempting to load translations for '{$domain}' in locale '{$locale}'</h3>";
    
    // WordPress standard directories
    $wordpress_mo = WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale . '.mo';
    echo "<p>Checking WP languages dir: " . $wordpress_mo . " - " . (file_exists($wordpress_mo) ? 'File exists' : 'File not found') . "</p>";
    
    // Plugin directory
    $plugin_mo = GD_PLUGIN_PATH . 'languages/' . $domain . '-' . $locale . '.mo';
    echo "<p>Checking plugin languages dir: " . $plugin_mo . " - " . (file_exists($plugin_mo) ? 'File exists' : 'File not found') . "</p>";
    
    // Try to load translations
    if (file_exists($plugin_mo)) {
        $result = load_textdomain($domain, $plugin_mo);
        echo "<p>Loading from plugin directory: " . ($result ? 'Success' : 'Failed') . "</p>";
    } else if (file_exists($wordpress_mo)) {
        $result = load_textdomain($domain, $wordpress_mo);
        echo "<p>Loading from WordPress directory: " . ($result ? 'Success' : 'Failed') . "</p>";
    }
    
    // Also try the WordPress standard method
    $result = load_plugin_textdomain($domain, false, basename(GD_PLUGIN_PATH) . '/languages');
    echo "<p>Loading with load_plugin_textdomain: " . ($result ? 'Success' : 'Failed') . "</p>";
    
    // Direct method for Chinese sites
    if (is_chinese_site()) {
        $zh_variant = (strpos($locale, 'zh_TW') === 0) ? 'zh_TW' : 'zh_CN';
        $zh_mo = GD_PLUGIN_PATH . 'languages/' . $domain . '-' . $zh_variant . '.mo';
        
        if (file_exists($zh_mo)) {
            $result = load_textdomain($domain, $zh_mo);
            echo "<p>Directly loading Chinese translation ({$zh_variant}): " . ($result ? 'Success' : 'Failed') . "</p>";
        }
    }
}

// Main output
echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Group Discount Translation Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .success { background-color: #dff0d8; }
        .failure { background-color: #f2dede; }
    </style>
</head>
<body>
    <h1>Group Discount Translation Test</h1>
    
    <h2>WordPress Environment Information</h2>
    <p>WordPress Version: " . get_bloginfo('version') . "</p>
    <p>Site Language: " . get_bloginfo('language') . "</p>
    <p>Current Locale: " . get_locale() . "</p>";

// Try to load translations
try_load_translations();

// Check if a specific locale was requested
if (isset($_GET['locale'])) {
    $requested_locale = sanitize_text_field($_GET['locale']);
    echo "<h3>Testing with forced locale: {$requested_locale}</h3>";
    
    // Try to switch to the requested locale
    add_filter('locale', function() use ($requested_locale) {
        return $requested_locale;
    });
    
    // Reload translations with the new locale
    try_load_translations();
}

// Test translations
echo "<h2>Translation Test Results</h2>
<table>
    <tr>
        <th>Original String</th>
        <th>Translated String</th>
        <th>Status</th>
    </tr>";

foreach ($test_strings as $string) {
    $translated = __($string, 'group-discount');
    $is_different = ($translated !== $string);
    
    echo "<tr class='" . ($is_different ? 'success' : 'failure') . "'>
        <td>" . htmlspecialchars($string) . "</td>
        <td>" . htmlspecialchars($translated) . "</td>
        <td>" . ($is_different ? 'Translated' : 'Not Translated') . "</td>
    </tr>";
}

echo "</table>

<h2>Available Translation Files</h2>";

// List all available translation files
$files = glob(GD_PLUGIN_PATH . 'languages/*.{mo,po}', GLOB_BRACE);
if (!empty($files)) {
    echo "<ul>";
    foreach ($files as $file) {
        echo "<li>" . basename($file) . " (" . size_format(filesize($file)) . ")</li>";
    }
    echo "</ul>";
} else {
    echo "<p>No translation files found in plugin directory.</p>";
}

// Show test links for different locales
echo "<h2>Test With Different Locales</h2>
<ul>
    <li><a href='?locale=zh_CN'>Test with zh_CN (Simplified Chinese)</a></li>
    <li><a href='?locale=zh_TW'>Test with zh_TW (Traditional Chinese)</a></li>
    <li><a href='?locale=en_US'>Test with en_US (English)</a></li>
    <li><a href='?locale=fr_FR'>Test with fr_FR (French)</a></li>
</ul>

</body>
</html>"; 