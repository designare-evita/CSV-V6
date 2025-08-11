<?php
/**
 * View-Datei für die Seite "Erweiterte Einstellungen".
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>Erweiterte CSV Import Einstellungen</h1>

    <?php
    if ( isset( $settings_saved ) && $settings_saved === true ) {
        echo '<div class="notice notice-success is-dismissible"><p>Erweiterte Einstellungen gespeichert.</p></div>';
    }
    ?>

	<form method="post">
		<?php wp_nonce_field( 'csv_import_advanced_settings' ); ?>

		<div class="csv-advanced-settings">
			<div class="card">
				<h2>⚡ Performance Einstellungen</h2>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><label for="batch_size">Batch-Größe</label></th>
							<td>
								<input type="number" id="batch_size" name="batch_size"
									   value="<?php echo esc_attr( $advanced_settings['batch_size'] ?? 25 ); ?>"
									   min="1" max="200" class="small-text">
								<p class="description">Anzahl der CSV-Zeilen, die pro Durchgang verarbeitet werden (empfohlen: 10-50).</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Performance Logging</th>
							<td>
								<label>
									<input type="checkbox" name="performance_logging" value="1"
										   <?php checked( $advanced_settings['performance_logging'] ?? true ); ?>>
									Detaillierte Performance-Logs (Laufzeit, Speicher) erstellen.
								</label>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="card">
				<h2>🚨 Fehlerbehandlung</h2>
				<p class="description">Legen Sie fest, nach wie vielen Fehlern eines bestimmten Typs ein Import automatisch abbricht.</p>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><label for="max_critical_errors">Max. kritische Fehler</label></th>
							<td>
								<input type="number" id="max_critical_errors" name="max_critical_errors"
									   value="<?php echo esc_attr( $advanced_settings['max_errors_per_level']['critical'] ?? 1 ); ?>"
									   min="1" max="10" class="small-text">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="max_error_errors">Max. Fehler</label></th>
							<td>
								<input type="number" id="max_error_errors" name="max_error_errors"
									   value="<?php echo esc_attr( $advanced_settings['max_errors_per_level']['error'] ?? 10 ); ?>"
									   min="1" max="100" class="small-text">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="max_warning_errors">Max. Warnungen</label></th>
							<td>
								<input type="number" id="max_warning_errors" name="max_warning_errors"
									   value="<?php echo esc_attr( $advanced_settings['max_errors_per_level']['warning'] ?? 50 ); ?>"
									   min="1" max="500" class="small-text">
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="card">
				<h2>📋 CSV-Vorverarbeitung</h2>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">Leere Zeilen entfernen</th>
							<td>
								<label>
									<input type="checkbox" name="remove_empty_rows" value="1"
										   <?php checked( $advanced_settings['csv_preprocessing']['remove_empty_rows'] ?? true ); ?>>
									Automatisch leere CSV-Zeilen ignorieren.
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">Werte trimmen</th>
							<td>
								<label>
									<input type="checkbox" name="trim_values" value="1"
										   <?php checked( $advanced_settings['csv_preprocessing']['trim_values'] ?? true ); ?>>
									Führende/nachfolgende Leerzeichen aus allen Werten entfernen.
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">Encoding konvertieren</th>
							<td>
								<label>
									<input type="checkbox" name="convert_encoding" value="1"
										   <?php checked( $advanced_settings['csv_preprocessing']['convert_encoding'] ?? true ); ?>>
									Automatisch versuchen, die CSV-Datei nach UTF-8 zu konvertieren.
								</label>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="card">
				<h2>🔒 Sicherheitseinstellungen</h2>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">Strikte SSL-Verifikation</th>
							<td>
								<label>
									<input type="checkbox" name="strict_ssl_verification" value="1"
										   <?php checked( $advanced_settings['security_settings']['strict_ssl_verification'] ?? true ); ?>>
									SSL-Zertifikate bei Downloads (z.B. von Dropbox) strikt prüfen.
								</label>
								<p class="description">Sollte für Produktionsumgebungen immer aktiviert sein.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="allowed_file_extensions">Erlaubte Datei-Erweiterungen</label></th>
							<td>
								<input type="text" id="allowed_file_extensions" name="allowed_file_extensions"
									   value="<?php echo esc_attr( implode( ', ', $advanced_settings['security_settings']['allowed_file_extensions'] ?? ['csv'] ) ); ?>"
									   class="regular-text">
								<p class="description">Komma-getrennte Liste (z.B. csv, txt).</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="max_file_size_mb">Max. Dateigröße (MB)</label></th>
							<td>
								<input type="number" id="max_file_size_mb" name="max_file_size_mb"
									   value="<?php echo esc_attr( $advanced_settings['security_settings']['max_file_size_mb'] ?? 50 ); ?>"
									   min="1" max="500" class="small-text">
								<p class="description">Maximale Größe für zu verarbeitende CSV-Dateien.</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="card">
				<h2>💾 Backup-Einstellungen</h2>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><label for="backup_retention_days">Backup-Aufbewahrung</label></th>
							<td>
								<input type="number" id="backup_retention_days" name="backup_retention_days"
									   value="<?php echo esc_attr( $advanced_settings['backup_retention_days'] ?? 30 ); ?>"
									   min="1" max="365" class="small-text"> Tage
								<p class="description">Wie lange sollen Rollback-Informationen aufbewahrt werden?</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="card">
				<h2>ℹ️ System-Information</h2>
				<table class="form-table">
					<tbody>
						<tr><th>PHP Version</th><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
						<tr><th>WordPress Version</th><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
						<tr><th>Memory Limit</th><td><?php echo esc_html( ini_get( 'memory_limit' ) ); ?></td></tr>
						<tr><th>Time Limit</th><td><?php echo esc_html( ini_get( 'max_execution_time' ) ); ?>s</td></tr>
						<tr><th>Upload Max Filesize</th><td><?php echo esc_html( ini_get( 'upload_max_filesize' ) ); ?></td></tr>
						<tr><th>Plugin Version</th><td><?php echo esc_html( CSV_IMPORT_PRO_VERSION ); ?></td></tr>
					</tbody>
				</table>
			</div>
		</div>

		<?php submit_button( 'Einstellungen speichern' ); ?>
	</form>
</div>