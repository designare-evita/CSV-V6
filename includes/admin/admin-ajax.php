<?php
/**
 * Verarbeitet alle AJAX-Anfragen aus dem Admin-Bereich des CSV Import Pro Plugins.
 * Version 8.5 - Komplett überarbeitete AJAX-Handler mit vollständiger Scheduler-Integration
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registriert alle AJAX-Aktionen des Plugins.
 * Version 8.5 - Erweiterte Handler-Registrierung
 */
function csv_import_register_ajax_hooks() {
    $ajax_actions = [
        // Standard Import-Aktionen
        'csv_import_validate',
        'csv_import_start',
        'csv_import_get_progress',
        'csv_import_cancel',
        
        // Scheduler-Aktionen (NEU in 8.5)
        'csv_scheduler_test',
        'csv_scheduler_status',
        'csv_scheduler_debug',
        
        // Erweiterte Aktionen
        'csv_import_get_progress_extended',
        'csv_import_emergency_reset',
        'csv_import_system_health'
    ];

    foreach($ajax_actions as $action) {
        $handler_function = $action . '_handler';
        
        // Prüfen ob Handler-Funktion existiert
        if ( function_exists( $handler_function ) ) {
            add_action('wp_ajax_' . $action, $handler_function);
            
            // Debug-Log für erfolgreiche Registrierung
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( "CSV Import AJAX: Handler registriert - {$action} -> {$handler_function}" );
            }
        } else {
            error_log( "CSV Import AJAX: Handler-Funktion fehlt - {$handler_function} für Action {$action}" );
        }
    }
    
    // Registrierung-Erfolg loggen
    if ( function_exists( 'csv_import_log' ) ) {
        csv_import_log( 'debug', 'AJAX-Handler registriert', [
            'total_actions' => count( $ajax_actions ),
            'version' => '8.5'
        ]);
    }
}

// Haken wird direkt ausgeführt, da diese Datei bei Bedarf geladen wird.
csv_import_register_ajax_hooks();

// ===================================================================
// STANDARD IMPORT AJAX-HANDLER
// ===================================================================

/**
 * Handler für die Validierung von Konfiguration und CSV-Dateien.
 * Version 8.5 - Verbesserte Fehlerbehandlung
 */
function csv_import_validate_handler() {
    // Sicherheitsprüfung
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'edit_pages' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung für Validierung.'] );
    }

    $type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
    $response_data = [ 'valid' => false, 'message' => 'Unbekannter Test-Typ.' ];

    try {
        // Sicherstellen, dass die Funktionen verfügbar sind
        if (!function_exists('csv_import_get_config')) {
             throw new Exception('Kernfunktionen des Plugins sind nicht geladen.');
        }

        $config = csv_import_get_config();
        
        if ( $type === 'config' ) {
            $validation = csv_import_validate_config( $config );
            $response_data = array_merge($response_data, $validation);
            if (!$validation['valid']) {
                $response_data['message'] = 'Konfigurationsfehler: <ul><li>' . implode('</li><li>', $validation['errors']) . '</li></ul>';
            } else {
                 $response_data['message'] = '✅ Konfiguration ist gültig und alle Systemanforderungen sind erfüllt.';
            }

        } elseif ( in_array( $type, [ 'dropbox', 'local' ] ) ) {
            $csv_result = csv_import_validate_csv_source( $type, $config );
            $response_data = array_merge( $response_data, $csv_result );
        }

        // Logging für Validierung
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'debug', "CSV-Validierung durchgeführt: {$type}", [
                'valid' => $response_data['valid'],
                'user_id' => get_current_user_id()
            ]);
        }

    } catch ( Exception $e ) {
        $error_msg = 'Validierungsfehler: ' . $e->getMessage();
        $response_data['message'] = $error_msg;
        
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'error', $error_msg, [
                'type' => $type,
                'exception' => get_class( $e ),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    if ( !empty($response_data['valid']) && $response_data['valid'] ) {
        wp_send_json_success( $response_data );
    } else {
        wp_send_json_error( $response_data );
    }
}

/**
 * Handler zum Starten des Imports.
 * Version 8.5 - Erweiterte Sicherheitsprüfungen
 */
function csv_import_start_handler() {
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'edit_pages' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung für Import-Start.'] );
    }

    $source = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : '';
    if ( ! in_array( $source, ['dropbox', 'local'] ) ) {
        wp_send_json_error( [ 'message' => 'Ungültige Import-Quelle.' ] );
    }

    try {
        // Prüfen ob bereits ein Import läuft
        if ( function_exists('csv_import_is_import_running') && csv_import_is_import_running() ) {
            wp_send_json_error( [ 'message' => 'Ein Import läuft bereits.' ] );
        }
        
        // Sicherstellen dass Import-Klasse verfügbar ist
        if ( ! class_exists( 'CSV_Import_Pro_Run' ) ) {
            throw new Exception( 'Import-Klasse (CSV_Import_Pro_Run) nicht gefunden.' );
        }
        
        // Mapping-Daten aus dem AJAX-Request holen (NEU)
$mapping = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : [];

// Die run-Methode mit dem neuen Mapping-Parameter aufrufen
$result = CSV_Import_Pro_Run::run( $source, $mapping );
        
        // Logging
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'info', "Import gestartet via AJAX: {$source}", [
                'success' => $result['success'] ?? false,
                'processed' => $result['processed'] ?? 0,
                'user_id' => get_current_user_id()
            ]);
        }
        
        if ( !empty($result['success']) ) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
        
    } catch ( Exception $e ) {
        $error_msg = 'Import-Start fehlgeschlagen: ' . $e->getMessage();
        
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'error', $error_msg, [
                'source' => $source,
                'exception' => get_class( $e ),
                'trace' => $e->getTraceAsString()
            ]);
        }
        
        wp_send_json_error(['message' => $error_msg]);
    }
}

