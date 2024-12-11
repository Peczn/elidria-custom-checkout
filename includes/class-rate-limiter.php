<?php
/**
 * Gestione del rate limiting con exponential backoff per la protezione degli endpoint AJAX
 * 
 * Fornisce funzionalità di limitazione delle richieste basata su diversi criteri:
 * - Limite globale per client
 * - Limite per endpoint specifico
 * - Limite per richieste fallite
 * - Sistema di backoff esponenziale
 *
 * @package Elidria
 * @since 1.0.0
 */
class Elidria_Rate_Limiter {
    // Costanti per i limiti
    const GLOBAL_LIMIT_PER_MIN = 60;
    const FAILED_LIMIT_PER_MIN = 10;
    const ENDPOINT_LIMIT_PER_MIN = 30;
    
    // Costanti per exponential backoff
    const MIN_BACKOFF_MS = 100;
    const MAX_BACKOFF_MS = 30000; // 30 secondi
    const BACKOFF_MULTIPLIER = 2;
    
    // Costanti per TTL
    const COUNTER_TTL = 60;      // 1 minuto
    const BACKOFF_TTL = 3600;    // 1 ora
    
    // Costanti per log e limiti
    const LOG_PREFIX = '[Elidria Rate Limit] ';
    const MAX_COUNTER_VALUE = PHP_INT_MAX - 1000; // Previene overflow
    
    private $transient_prefix = 'elidria_ratelimit_';
    private $backoff_prefix = 'elidria_backoff_';
    
    /**
     * Verifica se una richiesta è consentita
     * 
     * @param string $endpoint Nome dell'endpoint da verificare
     * @param bool $is_failed Indica se la richiesta è fallita
     * @throws InvalidArgumentException Se l'endpoint è vuoto o is_failed non è booleano
     * @return bool True se la richiesta è consentita, false altrimenti
     */
    public function check_rate_limit($endpoint, $is_failed = false) {
        if (empty($endpoint)) {
            throw new InvalidArgumentException('Endpoint cannot be empty');
        }
        
        if (!is_bool($is_failed)) {
            throw new InvalidArgumentException('is_failed must be boolean');
        }
        
        $identifier = $this->get_client_identifier();
        
        // Verifica il backoff se ci sono stati fallimenti
        if ($is_failed && !$this->check_backoff($identifier, $endpoint)) {
            $this->send_rate_limit_headers(0, true);
            return false;
        }
        
        // Verifica limiti globali e ottieni rimanenti
        $global_remaining = $this->get_remaining_global_requests($identifier);
        if ($global_remaining <= 0) {
            $this->log_limit_exceeded('global', $identifier);
            $this->send_rate_limit_headers($global_remaining);
            return false;
        }
        
        // Verifica limiti per fallimenti
        if ($is_failed) {
            $failed_remaining = $this->get_remaining_failed_requests($identifier);
            if ($failed_remaining <= 0) {
                $this->log_limit_exceeded('failed', $identifier);
                $this->apply_backoff($identifier, $endpoint);
                $this->send_rate_limit_headers($failed_remaining);
                return false;
            }
        }
        
        // Verifica limiti per endpoint specifico
        $endpoint_remaining = $this->get_remaining_endpoint_requests($identifier, $endpoint);
        if ($endpoint_remaining <= 0) {
            $this->log_limit_exceeded('endpoint', $identifier, $endpoint);
            $this->send_rate_limit_headers($endpoint_remaining);
            return false;
        }
        
        // Incrementa i contatori
        $this->increment_counters($identifier, $endpoint, $is_failed);
        
        // Invia headers con limiti rimanenti
        $this->send_rate_limit_headers(min($global_remaining, $endpoint_remaining));
        
        return true;
    }

    /**
     * Ottiene il numero di richieste globali rimanenti
     * 
     * @param string $identifier Identificatore univoco del client
     * @return int Numero di richieste rimanenti
     */
    private function get_remaining_global_requests($identifier) {
        $key = $this->transient_prefix . 'global_' . $identifier;
        $count = (int)get_transient($key);
        return self::GLOBAL_LIMIT_PER_MIN - $count;
    }

    /**
     * Ottiene il numero di richieste fallite rimanenti
     * 
     * @param string $identifier Identificatore univoco del client
     * @return int Numero di richieste fallite rimanenti
     */
    private function get_remaining_failed_requests($identifier) {
        $key = $this->transient_prefix . 'failed_' . $identifier;
        $count = (int)get_transient($key);
        return self::FAILED_LIMIT_PER_MIN - $count;
    }

    /**
     * Ottiene il numero di richieste endpoint rimanenti
     * 
     * @param string $identifier Identificatore univoco del client
     * @param string $endpoint Nome dell'endpoint
     * @return int Numero di richieste endpoint rimanenti
     */
    private function get_remaining_endpoint_requests($identifier, $endpoint) {
        $key = $this->transient_prefix . $endpoint . '_' . $identifier;
        $count = (int)get_transient($key);
        return self::ENDPOINT_LIMIT_PER_MIN - $count;
    }

