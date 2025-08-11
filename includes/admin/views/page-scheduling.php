<?php
/**
 * View-Datei für die Scheduling Seite.
 * NEUE VERSION: Modernes Grid-Layout, angepasst an das Haupt-Dashboard.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<div class="csv-dashboard-header">
		<h1>⏰ CSV Import Automatisierung</h1>
		<p>Konfigurieren und überwachen Sie automatische, zeitgesteuerte CSV-Imports.</p>
	</div>

	<?php
	if ( isset( $action_result ) && is_array( $action_result ) ) {
		$notice_class   = $action_result['success'] ? 'notice-success' : 'notice-error';
		$notice_message = $action_result['message'];
		echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . wp_kses_post( $notice_message ) . '</p></div>';
	}
	?>

	<div class="csv-import-dashboard">
		
		<div class="csv-import-box">
			<?php if ( $is_scheduled ) : ?>
				<h3>
					<span class="step-number completed">1</span>
					<span class="step-icon">✅</span>
					Aktiver Zeitplan
				</h3>
				<span class="status-indicator status-success">Aktiv</span>

				<ul class="status-list" style="margin: 15px 0;">
					<li><strong>Quelle:</strong> <?php echo esc_html( ucfirst( $current_source ) ); ?></li>
					<li><strong>Frequenz:</strong> <?php echo esc_html( $available_intervals[$current_frequency] ?? ucfirst( str_replace( '_', ' ', $current_frequency ) ) ); ?></li>
					<li><strong>Nächster Import:</strong>
						<?php
						echo $next_scheduled
							? esc_html( date_i18n( 'd.m.Y H:i:s', $next_scheduled ) ) . ' (in ' . human_time_diff( $next_scheduled ) . ')'
							: 'Unbekannt';
						?>
					</li>
				</ul>

				<form method="post">
					<?php wp_nonce_field( 'csv_import_scheduling' ); ?>
					<input type="hidden" name="action" value="unschedule_import">
					<div class="action-buttons">
						<button type="submit" class="button button-secondary" onclick="return confirm('Geplante Imports wirklich deaktivieren?');">
							⏹️ Scheduling deaktivieren
						</button>
					</div>
				</form>
			<?php else : ?>
				<h3>
					<span class="step-number active">1</span>
					<span class="step-icon">📅</span>
					Neuen Import planen
				</h3>
				<span class="status-indicator status-pending">Inaktiv</span>
				<p>Planen Sie automatische CSV-Imports. Es wird die aktuelle Konfiguration aus den <a href="<?php echo esc_url(admin_url('tools.php?page=csv-import-settings')); ?>">Einstellungen</a> verwendet.</p>

				<form method="post">
					<?php wp_nonce_field( 'csv_import_scheduling' ); ?>
					<input type="hidden" name="action" value="schedule_import">

					<table class="form-table compact-form">
						<tbody>
							<tr>
								<th scope="row"><label for="import_source">Import-Quelle</label></th>
								<td>
									<select id="import_source" name="import_source" required>
										<option value="">-- Quelle wählen --</option>
										<?php if ( $validation['dropbox_ready'] ) : ?>
											<option value="dropbox">☁️ Dropbox</option>
										<?php endif; ?>
										<?php if ( $validation['local_ready'] ) : ?>
											<option value="local">📁 Lokale Datei</option>
										<?php endif; ?>
									</select>
									<p class="description">Nur konfigurierte Quellen sind sichtbar.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="frequency">Frequenz</label></th>
								<td>
									<select id="frequency" name="frequency" required>
										<option value="">-- Frequenz wählen --</option>
										<?php foreach($available_intervals as $key => $label): ?>
											<option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						</tbody>
					</table>
					<div class="action-buttons" style="margin-top: 20px;">
						<?php submit_button( '🚀 Import planen', 'primary large', 'submit', false ); ?>
					</div>
				</form>
			<?php endif; ?>
		</div>

		<div class="csv-import-box">
			<h3>
				<span class="step-number">2</span>
				<span class="step-icon">⚙️</span>
				Benachrichtigungen
			</h3>
			<p>Legen Sie fest, wer per E-Mail über automatische Imports informiert wird.</p>
			<form method="post">
				<?php wp_nonce_field( 'csv_import_notification_settings' ); ?>
				<input type="hidden" name="action" value="update_notifications">

				<table class="form-table compact-form">
					<tbody>
						<tr>
							<th scope="row">Bei Erfolg</th>
							<td>
								<label>
									<input type="checkbox" name="email_on_success" value="1"
										   <?php checked( $notification_settings['email_on_success'] ?? false ); ?>>
									E-Mail senden
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">Bei Fehlern</th>
							<td>
								<label>
									<input type="checkbox" name="email_on_failure" value="1"
										   <?php checked( $notification_settings['email_on_failure'] ?? true ); ?>>
									E-Mail senden
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="recipients">Empfänger</label></th>
							<td>
								<textarea id="recipients" name="recipients" rows="2" class="large-text"><?php
									$recipients = $notification_settings['recipients'] ?? [ get_option( 'admin_email' ) ];
									echo esc_textarea( implode( "\n", $recipients ) );
								?></textarea>
								<p class="description">Eine E-Mail-Adresse pro Zeile.</p>
							</td>
						</tr>
					</tbody>
				</table>
				<div class="action-buttons" style="margin-top: 10px;">
					<?php submit_button( 'Benachrichtigungen speichern', 'secondary', 'submit', false ); ?>
				</div>
			</form>
		</div>

		<div class="csv-import-box" style="grid-column: 1 / -1;"> <h3>
				<span class="step-number">3</span>
				<span class="step-icon">📊</span>
				Scheduling-Historie
			</h3>
			<p>Die letzten 20 Aktionen des automatischen Schedulers.</p>
			<div class="sample-data-container" style="max-height: 300px;">
				<?php if ( empty( $scheduled_imports ) ) : ?>
					<div class="info-message">
						<strong>Info:</strong> Noch keine geplanten Imports ausgeführt.
					</div>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped sample-data-table">
						<thead>
							<tr>
								<th style="width: 160px;">Zeitpunkt</th>
								<th style="width: 100px;">Status</th>
								<th>Nachricht</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $scheduled_imports as $import ) : ?>
								<tr>
									<td><?php echo esc_html( mysql2date( 'd.m.Y H:i:s', $import['time'] ?? '' ) ); ?></td>
									<td>
										<?php if ( ($import['level'] ?? 'error') === 'info' ) : ?>
											<span class="status-indicator status-success" style="padding: 3px 6px;">Erfolg</span>
										<?php else : ?>
											<span class="status-indicator status-error" style="padding: 3px 6px;">Fehler</span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $import['message'] ?? '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
