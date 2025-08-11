<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direkten Zugriff verhindern
}

// ===================================================================
// ERROR HANDLER SYSTEM
// ===================================================================

if ( ! class_exists( 'CSV_Import_Error_Handler' ) ) {

    class CSV_Import_Error_Handler {
        
        // Error Level Konstanten
        const LEVEL_DEBUG = 'debug';
        const LEVEL_INFO = 'info';
        const LEVEL_WARNING = 'warning';
        const LEVEL_ERROR = 'error';
        const LEVEL_CRITICAL = 'critical';
        
        private static $error_log = [];
        private static $max_errors = 100;
        private static $error_counts = [];
        
        public static function handle( $level, $message, $context = [] ) {
            $error_entry = [
                'timestamp' => current_time( 'mysql' ),
                'level' => $level,
                'message' => $message,
                'context' => $context,
                'trace' => self::get_debug_backtrace(),
                'user_id' => get_current_user_id(),
                'memory_usage' => memory_get_usage( true )
            ];
            
            self::add_to_log( $error_entry );
            self::update_error_counts( $level );
            
            switch ( $level ) {
                case self::LEVEL_CRITICAL:
                case self::LEVEL_ERROR:
                    self::log_to_wp( $error_entry );
                    self::maybe_send_notification( $error_entry );
                    break;
                case self::LEVEL_WARNING:
                    self::log_to_wp( $error_entry );
                    break;
                case self::LEVEL_INFO:
                case self::LEVEL_DEBUG:
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        self::log_to_wp( $error_entry );
                    }
                    break;
            }
            
            if ( function_exists( 'csv_import_track_error_stats' ) ) {
                csv_import_track_error_stats( $level, $message );
            }
        }
        
        private static function add_to_log( $error_entry ) {
    // Schritt 1: Bestehendes Log aus der Datenbank laden
    $log = get_option('csv_import_error_log', []);

    // Schritt 2: Neuen Eintrag hinzufügen
    $log[] = $error_entry;

    // Schritt 3: Sicherstellen, dass das Log nicht zu groß wird
    if ( count( $log ) > self::$max_errors ) {
        // Nur die letzten X Einträge behalten, um das Log zu kürzen
        $log = array_slice( $log, -self::$max_errors );
    }

    // Schritt 4: Das aktualisierte Log zurück in die Datenbank speichern
    update_option( 'csv_import_error_log', $log );

    // Optional: Die statische Variable für die aktuelle Anfrage aktualisieren
    self::$error_log = $log;
}
        
        private static function update_error_counts( $level ) {
            if ( ! isset( self::$error_counts[ $level ] ) ) {
                self::$error_counts[ $level ] = 0;
            }
            self::$error_counts[ $level ]++;
            update_option( 'csv_import_error_counts', self::$error_counts );
        }
        
        private static function log_to_wp( $error_entry ) {
            $log_message = sprintf(
                '[CSV Import Pro %s] %s: %s',
                strtoupper( $error_entry['level'] ),
                $error_entry['message'],
                ! empty( $error_entry['context'] ) ? wp_json_encode( $error_entry['context'] ) : ''
            );
            error_log( $log_message );
        }
        
        private static function maybe_send_notification( $error_entry ) {
            if ( $error_entry['level'] !== self::LEVEL_CRITICAL ) return;
            $last_notification = get_transient( 'csv_import_last_critical_notification' );
            if ( $last_notification ) return;
            set_transient( 'csv_import_last_critical_notification', time(), 3600 );
            self::send_critical_error_email( $error_entry );
        }
        
        private static function send_critical_error_email( $error_entry ) {
            $admin_email = get_option( 'admin_email' );
            $site_name = get_bloginfo( 'name' );
            $subject = "[$site_name] CSV Import Pro - Kritischer Fehler";
            $body = "Ein kritischer Fehler ist im CSV Import Pro Plugin aufgetreten:\n\n";
            $body .= "Fehler: " . $error_entry['message'] . "\n";
            $body .= "Zeit: " . $error_entry['timestamp'] . "\n";
            $body .= "Benutzer: " . ( $error_entry['user_id'] ?? 'System' ) . "\n";
            if ( ! empty( $error_entry['context'] ) ) {
                $body .= "Kontext:\n" . wp_json_encode( $error_entry['context'], JSON_PRETTY_PRINT ) . "\n\n";
            }
            $body .= "-- CSV Import Pro Error Handler";
            wp_mail( $admin_email, $subject, $body );
        }
        
        private static function get_debug_backtrace() {
            if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) return null;
            $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 6 );
            $simplified_trace = [];
            foreach ( $trace as $frame ) {
                if ( isset( $frame['file'] ) && isset( $frame['line'] ) ) {
                    $file = str_replace( CSV_IMPORT_PRO_PATH, '', $frame['file'] );
                    $simplified_trace[] = basename( $file ) . ':' . $frame['line'];
                }
            }
            return implode( ' → ', $simplified_trace );
        }

        public static function get_persistent_errors() {
            return get_option( 'csv_import_error_log', [] );
        }

        public static function get_error_counts() {
            if ( empty( self::$error_counts ) ) {
                self::$error_counts = get_option( 'csv_import_error_counts', [] );
            }
            return self::$error_counts;
        }

        /**
         * Löscht alle gespeicherten Fehler und Statistiken.
         */
        public static function clear_error_log() {
            // Statische Variablen zurücksetzen
            self::$error_log = [];
            self::$error_counts = [];
            
            // WordPress-Optionen löschen
            delete_option( 'csv_import_error_log' );
            delete_option( 'csv_import_error_counts' );
            
            // KORREKTUR: Die Haupt-Log-Datei wird nun ebenfalls gelöscht.
            delete_option( 'csv_import_error_stats' );
            
            // Physische Log-Dateien auf dem Server löschen
            $upload_dir = wp_upload_dir();
            $log_files = glob( $upload_dir['basedir'] . '/csv-import-*.log' );
            if ($log_files) {
                foreach ( $log_files as $log_file ) {
                    @unlink( $log_file );
                }
            }
        }
        
        public static function init() {
            self::$error_counts = get_option( 'csv_import_error_counts', [] );
        }
    }
    
    add_action( 'plugins_loaded', [ 'CSV_Import_Error_Handler', 'init' ], 5 );

}
