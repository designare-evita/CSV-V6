<?php
/**
 * Core-Funktionen für das CSV Import Pro Plugin
 * FINALE VERSION: Mit zeilenweiser Verarbeitung (Streaming) und Fehlerkorrekturen.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direkten Zugriff verhindern
}

// ===================================================================
// VORBEUGENDE MAßNAHMEN & SCHUTZ VOR HÄNGENDEN IMPORTS
// ===================================================================

function csv_import_check_stuck_imports() {
    $progress = get_option('csv_import_progress', []);
    if (!empty($progress['running']) && !empty($progress['start_time'])) {
        $runtime = time() - $progress['start_time'];
        if ($runtime > 900) { // 15 Minuten
            csv_import_force_reset_import_status();
            csv_import_log('warning', 'Hängender Import-Prozess wurde automatisch zurückgesetzt', ['runtime' => $runtime]);
            set_transient('csv_import_stuck_reset_notice', true, 300);
        }
    }
}
add_action('admin_init', 'csv_import_check_stuck_imports');

function csv_import_force_reset_import_status() {
    $import_options = ['csv_import_progress', 'csv_import_session_id', 'csv_import_start_time', 'csv_import_current_header', 'csv_import_running_lock', 'csv_import_batch_progress'];
    foreach ($import_options as $option) {
        delete_option($option);
    }
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%csv_import%lock%'");
    csv_import_log('info', 'Import-Status wurde komplett zurückgesetzt (Notfall-Reset)');
}

function csv_import_is_import_running() {
    $progress = get_option('csv_import_progress', []);
    if (empty($progress) || empty($progress['running'])) {
        return false;
    }
    if (!empty($progress['start_time']) && (time() - $progress['start_time'] > 900)) {
        csv_import_force_reset_import_status();
        return false;
    }
    return true;
}

// ===================================================================
// KONFIGURATIONSFUNKTIONEN
// ===================================================================

function csv_import_get_config(): array {
    $config_keys = [
        'template_id', 'post_type', 'post_status', 'page_builder',
        'dropbox_url', 'local_path', 'image_source', 'image_folder',
        'memory_limit', 'time_limit', 'seo_plugin', 'required_columns',
        'skip_duplicates', 'taxonomy_mapping'
    ];
    $config = [];
    foreach ($config_keys as $key) {
        $config[$key] = get_option('csv_import_' . $key, csv_import_get_default_value($key));
    }
    if (is_string($config['required_columns'])) {
        $config['required_columns'] = array_filter(array_map('trim', explode("\n", $config['required_columns'] ?? '')));
    }
    return $config;
}

function csv_import_get_default_value(string $key) {
    $defaults = [
        'template_id'      => 0,
        'post_type'        => 'page',
        'post_status'      => 'draft',
        'page_builder'     => 'gutenberg',
        'dropbox_url'      => '',
        'local_path'       => 'data/landingpages.csv',
        'image_source'     => 'media_library',
        'image_folder'     => 'wp-content/uploads/csv-import-images/',
        'memory_limit'     => '256M',
        'time_limit'       => 300,
        'seo_plugin'       => 'none',
        'required_columns' => "post_title\npost_name",
        'skip_duplicates'  => true,
        'taxonomy_mapping' => []
    ];
    return $defaults[$key] ?? null;
}

// ===================================================================
// CSV VERARBEITUNGSFUNKTIONEN (OPTIMIERT)
// ===================================================================

function csv_import_load_csv_data(string $source, array $config): array {
    $file_handle = null;
    $temp_file_path = null;
    try {
        if ($source === 'dropbox') {
            $temp_file_path = csv_import_download_dropbox_to_temp($config);
            $file_handle = fopen($temp_file_path, 'r');
        } elseif ($source === 'local') {
            $file_path = ABSPATH . ltrim($config['local_path'], '/');
            if (!file_exists($file_path) || !is_readable($file_path)) {
                throw new Exception('Lokale CSV-Datei nicht gefunden oder nicht lesbar: ' . $config['local_path']);
            }
            $file_handle = fopen($file_path, 'r');
        } else {
            throw new Exception('Unbekannte CSV-Quelle: ' . $source);
        }
        if ($file_handle === false) {
            throw new Exception('Datei konnte nicht zum Lesen geöffnet werden.');
        }
        return csv_import_process_csv_stream($file_handle);
    } finally {
        if (is_resource($file_handle)) {
            fclose($file_handle);
        }
        if ($temp_file_path && file_exists($temp_file_path)) {
            @unlink($temp_file_path);
        }
    }
}

function csv_import_download_dropbox_to_temp(array $config): string {
    if (empty($config['dropbox_url'])) {
        throw new Exception('Dropbox URL nicht konfiguriert');
    }
    $download_url = str_replace('?dl=0', '?dl=1', $config['dropbox_url']);
    $temp_file = wp_tempnam('csv_import');
    if (!$temp_file) {
        throw new Exception('Konnte keine temporäre Datei erstellen.');
    }
    $response = wp_remote_get($download_url, ['timeout' => 60, 'stream' => true, 'filename' => $temp_file]);
    if (is_wp_error($response)) {
        @unlink($temp_file);
        throw new Exception('Dropbox-Download fehlgeschlagen: ' . $response->get_error_message());
    }
    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code !== 200) {
        @unlink($temp_file);
        throw new Exception("Dropbox-Datei nicht verfügbar (HTTP {$http_code})");
    }
    return $temp_file;
}

function csv_import_process_csv_stream($file_handle): array {
    if (fgets($file_handle, 4) !== "\xef\xbb\xbf") {
        rewind($file_handle);
    }
    $header_line = fgets($file_handle);
    if ($header_line === false) {
        throw new Exception('Konnte die Header-Zeile nicht lesen. Die Datei ist möglicherweise leer.');
    }
    $delimiter = csv_import_detect_csv_delimiter($header_line);
    $headers = array_map('trim', str_getcsv(trim($header_line), $delimiter));
    if (empty(array_filter($headers))) {
        throw new Exception('Keine gültigen Spalten-Header gefunden.');
    }
    $data = [];
    while (($row = fgetcsv($file_handle, 0, $delimiter)) !== false) {
        if (count($row) === 1 && is_null($row[0])) continue;
        $row_data = [];
        foreach ($headers as $index => $header) {
            $row_data[$header] = isset($row[$index]) ? trim($row[$index]) : '';
        }
        $data[] = $row_data;
    }
    return ['headers' => $headers, 'data' => $data, 'total_rows' => count($data), 'delimiter' => $delimiter];
}

function csv_import_detect_csv_delimiter(string $header_line): string {
    $delimiters = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];
    foreach ($delimiters as $delimiter => &$count) {
        $count = count(str_getcsv($header_line, $delimiter));
    }
    return array_search(max($delimiters), $delimiters);
}

// ===================================================================
// KONFIGURATIONSFUNKTIONEN
// ===================================================================

/**
 * Holt die gesamte Plugin-Konfiguration aus der Datenbank.
 *
 * @return array
 */
function csv_import_get_config(): array {
    $config_keys = [
        'template_id', 'post_type', 'post_status', 'page_builder',
        'dropbox_url', 'local_path', 'image_source', 'image_folder',
        'memory_limit', 'time_limit', 'seo_plugin', 'required_columns',
        'skip_duplicates'
    ];

    $config = [];
    foreach ( $config_keys as $key ) {
        $config[ $key ] = get_option( 'csv_import_' . $key, csv_import_get_default_value( $key ) );
    }

    // Required columns als Array verarbeiten
    if ( is_string( $config['required_columns'] ) ) {
        $config['required_columns'] = array_filter(
            array_map( 'trim', explode( "\n", $config['required_columns'] ?? '' ) )
        );
    }

    return $config;
}

/**
 * Gibt Standardwerte für die Plugin-Einstellungen zurück.
 *
 * @param string $key Der Einstellungs-Schlüssel.
 * @return mixed
 */
function csv_import_get_default_value( string $key ) {
    $defaults = [
        'template_id'      => 0,
        'post_type'        => 'page',
        'post_status'      => 'draft',
        'page_builder'     => 'gutenberg',
        'dropbox_url'      => '',
        'local_path'       => 'data/landingpages.csv',
        'image_source'     => 'media_library',
        'image_folder'     => 'wp-content/uploads/csv-import-images/',
        'memory_limit'     => '256M',
        'time_limit'       => 300,
        'seo_plugin'       => 'none',
        'required_columns' => "post_title\npost_name",
        'skip_duplicates'  => true
    ];

    return $defaults[ $key ] ?? null;
}

// ===================================================================
// VALIDIERUNGSFUNKTIONEN
// ===================================================================

/**
 * Validiert die Plugin-Konfiguration
 * @param array $config Konfigurationsdaten
 * @return array Validierungsergebnis
 */
