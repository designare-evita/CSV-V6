<?php
/**
 * Plugin Name:       CSV Import Pro
 * Plugin URI:        https://example.com/csv-import-plugin
 * Description:       Professionelles CSV-Import System mit korrigierter Scheduler-Integration und stabiler Fehlerbehandlung.
 * Version:           8.5 (Scheduler-Init-Fix)
 * Author:            Michael Kanda
 * Author URI:        https://example.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       csv-import
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Tested up to:      6.4
 * Requires PHP:      7.4
 */

// Direkten Zugriff verhindern
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Mehrfache Ladung verhindern
if ( defined( 'CSV_IMPORT_PRO_LOADED' ) ) {
    return;
}
define( 'CSV_IMPORT_PRO_LOADED', true );

// Plugin-Konstanten definieren
define( 'CSV_IMPORT_PRO_VERSION', '8.5' );
define( 'CSV_IMPORT_PRO_PATH', plugin_dir_path( __FILE__ ) );
define( 'CSV_IMPORT_PRO_URL', plugin_dir_url( __FILE__ ) );
// Cleanup beim Aktivieren
register_activation_hook(__FILE__, 'my_csv_import_cleanup');

function my_csv_import_cleanup() {
    global $wpdb;

    // 1. Alle eigenen CSV-Import-CRON-Events bereinigen
    $hooks = [
        'csv_import_scheduled',
        'csv_import_error_cleanup',
        'csv_import_daily_maintenance',
        'csv_import_daily_cleanup',
        'csv_import_weekly_maintenance'
    ];

    foreach ($hooks as $hook) {
        while ($timestamp = wp_next_scheduled($hook)) {
            wp_unschedule_event($timestamp, $hook);
        }
    }

    // 2. Import-Locks entfernen
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%import_lock%'");

    // 3. Hängende Jobs löschen (falls Action Scheduler genutzt wird)
    if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}actionscheduler_actions'")) {
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}actionscheduler_actions 
             WHERE hook LIKE 'csv_import%' AND status IN ('in-progress', 'pending')"
        );
    }

    // 4. Jobs neu planen
    if (!wp_next_scheduled('csv_import_scheduled', ['local', []])) {
        wp_schedule_event(strtotime('02:00:00'), 'daily', 'csv_import_scheduled', ['local', []]);
    }
    if (!wp_next_scheduled('csv_import_error_cleanup')) {
        wp_schedule_event(time(), 'daily', 'csv_import_error_cleanup');
    }
    if (!wp_next_scheduled('csv_import_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'csv_import_daily_cleanup');
    }
    if (!wp_next_scheduled('csv_import_daily_maintenance')) {
        wp_schedule_event(time(), 'daily', 'csv_import_daily_maintenance');
    }
    if (!wp_next_scheduled('csv_import_weekly_maintenance')) {
        wp_schedule_event(time(), 'weekly', 'csv_import_weekly_maintenance');
    }
}

// Optional: Cleanup beim Deaktivieren
register_deactivation_hook(__FILE__, function() {
    $hooks = [
        'csv_import_scheduled',
        'csv_import_error_cleanup',
        'csv_import_daily_maintenance',
        'csv_import_daily_cleanup',
        'csv_import_weekly_maintenance'
    ];
    foreach ($hooks as $hook) {
        while ($timestamp = wp_next_scheduled($hook)) {
            wp_unschedule_event($timestamp, $hook);
        }
    }
});

define( 'CSV_IMPORT_PRO_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Lädt die Core-Dateien, die sofort benötigt werden.
 * Diese müssen vor allen anderen Komponenten geladen werden.
 * Version 8.5 - Verbesserte Fehlerbehandlung
 */
function csv_import_pro_load_core_files() {
    $core_files = [
        'includes/core/core-functions.php',           // KRITISCH: Zuerst laden
        'includes/class-csv-import-error-handler.php', // Error Handler als zweites
        'includes/class-installer.php'                // Installer für Aktivierung
    ];
    
    $loaded_files = [];
    $failed_files = [];
    
    foreach ( $core_files as $file ) {
        $path = CSV_IMPORT_PRO_PATH . $file;
        if ( file_exists( $path ) ) {
            try {
                require_once $path;
                $loaded_files[] = $file;
            } catch ( Exception $e ) {
                $failed_files[] = $file . ' (Exception: ' . $e->getMessage() . ')';
                error_log( 'CSV Import Pro: FEHLER beim Laden von ' . $file . ': ' . $e->getMessage() );
            }
        } else {
            $failed_files[] = $file . ' (Datei nicht gefunden)';
            error_log( 'CSV Import Pro: KRITISCHE Core-Datei fehlt: ' . $path );
        }
    }
    
    // Admin-Notice für fehlende Core-Dateien
    if ( ! empty( $failed_files ) ) {
        add_action( 'admin_notices', function() use ( $failed_files ) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>CSV Import Pro:</strong> Kritische Core-Dateien fehlen oder konnten nicht geladen werden:';
            echo '<ul style="margin-left: 20px;">';
            foreach ( $failed_files as $file ) {
                echo '<li><code>' . esc_html( basename( $file ) ) . '</code></li>';
            }
            echo '</ul>';
            echo 'Plugin neu installieren oder Support kontaktieren.';
            echo '</p></div>';
        });
        
        return false;
    }
    
    // Erfolgs-Log
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'CSV Import Pro: Core-Dateien erfolgreich geladen: ' . implode( ', ', array_map( 'basename', $loaded_files ) ) );
    }
    
    return true;
}

// Lade die Core-Dateien sofort beim Plugin-Load
if ( ! csv_import_pro_load_core_files() ) {
    return; // Stoppe Execution wenn Core-Dateien fehlen
}

/**
 * Lädt alle weiteren Plugin-Dateien in der KORREKTEN Reihenfolge.
 * Version 8.5 - Optimierte Ladungsreihenfolge mit verbesserter Fehlerbehandlung
 */