/**
 * Handler zum Abrufen des Import-Fortschritts.
 * Version 8.5 - Erweiterte Progress-Informationen
 */
function csv_import_get_progress_handler() {
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'edit_pages' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung für Progress-Abfrage.'] );
    }

    try {
        if(function_exists('csv_import_get_progress')){
            $progress = csv_import_get_progress();
            
            // Zusätzliche Informationen hinzufügen
            $progress['timestamp'] = current_time( 'mysql' );
            $progress['server_time'] = time();
            
            wp_send_json_success( $progress );
        } else {
            wp_send_json_error(['message' => 'Fortschritts-Funktion nicht verfügbar.']);
        }
        
    } catch ( Exception $e ) {
        wp_send_json_error([
            'message' => 'Progress-Abfrage fehlgeschlagen: ' . $e->getMessage()
        ]);
    }
}

/**
 * Handler zum Abbrechen eines laufenden Imports.
 * Version 8.5 - Erweiterte Reset-Logik
 */
function csv_import_cancel_handler() {
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'edit_pages' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung für Import-Abbruch.'] );
    }
    
    try {
        if(function_exists('csv_import_force_reset_import_status')){
            csv_import_force_reset_import_status();
            
            // Logging
            if ( function_exists( 'csv_import_log' ) ) {
                csv_import_log( 'warning', 'Import via AJAX abgebrochen', [
                    'user_id' => get_current_user_id(),
                    'user_login' => wp_get_current_user()->user_login
                ]);
            }
            
            wp_send_json_success( ['message' => 'Import abgebrochen und zurückgesetzt.'] );
        } else {
             wp_send_json_error( ['message' => 'Reset-Funktion nicht verfügbar.'] );
        }
        
    } catch ( Exception $e ) {
        wp_send_json_error([
            'message' => 'Import-Abbruch fehlgeschlagen: ' . $e->getMessage()
        ]);
    }
}

// ===================================================================
// SCHEDULER AJAX-HANDLER - NEU IN VERSION 8.5
// ===================================================================

/**
 * Handler für Scheduler-Test AJAX-Request
 * URL: wp-admin/admin-ajax.php?action=csv_scheduler_test
 */
/**
 * Handler für Scheduler-Test AJAX-Request
 * URL: wp-admin/admin-ajax.php?action=csv_scheduler_test
 * KORRIGIERTE VERSION: Fängt WP_Error korrekt ab und gibt eine klare Fehlermeldung zurück.
 */
function csv_scheduler_test_handler() {
    // Sicherheitsprüfungen
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung für Scheduler-Tests.'] );
    }

    try {
        // Prüfen ob Scheduler verfügbar ist
        if ( ! class_exists( 'CSV_Import_Scheduler' ) ) {
            wp_send_json_error( [
                'message' => 'Scheduler-Klasse (CSV_Import_Scheduler) nicht verfügbar.',
            ]);
            return; // Beendet die Ausführung hier
        }

        // Test durchführen
        $result = CSV_Import_Scheduler::test_scheduler();
        
        // ===================================================================
        // KORREKTUR: Explizite Prüfung auf WP_Error
        // Hier wird der Fehler nun korrekt abgefangen.
        // ===================================================================
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [
                'message' => 'Scheduler-Test fehlgeschlagen: ' . $result->get_error_message(),
                'debug' => [
                    'error_code' => $result->get_error_code(),
                    'error_data' => $result->get_error_data()
                ]
            ]);
            return; // Wichtig: Ausführung hier beenden
        }

        // Logging für erfolgreichen Test
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'info', 'Scheduler-Test via AJAX durchgeführt', [
                'success' => is_array( $result ) ? $result['success'] : true,
                'user_id' => get_current_user_id()
            ]);
        }

        // Erfolgreiche Antwort, wenn kein WP_Error aufgetreten ist
        wp_send_json_success( array_merge( is_array( $result ) ? $result : ['success' => true], [
            'timestamp' => current_time( 'mysql' ),
            'test_completed' => true
        ]));

    } catch ( Exception $e ) {
        // Exception-Handling für unerwartete Fehler
        $error_details = [
            'message' => 'Eine Ausnahme (Exception) ist beim Scheduler-Test aufgetreten: ' . $e->getMessage(),
            'debug' => [
                'exception_class' => get_class( $e ),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ];
        
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'error', 'Scheduler-Test Exception', $error_details );
        }
        
        wp_send_json_error( $error_details );
    }
}
/**
 * Handler für Scheduler-Status AJAX-Request
 * URL: wp-admin/admin-ajax.php?action=csv_scheduler_status
 */
