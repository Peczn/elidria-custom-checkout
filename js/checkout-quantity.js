jQuery(function($) {
    'use strict';

    // Sistema di validazione e sanitizzazione errori lato client
    const ErrorHandler = {
        // Whitelist di tag e attributi permessi per i messaggi di errore
        allowedTags: {
            'strong': [],
            'em': [],
            'span': ['class'],
            'br': []
        },

        // Sanitizza il contenuto HTML
        sanitizeHTML(html) {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const sanitizedContent = this.sanitizeNode(doc.body);
            return sanitizedContent;
        },

        // Sanitizza ricorsivamente i nodi del DOM
        sanitizeNode(node) {
            if (node.nodeType === Node.TEXT_NODE) {
                return node.textContent;
            }

            if (node.nodeType !== Node.ELEMENT_NODE) {
                return '';
            }

            const tagName = node.tagName.toLowerCase();
            if (!this.allowedTags.hasOwnProperty(tagName)) {
                return node.textContent;
            }

            const allowedAttributes = this.allowedTags[tagName];
            const sanitizedNode = document.createElement(tagName);

            // Copia solo gli attributi permessi
            for (const attr of node.attributes) {
                if (allowedAttributes.includes(attr.name)) {
                    sanitizedNode.setAttribute(attr.name, attr.value);
                }
            }

            // Sanitizza ricorsivamente i nodi figli
            for (const child of node.childNodes) {
                const sanitizedChild = this.sanitizeNode(child);
                if (sanitizedChild) {
                    if (typeof sanitizedChild === 'string') {
                        sanitizedNode.appendChild(document.createTextNode(sanitizedChild));
                    } else {
                        sanitizedNode.appendChild(sanitizedChild);
                    }
                }
            }

            return sanitizedNode;
        },

        // Aggiungiamo una funzione per determinare il tipo di messaggio
        getMessageType(message) {
            
            if (typeof error === 'object' && error.code) {
                switch(error.code) {
                    case 'insufficient_stock':
                    case 'stock_reserved':
                        return 'warning';
                    case 'system_error':
                    case 'invalid_input':
                        return 'error';
                    case 'rate_limit_exceeded':
                        return 'info';
                    default:
                        return 'error';
                }
            }
        
            // Mantieni la logica esistente per i messaggi semplici
            const lowerMessage = (typeof error === 'string' ? error : error.message || '').toLowerCase();

            if (lowerMessage.includes('error') || 
                lowerMessage.includes('errore') || 
                lowerMessage.includes('impossibile') ||
                lowerMessage.includes('non valido') ||
                lowerMessage.includes('fallito')) {
                return 'error';
            }
            if (lowerMessage.includes('success') || 
                lowerMessage.includes('completato') || 
                lowerMessage.includes('aggiunto') ||
                lowerMessage.includes('applicato')) {
                return 'success';
            }
            if (lowerMessage.includes('info') || 
                lowerMessage.includes('nota') || 
                lowerMessage.includes('attendi')) {
                return 'info';
            }
            // Default a error per sicurezza
            return 'error';
        },

        // Validazione dei messaggi di errore
        validateErrorMessage(message, type = null) {
            if (!message || typeof message !== 'string') {
                return {
                    text: 'Si è verificato un errore.',
                    type: 'error'
                };
            }
    
            // Limita la lunghezza del messaggio
            if (message.length > 500) {
                message = message.substring(0, 500) + '...';
            }
    
            return {
                text: message,
                type: type || this.getMessageType(message)
            };
        }
    };
    
    // Stato di loading e refresh
    let isUpdating = false;
    let isRefreshing = false;
    let updateTimeout = null;
    const DEBOUNCE_DELAY = 500; // ms

    // Aggiungi una coda per le richieste shipping
    let shippingUpdateQueue = Promise.resolve();
    let lastShippingUpdate = 0;
    const MIN_UPDATE_INTERVAL = 500; // ms
    const MAX_RETRIES = 3;        // Numero massimo di tentativi per le chiamate AJAX
    const RETRY_DELAY = 1000; 

    /**
     * Funzione per gestire i retry delle chiamate AJAX
     * Implementa exponential backoff
     */
    async function retryableAjax(options, retries = MAX_RETRIES) {
        try {
            return await $.ajax(options);
        } catch (error) {
            if (retries > 0 && error.status !== 429) { // Non ritentare per rate limit
                await new Promise(resolve => setTimeout(resolve, RETRY_DELAY));
                return retryableAjax(options, retries - 1);
            }
            throw error;
        }
    }

    // Intercetta tutte le richieste di update_checkout e aggiungi il token CSRF
    $(document.body).on('update_checkout', function(event, args) {
        if (typeof args === 'undefined') {
            args = {};
        }
        if (typeof args.data === 'undefined') {
            args.data = {};
        }
        args.data.csrf_token = checkoutAjax.csrf_token;
    });

    // Funzione di validazione quantità migliorata
    function validateQuantity(value, productData = {}) {
        const quantity = parseInt(value, 10);
        const {
            maxQuantity = 99,
            minQuantity = 1,
            stockQuantity = null
        } = productData;

        // Validazione numero
        if (isNaN(quantity)) {
            return {
                isValid: false,
                value: minQuantity,
                error: 'La quantità deve essere un numero',
                code: 'invalid_input' 
            };
        }
        
        if (quantity < minQuantity) {
            return {
                isValid: false,
                value: minQuantity,
                error: `La quantità minima è ${minQuantity}`,
                code: 'below_min'
            };
        }
        
        // Solo se maxQuantity non è -1 controlliamo il limite massimo
        if (maxQuantity !== -1 && quantity > maxQuantity) {
            return {
                isValid: false,
                value: maxQuantity,
                error: `La quantità massima è ${maxQuantity}`,
                code: 'exceeds_max'
            };
        }
        
        // Se c'è un limite di stock, ha precedenza su maxQuantity
        if (stockQuantity !== null && quantity > stockQuantity) {
            return {
                isValid: false,
                value: stockQuantity,
                error: `Disponibilità massima: ${stockQuantity} unità`,
                code: 'insufficient_stock'
            };
        }
        
        return {
            isValid: true,
            value: quantity,
            error: null,
            code: null
        };
    }

        // Gestione click pulsante rimozione
        $(document).on('click', '.remove-item', function(e) {
            e.preventDefault();
            if (isUpdating) return;
            
            const cartKey = $(this).data('cart-key');
            if (!cartKey) return;
            
            // Chiamiamo updateQuantity con quantità 0 per rimuovere l'item
            updateQuantity(cartKey, 0);
        });
    
        // Gestione click pulsanti quantità
        $(document).on('click', '.checkout-quantity-controls .quantity-btn', function(e) {
            e.preventDefault();
            if (isUpdating) return;
            
            const $input = $(this).siblings('.quantity-input');
            const currentVal = parseInt($input.val(), 10);
            const $controls = $(this).closest('.checkout-quantity-controls');
            
            // Recupera i limiti dal data attribute
            const productData = {
                maxQuantity: parseInt($controls.data('max-quantity')) || 99,
                minQuantity: parseInt($controls.data('min-quantity') || 1, 10),
                stockQuantity: parseInt($controls.data('stock-quantity'), 10) || null
            };
            
            if ($(this).hasClass('minus')) {
                if (currentVal > productData.minQuantity) {
                    $input.val(currentVal - 1).trigger('change');
                }
            } else {
                const validation = validateQuantity(currentVal + 1, productData);
                if (validation.isValid) {
                    $input.val(currentVal + 1).trigger('change');
                }
            }
        });
    
    // Gestione cambiamento quantità
    $(document).on('change', '.quantity-input', function(e) {
        e.preventDefault();
        if (isUpdating) return;
        
        const $input = $(this);
        const currentValue = $input.val();
        const $controls = $input.closest('.checkout-quantity-controls');
        const cartKey = $controls.data('cart-key');
        
        // Salva l'ultimo valore valido
        $input.data('last-valid-value', $input.val());
        
        // Recupera i limiti dal data attribute
        const productData = {
            maxQuantity: parseInt($controls.data('max-quantity')) || 99,
            minQuantity: parseInt($controls.data('min-quantity') || 1, 10),
            stockQuantity: parseInt($controls.data('stock-quantity'), 10) || null
        };
        
        const validation = validateQuantity(currentValue, productData);
        if (!validation.isValid) {
            $input.val(validation.value);
            showError(validation.error, 'error');  
            return;
        }
        
        clearTimeout(updateTimeout);
        updateTimeout = setTimeout(() => {
            updateQuantity(cartKey, validation.value);
        }, DEBOUNCE_DELAY);
    });

    /* function updateQuantity(cartKey, quantity) {
        if (isUpdating) return;
        
        isUpdating = true;
        $('.custom-order-review').block({
            message: '<div class="loading-spinner"></div>',
            overlayCSS: {
                background: '#fff',
                opacity: 0.6
            }
        });
    
        $.ajax({
            url: checkoutAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'update_checkout_quantity',
                nonce: checkoutAjax.nonce,
                csrf_token: checkoutAjax.csrf_token,
                cart_key: cartKey,
                quantity: quantity
            },
            success: function(response) {
                if (response.success) {
                    if (response.new_csrf_token) {
                        checkoutAjax.csrf_token = response.new_csrf_token;
                    }
                    if (quantity === 0) {
                        $(`[data-cart-key="${cartKey}"]`).closest('.cart-item').slideUp(300, function() {
                            $(this).remove();
                            $(document.body).trigger('update_checkout');
                        });
                    } else {
                        $(document.body).trigger('update_checkout');
                    }
                } else {
                    const errorMessage = response.data?.error || 'Si è verificato un errore durante l\'aggiornamento.';
                    const errorCode = response.data?.error_code;
                    showError(errorMessage, 'error');
                    
                    // Gestione errori in base al codice
                    if (response.data?.valid_quantity !== undefined) {
                        const $input = $(`[data-cart-key="${cartKey}"]`).find('.quantity-input');
                        
                        switch(errorCode) {
                            case 'exceeds_max':
                            case 'insufficient_stock':
                            case 'stock_reserved':
                            case 'below_min':
                                $input.val(response.data.valid_quantity);
                                break;
                                
                            case 'system_error':
                                $input.val($input.data('last-valid-value') || 1);
                                break;
                                
                            default:
                                $input.val(response.data.valid_quantity);
                        }
                    } else {
                        const $input = $(`[data-cart-key="${cartKey}"]`).find('.quantity-input');
                        $input.val($input.data('last-valid-value') || 1);
                    }
                }
            },
            error: function(xhr, status, error) {
                // Gestione specifica per rate limiting
                if (xhr.status === 429) {
                    const retryAfter = xhr.getResponseHeader('Retry-After');
                    const resetTime = xhr.getResponseHeader('X-RateLimit-Reset');
                    const waitTime = retryAfter ? parseInt(retryAfter) : 5;
                    
                    showError(`Troppe richieste. Riprova tra ${waitTime} secondi.`, 'info');  

                    // Ripristina il valore precedente
                    const $input = $(`[data-cart-key="${cartKey}"]`).find('.quantity-input');
                    $input.val($input.data('last-valid-value') || 1);
                } else {
                    console.error('Update error:', {xhr, status, error});
                    showError('Errore durante l\'aggiornamento. Ricarica la pagina.', 'error');  
                    
                    const $input = $(`[data-cart-key="${cartKey}"]`).find('.quantity-input');
                    $input.val($input.data('last-valid-value') || 1);
                }
            },
            complete: function() {
                $('.custom-order-review').unblock();
                isUpdating = false;
            }
        });
    } */

    async function updateQuantity(cartKey, quantity) {
        if (isUpdating) return;
        
        isUpdating = true;
        $('.custom-order-review').block({
            message: '<div class="loading-spinner"></div>',
            overlayCSS: {
                background: '#fff',
                opacity: 0.6
            }
        });

        try {
            const response = await retryableAjax({    // Usa retryableAjax invece di $.ajax
                url: checkoutAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'update_checkout_quantity',
                    nonce: checkoutAjax.nonce,
                    csrf_token: checkoutAjax.csrf_token,
                    cart_key: cartKey,
                    quantity: quantity
                }
            });

            if (response.success) {
                if (response.new_csrf_token) {
                    checkoutAjax.csrf_token = response.new_csrf_token;
                }
                
                if (quantity === 0) {
                    $(`[data-cart-key="${cartKey}"]`).closest('.cart-item')
                        .slideUp(300, function() {
                            $(this).remove();
                            $(document.body).trigger('update_checkout');
                        });
                } else {
                    $(document.body).trigger('update_checkout');
                }
            } else {
                const errorMessage = response.data?.error || 'Si è verificato un errore durante l\'aggiornamento.';
                const errorCode = response.data?.error_code;
                
                handleUpdateError(response, cartKey);    // Nuova funzione di gestione errori
            }
        } catch (error) {
            handleAjaxError(error, cartKey);    // Nuova funzione di gestione errori AJAX
        } finally {
            $('.custom-order-review').unblock();
            isUpdating = false;
        }
    }

    /**
     * Gestisce gli errori specifici dell'aggiornamento quantità
     */
    function handleUpdateError(response, cartKey) {
        const $input = $(`[data-cart-key="${cartKey}"]`).find('.quantity-input');
        const errorMessage = response.data?.error || 'Si è verificato un errore durante l\'aggiornamento.';
        const errorCode = response.data?.error_code;

        showError(errorMessage, 'error');
        
        if (response.data?.valid_quantity !== undefined) {
            switch(errorCode) {
                case 'insufficient_stock':
                case 'exceeds_max':
                case 'stock_reserved':
                case 'below_min':
                    $input.val(response.data.valid_quantity);
                    updateQuantityLimits($input.closest('.checkout-quantity-controls'), response.data);
                    break;
                case 'system_error':
                default:
                    $input.val($input.data('last-valid-value') || 1);
                    break;
            }
        } else {
            $input.val($input.data('last-valid-value') || 1);
        }
    }

    /**
     * Gestisce gli errori AJAX generici
     */
    function handleAjaxError(error, cartKey) {
        const $input = $(`[data-cart-key="${cartKey}"]`).find('.quantity-input');
        
        if (error.status === 429) {
            const retryAfter = error.getResponseHeader('Retry-After') || 60;
            showError(`Troppe richieste. Riprova tra ${retryAfter} secondi.`, 'info');
        } else {
            console.error('Update error:', error);
            showError('Errore durante l\'aggiornamento. Ricarica la pagina.', 'error');
        }
        
        $input.val($input.data('last-valid-value') || 1);
    }

    /**
     * Aggiorna i limiti della quantità nell'UI
     */
    function updateQuantityLimits($controls, data) {
        if (data.stock_quantity !== undefined) {
            $controls.attr('data-stock-quantity', data.stock_quantity);
        }
        if (data.max_quantity !== undefined) {
            $controls.attr('data-max-quantity', data.max_quantity);
        }
        if (data.min_quantity !== undefined) {
            $controls.attr('data-min-quantity', data.min_quantity);
        }
    }

    // Funzione migliorata per mostrare errori in modo sicuro
    function showError(message, type = null) {
        // Rimuovi eventuali messaggi esistenti
        $('.woocommerce-error, .woocommerce-message, .woocommerce-info').remove();
        
        // Valida il messaggio e ottieni il tipo
        const validatedMessage = ErrorHandler.validateErrorMessage(message, type);
        
        // Sanitizza il contenuto HTML
        const sanitizedContent = ErrorHandler.sanitizeHTML(validatedMessage.text);
        
        // Crea il contenitore dell'errore
        const errorContainer = document.createElement('div');
        
        // Applica le classi appropriate in base al tipo
        switch (validatedMessage.type) {
            case 'success':
                errorContainer.className = 'woocommerce-message';
                break;
            case 'info':
                errorContainer.className = 'woocommerce-info';
                break;
            case 'error':
            default:
                errorContainer.className = 'woocommerce-error';
                break;
        }
        
        errorContainer.setAttribute('role', 'alert');
        
        // Crea la lista per il contenuto
        const contentList = document.createElement('ul');
        const contentItem = document.createElement('li');
        
        // Aggiungi il contenuto sanitizzato
        if (sanitizedContent instanceof Node) {
            contentItem.appendChild(sanitizedContent);
        } else {
            contentItem.textContent = sanitizedContent;
        }
        
        contentList.appendChild(contentItem);
        errorContainer.appendChild(contentList);
        
        // Inserisci l'errore nel DOM in modo sicuro
        const orderReview = document.querySelector('.custom-order-review');
        if (orderReview) {
            orderReview.insertBefore(errorContainer, orderReview.firstChild);
            
            // Gestione scroll
            const errorTop = errorContainer.getBoundingClientRect().top + window.pageYOffset;
            const windowTop = window.pageYOffset;
            const windowBottom = windowTop + window.innerHeight;
            
            if (errorTop < windowTop || errorTop > windowBottom) {
                window.scrollTo({
                    top: Math.max(0, errorTop - 100),
                    behavior: 'smooth'
                });
            }
        }
    }

    // Gestione coupon
    $(document).on('click', '.apply-coupon', function(e) {
        e.preventDefault();
        if (isUpdating) return;
        applyCoupon();
    });

    $(document).on('keypress', '#custom_coupon_code', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            if (!isUpdating) applyCoupon();
        }
    });

    function applyCoupon() {
        var $wrapper = $('.custom-coupon-form');
        var $couponCode = $('#custom_coupon_code');
        var couponCode = $couponCode.val().trim();

        if (!couponCode) {
            showError('Inserisci un codice promozionale', 'info');
            return;
        }

        isUpdating = true;
        $wrapper.block({
            message: '<div class="loading-spinner"></div>',
            overlayCSS: {
                background: '#fff',
                opacity: 0.6
            },
            css: {
                border: 'none',
                padding: '15px',
                backgroundColor: '#000',
                '-webkit-border-radius': '10px',
                '-moz-border-radius': '10px',
                opacity: .5,
                color: '#fff'
            }
        });

        $.ajax({
            type: 'POST',
            url: wc_checkout_params.wc_ajax_url.toString().replace('%%endpoint%%', 'apply_coupon'),
            data: {
                security: wc_checkout_params.apply_coupon_nonce,
                coupon_code: couponCode,
                csrf_token: checkoutAjax.csrf_token  // Aggiunto token CSRF
            },
            success: function(response) {
                if (response.new_csrf_token) {
                    checkoutAjax.csrf_token = response.new_csrf_token;
                }
                $('.woocommerce-error, .woocommerce-message, .woocommerce-info').remove();
                
                // Determina il tipo di messaggio basato sulla risposta
                const messageType = response.includes('error') ? 'error' : 
                                   response.includes('success') ? 'success' : 'info';
                
                // Estrai il testo del messaggio dalla risposta HTML
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = response;
                const messageText = tempDiv.textContent.trim();
                
                // Usa la funzione showError per mostrare il messaggio formattato correttamente
                showError(messageText, messageType);
                
                if (!response.includes('error')) {
                    $couponCode.val('');
                    $(document.body).trigger('update_checkout', { update_shipping_method: false });
                }
            },
            error: function() {
                showError('Si è verificato un errore. Riprova più tardi.', 'error');  
            },
            complete: function() {
                $wrapper.unblock();
                isUpdating = false;
            }
        });
    }

    // Gestione spedizione
    $(document).on('change', '.shipping_method', function(e) {
        e.preventDefault();
        
        const $option = $(this).closest('.shipping-method-option');
        const selectedMethod = $(this).val();
        
        // Previeni richieste troppo ravvicinate
        const now = Date.now();
        if (now - lastShippingUpdate < MIN_UPDATE_INTERVAL) {
            return;
        }
        
        $('.shipping-method-option').removeClass('selected');
        $option.addClass('selected');
        
        // Blocca UI
        $('.shipping-payment-wrapper, .custom-order-review').block({
            message: '<div class="loading-spinner"></div>',
            overlayCSS: {
                background: '#fff',
                opacity: 0.6
            }
        });
        
        // Aggiungi alla coda
        shippingUpdateQueue = shippingUpdateQueue.then(() => {
            return new Promise((resolve, reject) => {
                $.ajax({
                    type: 'POST',
                    url: checkoutAjax.ajaxurl,
                    data: {
                        action: 'update_shipping_method',
                        nonce: checkoutAjax.shipping_nonce,
                        csrf_token: checkoutAjax.csrf_token,
                        shipping_method: [selectedMethod]
                    },
                    success: function(response) {
                        if (response.success) {
                            if (response.new_csrf_token) {
                                checkoutAjax.csrf_token = response.new_csrf_token;
                            }
                            
                            // Aggiorna ultimo timestamp
                            lastShippingUpdate = Date.now();
                            
                            // Trigger update checkout dopo successo
                            $(document.body).trigger('update_checkout', {
                                update_shipping_method: true
                            });
                            
                            resolve();
                        } else {
                            $('.shipping-payment-wrapper, .custom-order-review').unblock();
                            showError(response.data?.error || 'Errore nell\'aggiornamento del metodo di spedizione', 'error');
                            reject(new Error(response.data?.error));
                        }
                    },
                    error: function(xhr) {
                        $('.shipping-payment-wrapper, .custom-order-review').unblock();
                        
                        if (xhr.status === 429) {
                            const retryAfter = xhr.getResponseHeader('Retry-After');
                            showError(`Troppe richieste. Riprova tra ${retryAfter || 60} secondi.`, 'info');
                        } else {
                            showError('Errore nella comunicazione con il server', 'error');
                        }
                        reject(xhr);
                    }
                });
            });
        }).catch(error => {
            console.error('Shipping update error:', error);
        });
    });
    
    // Aggiungi handler per i campi paese/stato/provincia
    $(document).on('change', 'select.country_select, select.state_select', function() {
        // Blocca UI dei metodi di spedizione durante l'aggiornamento
        $('.shipping-methods-list').block({
            message: '<div class="loading-spinner"></div>',
            overlayCSS: {
                background: '#fff',
                opacity: 0.6
            }
        });
    });

    // Modifica l'handler esistente per updated_checkout
    $(document.body).on('updated_checkout', function(event, data) {
        // Sblocco UI
        $('.shipping-payment-wrapper, .custom-order-review, .shipping-methods-list').unblock();
        
        $('.shipping_method:checked').each(function() {
            $(this).closest('.shipping-method-option').addClass('selected');
        });
        
        $('input[name="payment_method"]:checked').closest('.wc_payment_method').addClass('selected');
        
        // Aggiungi il nuovo controllo per il token CSRF
        if (data && data.fragments && data.fragments.csrf_token) {
            checkoutAjax.csrf_token = data.fragments.csrf_token;
        }
    });

    // Gestione pagamento
    $(document).on('change', 'input[name="payment_method"]', function(e) {
        e.preventDefault();
        
        var $method = $(this).closest('.wc_payment_method');
        
        $('.wc_payment_method').removeClass('selected');
        $method.addClass('selected');
        
        $('.shipping-payment-wrapper, .custom-order-review').block({
            message: '<div class="loading-spinner"></div>',
            overlayCSS: {
                background: '#fff',
                opacity: 0.6
            }
        });

        /* setTimeout(() => {
            $('.wc_payment_method').removeClass('selected');
            $method.addClass('selected');
        }, 100); */
        
        $(document.body).trigger('update_checkout');
    });

    $(document).on('click', '.wc_payment_method', function(e) {
        var $radio = $(this).find('input[type="radio"]');
        if (!$(e.target).is($radio) && !$(e.target).is('a') && !$(e.target).closest('.payment_box').length) {
            $radio.prop('checked', true).trigger('change');
        }
    });

    $(document).ready(function() {
        $('input[name="payment_method"]:checked').closest('.wc_payment_method').addClass('selected');
    });

    // Prevenzione double submit
    $(document.body).on('submit', 'form.checkout', function(e) {
        if ($('.shipping-payment-wrapper').is(':blocked')) {
            e.preventDefault();
            return false;
        }
    });

    $(document).on('keypress', '.quantity-input', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $(this).blur();
        }
    });
});