function csv_import_validate_config( $config ): array {
    $errors = [];
    $validation = [
        'valid' => true,
        'errors' => [],
        'dropbox_ready' => false,
        'local_ready' => false
    ];
    
    // Post-Typ prüfen
    if ( empty( $config['post_type'] ) || ! post_type_exists( $config['post_type'] ) ) {
        $errors[] = 'Ungültiger oder fehlender Post-Typ: ' . ($config['post_type'] ?? 'nicht gesetzt');
    }
    
    // Post-Status prüfen
    $valid_statuses = ['publish', 'draft', 'private', 'pending'];
    if ( ! in_array( $config['post_status'] ?? '', $valid_statuses ) ) {
        $errors[] = 'Ungültiger Post-Status: ' . ($config['post_status'] ?? 'nicht gesetzt');
    }
    
    // Template ID prüfen (falls Elementor/Gutenberg)
    if ( in_array( $config['page_builder'] ?? '', ['elementor', 'gutenberg'] ) ) {
        if ( empty( $config['template_id'] ) || ! is_numeric( $config['template_id'] ) ) {
            $errors[] = 'Template ID ist erforderlich für den gewählten Page Builder';
        } else {
            // Prüfen ob Template existiert
            $template_post = get_post( $config['template_id'] );
            if ( ! $template_post ) {
                $errors[] = 'Template mit ID ' . $config['template_id'] . ' wurde nicht gefunden';
            }
        }
    }
    
    // Dropbox URL prüfen
    if ( ! empty( $config['dropbox_url'] ) ) {
        if ( filter_var( $config['dropbox_url'], FILTER_VALIDATE_URL ) ) {
            // Zusätzlich prüfen ob es eine Dropbox URL ist
            if ( strpos( $config['dropbox_url'], 'dropbox.com' ) !== false ) {
                $validation['dropbox_ready'] = true;
            } else {
                $errors[] = 'URL ist kein gültiger Dropbox-Link';
            }
        } else {
            $errors[] = 'Dropbox URL ist nicht gültig: ' . $config['dropbox_url'];
        }
    }
    
    // Lokaler Pfad prüfen
    if ( ! empty( $config['local_path'] ) ) {
        $full_path = ABSPATH . ltrim( $config['local_path'], '/' );
        if ( file_exists( $full_path ) && is_readable( $full_path ) ) {
            $validation['local_ready'] = true;
        } else {
            $errors[] = 'Lokaler Pfad existiert nicht oder ist nicht lesbar: ' . $config['local_path'];
        }
    }
    
    // Mindestens eine Quelle muss konfiguriert sein
    if ( ! $validation['dropbox_ready'] && ! $validation['local_ready'] ) {
        $errors[] = 'Mindestens eine CSV-Quelle (Dropbox oder lokal) muss konfiguriert und verfügbar sein';
    }
    
    // Erforderliche Spalten prüfen
    $required_columns = $config['required_columns'] ?? [];
    if ( is_string( $required_columns ) ) {
        $required_columns = array_filter( array_map( 'trim', explode( "\n", $required_columns ) ) );
    }
    if ( empty( $required_columns ) ) {
        $errors[] = 'Erforderliche Spalten müssen definiert sein';
    }
    
    // Bildordner prüfen (falls Bildimport aktiviert)
    if ( ( $config['image_source'] ?? 'none' ) !== 'none' ) {
        $image_dir = ABSPATH . ltrim( $config['image_folder'] ?? '', '/' );
        if ( ! is_dir( $image_dir ) ) {
            $errors[] = 'Bildordner existiert nicht: ' . ($config['image_folder'] ?? 'nicht gesetzt');
        } elseif ( ! is_writable( $image_dir ) ) {
            $errors[] = 'Bildordner ist nicht beschreibbar: ' . $config['image_folder'];
        }
    }
    
    // Memory Limit prüfen
    $memory_limit = $config['memory_limit'] ?? '256M';
    $memory_bytes = csv_import_convert_to_bytes( $memory_limit );
    if ( $memory_bytes < csv_import_convert_to_bytes( '128M' ) ) {
        $errors[] = 'Memory Limit sollte mindestens 128M betragen (aktuell: ' . $memory_limit . ')';
    }
    
    $validation['errors'] = $errors;
    $validation['valid'] = empty( $errors );
    
    return $validation;
}

/**
 * Validiert eine CSV-Quelle (Dropbox oder lokal)
 * @param string $type 'dropbox' oder 'local'
 * @param array $config Plugin-Konfiguration
 * @return array Validierungsergebnis
 */
function csv_import_validate_csv_source( string $type, array $config ): array {
    $result = [
        'valid' => false,
        'message' => '',
        'rows' => 0,
        'columns' => [],
        'sample_data' => []
    ];
    
    try {
        if ( $type === 'dropbox' ) {
            $result = csv_import_validate_dropbox_source( $config );
        } elseif ( $type === 'local' ) {
            $result = csv_import_validate_local_source( $config );
        } else {
            throw new Exception( 'Unbekannter Quelltyp: ' . $type );
        }
    } catch ( Exception $e ) {
        $result['message'] = 'Validierungsfehler: ' . $e->getMessage();
        
        // Fehler loggen
        if ( class_exists( 'CSV_Import_Error_Handler' ) ) {
            CSV_Import_Error_Handler::handle(
                CSV_Import_Error_Handler::LEVEL_ERROR,
                'CSV-Quellen-Validierung fehlgeschlagen: ' . $e->getMessage(),
                [
                    'type' => $type,
                    'config' => $config,
                    'trace' => $e->getTraceAsString()
                ]
            );
        } else {
            error_log( 'CSV Import Pro: CSV-Validierung fehlgeschlagen - ' . $e->getMessage() );
        }
    }
    
    return $result;
}

/**
 * Validiert Dropbox CSV-Quelle
 */
function csv_import_validate_dropbox_source( array $config ): array {
    if ( empty( $config['dropbox_url'] ) ) {
        throw new Exception( 'Dropbox URL nicht konfiguriert' );
    }
    
    // Dropbox URL zu direktem Download-Link umwandeln
    $download_url = $config['dropbox_url'];
    if ( strpos( $download_url, 'dropbox.com' ) !== false ) {
        // URL normalisieren für direkten Download
        $download_url = str_replace( 'www.dropbox.com', 'dl.dropboxusercontent.com', $download_url );
        $download_url = str_replace( 'dropbox.com', 'dl.dropboxusercontent.com', $download_url );
        $download_url = str_replace( '?dl=0', '', $download_url );
        $download_url = str_replace( '?dl=1', '', $download_url );
        if ( strpos( $download_url, '?' ) === false ) {
            $download_url .= '?raw=1';
        }
    }
    
    // CSV-Datei herunterladen und validieren
    $response = wp_remote_get( $download_url, [
        'timeout' => 30,
        'headers' => [
            'User-Agent' => 'CSV Import Pro/' . (defined('CSV_IMPORT_PRO_VERSION') ? CSV_IMPORT_PRO_VERSION : '5.1')
        ]
    ] );
    
    if ( is_wp_error( $response ) ) {
        throw new Exception( 'Dropbox-Datei konnte nicht abgerufen werden: ' . $response->get_error_message() );
    }
    
    $http_code = wp_remote_retrieve_response_code( $response );
    if ( $http_code !== 200 ) {
        throw new Exception( 'Dropbox-Datei nicht verfügbar (HTTP ' . $http_code . ')' );
    }
    
    $csv_content = wp_remote_retrieve_body( $response );
    if ( empty( $csv_content ) ) {
        throw new Exception( 'Dropbox-Datei ist leer oder konnte nicht gelesen werden' );
    }
    
    return csv_import_analyze_csv_content( $csv_content, 'Dropbox' );
}

/**
 * Validiert lokale CSV-Quelle
 */
function csv_import_validate_local_source( array $config ): array {
    if ( empty( $config['local_path'] ) ) {
        throw new Exception( 'Lokaler Pfad nicht konfiguriert' );
    }
    
    $file_path = ABSPATH . ltrim( $config['local_path'], '/' );
    
    if ( ! file_exists( $file_path ) ) {
        throw new Exception( 'Datei nicht gefunden: ' . $config['local_path'] . ' (Vollständiger Pfad: ' . $file_path . ')' );
    }
    
    if ( ! is_readable( $file_path ) ) {
        throw new Exception( 'Datei nicht lesbar: ' . $config['local_path'] );
    }
    
    $csv_content = file_get_contents( $file_path );
    if ( $csv_content === false ) {
        throw new Exception( 'Datei konnte nicht gelesen werden: ' . $config['local_path'] );
    }
    
    return csv_import_analyze_csv_content( $csv_content, 'Lokal (' . basename( $file_path ) . ')' );
}

/**
 * Analysiert CSV-Inhalt und gibt Validierungsergebnis zurück
 */