function csv_import_pro_load_plugin_files() {
    $files_to_include = [
        // === HAUPT-KLASSEN (in Abhängigkeits-Reihenfolge) ===
        'includes/core/class-csv-import-run.php',        // Benötigt core-functions.php
        
        // === FEATURE-KLASSEN ===
        'includes/classes/class-csv-import-backup-manager.php',
        'includes/classes/class-csv-import-notifications.php',
        'includes/classes/class-csv-import-performance-monitor.php',
        'includes/classes/class-csv-import-profile-manager.php',
        'includes/classes/class-csv-import-template-manager.php',
        'includes/classes/class-csv-import-validator.php',
        
        // === SCHEDULER (nach allen Dependencies) ===
        'includes/classes/class-csv-import-scheduler.php', // Benötigt core-functions.php + Error Handler
        
        // === ADMIN-BEREICH (nur wenn im Admin) ===
        'includes/admin/class-admin-menus.php',           // Benötigt alle anderen Klassen
        'includes/admin/admin-ajax.php',                  // AJAX-Handler
    ];

    $loaded_files = [];
    $failed_files = [];
    $load_errors = [];

    foreach ( $files_to_include as $file ) {
        // Admin-Dateien nur im Admin-Bereich laden
        if ( strpos( $file, 'includes/admin/' ) === 0 && ! is_admin() ) {
            continue;
        }
        
        $path = CSV_IMPORT_PRO_PATH . $file;
        if ( file_exists( $path ) ) {
            try {
                require_once $path;
                $loaded_files[] = $file;
                
                // Debug-Log für erfolgreiche Ladung
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'CSV Import Pro: Geladen - ' . basename( $file ) );
                }
            } catch ( ParseError $e ) {
                $error_msg = 'Parse Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
                $load_errors[] = basename( $file ) . ': ' . $error_msg;
                error_log( 'CSV Import Pro: Parse Error in ' . $file . ': ' . $e->getMessage() );
            } catch ( Exception $e ) {
                $error_msg = 'Exception: ' . $e->getMessage();
                $load_errors[] = basename( $file ) . ': ' . $error_msg;
                error_log( 'CSV Import Pro: Exception beim Laden von ' . $file . ': ' . $e->getMessage() );
            }
        } else {
            $failed_files[] = $file;
            error_log( 'CSV Import Pro: Datei fehlt - ' . $path );
        }
    }
    
    // Log-Zusammenfassung
    if ( function_exists( 'csv_import_log' ) ) {
        csv_import_log( 'info', 'Plugin-Dateien geladen', [
            'loaded_count' => count( $loaded_files ),
            'failed_count' => count( $failed_files ),
            'error_count' => count( $load_errors ),
            'failed_files' => $failed_files,
            'load_errors' => $load_errors
        ]);
    }
    
    // Admin-Notice für Probleme
    if ( ! empty( $failed_files ) || ! empty( $load_errors ) ) {
        add_action( 'admin_notices', function() use ( $failed_files, $load_errors ) {
            if ( ! empty( $failed_files ) ) {
                echo '<div class="notice notice-warning"><p>';
                echo '<strong>CSV Import Pro:</strong> ' . count( $failed_files ) . ' Dateien fehlen - Plugin möglicherweise unvollständig installiert.';
                echo '</p></div>';
            }
            
            if ( ! empty( $load_errors ) ) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>CSV Import Pro:</strong> ' . count( $load_errors ) . ' Dateien konnten nicht geladen werden:';
                echo '<ul style="margin-left: 20px;">';
                foreach ( array_slice( $load_errors, 0, 3 ) as $error ) { // Nur erste 3 Fehler anzeigen
                    echo '<li><code>' . esc_html( $error ) . '</code></li>';
                }
                if ( count( $load_errors ) > 3 ) {
                    echo '<li>... und ' . ( count( $load_errors ) - 3 ) . ' weitere</li>';
                }
                echo '</ul>';
                echo '</p></div>';
            }
        });
    }
    
    return empty( $failed_files ) && empty( $load_errors );
}

/**
 * Sichere Scheduler-Initialisierung mit umfassenden Dependency-Checks
 * Version 8.5 - Komplett überarbeitete Initialisierung
 */
