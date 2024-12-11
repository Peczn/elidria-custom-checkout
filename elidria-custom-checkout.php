<?php
/**
 * Plugin Name: Elidria's checkout
 * Description: Personalizza il checkout di WooCommerce in stile Shopify
 * Version: 1.0
 * Author: Filippo Peci
 */

if (!defined('ABSPATH')) {
    exit;
}

// Definisci il path base del plugin
define('ELIDRIA_CHECKOUT_PATH', plugin_dir_path(__FILE__));

// Includi la classe CSRF Protection
require_once ELIDRIA_CHECKOUT_PATH . 'includes/class-elidria-csrf-protection.php';
require_once ELIDRIA_CHECKOUT_PATH . 'includes/class-rate-limiter.php';
require_once ELIDRIA_CHECKOUT_PATH . 'includes/class-stock-manager.php';  
require_once ELIDRIA_CHECKOUT_PATH . 'includes/class-elidria-session-security.php';

class Custom_WooCommerce_Checkout {
    private $nonce_actions = [
        'update_checkout_quantity' => 'update-checkout-quantity',
        'refresh_order_review' => 'refresh-order-review',
        'apply_coupon' => 'apply-coupon',
        'update_shipping_method' => 'update-shipping-method'  // Aggiunto il nuovo nonce
    ];

    private $stock_manager;
    private $session_security; 

    public function __construct() {
        // Verifica immediata che WooCommerce sia presente
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Inizializza protezione CSRF
        $this->csrf_protection = new Elidria_CSRF_Protection();
        $this->rate_limiter = new Elidria_Rate_Limiter();
        $this->stock_manager = new Elidria_Stock_Manager();
        // Inizializza la sicurezza delle sessioni
        $this->session_security = new Elidria_Session_Security();

        // Integrazione con WooCommerce session handling
        add_filter('woocommerce_session_handler', function($handler) {
            if ($this->session_security && $this->session_security->is_session_valid()) {
                // Aggiorna il customer ID di WooCommerce con il nostro ID sicuro
                if (method_exists($handler, 'get_customer_id')) {
                    add_filter('woocommerce_customer_id', function($customer_id) {
                        return $this->session_security->get_client_id() ?: $customer_id;
                    });
                }
            }
            return $handler;
        });

        // Registra l'attivazione del plugin
        register_activation_hook(__FILE__, array($this, 'plugin_activation'));
        // Aggiungi questo hook per la disattivazione
        register_deactivation_hook(__FILE__, array($this, 'plugin_deactivation'));

        // Hook di base che verranno eseguiti solo quando necessario
        add_action('wp', array($this, 'init_checkout_hooks'));
        
        // Aggiungi handler AJAX (questi devono essere sempre disponibili)
        foreach ($this->nonce_actions as $action => $nonce_action) {
            add_action("wp_ajax_{$action}", array($this, $action));
            add_action("wp_ajax_nopriv_{$action}", array($this, $action));
        }
        
        // Aggiungi nuovo hook per gestire i fragments
        add_filter('woocommerce_update_order_review_fragments', array($this, 'update_all_checkout_fragments'), 50);

        // Nuovo hook per la pulizia delle prenotazioni scadute
        add_action('cleanup_expired_stock_reservations', array($this, 'cleanup_expired_reservations'));
        add_action('woocommerce_checkout_order_processed', array($this, 'confirm_stock_reservations'), 10, 1);
    }

    /**
     * Attivazione del plugin
     */
    public function plugin_activation() {
        // Crea la tabella per le prenotazioni stock
        $this->stock_manager->create_reserved_stock_table(); 
                        
        // Schedula il cron job per la pulizia
        if (!wp_next_scheduled('cleanup_expired_stock_reservations')) {
            wp_schedule_event(time(), 'every_five_minutes', 'cleanup_expired_stock_reservations');
        }
    }
    /* disattivazione */
    public function plugin_deactivation() {
        wp_clear_scheduled_hook('cleanup_expired_stock_reservations');
    }
    
    /**
     * Pulizia prenotazioni scadute
     */
    public function cleanup_expired_reservations() {
        $this->stock_manager->cleanup_expired_reservations();
    }

