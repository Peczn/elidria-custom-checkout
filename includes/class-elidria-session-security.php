<?php
/**
 * Gestione sicura delle sessioni per prevenire Session Fixation
 * 
 * @package Elidria
 * @since 1.0.0
 */
class Elidria_Session_Security {
    private $session_name = 'ELIDRIA_SESSID';
    private $entropy_length = 32;
    private $session_lifetime = 7200; // 2 ore
    private $regenerate_interval = 300; // 5 minuti
    private $session_cookie_params = [];
    
    public function __construct() {
        // Configura i parametri del cookie di sessione
        $this->session_cookie_params = [
            'lifetime' => 0, // Cookie di sessione
            'path' => COOKIEPATH,
            'domain' => COOKIE_DOMAIN,
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax'  // Lax per compatibilità con form esterni
        ];
        
        // Inizializza la protezione della sessione
        $this->initialize();
    }
    
    /**
     * Inizializza la protezione della sessione
     */
    public function initialize() {
        // Imposta il nome della sessione
        if (session_name() !== $this->session_name) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            session_name($this->session_name);
        }
        
        // Configura i parametri del cookie prima di iniziare la sessione
        session_set_cookie_params($this->session_cookie_params);
        
        // Avvia la sessione se non è già attiva
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        // Imposta l'ID univoco del client se non esiste
        if (!isset($_SESSION['client_id'])) {
            $_SESSION['client_id'] = $this->generate_client_id();
        }
        
        // Verifica e rigenera la sessione se necessario
        $this->validate_and_regenerate_session();
        
        // Imposta header di sicurezza
        $this->set_security_headers();
    }
    
    /**
     * Genera un ID client univoco e sicuro
     */
    private function generate_client_id() {
        return bin2hex(random_bytes($this->entropy_length));
    }
    
    /**
     * Valida la sessione corrente e la rigenera se necessario
     */
    private function validate_and_regenerate_session() {
        // Verifica se è necessario rigenerare la sessione
        if ($this->should_regenerate_session()) {
            $this->regenerate_session();
        }
        
        // Verifica le fingerprint del client
        if (!$this->validate_client_fingerprint()) {
            $this->handle_invalid_session();
            return;
        }
        
        // Aggiorna il timestamp dell'ultima attività
        $_SESSION['last_activity'] = time();
        
        // Verifica la scadenza della sessione
        if ($this->is_session_expired()) {
            $this->handle_expired_session();
            return;
        }
        
        // Aggiorna fingerprint del client
        $this->update_client_fingerprint();
    }
    
    /**
     * Verifica se è necessario rigenerare la sessione
     */
    private function should_regenerate_session() {
        return !isset($_SESSION['last_regeneration']) ||
               (time() - $_SESSION['last_regeneration']) > $this->regenerate_interval;
    }
    
    /**
     * Rigenera la sessione in modo sicuro
     */
    public function regenerate_session() {
        // Salva i dati vecchi
        $old_session_data = $_SESSION;
        
        // Rigenera l'ID sessione
        session_regenerate_id(true);
        
        // Ripristina i dati e aggiorna i timestamp
        $_SESSION = $old_session_data;
        $_SESSION['last_regeneration'] = time();
        $_SESSION['created_at'] = time();
        
        // Aggiorna il client ID
        $_SESSION['client_id'] = $this->generate_client_id();
        
        // Aggiorna fingerprint
        $this->update_client_fingerprint();

        // Ruota il cookie di sessione
        $this->rotate_session_cookie();
    }

    /* ruota cookie di sessione */
    private function rotate_session_cookie() {
        if (isset($_COOKIE[session_name()])) {
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => COOKIEPATH,
                    'domain' => COOKIE_DOMAIN,
                    'secure' => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]
            );
        }
        
        setcookie(
            session_name(),
            session_id(),
            [
                'expires' => time() + $this->session_lifetime,
                'path' => COOKIEPATH,
                'domain' => COOKIE_DOMAIN,
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
    }
    
    /**
     * Verifica la fingerprint del client
     */
    private function validate_client_fingerprint() {
        if (!isset($_SESSION['client_fingerprint'])) {
            return true; // Prima visita
        }
        
        $current_fingerprint = $this->generate_client_fingerprint();
        return hash_equals($_SESSION['client_fingerprint'], $current_fingerprint);
    }
    
    /**
     * Genera la fingerprint del client
     */
    private function generate_client_fingerprint() {
        $fingerprint_data = [
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            'ip_segment' => $this->get_ip_segment(),
            'client_id' => $_SESSION['client_id'] ?? ''
        ];
        
        return hash('sha256', json_encode($fingerprint_data));
    }
    
    /**
     * Ottiene il segmento IP (primi 3 ottetti per IPv4, primi 4 blocchi per IPv6)
     */
    private function get_ip_segment() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $segments = explode('.', $ip);
            array_pop($segments);
            return implode('.', $segments);
        }
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $segments = explode(':', $ip);
            return implode(':', array_slice($segments, 0, 4));
        }
        
        return '';
    }
    
    /**
     * Aggiorna la fingerprint del client
     */
    private function update_client_fingerprint() {
        $_SESSION['client_fingerprint'] = $this->generate_client_fingerprint();
    }
    
    /**
     * Verifica se la sessione è scaduta
     */
    private function is_session_expired() {
        if (!isset($_SESSION['last_activity'])) {
            return false;
        }
        
        return (time() - $_SESSION['last_activity']) > $this->session_lifetime;
    }
    
    /**
     * Gestisce una sessione non valida
     */
    private function handle_invalid_session() {
        $this->destroy_session();
        $this->initialize(); // Crea una nuova sessione pulita
    }
    
    /**
     * Gestisce una sessione scaduta
     */
    private function handle_expired_session() {
        $this->destroy_session();
        $this->initialize(); // Crea una nuova sessione pulita
    }
    
    /**
     * Distrugge la sessione corrente in modo sicuro
     */
    public function destroy_session() {
        // Invalida il cookie di sessione
        if (isset($_COOKIE[session_name()])) {
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => COOKIEPATH,
                    'domain' => COOKIE_DOMAIN,
                    'secure' => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]
            );
        }
        
        // Pulisci l'array della sessione
        $_SESSION = [];
        
        // Distruggi la sessione
        session_destroy();
    }
    
    /**
     * Imposta gli header di sicurezza
     */
    private function set_security_headers() {
        if (!headers_sent()) {
            header('X-Frame-Options: SAMEORIGIN');
            header('X-XSS-Protection: 1; mode=block');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            
            if (is_ssl()) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
            }
        }
    }
    
    /**
     * Ottiene l'ID client corrente
     */
    public function get_client_id() {
        return $_SESSION['client_id'] ?? null;
    }
    
    /**
     * Verifica se la sessione è valida
     */
    public function is_session_valid() {
        return isset($_SESSION['client_id']) && 
               $this->validate_client_fingerprint() && 
               !$this->is_session_expired();
    }
}