function csv_import_analyze_csv_content( string $csv_content, string $source_name ): array {
    if ( empty( trim( $csv_content ) ) ) {
        throw new Exception( 'CSV-Datei ist leer' );
    }
    
    // CSV-Inhalt normalisieren
    $csv_content = csv_import_normalize_line_endings( $csv_content );
    
    // CSV in Array umwandeln - verschiedene Trennzeichen probieren
    $delimiters = [',', ';', '\t', '|'];
    $best_result = null;
    $max_columns = 0;
    
    foreach ( $delimiters as $delimiter ) {
        $test_lines = str_getcsv( $csv_content, "\n" );
        if ( ! empty( $test_lines ) ) {
            $actual_delimiter = $delimiter === '\t' ? "\t" : $delimiter;
            $test_headers = str_getcsv( $test_lines[0], $actual_delimiter );
            if ( count( $test_headers ) > $max_columns ) {
                $max_columns = count( $test_headers );
                $best_result = [
                    'delimiter' => $delimiter,
                    'actual_delimiter' => $actual_delimiter,
                    'lines' => $test_lines,
                    'headers' => $test_headers
                ];
            }
        }
    }
    
    if ( ! $best_result || $max_columns < 2 ) {
        throw new Exception( 'Keine gültigen CSV-Daten gefunden. Stellen Sie sicher, dass die Datei korrekt formatiert ist.' );
    }
    
    $lines = $best_result['lines'];
    $headers = $best_result['headers'];
    $delimiter = $best_result['delimiter'];
    $actual_delimiter = $best_result['actual_delimiter'];
    
    // Header bereinigen
    $headers = array_map( 'trim', $headers );
    $headers = array_filter( $headers ); // Leere Header entfernen
    
    if ( empty( $headers ) ) {
        throw new Exception( 'Keine gültigen Spalten-Header gefunden' );
    }
    
    // Beispieldaten sammeln (erste 3 Datenzeilen)
    $sample_data = [];
    for ( $i = 1; $i <= min( 4, count( $lines ) - 1 ); $i++ ) {
        if ( ! empty( trim( $lines[ $i ] ) ) ) {
            $row_data = str_getcsv( $lines[ $i ], $actual_delimiter );
            $row_data = array_map( 'trim', $row_data );
            $sample_data[] = array_slice( $row_data, 0, min( 5, count( $headers ) ) ); // Nur erste 5 Spalten
        }
    }
    
    $total_rows = count( $lines ) - 1; // Minus Header-Zeile
    $non_empty_rows = 0;
    for ( $i = 1; $i < count( $lines ); $i++ ) {
        if ( ! empty( trim( $lines[ $i ] ) ) ) {
            $non_empty_rows++;
        }
    }
    
    $message = "✅ {$source_name} CSV erfolgreich validiert!<br>" .
               "<strong>Gesamtzeilen:</strong> {$total_rows}<br>" .
               "<strong>Datenzeilen:</strong> {$non_empty_rows}<br>" .
               "<strong>Spalten:</strong> " . count( $headers ) . "<br>" .
               "<strong>Delimiter:</strong> " . ($delimiter === '\t' ? 'Tab' : $delimiter) . "<br>" .
               "<strong>Header:</strong> " . implode( ', ', array_slice( $headers, 0, 5 ) ) . 
               ( count( $headers ) > 5 ? ' ... (und ' . (count( $headers ) - 5) . ' weitere)' : '' );
    
    $result = [
        'valid' => true,
        'message' => $message,
        'rows' => $non_empty_rows,
        'total_rows' => $total_rows,
        'columns' => $headers,
        'sample_data' => $sample_data,
        'delimiter' => $delimiter
    ];
    
    return $result;
}

// ===================================================================
// CSV VERARBEITUNGSFUNKTIONEN (OPTIMIERT FÜR GERINGEN SPEICHERVERBRAUCH)
// ===================================================================

/**
 * Lädt CSV-Daten von einer Quelle und verarbeitet sie als Stream.
 * @param string $source 'dropbox' oder 'local'
 * @param array $config Plugin-Konfiguration
 * @return array CSV-Daten als Array
 */
function csv_import_load_csv_data( string $source, array $config ): array {
    $file_handle = null;
    $temp_file_path = null;

    try {
        if ( $source === 'dropbox' ) {
            // Dropbox-Datei temporär auf dem Server speichern, um sie als Stream zu lesen
            $temp_file_path = csv_import_download_dropbox_to_temp( $config );
            $file_handle = fopen( $temp_file_path, 'r' );
        } elseif ( $source === 'local' ) {
            $file_path = ABSPATH . ltrim( $config['local_path'], '/' );
            if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
                throw new Exception( 'Lokale CSV-Datei nicht gefunden oder nicht lesbar: ' . $config['local_path'] );
            }
            $file_handle = fopen( $file_path, 'r' );
        } else {
            throw new Exception( 'Unbekannte CSV-Quelle: ' . $source );
        }

        if ( $file_handle === false ) {
            throw new Exception( 'Datei konnte nicht zum Lesen geöffnet werden.' );
        }

        // --- NEU: Die neue Streaming-Funktion aufrufen ---
        return csv_import_process_csv_stream( $file_handle, $config );

    } finally {
        // Sicherstellen, dass der File Handle geschlossen und die temporäre Datei gelöscht wird
        if ( is_resource( $file_handle ) ) {
            fclose( $file_handle );
        }
        if ( $temp_file_path && file_exists( $temp_file_path ) ) {
            @unlink( $temp_file_path );
        }
    }
}

/**
 * NEU: Lädt eine Dropbox-Datei in eine temporäre Datei und gibt den Pfad zurück.
 */
function csv_import_download_dropbox_to_temp( array $config ): string {
    if ( empty( $config['dropbox_url'] ) ) {
        throw new Exception( 'Dropbox URL nicht konfiguriert' );
    }

    $download_url = str_replace( '?dl=0', '?dl=1', $config['dropbox_url'] );

    $temp_file = wp_tempnam( 'csv_import' );
    if ( ! $temp_file ) {
        throw new Exception( 'Konnte keine temporäre Datei erstellen.' );
    }

    $response = wp_remote_get( $download_url, [
        'timeout'  => 60,
        'stream'   => true, // Wichtig: Aktiviert Streaming-Download
        'filename' => $temp_file
    ] );

    if ( is_wp_error( $response ) ) {
        @unlink( $temp_file );
        throw new Exception( 'Dropbox-Download fehlgeschlagen: ' . $response->get_error_message() );
    }
    
    $http_code = wp_remote_retrieve_response_code( $response );
    if ( $http_code !== 200 ) {
        @unlink( $temp_file );
        throw new Exception( "Dropbox-Datei nicht verfügbar (HTTP {$http_code})" );
    }

    return $temp_file;
}


/**
 * NEU & ERSETZT parse_csv_content: Verarbeitet einen CSV-Dateistream Zeile für Zeile.
 *
 * @param resource $file_handle Der geöffnete File Handle der CSV-Datei.
 * @param array $config Die Plugin-Konfiguration.
 * @return array Die geparsten CSV-Daten.
 */
function csv_import_process_csv_stream( $file_handle, array $config ): array {
    // BOM (Byte Order Mark) überspringen, falls vorhanden (wichtig für UTF-8 Kompatibilität)
    if (fgets($file_handle, 4) !== "\xef\xbb\xbf") {
        rewind($file_handle);
    }
    
    // Header auslesen und Trennzeichen erkennen
    $header_line = fgets( $file_handle );
    if ($header_line === false) {
        throw new Exception('Konnte die Header-Zeile nicht lesen. Die Datei ist möglicherweise leer.');
    }
    
    $delimiter = csv_import_detect_csv_delimiter($header_line);
    $headers = str_getcsv( trim($header_line), $delimiter );
    $headers = array_map( 'trim', $headers );

    if ( empty( array_filter( $headers ) ) ) {
        throw new Exception( 'Keine gültigen Spalten-Header gefunden.' );
    }
    
    $data = [];
    // Schleife, die die Datei Zeile für Zeile liest
    while ( ( $row = fgetcsv( $file_handle, 0, $delimiter ) ) !== false ) {
        // Ignoriere komplett leere Zeilen
        if (count($row) === 1 && is_null($row[0])) {
            continue;
        }

        $row_data = [];
        foreach ( $headers as $index => $header ) {
            $row_data[ $header ] = isset( $row[ $index ] ) ? trim( $row[ $index ] ) : '';
        }
        $data[] = $row_data;
    }
    
    return [
        'headers' => $headers,
        'data' => $data,
        'total_rows' => count( $data ),
        'delimiter' => $delimiter
    ];
}


/**
 * Erkennt das CSV-Trennzeichen automatisch aus der ersten Zeile.
 */
function csv_import_detect_csv_delimiter( string $header_line ): string {
    $delimiters = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];
    foreach ($delimiters as $delimiter => &$count) {
        $count = count(str_getcsv($header_line, $delimiter));
    }
    // Das Trennzeichen, das die meisten Spalten erzeugt, ist wahrscheinlich das richtige.
    return array_search(max($delimiters), $delimiters);
}

// ===================================================================
// IMPORT HAUPT-FUNKTIONEN MIT SICHERHEITSCHECKS
// ===================================================================

/**
 * Startet den CSV-Import mit erweiterten Sicherheitschecks (Hauptfunktion)
 * @param string $source 'dropbox' oder 'local'
 * @param array $config Import-Konfiguration
 * @return array Import-Ergebnis
 */