    public function init_checkout_hooks() {
        // Verifica che siamo nella pagina checkout e che WC sia disponibile
        if (!is_checkout() || !function_exists('WC') || !WC()->cart) {
            return;
        }

        // Rimuovi le azioni di default
        remove_action('woocommerce_checkout_order_review', 'woocommerce_order_review', 10);
        remove_action('woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20);
        remove_action('woocommerce_checkout_order_review_heading', '__return_null');

        // Aggiungi le nostre azioni custom
        add_action('woocommerce_checkout_before_customer_details', array($this, 'open_custom_div'), 10);
        add_action('woocommerce_checkout_after_customer_details', array($this, 'add_payment_section'), 20);
        add_action('woocommerce_checkout_after_order_review', array($this, 'close_wrappers'), 10);
        
        // Riorganizza i campi del checkout
        add_filter('woocommerce_checkout_fields', array($this, 'reorder_checkout_fields'), 99);
        
        // Aggiungi stili e script
        add_action('wp_enqueue_scripts', array($this, 'add_custom_styles'));
        add_action('wp_enqueue_scripts', array($this, 'add_custom_scripts'));
    }

    public function add_custom_styles() {
        if (is_checkout()) {
            wp_enqueue_style(
                'elidria-custom-checkout-styles',
                plugins_url('css/elidria-custom-checkout.css', __FILE__),
                array(),
                time(),
                'all'
            );
        }
    }

    public function add_custom_scripts() {
        if (is_checkout()) {
            wp_enqueue_script('jquery');
            wp_enqueue_script('jquery-blockui');
            
            wp_enqueue_script(
                'elidria-checkout-quantity',
                plugins_url('js/checkout-quantity.js', __FILE__),
                array('jquery', 'jquery-blockui'),
                time(),
                true
            );
            
            wp_localize_script('elidria-checkout-quantity', 'checkoutAjax', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('update-checkout-quantity'),
                'update_order_review_nonce' => wp_create_nonce('update-order-review'),
                'shipping_nonce' => wp_create_nonce('update-shipping-method'),
                'csrf_token' => $this->csrf_protection->get_token() // Aggiungiamo il token CSRF
            ));
        }
    }

    public function open_custom_div() {
        echo '<div class="shopify-style-checkout">';
        echo '<div class="checkout-columns">';
        echo '<div class="checkout-customer-details">';
    }

    public function close_wrappers() {
        echo '</div>'; // Chiude checkout-columns
        echo '</div>'; // Chiude shopify-style-checkout
    }

    public function reorder_checkout_fields($fields) {
        $placeholders = array(
            'billing_first_name' => esc_attr__('Nome', 'woocommerce'),
            'billing_last_name'  => esc_attr__('Cognome', 'woocommerce'),
            'billing_company'    => esc_attr__('Azienda (opzionale)', 'woocommerce'),
            'billing_address_1'  => esc_attr__('Indirizzo', 'woocommerce'),
            'billing_address_2'  => esc_attr__('Appartamento, interno, etc. (opzionale)', 'woocommerce'),
            'billing_city'       => esc_attr__('Città', 'woocommerce'),
            'billing_postcode'   => esc_attr__('CAP', 'woocommerce'),
            'billing_country'    => esc_attr__('Paese/Regione', 'woocommerce'),
            'billing_state'      => esc_attr__('Provincia', 'woocommerce'),
            'billing_phone'      => esc_attr__('Telefono', 'woocommerce'),
            'billing_email'      => esc_attr__('Email', 'woocommerce'),
            'shipping_first_name'=> esc_attr__('Nome', 'woocommerce'),
            'shipping_last_name' => esc_attr__('Cognome', 'woocommerce'),
            'shipping_company'   => esc_attr__('Azienda (opzionale)', 'woocommerce'),
            'shipping_address_1' => esc_attr__('Indirizzo', 'woocommerce'),
            'shipping_address_2' => esc_attr__('Appartamento, interno, etc. (opzionale)', 'woocommerce'),
            'shipping_city'      => esc_attr__('Città', 'woocommerce'),
            'shipping_postcode'  => esc_attr__('CAP', 'woocommerce'),
            'shipping_country'   => esc_attr__('Paese/Regione', 'woocommerce'),
            'shipping_state'     => esc_attr__('Provincia', 'woocommerce'),
            'order_comments'     => esc_attr__('Note sull\'ordine (opzionale)', 'woocommerce')
        );
    
        // Billing fields (sempre presenti)
        foreach ($fields['billing'] as $key => $field) {
            $fields['billing'][$key]['label'] = false;
            if (isset($placeholders[$key])) {
                $fields['billing'][$key]['placeholder'] = $placeholders[$key];
            }
        }
    
        // Shipping fields (opzionali)
        if (isset($fields['shipping'])) {
            foreach ($fields['shipping'] as $key => $field) {
                $fields['shipping'][$key]['label'] = false;
                if (isset($placeholders[$key])) {
                    $fields['shipping'][$key]['placeholder'] = $placeholders[$key];
                }
            }
        }
    
        // Order fields (opzionali)
        if (isset($fields['order'])) {
            foreach ($fields['order'] as $key => $field) {
                $fields['order'][$key]['label'] = false;
                if (isset($placeholders[$key])) {
                    $fields['order'][$key]['placeholder'] = $placeholders[$key];
                }
            }
        }
    
        // Imposta le priorità dei campi billing
        $fields['billing']['billing_first_name']['priority'] = 10;
        $fields['billing']['billing_last_name']['priority'] = 20;
        $fields['billing']['billing_email']['priority'] = 30;
        $fields['billing']['billing_phone']['priority'] = 40;
    
        return $fields;
    }

