<?php
// OPTIMIERTER QUICK FIX: Scheduler-Historie mit verbesserter Erkennung
if (empty($scheduled_imports)) {
    $history = [];
    
    // 1. Error Handler Logs durchsuchen
    if (class_exists('CSV_Import_Error_Handler')) {
        $error_logs = CSV_Import_Error_Handler::get_persistent_errors();
        if (is_array($error_logs)) {
            foreach ($error_logs as $log) {
                if (isset($log['message'])) {
                    $msg = strtolower($log['message']);
                    $original_msg = $log['message'];
                    
                    // Erweiterte Kriterien für Import-Logs
                    $is_import_log = false;
                    
                    // Direkte Scheduler-Keywords
                    if (strpos($msg, 'geplant') !== false || 
                        strpos($msg, 'scheduled') !== false || 
                        strpos($msg, 'automatisch') !== false ||
                        strpos($msg, 'cron') !== false) {
                        $is_import_log = true;
                    }
                    
                    // Import-Keywords mit zusätzlichen Indikatoren
                    if (strpos($msg, 'import') !== false) {
                        // System-User (automatische Imports)
                        if (isset($log['user_id']) && $log['user_id'] == 0) {
                            $is_import_log = true;
                        }
                        
                        // Import-Status-Keywords
                        if (strpos($msg, 'gestartet') !== false || 
                            strpos($msg, 'erfolgreich') !== false ||
                            strpos($msg, 'abgeschlossen') !== false ||
                            strpos($msg, 'verarbeitet') !== false ||
                            strpos($msg, 'completed') !== false) {
                            $is_import_log = true;
                        }
                        
                        // Session-IDs die auf Scheduler hinweisen
                        if (strpos($original_msg, 'scheduled_') !== false ||
                            strpos($original_msg, 'auto_') !== false ||
                            strpos($original_msg, 'cron_') !== false) {
                            $is_import_log = true;
                        }
                    }
                    
                    if ($is_import_log) {
                        $history[] = [
                            'time' => $log['time'] ?? $log['timestamp'] ?? date('Y-m-d H:i:s'),
                            'level' => $log['level'] ?? 'info',
                            'message' => $original_msg
                        ];
                    }
                }
            }
        }
    }
    
    // 2. Letzte Import-Statistiken hinzufügen (falls verfügbar)
    $last_run = get_option('csv_import_last_run');
    $last_count = get_option('csv_import_last_count', 0);
    $last_source = get_option('csv_import_last_source', 'Unbekannt');
    
    if ($last_run && $last_count > 0) {
        // Prüfen ob dieser Import-Eintrag nicht bereits in den Logs vorhanden ist
        $already_exists = false;
        foreach ($history as $existing) {
            if (abs(strtotime($existing['time']) - strtotime($last_run)) < 60) { // Innerhalb 1 Minute
                $already_exists = true;
                break;
            }
        }
        
        if (!$already_exists) {
            $history[] = [
                'time' => $last_run,
                'level' => 'info',
                'message' => "Import erfolgreich abgeschlossen: {$last_count} Einträge verarbeitet (Quelle: {$last_source})"
            ];
        }
    }
    
    // 3. WordPress Cron-Events prüfen (falls noch geplant)
    $next_scheduled = wp_next_scheduled('csv_import_scheduled');
    if ($next_scheduled && empty($history)) {
        $history[] = [
            'time' => date('Y-m-d H:i:s'),
            'level' => 'info', 
            'message' => 'Scheduler ist aktiv - Nächster automatischer Import geplant für ' . date('d.m.Y H:i:s', $next_scheduled)
        ];
    }
    
    // 4. Fallback: Wenn immer noch leer, zeige Scheduler-Status
    if (empty($history) && !empty($is_scheduled)) {
        $history[] = [
            'time' => date('Y-m-d H:i:s'),
            'level' => 'info',
            'message' => 'Automatischer Import-Scheduler ist konfiguriert und aktiv'
        ];
    }
    
    // Sortieren (neueste zuerst) und begrenzen
    if (!empty($history)) {
        usort($history, function($a, $b) {
            return strtotime($b['time']) - strtotime($a['time']);
        });
        
        $scheduled_imports = array_slice($history, 0, 20);
    }
}

// Debug-Information (nur bei WP_DEBUG anzeigen)
if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
    $debug_info = [
        'found_logs' => count($scheduled_imports ?? []),
        'error_handler_available' => class_exists('CSV_Import_Error_Handler'),
        'last_run_available' => !empty(get_option('csv_import_last_run')),
        'scheduler_active' => !empty($is_scheduled),
        'next_scheduled' => wp_next_scheduled('csv_import_scheduled')
    ];
    echo '<!-- CSV Import Scheduler Debug: ' . esc_html(print_r($debug_info, true)) . ' -->';
}

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

		<div class="csv-import-box" style="grid-column: 1 / -1;">
			<h3>
				<span class="step-number">3</span>
				<span class="step-icon">📊</span>
				Scheduling-Historie
			</h3>
			<p>Die letzten 20 Aktionen des automatischen Schedulers.</p>
			<div class="sample-data-container" style="max-height: 300px;">
				<?php if ( empty( $scheduled_imports ) ) : ?>
					<div class="info-message">
						<strong>Info:</strong> Noch keine automatischen Imports gefunden.
						<?php if ( !empty( $is_scheduled ) ) : ?>
							<br><small>Der Scheduler ist aktiv - Historie wird nach dem ersten automatischen Import angezeigt.</small>
						<?php else : ?>
							<br><small>Aktivieren Sie zunächst einen geplanten Import oben.</small>
						<?php endif; ?>
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
										<?php elseif ( ($import['level'] ?? 'error') === 'warning' ) : ?>
											<span class="status-indicator status-pending" style="padding: 3px 6px;">Warnung</span>
										<?php else : ?>
											<span class="status-indicator status-error" style="padding: 3px 6px;">Fehler</span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $import['message'] ?? '' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					
					<?php if ( defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options') ) : ?>
						<p class="description" style="margin-top: 10px;">
							<strong>Debug:</strong> <?php echo count($scheduled_imports); ?> Einträge gefunden.
							<?php if ( !empty($last_run) ) : ?>
								Letzter Import: <?php echo esc_html($last_run); ?>.
							<?php endif; ?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