function csv_import_safe_scheduler_init() {
    // 1. KRITISCHE DEPENDENCY-PRÜFUNG
    $critical_functions = [
        'csv_import_get_config',
        'csv_import_validate_config',
        'csv_import_start_import',
        'csv_import_is_import_running',
        'csv_import_log'
    ];
    
    $missing_functions = [];
    foreach ( $critical_functions as $func ) {
        if ( ! function_exists( $func ) ) {
            $missing_functions[] = $func;
        }
    }
    
    // 2. KLASSEN-VERFÜGBARKEIT PRÜFEN
    $critical_classes = [
        'CSV_Import_Scheduler',
        'CSV_Import_Error_Handler'
    ];
    
    $missing_classes = [];
    foreach ( $critical_classes as $class ) {
        if ( ! class_exists( $class ) ) {
            $missing_classes[] = $class;
        }
    }
    
    // 3. ENTSCHEIDUNG: DIREKTE INIT ODER FALLBACK
    if ( empty( $missing_functions ) && empty( $missing_classes ) ) {
        // === DIREKTE INITIALISIERUNG ===
        try {
            $scheduler_result = CSV_Import_Scheduler::init();
            
            if ( $scheduler_result ) {
                csv_import_log( 'info', 'Scheduler erfolgreich initialisiert (direkt)', [
                    'method' => 'direct',
                    'load_time' => microtime( true ),
                    'memory_usage' => memory_get_usage( true )
                ]);
                return true;
            } else {
                throw new Exception( 'Scheduler::init() gab false zurück' );
            }
            
        } catch ( Exception $e ) {
            csv_import_log( 'error', 'Direkte Scheduler-Initialisierung fehlgeschlagen: ' . $e->getMessage(), [
                'exception_class' => get_class( $e ),
                'trace' => $e->getTraceAsString()
            ]);
            // Fallback wird unten ausgeführt
        }
    }
    
    // === FALLBACK-INITIALISIERUNG ===
    $debug_info = [
        'missing_functions' => $missing_functions,
        'missing_classes' => $missing_classes,
        'current_hook' => current_action() ?: 'none',
        'load_time' => microtime( true ),
        'available_functions' => array_filter( $critical_functions, 'function_exists' ),
        'available_classes' => array_filter( $critical_classes, 'class_exists' )
    ];
    
    if ( function_exists( 'csv_import_log' ) ) {
        csv_import_log( 'warning', 'Scheduler-Fallback-Initialisierung erforderlich', $debug_info );
    } else {
        error_log( 'CSV Import Pro: Scheduler-Fallback - Dependencies fehlen: ' . wp_json_encode( $debug_info ) );
    }
    
    // Fallback mit mehreren Versuchen auf verschiedenen Hooks
    add_action( 'wp_loaded', function() use ( $debug_info ) {
        csv_import_scheduler_fallback_init( $debug_info, 'wp_loaded' );
    }, 999 );
    
    add_action( 'admin_init', function() use ( $debug_info ) {
        if ( is_admin() ) {
            csv_import_scheduler_fallback_init( $debug_info, 'admin_init' );
        }
    }, 999 );
    
    // Noch später Fallback für hartnäckige Fälle
    add_action( 'init', function() use ( $debug_info ) {
        csv_import_scheduler_fallback_init( $debug_info, 'init' );
    }, 9999 );
    
    return false;
}

/**
 * Fallback-Initialisierung mit erweiterten Checks
 * Version 8.5 - Robuste Mehrfach-Fallback-Logik
 */