function csv_scheduler_status_handler() {
    // Sicherheitsprüfungen
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'edit_pages' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung für Scheduler-Status.'] );
    }

    try {
        // Basis-Status sammeln
        $status = [
            'scheduler_available' => class_exists( 'CSV_Import_Scheduler' ),
            'core_functions_available' => function_exists( 'csv_import_get_config' ),
            'timestamp' => current_time( 'mysql' ),
            'server_time' => time()
        ];

        if ( class_exists( 'CSV_Import_Scheduler' ) ) {
            // Detaillierte Scheduler-Informationen holen
            if ( method_exists( 'CSV_Import_Scheduler', 'get_scheduler_info' ) ) {
                $scheduler_info = CSV_Import_Scheduler::get_scheduler_info();
                $status = array_merge( $status, $scheduler_info );
            } else {
                // Fallback: Basis-Informationen sammeln
                $status['is_scheduled'] = method_exists( 'CSV_Import_Scheduler', 'is_scheduled' ) 
                    ? CSV_Import_Scheduler::is_scheduled() 
                    : false;
                
                $status['next_run'] = method_exists( 'CSV_Import_Scheduler', 'get_next_scheduled' ) 
                    ? CSV_Import_Scheduler::get_next_scheduled() 
                    : false;
                
                $status['current_frequency'] = get_option( 'csv_import_scheduled_frequency', '' );
                $status['current_source'] = get_option( 'csv_import_scheduled_source', '' );
                
                // Manuell verfügbare Intervalle setzen
                $status['available_intervals'] = [
                    'hourly' => 'Stündlich',
                    'twicedaily' => 'Zweimal täglich',
                    'daily' => 'Täglich',
                    'weekly' => 'Wöchentlich'
                ];
            }

            // WordPress Cron-Status hinzufügen
            $status['wp_cron_disabled'] = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
            $status['next_wp_cron'] = wp_next_scheduled( 'wp_version_check' );
            
            // Aktuelle Cron-Jobs zählen
            $cron_array = _get_cron_array();
            $status['total_cron_jobs'] = count( $cron_array );
            
            // CSV Import spezifische Cron-Jobs
            $csv_cron_jobs = 0;
            foreach ( $cron_array as $timestamp => $cron ) {
                foreach ( $cron as $hook => $details ) {
                    if ( strpos( $hook, 'csv_import' ) !== false ) {
                        $csv_cron_jobs++;
                    }
                }
            }
            $status['csv_import_cron_jobs'] = $csv_cron_jobs;
            
        } else {
            $status['error'] = 'Scheduler-Klasse nicht verfügbar';
            $status['debug'] = [
                'declared_classes_count' => count( get_declared_classes() ),
                'wp_loaded' => did_action( 'wp_loaded' ) > 0,
                'plugins_loaded' => did_action( 'plugins_loaded' ) > 0
            ];
        }

        // Erweiterte System-Informationen
        $status['system_info'] = [
            'memory_usage' => memory_get_usage( true ),
            'memory_peak' => memory_get_peak_usage( true ),
            'wp_version' => get_bloginfo( 'version' ),
            'php_version' => PHP_VERSION
        ];

        wp_send_json_success( $status );

    } catch ( Exception $e ) {
        $error_details = [
            'message' => 'Scheduler-Status-Fehler: ' . $e->getMessage(),
            'debug' => [
                'exception_class' => get_class( $e ),
                'trace' => $e->getTraceAsString()
            ]
        ];
        
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'error', 'Scheduler-Status Exception', $error_details );
        }
        
        wp_send_json_error( $error_details );
    }
}

/**
 * Handler für Scheduler-Debug AJAX-Request
 * URL: wp-admin/admin-ajax.php?action=csv_scheduler_debug
 */
