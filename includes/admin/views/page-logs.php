<?php
/**
 * View-Datei für die Logs & Monitoring Seite.
 * NEUE VERSION: Modernes Grid-Layout für bessere Übersicht.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<div class="csv-dashboard-header">
		<h1>📈 CSV Import Logs &amp; Monitoring</h1>
		<p>Überwachen Sie den Systemstatus und analysieren Sie detaillierte Import-Protokolle.</p>
	</div>

	<?php
	if ( isset( $_GET['logs_cleared'] ) && $_GET['logs_cleared'] === 'true' ) {
		echo '<div class="notice notice-success is-dismissible"><p>Alle Logs wurden gelöscht.</p></div>';
	}
	?>

	<div class="csv-import-dashboard">

		<div class="csv-import-box">
			<h3><span class="step-icon">❤️</span> System Health</h3>
			<?php
			$health_labels = [
				'memory_ok'         => 'Memory Limit', 'php_version_ok'    => 'PHP Version',
				'disk_space_ok'     => 'Freier Speicher', 'permissions_ok'    => 'Schreibrechte',
				'time_ok'           => 'Ausführungszeit', 'curl_ok'           => 'cURL Extension',
				'wp_version_ok'     => 'WordPress Version', 'import_locks_ok'   => 'Import Locks',
				'no_stuck_processes' => 'Hängende Prozesse'
			];
			$all_healthy = !in_array(false, array_values($health), true);
			?>
			<span class="status-indicator <?php echo $all_healthy ? 'status-success' : 'status-error'; ?>">
				<?php echo $all_healthy ? 'Gesund' : 'Probleme erkannt'; ?>
			</span>

			<ul class="status-list" style="margin-top: 15px;">
				<?php foreach ( $health as $check => $status ) : ?>
					<?php if ( isset( $health_labels[$check] ) ) : ?>
					<li><?php echo $status ? '✅' : '❌'; ?> <?php echo esc_html( $health_labels[$check] ); ?></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
		</div>

		<div class="csv-import-box">
			<h3><span class="step-icon">📊</span> Fehler-Statistiken</h3>
			<span class="status-indicator status-active">
				<?php echo esc_html( $error_stats['total_real_errors'] ?? 0 ); ?> Fehler/Warnungen
			</span>
			<div class="error-stats" style="margin-top: 15px;">
				<div class="stat-item">
					<strong><?php echo esc_html( $error_stats['total_errors'] ?? 0 ); ?></strong>
					<span>Gesamt</span>
				</div>
				<?php foreach ( ['critical', 'error', 'warning', 'info'] as $level ) : ?>
					<?php if ( !empty($error_stats['errors_by_level'][$level]) ): ?>
					<div class="stat-item level-<?php echo esc_attr( $level ); ?>">
						<strong><?php echo esc_html( $error_stats['errors_by_level'][$level] ); ?></strong>
						<span><?php echo esc_html( ucfirst( $level ) ); ?></span>
					</div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="csv-import-box" style="grid-column: 1 / -1;">
			<h3><span class="step-icon">📋</span> Import Logs</h3>
			<div class="log-filters">
				<a href="<?php echo esc_url( admin_url('tools.php?page=csv-import-logs') ); ?>" 
				   class="button <?php echo $filter_level === 'all' ? 'button-primary' : ''; ?>">
					Alle
				</a>
				<?php foreach ( ['critical', 'error', 'warning', 'info'] as $level ) : ?>
					<?php if ( !empty($error_stats['errors_by_level'][$level]) ): ?>
					<a href="<?php echo esc_url( add_query_arg('level', $level) ); ?>" 
					   class="button <?php echo $filter_level === $level ? 'button-primary' : ''; ?>">
						<?php echo esc_html( ucfirst($level) ); ?> (<?php echo esc_html($error_stats['errors_by_level'][$level]); ?>)
					</a>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			
			<div class="sample-data-container" style="max-height: 500px; margin-top: 15px;">
				<?php if ( empty( $logs ) ) : ?>
					<div class="info-message"><strong>Info:</strong> Keine Logs für den gewählten Filter gefunden.</div>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped sample-data-table">
						<thead>
							<tr>
								<th style="width:160px;">Zeitpunkt</th>
								<th style="width:110px;">Level</th>
								<th>Nachricht</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $logs as $log_entry ) : ?>
								<tr>
									<td><?php echo esc_html( mysql2date('d.m.Y H:i:s', $log_entry['timestamp']) ); ?></td>
									<td>
										<span class="status-indicator level-<?php echo esc_attr( $log_entry['level'] ); ?>" style="padding: 3px 6px;">
											<?php echo esc_html( strtoupper( $log_entry['level'] ) ); ?>
										</span>
									</td>
									<td>
										<div class="log-message" title="<?php echo esc_attr( wp_json_encode($log_entry['context'] ?? '', JSON_PRETTY_PRINT) ); ?>">
											<?php echo esc_html( $log_entry['message'] ); ?>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
				
			<?php if ( $total_pages > 1 ) : /* Pagination ... */ endif; ?>
			
			<div class="action-buttons" style="margin-top: 20px;">
				<form method="post" onsubmit="return confirm('Alle Logs wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.');">
					<?php wp_nonce_field( 'csv_import_clear_logs' ); ?>
					<input type="hidden" name="action" value="clear_logs">
					<button type="submit" class="button button-secondary">🗑️ Alle Logs löschen</button>
				</form>
			</div>
		</div>
	</div>
</div>