    public function update_all_checkout_fragments($fragments) {
        // Prima aggiungi i fragments della spedizione
        $fragments = $this->add_shipping_methods_fragment($fragments);
        
        // Poi aggiungi il fragment completo dell'ordine
        ob_start();
        $this->custom_order_review();
        $fragments['.custom-order-review'] = ob_get_clean();
        
        return $fragments;
    }

    public function output_shipping_methods() {
        if (!WC()->cart || !WC()->cart->needs_shipping() || !WC()->cart->show_shipping()) {
            return;
        }
    
        wp_enqueue_script('wc-country-select');
        
        do_action('woocommerce_review_order_before_shipping');
        
        echo '<div class="shipping-methods-list">';
        
        $packages = WC()->shipping()->get_packages();
        
        foreach ($packages as $i => $package) {
            $chosen_method = isset(WC()->session->chosen_shipping_methods[$i]) ? WC()->session->chosen_shipping_methods[$i] : '';
            $product_names = array();
            
            if (!empty($package['contents'])) {
                foreach ($package['contents'] as $item) {
                    $product_names[] = $item['data']->get_name() . ' &times;' . $item['quantity'];
                }
            }
            
            $available_methods = $package['rates'];
            $formatted_destination = WC()->countries->get_formatted_address($package['destination'], ', ');
            
            if (count($available_methods) > 0) {
                foreach ($available_methods as $method) {
                    $method_id = esc_attr(sanitize_title($method->id));
                    $input_id = "shipping_method_{$i}_{$method_id}";
                    $is_chosen = ($method->id === $chosen_method);
                    
                    echo '<label class="shipping-method-option' . ($is_chosen ? ' selected' : '') . '" for="' . $input_id . '">';
                    echo '<input 
                            type="radio" 
                            name="shipping_method[' . $i . ']" 
                            data-index="' . $i . '" 
                            id="' . $input_id . '" 
                            value="' . esc_attr($method->id) . '" 
                            class="shipping_method" 
                            ' . ($is_chosen ? 'checked="checked"' : '') . '
                        />';
                    
                    echo '<div class="shipping-method-content">';
                    echo '<div class="shipping-method-info">';
                    echo '<span class="shipping-method-name">' . esc_html($method->label) . '</span>';
                    if (!empty($method->meta_data['delivery_time'])) {
                        echo '<span class="delivery-estimate">' . esc_html($method->meta_data['delivery_time']) . '</span>';
                    }
                    echo '</div>';
                    
                    echo '<div class="shipping-method-price">';
                    echo wc_price($method->cost);
                    echo '</div>';
                    echo '</div>';
                    echo '</label>';
                }
            } else {
                echo '<div class="no-shipping-methods">' . __('Nessun metodo di spedizione disponibile', 'woocommerce') . '</div>';
            }
        }
        
        echo '</div>';
        
        do_action('woocommerce_review_order_after_shipping');
    }
    
    public function add_shipping_methods_fragment($fragments) {
        if (WC()->cart && WC()->cart->needs_shipping()) {
            ob_start();
            $this->output_shipping_methods();
            $fragments['.shipping-methods-list'] = ob_get_clean();
            
            ob_start();
            echo '<span class="value">';
            if (WC()->customer && WC()->customer->get_shipping_country()) {
                echo WC()->cart->get_cart_shipping_total();
            } else {
                echo __('Inserisci indirizzo di spedizione', 'woocommerce');
            }
            echo '</span>';
            $fragments['.total-row.shipping .value'] = ob_get_clean();
            
            ob_start();
            echo '<span class="value">' . WC()->cart->get_total() . '</span>';
            $fragments['.total-row.total .value'] = ob_get_clean();
        }
        
        return $fragments;
    }
    
    public function add_payment_section() {
        echo '<div class="shipping-payment-wrapper">';
        
        // Sezione metodi di spedizione
        if (WC()->cart && WC()->cart->needs_shipping() && WC()->cart->show_shipping()) {
            echo '<div class="checkout-shipping-methods checkout-section">';
            echo '<h3>' . __('Metodo di spedizione', 'woocommerce') . '</h3>';
            $this->output_shipping_methods();
            echo '</div>';
        }
        
        // Sezione metodi di pagamento
        echo '<div class="checkout-payment-section checkout-section">';
        echo '<h3>' . __('Modalità di pagamento', 'woocommerce') . '</h3>';
        echo '<div id="payment" class="woocommerce-checkout-payment">';
        woocommerce_checkout_payment();
        echo '</div>';
        echo '</div>';
        
        echo '</div>'; // Chiude shipping-payment-wrapper
        echo '</div>'; // Chiude checkout-customer-details
        
        // Sezione riepilogo ordine
        echo '<div class="checkout-order-review">';
        $this->custom_order_review();
        echo '</div>';
    }

