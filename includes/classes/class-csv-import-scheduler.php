<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direkten Zugriff verhindern
}

/**
 * CSV Import Scheduler Klasse
 * Version 8.4 - Korrigierte Dependencies und robuste Error-Handling
 * Verwaltet geplante CSV-Imports und automatische Wiederholungen
 * @since 5.1
 */
class CSV_Import_Scheduler {
    
    // Hook-Namen für geplante Events
    const HOOK_SCHEDULED_IMPORT = 'csv_import_scheduled';
    const HOOK_DAILY_CLEANUP = 'csv_import_daily_cleanup';
    const HOOK_WEEKLY_MAINTENANCE = 'csv_import_weekly_maintenance';
    
    // Verfügbare Zeitintervalle (korrigiert für WordPress-Kompatibilität)
    const INTERVALS = [
        'hourly' => 'Stündlich',
        'twicedaily' => 'Zweimal täglich', 
        'daily' => 'Täglich',
        'weekly' => 'Wöchentlich'
    ];
    
    private static $instance = null;
    private static $dependencies_checked = false;
    private static $initialization_errors = [];
    
    /**
     * Singleton Pattern
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Sichere Initialisierung mit Dependency-Checks.
     */
    public static function init() {
        $instance = self::instance();
        
        // Dependency-Check nur einmal durchführen
        if ( ! self::$dependencies_checked ) {
            if ( ! $instance->check_dependencies() ) {
                return false; // Initialisierung abbrechen
            }
            self::$dependencies_checked = true;
        }
        
        $instance->setup_hooks();
        $instance->register_custom_intervals();
        
        // Scheduler-Health-Check beim Start
        $instance->perform_startup_health_check();
        
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'info', 'CSV Import Scheduler erfolgreich initialisiert', [
                'available_intervals' => array_keys( self::INTERVALS ),
                'wp_cron_enabled' => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
                'next_scheduled' => wp_next_scheduled( self::HOOK_SCHEDULED_IMPORT )
            ]);
        }
        
        return true;
    }
    
    /**
     * Prüft alle erforderlichen Dependencies.
     */
    private function check_dependencies() {
        $required_functions = [
            'csv_import_get_config',
            'csv_import_start_import', 
            'csv_import_validate_config',
            'csv_import_is_import_running',
            'csv_import_log'
        ];
        
        $missing_functions = [];
        foreach ( $required_functions as $func ) {
            if ( ! function_exists( $func ) ) {
                $missing_functions[] = $func;
            }
        }
        
        if ( ! empty( $missing_functions ) ) {
            self::$initialization_errors[] = 'Fehlende Funktionen: ' . implode( ', ', $missing_functions );
            error_log( 'CSV Import Scheduler: Dependencies fehlen - ' . implode( ', ', $missing_functions ) );
            return false;
        }
        
        // Prüfe WordPress Cron-System
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            self::$initialization_errors[] = 'WordPress Cron ist deaktiviert - externer Cron erforderlich';
        }
        
        return true;
    }
    
    /**
     * Startup Health Check für den Scheduler.
     */
    private function perform_startup_health_check() {
        // Prüfe auf verwaiste oder hängende Scheduler-Events
        $next_run = wp_next_scheduled( self::HOOK_SCHEDULED_IMPORT );
        if ( $next_run && $next_run < ( time() - 3600 ) ) {
            // Event ist überfällig (älter als 1 Stunde)
            $this->log_scheduler_event( 'warning', 'Überfälliges Scheduler-Event gefunden - wird zurückgesetzt' );
            wp_clear_scheduled_hook( self::HOOK_SCHEDULED_IMPORT );
        }
        
        // Prüfe Scheduler-Optionen auf Konsistenz
        $frequency = get_option( 'csv_import_scheduled_frequency', '' );
        $source = get_option( 'csv_import_scheduled_source', '' );
        
        if ( ! empty( $frequency ) && ! empty( $source ) && ! $next_run ) {
            // Optionen vorhanden aber kein Event geplant
            $this->log_scheduler_event( 'warning', 'Scheduler-Konfiguration inkonsistent - Einstellungen vs. geplante Events' );
        }
    }
    
    /**
     * WordPress Hooks registrieren
     */
    private function setup_hooks() {
        // Geplante Import-Events
        add_action( self::HOOK_SCHEDULED_IMPORT, [ $this, 'execute_scheduled_import' ], 10, 2 );
        
        // Wartung und Bereinigung
        add_action( self::HOOK_DAILY_CLEANUP, [ $this, 'daily_cleanup' ] );
        add_action( self::HOOK_WEEKLY_MAINTENANCE, [ $this, 'weekly_maintenance' ] );
        
        // Plugin-Deaktivierung: Alle geplanten Events löschen
        register_deactivation_hook( CSV_IMPORT_PRO_PATH . 'csv-import-pro.php', [ __CLASS__, 'unschedule_all' ] );
        
        // WordPress Shutdown Hook für Cleanup
        add_action( 'wp_loaded', [ $this, 'check_and_schedule_maintenance' ] );
    }
    
    /**
     * Benutzerdefinierte Zeitintervalle registrieren
     */
    private function register_custom_intervals() {
        add_filter( 'cron_schedules', [ $this, 'add_custom_cron_intervals' ] );
    }
    
    /**
     * Fügt benutzerdefinierte Cron-Intervalle hinzu
     */
    public function add_custom_cron_intervals( $schedules ) {
        // Monatlich (falls noch nicht vorhanden)
        if ( ! isset( $schedules['monthly'] ) ) {
            $schedules['monthly'] = [
                'interval' => 30 * 24 * 60 * 60, // 30 Tage
                'display'  => __( 'Einmal im Monat', 'csv-import' )
            ];
        }
        
        // Alle 15 Minuten (für Tests)
        if ( ! isset( $schedules['fifteen_minutes'] ) ) {
            $schedules['fifteen_minutes'] = [
                'interval' => 15 * 60, // 15 Minuten
                'display'  => __( 'Alle 15 Minuten', 'csv-import' )
            ];
        }
        
        // Alle 30 Minuten
        if ( ! isset( $schedules['thirty_minutes'] ) ) {
            $schedules['thirty_minutes'] = [
                'interval' => 30 * 60, // 30 Minuten
                'display'  => __( 'Alle 30 Minuten', 'csv-import' )
            ];
        }
        
        return $schedules;
    }
    
    /**
     * Stellt sicher, dass Wartungs-Events geplant sind.
     */
    public function check_and_schedule_maintenance() {
        // Tägliche Bereinigung
        if ( ! wp_next_scheduled( self::HOOK_DAILY_CLEANUP ) ) {
            wp_schedule_event( time(), 'daily', self::HOOK_DAILY_CLEANUP );
        }
        
        // Wöchentliche Wartung
        if ( ! wp_next_scheduled( self::HOOK_WEEKLY_MAINTENANCE ) ) {
            wp_schedule_event( time(), 'weekly', self::HOOK_WEEKLY_MAINTENANCE );
        }
    }
    
    // ===================================================================
    // ÖFFENTLICHE METHODEN FÜR SCHEDULING
    // ===================================================================
    
    /**
     * Prüft ob ein geplanter Import aktiv ist
     */
    public static function is_scheduled( $hook_name = null ) {
        if ( $hook_name === null ) {
            $hook_name = self::HOOK_SCHEDULED_IMPORT;
        }
        
        $timestamp = wp_next_scheduled( $hook_name );
        return $timestamp !== false;
    }
    
    /**
     * Holt das nächste geplante Event
     */
    public static function get_next_scheduled( $hook_name = null ) {
        if ( $hook_name === null ) {
            $hook_name = self::HOOK_SCHEDULED_IMPORT;
        }
        
        return wp_next_scheduled( $hook_name );
    }
    
    /**
     * Plant einen wiederkehrenden Import mit verbesserter Fehlerbehandlung
     */
    public static function schedule_import( $frequency, $source, $options = [] ) {
        try {
            // Dependency-Check vor Scheduling
            if ( ! function_exists( 'csv_import_get_config' ) || ! function_exists( 'csv_import_validate_config' ) ) {
                throw new Exception( 'Import-System nicht verfügbar - Core-Funktionen fehlen' );
            }
            
            // Bestehende Planung löschen
            self::unschedule_import();
            
            // Validierung
            if ( ! in_array( $frequency, array_keys( self::INTERVALS ) ) ) {
                throw new Exception( 'Ungültige Frequenz: ' . $frequency . '. Erlaubt: ' . implode( ', ', array_keys( self::INTERVALS ) ) );
            }
            
            if ( ! in_array( $source, ['dropbox', 'local'] ) ) {
                throw new Exception( 'Ungültige Quelle: ' . $source . '. Erlaubt: dropbox, local' );
            }
            
            // Konfiguration validieren
            $config = csv_import_get_config();
            $validation = csv_import_validate_config( $config );
            
            if ( ! $validation['valid'] ) {
                throw new Exception( 'Konfiguration ungültig: ' . implode( ', ', $validation['errors'] ) );
            }
            
            // Source-spezifische Validierung
            if ( $source === 'dropbox' && ! $validation['dropbox_ready'] ) {
                throw new Exception( 'Dropbox-Quelle nicht verfügbar oder nicht konfiguriert' );
            }
            
            if ( $source === 'local' && ! $validation['local_ready'] ) {
                throw new Exception( 'Lokale Quelle nicht verfügbar oder nicht lesbar' );
            }
            
            // Start-Zeit berechnen (nächste volle Stunde)
            $start_time = strtotime( '+1 hour', current_time( 'timestamp' ) );
            $start_time = strtotime( date( 'Y-m-d H:00:00', $start_time ) );
            
            // WordPress Cron-Check
            if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
                // Warnung aber nicht blockieren (externer Cron könnte eingerichtet sein)
                if ( function_exists( 'csv_import_log' ) ) {
                    csv_import_log( 'warning', 'WordPress Cron deaktiviert - externer Cron erforderlich für Scheduling' );
                }
            }
            
            // Event planen
            $result = wp_schedule_event( 
                $start_time, 
                $frequency, 
                self::HOOK_SCHEDULED_IMPORT,
                [ $source, $options ]
            );
            
            if ( $result === false ) {
                throw new Exception( 'WordPress konnte das Event nicht planen - möglicherweise Cron-Problem' );
            }
            
            // Einstellungen speichern
            update_option( 'csv_import_scheduled_frequency', $frequency );
            update_option( 'csv_import_scheduled_source', $source );
            update_option( 'csv_import_scheduled_options', $options );
            update_option( 'csv_import_scheduled_start', $start_time );
            update_option( 'csv_import_scheduled_created', current_time( 'mysql' ) );
            
            // Logging
            if ( function_exists( 'csv_import_log' ) ) {
                csv_import_log( 'info', "Geplanter Import aktiviert: {$frequency} für Quelle {$source}", [
                    'start_time' => date( 'Y-m-d H:i:s', $start_time ),
                    'next_run_human' => human_time_diff( time(), $start_time ),
                    'options' => $options,
                    'config_valid' => $validation['valid']
                ]);
            }
            
            return true;
            
        } catch ( Exception $e ) {
            if ( function_exists( 'csv_import_log' ) ) {
                csv_import_log( 'error', 'Fehler beim Planen des Imports: ' . $e->getMessage(), [
                    'frequency' => $frequency,
                    'source' => $source,
                    'options' => $options,
                    'trace' => $e->getTraceAsString()
                ]);
            }
            
            return new WP_Error( 'scheduling_failed', $e->getMessage() );
        }
    }
    
    /**
     * Stoppt den geplanten Import
     */
    public static function unschedule_import() {
        $timestamp = wp_next_scheduled( self::HOOK_SCHEDULED_IMPORT );
        
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK_SCHEDULED_IMPORT );
        }
        
        // Alle Events dieses Typs löschen (Sicherheit)
        wp_clear_scheduled_hook( self::HOOK_SCHEDULED_IMPORT );
        
        // Einstellungen löschen
        delete_option( 'csv_import_scheduled_frequency' );
        delete_option( 'csv_import_scheduled_source' );
        delete_option( 'csv_import_scheduled_options' );
        delete_option( 'csv_import_scheduled_start' );
        delete_option( 'csv_import_scheduled_created' );
        
        // Logging
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'info', 'Geplanter Import deaktiviert' );
        }
        
        return true;
    }
    
    /**
     * Stoppt ALLE geplanten Events des Plugins
     */
    public static function unschedule_all() {
        $hooks = [
            self::HOOK_SCHEDULED_IMPORT,
            self::HOOK_DAILY_CLEANUP,
            self::HOOK_WEEKLY_MAINTENANCE
        ];
        
        foreach ( $hooks as $hook ) {
            wp_clear_scheduled_hook( $hook );
        }
        
        // Scheduler-spezifische Optionen löschen
        $options_to_delete = [
            'csv_import_scheduled_frequency',
            'csv_import_scheduled_source', 
            'csv_import_scheduled_options',
            'csv_import_scheduled_start',
            'csv_import_scheduled_created',
            'csv_import_scheduler_stats'
        ];
        
        foreach ( $options_to_delete as $option ) {
            delete_option( $option );
        }
        
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'info', 'Alle Scheduler-Events und -Optionen entfernt' );
        }
    }
    
    // ===================================================================
    // EVENT HANDLER
    // ===================================================================
    
    /**
     * Führt einen geplanten Import aus mit umfassendem Error-Handling
     */
    public function execute_scheduled_import( $source = 'local', $options = [] ) {
        // Mehrfach-Ausführung verhindern
        $lock_key = 'csv_import_scheduled_lock';
        if ( get_transient( $lock_key ) ) {
            $this->log_scheduler_event( 'warning', 'Geplanter Import übersprungen - bereits ein Import läuft (Lock aktiv)' );
            return;
        }
        
        // Lock setzen (für 2 Stunden)
        set_transient( $lock_key, time(), 2 * HOUR_IN_SECONDS );
        
        try {
            // Dependency Re-Check vor Import
            if ( ! function_exists( 'csv_import_start_import' ) ) {
                throw new Exception( 'Import-Funktion nicht verfügbar - Core-Functions nicht geladen' );
            }
            
            if ( ! function_exists( 'csv_import_is_import_running' ) ) {
                throw new Exception( 'Import-Status-Funktion nicht verfügbar' );
            }
            
            // Bereits laufenden Import prüfen
            if ( csv_import_is_import_running() ) {
                throw new Exception( 'Ein manueller Import läuft bereits - geplanter Import übersprungen' );
            }
            
            $this->log_scheduler_event( 'info', "Geplanter Import gestartet für Quelle: {$source}" );
            
            // Import-Statistiken aktualisieren
            $this->update_scheduler_stats( 'started' );
            
            // Konfiguration laden und validieren
            $config = csv_import_get_config();
            $validation = csv_import_validate_config( $config );
            
            if ( ! $validation['valid'] ) {
                throw new Exception( 'Konfiguration ungültig: ' . implode( ', ', $validation['errors'] ) );
            }
            
            // System-Ressourcen prüfen
            $this->check_system_resources();
            
            // Import durchführen mit erweiterten Optionen
            $import_options = array_merge( [
                'scheduled' => true,
                'session_prefix' => 'scheduled_' . date( 'Y-m-d_H-i' ),
                'source' => $source
            ], $options );
            
            $result = csv_import_start_import( $source, $config );
            
            if ( $result['success'] ) {
                $this->log_scheduler_event( 'info', 
                    "Geplanter Import erfolgreich: {$result['processed']} von {$result['total']} Einträgen verarbeitet",
                    $result
                );
                $this->update_scheduler_stats( 'completed', $result );
                
                // Erfolgs-Benachrichtigung
                do_action( 'csv_import_scheduled_success', $result, $source );
                
            } else {
                throw new Exception( $result['message'] ?? 'Import fehlgeschlagen ohne spezifische Fehlermeldung' );
            }
            
        } catch ( Exception $e ) {
            $error_message = 'Geplanter Import fehlgeschlagen: ' . $e->getMessage();
            
            $this->log_scheduler_event( 'error', $error_message, [
                'source' => $source,
                'options' => $options,
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->update_scheduler_stats( 'failed', [ 'error' => $e->getMessage() ] );
            
            // Bei kritischen Fehlern Scheduling pausieren
            if ( $this->is_critical_error( $e ) ) {
                $this->log_scheduler_event( 'critical', 'Kritischer Fehler - Scheduling wird deaktiviert' );
                self::unschedule_import();
            }
            
            // Fehler-Benachrichtigung
            do_action( 'csv_import_scheduled_failure', $e->getMessage(), $source );
            
        } finally {
            // Lock entfernen
            delete_transient( $lock_key );
        }
    }
    
    /**
     * Prüft ob ein Fehler kritisch ist (Scheduling sollte gestoppt werden)
     */
    private function is_critical_error( Exception $e ) {
        $critical_keywords = [
            'Core-Functions nicht geladen',
            'Konfiguration ungültig',
            'Speicher',
            'Fatal error',
            'Class not found'
        ];
        
        $message = $e->getMessage();
        foreach ( $critical_keywords as $keyword ) {
            if ( stripos( $message, $keyword ) !== false ) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Prüft System-Ressourcen vor Import
     */
    private function check_system_resources() {
        // Memory Check
        $memory_limit = ini_get( 'memory_limit' );
        if ( $memory_limit && $memory_limit !== '-1' ) {
            $memory_bytes = wp_convert_hr_to_bytes( $memory_limit );
            $current_usage = memory_get_usage( true );
            
            if ( $current_usage > ( $memory_bytes * 0.8 ) ) {
                throw new Exception( 'Unzureichender Speicher für Import (80% des Limits erreicht)' );
            }
        }
        
        // Disk Space Check
        $free_space = disk_free_space( ABSPATH );
        if ( $free_space && $free_space < ( 100 * 1024 * 1024 ) ) { // 100MB minimum
            throw new Exception( 'Unzureichender Festplattenspeicher (weniger als 100MB frei)' );
        }
        
        // Time Limit Check
        $time_limit = ini_get( 'max_execution_time' );
        if ( $time_limit > 0 && $time_limit < 300 ) { // 5 Minuten minimum
            $this->log_scheduler_event( 'warning', 'Geringes PHP Time Limit für geplanten Import: ' . $time_limit . 's' );
        }
    }
    
    /**
     * Tägliche Bereinigung
     */
    public function daily_cleanup() {
        $this->log_scheduler_event( 'debug', 'Tägliche Scheduler-Bereinigung gestartet' );
        
        // Verwaiste Scheduler-Optionen bereinigen
        $this->cleanup_orphaned_options();
        
        // Scheduler-Statistiken bereinigen (älter als 30 Tage)
        $this->cleanup_old_stats();
        
        // Abgelaufene Transients bereinigen
        $this->cleanup_expired_transients();
        
        $this->log_scheduler_event( 'debug', 'Tägliche Scheduler-Bereinigung abgeschlossen' );
    }
    
    /**
     * Wöchentliche Wartung
     */
    public function weekly_maintenance() {
        $this->log_scheduler_event( 'debug', 'Wöchentliche Scheduler-Wartung gestartet' );
        
        // Scheduler-Health-Check
        $this->perform_health_check();
        
        // Statistiken optimieren
        $this->optimize_stats();
        
        // Cron-System-Check
        $this->check_cron_system();
        
        $this->log_scheduler_event( 'debug', 'Wöchentliche Scheduler-Wartung abgeschlossen' );
    }
    
    // ===================================================================
    // HILFSMETHODEN
    // ===================================================================
    
    /**
     * Scheduler-spezifisches Logging mit Context
     */
    private function log_scheduler_event( $level, $message, $context = [] ) {
        if ( function_exists( 'csv_import_log' ) ) {
            $scheduler_context = array_merge( [
                'component' => 'scheduler',
                'wp_cron_enabled' => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
                'next_scheduled' => wp_next_scheduled( self::HOOK_SCHEDULED_IMPORT )
            ], $context );
            
            csv_import_log( $level, '[Scheduler] ' . $message, $scheduler_context );
        } else {
            error_log( "CSV Import Scheduler [{$level}]: {$message}" );
        }
    }
    
    /**
     * Aktualisiert Scheduler-Statistiken
     */
    private function update_scheduler_stats( $event_type, $data = [] ) {
        $stats = get_option( 'csv_import_scheduler_stats', [] );
        
        $today = current_time( 'Y-m-d' );
        if ( ! isset( $stats[ $today ] ) ) {
            $stats[ $today ] = [
                'started' => 0,
                'completed' => 0,
                'failed' => 0,
                'processed_total' => 0,
                'errors' => []
            ];
        }
        
        $stats[ $today ][ $event_type ]++;
        
        // Spezifische Daten je Event-Typ
        switch ( $event_type ) {
            case 'completed':
                if ( isset( $data['processed'] ) ) {
                    $stats[ $today ]['processed_total'] += (int) $data['processed'];
                }
                break;
            case 'failed':
                if ( isset( $data['error'] ) ) {
                    $stats[ $today ]['errors'][] = [
                        'time' => current_time( 'H:i:s' ),
                        'error' => substr( $data['error'], 0, 100 ) // Begrenzt auf 100 Zeichen
                    ];
                }
                break;
        }
        
        // Nur die letzten 30 Tage behalten
        $cutoff_date = date( 'Y-m-d', strtotime( '-30 days' ) );
        foreach ( $stats as $date => $stat ) {
            if ( $date < $cutoff_date ) {
                unset( $stats[ $date ] );
            }
        }
        
        update_option( 'csv_import_scheduler_stats', $stats );
    }
    
    /**
     * Bereinigt verwaiste Scheduler-Optionen
     */
    private function cleanup_orphaned_options() {
        global $wpdb;
        
        // Suche nach veralteten Scheduler-Optionen
        $orphaned_options = $wpdb->get_results(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE 'csv_import_scheduled_%_old' 
             OR option_name LIKE '%_csv_import_temp_%'
             OR option_name LIKE 'csv_import_scheduler_temp_%'"
        );
        
        $deleted_count = 0;
        foreach ( $orphaned_options as $option ) {
            delete_option( $option->option_name );
            $deleted_count++;
        }
        
        if ( $deleted_count > 0 ) {
            $this->log_scheduler_event( 'debug', "Bereinigt: {$deleted_count} verwaiste Scheduler-Optionen" );
        }
    }
    
    /**
     * Bereinigt alte Statistiken
     */
    private function cleanup_old_stats() {
        $stats = get_option( 'csv_import_scheduler_stats', [] );
        $cutoff_date = date( 'Y-m-d', strtotime( '-30 days' ) );
        $cleaned = false;
        
        foreach ( $stats as $date => $stat ) {
            if ( $date < $cutoff_date ) {
                unset( $stats[ $date ] );
                $cleaned = true;
            }
        }
        
        if ( $cleaned ) {
            update_option( 'csv_import_scheduler_stats', $stats );
            $this->log_scheduler_event( 'debug', 'Alte Scheduler-Statistiken bereinigt' );
        }
    }
    
    /**
     * Bereinigt abgelaufene Transients
     */
    private function cleanup_expired_transients() {
        global $wpdb;
        
        // Lösche abgelaufene CSV-Import-Transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_timeout_csv_import_%' 
             AND option_value < UNIX_TIMESTAMP()"
        );
        
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_csv_import_%' 
             AND option_name NOT IN (
                 SELECT REPLACE(option_name, '_transient_timeout_', '_transient_') 
                 FROM {$wpdb->options} 
                 WHERE option_name LIKE '_transient_timeout_csv_import_%'
             )"
        );
    }
    
    /**
     * Führt Scheduler-Health-Check durch
     */
    private function perform_health_check() {
        $issues = [];
        
        // Prüfen ob geplante Events korrekt registriert sind
        if ( self::is_scheduled() ) {
            $next_run = self::get_next_scheduled();
            if ( $next_run < time() - 3600 ) { // Mehr als 1 Stunde überfällig
                $issues[] = 'Geplanter Import ist überfällig';
                
                // Automatische Reparatur
                $this->log_scheduler_event( 'warning', 'Repariere überfälliges Scheduler-Event' );
                wp_clear_scheduled_hook( self::HOOK_SCHEDULED_IMPORT );
            }
        }
        
        // Prüfen ob WordPress Cron funktioniert
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            $issues[] = 'WordPress Cron ist deaktiviert - externe Cron-Jobs erforderlich';
        }
        
        // Prüfe Scheduler-Optionen-Konsistenz
        $frequency = get_option( 'csv_import_scheduled_frequency', '' );
        $source = get_option( 'csv_import_scheduled_source', '' );
        $is_scheduled = self::is_scheduled();
        
        if ( ( ! empty( $frequency ) && ! empty( $source ) ) && ! $is_scheduled ) {
            $issues[] = 'Scheduler-Konfiguration inkonsistent - Optionen vorhanden aber kein Event geplant';
        } elseif ( ( empty( $frequency ) || empty( $source ) ) && $is_scheduled ) {
            $issues[] = 'Scheduler-Konfiguration inkonsistent - Event geplant aber Optionen fehlen';
        }
        
        // Health-Check-Ergebnisse loggen
        if ( ! empty( $issues ) ) {
            $this->log_scheduler_event( 'warning', 
                'Scheduler Health-Check Probleme gefunden', 
                [ 'issues' => $issues ] 
            );
        } else {
            $this->log_scheduler_event( 'debug', 'Scheduler Health-Check erfolgreich' );
        }
    }
    
    /**
     * Prüft das WordPress Cron-System
     */
    private function check_cron_system() {
        // Teste WordPress Cron durch Planen eines Test-Events
        $test_hook = 'csv_import_cron_test';
        $test_time = time() + 60; // In 1 Minute
        
        // Test-Event planen
        $scheduled = wp_schedule_single_event( $test_time, $test_hook );
        
        if ( $scheduled ) {
            // Event wurde geplant - prüfe ob es in der Cron-Liste steht
            $cron_array = _get_cron_array();
            $test_found = false;
            
            foreach ( $cron_array as $timestamp => $cron ) {
                if ( isset( $cron[ $test_hook ] ) ) {
                    $test_found = true;
                    break;
                }
            }
            
            if ( $test_found ) {
                $this->log_scheduler_event( 'debug', 'WordPress Cron-System funktioniert korrekt' );
                // Test-Event wieder entfernen
                wp_unschedule_event( $test_time, $test_hook );
            } else {
                $this->log_scheduler_event( 'warning', 'WordPress Cron-System: Event geplant aber nicht in Cron-Array gefunden' );
            }
        } else {
            $this->log_scheduler_event( 'error', 'WordPress Cron-System: Kann keine Events planen' );
        }
    }
    
    /**
     * Optimiert Scheduler-Statistiken
     */
    private function optimize_stats() {
        $stats = get_option( 'csv_import_scheduler_stats', [] );
        
        if ( count( $stats ) > 60 ) { // Mehr als 60 Tage
            $stats = array_slice( $stats, -30, null, true ); // Nur die letzten 30 behalten
            update_option( 'csv_import_scheduler_stats', $stats );
            $this->log_scheduler_event( 'debug', 'Scheduler-Statistiken optimiert - auf 30 Tage begrenzt' );
        }
        
        // Große Error-Arrays in den täglichen Stats begrenzen
        foreach ( $stats as $date => &$day_stats ) {
            if ( isset( $day_stats['errors'] ) && count( $day_stats['errors'] ) > 10 ) {
                $day_stats['errors'] = array_slice( $day_stats['errors'], -10 ); // Nur letzte 10 Fehler
            }
        }
        
        update_option( 'csv_import_scheduler_stats', $stats );
    }
    
    // ===================================================================
    // ÖFFENTLICHE METHODEN FÜR ADMIN-INTERFACE
    // ===================================================================
    
    /**
     * Holt Scheduler-Informationen für das Admin-Interface
     */
    public static function get_scheduler_info() {
        $info = [
            'is_scheduled' => self::is_scheduled(),
            'next_run' => self::get_next_scheduled(),
            'frequency' => get_option( 'csv_import_scheduled_frequency', '' ),
            'source' => get_option( 'csv_import_scheduled_source', '' ),
            'available_intervals' => self::INTERVALS,
            'stats' => get_option( 'csv_import_scheduler_stats', [] ),
            'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
            'initialization_errors' => self::$initialization_errors,
            'dependencies_ok' => self::$dependencies_checked
        ];
        
        // Zusätzliche berechnete Werte
        if ( $info['next_run'] ) {
            $info['next_run_human'] = human_time_diff( time(), $info['next_run'] );
            $info['next_run_formatted'] = date_i18n( 'd.m.Y H:i:s', $info['next_run'] );
        }
        
        // Scheduler-Health-Status
        $info['health_status'] = self::get_health_status();
        
        return $info;
    }
    
    /**
     * Ermittelt den aktuellen Health-Status des Schedulers
     */
    private static function get_health_status() {
        $status = [
            'overall' => 'good',
            'issues' => []
        ];
        
        // WordPress Cron Check
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            $status['issues'][] = 'WordPress Cron deaktiviert';
            $status['overall'] = 'warning';
        }
        
        // Dependencies Check
        if ( ! empty( self::$initialization_errors ) ) {
            $status['issues'] = array_merge( $status['issues'], self::$initialization_errors );
            $status['overall'] = 'error';
        }
        
        // Überfällige Events Check
        if ( self::is_scheduled() ) {
            $next_run = self::get_next_scheduled();
            if ( $next_run && $next_run < ( time() - 3600 ) ) {
                $status['issues'][] = 'Überfälliges Scheduler-Event';
                $status['overall'] = 'warning';
            }
        }
        
        return $status;
    }
    
    /**
     * Testet den Scheduler mit einem Test-Import
     */
    public static function test_scheduler() {
        // Prüfe Dependencies
        if ( ! function_exists( 'csv_import_get_config' ) ) {
            return [
                'success' => false,
                'message' => 'Core-Funktionen nicht verfügbar'
            ];
        }
        
        // Test-Event in 2 Minuten planen
        $test_time = time() + 120;
        $test_hook = 'csv_import_scheduler_test';
        
        // Test-Handler registrieren
        add_action( $test_hook, function() {
            update_option( 'csv_import_scheduler_test_result', [
                'success' => true,
                'timestamp' => current_time( 'mysql' ),
                'server_time' => date( 'Y-m-d H:i:s' )
            ] );
            
            if ( function_exists( 'csv_import_log' ) ) {
                csv_import_log( 'info', 'Scheduler-Test erfolgreich ausgeführt' );
            }
        });
        
        // Test-Event planen
        $scheduled = wp_schedule_single_event( $test_time, $test_hook );
        
        return [
            'success' => $scheduled !== false,
            'test_time' => $test_time,
            'test_time_formatted' => date( 'd.m.Y H:i:s', $test_time ),
            'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
            'next_cron_run' => wp_next_scheduled( 'wp_version_check' ), // Standard WP Cron als Referenz
            'message' => $scheduled 
                ? 'Test-Event geplant - Ergebnis in 2 Minuten verfügbar'
                : 'Test-Event konnte nicht geplant werden'
        ];
    }
    
    /**
     * Holt das Ergebnis des Scheduler-Tests
     */
    public static function get_test_result() {
        $result = get_option( 'csv_import_scheduler_test_result' );
        
        if ( $result ) {
            // Test-Result nach dem Abrufen löschen
            delete_option( 'csv_import_scheduler_test_result' );
        }
        
        return $result;
    }
    
    /**
     * Debug-Funktion für Scheduler-Status
     */
    public static function debug_scheduler_status() {
        $debug_info = [
            'current_time' => [
                'wp_time' => current_time( 'mysql' ),
                'server_time' => date( 'Y-m-d H:i:s' ),
                'timestamp' => time()
            ],
            'wordpress_cron' => [
                'enabled' => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
                'next_system_cron' => wp_next_scheduled( 'wp_version_check' )
            ],
            'scheduler_events' => [
                'scheduled_import' => wp_get_scheduled_event( self::HOOK_SCHEDULED_IMPORT ),
                'next_import' => wp_next_scheduled( self::HOOK_SCHEDULED_IMPORT ),
                'daily_cleanup' => wp_next_scheduled( self::HOOK_DAILY_CLEANUP ),
                'weekly_maintenance' => wp_next_scheduled( self::HOOK_WEEKLY_MAINTENANCE )
            ],
            'cron_schedules' => wp_get_schedules(),
            'plugin_options' => [
                'frequency' => get_option( 'csv_import_scheduled_frequency' ),
                'source' => get_option( 'csv_import_scheduled_source' ),
                'start_time' => get_option( 'csv_import_scheduled_start' ),
                'created' => get_option( 'csv_import_scheduled_created' )
            ],
            'function_availability' => [
                'csv_import_start_import' => function_exists( 'csv_import_start_import' ),
                'csv_import_get_config' => function_exists( 'csv_import_get_config' ),
                'csv_import_validate_config' => function_exists( 'csv_import_validate_config' ),
                'csv_import_is_import_running' => function_exists( 'csv_import_is_import_running' ),
                'csv_import_log' => function_exists( 'csv_import_log' )
            ],
            'class_availability' => [
                'CSV_Import_Scheduler' => class_exists( 'CSV_Import_Scheduler' ),
                'CSV_Import_Pro_Run' => class_exists( 'CSV_Import_Pro_Run' ),
                'CSV_Import_Error_Handler' => class_exists( 'CSV_Import_Error_Handler' ),
                'CSV_Import_Backup_Manager' => class_exists( 'CSV_Import_Backup_Manager' )
            ],
            'initialization' => [
                'dependencies_checked' => self::$dependencies_checked,
                'initialization_errors' => self::$initialization_errors
            ],
            'stats' => get_option( 'csv_import_scheduler_stats', [] ),
            'health_status' => self::get_health_status()
        ];
        
        return $debug_info;
    }
    
    // ===================================================================
    // ADMIN AJAX HANDLERS
    // ===================================================================
    
    /**
     * AJAX-Handler für Scheduler-Operationen
     */
    public static function handle_scheduler_ajax() {
        // Nur für Admin-Bereich
        if ( ! is_admin() ) {
            return;
        }
        
        add_action( 'wp_ajax_csv_scheduler_test', [ __CLASS__, 'ajax_test_scheduler' ] );
        add_action( 'wp_ajax_csv_scheduler_status', [ __CLASS__, 'ajax_get_status' ] );
        add_action( 'wp_ajax_csv_scheduler_debug', [ __CLASS__, 'ajax_debug_info' ] );
    }
    
    /**
     * AJAX: Scheduler testen
     */
    public static function ajax_test_scheduler() {
        check_ajax_referer( 'csv_import_ajax', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung' ] );
        }
        
        $result = self::test_scheduler();
        wp_send_json_success( $result );
    }
    
    /**
     * AJAX: Scheduler-Status abrufen
     */
    public static function ajax_get_status() {
        check_ajax_referer( 'csv_import_ajax', 'nonce' );
        
        if ( ! current_user_can( 'edit_pages' ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung' ] );
        }
        
        $status = self::get_scheduler_info();
        wp_send_json_success( $status );
    }
    
    /**
     * AJAX: Debug-Informationen abrufen
     */
    public static function ajax_debug_info() {
        check_ajax_referer( 'csv_import_ajax', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung' ] );
        }
        
        $debug_info = self::debug_scheduler_status();
        wp_send_json_success( $debug_info );
    }
}

// AJAX-Handler registrieren wenn im Admin-Bereich
if ( is_admin() ) {
    add_action( 'wp_loaded', [ 'CSV_Import_Scheduler', 'handle_scheduler_ajax' ] );
}

// === ENTFERNT: Automatische Initialisierung ===
// Die Zeile wurde entfernt, da der Scheduler jetzt über csv_import_pro_init() initialisiert wird:
// add_action( 'plugins_loaded', [ 'CSV_Import_Scheduler', 'init' ], 15 );

// Scheduler wird jetzt sicher über csv_import_pro_init() initialisiert,
// nachdem alle Dependencies (core-functions.php) geladen wurden.