function csv_import_start_import( string $source, array $config = null ): array {
    try {
        if ( csv_import_is_import_running() ) {
            return ['success' => false, 'message' => 'Ein Import läuft bereits.'];
        }
        if ( $config === null ) {
            $config = csv_import_get_config();
        }
        
        csv_import_set_import_lock();
        $session_id = 'import_' . time() . '_' . uniqid();
        csv_import_log( 'info', "Import gestartet - Quelle: {$source}, Session: {$session_id}" );
        
        $csv_data = csv_import_load_csv_data( $source, $config );
        if ( empty( $csv_data['data'] ) ) {
            throw new Exception( 'Keine Daten in CSV-Datei gefunden' );
        }
        
        csv_import_update_progress( 0, count( $csv_data['data'] ), 'starting' );
        $result = csv_import_process_data( $csv_data, $config, $session_id );
        csv_import_update_import_stats( $result, $source );
        csv_import_update_progress( $result['processed'], $result['total'], $result['errors'] > 0 ? 'completed_with_errors' : 'completed' );
        csv_import_remove_import_lock();
        
        return $result;
        
    } catch ( Exception $e ) {
        csv_import_remove_import_lock();
        csv_import_update_progress( 0, 0, 'failed' );
        csv_import_log( 'error', 'Import fehlgeschlagen: ' . $e->getMessage() );
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function csv_import_process_data( array $csv_data, array $config, string $session_id ): array {
    $processed = 0; $errors = 0; $created_posts = [];
    $data = $csv_data['data'];
    $total = count( $data );

    foreach ( $data as $index => $row ) {
        try {
            csv_import_update_progress( $processed, $total, 'processing' );
            $post_result = csv_import_create_post_from_row( $row, $config, $session_id );
            if ( $post_result === 'created' ) {
                $created_posts[] = $post_result;
            }
            $processed++;
        } catch ( Exception $e ) {
            $errors++;
            csv_import_log( 'warning', "Zeile " . ($index + 2) . ": " . $e->getMessage() );
        }
    }
    return ['success' => $processed > 0, 'processed' => $processed, 'total' => $total, 'errors' => $errors];
}

function csv_import_create_post_from_row( array $row, array $config, string $session_id ) {
    $post_title = csv_import_sanitize_title( $row['post_title'] ?? 'Untitled' );
    if ( empty( $post_title ) ) throw new Exception( 'Post-Titel ist erforderlich' );
    
    if ( !empty($config['skip_duplicates']) && get_page_by_title( $post_title, OBJECT, $config['post_type'] ) ) {
        return 'skipped';
    }

    $post_data = [
        'post_title'   => $post_title,
        'post_content' => $row['post_content'] ?? '',
        'post_status'  => $config['post_status'],
        'post_type'    => $config['post_type'],
        'meta_input'   => ['_csv_import_session' => $session_id]
    ];
    
    $post_id = wp_insert_post( $post_data );
    if ( is_wp_error( $post_id ) ) {
        throw new Exception( 'WordPress Fehler: ' . $post_id->get_error_message() );
    }

    // Meta, Bilder, SEO etc.
    csv_import_add_meta_fields( $post_id, $row, $config );
    if ( $config['image_source'] !== 'none' ) {
        csv_import_process_images( $post_id, $row, $config );
    }

    return 'created';
}

function csv_import_add_meta_fields( int $post_id, array $row, array $config ): void {
    $skip_fields = ['post_title', 'post_content'];
    foreach ( $row as $key => $value ) {
        if ( ! in_array( $key, $skip_fields ) && ! empty( $value ) ) {
            update_post_meta( $post_id, sanitize_key($key), sanitize_text_field( $value ) );
        }
    }
}

function csv_import_process_images( int $post_id, array $row, array $config ): void {
    $image_url = $row['featured_image'] ?? $row['image'] ?? '';
    if (empty($image_url)) return;
    try {
        $attachment_id = csv_import_download_and_attach_image( $image_url, $post_id );
        if ( $attachment_id ) {
            set_post_thumbnail( $post_id, $attachment_id );
        }
    } catch (Exception $e) {
        csv_import_log('warning', "Bildfehler für Post {$post_id}: " . $e->getMessage());
    }
}

function csv_import_download_and_attach_image( string $image_url, int $post_id ): int {
    // ... (Diese Funktion wurde in einer vorherigen Antwort bereits optimiert, 
    // der Code ist hier zur Vollständigkeit enthalten)
    if ( ! filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
        throw new Exception( 'Ungültige Bild-URL: ' . $image_url );
    }
    
    $allowed_domains = apply_filters('csv_import_allowed_image_domains', []);
    $host = wp_parse_url( $image_url, PHP_URL_HOST );
    if ( !empty($allowed_domains) && ! in_array( $host, $allowed_domains, true ) ) {
        throw new Exception( 'Nicht erlaubte Domain für Bild-Download: ' . $host );
    }

    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    
    $attachment_id = media_sideload_image( $image_url, $post_id, null, 'id' );

    if ( is_wp_error( $attachment_id ) ) {
        throw new Exception( $attachment_id->get_error_message() );
    }
    return $attachment_id;
}

// ===================================================================
// SYSTEM & HEALTH FUNKTIONEN MIT ERWEITERTEN CHECKS
// ===================================================================

/**
 * Überprüft den Systemzustand auf potenzielle Probleme.
 *
 * @return array
 */
/**
 * Überprüft den Systemzustand auf potenzielle Probleme (FINALE VERSION MIT DIREKTER DB-ABFRAGE).
 *
 * @return array
 */
function csv_import_system_health_check(): array {
    $health = [
        'memory_ok'      => true,
        'time_ok'        => true,
        'disk_space_ok'  => true,
        'permissions_ok' => true,
        'php_version_ok' => true,
        'curl_ok'        => true,
        'wp_version_ok'  => true,
        'import_locks_ok' => true,    // GEÄNDERT: Klarere Benennung
        'no_stuck_processes' => true, // GEÄNDERT: Klarere Benennung
    ];

    // Memory Check
    $memory_limit = ini_get( 'memory_limit' );
    if ( $memory_limit && $memory_limit !== '-1' ) {
        $memory_bytes = csv_import_convert_to_bytes( $memory_limit );
        $health['memory_ok'] = $memory_bytes >= 128 * 1024 * 1024;
    }

    // Time Limit Check
    $time_limit = ini_get( 'max_execution_time' );
    $health['time_ok'] = ( $time_limit == 0 || $time_limit >= 60 );

    // PHP Version Check
    $health['php_version_ok'] = version_compare( PHP_VERSION, '7.4', '>=' );

    // WordPress Version Check
    global $wp_version;
    $health['wp_version_ok'] = version_compare( $wp_version, '5.0', '>=' );

    // cURL Check
    $health['curl_ok'] = function_exists( 'curl_init' );

    // Disk Space Check
    $free_space = @disk_free_space( ABSPATH );
    if ( $free_space ) {
        $health['disk_space_ok'] = $free_space >= 100 * 1024 * 1024;
    }

    // Permissions Check
    $upload_dir = wp_upload_dir();
    $health['permissions_ok'] = is_writable( $upload_dir['basedir'] );

    // ==========================================================
    // KORRIGIERT: Einheitliche Logik true = OK, false = Problem
    // ==========================================================

    global $wpdb;

    // Import Lock Check: true = keine Locks (OK), false = Locks vorhanden (Problem)
    $lock_value = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'csv_import_running_lock' LIMIT 1" );
    $lock_data = maybe_unserialize($lock_value);
    $health['import_locks_ok'] = empty($lock_data); // KORRIGIERT: empty() = true = OK

    // Stuck Process Check: true = keine hängenden Prozesse (OK), false = hängend (Problem)
    $progress_value = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'csv_import_progress' LIMIT 1" );
    if ( ! empty($progress_value) ) {
        $progress = maybe_unserialize($progress_value);
        if ( is_array($progress) && !empty($progress['running']) && !empty($progress['start_time']) ) {
            $runtime = time() - $progress['start_time'];
            $health['no_stuck_processes'] = $runtime <= 600; // KORRIGIERT: <= 600 = true = OK
        } else {
            $health['no_stuck_processes'] = true; // Kein aktiver Prozess = OK
        }
    } else {
        $health['no_stuck_processes'] = true; // Keine Progress-Daten = OK
    }

    return $health;
}
/**
 * Konvertiert Grössenangaben wie '256M' in Bytes.
 *
 * @param string $size_str
 * @return int
 */
function csv_import_convert_to_bytes( string $size_str ): int {
    $size_str = trim( $size_str );
    if ( empty( $size_str ) || $size_str === '-1' ) {
        return PHP_INT_MAX; // Unbegrenzter Speicher
    }
    
    $last = strtolower( $size_str[ strlen( $size_str ) - 1 ] );
    $size = (int) $size_str;

    switch ( $last ) {
        case 'g':
            $size *= 1024;
        case 'm':
            $size *= 1024;
        case 'k':
            $size *= 1024;
    }

    return $size;
}

// ===================================================================
// FORTSCHRITT & STATISTIKEN MIT ERWEITERTEN FEATURES
// ===================================================================

/**
 * Holt den aktuellen Import-Fortschritt mit Validierung.
 *
 * @return array
 */
function csv_import_get_progress(): array {
    $progress = get_option( 'csv_import_progress', [] );
    $default_progress = [
        'running'    => false,
        'processed'  => 0,
        'total'      => 0,
        'percent'    => 0,
        'status'     => 'idle',
        'message'    => 'Kein aktiver Import',
        'timestamp'  => 0,
        'start_time' => 0,
        'errors'     => 0
    ];
    
    $progress = wp_parse_args( $progress, $default_progress );
    
    // Validierung: Wenn Import als laufend markiert aber älter als 15 Minuten
    if ( $progress['running'] && $progress['start_time'] > 0 ) {
        $runtime = time() - $progress['start_time'];
        if ( $runtime > 900 ) { // 15 Minuten
            $progress['running'] = false;
            $progress['status'] = 'timeout';
            $progress['message'] = 'Import-Timeout nach ' . human_time_diff($progress['start_time']) . ' - automatisch zurückgesetzt';
            update_option( 'csv_import_progress', $progress );
        }
    }
    
    return $progress;
}

/**
 * Holt allgemeine Import-Statistiken.
 *
 * @return array
 */
function csv_import_get_stats(): array {
    return [
        'total_imported' => get_option( 'csv_import_total_imported', 0 ),
        'last_run'       => get_option( 'csv_import_last_run', 'Nie' ),
        'last_count'     => get_option( 'csv_import_last_count', 0 ),
        'last_source'    => get_option( 'csv_import_last_source', 'Keine' ),
        'success_rate'   => get_option( 'csv_import_last_success_rate', 0 ),
        'avg_processing_time' => get_option( 'csv_import_avg_processing_time', 0 )
    ];
}

/**
 * Holt Fehlerstatistiken mit erweiterten Metriken
 */
function csv_import_get_error_stats(): array {
    return get_option( 'csv_import_error_stats', [
        'total_errors'      => 0,
        'total_real_errors' => 0,
        'errors_by_level'   => [],
        'recent_errors'     => [],
        'error_trends'      => [],
        'critical_errors_24h' => 0,
        'warning_errors_24h' => 0
    ] );
}

/**
 * Aktualisiert den Fortschritt eines laufenden Imports mit verbesserter Logik.
 */
function csv_import_update_progress( int $processed, int $total, string $status = 'processing' ): void {
    $current_progress = get_option( 'csv_import_progress', [] );
    
    $progress = [
        'running'    => ( $status !== 'completed' && $status !== 'failed' && $status !== 'timeout' ),
        'processed'  => $processed,
        'total'      => $total,
        'percent'    => $total > 0 ? round( ( $processed / $total ) * 100, 1 ) : 0,
        'status'     => $status,
        'message'    => csv_import_get_status_message( $status, $processed, $total ),
        'timestamp'  => current_time( 'timestamp' ),
        'start_time' => $current_progress['start_time'] ?? current_time( 'timestamp' ),
        'errors'     => $current_progress['errors'] ?? 0
    ];
    
    // ETA berechnen wenn genug Daten vorhanden
    if ( $processed > 5 && $total > $processed ) {
        $elapsed = time() - $progress['start_time'];
        $rate = $processed / $elapsed; // Posts pro Sekunde
        $remaining = $total - $processed;
        $eta = $remaining / $rate;
        $progress['eta_seconds'] = (int) $eta;
        $progress['eta_human'] = human_time_diff( time(), time() + $eta );
    }
    
    update_option( 'csv_import_progress', $progress );
    
    // Start-Zeit bei erstem Aufruf setzen
    if ( $status === 'starting' ) {
        update_option( 'csv_import_start_time', current_time( 'timestamp' ) );
    }
}

/**
 * Gibt eine lesbare Status-Nachricht zurück
 */
function csv_import_get_status_message( string $status, int $processed, int $total ): string {
    switch ( $status ) {
        case 'starting':
            return 'Import wird vorbereitet...';
        case 'processing':
            $percent = $total > 0 ? round(($processed / $total) * 100, 1) : 0;
            return "Verarbeite Eintrag {$processed} von {$total} ({$percent}%)...";
        case 'completed':
            return "Import erfolgreich abgeschlossen. {$processed} Einträge verarbeitet.";
        case 'completed_with_errors':
            return "Import abgeschlossen mit Fehlern. {$processed} von {$total} Einträgen verarbeitet.";
        case 'failed':
            return 'Import fehlgeschlagen.';
        case 'timeout':
            return 'Import-Timeout - Prozess wurde automatisch zurückgesetzt.';
        case 'idle':
        default:
            return 'Kein aktiver Import';
    }
}

/**
 * Löscht den Import-Fortschritt aus der Datenbank.
 */
function csv_import_clear_progress(): void {
    delete_option( 'csv_import_progress' );
    delete_option( 'csv_import_start_time' );
    delete_option( 'csv_import_running_lock' );
}

/**
 * Aktualisiert Import-Statistiken mit erweiterten Metriken
 */
function csv_import_update_import_stats( array $result, string $source ): void {
    $total_imported = get_option( 'csv_import_total_imported', 0 );
    $total_imported += $result['processed'];
    
    update_option( 'csv_import_total_imported', $total_imported );
    update_option( 'csv_import_last_run', current_time( 'mysql' ) );
    update_option( 'csv_import_last_count', $result['processed'] );
    update_option( 'csv_import_last_source', ucfirst( $source ) );
    
    // Erfolgsquote berechnen
    if ( $result['total'] > 0 ) {
        $success_rate = round( ( $result['processed'] / $result['total'] ) * 100, 1 );
        update_option( 'csv_import_last_success_rate', $success_rate );
    }
    
    // Durchschnittliche Verarbeitungszeit
    $start_time = get_option( 'csv_import_start_time', time() );
    $processing_time = time() - $start_time;
    if ( $result['processed'] > 0 ) {
        $time_per_item = $processing_time / $result['processed'];
        update_option( 'csv_import_avg_processing_time', round( $time_per_item, 2 ) );
    }
}

// ===================================================================
// ADMIN UI FUNKTIONEN
// ===================================================================

/**
 * Holt Informationen zum Template-Post für die Anzeige im Admin-Bereich.
 *
 * @return string
 */
function csv_import_get_template_info(): string {
    $id = get_option( 'csv_import_template_id' );
    if ( ! $id || $id == 0 ) {
        return '<span style="color:red;">❌ Nicht gesetzt</span>';
    }
    
    $post = get_post( $id );
    if ( ! $post ) {
        return '<span style="color:red;">❌ Template mit ID ' . $id . ' nicht gefunden</span>';
    }
    
    $edit_link = get_edit_post_link( $id );
    $view_link = get_permalink( $id );
    
    return sprintf(
        '✅ <strong>"%s"</strong> (ID: %d)<br>' .
        '<small>Status: %s | Typ: %s</small><br>' .
        '<a href="%s" target="_blank" class="button button-small">Bearbeiten</a> ' .
        '<a href="%s" target="_blank" class="button button-small">Ansehen</a>',
        esc_html( $post->post_title ),
        $id,
        esc_html( $post->post_status ),
        esc_html( $post->post_type ),
        esc_url( $edit_link ),
        esc_url( $view_link )
    );
}

/**
 * Prüft den Status einer Datei oder eines Verzeichnisses für die Anzeige im Admin-Bereich.
 *
 * @param string $path
 * @param bool $is_dir
 * @return string
 */
function csv_import_get_file_status( string $path, bool $is_dir = false ): string {
    $full_path = $path;
    
    // Wenn der Pfad nicht absolut ist, ABSPATH voranstellen
    if ( ! csv_import_path_is_absolute( $path ) ) {
        $full_path = ABSPATH . ltrim( $path, '/' );
    }
    
    if ( $is_dir ) {
        if ( is_dir( $full_path ) && is_readable( $full_path ) ) {
            $file_count = count( glob( $full_path . '/*' ) );
            return '<span style="color:green;">✅ Ordner existiert (' . $file_count . ' Dateien)</span>';
        }
        return '<span style="color:red;">❌ Ordner nicht gefunden oder nicht lesbar</span>';
    } else {
        if ( file_exists( $full_path ) && is_readable( $full_path ) ) {
            $size = filesize( $full_path );
            $modified = date( 'Y-m-d H:i:s', filemtime( $full_path ) );
            return sprintf(
                '<span style="color:green;">✅ Datei gefunden (%s, geändert: %s)</span>',
                size_format( $size ),
                $modified
            );
        }
        return '<span style="color:red;">❌ Datei nicht gefunden: ' . esc_html( basename( $path ) ) . '</span>';
    }
}

// ===================================================================
// HILFSFUNKTIONEN
// ===================================================================

/**
 * Normalisiert Zeilenumbrüche in einem String.
 */
function csv_import_normalize_line_endings( string $content ): string {
    return str_replace( [ "\r\n", "\r" ], "\n", $content );
}

/**
 * Hilfsfunktion um zu prüfen ob ein Pfad absolut ist
 */
function csv_import_path_is_absolute( string $path ): bool {
    return isset( $path[0] ) && $path[0] === '/' || // Unix
           isset( $path[1] ) && $path[1] === ':';    // Windows
}

/**
 * Validiert erforderliche CSV-Spalten
 */
function csv_import_validate_required_columns( array $csv_headers, array $required_columns ): array {
    $missing = [];
    
    foreach ( $required_columns as $required ) {
        if ( ! in_array( $required, $csv_headers ) ) {
            $missing[] = $required;
        }
    }
    
    return [
        'valid' => empty( $missing ),
        'missing' => $missing,
        'message' => empty( $missing ) 
            ? 'Alle erforderlichen Spalten vorhanden' 
            : 'Fehlende Spalten: ' . implode( ', ', $missing )
    ];
}

/**
 * Sanitized einen Post-Titel
 */
function csv_import_sanitize_title( string $title ): string {
    $title = trim( $title );
    $title = wp_strip_all_tags( $title );
    $title = html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );
    return $title;
}

/**
 * Generiert einen eindeutigen Post-Slug
 */
function csv_import_generate_unique_slug( string $title, string $post_type = 'post' ): string {
    $slug = sanitize_title( $title );
    
    if ( empty( $slug ) ) {
        $slug = 'csv-import-post-' . uniqid();
    }
    
    $original_slug = $slug;
    $counter = 1;
    
    // Prüfe ob Slug bereits existiert
    while ( get_page_by_path( $slug, OBJECT, $post_type ) ) {
        $slug = $original_slug . '-' . $counter;
        $counter++;
    }
    
    return $slug;
}

/**
 * Human-readable time diff für bessere UX
 */
function csv_import_human_time_diff( $from, $to = null ) {
    if ( $to === null ) {
        $to = time();
    }
    
    $diff = abs( $to - $from );
    
    if ( $diff < 60 ) {
        return $diff . ' Sekunden';
    } elseif ( $diff < 3600 ) {
        return round( $diff / 60 ) . ' Minuten';
    } elseif ( $diff < 86400 ) {
        return round( $diff / 3600 ) . ' Stunden';
    } else {
        return round( $diff / 86400 ) . ' Tage';
    }
}

// ===================================================================
// BACKUP & CLEANUP FUNKTIONEN MIT ERWEITERTEN FEATURES
// ===================================================================

/**
 * Erstellt ein Backup vor dem Import mit erweiterten Informationen
 */
function csv_import_create_backup( string $session_id ): bool {
    // Wird von der Backup Manager Klasse implementiert
    if ( class_exists( 'CSV_Import_Backup_Manager' ) && method_exists( 'CSV_Import_Backup_Manager', 'create_backup' ) ) {
        return CSV_Import_Backup_Manager::create_backup( $session_id );
    }
    
    // Fallback: Erweiterte Backup-Info in Optionen speichern
    global $wpdb;
    
    $backup_info = [
        'session_id' => $session_id,
        'timestamp' => current_time( 'mysql' ),
        'pre_import_post_count' => wp_count_posts()->publish,
        'user_id' => get_current_user_id(),
        'memory_usage' => memory_get_usage( true ),
        'mysql_version' => $wpdb->db_version(),
        'wp_version' => get_bloginfo( 'version' ),
        'php_version' => PHP_VERSION,
        'server_info' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown'
    ];
    
    update_option( 'csv_import_backup_' . $session_id, $backup_info );
    csv_import_log( 'info', "Backup für Session {$session_id} erstellt" );
    
    return true;
}

/**
 * Bereinigt temporäre Dateien und alte Daten mit erweiterten Optionen
 */
function csv_import_cleanup_temp_files( int $older_than_hours = 24 ): void {
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/csv-import-temp/';
    $cutoff_time = time() - ( $older_than_hours * 3600 );
    $deleted_files = 0;
    
    if ( is_dir( $temp_dir ) ) {
        $files = glob( $temp_dir . '*' );
        foreach ( $files as $file ) {
            if ( is_file( $file ) && filemtime( $file ) < $cutoff_time ) {
                if ( @unlink( $file ) ) {
                    $deleted_files++;
                }
            }
        }
        
        // Leere Verzeichnisse entfernen
        if ( is_dir_empty( $temp_dir ) ) {
            @rmdir( $temp_dir );
        }
    }
    
    if ( $deleted_files > 0 ) {
        csv_import_log( 'info', "Bereinigung: {$deleted_files} temporäre Dateien gelöscht" );
    }
}

/**
 * Prüft ob ein Verzeichnis leer ist
 */
function is_dir_empty( string $dir ): bool {
    if ( ! is_readable( $dir ) ) {
        return false;
    }
    
    $handle = opendir( $dir );
    while ( false !== ( $entry = readdir( $handle ) ) ) {
        if ( $entry != '.' && $entry != '..' ) {
            closedir( $handle );
            return false;
        }
    }
    closedir( $handle );
    return true;
}

// ===================================================================
// LOGGING & FEHLERBEHANDLUNG MIT ERWEITERTEN FEATURES
// ===================================================================

/**
 * Verfolgt Fehlerstatistiken für das Monitoring mit erweiterten Metriken.
 *
 * @param string $level
 * @param string $message
 */
function csv_import_track_error_stats( string $level, string $message ): void {
    $stats = get_option( 'csv_import_error_stats', [
        'total_errors'      => 0,
        'total_real_errors' => 0,
        'errors_by_level'   => [],
        'recent_errors'     => [],
        'error_trends'      => [],
        'critical_errors_24h' => 0,
        'warning_errors_24h' => 0
    ] );

    // Gesamtzahl aller Meldungen
    $stats['total_errors']++;

    // Echte Fehler (critical, error, warning) separat zählen
    if ( in_array( $level, ['critical', 'error', 'warning'] ) ) {
        $stats['total_real_errors'] = ( $stats['total_real_errors'] ?? 0 ) + 1;
        
        // 24h Zähler für kritische Fehler und Warnungen
        $cutoff_24h = time() - 86400;
        if ( $level === 'critical' || $level === 'error' ) {
            $stats['critical_errors_24h'] = ( $stats['critical_errors_24h'] ?? 0 ) + 1;
        } elseif ( $level === 'warning' ) {
            $stats['warning_errors_24h'] = ( $stats['warning_errors_24h'] ?? 0 ) + 1;
        }
    }

    // Fehler pro Level
    $stats['errors_by_level'][ $level ] = ( $stats['errors_by_level'][ $level ] ?? 0 ) + 1;

    // Letzte Fehler (die letzten 50 behalten)
    $stats['recent_errors'][] = [
        'level'   => $level,
        'message' => mb_substr( $message, 0, 200 ), // Nachrichtenlänge begrenzen
        'time'    => current_time( 'mysql' ),
        'user_id' => get_current_user_id(),
        'ip'      => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    $stats['recent_errors'] = array_slice( $stats['recent_errors'], -50 );

    // Tägliche Trends für echte Fehler
    if ( in_array( $level, ['critical', 'error', 'warning'] ) ) {
        $today = current_time( 'Y-m-d' );
        $stats['error_trends'][ $today ] = ( $stats['error_trends'][ $today ] ?? 0 ) + 1;

        // Alte Trends aufräumen (letzte 30 Tage behalten)
        if ( count( $stats['error_trends'] ) > 30 ) {
            $stats['error_trends'] = array_slice( $stats['error_trends'], -30, null, true );
        }
    }

    update_option( 'csv_import_error_stats', $stats );
}

/**
 * Loggt Import-Aktivitäten mit erweiterten Kontextdaten
 */
function csv_import_log( string $level, string $message, array $context = [] ): void {
    // Erweiterte Kontextdaten hinzufügen
    $context = array_merge( $context, [
        'timestamp' => current_time( 'mysql' ),
        'user_id' => get_current_user_id(),
        'memory_usage' => memory_get_usage( true ),
        'peak_memory' => memory_get_peak_usage( true ),
        'php_version' => PHP_VERSION,
        'plugin_version' => defined('CSV_IMPORT_PRO_VERSION') ? CSV_IMPORT_PRO_VERSION : 'unknown'
    ] );
    
    if ( class_exists( 'CSV_Import_Error_Handler' ) ) {
        CSV_Import_Error_Handler::handle( $level, $message, $context );
    } else {
        error_log( sprintf( '[CSV Import Pro %s] %s', strtoupper( $level ), $message ) );
    }
    
    // In Statistiken verfolgen
    csv_import_track_error_stats( $level, $message );
    
    // Zusätzlich in eigenes Log schreiben (falls Debug-Modus)
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        csv_import_debug_log( $message, $context );
    }
    
    // Bei kritischen Fehlern sofort Admin benachrichtigen
    if ( $level === 'critical' ) {
        csv_import_send_critical_error_notification( $message, $context );
    }
}

/**
 * Debug-Logging in separate Datei mit Rotation
 */
function csv_import_debug_log( string $message, array $context = [] ): void {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/csv-import-debug.log';
    
    // Log-Rotation: Wenn Datei größer als 10MB, in .old umbenennen
    if ( file_exists( $log_file ) && filesize( $log_file ) > 10485760 ) {
        @rename( $log_file, $upload_dir['basedir'] . '/csv-import-debug.log.old' );
    }
    
    $log_entry = sprintf(
        "[%s] [%s] %s %s\n",
        current_time( 'Y-m-d H:i:s' ),
        strtoupper( WP_DEBUG ? 'DEBUG' : 'INFO' ),
        $message,
        ! empty( $context ) ? wp_json_encode( $context, JSON_PRETTY_PRINT ) : ''
    );
    
    @file_put_contents( $log_file, $log_entry, FILE_APPEND | LOCK_EX );
}

/**
 * Sendet kritische Fehler-Benachrichtigungen
 */
function csv_import_send_critical_error_notification( string $message, array $context = [] ): void {
    // Throttling: Nicht mehr als 1 kritischer Fehler pro Stunde
    $last_critical = get_transient( 'csv_import_last_critical_notification' );
    if ( $last_critical ) {
        return;
    }
    
    set_transient( 'csv_import_last_critical_notification', time(), 3600 );
    
    $admin_email = get_option( 'admin_email' );
    $site_name = get_bloginfo( 'name' );
    
    $subject = "[$site_name] CSV Import Pro - Kritischer Fehler";
    $body = "Ein kritischer Fehler ist im CSV Import Pro Plugin aufgetreten:\n\n";
    $body .= "Fehler: $message\n\n";
    $body .= "Zeit: " . current_time( 'Y-m-d H:i:s' ) . "\n";
    $body .= "Benutzer: " . ( $context['user_id'] ?? 'unbekannt' ) . "\n";
    $body .= "Speicherverbrauch: " . size_format( $context['memory_usage'] ?? 0 ) . "\n\n";
    $body .= "Weitere Details finden Sie in den Plugin-Logs.\n\n";
    $body .= "-- CSV Import Pro System";
    
    wp_mail( $admin_email, $subject, $body );
}

// ===================================================================
// INITIALIZATION & MAINTENANCE MIT ERWEITERTEN FEATURES
// ===================================================================

/**
 * Führt tägliche Wartungsaufgaben aus mit erweiterten Features
 */
function csv_import_daily_maintenance(): void {
    csv_import_log( 'debug', 'Starte tägliche Wartung' );
    
    // 1. Alte Fehlerstatistiken bereinigen
    $stats = csv_import_get_error_stats();
    
    // Alte Einträge entfernen (älter als 30 Tage)
    $cutoff_date = date( 'Y-m-d', strtotime( '-30 days' ) );
    
    if ( isset( $stats['error_trends'] ) ) {
        $cleaned = 0;
        foreach ( $stats['error_trends'] as $date => $count ) {
            if ( $date < $cutoff_date ) {
                unset( $stats['error_trends'][ $date ] );
                $cleaned++;
            }
        }
        csv_import_log( 'debug', "Bereinigt: {$cleaned} alte Fehler-Trend-Einträge" );
    }
    
    // Nur die letzten 50 Fehler behalten
    if ( isset( $stats['recent_errors'] ) && count( $stats['recent_errors'] ) > 50 ) {
        $stats['recent_errors'] = array_slice( $stats['recent_errors'], -50 );
    }
    
    // 24h-Zähler zurücksetzen
    $stats['critical_errors_24h'] = 0;
    $stats['warning_errors_24h'] = 0;
    
    update_option( 'csv_import_error_stats', $stats );
    
    // 2. Temporäre Dateien aufräumen
    csv_import_cleanup_temp_files( 24 );
    
    // 3. Alte Backup-Informationen bereinigen (älter als 7 Tage)
    global $wpdb;
    $old_backups = $wpdb->get_results(
        "SELECT option_name FROM {$wpdb->options} 
         WHERE option_name LIKE 'csv_import_backup_%' 
         AND option_value LIKE '%\"timestamp\":\"%' 
         LIMIT 100"
    );
    
    $deleted_backups = 0;
    foreach ( $old_backups as $backup ) {
        $backup_data = get_option( $backup->option_name );
        if ( isset( $backup_data['timestamp'] ) ) {
            $backup_time = strtotime( $backup_data['timestamp'] );
            if ( $backup_time < strtotime( '-7 days' ) ) {
                delete_option( $backup->option_name );
                $deleted_backups++;
            }
        }
    }
    
    if ( $deleted_backups > 0 ) {
        csv_import_log( 'debug', "Bereinigt: {$deleted_backups} alte Backup-Einträge" );
    }
    
    // 4. Hängende Import-Prozesse bereinigen
    csv_import_cleanup_dead_processes();
    
    // 5. Backup alte Progress-Optionen löschen
    delete_transient( 'csv_import_progress' );
    
    // 6. Plugin-Performance-Metriken sammeln
    $memory_limit = csv_import_convert_to_bytes( ini_get( 'memory_limit' ) );
    $disk_free = disk_free_space( ABSPATH );
    
    update_option( 'csv_import_system_metrics', [
        'memory_limit' => $memory_limit,
        'disk_free' => $disk_free,
        'last_maintenance' => current_time( 'mysql' ),
        'php_version' => PHP_VERSION,
        'wp_version' => get_bloginfo( 'version' )
    ] );
    
    csv_import_log( 'debug', 'Tägliche Wartung abgeschlossen', [
        'deleted_backups' => $deleted_backups,
        'memory_limit' => size_format( $memory_limit ),
        'disk_free' => size_format( $disk_free )
    ] );
}

/**
 * Wöchentliche Wartungsaufgaben
 */
function csv_import_weekly_maintenance(): void {
    csv_import_log( 'debug', 'Starte wöchentliche Wartung' );
    
    // Erweiterte Bereinigung
    csv_import_cleanup_temp_files( 168 ); // 7 Tage
    
    // Plugin-Health-Check
    $health = csv_import_system_health_check();
    $health_issues = array_filter( $health, function( $value, $key ) {
        return $value === false && $key !== 'import_locks' && $key !== 'stuck_processes';
    }, ARRAY_FILTER_USE_BOTH );
    
    if ( ! empty( $health_issues ) ) {
        csv_import_log( 'warning', 'System-Health-Probleme erkannt', [
            'issues' => array_keys( $health_issues )
        ] );
    }
    
    csv_import_log( 'debug', 'Wöchentliche Wartung abgeschlossen' );
}

// Hook für erweiterte Wartung
if ( ! wp_next_scheduled( 'csv_import_daily_maintenance' ) ) {
    wp_schedule_event( time(), 'daily', 'csv_import_daily_maintenance' );
}

if ( ! wp_next_scheduled( 'csv_import_weekly_maintenance' ) ) {
    wp_schedule_event( time(), 'weekly', 'csv_import_weekly_maintenance' );
}

add_action( 'csv_import_daily_maintenance', 'csv_import_daily_maintenance' );
add_action( 'csv_import_weekly_maintenance', 'csv_import_weekly_maintenance' );

// ===================================================================
// SCHEDULER-AKTIVIERUNGSSYSTEM (NEU - SCHRITT 01)
// ===================================================================

/**
 * Prüft ob der Scheduler grundsätzlich aktiviert ist
 * 
 * @return bool True wenn Scheduler aktiviert, false wenn deaktiviert
 */
function csv_import_is_scheduler_enabled(): bool {
    return get_option('csv_import_scheduler_enabled', false);
}

/**
 * Aktiviert den Scheduler mit umfassenden Sicherheitsprüfungen
 * 
 * @return array Ergebnis mit success/message
 */
function csv_import_enable_scheduler(): array {
    // Nur für Admins
    if (!current_user_can('manage_options')) {
        return [
            'success' => false,
            'message' => 'Keine Berechtigung für Scheduler-Aktivierung'
        ];
    }

    // System-Health-Check vor Aktivierung
    $health_check = csv_import_system_health_check();
    $critical_issues = array_filter($health_check, function($status) {
        return $status === false;
    });

    if (!empty($critical_issues)) {
        $issue_names = array_map(function($key) {
            $names = [
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
            return $names[$key] ?? $key;
        }, array_keys($critical_issues));
        
        return [
            'success' => false,
            'message' => 'System-Probleme gefunden: ' . implode(', ', $issue_names) . '. Bitte beheben Sie diese Probleme vor der Aktivierung.'
        ];
    }

    // WordPress Cron Check - Kritisch für Scheduler
    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
        return [
            'success' => false,
            'message' => 'WordPress Cron ist deaktiviert (DISABLE_WP_CRON = true). Für automatische Imports ist ein funktionierendes Cron-System erforderlich. Bitte aktivieren Sie WordPress Cron oder richten Sie einen externen Cron ein.'
        ];
    }

    // Zusätzliche Dependency-Checks
    $required_functions = [
        'csv_import_get_config',
        'csv_import_validate_config',
        'csv_import_start_import'
    ];
    
    $missing_functions = array_filter($required_functions, function($func) {
        return !function_exists($func);
    });
    
    if (!empty($missing_functions)) {
        return [
            'success' => false,
            'message' => 'Erforderliche Plugin-Funktionen nicht verfügbar: ' . implode(', ', $missing_functions) . '. Plugin möglicherweise beschädigt.'
        ];
    }

    // Scheduler-Klassen-Check
    if (!class_exists('CSV_Import_Scheduler')) {
        return [
            'success' => false,
            'message' => 'Scheduler-Klasse nicht verfügbar. Plugin möglicherweise unvollständig installiert.'
        ];
    }

    // Alle Checks bestanden - Scheduler aktivieren
    update_option('csv_import_scheduler_enabled', true);
    update_option('csv_import_scheduler_activated_at', current_time('mysql'));
    update_option('csv_import_scheduler_activated_by', get_current_user_id());

    // Aktivierung protokollieren
    csv_import_log('info', 'Scheduler wurde aktiviert', [
        'user_id' => get_current_user_id(),
        'user_login' => wp_get_current_user()->user_login,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'timestamp' => current_time('mysql')
    ]);

    // Audit-Log für Sicherheit
    csv_import_log_scheduler_audit('scheduler_enabled', get_current_user_id(), true, [
        'method' => 'manual_activation',
        'health_check_passed' => true
    ]);

    return [
        'success' => true,
        'message' => 'Scheduler erfolgreich aktiviert! Automatische CSV-Imports sind jetzt verfügbar.'
    ];
}

/**
 * Deaktiviert den Scheduler und entfernt alle geplanten Events
 * 
 * @return array Ergebnis mit success/message
 */
function csv_import_disable_scheduler(): array {
    // Nur für Admins
    if (!current_user_can('manage_options')) {
        return [
            'success' => false,
            'message' => 'Keine Berechtigung für Scheduler-Deaktivierung'
        ];
    }

    // Alle geplanten Imports stoppen
    $stopped_imports = 0;
    if (class_exists('CSV_Import_Scheduler')) {
        // Zähle aktuelle geplante Imports vor dem Stoppen
        if (method_exists('CSV_Import_Scheduler', 'is_scheduled') && CSV_Import_Scheduler::is_scheduled()) {
            $stopped_imports = 1;
        }
        
        // Alle Scheduler-Events entfernen
        CSV_Import_Scheduler::unschedule_all();
    }

    // Scheduler-Optionen zurücksetzen
    update_option('csv_import_scheduler_enabled', false);
    delete_option('csv_import_scheduler_activated_at');
    delete_option('csv_import_scheduler_activated_by');
    
    // Zusätzlich alle aktiven Scheduler-Sessions bereinigen
    delete_option('csv_import_scheduled_frequency');
    delete_option('csv_import_scheduled_source');
    delete_option('csv_import_scheduled_options');
    delete_option('csv_import_scheduled_start');
    delete_option('csv_import_scheduled_created');

    // Deaktivierung protokollieren
    csv_import_log('info', 'Scheduler wurde deaktiviert', [
        'user_id' => get_current_user_id(),
        'user_login' => wp_get_current_user()->user_login,
        'stopped_imports' => $stopped_imports,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'timestamp' => current_time('mysql')
    ]);

    // Audit-Log für Sicherheit
    csv_import_log_scheduler_audit('scheduler_disabled', get_current_user_id(), true, [
        'method' => 'manual_deactivation',
        'stopped_imports' => $stopped_imports
    ]);

    $message = 'Scheduler deaktiviert';
    if ($stopped_imports > 0) {
        $message .= " und {$stopped_imports} geplante Imports gestoppt";
    }
    $message .= '. Automatische Imports sind nicht mehr verfügbar.';

    return [
        'success' => true,
        'message' => $message
    ];
}

/**
 * Audit-Log für Scheduler-Aktivitäten (Sicherheitsprotokoll)
 * 
 * @param string $action Die ausgeführte Aktion
 * @param int $user_id ID des ausführenden Benutzers
 * @param bool $success Ob die Aktion erfolgreich war
 * @param array $details Zusätzliche Details
 */
function csv_import_log_scheduler_audit(string $action, int $user_id, bool $success = true, array $details = []): void {
    $user = get_user_by('id', $user_id);
    
    $audit_entry = [
        'timestamp' => current_time('mysql'),
        'action' => $action,
        'user_id' => $user_id,
        'user_login' => $user ? $user->user_login : 'unknown',
        'user_roles' => $user ? $user->roles : [],
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'success' => $success,
        'details' => $details,
        'server_info' => [
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'memory_usage' => memory_get_usage(true),
            'server_time' => time()
        ]
    ];
    
    // Audit-Log in separate Option speichern
    $audit_log = get_option('csv_import_scheduler_audit_log', []);
    $audit_log[] = $audit_entry;
    
    // Nur die letzten 100 Einträge behalten (Performance)
    if (count($audit_log) > 100) {
        $audit_log = array_slice($audit_log, -100);
    }
    
    update_option('csv_import_scheduler_audit_log', $audit_log);
    
    // Bei kritischen Fehlern sofortige Admin-Benachrichtigung
    if (!$success && in_array($action, ['scheduler_enabled', 'scheduler_disabled'])) {
        csv_import_send_security_alert($action, $audit_entry);
    }
}

/**
 * Sendet Sicherheitswarnungen bei kritischen Scheduler-Aktionen
 * 
 * @param string $action Die Aktion
 * @param array $audit_entry Audit-Eintrag
 */
function csv_import_send_security_alert(string $action, array $audit_entry): void {
    // Throttling: Nicht mehr als 1 Warnung pro Stunde
    $last_alert = get_transient('csv_import_last_security_alert');
    if ($last_alert) {
        return;
    }
    
    set_transient('csv_import_last_security_alert', time(), 3600);
    
    $admin_email = get_option('admin_email');
    $site_name = get_bloginfo('name');
    
    $subject = "[{$site_name}] CSV Import Pro - Sicherheitswarnung";
    $body = "Eine kritische Scheduler-Aktion ist fehlgeschlagen:\n\n";
    $body .= "Aktion: {$action}\n";
    $body .= "Benutzer: {$audit_entry['user_login']} (ID: {$audit_entry['user_id']})\n";
    $body .= "IP-Adresse: {$audit_entry['ip_address']}\n";
    $body .= "Zeit: {$audit_entry['timestamp']}\n";
    $body .= "Erfolg: " . ($audit_entry['success'] ? 'Ja' : 'Nein') . "\n\n";
    
    if (!empty($audit_entry['details'])) {
        $body .= "Details:\n" . print_r($audit_entry['details'], true) . "\n\n";
    }
    
    $body .= "Bitte überprüfen Sie die Scheduler-Sicherheitslogs im Plugin-Dashboard.\n\n";
    $body .= "-- CSV Import Pro Sicherheitssystem";
    
    wp_mail($admin_email, $subject, $body);
}

/**
 * Prüft ob ein Benutzer Scheduler-Aktionen durchführen darf
 * Zusätzliche Sicherheitsebene über die WordPress-Capabilities hinaus
 * 
 * @param int $user_id Benutzer-ID (optional, Standard: aktueller Benutzer)
 * @return bool True wenn berechtigt
 */
function csv_import_can_manage_scheduler(int $user_id = 0): bool {
    if ($user_id === 0) {
        $user_id = get_current_user_id();
    }
    
    // Basis-Capability-Check
    if (!user_can($user_id, 'manage_options')) {
        return false;
    }
    
    // Zusätzliche Sicherheitschecks
    $user = get_user_by('id', $user_id);
    if (!$user) {
        return false;
    }
    
    // Super-Admin-Check für Multisite
    if (is_multisite() && !is_super_admin($user_id)) {
        return false;
    }
    
    // Blacklist-Check (für gesperrte Benutzer)
    $blacklisted_users = get_option('csv_import_scheduler_blacklist', []);
    if (in_array($user_id, $blacklisted_users)) {
        csv_import_log('warning', "Blacklisted user attempted scheduler access: {$user->user_login}", [
            'user_id' => $user_id,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        return false;
    }
    
    // IP-Whitelist-Check (falls konfiguriert)
    $ip_whitelist = get_option('csv_import_scheduler_ip_whitelist', []);
    if (!empty($ip_whitelist)) {
        $current_ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!in_array($current_ip, $ip_whitelist)) {
            csv_import_log('warning', "Scheduler access from non-whitelisted IP: {$current_ip}", [
                'user_id' => $user_id,
                'user_login' => $user->user_login
            ]);
            return false;
        }
    }
    
    return true;
}

/**
 * Holt Scheduler-Aktivierungs-Informationen für das Admin-Interface
 * 
 * @return array Aktivierungsdaten
 */
function csv_import_get_scheduler_activation_info(): array {
    return [
        'enabled' => csv_import_is_scheduler_enabled(),
        'activated_at' => get_option('csv_import_scheduler_activated_at'),
        'activated_by' => get_option('csv_import_scheduler_activated_by'),
        'activated_by_name' => csv_import_get_activation_user_name(),
        'system_health' => csv_import_system_health_check(),
        'can_manage' => csv_import_can_manage_scheduler(),
        'wp_cron_enabled' => !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON),
        'required_functions_available' => csv_import_check_scheduler_dependencies()
    ];
}

/**
 * Holt den Namen des Benutzers der den Scheduler aktiviert hat
 * 
 * @return string Benutzername oder Fallback
 */
function csv_import_get_activation_user_name(): string {
    $user_id = get_option('csv_import_scheduler_activated_by');
    
    if (!$user_id) {
        return 'Nicht verfügbar';
    }
    
    if ($user_id === 0) {
        return 'System (automatisch)';
    }
    
    $user = get_user_by('id', $user_id);
    return $user ? $user->user_login : 'Unbekannter Benutzer (ID: ' . $user_id . ')';
}

/**
 * Prüft die Verfügbarkeit aller für den Scheduler erforderlichen Abhängigkeiten
 * 
 * @return array Status der Dependencies
 */
function csv_import_check_scheduler_dependencies(): array {
    $dependencies = [
        'functions' => [
            'csv_import_get_config' => function_exists('csv_import_get_config'),
            'csv_import_validate_config' => function_exists('csv_import_validate_config'),
            'csv_import_start_import' => function_exists('csv_import_start_import'),
            'csv_import_is_import_running' => function_exists('csv_import_is_import_running'),
            'csv_import_log' => function_exists('csv_import_log')
        ],
        'classes' => [
            'CSV_Import_Scheduler' => class_exists('CSV_Import_Scheduler'),
            'CSV_Import_Error_Handler' => class_exists('CSV_Import_Error_Handler'),
            'CSV_Import_Pro_Run' => class_exists('CSV_Import_Pro_Run')
        ],
        'wordpress' => [
            'cron_enabled' => !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON),
            'wp_version_ok' => version_compare(get_bloginfo('version'), '5.0', '>='),
            'admin_ajax_available' => is_admin()
        ]
    ];
    
    // Zusammenfassung der Verfügbarkeit
    $summary = [
        'all_functions_available' => !in_array(false, $dependencies['functions']),
        'all_classes_available' => !in_array(false, $dependencies['classes']),
        'wordpress_compatible' => !in_array(false, $dependencies['wordpress']),
        'details' => $dependencies
    ];
    
    $summary['scheduler_ready'] = $summary['all_functions_available'] && 
                                  $summary['all_classes_available'] && 
                                  $summary['wordpress_compatible'];
    
    return $summary;
}

// ===================================================================
// ENDE DER NEUEN SCHEDULER-AKTIVIERUNGSFUNKTIONEN
// ===================================================================


csv_import_log( 'debug', 'CSV Import Pro Core Functions geladen - Version 5.2 (Dashboard Widget bereinigt)' );