    public function custom_order_review() {
        if (!WC()->cart) {
            return;
        }

        $cart = WC()->cart;
        
        echo '<div class="custom-order-review">';
        echo '<h2>' . __('Il tuo ordine', 'woocommerce') . '</h2>';
        
        echo '<div class="cart-items">';
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $thumbnail = get_the_post_thumbnail_url($cart_item['product_id'], 'thumbnail');
            if (!$thumbnail) {
                $thumbnail = wc_placeholder_img_src('thumbnail');
            }
            
            echo '<div class="cart-item">';
            echo '<div class="product-info">';
            echo '<img src="' . esc_url($thumbnail) . '" alt="' . esc_attr($product->get_name()) . '" class="product-thumb">';
            echo '<div class="product-details">';
            echo '<div class="product-name">' . $product->get_name() . '</div>';
            echo '<div class="product-meta">' . $this->get_product_variations($cart_item) . '</div>';
            echo '</div>';
            echo '</div>';
            
            echo '<div class="quantity-and-price">';
            echo '<div class="checkout-quantity-controls" 
                data-cart-key="' . esc_attr($cart_item_key) . '"
                data-max-quantity="' . esc_attr($product->get_max_purchase_quantity()) . '"
                data-min-quantity="' . esc_attr($product->get_min_purchase_quantity()) . '"
                ' . ($product->managing_stock() ? 'data-stock-quantity="' . esc_attr($product->get_stock_quantity()) . '"' : '') . ' >';
            echo '<button type="button" class="quantity-btn minus">-</button>';
            echo '<input type="number" class="quantity-input" value="' . esc_attr($cart_item['quantity']) . '" min="1" step="1">';
            echo '<button type="button" class="quantity-btn plus">+</button>';
            echo '</div>';
            
            echo '<div class="product-price">';
            echo wc_price($cart_item['line_subtotal']);
            echo '</div>';
            
            echo '<button type="button" class="remove-item" data-cart-key="' . esc_attr($cart_item_key) . '">&times;</button>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        
        echo '<div class="coupon">';
        echo '<div class="custom-coupon-form">';
        echo '<input type="text" name="coupon_code" class="input-text" id="custom_coupon_code" value="" placeholder="' . esc_attr__('Codice promozionale', 'woocommerce') . '">';
        echo '<button type="button" class="button apply-coupon">' . esc_html__('Applica', 'woocommerce') . '</button>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="order-totals">';
        echo '<div class="total-row subtotal">';
        echo '<span class="label">' . __('Subtotale', 'woocommerce') . '</span>';
        echo '<span class="value">' . $cart->get_cart_subtotal() . '</span>';
        echo '</div>';
        
        $total_discount = $cart->get_discount_total();
        if ($total_discount > 0) {
            echo '<div class="total-row discount">';
            echo '<span class="label">' . __('Sconto', 'woocommerce') . '</span>';
            echo '<span class="value">-' . wc_price($total_discount) . '</span>';
            echo '</div>';
        }
        
        echo '<div class="total-row shipping">';
        echo '<span class="label">' . __('Spedizione', 'woocommerce') . '</span>';
        echo '<span class="value">';
        if ($cart->needs_shipping() && WC()->customer && WC()->customer->get_shipping_country()) {
            echo $cart->get_cart_shipping_total();
        } else {
            echo __('Inserisci indirizzo di spedizione', 'woocommerce');
        }
        echo '</span>';
        echo '</div>';
        
        echo '<div class="total-row total">';
        echo '<span class="label">' . __('Totale', 'woocommerce') . '</span>';
        echo '<span class="value">' . $cart->get_total() . '</span>';
        echo '</div>';
        
        echo '<div class="costs-note">';
        echo '<span class="info-icon">ⓘ</span>';
        echo '<span class="note-text">' . __('The total amount you pay includes all applicable customs duties & taxes. We guarantee no additional charges on delivery.', 'woocommerce') . '</span>';
        echo '</div>';
        
        echo '</div>';
        echo '</div>';
    }

    private function get_product_variations($cart_item) {
        $variation_data = array();
        
        if (isset($cart_item['variation'])) {
            foreach ($cart_item['variation'] as $attribute => $value) {
                $taxonomy = str_replace('attribute_', '', $attribute);
                $term = get_term_by('slug', $value, $taxonomy);
                
                // Sanitizza il valore prima di aggiungerlo all'array
                $clean_value = $term ? wp_kses_post($term->name) : wp_kses_post($value);
                $variation_data[] = $clean_value;
            }
        }
        
        // Filtra l'output finale
        return wp_kses(
            implode(' / ', $variation_data),
            array(
                'span' => array('class' => array()),
                'strong' => array(),
                'em' => array()
            )
        );
    }