function csv_import_scheduler_fallback_init( $original_debug_info, $hook_name ) {
    static $already_initialized = false;
    static $attempt_count = 0;
    
    $attempt_count++;
    
    if ( $already_initialized ) {
        return true; // Bereits erfolgreich initialisiert
    }
    
    // Maximum 5 Versuche pro Request
    if ( $attempt_count > 5 ) {
        return false;
    }
    
    // Re-Check der Dependencies
    $functions_now_available = array_filter( [
        'csv_import_get_config',
        'csv_import_validate_config',
        'csv_import_start_import',
        'csv_import_is_import_running',
        'csv_import_log'
    ], 'function_exists' );
    
    $classes_now_available = array_filter( [
        'CSV_Import_Scheduler',
        'CSV_Import_Error_Handler'
    ], 'class_exists' );
    
    // Minimum-Requirement: 4 von 5 Funktionen und Scheduler-Klasse
    if ( count( $functions_now_available ) >= 4 && in_array( 'CSV_Import_Scheduler', $classes_now_available ) ) {
        try {
            $result = CSV_Import_Scheduler::init();
            
            if ( $result ) {
                $already_initialized = true;
                
                if ( function_exists( 'csv_import_log' ) ) {
                    csv_import_log( 'info', "Scheduler erfolgreich initialisiert (Fallback via {$hook_name})", [
                        'method' => 'fallback',
                        'hook' => $hook_name,
                        'attempt' => $attempt_count,
                        'functions_available' => count( $functions_now_available ),
                        'classes_available' => count( $classes_now_available ),
                        'original_issues' => $original_debug_info,
                        'resolution_time' => microtime( true )
                    ]);
                }
                
                return true;
            }
            
        } catch ( Exception $e ) {
            if ( function_exists( 'csv_import_log' ) ) {
                csv_import_log( 'error', "Scheduler-Fallback fehlgeschlagen auf {$hook_name} (Versuch {$attempt_count}): " . $e->getMessage(), [
                    'exception_class' => get_class( $e ),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
    }
    
    // Letzter Versuch fehlgeschlagen - Admin-Notice setzen (nur einmal)
    if ( is_admin() && $hook_name === 'admin_init' && $attempt_count >= 3 ) {
        add_action( 'admin_notices', function() use ( $attempt_count, $functions_now_available, $classes_now_available ) {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>CSV Import Pro:</strong> Scheduler konnte nach ' . $attempt_count . ' Versuchen nicht initialisiert werden.</p>';
            echo '<p><strong>Status:</strong> ' . count( $functions_now_available ) . '/5 Funktionen verfügbar, ' . count( $classes_now_available ) . '/2 Klassen verfügbar.</p>';
            echo '<p>';
            echo '<a href="' . admin_url( 'plugins.php' ) . '" class="button">Plugin deaktivieren/reaktivieren</a> ';
            echo '<a href="' . wp_nonce_url( admin_url( 'tools.php?page=csv-import&csv_emergency_reset=1' ), 'csv_import_emergency_reset' ) . '" class="button">Notfall-Reset</a>';
            echo '</p>';
            echo '</div>';
        });
    }
    
    return false;
}

/**
 * Haupt-Initialisierungsfunktion mit verbesserter Dependency-Verwaltung.
 * Version 8.5 - Robuste Scheduler-Integration mit Fallbacks
 */
function csv_import_pro_init() {
    // Lade alle Plugin-Dateien
    $files_loaded = csv_import_pro_load_plugin_files();
    
    if ( ! $files_loaded ) {
        // Wenn kritische Dateien fehlen, Plugin-Execution stoppen
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'critical', 'Plugin-Initialization fehlgeschlagen - kritische Dateien fehlen oder haben Fehler' );
        }
        return false;
    }

    // === ADMIN-BEREICH INITIALISIERUNG ===
    if ( is_admin() && class_exists( 'CSV_Import_Pro_Admin' ) ) {
        try {
            new CSV_Import_Pro_Admin();
            
            if ( function_exists( 'csv_import_log' ) ) {
                csv_import_log( 'debug', 'Admin-Interface initialisiert' );
            }
        } catch ( Exception $e ) {
            if ( function_exists( 'csv_import_log' ) ) {
                csv_import_log( 'error', 'Admin-Interface-Initialisierung fehlgeschlagen: ' . $e->getMessage() );
            }
        }
    }
    
    // === SCHEDULER-INITIALISIERUNG (mit umfassenden Dependency-Checks) ===
    if ( class_exists( 'CSV_Import_Scheduler' ) ) {
        csv_import_safe_scheduler_init();
    } else {
        // Scheduler-Klasse nicht gefunden - späte Initialisierung versuchen
        add_action( 'wp_loaded', function() {
            if ( class_exists( 'CSV_Import_Scheduler' ) ) {
                csv_import_safe_scheduler_init();
            } else {
                error_log( 'CSV Import Pro: KRITISCH - CSV_Import_Scheduler Klasse nie verfügbar geworden' );
                
                if ( function_exists( 'csv_import_log' ) ) {
                    csv_import_log( 'critical', 'CSV_Import_Scheduler Klasse nie verfügbar geworden' );
                }
            }
        }, 999 );
    }
    
    // === WEITERE KOMPONENTEN INITIALISIERUNG ===
    
    // Backup Manager
    if ( class_exists( 'CSV_Import_Backup_Manager' ) ) {
        try {
            CSV_Import_Backup_Manager::init();
        } catch ( Exception $e ) {
            if ( function_exists( 'csv_import_log' ) ) {
                csv_import_log( 'warning', 'Backup Manager init fehlgeschlagen: ' . $e->getMessage() );
            }
        }
    }
    
    // Notifications
    if ( class_exists( 'CSV_Import_Notifications' ) ) {
        try {
            CSV_Import_Notifications::init();
        } catch ( Exception $e ) {
            if ( function_exists( 'csv_import_log' ) ) {
                csv_import_log( 'warning', 'Notifications init fehlgeschlagen: ' . $e->getMessage() );
            }
        }
    }
    
    // Performance Monitor
    if ( class_exists( 'CSV_Import_Performance_Monitor' ) ) {
        try {
            CSV_Import_Performance_Monitor::start();
        } catch ( Exception $e ) {
            if ( function_exists( 'csv_import_log' ) ) {
                csv_import_log( 'warning', 'Performance Monitor start fehlgeschlagen: ' . $e->getMessage() );
            }
        }
    }
    
    // === WARTUNGS-HOOKS REGISTRIEREN ===
    
    // Tägliche Wartung
    if ( ! wp_next_scheduled( 'csv_import_daily_maintenance' ) ) {
        wp_schedule_event( time(), 'daily', 'csv_import_daily_maintenance' );
    }
    
    // Wöchentliche Wartung
    if ( ! wp_next_scheduled( 'csv_import_weekly_maintenance' ) ) {
        wp_schedule_event( time(), 'weekly', 'csv_import_weekly_maintenance' );
    }
    
    // === INITIALIZATION ERFOLGREICH ===
    if ( function_exists( 'csv_import_log' ) ) {
        csv_import_log( 'debug', 'CSV Import Pro erfolgreich initialisiert', [
            'version' => CSV_IMPORT_PRO_VERSION,
            'scheduler_active' => class_exists( 'CSV_Import_Scheduler' ),
            'admin_active' => is_admin() && class_exists( 'CSV_Import_Pro_Admin' ),
            'core_functions_available' => function_exists( 'csv_import_get_config' ),
            'initialization_time' => microtime( true ),
            'memory_usage' => memory_get_usage( true )
        ]);
    }
    
    return true;
}

// === EMERGENCY HEALTH-CHECK SYSTEM ===
add_action( 'admin_init', function() {
    // Nur für Admins und nur einmal pro Session
    if ( ! current_user_can( 'manage_options' ) || get_transient( 'csv_import_health_checked' ) ) {
        return;
    }
    
    set_transient( 'csv_import_health_checked', true, 300 ); // 5 Minuten
    
    $critical_issues = [];
    $warnings = [];
    
    // 1. Core-Functions Check
    if ( ! function_exists( 'csv_import_get_config' ) ) {
        $critical_issues[] = 'Core-Funktionen nicht geladen';
    }
    
    // 2. Scheduler-Status Check
    if ( ! class_exists( 'CSV_Import_Scheduler' ) ) {
        $critical_issues[] = 'Scheduler-Klasse fehlt';
    } elseif ( get_option( 'csv_import_scheduled_frequency' ) && method_exists( 'CSV_Import_Scheduler', 'is_scheduled' ) && ! CSV_Import_Scheduler::is_scheduled() ) {
        $warnings[] = 'Scheduler-Konfiguration inkonsistent (Einstellungen vorhanden aber nicht geplant)';
    }
    
    // 3. WordPress Cron Check
    if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && get_option( 'csv_import_scheduled_frequency' ) ) {
        $warnings[] = 'WordPress Cron deaktiviert aber Scheduling konfiguriert';
    }
    
    // 4. File Permissions Check
    $upload_dir = wp_upload_dir();
    if ( ! is_writable( $upload_dir['basedir'] ) ) {
        $warnings[] = 'Upload-Verzeichnis nicht beschreibbar';
    }
    
    // Admin-Notice bei kritischen Problemen
    if ( ! empty( $critical_issues ) ) {
        add_action( 'admin_notices', function() use ( $critical_issues ) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>CSV Import Pro - Kritische Probleme erkannt:</strong></p>';
            echo '<ul style="margin-left: 20px;">';
            foreach ( $critical_issues as $issue ) {
                echo '<li>' . esc_html( $issue ) . '</li>';
            }
            echo '</ul>';
            echo '<p>';
            echo '<a href="' . admin_url( 'tools.php?page=csv-import' ) . '" class="button">Plugin-Dashboard</a> ';
            echo '<a href="' . admin_url( 'plugins.php' ) . '" class="button">Plugins verwalten</a> ';
            echo '<a href="' . wp_nonce_url( admin_url( 'tools.php?page=csv-import&csv_emergency_reset=1' ), 'csv_import_emergency_reset' ) . '" class="button button-secondary">Notfall-Reset</a>';
            echo '</p>';
            echo '</div>';
        });
    }
    
    // Admin-Notice bei Warnungen
    if ( ! empty( $warnings ) && empty( $critical_issues ) ) {
        add_action( 'admin_notices', function() use ( $warnings ) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>CSV Import Pro - Warnungen:</strong></p>';
            echo '<ul style="margin-left: 20px;">';
            foreach ( $warnings as $warning ) {
                echo '<li>' . esc_html( $warning ) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        });
    }
});

