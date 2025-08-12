<?php
/**
 * View-Datei für die Einstellungsseite.
 * NEUE VERSION: Flexibles Grid Layout für optimale Darstellung
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<div class="csv-dashboard-header">
		<h1>⚙️ CSV Import Einstellungen</h1>
		<p>Konfigurieren Sie alle Aspekte des CSV-Imports für optimale Ergebnisse</p>
	</div>

	<?php
	if ( isset( $_GET['settings-updated'] ) ) {
		settings_errors();
	}
	?>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'csv_import_settings' );
		?>
		
		<!-- 🎯 NEUES FLEXIBLES SETTINGS GRID -->
		<div class="csv-settings-dashboard">

			<!-- ROW 1: Basis-Konfiguration & Quellen -->
			<div class="csv-import-box settings-box">
				<h3>
					<span class="step-number completed">1</span>
					<span class="step-icon">📋</span>
					Basis-Konfiguration
				</h3>
				<span class="status-indicator status-success">✅ Grundeinstellungen</span>
				
				<table class="form-table compact-form" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="csv_import_template_id">Template-Post ID</label></th>
							<td>
								<input type="number" id="csv_import_template_id" name="csv_import_template_id"
									   value="<?php echo esc_attr( get_option( 'csv_import_template_id' ) ); ?>"
									   class="small-text">
								<p class="description">
									ID der Vorlage. Aktuell: <?php echo csv_import_get_template_info(); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_page_builder">Page Builder</label></th>
							<td>
								<select id="csv_import_page_builder" name="csv_import_page_builder">
									<?php
									$pb_options = [
    'gutenberg' => 'Gutenberg (Standard)',
    'elementor' => 'Elementor',
    'wpbakery' => 'WPBakery',
    'breakdance' => 'Breakdance', 
    'enfold' => 'Enfold'        
];
									foreach ( $pb_options as $val => $label ) {
										echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current_pb, $val, false ) . '>' . esc_html( $label ) . '</option>';
									}
									?>
								</select>
								<p class="description">Editor für das Template</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_post_type">Post-Typ</label></th>
							<td>
								<select id="csv_import_post_type" name="csv_import_post_type">
									<?php
									$post_types    = get_post_types( [ 'public' => true ], 'objects' );
									$current_ptype = get_option( 'csv_import_post_type', 'page' );
									foreach ( $post_types as $post_type ) {
										echo '<option value="' . esc_attr( $post_type->name ) . '" ' . selected( $current_ptype, $post_type->name, false ) . '>' . esc_html( $post_type->label ) . '</option>';
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_post_status">Post-Status</label></th>
							<td>
								<select id="csv_import_post_status" name="csv_import_post_status">
									<?php
									$status_options = [ 'draft' => 'Entwurf', 'publish' => 'Veröffentlicht', 'private' => 'Privat', 'pending' => 'Ausstehend' ];
									$current_status = get_option( 'csv_import_post_status', 'draft' );
									foreach ( $status_options as $val => $label ) {
										echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current_status, $val, false ) . '>' . esc_html( $label ) . '</option>';
									}
									?>
								</select>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="csv-import-box settings-box">
				<h3>
					<span class="step-number completed">2</span>
					<span class="step-icon">🔗</span>
					CSV-Quellen
				</h3>
				
				<?php 
				$dropbox_url = get_option( 'csv_import_dropbox_url' );
				$local_path = get_option( 'csv_import_local_path', 'data/landingpages.csv' );
				$has_sources = !empty($dropbox_url) || !empty($local_path);
				?>
				
				<?php if ( $has_sources ) : ?>
					<span class="status-indicator status-success">✅ Quellen konfiguriert</span>
				<?php else : ?>
					<span class="status-indicator status-error">❌ Keine Quellen</span>
				<?php endif; ?>
				
				<table class="form-table compact-form" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="csv_import_dropbox_url">Dropbox CSV-URL</label></th>
							<td>
								<input type="url" id="csv_import_dropbox_url" name="csv_import_dropbox_url"
									   value="<?php echo esc_attr( get_option( 'csv_import_dropbox_url' ) ); ?>"
									   class="regular-text" placeholder="https://www.dropbox.com/s/...?dl=1">
								<p class="description">Direkt-Download-Link. Muss mit `?dl=1` enden.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_local_path">Lokaler CSV-Pfad</label></th>
							<td>
								<input type="text" id="csv_import_local_path" name="csv_import_local_path"
									   value="<?php echo esc_attr( get_option( 'csv_import_local_path', 'data/landingpages.csv' ) ); ?>"
									   class="regular-text">
								<p class="description">
									Pfad: <code><?php echo esc_html( ABSPATH . get_option( 'csv_import_local_path', 'data/landingpages.csv' ) ); ?></code>
									<?php echo csv_import_get_file_status( ABSPATH . get_option( 'csv_import_local_path', 'data/landingpages.csv' ) ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_delimiter">CSV-Trennzeichen</label></th>
							<td>
								<input type="text" id="csv_import_delimiter" name="csv_import_delimiter"
									   value="<?php echo esc_attr( get_option( 'csv_import_delimiter', 'auto' ) ); ?>"
									   class="small-text">
								<p class="description">
									Standard: <code>auto</code> für automatische Erkennung.
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- ROW 2: Medien & SEO -->
			<div class="csv-import-box settings-box">
				<h3>
					<span class="step-number completed">3</span>
					<span class="step-icon">🖼️</span>
					Medien-Einstellungen
				</h3>
				<span class="status-indicator status-active">⚙️ Medien-Konfiguration</span>
				
				<table class="form-table compact-form" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="csv_import_image_source">Bildquelle</label></th>
							<td>
								<select id="csv_import_image_source" name="csv_import_image_source">
									<?php
									$img_src_options = [ 'media_library' => 'WordPress-Mediathek', 'local_folder' => 'Lokaler Ordner' ];
									$current_img_src = get_option( 'csv_import_image_source', 'media_library' );
									foreach ( $img_src_options as $val => $label ) {
										echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current_img_src, $val, false ) . '>' . esc_html( $label ) . '</option>';
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_image_folder">Lokaler Bild-Ordner</label></th>
							<td>
								<input type="text" id="csv_import_image_folder" name="csv_import_image_folder"
									   value="<?php echo esc_attr( get_option( 'csv_import_image_folder', 'wp-content/uploads/csv-import-images/' ) ); ?>"
									   class="regular-text">
								<p class="description">
									Pfad relativ zum WordPress-Root.
									<?php echo csv_import_get_file_status( ABSPATH . get_option( 'csv_import_image_folder' ), true ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="csv-import-box settings-box">
				<h3>
					<span class="step-number completed">4</span>
					<span class="step-icon">🎯</span>
					SEO & Erweitert
				</h3>
				<span class="status-indicator status-active">🎯 SEO-Einstellungen</span>
				
				<table class="form-table compact-form" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="csv_import_seo_plugin">SEO-Plugin</label></th>
							<td>
								<select id="csv_import_seo_plugin" name="csv_import_seo_plugin">
									<?php
									$seo_options    = [ 'none' => 'Keins / Manuell', 'aioseo' => 'All in One SEO', 'yoast' => 'Yoast SEO', 'rankmath' => 'Rank Math' ];
									$current_seo_pl = get_option( 'csv_import_seo_plugin', 'none' );
									foreach ( $seo_options as $val => $label ) {
										echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current_seo_pl, $val, false ) . '>' . esc_html( $label ) . '</option>';
									}
									?>
								</select>
								<p class="description">Wähle dein aktives SEO-Plugin</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_required_columns">Erforderliche Spalten</label></th>
							<td>
								<textarea id="csv_import_required_columns" name="csv_import_required_columns" rows="3" class="large-text"><?php echo esc_textarea( get_option( 'csv_import_required_columns', "post_title\npost_name" ) ); ?></textarea>
								<p class="description">Eine Spalte pro Zeile</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Duplikate</th>
							<td>
								<label>
									<input type="checkbox" name="csv_import_skip_duplicates" value="1"
										<?php checked( get_option( 'csv_import_skip_duplicates' ), 1 ); ?> >
									Duplikate überspringen (basierend auf Post-Titel)
								</label>
							</td>
						</tr>
<tr>
							<th scope="row">Suchmaschinen</th>
							<td>
								<label>
									<input type="checkbox" name="csv_import_noindex_posts" value="1"
										<?php checked( get_option( 'csv_import_noindex_posts' ), 1 ); ?> >
									Alle importierten Posts auf "noindex" setzen.
								</label>
								<p class="description">Verhindert, dass Suchmaschinen diese Seiten indizieren. Nützlich für reine Landingpages.</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- ROW 3: Test & Validierung -->
			<div class="csv-import-box settings-box">
				<h3>
					<span class="step-number active">5</span>
					<span class="step-icon">🧪</span>
					Konfiguration testen
				</h3>
				<span class="status-indicator status-pending">⏳ Bereit für Tests</span>
				
				<p>Überprüfen Sie Ihre Einstellungen und die CSV-Dateien vor dem Import.</p>
				
				<div class="action-buttons">
					<button type="button" class="button button-secondary" onclick="csvImportTestConfig()">⚙️ Konfiguration prüfen</button>
					<button type="button" class="button button-secondary" onclick="csvImportValidateCSV('dropbox')">📊 Dropbox CSV validieren</button>
					<button type="button" class="button button-secondary" onclick="csvImportValidateCSV('local')">📁 Lokale CSV validieren</button>
				</div>
				
				<div id="csv-test-results" class="test-results-container"></div>
			</div>

			<div class="csv-import-box settings-box">
				<h3>
					<span class="step-number">6</span>
					<span class="step-icon">📊</span>
					CSV Beispieldaten
				</h3>
				<span class="status-indicator status-pending">📄 Daten-Vorschau</span>
				
				<p class="description">Nach einer erfolgreichen CSV-Validierung werden hier die ersten Zeilen angezeigt.</p>
				
				<div id="csv-sample-data-container" class="sample-data-container">
					<div class="info-message">
						<strong>Info:</strong> Führen Sie zuerst eine CSV-Validierung durch, um Beispieldaten zu sehen.
					</div>
				</div>
			</div>

			<!-- ROW 4: Column Mapping (Spans full width when visible) -->
			<div id="csv-column-mapping-container" class="csv-import-box settings-box mapping-box" style="display:none;">
				<h3>
					<span class="step-number active">7</span>
					<span class="step-icon">🔄</span>
					Spalten-Zuordnung
				</h3>
				<span class="status-indicator status-active">🔄 Mapping aktiv</span>
				
				<!-- Column mapping content will be inserted here by JavaScript -->
			</div>

		</div>

		<!-- Save Button -->
		<div class="csv-dashboard-footer">
			<?php submit_button( '💾 Einstellungen speichern', 'primary large', 'submit', false ); ?>
			
			<div style="margin-top: 15px;">
				<p>
					💡 <strong>Tipp:</strong> Testen Sie Ihre Konfiguration nach dem Speichern mit den Validierungs-Buttons.
				</p>
			</div>
		</div>
	</form>
</div>
