<?php
/**
 * Gestione delle prenotazioni e del controllo stock
 *
 * @package Elidria
 * @since 1.0.0
 */
class Elidria_Stock_Manager {
    private $lock_timeout = 10; // secondi
    private $reservation_timeout = 900; // 15 minuti in secondi
    private $max_deadlock_retries = 3;
    private $deadlock_retry_wait = 100000; // 100ms

    /**
     * Crea la tabella per le prenotazioni stock
     * Mantiene il nome originale del metodo per retrocompatibilità
     */
    public function create_reserved_stock_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Tabella principale per le prenotazioni
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_reserved_stock (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            quantity int(11) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned DEFAULT 0,
            session_id varchar(255) NOT NULL,
            version bigint(20) unsigned NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY session_id (session_id),
            KEY expires_at (expires_at)
        ) $charset_collate");

        // Tabella per il version control dello stock
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_stock_version (
            product_id bigint(20) unsigned NOT NULL,
            version bigint(20) unsigned NOT NULL DEFAULT 1,
            last_updated datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (product_id)
        ) $charset_collate");

        // Tabella per i locks distribuiti
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_stock_locks (
            lock_key varchar(64) NOT NULL,
            lock_value varchar(64) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            PRIMARY KEY (lock_key)
        ) $charset_collate");