    /**
     * Verifica la sicurezza di una richiesta AJAX
     */

     private function get_allowed_html() {
        return array(
            'div' => array(
                'class' => array(),
                'id' => array(),
                'data-cart-key' => array(),
                'data-max-quantity' => array(),
                'data-min-quantity' => array(),
                'data-stock-quantity' => array(),
                'role' => array()
            ),
            'span' => array(
                'class' => array()
            ),
            'button' => array(
                'type' => array(),
                'class' => array(),
                'data-cart-key' => array()
            ),
            'input' => array(
                'type' => array(),
                'class' => array(),
                'value' => array(),
                'min' => array(),
                'step' => array(),
                'name' => array(),
                'id' => array(),
                'placeholder' => array()
            ),
            'img' => array(
                'src' => array(),
                'alt' => array(),
                'class' => array()
            ),
            'label' => array(
                'class' => array(),
                'for' => array()
            ),
            'h2' => array(),
            'h3' => array(),
            // Form elements
            'form' => array(
                'class' => array(),
                'id' => array(),
                'action' => array(),
                'method' => array()
            ),
            // Price elements
            'bdi' => array(),
            'small' => array(),
            // Links
            'a' => array(
                'href' => array(),
                'class' => array(),
                'id' => array(),
                'target' => array(),
                'rel' => array()
            )
        );
    }

    private function verify_ajax_security($action_name) {
        if (!isset($this->nonce_actions[$action_name])) {
            wp_send_json_error([
                'error' => esc_html__('Invalid action', 'woocommerce')
            ]);
            exit;
        }
        
        // Verifica rate limiting
        if (!$this->rate_limiter->check_rate_limit($action_name)) {
            header('Retry-After: 60');
            wp_send_json_error([
                'error' => esc_html__('Too many requests. Please wait before trying again.', 'woocommerce'),
                'code' => 'rate_limit_exceeded'
            ], 429);
            exit;
        }
        
        // Verifica CSRF
        if (!$this->csrf_protection->verify_ajax_request($this->nonce_actions[$action_name])) {
            // Segnala come fallimento al rate limiter
            $this->rate_limiter->check_rate_limit($action_name, true);
            
            wp_send_json_error([
                'error' => esc_html__('Security check failed', 'woocommerce')
            ]);
            exit;
        }
        
        return true;
    }

    public function refresh_order_review() {
        if (!$this->verify_ajax_security('refresh_order_review')) {
            wp_send_json_error([
                'error' => esc_html__('Verifica di sicurezza fallita', 'woocommerce')
            ]);
            return;
        }
        
        if (!WC()->cart) {
            wp_send_json_error(esc_html__('Cart not available', 'woocommerce'));
            return;
        }
        
        WC()->cart->calculate_totals();
        $fragments = apply_filters('woocommerce_update_order_review_fragments', []);
        $sanitized_fragments = array_map(function($fragment) {
            return wp_kses($fragment, $this->get_allowed_html());
        }, $fragments);

        wp_send_json_success([
            'fragments' => $sanitized_fragments,
            'new_csrf_token' => $this->csrf_protection->get_token() // Invia il nuovo token
        ]);
    }

