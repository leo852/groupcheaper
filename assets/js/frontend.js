/**
 * Group Discount Frontend JavaScript
 */
jQuery(document).ready(function($) {
    // Only run on product pages
    if (!$('body').hasClass('single-product')) {
        return;
    }
    
    // Store a language cache to prevent flicker between Simplified and Traditional Chinese
    var languageCache = {
        currentLang: '',
        isChinese: false,
        isTraditional: false
    };
    
    // Get product ID from params or try to extract from page
    var productId = 0;
    
    if (typeof group_discount_params !== 'undefined' && group_discount_params.product_id) {
        productId = group_discount_params.product_id;
    } else {
        // Try to extract from the add to cart button
        var addToCartBtn = $('button[name="add-to-cart"], input[name="add-to-cart"]').first();
        if (addToCartBtn.length) {
            productId = parseInt(addToCartBtn.val());
        }
        
        // If still no product ID, try to get it from the body class
        if (!productId) {
            var bodyClasses = $('body').attr('class').split(' ');
            for (var i = 0; i < bodyClasses.length; i++) {
                if (bodyClasses[i].indexOf('postid-') === 0) {
                    productId = parseInt(bodyClasses[i].replace('postid-', ''));
                    break;
                }
            }
        }
    }
    
    // If still no product ID, we can't proceed
    if (!productId) {
        console.log('Group Discount: Could not determine product ID');
        return;
    }
    
    console.log('Group Discount: Using product ID ' + productId);
    
    // Increase refresh interval to 3 minutes (180000ms) instead of 30 seconds
    var refreshInterval = typeof group_discount_params !== 'undefined' && group_discount_params.refresh_interval 
        ? parseInt(group_discount_params.refresh_interval) 
        : 180000; 
    var isRefreshing = false;
    var lastTotalSold = 0; // Store the last known total to detect changes
    
    // Different themes use different selectors for price elements
    var priceSelectors = [
        '.summary .price .woocommerce-Price-amount', 
        '.product p.price .woocommerce-Price-amount',
        '.product span.price .woocommerce-Price-amount',
        '.woocommerce-variation-price .price .woocommerce-Price-amount',
        '.entry-summary .woocommerce-Price-amount',
        '.product-price .woocommerce-Price-amount'
    ];
    
    // Find price elements that actually exist on the page
    function getPriceElements() {
        var elements = $();
        
        for (var i = 0; i < priceSelectors.length; i++) {
            var $el = $(priceSelectors[i]);
            if ($el.length) {
                elements = elements.add($el);
            }
        }
        
        return elements;
    }
    
    // Store original HTML content for all elements to preserve translations
    var originalContent = {};
    
    // Function to store original content of elements
    function storeOriginalContent() {
        // Store original price elements
        var priceElements = getPriceElements();
        priceElements.each(function(index) {
            var key = 'price_' + index;
            originalContent[key] = $(this).html();
        });
        
        // Store original discount label elements
        $('.group-discount-label p').each(function(index) {
            var key = 'discount_label_' + index;
            originalContent[key] = $(this).html();
        });
        
        // Store original next tier elements
        $('.group-discount-next-tier p').each(function(index) {
            var key = 'next_tier_' + index;
            originalContent[key] = $(this).html();
        });
        
        console.log('Group Discount: Stored original content for translation preservation');
    }
    
    // Call immediately to store initial content
    storeOriginalContent();
    
    // Check if the page contains Chinese characters
    function hasChineseCharacters() {
        var pageText = $('body').text();
        return /[\u4e00-\u9fa5]/.test(pageText);
    }
    
    // Detect which Chinese variant is most likely being used
    function detectChineseVariant() {
        console.log('Group Discount: Analyzing page for Chinese variant detection');
        
        // First check the HTML lang attribute - this should be most reliable
        var htmlLang = $('html').attr('lang') || '';
        
        // Log the HTML lang attribute for debugging
        if (htmlLang) {
            console.log('Group Discount: HTML lang attribute found: ' + htmlLang);
        }
        
        // These variations all indicate Traditional Chinese
        if (htmlLang.indexOf('zh-tw') === 0 || htmlLang.indexOf('zh_TW') === 0 || 
            htmlLang.indexOf('zh-hk') === 0 || htmlLang.indexOf('zh_HK') === 0) {
            console.log('Group Discount: Traditional Chinese detected from HTML lang attribute: ' + htmlLang);
            return 'zh_TW'; // Traditional Chinese
        } 
        // These variations all indicate Simplified Chinese
        else if (htmlLang.indexOf('zh-cn') === 0 || htmlLang.indexOf('zh_CN') === 0 || 
                 htmlLang.indexOf('zh-hans') === 0) {
            console.log('Group Discount: Simplified Chinese detected from HTML lang attribute: ' + htmlLang);
            return 'zh_CN'; // Simplified Chinese
        }
        else if (htmlLang.indexOf('zh') === 0) {
            console.log('Group Discount: Generic Chinese detected from HTML lang attribute, analyzing further: ' + htmlLang);
        }
        
        // Check URL patterns which might indicate Traditional Chinese
        var url = window.location.href.toLowerCase();
        if (url.indexOf('/zh-tw/') !== -1 || url.indexOf('/zh_tw/') !== -1 || 
            url.indexOf('/tw/') !== -1 || url.indexOf('/hk/') !== -1 || 
            url.indexOf('.tw/') !== -1 || url.indexOf('.hk/') !== -1) {
            console.log('Group Discount: Traditional Chinese detected from URL pattern: ' + url);
            return 'zh_TW';
        }
        
        // Check for Taiwan or Hong Kong in the domain - this is a strong signal
        var domain = window.location.hostname.toLowerCase();
        if (domain.endsWith('.tw') || domain.endsWith('.hk')) {
            console.log('Group Discount: Traditional Chinese detected from domain: ' + domain);
            return 'zh_TW';
        }
        
        // If no URL or domain indicators, analyze page content
        var pageText = $('body').text();
        
        // More comprehensive set of distinctive characters
        var traditionalChars = ['說', '時', '國', '會', '東', '語', '學', '關', '車', '書', '實', '點', 
                               '萬', '樣', '發', '經', '處', '產', '見', '號', '長', '親', '務', '熱'];
        var simplifiedChars = ['说', '时', '国', '会', '东', '语', '学', '关', '车', '书', '实', '点', 
                              '万', '样', '发', '经', '处', '产', '见', '号', '长', '亲', '务', '热'];
        
        var traditionalCount = 0;
        var simplifiedCount = 0;
        
        // Count occurrences of each set of characters
        for (var i = 0; i < traditionalChars.length; i++) {
            if (pageText.indexOf(traditionalChars[i]) !== -1) {
                traditionalCount++;
            }
        }
        
        for (var i = 0; i < simplifiedChars.length; i++) {
            if (pageText.indexOf(simplifiedChars[i]) !== -1) {
                simplifiedCount++;
            }
        }
        
        console.log('Group Discount: Chinese character analysis - Traditional: ' + traditionalCount + ', Simplified: ' + simplifiedCount);
        
        // If we find ANY traditional characters, prefer Traditional Chinese
        // This is a more aggressive approach to ensure Traditional is properly detected
        if (traditionalCount > 0) {
            console.log('Group Discount: Traditional Chinese detected from content analysis - found ' + traditionalCount + ' traditional characters');
            return 'zh_TW';
        }
        
        // Only default to Simplified if we find simplified characters and no traditional ones
        if (simplifiedCount > 0) {
            console.log('Group Discount: Simplified Chinese detected from content analysis - found ' + simplifiedCount + ' simplified characters');
            return 'zh_CN';
        }
        
        // Default to Simplified Chinese as a last resort
        console.log('Group Discount: No definitive Chinese variant detected, defaulting to Simplified Chinese');
        return 'zh_CN';
    }
    
    // Enhanced function to update numbers in HTML while preserving translations
    function updateNumbersInHTML(originalHTML, totalSold, savings, savingsPercent, data) {
        if (!originalHTML) return originalHTML;
        
        // Price comparison paragraph (Original price → Current price)
        if (originalHTML.match(/[Oo]riginal|原价|原價|初始|원래|元の|최초|начальн|Ursprünglich|original|Başlangıç|初始价格|元の価格|가격|Precio original|Prix original|Originele prijs|Preço original|Prezzo originale/)) {
            // For the price comparison paragraph, we need a very robust approach
            // First, check if the server sent a complete translated version
            if (data && data.price_comparison_text) {
                // Don't try to modify the server text - use it directly instead
                // This prevents duplicated prices and unwanted quote characters
                return data.price_comparison_text;
            }
            
            try {
                // Extract components from the original HTML - more flexible pattern to catch all variations
                var priceRegex = /([$€£¥₹₽¢₩₴₦₱R¤﷼฿원円][\d\s,.]+)/gi;
                var priceMatches = originalHTML.match(priceRegex);
                
                if (priceMatches && priceMatches.length >= 2) {
                    // Replace just the price values while keeping everything else intact
                    var updatedHTML = originalHTML;
                    
                    // Replace the first price match (original price) with formatted_regular_price
                    updatedHTML = updatedHTML.replace(priceMatches[0], data.formatted_regular_price);
                    
                    // Replace the second price match (current price) with formatted_price
                    updatedHTML = updatedHTML.replace(priceMatches[1], data.formatted_price);
                    
                    // If there's a third price (savings amount), replace it too
                    if (priceMatches.length >= 3) {
                        updatedHTML = updatedHTML.replace(priceMatches[2], data.formatted_savings_amount);
                    }
                    
                    console.log('Updated price comparison using regex pattern');
                    return updatedHTML;
                }
                
                // If the regex approach fails, try a more comprehensive approach
                var priceComparisonRegex = /(.*?)[\s:]*([$€£¥₹₽¢₩₴₦₱R¤﷼฿원円\d.,]+)[\s]*([→⟶⇒⇾⟹⥱⥲⥵>\-→])[\s]*(.*?)[\s:]*([$€£¥₹₽¢₩₴₦₱R¤﷼฿원円\d.,]+)/i;
                var priceComparisonMatch = originalHTML.match(priceComparisonRegex);
                
                if (priceComparisonMatch) {
                    // Create a new HTML with the original labels and server-provided prices
                    var originalPriceLabel = priceComparisonMatch[1].trim();
                    var currentPriceLabel = priceComparisonMatch[4].trim();
                    var arrowSymbol = priceComparisonMatch[3].trim();
                    
                    // Always use the formatted prices from the server
                    var newHTML = originalPriceLabel + ': ' + data.formatted_regular_price + 
                                ' ' + arrowSymbol + ' ' + currentPriceLabel + ': ' + data.formatted_price;
                    
                    // Check if there's a savings part in parentheses
                    if (originalHTML.includes('(')) {
                        var savingsRegex = /\((.*?)([$€£¥₹₽¢₩₴₦₱R¤﷼฿원円\d.,]+)(.*?)\)/i;
                        var savingsMatch = originalHTML.match(savingsRegex);
                        
                        if (savingsMatch) {
                            var savingsPrefix = savingsMatch[1].trim();
                            var savingsSuffix = savingsMatch[3].trim();
                            newHTML += ' (' + savingsPrefix + ' ' + data.formatted_savings_amount + ' ' + savingsSuffix + ')';
                        }
                    }
                    
                    console.log('Updated price comparison using comprehensive regex');
                    return newHTML;
                }
            } catch (e) {
                console.log('Error in price comparison regex: ' + e.message);
            }
            
            // Last resort: if all regex approaches fail, just use the server-provided text
            console.log('Using server text as fallback for price comparison');
            return data.price_comparison_text;
        }
        
        // Units sold paragraph - matches various translations of "units already sold"
        // Enhanced regex with more language support
        var unitsSoldRegex = /([\d\s,.]+)[\s]*(units|unités|unidades|unità|Einheiten|単位|個|sztuk|enhet|enheter|eenheden|μονάδες|единиц|unités|birim|unitate|unități|unidad|وحدات|واحد|đơn vị|หน่วย|단위|ünite|件|pcs|个|個|pieces|stuk|kpl)[\s]*(.*sold|.*vendues|.*verkauft|.*vendute|.*vendidos|.*verkocht|.*vendidas|.*sålda|.*solgte|.*myyty|.*vendu|.*satıldı|.*проданы|.*sprzedanych|.*vendute|.*ขายแล้ว|.*판매됨|.*продано|已售出|販売済み|이미 판매됨|ya vendidos)/i;
        
        var unitsSoldMatch = originalHTML.match(unitsSoldRegex);
        if (unitsSoldMatch) {
            // Replace just the number, keep the translation intact
            return originalHTML.replace(/[\d\s,.]+/, totalSold);
        }
        
        // Next discount tier - matches various translations
        var nextDiscountRegex = /(.*?)([\d\s,.]+)[\s]*(units|unités|unidades|unità|Einheiten|単位|個|sztuk|enhet|enheter|eenheden|μονάδες|единиц|unités|birim|unitate|unități|unidad|وحدات|واحد|đơn vị|หน่วย|단위|ünite|件|pcs|个|個|pieces|stuk|kpl)/i;
        var nextDiscountMatch = originalHTML.match(nextDiscountRegex);
        
        if (nextDiscountMatch && originalHTML.match(/[Nn]ext|下一个|下一個|次の|다음|следующ|Nächste|siguiente|Prochain|volgende|próximo|prossimo|nästa|siguiente|Sonraki|下个折扣|次の割引|다음 할인|Siguiente descuento/)) {
            // Special handling for Traditional Chinese to prevent duplication
            if (originalHTML.indexOf('下一個折扣在') !== -1) {
                // For Traditional Chinese, completely replace the text with properly formatted version
                return '下一個折扣在 <strong>' + data.next_tier_quantity + '</strong> 件';
            }
            
            // For other languages, replace just the number, keep the translation intact
            return nextDiscountMatch[1] + data.next_tier_quantity + ' ' + nextDiscountMatch[3];
        }
        
        // Special handling for "Only X more units needed" in Traditional Chinese
        if (originalHTML.indexOf('只需再購買') !== -1 && data.units_needed) {
            return '只需再購買 <strong>' + data.units_needed + '</strong> 件即可解鎖價格：每件 <strong>' + data.next_tier_price + '</strong>';
        }
        
        // Save X% - match various translations of savings percentage
        var savePercentRegex = /([Ss]ave|[Dd]iscount|[Ss]paren|[Ss]paar|[Éé]conomisez|[Aa]horra|[Рр]испестите|[Оо]щадність|节省|節省|[Рр]ескономьте|[Оо]сознайте|[Zz]aoszczędź|[Ss]äästä|[Ss]par|[Bb]espar|[Bb]espaar|[Bb]esparelse|[Tt]asarruf|[Ss]parmål|[Ss]parpengar|[Рр]спеститите|[Тт]заощадження|节省|節約|[Сс]пестяване|[Оо]щадность|[Сс]береження|[Сс]эканомьте|[Рр]ъкономически|저장|저장|节省|節約|节省|Salva|Salvare|Salvar|Sauvegarder|Sparen|Besparen)[\s]*?:?[\s]*?([\d\s,.]+)[\s]*?(%|﹪|％|percent|prozent|procent|pour cent|por ciento|por cento|procentų|procent|процент|процентов|процента|protsenti|százalék|százalékot|próiseint)/i;
        
        var savePercentMatch = originalHTML.match(savePercentRegex);
        if (savePercentMatch) {
            // Replace just the percentage number, keep the labels and symbols
            return originalHTML.replace(/[\d\s,.]+\s*?(%|﹪|％|percent|prozent|procent)/, savingsPercent.toFixed(2) + ' ' + savePercentMatch[3]);
        }
        
        // Units needed for next tier
        var unitsNeededRegex = /(.*?)([\d\s,.]+)(.*)/i;
        if (originalHTML.match(/[Oo]nly|[Jj]ust|[Nn]ur|[Ss]olo|[Ss]eulement|[Aa]ppena|[Nn]ur|[Ss]ólo|[Aa]lleen|[Bb]are|[Кк]ун|[Тт]олько|[Ss]amo|只需|只要|たった|단지|только|Tylko|nur|seulement|sadece/) && 
            originalHTML.match(/[Mm]ore|[Pp]lus|[Mm]ehr|[Pp]iù|[Mm]ás|[Mm]eer|[Ff]lere|[Бб]ільше|[Бб]ольше|[Ww]ięcej|更多|もっと|더|больше|еще|dodatkowo|mehr|plus|daha/) && 
            originalHTML.match(/[Uu]nits|[Uu]nités|[Uu]nidades|[Uu]nità|[Ee]inheiten|[Пп]родукт|[Шш]тук|件|単位|개|единиц|штук|unidad|unités|birim/)) {
            
            var unitsNeededMatch = originalHTML.match(unitsNeededRegex);
            if (unitsNeededMatch && data.units_needed) {
                // Replace just the number, keeping the rest of the translated text
                return unitsNeededMatch[1] + data.units_needed + unitsNeededMatch[3];
            }
        }
        
        // If we couldn't match any specific pattern, return the original
        return originalHTML;
    }
    
    // Auto-refresh prices periodically
    function refreshPrice() {
        // Prevent multiple simultaneous refreshes
        if (isRefreshing) {
            return;
        }
        
        isRefreshing = true;
        
        // If we have a cached language and it's Traditional Chinese, use it immediately
        // This prevents the brief flicker of Simplified Chinese
        if (languageCache.currentLang === 'zh_TW' && languageCache.isTraditional) {
            console.log('Group Discount: Using cached Traditional Chinese language');
            
            // Apply Traditional Chinese labels immediately to prevent flicker
            $('.group-discount-price-comparison.gd-two-row').each(function() {
                var $this = $(this);
                // Keep any existing Traditional Chinese content to prevent flicker
                if ($this.text().indexOf('原價') !== -1 || $this.text().indexOf('現價') !== -1) {
                    console.log('Group Discount: Preserving existing Traditional Chinese content');
                    // Don't modify the content - keep the existing Traditional Chinese
                } else {
                    console.log('Group Discount: No Traditional Chinese content found yet');
                }
            });
        }
        
        // Get the current language from various possible sources (much more comprehensive detection)
        var currentLang = '';
        
        // Special handling for Chinese sites
        if (hasChineseCharacters()) {
            currentLang = detectChineseVariant();
            console.log('Group Discount: Detected Chinese site, using language: ' + currentLang);
            
            // Make sure currentLang is exactly 'zh_CN' or 'zh_TW', not a shortened variant
            if (currentLang === 'zh') {
                currentLang = 'zh_CN'; // Default to Simplified if we just know it's Chinese
                console.log('Group Discount: Normalized Chinese language code to zh_CN');
            } else if (currentLang === 'cn') {
                currentLang = 'zh_CN';
                console.log('Group Discount: Normalized Chinese language code from cn to zh_CN');
            } else if (currentLang === 'tw') {
                currentLang = 'zh_TW';
                console.log('Group Discount: Normalized Chinese language code from tw to zh_TW');
            }
            
            // Just to be extra certain, check the HTML lang attribute format
            var htmlLang = $('html').attr('lang') || '';
            if (htmlLang) {
                if (htmlLang === 'zh-cn' || htmlLang === 'zh-CN' || htmlLang === 'zh_CN') {
                    currentLang = 'zh_CN';
                    console.log('Group Discount: Setting language to zh_CN based on HTML lang attribute');
                } else if (htmlLang === 'zh-tw' || htmlLang === 'zh-TW' || htmlLang === 'zh_TW' ||
                           htmlLang === 'zh-hk' || htmlLang === 'zh-HK' || htmlLang === 'zh_HK') {
                    currentLang = 'zh_TW';
                    console.log('Group Discount: Setting language to zh_TW based on HTML lang attribute');
                }
            }
            
            // Also check URL patterns for Traditional Chinese indicators
            var url = window.location.href.toLowerCase();
            if (url.indexOf('/zh-tw') !== -1 || url.indexOf('/zh_tw') !== -1 || 
                url.indexOf('/tw/') !== -1 || url.indexOf('/hk/') !== -1 || 
                url.indexOf('.tw/') !== -1 || url.indexOf('.hk/') !== -1) {
                currentLang = 'zh_TW';
                console.log('Group Discount: Setting language to zh_TW based on URL pattern: ' + url);
            }
            
            // Check domain for .tw or .hk extensions
            var domain = window.location.hostname.toLowerCase();
            if (domain.endsWith('.tw') || domain.endsWith('.hk')) {
                currentLang = 'zh_TW';
                console.log('Group Discount: Setting language to zh_TW based on domain: ' + domain);
            }
            
            // Update the language cache
            languageCache.currentLang = currentLang;
            languageCache.isChinese = true;
            languageCache.isTraditional = (currentLang === 'zh_TW');
        } else {
            // 1. Try HTML lang attribute (most reliable)
            var htmlLang = $('html').attr('lang') || $('html').attr('xml:lang') || document.documentElement.lang || '';
            if (htmlLang) {
                currentLang = htmlLang.split('-')[0]; // Get base language code
                console.log('Group Discount: Detected language from HTML tag: ' + currentLang);
                
                // Special handling for Chinese in HTML lang attribute
                if (htmlLang === 'zh-cn' || htmlLang === 'zh-CN' || htmlLang === 'zh_CN') {
                    currentLang = 'zh_CN';
                    console.log('Group Discount: Setting language to zh_CN based on HTML lang attribute');
                } else if (htmlLang === 'zh-tw' || htmlLang === 'zh-TW' || htmlLang === 'zh_TW' ||
                           htmlLang === 'zh-hk' || htmlLang === 'zh-HK' || htmlLang === 'zh_HK') {
                    currentLang = 'zh_TW';
                    console.log('Group Discount: Setting language to zh_TW based on HTML lang attribute');
                }
            }
            
            // 2. Try URL pattern detection for language (common in many multilingual setups)
            if (!currentLang || currentLang === 'en') {
                var urlLangMatch = window.location.pathname.match(/^\/(([a-z]{2,3})(-[a-z]{2,3})?)\//i);
                if (urlLangMatch) {
                    currentLang = urlLangMatch[2]; // Use the base language code
                    console.log('Group Discount: Detected language from URL: ' + currentLang);
                }
            }
            
            // 3. Try body classes for common language indicators
            if (!currentLang || currentLang === 'en') {
                var bodyClasses = $('body').attr('class') || '';
                
                // WPML classes
                var wpmlMatch = bodyClasses.match(/\b(?:wpml-lang|lang)-([a-z]{2})\b/i);
                if (wpmlMatch) {
                    currentLang = wpmlMatch[1];
                    console.log('Group Discount: Detected language from WPML body class: ' + currentLang);
                }
                
                // Polylang classes
                if (!currentLang) {
                    var polylangMatch = bodyClasses.match(/\b(?:pll-|lang-pll-|language-)([a-z]{2})\b/i);
                    if (polylangMatch) {
                        currentLang = polylangMatch[1];
                        console.log('Group Discount: Detected language from Polylang body class: ' + currentLang);
                    }
                }
            }
            
            // 4. Try to detect from WP global
            if ((!currentLang || currentLang === 'en') && typeof window.pagenow === 'string') {
                var wpLocaleMatch = window.pagenow.match(/[?&]locale=([a-z]{2})/i);
                if (wpLocaleMatch) {
                    currentLang = wpLocaleMatch[1];
                    console.log('Group Discount: Detected language from WP global: ' + currentLang);
                }
            }
            
            // 5. Check for language-specific strings on the page to determine language
            if (!currentLang || currentLang === 'en') {
                // Try to detect language from common words on the page
                var pageText = $('body').text().toLowerCase();
                
                // Language detection based on common words (very basic but can help)
                var languageIndicators = {
                    'fr': ['panier', 'acheter', 'produit', 'prix'],
                    'es': ['carrito', 'comprar', 'producto', 'precio'],
                    'de': ['warenkorb', 'kaufen', 'produkt', 'preis'],
                    'it': ['carrello', 'acquistare', 'prodotto', 'prezzo'],
                    'pt': ['carrinho', 'comprar', 'produto', 'preço'],
                    'ru': ['корзина', 'купить', 'продукт', 'цена'],
                    'ja': ['カート', '購入', '商品', '価格'],
                    'zh': ['购物车', '购买', '产品', '价格'],
                    'ar': ['سلة', 'شراء', 'منتج', 'سعر'],
                    'nl': ['winkelwagen', 'kopen', 'product', 'prijs']
                };
                
                var bestMatch = '';
                var bestScore = 0;
                
                for (var lang in languageIndicators) {
                    var score = 0;
                    var words = languageIndicators[lang];
                    
                    for (var i = 0; i < words.length; i++) {
                        if (pageText.indexOf(words[i]) !== -1) {
                            score++;
                        }
                    }
                    
                    if (score > bestScore) {
                        bestScore = score;
                        bestMatch = lang;
                    }
                }
                
                if (bestScore >= 2) { // At least 2 matching words to be confident
                    currentLang = bestMatch;
                    console.log('Group Discount: Detected language from content analysis: ' + currentLang);
                }
            }
        }
        
        // Default to 'en' if we still couldn't detect a language
        if (!currentLang) {
            currentLang = 'en';
            console.log('Group Discount: Using default language: en');
        }
        
        console.log('Group Discount: Using language: ' + currentLang + ' for AJAX request');
        
        $.ajax({
            url: group_discount_params.ajax_url,
            type: 'POST',
            data: {
                action: 'group_discount_refresh_price',
                nonce: group_discount_params.nonce,
                product_id: productId,
                last_total: lastTotalSold,
                lang: currentLang
            },
            beforeSend: function() {
                // Only add loading indicator to the discount label
                $('.group-discount-label, .group-discount-next-tier').addClass('refreshing');
            },
            success: function(response) {
                if (!response || !response.success) {
                    console.log('Group Discount: Error refreshing price data');
                    return;
                }
                
                var data = response.data;
                console.log('Group Discount: Received data', data);
                
                // Check if the total sold has changed
                var totalChanged = (lastTotalSold > 0 && data.total_sold !== lastTotalSold);
                lastTotalSold = data.total_sold;
                
                // Update price display
                if (data.tier_price) {
                    // Get all matching price elements
                    var $priceElements = getPriceElements();
                    
                    if ($priceElements.length) {
                        console.log('Group Discount: Found ' + $priceElements.length + ' price elements to update');
                        
                        // Only update the numerical value in the price, not the entire HTML
                        // This preserves the currency symbol and formatting in the user's language
                        $priceElements.each(function(index) {
                            var $this = $(this);
                            var key = 'price_' + index;
                            var originalHTML = originalContent[key] || $this.html();
                            var numericValue = data.tier_price;
                            
                            // Extract numeric part from the current HTML
                            var numericMatch = originalHTML.match(/([\d\s,.]+)/);
                            if (numericMatch) {
                                // Replace only the numeric part, preserving everything else
                                var newHTML = originalHTML.replace(
                                    numericMatch[0], 
                                    numericValue.toString().replace('.', data.decimal_separator)
                                );
                                $this.html(newHTML);
                            } else {
                                // Fallback to using the formatted price from the server
                                $this.html(data.formatted_price);
                            }
                        });
                    } else {
                        console.log('Group Discount: No price elements found to update');
                    }
                    
                    // Only update the discount label if it exists - preserve translations
                    if ($('.group-discount-label').length) {
                        var savings = data.regular_price - data.tier_price;
                        var savingsPercent = (savings / data.regular_price) * 100;
                        
                        // Special handling for Traditional Chinese - immediate application
                        var isTraditionalChinese = data.language === 'zh_TW';
                        
                        // Process each paragraph in the discount label separately
                        $('.group-discount-label p').each(function(index) {
                            var $this = $(this);
                            var key = 'discount_label_' + index;
                            var originalHTML = originalContent[key] || $this.html();
                            
                            // Special handling for price comparison paragraphs
                            if ($this.hasClass('group-discount-price-comparison')) {
                                // For price comparison, use the server-provided text directly
                                // This prevents issues with duplicated prices and unwanted quotes
                                if (data.price_comparison_text) {
                                    console.log('Using server-provided price comparison text');
                                    
                                    // Add the two-row class if not already present
                                    if (!$this.hasClass('gd-two-row')) {
                                        $this.addClass('gd-two-row');
                                    }
                                    
                                    // Special handling for Traditional Chinese - directly use the server text without modifications
                                    if (data.language === 'zh_TW') {
                                        console.log('Traditional Chinese detected, using server text without modifications');
                                        
                                        // Direct replacement for Traditional Chinese texts to prevent flickering
                                        var hasTraditionalText = $this.text().indexOf('原價') !== -1 || $this.text().indexOf('現價') !== -1;
                                        
                                        // Only update if the current content is not already in Traditional Chinese
                                        // This prevents the flicker between reloads
                                        if (!hasTraditionalText) {
                                            $this.html(data.price_comparison_text);
                                            console.log('Applied Traditional Chinese text');
                                        } else {
                                            console.log('Skipping update - already contains Traditional Chinese');
                                        }
                                        
                                        // Add loaded class to ensure visibility
                                        $this.removeClass('gd-not-loaded').addClass('gd-loaded');
                                        
                                        // Cache this for future use
                                        languageCache.currentLang = 'zh_TW';
                                        languageCache.isChinese = true;
                                        languageCache.isTraditional = true;
                                    } else {
                                        $this.html(data.price_comparison_text);
                                    }
                                    
                                    // Ensure classes are preserved
                                    $this.find('.original-price').addClass('original-price');
                                    $this.find('.gd-original-price-row').addClass('gd-original-price-row');
                                    $this.find('.gd-current-price-row').addClass('gd-current-price-row');
                                    $this.find('.savings-text').addClass('savings-text');
                                } else {
                                    // Log warning if the server didn't provide the text
                                    console.log('Warning: Server did not provide price comparison text');
                                    
                                    // Fallback to using individual translations (when available)
                                    if (data.original_price_label && data.current_price_label) {
                                        var newHTML = 
                                            '<span class="gd-original-price-row">' + data.original_price_label + ': ' + 
                                            '<span class="original-price">' + data.formatted_regular_price + '</span></span>' +
                                            '<span class="gd-current-price-row">' + data.current_price_label + ': ' + 
                                            '<strong>' + data.formatted_price + '</strong> ' +
                                            '<span class="savings-text">(' + data.you_save_text + ' ' + data.formatted_savings_amount + ' ' + data.per_unit_text + ')</span></span>';
                                        
                                        $this.html(newHTML).addClass('gd-two-row');
                                    } else {
                                        // Very last resort - update the existing HTML with numbers only
                                        var updatedHTML = updateNumbersInHTML(
                                            originalHTML,
                                            data.total_sold,
                                            data.formatted_savings_amount,
                                            savingsPercent,
                                            data
                                        );
                                        $this.html(updatedHTML);
                                    }
                                }
                            } else {
                                // For other paragraphs (like units sold), update normally
                                var updatedHTML = updateNumbersInHTML(
                                    originalHTML, 
                                    data.total_sold,
                                    data.formatted_savings_amount, 
                                    savingsPercent,
                                    data
                                );
                                $this.html(updatedHTML);
                            }
                        });
                        
                        // After all updates, re-apply any CSS classes that might have been lost
                        $('.group-discount-label .original-price').addClass('original-price');
                        $('.group-discount-label .price-arrow').addClass('price-arrow');
                        $('.group-discount-label .savings-text').addClass('savings-text');
                    } else if (totalChanged && data.significant_change) {
                        // If the label doesn't exist but the total changed significantly, reload to get it
                        console.log('Group Discount: Total changed but no discount label exists, reloading page');
                        window.location.reload();
                        return;
                    }
                    
                    // Update next tier information if it exists
                    if ($('.group-discount-next-tier').length && data.next_tier) {
                        $('.group-discount-next-tier p').each(function(index) {
                            var $this = $(this);
                            var key = 'next_tier_' + index;
                            var originalHTML = originalContent[key] || $this.html();
                            
                            // Special handling for Traditional Chinese next tier elements
                            if (data.language === 'zh_TW') {
                                // Check if this is the "Next discount at X units" paragraph
                                if ($this.hasClass('group-discount-next-tier-info')) {
                                    // Direct replacement for Traditional Chinese to prevent duplication
                                    $this.html('下一個折扣在 <strong>' + data.next_tier_quantity + '</strong> 件 ' + 
                                              '<span class="group-discount-savings-badge group-discount-next-savings-badge">' +
                                              '節省 <strong>' + parseFloat(data.next_tier_savings_percent).toFixed(2) + '%</strong></span>');
                                }
                                // Check if this is the "Only X more units needed" paragraph
                                else if ($this.hasClass('group-discount-next-tier-price')) {
                                    $this.html('只需再購買 <strong>' + data.units_needed + '</strong> 件即可解鎖價格：每件 <strong>' + 
                                              data.next_tier_price_formatted + '</strong>');
                                }
                            } else {
                                // For other languages, update normally
                                var updatedHTML = updateNumbersInHTML(
                                    originalHTML,
                                    data.total_sold,
                                    data.formatted_savings_amount,
                                    savingsPercent,
                                    data
                                );
                                $this.html(updatedHTML);
                            }
                        });
                    }
                    
                    // Only reload the page if total changed significantly AND it's been requested by the server
                    if (data.force_reload) {
                        console.log('Group Discount: Server requested page reload');
                        window.location.reload();
                        return;
                    }
                    
                    // Store the updated content as the new "original" to preserve the translation
                    storeOriginalContent();
                }
                
                // Remove loading indicators
                $('.group-discount-label, .group-discount-next-tier').removeClass('refreshing');
            },
            error: function(xhr, status, error) {
                console.log('Group Discount: AJAX error when refreshing price: ' + error);
                $('.group-discount-label, .group-discount-next-tier').removeClass('refreshing');
            },
            complete: function() {
                isRefreshing = false;
                
                // Schedule the next refresh
                setTimeout(refreshPrice, refreshInterval);
            }
        });
    }
    
    // Initial refresh after 3 seconds (let the page load first)
    setTimeout(refreshPrice, 3000);
    
    // Force refresh on all cart changes
    $(document.body).on('added_to_cart removed_from_cart updated_cart_totals updated_checkout', function(e) {
        console.log('Group Discount: Cart/checkout event detected: ' + e.type);
        // Refresh immediately
        setTimeout(function() {
            refreshPrice();
        }, 500);
    });
    
    // Also refresh when quantity is changed
    $('.quantity input.qty').on('change', function() {
        console.log('Group Discount: Quantity change detected');
        setTimeout(function() {
            refreshPrice();
        }, 500);
    });
    
    // Also listen for WooCommerce events
    $(document.body).on('wc_fragments_refreshed wc_fragments_loaded', function(e) {
        console.log('Group Discount: WooCommerce fragment event detected: ' + e.type);
        setTimeout(function() {
            refreshPrice();
        }, 500);
    });
    
    // Refresh on page focus (user returns to tab)
    $(window).on('focus', function() {
        console.log('Group Discount: Window focus detected, refreshing price');
        setTimeout(function() {
            refreshPrice();
        }, 500);
    });
    
    // Add anti-flicker CSS for Traditional Chinese pages
    // This will hide the price comparison section until it's properly loaded
    function addAntiFlickerCSS() {
        var htmlLang = $('html').attr('lang') || '';
        var isTraditionalChinese = htmlLang.indexOf('zh-tw') === 0 || 
                                 htmlLang.indexOf('zh_TW') === 0 || 
                                 htmlLang.indexOf('zh-hk') === 0 || 
                                 htmlLang.indexOf('zh_HK') === 0;
                                 
        var url = window.location.href.toLowerCase();
        var hasTWinURL = url.indexOf('/zh-tw') !== -1 || 
                        url.indexOf('/zh_tw') !== -1 || 
                        url.indexOf('/tw/') !== -1 || 
                        url.indexOf('/hk/') !== -1;
                        
        if (isTraditionalChinese || hasTWinURL) {
            console.log('Group Discount: Adding anti-flicker CSS for Traditional Chinese');
            $('<style id="gd-anti-flicker">' +
              '.group-discount-price-comparison:not(.gd-loaded) { visibility: hidden; }' +
              '</style>').appendTo('head');
            
            // Mark existing elements as not loaded
            $('.group-discount-price-comparison').addClass('gd-not-loaded');
            
            // After a short delay, forcibly set Traditional Chinese content
            setTimeout(function() {
                $('.group-discount-price-comparison').each(function() {
                    var $this = $(this);
                    var hasTraditionalText = $this.text().indexOf('原價') !== -1 || $this.text().indexOf('現價') !== -1;
                    
                    if (!hasTraditionalText) {
                        // Directly set Traditional Chinese labels
                        var regularPriceText = $this.find('.original-price').text();
                        var currentPriceText = $this.find('strong').text();
                        var savingsText = $this.find('.savings-text').text() || '';
                        
                        var newHtml = '<span class="gd-original-price-row">原價: <span class="original-price">' + 
                                    regularPriceText + '</span></span>' +
                                    '<span class="gd-current-price-row">現價: <strong>' + 
                                    currentPriceText + '</strong> <span class="savings-text">(每件節省 ' + 
                                    savingsText.replace(/[\(\)]/g, '').replace(/You save|per unit|you save/gi, '') + 
                                    ')</span></span>';
                                    
                        $this.html(newHtml);
                        console.log('Group Discount: Forced Traditional Chinese display');
                    }
                    
                    // Mark as loaded so it becomes visible
                    $this.removeClass('gd-not-loaded').addClass('gd-loaded');
                });
            }, 500);
        }
    }
    
    // Call anti-flicker protection
    addAntiFlickerCSS();
}); 