function csv_scheduler_debug_handler() {
    // Sicherheitsprüfungen - Nur für Admins
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung für Debug-Informationen.'] );
    }

    try {
        $debug_info = [
            'timestamp' => current_time( 'mysql' ),
            'server_time' => time(),
            'user_info' => [
                'user_id' => get_current_user_id(),
                'user_login' => wp_get_current_user()->user_login,
                'user_roles' => wp_get_current_user()->roles
            ]
        ];

        // Scheduler-Debug-Informationen
        if ( class_exists( 'CSV_Import_Scheduler' ) && method_exists( 'CSV_Import_Scheduler', 'debug_scheduler_status' ) ) {
            $debug_info['scheduler'] = CSV_Import_Scheduler::debug_scheduler_status();
        } else {
            $debug_info['scheduler'] = [
                'error' => 'Scheduler-Debug-Methode nicht verfügbar',
                'class_exists' => class_exists( 'CSV_Import_Scheduler' ),
                'available_methods' => class_exists( 'CSV_Import_Scheduler' ) 
                    ? get_class_methods( 'CSV_Import_Scheduler' )
                    : [],
                'class_file' => class_exists( 'CSV_Import_Scheduler' ) 
                    ? (new ReflectionClass( 'CSV_Import_Scheduler' ))->getFileName()
                    : 'not found'
            ];
        }

        // System-Debug-Informationen
        $debug_info['system'] = [
            'wp_version' => get_bloginfo( 'version' ),
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get( 'memory_limit' ),
            'memory_usage' => memory_get_usage( true ),
            'memory_peak' => memory_get_peak_usage( true ),
            'max_execution_time' => ini_get( 'max_execution_time' ),
            'wp_debug' => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'php_sapi' => php_sapi_name()
        ];

        // Plugin-Debug-Informationen
        $debug_info['plugin'] = [
            'version' => defined( 'CSV_IMPORT_PRO_VERSION' ) ? CSV_IMPORT_PRO_VERSION : 'unknown',
            'path' => defined( 'CSV_IMPORT_PRO_PATH' ) ? CSV_IMPORT_PRO_PATH : 'unknown',
            'url' => defined( 'CSV_IMPORT_PRO_URL' ) ? CSV_IMPORT_PRO_URL : 'unknown',
            'functions_available' => [
                'csv_import_get_config' => function_exists( 'csv_import_get_config' ),
                'csv_import_validate_config' => function_exists( 'csv_import_validate_config' ),
                'csv_import_start_import' => function_exists( 'csv_import_start_import' ),
                'csv_import_is_import_running' => function_exists( 'csv_import_is_import_running' ),
                'csv_import_log' => function_exists( 'csv_import_log' ),
                'csv_import_get_progress' => function_exists( 'csv_import_get_progress' )
            ],
            'classes_available' => [
                'CSV_Import_Scheduler' => class_exists( 'CSV_Import_Scheduler' ),
                'CSV_Import_Error_Handler' => class_exists( 'CSV_Import_Error_Handler' ),
                'CSV_Import_Pro_Run' => class_exists( 'CSV_Import_Pro_Run' ),
                'CSV_Import_Pro_Admin' => class_exists( 'CSV_Import_Pro_Admin' ),
                'CSV_Import_Backup_Manager' => class_exists( 'CSV_Import_Backup_Manager' ),
                'CSV_Import_Notifications' => class_exists( 'CSV_Import_Notifications' )
            ]
        ];

        // WordPress Cron Debug-Informationen
        $cron_array = _get_cron_array();
        $debug_info['cron'] = [
            'total_jobs' => count( $cron_array ),
            'csv_import_jobs' => [],
            'next_wp_jobs' => [],
            'cron_lock' => get_transient( 'doing_cron' )
        ];

        // CSV Import spezifische Cron-Jobs finden
        foreach ( $cron_array as $timestamp => $cron ) {
            foreach ( $cron as $hook => $details ) {
                if ( strpos( $hook, 'csv_import' ) !== false ) {
                    $debug_info['cron']['csv_import_jobs'][] = [
                        'hook' => $hook,
                        'timestamp' => $timestamp,
                        'human_time' => date( 'Y-m-d H:i:s', $timestamp ),
                        'in_seconds' => $timestamp - time(),
                        'details' => $details
                    ];
                }
            }
        }

        // Nächste WordPress Standard-Jobs
        $wp_hooks = ['wp_version_check', 'wp_update_plugins', 'wp_update_themes'];
        foreach ( $wp_hooks as $hook ) {
            $next = wp_next_scheduled( $hook );
            if ( $next ) {
                $debug_info['cron']['next_wp_jobs'][$hook] = [
                    'timestamp' => $next,
                    'human_time' => date( 'Y-m-d H:i:s', $next ),
                    'in' => human_time_diff( time(), $next )
                ];
            }
        }

        // Plugin-Optionen Debug
        $debug_info['options'] = [
            'csv_import_version' => get_option( 'csv_import_version' ),
            'csv_import_scheduled_frequency' => get_option( 'csv_import_scheduled_frequency' ),
            'csv_import_scheduled_source' => get_option( 'csv_import_scheduled_source' ),
            'csv_import_progress' => get_option( 'csv_import_progress' ),
            'csv_import_running_lock' => get_option( 'csv_import_running_lock' )
        ];

        // Logging
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'debug', 'Debug-Informationen via AJAX abgerufen', [
                'user_id' => get_current_user_id(),
                'data_size' => strlen( json_encode( $debug_info ) )
            ]);
        }

        wp_send_json_success( $debug_info );

    } catch ( Exception $e ) {
        $error_details = [
            'message' => 'Debug-Informationen-Fehler: ' . $e->getMessage(),
            'debug' => [
                'exception_class' => get_class( $e ),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]
        ];
        
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'error', 'Debug-Handler Exception', $error_details );
        }
        
        wp_send_json_error( $error_details );
    }
}