// Plugin nach dem Laden aller WordPress-Komponenten initialisieren
add_action( 'plugins_loaded', 'csv_import_pro_init', 10 );

/**
 * Plugin-Aktivierung mit verbesserter Fehlerbehandlung.
 * Version 8.5 - Erweiterte Validierung
 */
register_activation_hook( __FILE__, function() {
    // Prüfe ob Installer verfügbar ist
    if ( class_exists( 'Installer' ) ) {
        try {
            Installer::activate();
            
            // Erfolgreiche Aktivierung protokollieren
            if ( function_exists( 'csv_import_log' ) ) {
                csv_import_log( 'info', 'Plugin erfolgreich aktiviert', [
                    'version' => CSV_IMPORT_PRO_VERSION,
                    'wp_version' => get_bloginfo( 'version' ),
                    'php_version' => PHP_VERSION,
                    'memory_limit' => ini_get( 'memory_limit' ),
                    'max_execution_time' => ini_get( 'max_execution_time' )
                ]);
            }
            
            // Admin-Notice für erfolgreiche Aktivierung
            set_transient( 'csv_import_activated_notice', true, 30 );
            
        } catch ( Exception $e ) {
            // Aktivierungsfehler protokollieren
            error_log( 'CSV Import Pro Aktivierungsfehler: ' . $e->getMessage() );
            
            // Plugin bei kritischen Fehlern wieder deaktivieren
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( 
                '<h1>Plugin Aktivierung Fehlgeschlagen</h1>' .
                '<p><strong>CSV Import Pro</strong> konnte nicht aktiviert werden:</p>' .
                '<p><code>' . esc_html( $e->getMessage() ) . '</code></p>' .
                '<p>Mögliche Lösungen:</p>' .
                '<ul>' .
                '<li>WordPress und PHP-Version prüfen (Mindestanforderungen: WP 5.0+, PHP 7.4+)</li>' .
                '<li>Plugin neu herunterladen und installieren</li>' .
                '<li>Schreibrechte für wp-content/plugins/ prüfen</li>' .
                '<li>Speicherlimit erhöhen (memory_limit in php.ini)</li>' .
                '</ul>' .
                '<br><a href="' . admin_url( 'plugins.php' ) . '" class="button">Zurück zu Plugins</a>',
                'Plugin Aktivierung Fehlgeschlagen',
                ['back_link' => true]
            );
        }
    } else {
        // Installer-Klasse nicht verfügbar
        error_log( 'CSV Import Pro: Installer-Klasse nicht verfügbar bei Aktivierung' );
        wp_die( 
            '<h1>Plugin Installation Unvollständig</h1>' .
            '<p><strong>CSV Import Pro:</strong> Installer-Klasse fehlt.</p>' .
            '<p>Das Plugin wurde möglicherweise unvollständig installiert oder Dateien sind beschädigt.</p>' .
            '<p><strong>Lösung:</strong> Plugin neu herunterladen und installieren.</p>' .
            '<br><a href="' . admin_url( 'plugins.php' ) . '" class="button">Zurück zu Plugins</a>',
            'Plugin Installation Unvollständig',
            ['back_link' => true]
        );
    }
});

/**
 * Plugin-Deaktivierung mit kompletter Bereinigung.
 * Version 8.5 - Erweiterte Cleanup-Logik
 */
register_deactivation_hook( __FILE__, function() {
    try {
        // Alle geplanten Events löschen
        $scheduled_hooks = [
            'csv_import_scheduled',
            'csv_import_daily_cleanup', 
            'csv_import_weekly_maintenance',
            'csv_import_daily_maintenance'
        ];
        
        foreach ( $scheduled_hooks as $hook ) {
            wp_clear_scheduled_hook( $hook );
        }
        
        // Scheduler-spezifische Bereinigung
        if ( class_exists( 'CSV_Import_Scheduler' ) && method_exists( 'CSV_Import_Scheduler', 'unschedule_all' ) ) {
            CSV_Import_Scheduler::unschedule_all();
        }
        
        // Temporäre Plugin-Daten löschen
        $temp_options = [
            'csv_import_progress',
            'csv_import_running_lock',
            'csv_import_session_id',
            'csv_import_start_time',
            'csv_import_current_header',
            'csv_import_health_checked'
        ];
        
        foreach ( $temp_options as $option ) {
            delete_option( $option );
            delete_transient( $option );
        }
        
        // Deaktivierung protokollieren
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'info', 'Plugin deaktiviert - Bereinigung abgeschlossen', [
                'version' => CSV_IMPORT_PRO_VERSION,
                'cleaned_options' => count( $temp_options ),
                'cleaned_hooks' => count( $scheduled_hooks )
            ]);
        }
        
        error_log( 'CSV Import Pro: Plugin deaktiviert und bereinigt (Version ' . CSV_IMPORT_PRO_VERSION . ')' );
        
    } catch ( Exception $e ) {
        error_log( 'CSV Import Pro: Fehler bei Deaktivierung - ' . $e->getMessage() );
    }
});