        // Tabella per il logging delle operazioni
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wc_stock_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            operation varchar(50) NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            quantity int(11),
            user_id bigint(20) unsigned,
            session_id varchar(255),
            status varchar(20) NOT NULL,
            message text,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY operation (operation),
            KEY product_id (product_id),
            KEY status (status)
        ) $charset_collate");

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    }

    /**
     * Validazione input
     */
    private function validate_input($product_id, $quantity) {
        if (!is_numeric($product_id) || $product_id <= 0) {
            throw new InvalidArgumentException('ID prodotto non valido');
        }
        if (!is_numeric($quantity) || $quantity < 0) {
            throw new InvalidArgumentException('Quantità non valida');
        }
    }

    /**
     * Acquisisce un lock a livello di database
     */
    private function acquire_lock($product_id, $max_retries = 3) {
        global $wpdb;
        
        $lock_key = "stock_lock_" . $product_id;
        $lock_value = bin2hex(random_bytes(16));
        $retry_count = 0;
        
        while ($retry_count < $max_retries) {
            // Query ottimizzata che combina DELETE e INSERT in una singola operazione
            $query = $wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}wc_stock_locks 
                 (lock_key, lock_value, expires_at)
                 SELECT %s, %s, DATE_ADD(NOW(), INTERVAL %d SECOND)
                 WHERE NOT EXISTS (
                     SELECT 1 
                     FROM {$wpdb->prefix}wc_stock_locks 
                     WHERE lock_key = %s 
                     AND expires_at > NOW()
                 )",
                $lock_key,
                $lock_value,
                $this->lock_timeout,
                $lock_key
            );
    
            $result = $wpdb->query($query);
            
            if ($result) {
                $this->log_operation('lock_acquired', $product_id, null, 'success');
                return $lock_value;
            }
            
            $retry_count++;
            if ($retry_count < $max_retries) {
                usleep(mt_rand(100000, 300000)); // Sleep random 100-300ms
                $this->log_operation('lock_retry', $product_id, null, 'warning', 
                    "Tentativo {$retry_count} di {$max_retries}");
            }
        }
        
        $this->log_operation('lock_failed', $product_id, null, 'error', 
            "Impossibile acquisire il lock dopo {$max_retries} tentativi");
        return false;
    }

    /**
     * Rilascia un lock in modo sicuro
     */
    private function release_lock($product_id, $lock_value) {
        global $wpdb;
        
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}wc_stock_locks 
             WHERE lock_key = %s AND lock_value = %s",
            "stock_lock_" . $product_id,
            $lock_value
        ));

        if ($result) {
            $this->log_operation('lock_released', $product_id, null, 'success');
        } else {
            $this->log_operation('lock_release_failed', $product_id, null, 'error');
        }

        return $result;
    }

    /**
     * Ottiene stock con protezione deadlock
     */
    private function get_stock_with_deadlock_protection($product_id) {
        global $wpdb;
        
        for ($i = 0; $i < $this->max_deadlock_retries; $i++) {
            try {
                $stock_data = $wpdb->get_row($wpdb->prepare(
                    "SELECT pm.meta_value as stock_quantity, p.post_status
                     FROM {$wpdb->postmeta} pm
                     JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.post_id = %d 
                     AND pm.meta_key = '_stock'
                     AND p.post_type = 'product'
                     FOR UPDATE",
                    $product_id
                ));

                if ($i > 0) {
                    $this->log_operation('deadlock_resolved', $product_id, null, 'warning', 
                        "Risolto dopo {$i} tentativi");
                }
                
                return $stock_data;
            } catch (Exception $e) {
                if ($i < $this->max_deadlock_retries - 1) {
                    $this->log_operation('deadlock_retry', $product_id, null, 'warning', 
                        "Tentativo {$i} di {$this->max_deadlock_retries}");
                    usleep($this->deadlock_retry_wait * pow(2, $i));
                    continue;
                }
                $this->log_operation('deadlock_failed', $product_id, null, 'error', 
                    $e->getMessage());
                throw $e;
            }
        }
    }

    /**
     * Verifica e prenota lo stock con gestione delle race condition
     */
    public function verify_and_reserve_stock($product_id, $quantity) {
        try {
            $this->validate_input($product_id, $quantity);
        } catch (InvalidArgumentException $e) {
            $this->log_operation('validation_failed', $product_id, $quantity, 'error', 
                $e->getMessage());
            throw $e;
        }

        global $wpdb;
        
        $lock_value = $this->acquire_lock($product_id);
        if (!$lock_value) {
            throw new Exception('Sistema momentaneamente occupato. Riprova.');
        }

        try {
            $wpdb->query('START TRANSACTION');

            // 1. Ottieni e incrementa la versione dello stock
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}wc_stock_version (product_id, version)
                 VALUES (%d, 1)
                 ON DUPLICATE KEY UPDATE 
                 version = version + 1,
                 last_updated = NOW()",
                $product_id
            ));

            $current_version = $wpdb->get_var($wpdb->prepare(
                "SELECT version 
                 FROM {$wpdb->prefix}wc_stock_version 
                 WHERE product_id = %d",
                $product_id
            ));

            // 2. Ottieni stock attuale con protezione deadlock
            $stock_data = $this->get_stock_with_deadlock_protection($product_id);

            if (!$stock_data || $stock_data->post_status !== 'publish') {
                throw new Exception('Prodotto non disponibile');
            }

            // 3. Calcola stock disponibile
            $current_stock = (int)$stock_data->stock_quantity;
            $reserved_quantity = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(quantity), 0)
                 FROM {$wpdb->prefix}wc_reserved_stock
                 WHERE product_id = %d
                 AND expires_at > NOW()
                 AND order_id = 0",
                $product_id
            ));

            $available_stock = $current_stock - (int)$reserved_quantity;

            if ($available_stock < $quantity) {
                $this->log_operation('insufficient_stock', $product_id, $quantity, 'warning',
                    "Richiesti: {$quantity}, Disponibili: {$available_stock}");
                $wpdb->query('ROLLBACK');
                return [
                    'success' => false,
                    'error' => sprintf(
                        __('Disponibili solo %d unità del prodotto', 'woocommerce'),
                        $available_stock
                    ),
                    'available_quantity' => $available_stock
                ];
            }

            // 4. Crea la prenotazione
            $wpdb->insert(
                $wpdb->prefix . 'wc_reserved_stock',
                [
                    'product_id' => $product_id,
                    'quantity' => $quantity,
                    'user_id' => get_current_user_id(),
                    'session_id' => WC()->session->get_customer_id(),
                    'version' => $current_version,
                    'expires_at' => date('Y-m-d H:i:s', time() + $this->reservation_timeout)
                ],
                ['%d', '%d', '%d', '%s', '%d', '%s']
            );

            $reservation_id = $wpdb->insert_id;

            $wpdb->query('COMMIT');

            $this->log_operation('reservation_success', $product_id, $quantity, 'success',
                "Prenotazione ID: {$reservation_id}");

            return [
                'success' => true,
                'reservation_id' => $reservation_id,
                'expires_at' => time() + $this->reservation_timeout
            ];

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $this->log_operation('reservation_failed', $product_id, $quantity, 'error',
                $e->getMessage());
            throw $e;
        } finally {
            $this->release_lock($product_id, $lock_value);
        }
    }

    /**
     * Log delle operazioni
     */
    private function log_operation($operation, $product_id, $quantity = null, $status = 'success', $message = '') {
        global $wpdb;
        
        try {
            $wpdb->insert(
                $wpdb->prefix . 'wc_stock_log',
                [
                    'operation' => $operation,
                    'product_id' => $product_id,
                    'quantity' => $quantity,
                    'user_id' => get_current_user_id(),
                    'session_id' => WC()->session ? WC()->session->get_customer_id() : '',
                    'status' => $status,
                    'message' => $message,
                    'created_at' => current_time('mysql', true)
                ],
                ['%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s']
            );
        } catch (Exception $e) {
            error_log(sprintf(
                '[Stock Manager] Log error - Operation: %s, Product: %d, Error: %s',
                $operation,
                $product_id,
                $e->getMessage()
            ));
        }
    }

    /**
     * Conferma una prenotazione quando l'ordine viene completato
     */
    public function confirm_reservation($reservation_id, $order_id) {
        global $wpdb;
        
        $reservation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wc_reserved_stock WHERE id = %d",
            $reservation_id
        ));
        
        if (!$reservation) {
            $this->log_operation('confirm_not_found', 0, null, 'error',
                "Prenotazione ID: {$reservation_id} non trovata");
            throw new Exception('Prenotazione non trovata');
        }

        $lock_value = $this->acquire_lock($reservation->product_id);
        if (!$lock_value) {
            throw new Exception('Impossibile acquisire il lock');
        }

        try {
            $wpdb->query('START TRANSACTION');

            $updated = $wpdb->update(
                $wpdb->prefix . 'wc_reserved_stock',
                [
                    'order_id' => $order_id,
                    'expires_at' => date('Y-m-d H:i:s', time() + 86400)
                ],
                ['id' => $reservation_id],
                ['%d', '%s'],
                ['%d']
            );

            if ($updated === false) {
                throw new Exception('Errore durante la conferma della prenotazione');
            }

            // Aggiorna lo stock
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} 
                 SET meta_value = meta_value - %d 
                 WHERE post_id = %d AND meta_key = '_stock'",
                $reservation->quantity,
                $reservation->product_id
            ));

            $wpdb->query('COMMIT');
            
            $this->log_operation('confirm_success', $reservation->product_id, 
                $reservation->quantity, 'success',
                "Ordine ID: {$order_id}, Prenotazione ID: {$reservation_id}"
            );
            
            return true;

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $this->log_operation('confirm_failed', $reservation->product_id, 
                $reservation->quantity, 'error',
                "Ordine ID: {$order_id}, Errore: " . $e->getMessage()
            );
            throw $e;
        } finally {
            $this->release_lock($reservation->product_id, $lock_value);
        }
    }

    /**
     * Cancella una prenotazione specifica
     */
    public function cancel_reservation($reservation_id) {
        global $wpdb;
        
        try {
            $reservation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wc_reserved_stock WHERE id = %d",
                $reservation_id
            ));
            
            if (!$reservation) {
                $this->log_operation('cancel_not_found', 0, null, 'error',
                    "Prenotazione ID: {$reservation_id} non trovata");
                throw new Exception('Prenotazione non trovata');
            }
            
            $deleted = $wpdb->delete(
                $wpdb->prefix . 'wc_reserved_stock',
                ['id' => $reservation_id],
                ['%d']
            );
            
            if ($deleted === false) {
                throw new Exception('Errore durante la cancellazione della prenotazione');
            }
            
            $this->log_operation('cancel_success', $reservation->product_id, 
                $reservation->quantity, 'success',
                "Prenotazione ID: {$reservation_id}"
            );
            
            return true;
            
        } catch (Exception $e) {
            $this->log_operation('cancel_failed', 
                $reservation->product_id ?? 0, 
                $reservation->quantity ?? 0, 
                'error',
                "Prenotazione ID: {$reservation_id}, Errore: " . $e->getMessage()
            );
            throw $e;
        }
    }

    /**
     * Pulisce tutte le prenotazioni scadute
     */
    public function cleanup_expired_reservations() {
        global $wpdb;
        
        $lock_value = $this->acquire_lock('cleanup');
        if (!$lock_value) {
            $this->log_operation('cleanup_lock_failed', 0, null, 'error',
                'Impossibile acquisire il lock per il cleanup');
            return false;
        }

        try {
            $wpdb->query('START TRANSACTION');

            // Prima ottieni le prenotazioni scadute
            $expired_reservations = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}wc_reserved_stock 
                 WHERE expires_at <= NOW() 
                 AND order_id = 0
                 FOR UPDATE"
            );

            if ($expired_reservations) {
                foreach ($expired_reservations as $reservation) {
                    $this->log_operation('cleanup_item', $reservation->product_id,
                        $reservation->quantity, 'info',
                        "Pulizia prenotazione ID: {$reservation->id}"
                    );
                }

                // Elimina le prenotazioni
                $deleted = $wpdb->query(
                    "DELETE FROM {$wpdb->prefix}wc_reserved_stock 
                     WHERE expires_at <= NOW() 
                     AND order_id = 0"
                );

                $this->log_operation('cleanup_success', 0, null, 'success',
                    "Eliminate {$deleted} prenotazioni scadute"
                );
            }

            $wpdb->query('COMMIT');
            return true;

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $this->log_operation('cleanup_failed', 0, null, 'error',
                "Errore: " . $e->getMessage()
            );
            return false;
        } finally {
            $this->release_lock('cleanup', $lock_value);
        }
    }

    /**
     * Ottiene tutte le prenotazioni attive per una sessione
     */
    public function get_session_reservations($session_id = null) {
        global $wpdb;
        
        if ($session_id === null) {
            $session_id = WC()->session->get_customer_id();
        }
        
        $reservations = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wc_reserved_stock
             WHERE session_id = %s
             AND expires_at > NOW()
             AND order_id = 0",
            $session_id
        ));
        
        if ($reservations) {
            $this->log_operation('get_session_reservations', 0, null, 'info',
                "Trovate " . count($reservations) . " prenotazioni attive per la sessione: {$session_id}"
            );
        }
        
        return $reservations;
    }
}