    /**
     * Validazione rigorosa della quantità prodotto con sanitizzazione e controlli transazionali
     * 
     * @param mixed $quantity Quantità da validare
     * @param WC_Product $product Oggetto prodotto WooCommerce
     * @param bool $strict_mode Attiva controlli aggiuntivi (default: true)
     * @return array Risultato validazione con status, errore e valore corretto
     * @throws InvalidArgumentException Se i parametri non sono validi
     */
    private function validate_product_quantity($quantity, $product, bool $strict_mode = true): array {
        // Controllo tipo parametri
        if (!($product instanceof WC_Product)) {
            throw new InvalidArgumentException('Product must be an instance of WC_Product');
        }
    
        // Sanitizzazione iniziale input
        $sanitized_quantity = $this->sanitize_quantity_input($quantity);
        
        try {
            // Inizia transazione se in strict mode
            if ($strict_mode && function_exists('wc_transaction_query')) {
                wc_transaction_query('start');
            }
    
            // Validazione tipo e range base
            if (!is_int($sanitized_quantity)) {
                return [
                    'valid' => false,
                    'error' => __('La quantità deve essere un numero intero', 'woocommerce'),
                    'value' => 0,
                    'error_code' => 'invalid_type'
                ];
            }
    
            if ($sanitized_quantity < 0) {
                return [
                    'valid' => false,
                    'error' => __('La quantità non può essere negativa', 'woocommerce'),
                    'value' => 0,
                    'error_code' => 'negative_quantity'
                ];
            }
    
            // Ottieni i limiti di quantità
            $limits = $this->get_product_quantity_limits($product);
            
            // Verifica stock in real-time se in strict mode
            if ($strict_mode) {
                $current_stock = $product->get_stock_quantity();
                if ($current_stock !== null && $current_stock !== $limits['stock_quantity']) {
                    $limits['stock_quantity'] = $current_stock;
                    $limits['max_quantity'] = min($limits['max_quantity'], $current_stock);
                }
            }
    
            // Verifica limiti
            if ($sanitized_quantity > $limits['max_quantity']) {
                return [
                    'valid' => false,
                    'error' => sprintf(
                        __('Non puoi aggiungere più di %d unità di "%s"', 'woocommerce'),
                        $limits['max_quantity'],
                        esc_html($product->get_name())
                    ),
                    'value' => $limits['max_quantity'],
                    'error_code' => 'exceeds_max'
                ];
            }
    
            if ($sanitized_quantity < $limits['min_quantity']) {
                return [
                    'valid' => false,
                    'error' => sprintf(
                        __('Devi aggiungere almeno %d unità di "%s"', 'woocommerce'),
                        $limits['min_quantity'],
                        esc_html($product->get_name())
                    ),
                    'value' => $limits['min_quantity'],
                    'error_code' => 'below_min'
                ];
            }
    
            // Verifica disponibilità stock con il nuovo sistema di gestione
            if ($product->managing_stock() && $strict_mode) {
                try {
                    $stock_result = $this->stock_manager->verify_and_reserve_stock(
                        $product->get_id(),
                        $sanitized_quantity
                    );
                    
                    if (!$stock_result['success']) {
                        return [
                            'valid' => false,
                            'error' => $stock_result['error'],
                            'value' => $stock_result['available_quantity'],
                            'error_code' => 'insufficient_stock'
                        ];
                    }
                    
                    // Salva l'ID della prenotazione nella sessione
                    if (isset($stock_result['reservation_id'])) {
                        WC()->session->set(
                            "stock_reservation_{$product->get_id()}",
                            $stock_result['reservation_id']
                        );
                    }
                } catch (Exception $e) {
                    // Fallback al sistema tradizionale in caso di errore
                    if ($limits['stock_quantity'] !== null) {
                        if ($sanitized_quantity > $limits['stock_quantity']) {
                            return [
                                'valid' => false,
                                'error' => sprintf(
                                    __('Disponibili solo %d unità di "%s"', 'woocommerce'),
                                    $limits['stock_quantity'],
                                    esc_html($product->get_name())
                                ),
                                'value' => $limits['stock_quantity'],
                                'error_code' => 'insufficient_stock'
                            ];
                        }
    
                        // Verifica prenotazioni simultanee
                        $pending_quantity = $this->get_pending_order_quantity($product->get_id());
                        $available_quantity = $limits['stock_quantity'] - $pending_quantity;
                        
                        if ($sanitized_quantity > $available_quantity) {
                            return [
                                'valid' => false,
                                'error' => __('La quantità richiesta non è più disponibile', 'woocommerce'),
                                'value' => max(0, $available_quantity),
                                'error_code' => 'stock_reserved'
                            ];
                        }
                    }
                }
            }
    
            // Commit transazione se in strict mode
            if ($strict_mode && function_exists('wc_transaction_query')) {
                wc_transaction_query('commit');
            }
    
            // Tutte le validazioni passate
            return [
                'valid' => true,
                'error' => null,
                'value' => $sanitized_quantity,
                'error_code' => null
            ];
    
        } catch (Exception $e) {
            // Rollback in caso di errori
            if ($strict_mode && function_exists('wc_transaction_query')) {
                wc_transaction_query('rollback');
            }
    
            error_log(sprintf(
                '[Elidria Checkout] Product quantity validation error: %s',
                $e->getMessage()
            ));
    
            return [
                'valid' => false,
                'error' => __('Errore durante la validazione della quantità', 'woocommerce'),
                'value' => 0,
                'error_code' => 'system_error'
            ];
        }
    }

    /**
     * Sanitizza l'input della quantità
     * 
     * @param mixed $quantity Input da sanitizzare
     * @return int Quantità sanitizzata
     */
    private function sanitize_quantity_input($quantity): int {
        // Rimuovi caratteri non numerici
        $sanitized = preg_replace('/[^0-9]/', '', (string)$quantity);
        
        // Converti in intero
        return (int)$sanitized;
    }