/**
 * Plugin-Update-Hook für zukünftige Versionen.
 * Version 8.5 - Erweiterte Update-Logik
 */
add_action( 'upgrader_process_complete', function( $upgrader_object, $options ) {
    if ( isset( $options['plugin'] ) && $options['plugin'] === CSV_IMPORT_PRO_BASENAME ) {
        try {
            $previous_version = get_option( 'csv_import_version', 'unbekannt' );
            
            // Plugin wurde aktualisiert
            if ( function_exists( 'csv_import_log' ) ) {
                csv_import_log( 'info', 'Plugin aktualisiert', [
                    'new_version' => CSV_IMPORT_PRO_VERSION,
                    'previous_version' => $previous_version,
                    'update_time' => current_time( 'mysql' )
                ]);
            }
            
            // Version in Datenbank aktualisieren
            update_option( 'csv_import_version', CSV_IMPORT_PRO_VERSION );
            
            // Cache-Bereinigung nach Update
            if ( function_exists( 'csv_import_cleanup_temp_files' ) ) {
                csv_import_cleanup_temp_files();
            }
            
            // Health-Check nach Update zurücksetzen
            delete_transient( 'csv_import_health_checked' );
            
            // Admin-Notice für erfolgreiches Update
            set_transient( 'csv_import_updated_notice', [
                'previous_version' => $previous_version,
                'new_version' => CSV_IMPORT_PRO_VERSION
            ], 300 );
            
        } catch ( Exception $e ) {
            error_log( 'CSV Import Pro: Fehler bei Update-Verarbeitung - ' . $e->getMessage() );
        }
    }
}, 10, 2 );

/**
 * Emergency-Reset für hängende Imports (Admin-Interface).
 * Version 8.5 - Erweiterte Reset-Funktionalität
 */
add_action( 'admin_init', function() {
    if ( isset( $_GET['csv_emergency_reset'] ) && $_GET['csv_emergency_reset'] === '1' ) {
        // Nur für Admins
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Keine Berechtigung für diese Aktion.' );
        }
        
        // Nonce-Check
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'csv_import_emergency_reset' ) ) {
            wp_die( 'Sicherheitscheck fehlgeschlagen.' );
        }
        
        try {
            // Reset durchführen
            if ( function_exists( 'csv_import_force_reset_import_status' ) ) {
                csv_import_force_reset_import_status();
            }
            
            // Zusätzliche Bereinigung
            if ( function_exists( 'csv_import_cleanup_temp_files' ) ) {
                csv_import_cleanup_temp_files();
            }
            
            if ( function_exists( 'csv_import_cleanup_dead_processes' ) ) {
                csv_import_cleanup_dead_processes();
            }
            
            // Scheduler-Reset
            if ( class_exists( 'CSV_Import_Scheduler' ) && method_exists( 'CSV_Import_Scheduler', 'unschedule_all' ) ) {
                CSV_Import_Scheduler::unschedule_all();
            }
            
            // Alle Plugin-Locks und Transients löschen
            global $wpdb;
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%csv_import%lock%'" );
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_csv_import_%'" );
            $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_csv_import_%'" );
            
            // Health-Check zurücksetzen
            delete_transient( 'csv_import_health_checked' );
            
            // Erfolgs-Notice
            set_transient( 'csv_import_emergency_reset_success', true, 30 );
            
            if ( function_exists( 'csv_import_log' ) ) {
                csv_import_log( 'warning', 'Notfall-Reset durchgeführt', [
                    'user_id' => get_current_user_id(),
                    'user_login' => wp_get_current_user()->user_login,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'reset_time' => current_time( 'mysql' )
                ]);
            }
            
        } catch ( Exception $e ) {
            error_log( 'CSV Import Pro: Fehler bei Emergency-Reset - ' . $e->getMessage() );
            set_transient( 'csv_import_emergency_reset_error', $e->getMessage(), 30 );
        }
        
        // Redirect zurück zum Plugin
        wp_redirect( add_query_arg( [
            'page' => 'csv-import',
            'reset' => get_transient( 'csv_import_emergency_reset_success' ) ? 'success' : 'error'
        ], admin_url( 'tools.php' ) ) );
        exit;
    }
});

/**
 * Admin-Notices für verschiedene Plugin-Events.
 * Version 8.5 - Konsolidierte Notice-Verwaltung
 */