// ===================================================================
// ERWEITERTE AJAX-HANDLER - NEU IN VERSION 8.5
// ===================================================================

/**
 * Handler für erweiterte Import-Fortschritt AJAX-Request
 * Bietet detailliertere Informationen als der Standard-Handler
 */
function csv_import_get_progress_extended_handler() {
    // Sicherheitsprüfungen
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'edit_pages' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung für erweiterte Progress-Abfrage.'] );
    }

    try {
        $progress_data = [];

        // Basis-Progress-Informationen
        if ( function_exists( 'csv_import_get_progress' ) ) {
            $progress_data = csv_import_get_progress();
        } else {
            $progress_data = [
                'running' => false,
                'processed' => 0,
                'total' => 0,
                'percent' => 0,
                'status' => 'unknown',
                'message' => 'Progress-Funktion nicht verfügbar',
                'error' => 'Function csv_import_get_progress not found'
            ];
        }

        // Erweiterte Informationen hinzufügen
        $progress_data['timestamp'] = current_time( 'mysql' );
        $progress_data['server_time'] = time();
        $progress_data['memory_usage'] = memory_get_usage( true );
        $progress_data['memory_peak'] = memory_get_peak_usage( true );
        $progress_data['memory_limit'] = ini_get( 'memory_limit' );

        // Import-Lock-Status prüfen
        $progress_data['import_locked'] = get_option( 'csv_import_running_lock' ) !== false;
        $lock_data = get_option( 'csv_import_running_lock' );
        if ( $lock_data ) {
            $progress_data['lock_info'] = [
                'locked_since' => $lock_data['locked_at'] ?? 'unknown',
                'locked_by_user' => $lock_data['locked_by'] ?? 'unknown',
                'lock_age_seconds' => time() - ( $lock_data['locked_at'] ?? time() )
            ];
        }
        
        // Scheduler-Status hinzufügen
        if ( class_exists( 'CSV_Import_Scheduler' ) && method_exists( 'CSV_Import_Scheduler', 'is_scheduled' ) ) {
            $progress_data['scheduler_active'] = CSV_Import_Scheduler::is_scheduled();
            if ( method_exists( 'CSV_Import_Scheduler', 'get_next_scheduled' ) ) {
                $progress_data['next_scheduled'] = CSV_Import_Scheduler::get_next_scheduled();
            }
        }

        // System-Health-Check
        if ( function_exists( 'csv_import_system_health_check' ) ) {
            $health = csv_import_system_health_check();
            $progress_data['system_health'] = $health;
            $progress_data['system_healthy'] = ! in_array( false, $health, true );
        }

        wp_send_json_success( $progress_data );

    } catch ( Exception $e ) {
        wp_send_json_error( [
            'message' => 'Erweiterte Progress-Abfrage fehlgeschlagen: ' . $e->getMessage(),
            'debug' => [
                'exception_class' => get_class( $e ),
                'trace' => $e->getTraceAsString()
            ]
        ]);
    }
}

/**
 * Handler für Emergency-Reset AJAX-Request
 */
