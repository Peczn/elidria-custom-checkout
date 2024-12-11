<?php
/**
 * CSRF Protection Class
 */
class Elidria_CSRF_Protection {
    private $token_name = 'elidria_csrf_token';
    private $cookie_name = 'elidria_csrf_cookie';
    private $token_lifetime = 3600; // 1 hour
    private $overlap_time = 30; // 30 secondi di sovrapposizione per gestire richieste simultanee

    public function __construct() {
        // Inizializza la protezione
        add_action('init', array($this, 'initialize_csrf_protection'));
        
        // Rigenera token dopo login/logout
        add_action('wp_login', array($this, 'regenerate_token'));
        add_action('wp_logout', array($this, 'regenerate_token'));
    }

    /**
     * Inizializza la protezione CSRF
     */
    public function initialize_csrf_protection() {
        if (!$this->get_token()) {
            $this->regenerate_token();
        }
    }

    /**
     * Genera un nuovo token CSRF
     */
    private function generate_token() {
        return bin2hex(random_bytes(32));
    }

    /**
     * Rigenera il token CSRF
     */
    public function regenerate_token() {
        $token = $this->generate_token();
        set_transient($this->token_name, $token, $this->token_lifetime);
        $this->set_cookie($token);
    }

    /**
     * Imposta il cookie CSRF con policy di sicurezza
     */
    private function set_cookie($token) {
        $secure = is_ssl();
        $httponly = true;
        
        setcookie(
            $this->cookie_name,
            $token,
            [
                'expires' => time() + $this->token_lifetime,
                'path' => COOKIEPATH,
                'domain' => COOKIE_DOMAIN,
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => 'Strict'
            ]
        );
    }

    /**
     * Recupera il token corrente
     */
    public function get_token() {
        return get_transient($this->token_name);
    }

    /**
     * Ottiene gli origin consentiti
     */
    private function get_allowed_origins() {
        $site_url = parse_url(get_site_url(), PHP_URL_HOST);
        $allowed = array($site_url);
        
        // Aggiungi eventuali domini aggiuntivi (es. staging, development)
        if (defined('ALLOWED_ORIGINS') && is_array(ALLOWED_ORIGINS)) {
            $allowed = array_merge($allowed, ALLOWED_ORIGINS);
        }
        
        return apply_filters('elidria_allowed_origins', $allowed);
    }
 
    /**
     * Verifica l'origine della richiesta
     */
    public function verify_origin() {
        // Lista degli origin consentiti
        $allowed_origins = $this->get_allowed_origins();
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
        
        if ($origin) {
            $origin_parts = parse_url($origin);
            // Verifica anche il protocollo
            if (!isset($origin_parts['scheme']) || 
                !isset($origin_parts['host']) || 
                !in_array($origin_parts['scheme'], ['http', 'https']) ||
                !in_array($origin_parts['host'], $allowed_origins, true)) {
                return false;
            }
        }

        // Verifica Referer come backup
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        if ($referer) {
            $referer_parts = parse_url($referer);
            if (!isset($referer_parts['scheme']) || 
                !isset($referer_parts['host']) || 
                !in_array($referer_parts['scheme'], ['http', 'https']) ||
                !in_array($referer_parts['host'], $allowed_origins, true)) {
                $this->log_security_event('invalid_referer', [
                    'referer' => $referer,
                    'allowed' => $allowed_origins
                ]);
                return false;
            }
        }

        // Se non c'è né Origin né Referer, nega l'accesso
        if (!$origin && !$referer) {
            $this->log_security_event('missing_origin_referer', [
                'remote_addr' => $_SERVER['REMOTE_ADDR']
            ]);
            return false;
        }

        return true;
    } 

    /**
     * Verifica completa della richiesta AJAX
     */
    public function verify_ajax_request($nonce_action = '') {
        // Verifica metodo
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return false;
        }

        // Verifica origine
        if (!$this->verify_origin()) {
            return false;
        }
    
        // Verifica token CSRF
        $provided_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
        if (!$this->verify_token($provided_token)) {
            return false;
        }

        // Se la verifica ha successo, ruota il token
        $this->rotate_token($provided_token);
    
        // Verifica nonce WordPress se fornito
        if ($nonce_action && !check_ajax_referer($nonce_action, 'nonce', false)) {
            return false;
        }
    
        return true;
    }

    private function rotate_token($used_token) {
        // Genera nuovo token
        $new_token = $this->generate_token();
        
        // Salva il nuovo token
        set_transient($this->token_name, $new_token, $this->token_lifetime);
        
        // Salva temporaneamente il vecchio token con una breve scadenza
        set_transient($this->token_name . '_previous', $used_token, $this->overlap_time);
        
        // Aggiorna il cookie con il nuovo token
        $this->set_cookie($new_token);
    }

    public function verify_token($provided_token) {
        if (empty($provided_token)) {
            return false;
        }

        // Verifica contro il token corrente
        $current_token = get_transient($this->token_name);
        if (hash_equals($current_token, $provided_token)) {
            return true;
        }

        // Se non corrisponde al token corrente, verifica contro il token precedente
        $previous_token = get_transient($this->token_name . '_previous');
        if ($previous_token && hash_equals($previous_token, $provided_token)) {
            return true;
        }

        return false;
    }
}