<?php
/**
 * Die zentrale Klasse zur Durchführung des CSV-Imports.
 * Korrigierte Version - kompatibel mit core-functions.php
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CSV_Import_Pro_Run {

	private array $config;
	private ?object $template_post;
	private string $session_id;
	private array $existing_slugs = [];
	private string $source;
	private array $csv_data = [];
    private array $mapping = [];

	private function __construct( string $source ) {
		$this->source     = $source;
		$this->session_id = 'run_' . time() . '_' . uniqid();
	}

	public static function run( string $source, array $mapping = [] ): array { // Zeile anpassen
    $importer = new self( $source );
    $importer->mapping = $mapping; // Diese Zeile hinzufügen
    return $importer->execute_import();
}

	private function execute_import(): array {
		// Fehler-Handler Check
		if ( class_exists( 'CSV_Import_Error_Handler' ) && method_exists( 'CSV_Import_Error_Handler', 'reset_error_counts' ) ) {
			CSV_Import_Error_Handler::reset_error_counts();
		}
		
		do_action( 'csv_import_start' );
		update_option( 'csv_import_session_id', $this->session_id );
		
		try {
			$this->load_and_validate_config();
			$this->set_system_limits();

			// CSV-Daten über core-functions laden
			$this->csv_data = csv_import_load_csv_data( $this->source, $this->config );
			
			if ( empty( $this->csv_data['data'] ) ) {
				throw new Exception( 'CSV muss mindestens Header und eine Datenzeile enthalten.' );
			}
			
			$header = $this->csv_data['headers'];
			$data_rows = $this->csv_data['data'];
			
			$this->validate_header( $header );
			update_option( 'csv_import_current_header', implode( ',', $header ) );

			csv_import_log( 'info', "CSV-Import gestartet: " . count( $data_rows ) . " Zeilen." );
			
			$batch_size = apply_filters( 'csv_import_batch_size', 25 );
			$results = $this->process_batches( $data_rows, $header, $batch_size );

			$message = sprintf( 
				'Import erfolgreich: %d Posts erstellt, %d Duplikate übersprungen, %d Fehler.', 
				$results['created'], 
				$results['skipped'], 
				$results['errors'] 
			);
			
			$final_result = [ 
				'success' => ( $results['errors'] === 0 ), 
				'message' => $message,
				'processed' => $results['created'],
				'total' => count( $data_rows ),
				'errors' => $results['errors']
			];
			
			do_action( 'csv_import_completed', $final_result, $this->source );
			csv_import_log( 'info', $message );
			
			$this->cleanup_after_import();
			return $final_result;

		} catch ( Exception $e ) {
			$this->cleanup_after_import( true );
			$error_message = 'Kritischer Import-Fehler: ' . $e->getMessage();
			csv_import_log( 'critical', $error_message, [ 'source' => $this->source ] );
			do_action( 'csv_import_failed', $error_message, $this->source );
			
			return [ 
				'success' => false, 
				'message' => $e->getMessage(),
				'processed' => 0,
				'total' => 0,
				'errors' => 1
			];
		}
	}


	private function apply_mapping( array $row ): array {
    if ( empty( $this->mapping ) ) {
        return $row;
    }

    $mapped_row = $row; // ✅ Alle Original-Daten beibehalten
    
    foreach ( $this->mapping as $original_column => $target_field ) {
        if ( isset( $row[ $original_column ] ) && ! empty( $target_field ) ) {
            $mapped_row[ $target_field ] = $row[ $original_column ]; // ✅ Hinzufügen
            // ✅ KEINE unset() Operation mehr!
        }
    }
    
    return $mapped_row;
}
	
	private function load_and_validate_config(): void {
		$this->config = csv_import_get_config();
		
		// Basis-Validierung
		if ( empty( $this->config['post_type'] ) ) {
			throw new Exception( 'Post-Typ nicht konfiguriert' );
		}
		
		if ( ! post_type_exists( $this->config['post_type'] ) ) {
			throw new Exception( 'Post-Typ existiert nicht: ' . $this->config['post_type'] );
		}
		
		// Template validieren falls erforderlich
		if ( ! empty( $this->config['template_id'] ) ) {
			$this->template_post = get_post( $this->config['template_id'] );
			if ( ! $this->template_post ) {
				throw new Exception( 'Template Post nicht gefunden: ID ' . $this->config['template_id'] );
			}
		}
	}

	private function process_batches( array $rows, array $header, int $batch_size ): array {
		$results = [
			'created' => 0,
			'skipped' => 0,
			'errors' => 0,
			'error_messages' => []
		];
		
		$total_rows = count( $rows );
		$processed = 0;
		
		// Erforderliche Spalten prüfen
		$required_columns = $this->config['required_columns'] ?? [];
		if ( is_string( $required_columns ) ) {
			$required_columns = array_filter( array_map( 'trim', explode( "\n", $required_columns ) ) );
		}
		
		$column_validation = csv_import_validate_required_columns( $header, $required_columns );
		if ( ! $column_validation['valid'] ) {
			throw new Exception( 'Erforderliche Spalten fehlen: ' . implode( ', ', $column_validation['missing'] ) );
		}
		
		foreach ( $rows as $index => $row_data ) {
    try {
        // Fortschritt aktualisieren
        if ( $processed % 5 === 0 ) {
            csv_import_update_progress( $processed, $total_rows, 'processing' );
        }

        $mapped_row = $this->apply_mapping( $row_data ); // DIESE ZEILE HINZUFÜGEN

        $post_result = $this->process_single_row( $mapped_row ); // HIER $row_data zu $mapped_row ändern

        if ( $post_result === 'created' ) {
					$results['created']++;
				} elseif ( $post_result === 'skipped' ) {
					$results['skipped']++;
				}
				
				$processed++;
				
				// Kurze Pause alle 10 Posts
				if ( $processed % 10 === 0 ) {
					usleep( 100000 ); // 0.1 Sekunde
				}
				
			} catch ( Exception $e ) {
				$results['errors']++;
				$error_msg = "Zeile " . ($index + 2) . ": " . $e->getMessage();
				$results['error_messages'][] = $error_msg;
				
				csv_import_log( 'warning', $error_msg, [
					'row_data' => $row_data,
					'session_id' => $this->session_id
				] );
				
				// Maximale Fehleranzahl erreicht?
				if ( $results['errors'] > 50 ) {
					csv_import_log( 'error', 'Import abgebrochen - zu viele Fehler (>50)' );
					break;
				}
			}
		}
		
		return $results;
	}

	private function process_single_row( array $data ): string {
		// Post-Grunddaten extrahieren
		$post_title = $this->sanitize_title( $data['post_title'] ?? $data['title'] ?? '' );
		$post_content = $data['post_content'] ?? $data['content'] ?? '';
		$post_excerpt = $data['post_excerpt'] ?? $data['excerpt'] ?? '';
		
		if ( empty( $post_title ) ) {
			throw new Exception( 'Post-Titel ist erforderlich' );
		}
		
		// Duplikat prüfen
		if ( ! empty( $this->config['skip_duplicates'] ) ) {
			$existing_post = get_page_by_title( $post_title, OBJECT, $this->config['post_type'] );
			if ( $existing_post ) {
				return 'skipped'; // Duplikat übersprungen
			}
		}
		
		// Eindeutigen Slug generieren
		$post_slug = $this->generate_unique_slug( $post_title );
		
		$post_id = $this->create_post_transaction( $data, $post_slug );
		
		if ( $post_id ) {
			return 'created';
		} else {
			throw new Exception( 'Post konnte nicht erstellt werden' );
		}
	}

	private function create_post_transaction( array $data, string $post_slug ): ?int {
		// Post-Daten zusammenstellen
		$post_data = [
			'post_title'   => $this->sanitize_title( $data['post_title'] ?? $data['title'] ?? '' ),
			'post_content' => $data['post_content'] ?? $data['content'] ?? '',
			'post_excerpt' => $data['post_excerpt'] ?? $data['excerpt'] ?? '',
			'post_name'    => $post_slug,
			'post_status'  => $this->config['post_status'] ?? 'draft',
			'post_type'    => $this->config['post_type'] ?? 'post',
			'meta_input'   => [
				'_csv_import_session' => $this->session_id,
				'_csv_import_date' => current_time( 'mysql' ),
			]
		];
		
		// Template anwenden falls vorhanden
		if ( $this->template_post && ! empty( $this->config['page_builder'] ) && $this->config['page_builder'] !== 'none' ) {
			$post_data['post_content'] = $this->apply_template( $data );
		}
		
		// Post erstellen
		$post_id = wp_insert_post( $post_data );
		
		if ( is_wp_error( $post_id ) ) {
			throw new Exception( 'WordPress Fehler: ' . $post_id->get_error_message() );
		}
		
		// Meta-Felder hinzufügen
		$this->add_meta_fields( $post_id, $data );
		
		// Bilder verarbeiten
		if ( ! empty( $this->config['image_source'] ) && $this->config['image_source'] !== 'none' ) {
			$this->process_post_images( $post_id, $data );
		}
		
		return $post_id;
	}

	private function validate_header( array $header ): void {
		if ( empty( $header ) ) {
			throw new Exception( 'CSV-Header ist leer' );
		}
		
		// Prüfen ob mindestens post_title oder title vorhanden ist
		$title_fields = ['post_title', 'title'];
		$has_title_field = false;
		
		foreach ( $title_fields as $field ) {
			if ( in_array( $field, $header ) ) {
				$has_title_field = true;
				break;
			}
		}
		
		if ( ! $has_title_field ) {
			throw new Exception( 'CSV muss eine post_title oder title Spalte enthalten' );
		}
	}

	private function set_system_limits(): void {
		if ( ! empty( $this->config['memory_limit'] ) ) {
			@ini_set( 'memory_limit', $this->config['memory_limit'] );
		}
		
		if ( ! empty( $this->config['time_limit'] ) ) {
			@set_time_limit( (int) $this->config['time_limit'] );
		}
	}
	
	private function cleanup_after_import( bool $is_error = false ): void {
		delete_option( 'csv_import_current_header' );
		delete_option( 'csv_import_session_id' );
		
		if ( ! $is_error ) {
			csv_import_update_progress( 0, 0, 'completed' );
		} else {
			csv_import_clear_progress();
		}
	}
	
	// ===================================================================
	// PRIVATE HELPER METHODEN
	// ===================================================================
	
	private function sanitize_title( string $title ): string {
		$title = trim( $title );
		$title = wp_strip_all_tags( $title );
		$title = html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );
		return $title;
	}
	
	private function generate_unique_slug( string $title ): string {
		$slug = sanitize_title( $title );
		
		if ( empty( $slug ) ) {
			$slug = 'csv-import-post-' . uniqid();
		}
		
		// Prüfen ob Slug bereits verwendet wurde in diesem Import
		if ( in_array( $slug, $this->existing_slugs ) ) {
			$counter = 1;
			$original_slug = $slug;
			while ( in_array( $slug, $this->existing_slugs ) || get_page_by_path( $slug, OBJECT, $this->config['post_type'] ) ) {
				$slug = $original_slug . '-' . $counter;
				$counter++;
			}
		}
		
		$this->existing_slugs[] = $slug;
		return $slug;
	}
	
	private function apply_template( array $data ): string {
		if ( ! $this->template_post ) {
			return $data['post_content'] ?? '';
		}
		
		$content = $this->template_post->post_content;
		
		// Platzhalter ersetzen
		foreach ( $data as $key => $value ) {
			$placeholder = '{{' . $key . '}}';
			$content = str_replace( $placeholder, $value, $content );
		}
		
		// Standard-Platzhalter
		$content = str_replace( '{{title}}', $data['post_title'] ?? $data['title'] ?? '', $content );
		$content = str_replace( '{{content}}', $data['post_content'] ?? $data['content'] ?? '', $content );
		
		return $content;
	}
	
	private function add_meta_fields( int $post_id, array $data ): void {
		// Standard-Felder überspringen
		$skip_fields = ['post_title', 'title', 'post_content', 'content', 'post_excerpt', 'excerpt', 'post_name'];
		
		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $skip_fields ) && ! empty( $value ) ) {
				// Meta-Key normalisieren
				$meta_key = sanitize_key( $key );
				if ( strpos( $meta_key, '_' ) !== 0 ) { // PHP 7.4 kompatibel
					$meta_key = '_' . $meta_key;
				}
				
				update_post_meta( $post_id, $meta_key, sanitize_text_field( $value ) );
			}
		}
	}
	
	private function process_post_images( int $post_id, array $data ): void {
		$image_fields = ['image', 'featured_image', 'thumbnail', 'post_image'];
		$image_url = '';
		
		// Bild-URL finden
		foreach ( $image_fields as $field ) {
			if ( ! empty( $data[ $field ] ) ) {
				$image_url = $data[ $field ];
				break;
			}
		}
		
		if ( empty( $image_url ) ) {
			return;
		}
		
		try {
			// Verwende core-functions für Bild-Download
			$attachment_id = csv_import_download_and_attach_image( $image_url, $post_id );
			
			if ( $attachment_id ) {
				set_post_thumbnail( $post_id, $attachment_id );
				update_post_meta( $post_id, '_csv_import_image_attached', true );
			}
			
		} catch ( Exception $e ) {
			csv_import_log( 'warning', "Bild-Fehler für Post {$post_id}: " . $e->getMessage() );
		}
	}
}