function csv_import_emergency_reset_handler() {
    // Sicherheitsprüfungen - Nur für Admins
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung für Emergency-Reset.'] );
    }

    try {
        $reset_actions = [];
        
        // 1. Import-Status zurücksetzen
        if ( function_exists( 'csv_import_force_reset_import_status' ) ) {
            csv_import_force_reset_import_status();
            $reset_actions[] = 'Import-Status zurückgesetzt';
        }
        
        // 2. Temporäre Dateien bereinigen
        if ( function_exists( 'csv_import_cleanup_temp_files' ) ) {
            csv_import_cleanup_temp_files();
            $reset_actions[] = 'Temporäre Dateien bereinigt';
        }
        
        // 3. Tote Prozesse bereinigen
        if ( function_exists( 'csv_import_cleanup_dead_processes' ) ) {
            csv_import_cleanup_dead_processes();
            $reset_actions[] = 'Tote Prozesse bereinigt';
        }
        
        // 4. Scheduler zurücksetzen
        if ( class_exists( 'CSV_Import_Scheduler' ) && method_exists( 'CSV_Import_Scheduler', 'unschedule_all' ) ) {
            CSV_Import_Scheduler::unschedule_all();
            $reset_actions[] = 'Scheduler zurückgesetzt';
        }
        
        // 5. Alle Plugin-Locks und Transients löschen
        global $wpdb;
        $deleted_options = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%csv_import%lock%'" );
        $deleted_transients = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_csv_import_%'" );
        $deleted_timeouts = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_csv_import_%'" );
        
        if ( $deleted_options > 0 ) $reset_actions[] = "Locks gelöscht: {$deleted_options}";
        if ( $deleted_transients > 0 ) $reset_actions[] = "Transients gelöscht: {$deleted_transients}";
        
        // 6. Health-Check zurücksetzen
        delete_transient( 'csv_import_health_checked' );
        $reset_actions[] = 'Health-Check zurückgesetzt';
        
        // Logging
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'warning', 'Emergency-Reset via AJAX durchgeführt', [
                'actions' => $reset_actions,
                'user_id' => get_current_user_id(),
                'user_login' => wp_get_current_user()->user_login,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        }
        
        wp_send_json_success( [
            'message' => 'Emergency-Reset erfolgreich durchgeführt',
            'actions_performed' => $reset_actions,
            'timestamp' => current_time( 'mysql' )
        ]);
        
    } catch ( Exception $e ) {
        $error_details = [
            'message' => 'Emergency-Reset fehlgeschlagen: ' . $e->getMessage(),
            'debug' => [
                'exception_class' => get_class( $e ),
                'trace' => $e->getTraceAsString()
            ]
        ];
        
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'error', 'Emergency-Reset Exception', $error_details );
        }
        
        wp_send_json_error( $error_details );
    }
}

/**
 * Ersetzen Sie in includes/admin/admin-ajax.php die Funktion:
 * csv_import_system_health_handler()
 */

/**
 * Handler für System-Health AJAX-Request
 * PROFESSIONELLE VERSION - Korrekte Interpretation verschiedener Check-Typen
 */
function csv_import_system_health_handler() {
    // Sicherheitsprüfungen
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'edit_pages' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung für System-Health-Check.'] );
    }

    try {
        $health_data = [
            'timestamp' => current_time( 'mysql' ),
            'server_time' => time()
        ];
        
        // Basis Health-Check
        if ( function_exists( 'csv_import_system_health_check' ) ) {
            $health_data['system_health'] = csv_import_system_health_check();
        } else {
            $health_data['system_health'] = [
                'error' => 'Health-Check-Funktion nicht verfügbar'
            ];
        }
        
        // Plugin-spezifische Checks
        $health_data['plugin_health'] = [
            'core_functions_loaded' => function_exists( 'csv_import_get_config' ),
            'scheduler_available' => class_exists( 'CSV_Import_Scheduler' ),
            'admin_available' => class_exists( 'CSV_Import_Pro_Admin' ),
            'error_handler_available' => class_exists( 'CSV_Import_Error_Handler' ),
            'import_class_available' => class_exists( 'CSV_Import_Pro_Run' )
        ];
        
        // Import-Status
        $health_data['import_status'] = [
            'running' => function_exists( 'csv_import_is_import_running' ) ? csv_import_is_import_running() : false,
            'locked' => get_option( 'csv_import_running_lock' ) !== false,
            'progress_available' => function_exists( 'csv_import_get_progress' )
        ];
        
        // WordPress-Status
        $health_data['wp_status'] = [
            'version' => get_bloginfo( 'version' ),
            'multisite' => is_multisite(),
            'debug_mode' => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
        ];
        
        // Server-Status
        $health_data['server_status'] = [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get( 'memory_limit' ),
            'memory_usage' => memory_get_usage( true ),
            'memory_peak' => memory_get_peak_usage( true ),
            'max_execution_time' => ini_get( 'max_execution_time' ),
            'disk_free_space' => disk_free_space( ABSPATH )
        ];
        
        // ===================================================================
        // VEREINFACHTE ISSUE-DETECTION: Einheitliche Regel true = OK, false = Problem
        // ===================================================================
        
        $issues = [];
        
        // System-Health prüfen (ALLE Checks: true = OK, false = Problem)
        if ( isset( $health_data['system_health'] ) && is_array( $health_data['system_health'] ) ) {
            foreach ( $health_data['system_health'] as $check => $status ) {
                if ( $status === false ) {
                    // Schöne Namen für die Anzeige
                    $check_names = [
                        'memory_ok' => 'Memory Limit',
                        'time_ok' => 'Execution Time',
                        'disk_space_ok' => 'Disk Space',
                        'permissions_ok' => 'File Permissions',
                        'php_version_ok' => 'PHP Version',
                        'curl_ok' => 'cURL Extension',
                        'wp_version_ok' => 'WordPress Version',
                        'import_locks_ok' => 'Import Locks',
                        'no_stuck_processes' => 'Hängende Prozesse'
                    ];
                    
                    $display_name = $check_names[$check] ?? ucwords(str_replace('_', ' ', $check));
                    $issues[] = "System: {$display_name} Problem";
                }
            }
        }
        
        // Plugin-Health prüfen (true = OK, false = Problem)
        foreach ( $health_data['plugin_health'] as $check => $status ) {
            if ( ! $status ) {
                $check_name = ucwords(str_replace('_', ' ', $check));
                $issues[] = "Plugin: {$check_name} fehlt";
            }
        }
        
        // Gesamtstatus berechnen
        $health_data['overall_status'] = [
            'healthy' => empty( $issues ),
            'issues_count' => count( $issues ),
            'issues' => $issues
        ];
        
        wp_send_json_success( $health_data );
        
    } catch ( Exception $e ) {
        wp_send_json_error( [
            'message' => 'System-Health-Check fehlgeschlagen: ' . $e->getMessage(),
            'debug' => [
                'exception_class' => get_class( $e ),
                'trace' => $e->getTraceAsString()
            ]
        ]);
    }
}