add_action( 'admin_notices', function() {
    // Nur auf Plugin-Seiten anzeigen
    if ( ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'csv-import' ) === false ) {
        return;
    }
    
    // Aktivierungs-Notice
    if ( get_transient( 'csv_import_activated_notice' ) ) {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>CSV Import Pro</strong> wurde erfolgreich aktiviert! ';
        echo '<a href="' . admin_url( 'tools.php?page=csv-import-settings' ) . '">Jetzt konfigurieren</a></p>';
        echo '</div>';
        delete_transient( 'csv_import_activated_notice' );
    }
    
    // Update-Notice
    if ( $update_info = get_transient( 'csv_import_updated_notice' ) ) {
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>CSV Import Pro</strong> wurde erfolgreich von Version ' . esc_html( $update_info['previous_version'] ) . ' auf ' . esc_html( $update_info['new_version'] ) . ' aktualisiert!</p>';
        echo '</div>';
        delete_transient( 'csv_import_updated_notice' );
    }
    
    // Emergency-Reset Success-Notice
    if ( get_transient( 'csv_import_emergency_reset_success' ) ) {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>CSV Import Pro:</strong> Notfall-Reset erfolgreich durchgeführt. Alle hängenden Prozesse und Locks wurden bereinigt.</p>';
        echo '</div>';
        delete_transient( 'csv_import_emergency_reset_success' );
    }
    
    // Emergency-Reset Error-Notice
    if ( $error_msg = get_transient( 'csv_import_emergency_reset_error' ) ) {
        echo '<div class="notice notice-error is-dismissible">';
        echo '<p><strong>CSV Import Pro:</strong> Notfall-Reset fehlgeschlagen: ' . esc_html( $error_msg ) . '</p>';
        echo '</div>';
        delete_transient( 'csv_import_emergency_reset_error' );
    }
    
    // WordPress Cron Warnung
    if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && get_option( 'csv_import_scheduled_frequency' ) ) {
        echo '<div class="notice notice-warning">';
        echo '<p><strong>CSV Import Pro:</strong> WordPress Cron ist deaktiviert, aber ein geplanter Import ist konfiguriert. ';
        echo 'Für automatische Imports benötigen Sie einen externen Cron-Job oder aktivieren Sie WordPress Cron.</p>';
        echo '</div>';
    }
    
    // Dependency-Warnung
    if ( ! function_exists( 'csv_import_get_config' ) ) {
        echo '<div class="notice notice-error">';
        echo '<p><strong>CSV Import Pro:</strong> Core-Funktionen nicht verfügbar. ';
        echo '<a href="' . admin_url( 'plugins.php' ) . '">Plugin deaktivieren und wieder aktivieren</a> ';
        echo 'oder Support kontaktieren.</p>';
        echo '</div>';
    }
});

/**
 * Plugin-Health-Check für Admin-Dashboard.
 * Version 8.5 - Erweiterte Health-Informationen
 */
add_action( 'wp_dashboard_setup', function() {
    if ( current_user_can( 'manage_options' ) ) {
        wp_add_dashboard_widget(
            'csv_import_health_widget',
            'CSV Import Pro - Status',
            function() {
                echo '<div style="display: flex; gap: 15px; flex-wrap: wrap;">';
                
                // Plugin-Status
                $core_ok = function_exists( 'csv_import_get_config' );
                $scheduler_ok = class_exists( 'CSV_Import_Scheduler' );
                $all_good = $core_ok && $scheduler_ok;
                
                echo '<div style="flex: 1; min-width: 200px;">';
                echo '<h4>' . ( $all_good ? '✅ Plugin OK' : '⚠️ Plugin Probleme' ) . '</h4>';
                echo '<p>Version: ' . CSV_IMPORT_PRO_VERSION . '</p>';
                if ( ! $core_ok ) echo '<p style="color: red;">⚠️ Core-Funktionen fehlen</p>';
                if ( ! $scheduler_ok ) echo '<p style="color: red;">⚠️ Scheduler fehlt</p>';
                echo '</div>';
                
                // Import-Status
                if ( function_exists( 'csv_import_get_progress' ) ) {
                    $progress = csv_import_get_progress();
                    $is_running = $progress['running'] ?? false;
                    
                    echo '<div style="flex: 1; min-width: 200px;">';
                    echo '<h4>' . ( $is_running ? '🔄 Import läuft' : '💤 Kein Import' ) . '</h4>';
                    if ( $is_running ) {
                        echo '<p>' . ( $progress['percent'] ?? 0 ) . '% abgeschlossen</p>';
                        if ( isset( $progress['eta_human'] ) ) {
                            echo '<p>ETA: ' . esc_html( $progress['eta_human'] ) . '</p>';
                        }
                    } else {
                        $last_run = get_option( 'csv_import_last_run', 'Nie' );
                        if ( $last_run !== 'Nie' ) {
                            echo '<p>Letzter Import: ' . human_time_diff( strtotime( $last_run ) ) . ' ago</p>';
                        }
                    }
                    echo '</div>';
                }
                
                // Scheduler-Status
                if ( class_exists( 'CSV_Import_Scheduler' ) && method_exists( 'CSV_Import_Scheduler', 'is_scheduled' ) ) {
                    $is_scheduled = CSV_Import_Scheduler::is_scheduled();
                    echo '<div style="flex: 1; min-width: 200px;">';
                    echo '<h4>' . ( $is_scheduled ? '⏰ Geplant' : '⏸️ Nicht geplant' ) . '</h4>';
                    if ( $is_scheduled && method_exists( 'CSV_Import_Scheduler', 'get_next_scheduled' ) ) {
                        $next_run = CSV_Import_Scheduler::get_next_scheduled();
                        if ( $next_run ) {
                            echo '<p>Nächster Run: ' . human_time_diff( $next_run ) . '</p>';
                        }
                    }
                    echo '</div>';
                }
                
                echo '</div>';
                
                // Quick-Actions
                echo '<div style="margin-top: 15px; text-align: center;">';
                echo '<a href="' . admin_url( 'tools.php?page=csv-import' ) . '" class="button button-primary">Import Dashboard</a> ';
                echo '<a href="' . admin_url( 'tools.php?page=csv-import-settings' ) . '" class="button">Einstellungen</a> ';
                
                // Emergency-Reset nur bei Problemen anzeigen
                if ( ! $all_good || ( function_exists( 'csv_import_is_import_running' ) && csv_import_is_import_running() ) ) {
                    echo '<a href="' . wp_nonce_url( admin_url( 'tools.php?page=csv-import&csv_emergency_reset=1' ), 'csv_import_emergency_reset' ) . '" class="button button-secondary" onclick="return confirm(\'Notfall-Reset wirklich durchführen?\')">🔧 Notfall-Reset</a>';
                }
                
                echo '</div>';
            }
        );
    }
});

/**
 * Globaler Fehler-Handler für unerwartete Plugin-Fehler.
 * Version 8.5 - Erweiterte Error-Detection
 */