    /**
     * Invia gli headers relativi al rate limiting
     * 
     * @param int $remaining Numero di richieste rimanenti
     * @param bool $is_backoff Indica se è attivo il backoff
     */
    private function send_rate_limit_headers($remaining, $is_backoff = false) {
        if (!is_numeric($remaining)) {
            throw new InvalidArgumentException('Remaining requests must be numeric');
        }
        
        if (!headers_sent()) {
            // Headers standard per rate limiting
            header('X-RateLimit-Limit: ' . self::GLOBAL_LIMIT_PER_MIN);
            header('X-RateLimit-Remaining: ' . max(0, $remaining));
            
            if ($remaining <= 0 || $is_backoff) {
                // Calcola quando il limite si resetta (prossimo minuto)
                $reset_time = strtotime('next minute');
                $retry_after = max(1, $reset_time - time());
                
                // Se c'è backoff, usa quello come retry-after
                if ($is_backoff) {
                    $retry_after = ceil(self::MIN_BACKOFF_MS / 1000);
                }
                
                header('Retry-After: ' . $retry_after);
                header('X-RateLimit-Reset: ' . $reset_time);
                
                // Imposta anche lo status code appropriato
                if (!function_exists('http_response_code')) {
                    $protocol = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0';
                    header($protocol . ' 429 Too Many Requests');
                } else {
                    http_response_code(429);
                }
            }
        }
    }
    
    /**
     * Genera un identificatore univoco per il client
     * 
     * @return string Hash univoco per identificare il client
     */
    private function get_client_identifier() {
        $session_token = wp_get_session_token();
        if (empty($session_token)) {
            $session_token = uniqid('session_', true);
        }
        
        $components = [
            $session_token,
            $_SERVER['REMOTE_ADDR'] ?? '',
            get_current_user_id()
        ];
        
        return wp_hash(implode('|', array_filter($components)));
    }
    
    /**
     * Incrementa i contatori per tutti i tipi di limite
     * 
     * @param string $identifier Identificatore univoco del client
     * @param string $endpoint Nome dell'endpoint
     * @param bool $is_failed Indica se la richiesta è fallita
     */
    private function increment_counters($identifier, $endpoint, $is_failed) {
        // Incrementa contatore globale
        $global_key = $this->transient_prefix . 'global_' . $identifier;
        $count = (int)get_transient($global_key);
        if ($count < self::MAX_COUNTER_VALUE) {
            set_transient($global_key, $count + 1, self::COUNTER_TTL);
        }
        
        // Incrementa contatore fallimenti se necessario
        if ($is_failed) {
            $failed_key = $this->transient_prefix . 'failed_' . $identifier;
            $failed_count = (int)get_transient($failed_key);
            if ($failed_count < self::MAX_COUNTER_VALUE) {
                set_transient($failed_key, $failed_count + 1, self::COUNTER_TTL);
            }
        }
        
        // Incrementa contatore endpoint
        $endpoint_key = $this->transient_prefix . $endpoint . '_' . $identifier;
        $endpoint_count = (int)get_transient($endpoint_key);
        if ($endpoint_count < self::MAX_COUNTER_VALUE) {
            set_transient($endpoint_key, $endpoint_count + 1, self::COUNTER_TTL);
        }
    }
    
    /**
     * Verifica se è in corso un backoff
     * 
     * @param string $identifier Identificatore univoco del client
     * @param string $endpoint Nome dell'endpoint
     * @return bool True se non c'è backoff attivo, False altrimenti
     */
    private function check_backoff($identifier, $endpoint) {
        $key = $this->backoff_prefix . $endpoint . '_' . $identifier;
        $backoff_data = get_transient($key);
        
        if (!$backoff_data || !isset($backoff_data['until'])) {
            return true;
        }
        
        $backoff_until = $backoff_data['until'];
        if (!is_numeric($backoff_until)) {
            return true;
        }
        
        return time() >= $backoff_until;
    }
    
    /**
     * Applica exponential backoff
     * 
     * @param string $identifier Identificatore univoco del client
     * @param string $endpoint Nome dell'endpoint
     */
    private function apply_backoff($identifier, $endpoint) {
        $key = $this->backoff_prefix . $endpoint . '_' . $identifier;
        $backoff_data = get_transient($key);
        
        if (!$backoff_data || !isset($backoff_data['wait_ms'])) {
            $wait_ms = self::MIN_BACKOFF_MS;
            $attempts = 1;
        } else {
            $wait_ms = min(
                self::MAX_BACKOFF_MS,
                $backoff_data['wait_ms'] * self::BACKOFF_MULTIPLIER
            );
            $attempts = ($backoff_data['attempts'] ?? 0) + 1;
        }
        
        set_transient($key, [
            'until' => time() + ($wait_ms / 1000),
            'wait_ms' => $wait_ms,
            'attempts' => $attempts
        ], self::BACKOFF_TTL);
    }
    
    /**
     * Registra eventi di limite superato
     * 
     * @param string $type Tipo di limite superato
     * @param string $identifier Identificatore univoco del client
     * @param string $endpoint Nome dell'endpoint (opzionale)
     */
    private function log_limit_exceeded($type, $identifier, $endpoint = '') {
        $log_data = [
            'time' => current_time('mysql'),
            'type' => $type,
            'identifier' => $identifier,
            'endpoint' => $endpoint,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_id' => get_current_user_id(),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? ''
        ];
        
        error_log(
            sprintf(
                self::LOG_PREFIX . '%s limit exceeded - %s',
                ucfirst($type),
                wp_json_encode($log_data)
            )
        );
    }
}