/**
 * Health-Check Result Struktur für API-Dokumentation:
 * 
 * @return array {
 *     @type bool   $memory_ok         PHP Memory ausreichend (true = OK)
 *     @type bool   $time_ok           Execution Time ausreichend (true = OK) 
 *     @type bool   $disk_space_ok     Disk Space ausreichend (true = OK)
 *     @type bool   $permissions_ok    File Permissions OK (true = OK)
 *     @type bool   $php_version_ok    PHP Version kompatibel (true = OK)
 *     @type bool   $curl_ok           cURL verfügbar (true = OK)
 *     @type bool   $wp_version_ok     WordPress Version kompatibel (true = OK)
 *     @type bool   $import_locks      Import-Locks vorhanden (false = OK, true = Problem)
 *     @type bool   $stuck_processes   Hängende Prozesse vorhanden (false = OK, true = Problem)
 * }
 */

/**
 * PROFESSIONELLE ALTERNATIVE: Könnte auch über Interface abstrahiert werden
 */
interface HealthCheckInterface {
    public function getName(): string;
    public function check(): bool;
    public function isNegativeCheck(): bool; // true wenn false = gut
    public function getDescription(): string;
}

/**
 * Aber für WordPress-Plugin-Kontext ist die obige Lösung optimal:
 * - Keine Überarchitektur
 * - Klare, dokumentierte Semantik  
 * - Einfach erweiterbar
 * - Rückwärtskompatibel
 */

// ===================================================================
// HILFSFUNKTIONEN FÜR AJAX-HANDLER
// ===================================================================

/**
 * Validiert AJAX-Request-Parameter
 */
function csv_import_validate_ajax_params( $required_params, $request_data ) {
    $missing = [];
    
    foreach ( $required_params as $param ) {
        if ( ! isset( $request_data[ $param ] ) || empty( $request_data[ $param ] ) ) {
            $missing[] = $param;
        }
    }
    
    return [
        'valid' => empty( $missing ),
        'missing' => $missing
    ];
}

/**
 * Standardisierte AJAX-Fehler-Response
 */
function csv_import_ajax_error_response( $message, $details = [], $http_code = 400 ) {
    $error_data = [
        'message' => $message,
        'timestamp' => current_time( 'mysql' ),
        'user_id' => get_current_user_id()
    ];
    
    if ( ! empty( $details ) ) {
        $error_data['debug'] = $details;
    }
    
    // Logging
    if ( function_exists( 'csv_import_log' ) ) {
        csv_import_log( 'warning', "AJAX-Fehler: {$message}", $error_data );
    }
    
    wp_send_json_error( $error_data, $http_code );
}

/**
 * Standardisierte AJAX-Erfolg-Response
 */
function csv_import_ajax_success_response( $data = [], $message = null ) {
    $response_data = [
        'timestamp' => current_time( 'mysql' ),
        'user_id' => get_current_user_id()
    ];
    
    if ( $message ) {
        $response_data['message'] = $message;
    }
    
    if ( ! empty( $data ) ) {
        $response_data = array_merge( $response_data, $data );
    }
    
    wp_send_json_success( $response_data );
}

// ===================================================================
// AJAX-HANDLER VERFÜGBARKEIT PRÜFEN
// ===================================================================