    /**
     * Ottiene i limiti di quantità per un prodotto
     * 
     * @param WC_Product $product Prodotto
     * @return array Limiti di quantità
     */
    private function get_product_quantity_limits(WC_Product $product): array {
        // Limiti di default
        $default_min = 1;
        $default_max = 99;

        // Ottieni limiti specifici del prodotto
        $product_min = $product->get_min_purchase_quantity();
        $product_max = $product->get_max_purchase_quantity();
        
        // Ottieni limiti globali
        $global_min = apply_filters('woocommerce_quantity_input_min', $default_min, $product);
        $global_max = apply_filters('woocommerce_quantity_input_max', $default_max, $product);

        // Calcola limiti effettivi
        $min_quantity = max($default_min, $global_min, $product_min);
        $max_quantity = $product_max === -1 ? $global_max : min($global_max, $product_max);

        // Gestione stock
        $stock_quantity = null;
        if ($product->managing_stock()) {
            $stock_quantity = $product->get_stock_quantity();
            if ($stock_quantity !== null) {
                $max_quantity = min($max_quantity, $stock_quantity);
            }
        }

        return [
            'min_quantity' => $min_quantity,
            'max_quantity' => $max_quantity,
            'stock_quantity' => $stock_quantity
        ];
    }

    /**
     * Ottiene la quantità totale in ordini pendenti per un prodotto
     * 
     * @param int $product_id ID del prodotto
     * @return int Quantità totale in ordini pendenti
     */
    private function get_pending_order_quantity(int $product_id): int {
        global $wpdb;

        $sql = $wpdb->prepare("
            SELECT SUM(order_item_meta.meta_value) as quantity
            FROM {$wpdb->prefix}woocommerce_order_items as order_items
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta as order_item_meta ON order_items.order_item_id = order_item_meta.order_item_id
            LEFT JOIN {$wpdb->posts} as posts ON order_items.order_id = posts.ID
            WHERE posts.post_type = 'shop_order'
            AND posts.post_status IN ('wc-pending', 'wc-processing')
            AND order_item_meta.meta_key = '_product_id'
            AND order_item_meta.meta_value = %d
        ", $product_id);

        return (int)$wpdb->get_var($sql) ?: 0;
    }

    public function update_checkout_quantity() {
        if (!$this->verify_ajax_security('update_checkout_quantity')) {
            wp_send_json_error([
                'error' => esc_html__('Verifica di sicurezza fallita', 'woocommerce')
            ]);
            return;
        }
        
        if (!isset($_POST['cart_key']) || !isset($_POST['quantity'])) {
            wp_send_json_error([
                'error' => esc_html__('Parametri mancanti', 'woocommerce')
            ]);
            return;
        }
        
        if (!WC()->cart) {
            wp_send_json_error([
                'error' => esc_html__('Carrello non disponibile', 'woocommerce')
            ]);
            return;
        }
        
        $cart_key = sanitize_text_field($_POST['cart_key']);
        $quantity = filter_var($_POST['quantity'], FILTER_VALIDATE_INT);
        
        // Gestione rimozione prodotto
        if ($quantity === 0) {
            try {
                $cart_item = WC()->cart->get_cart_item($cart_key);
                if ($cart_item) {
                    // Cancella la prenotazione esistente se presente
                    $product_id = $cart_item['product_id'];
                    $reservation_id = WC()->session->get("stock_reservation_{$product_id}");
                    if ($reservation_id) {
                        try {
                            $this->stock_manager->cancel_reservation($reservation_id);
                        } catch (Exception $e) {
                            error_log(sprintf(
                                '[Elidria Checkout] Failed to cancel reservation %d: %s',
                                $reservation_id,
                                $e->getMessage()
                            ));
                        }
                        WC()->session->set("stock_reservation_{$product_id}", null);
                    }
                }
                
                WC()->cart->remove_cart_item($cart_key);
                WC()->cart->calculate_totals();
                
                $fragments = apply_filters('woocommerce_update_order_review_fragments', []);
                $sanitized_fragments = array_map(function($fragment) {
                    return wp_kses($fragment, $this->get_allowed_html());
                }, $fragments);
    
                wp_send_json_success([
                    'fragments' => $sanitized_fragments,
                    'new_csrf_token' => $this->csrf_protection->get_token()
                ]);
            } catch (Exception $e) {
                wp_send_json_error([
                    'error' => esc_html($e->getMessage())
                ]);
            }
            return;
        }
        
        // Verifica esistenza prodotto nel carrello
        $cart_item = WC()->cart->get_cart_item($cart_key);
        if (!$cart_item) {
            wp_send_json_error([
                'error' => esc_html__('Prodotto non trovato nel carrello', 'woocommerce')
            ]);
            return;
        }
        
        try {
            // Cancella la prenotazione esistente se presente
            $product_id = $cart_item['product_id'];
            $existing_reservation_id = WC()->session->get("stock_reservation_{$product_id}");
            if ($existing_reservation_id) {
                try {
                    $this->stock_manager->cancel_reservation($existing_reservation_id);
                } catch (Exception $e) {
                    error_log(sprintf(
                        '[Elidria Checkout] Failed to cancel existing reservation %d: %s',
                        $existing_reservation_id,
                        $e->getMessage()
                    ));
                }
                WC()->session->set("stock_reservation_{$product_id}", null);
            }
            
            // Validazione con nuovo sistema di gestione stock
            $validation = $this->validate_product_quantity($quantity, $cart_item['data']);
            if (!$validation['valid']) {
                wp_send_json_error([
                    'error' => esc_html($validation['error']),
                    'valid_quantity' => absint($validation['value']),
                    'error_code' => $validation['error_code']
                ]);
                return;
            }
            
        } catch (InvalidArgumentException $e) {
            wp_send_json_error([
                'error' => esc_html__('Errore di validazione parametri', 'woocommerce'),
                'error_code' => 'invalid_params'
            ]);
            return;
        }
        
        try {
            WC()->cart->set_quantity($cart_key, $validation['value'], true);
            WC()->cart->calculate_totals();
            
            $fragments = apply_filters('woocommerce_update_order_review_fragments', []);
            $sanitized_fragments = array_map(function($fragment) {
                return wp_kses($fragment, $this->get_allowed_html());
            }, $fragments);
    
            wp_send_json_success([
                'fragments' => $sanitized_fragments,
                'new_csrf_token' => $this->csrf_protection->get_token()
            ]);
            
        } catch (Exception $e) {
            // In caso di errore, prova a cancellare la nuova prenotazione
            $new_reservation_id = WC()->session->get("stock_reservation_{$product_id}");
            if ($new_reservation_id) {
                try {
                    $this->stock_manager->cancel_reservation($new_reservation_id);
                } catch (Exception $cancel_e) {
                    error_log(sprintf(
                        '[Elidria Checkout] Failed to cancel new reservation %d after error: %s',
                        $new_reservation_id,
                        $cancel_e->getMessage()
                    ));
                }
                WC()->session->set("stock_reservation_{$product_id}", null);
            }
            
            wp_send_json_error([
                'error' => esc_html($e->getMessage())
            ]);
        }
    }

    /**
     * Hook per confermare le prenotazioni al completamento dell'ordine
     */
    public function confirm_stock_reservations($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $reservation_id = WC()->session->get("stock_reservation_{$product_id}");
            
            if ($reservation_id) {
                $this->stock_manager->confirm_reservation($reservation_id, $order_id);
                WC()->session->set("stock_reservation_{$product_id}", null);
            }
        }
    }