add_action( 'wp_loaded', function() {
    // Prüfe Plugin-Integrität
    $critical_functions = [
        'csv_import_get_config',
        'csv_import_validate_config', 
        'csv_import_get_progress',
        'csv_import_log'
    ];
    
    $missing_functions = array_filter( $critical_functions, function( $func ) {
        return ! function_exists( $func );
    });
    
    $critical_classes = [
        'CSV_Import_Scheduler',
        'CSV_Import_Error_Handler',
        'CSV_Import_Pro_Run'
    ];
    
    $missing_classes = array_filter( $critical_classes, function( $class ) {
        return ! class_exists( $class );
    });
    
    if ( ( ! empty( $missing_functions ) || ! empty( $missing_classes ) ) && is_admin() ) {
        add_action( 'admin_notices', function() use ( $missing_functions, $missing_classes ) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>CSV Import Pro:</strong> Plugin-Integrität beeinträchtigt.</p>';
            
            if ( ! empty( $missing_functions ) ) {
                echo '<p>Fehlende Funktionen: <code>' . implode( ', ', $missing_functions ) . '</code></p>';
            }
            
            if ( ! empty( $missing_classes ) ) {
                echo '<p>Fehlende Klassen: <code>' . implode( ', ', $missing_classes ) . '</code></p>';
            }
            
            echo '<p>';
            echo '<a href="' . admin_url( 'plugins.php' ) . '" class="button button-primary">Plugin deaktivieren/reaktivieren</a> ';
            echo '<a href="' . wp_nonce_url( admin_url( 'tools.php?page=csv-import&csv_emergency_reset=1' ), 'csv_import_emergency_reset' ) . '" class="button">Notfall-Reset</a>';
            echo '</p>';
            echo '</div>';
        });
    }
});

/**
 * Debug-Helper für Entwicklung (nur bei WP_DEBUG)
 * Version 8.5 - Erweiterte Debug-Informationen
 */
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    add_action( 'wp_footer', function() {
        if ( current_user_can( 'manage_options' ) && isset( $_GET['csv_debug'] ) ) {
            $debug_data = [
                'plugin_info' => [
                    'version' => CSV_IMPORT_PRO_VERSION,
                    'path' => CSV_IMPORT_PRO_PATH,
                    'url' => CSV_IMPORT_PRO_URL
                ],
                'function_availability' => [
                    'csv_import_get_config' => function_exists( 'csv_import_get_config' ),
                    'csv_import_validate_config' => function_exists( 'csv_import_validate_config' ),
                    'csv_import_start_import' => function_exists( 'csv_import_start_import' ),
                    'csv_import_is_import_running' => function_exists( 'csv_import_is_import_running' ),
                    'csv_import_log' => function_exists( 'csv_import_log' )
                ],
                'class_availability' => [
                    'CSV_Import_Scheduler' => class_exists( 'CSV_Import_Scheduler' ),
                    'CSV_Import_Error_Handler' => class_exists( 'CSV_Import_Error_Handler' ),
                    'CSV_Import_Pro_Run' => class_exists( 'CSV_Import_Pro_Run' ),
                    'CSV_Import_Pro_Admin' => class_exists( 'CSV_Import_Pro_Admin' )
                ],
                'scheduler_status' => class_exists( 'CSV_Import_Scheduler' ) && method_exists( 'CSV_Import_Scheduler', 'debug_scheduler_status' ) 
                    ? CSV_Import_Scheduler::debug_scheduler_status() 
                    : ['error' => 'Scheduler nicht verfügbar'],
                'system_info' => [
                    'wp_version' => get_bloginfo( 'version' ),
                    'php_version' => PHP_VERSION,
                    'memory_limit' => ini_get( 'memory_limit' ),
                    'max_execution_time' => ini_get( 'max_execution_time' ),
                    'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
                    'wp_debug' => defined( 'WP_DEBUG' ) && WP_DEBUG
                ]
            ];
            
            echo '<script>console.log("CSV Import Pro Debug Info:", ' . wp_json_encode( $debug_data, JSON_PRETTY_PRINT ) . ');</script>';
        }
    });
}

// === PLUGIN VOLLSTÄNDIG GELADEN ===

// Final-Debug-Information für Entwicklung
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    error_log( 'CSV Import Pro v' . CSV_IMPORT_PRO_VERSION . ' - Haupt-Plugin-Datei vollständig geladen (korrigierte Version 8.5)' );
}


// Debug-Admin-Seite hinzufügen
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'CSV Import Debug',
        'CSV Import Debug',
        'manage_options',
        'csv-import-debug',
        'csv_import_debug_page'
    );
});

function csv_import_debug_page() {
    global $wpdb;

    $data = [];

    // Formular-Handling für Bereinigung
    if (isset($_POST['csv_import_cleanup']) && check_admin_referer('csv_import_debug')) {
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%import_lock%' OR option_name LIKE '%csv_import_progress%'");
        if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}actionscheduler_actions'")) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE %s AND status IN ('in-progress','pending')",
                    'csv_import%'
                )
            );
        }
        $data['cleanup_message'] = 'Alle Import-Locks und hängenden Prozesse wurden bereinigt.';
    }

    // Daten für die Ansicht sammeln
    $data['locks'] = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%import_lock%' OR option_name = 'csv_import_progress'");
    
    if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}actionscheduler_actions'")) {
        $data['stuck_jobs'] = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT hook, status, scheduled_date_gmt FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE %s AND status IN ('in-progress','pending')",
                'csv_import%'
            )
        );
    } else {
        $data['stuck_jobs'] = [];
    }

    // Die neue View-Datei laden
    extract($data);
    $template_path = CSV_IMPORT_PRO_PATH . 'includes/admin/views/page-debug.php';
    if (file_exists($template_path)) {
        include $template_path;
    } else {
        echo '<div class="wrap"><h1>Fehler</h1><p>Debug-Ansichtsdatei nicht gefunden.</p></div>';
    }
}