/**
 * Prüft ob alle AJAX-Handler korrekt registriert wurden
 * Kann via AJAX aufgerufen werden: action=csv_import_check_handlers
 */
function csv_import_check_handlers_handler() {
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung.'] );
    }
    
    $handlers = [
        // Standard-Handler
        'csv_import_validate' => 'csv_import_validate_handler',
        'csv_import_start' => 'csv_import_start_handler',
        'csv_import_get_progress' => 'csv_import_get_progress_handler',
        'csv_import_cancel' => 'csv_import_cancel_handler',
        
        // Scheduler-Handler
        'csv_scheduler_test' => 'csv_scheduler_test_handler',
        'csv_scheduler_status' => 'csv_scheduler_status_handler',
        'csv_scheduler_debug' => 'csv_scheduler_debug_handler',
        
        // Erweiterte Handler
        'csv_import_get_progress_extended' => 'csv_import_get_progress_extended_handler',
        'csv_import_emergency_reset' => 'csv_import_emergency_reset_handler',
        'csv_import_system_health' => 'csv_import_system_health_handler'
    ];
    
    $status = [];
    
    foreach ( $handlers as $action => $function ) {
        $status[ $action ] = [
            'function_exists' => function_exists( $function ),
            'hook_registered' => has_action( "wp_ajax_{$action}" ) !== false,
            'function_name' => $function
        ];
    }
    
    $all_good = true;
    foreach ( $status as $action => $info ) {
        if ( ! $info['function_exists'] || ! $info['hook_registered'] ) {
            $all_good = false;
            break;
        }
    }
    
    wp_send_json_success( [
        'all_handlers_available' => $all_good,
        'handlers' => $status,
        'total_handlers' => count( $handlers ),
        'available_count' => count( array_filter( $status, function( $info ) {
            return $info['function_exists'] && $info['hook_registered'];
        }))
    ]);
}

// Handler-Check registrieren
add_action( 'wp_ajax_csv_import_check_handlers', 'csv_import_check_handlers_handler' );

// ===================================================================
// AJAX-LOGGING UND DEBUGGING
// ===================================================================

/**
 * Loggt alle AJAX-Requests für Debugging-Zwecke
 */
function csv_import_log_ajax_requests() {
    if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
        return;
    }
    
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    if ( strpos( $action, 'csv_' ) === 0 ) {
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'debug', "AJAX-Request: {$action}", [
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                'user_id' => get_current_user_id(),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        }
    }
}

// AJAX-Request-Logging aktivieren (nur bei WP_DEBUG)
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    add_action( 'wp_ajax_nopriv_' . 'csv_import_log_request', 'csv_import_log_ajax_requests' );
    add_action( 'wp_ajax_' . 'csv_import_log_request', 'csv_import_log_ajax_requests' );
}

// ===================================================================
// ABSCHLUSS UND FINALISIERUNG
// ===================================================================

// Debug-Information für erfolgreiche AJAX-Handler-Ladung
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    error_log( 'CSV Import Pro: Alle AJAX-Handler erfolgreich geladen (Version 8.5 - ' . date('Y-m-d H:i:s') . ')' );
}

// Handler-Anzahl für Monitoring
$total_handlers = 0;
foreach ( ['csv_import_validate', 'csv_import_start', 'csv_import_get_progress', 'csv_import_cancel', 
           'csv_scheduler_test', 'csv_scheduler_status', 'csv_scheduler_debug',
           'csv_import_get_progress_extended', 'csv_import_emergency_reset', 'csv_import_system_health',
           'csv_import_check_handlers'] as $action ) {
    if ( has_action( "wp_ajax_{$action}" ) ) {
        $total_handlers++;
    }
}

// Erfolgreiche Handler-Registrierung loggen
if ( function_exists( 'csv_import_log' ) ) {
    csv_import_log( 'debug', 'AJAX-Handler-Setup abgeschlossen', [
        'total_handlers' => $total_handlers,
        'expected_handlers' => 11,
        'version' => '8.5',
        'file' => basename( __FILE__ )
    ]);
}

// Performance-Monitoring für AJAX-Requests
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    add_action( 'wp_ajax_*', function() {
        static $start_time;
        if ( ! $start_time ) {
            $start_time = microtime( true );
        }
        
        // Am Ende des Requests Performance loggen
        add_action( 'shutdown', function() use ( $start_time ) {
            $execution_time = microtime( true ) - $start_time;
            if ( $execution_time > 1.0 && function_exists( 'csv_import_log' ) ) { // Nur bei > 1 Sekunde
                csv_import_log( 'warning', 'Langsamer AJAX-Request erkannt', [
                    'execution_time' => round( $execution_time, 2 ),
                    'action' => $_POST['action'] ?? 'unknown',
                    'memory_peak' => memory_get_peak_usage( true )
                ]);
            }
        });
    });
}