    /**
     * Gestisce l'aggiornamento del metodo di spedizione
     */
    public function update_shipping_method() {
        
        if (!$this->verify_ajax_security('update_shipping_method')) {
            wp_send_json_error([
                'error' => esc_html__('Verifica di sicurezza fallita', 'woocommerce')
            ]);
            return;
        }

        // Verifica che il carrello esista
        if (!WC()->cart) {
            wp_send_json_error([
                'error' => esc_html__('Carrello non disponibile', 'woocommerce')
            ]);
            return;
        }
        
        // Verifica e sanitizza l'input
        $shipping_method = isset($_POST['shipping_method']) ? wc_clean(wp_unslash($_POST['shipping_method'])) : [];
        
        if (!is_array($shipping_method)) {
            wp_send_json_error([
                'error' => esc_html__('Formato metodo di spedizione non valido', 'woocommerce')
            ]);
            return;
        }
    
        // Forza il ricalcolo dei pacchetti di spedizione
        WC()->cart->calculate_shipping();
        WC()->shipping()->calculate_shipping(WC()->cart->get_shipping_packages());
        
        // Verifica che il metodo di spedizione sia valido
        $packages = WC()->shipping()->get_packages();
        $valid_methods = [];
        
        // Raccogli tutti i metodi validi
        foreach ($packages as $package) {
            if (isset($package['rates'])) {
                foreach ($package['rates'] as $rate) {
                    $valid_methods[] = $rate->id;
                }
            }
        }
        
        // Verifica che il metodo selezionato sia tra quelli validi
        foreach ($shipping_method as $i => $value) {
            if (!in_array($value, $valid_methods)) {
                wp_send_json_error([
                    'error' => esc_html__('Metodo di spedizione non valido', 'woocommerce')
                ]);
                return;
            }
        }
        
        // Aggiorna i metodi di spedizione
        foreach ($shipping_method as $i => $value) {
            WC()->session->set("chosen_shipping_methods[$i]", $value);
        }
        
        // Ricalcola i totali
        WC()->cart->calculate_totals();
        
        // Restituisci successo
        $fragments = apply_filters('woocommerce_update_order_review_fragments', []);
        $sanitized_fragments = array_map(function($fragment) {
            return wp_kses($fragment, $this->get_allowed_html());
        }, $fragments);

        wp_send_json_success([
            'fragments' => $sanitized_fragments,
            'new_csrf_token' => $this->csrf_protection->get_token() // Invia il nuovo token
        ]);
    }
}

function initialize_elidria_checkout() {
    new Custom_WooCommerce_Checkout();
}
add_action('plugins_loaded', 'initialize_elidria_checkout');