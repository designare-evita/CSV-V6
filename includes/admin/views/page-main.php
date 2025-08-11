<?php
/**
 * View-Datei für das Haupt-Import-Dashboard.
 * NEUE VERSION: 2x2 Grid Layout für bessere Übersichtlichkeit
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<div class="csv-dashboard-header">
		<h1>🚀 CSV Import für Landingpages</h1>
		<p>Importieren Sie Ihre Landingpage-Daten in 4 einfachen Schritten</p>
	</div>

	<?php if ( isset( $_GET['result'] ) && isset( $_GET['message'] ) ) : ?>
		<div class="notice notice-<?php echo $_GET['result'] === 'success' ? 'success' : 'error'; ?> is-dismissible">
			<p><?php echo esc_html( urldecode( $_GET['message'] ) ); ?></p>
		</div>
	<?php endif; ?>

	<?php 
    $progress = csv_import_get_progress();
    if ( $progress['status'] === 'processing' ) : 
    ?>
		<div class="notice notice-info">
			<p><strong>Import läuft:</strong> <?php echo esc_html( $progress['processed'] ); ?> von <?php echo esc_html( $progress['total'] ); ?> Zeilen verarbeitet (<?php echo esc_html( $progress['percent'] ); ?>%)</p>
			<div class="progress-container">
				<div class="progress-bar-fill" style="width: <?php echo esc_attr( $progress['percent'] ); ?>%;"></div>
			</div>
		</div>
	<?php endif; ?>

	<?php 
    $config_valid = csv_import_validate_config( csv_import_get_config() );
    if ( ! $config_valid['valid'] ) : 
    ?>
		<div class="notice notice-error">
			<p><strong>Konfigurationsfehler:</strong></p>
			<ul style="margin-left: 20px; list-style-type: disc;">
				<?php foreach ( $config_valid['errors'] as $error ) : ?>
					<li><?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=csv-import-settings' ) ); ?>"
				   class="button button-primary">⚙️ Einstellungen konfigurieren</a>
			</p>
		</div>
	<?php endif; ?>

	<!-- 🎯 NEUES 2x2 DASHBOARD LAYOUT -->
	<div class="csv-import-dashboard">
		
		<!-- BOX 1: Dropbox Import (Oben Links) -->
		<div class="csv-import-box">
			<h3>
				<span class="step-number <?php echo $config_valid['dropbox_ready'] ? 'completed' : 'pending'; ?>">1</span>
				<span class="step-icon">🔗</span>
				Dropbox Import
			</h3>
			
			<?php if ( $config_valid['dropbox_ready'] ) : ?>
				<span class="status-indicator status-success">✅ Konfiguration bereit</span>
			<?php else : ?>
				<span class="status-indicator status-error">❌ Konfiguration fehlt</span>
			<?php endif; ?>
			
			<p>Importiert die CSV-Datei von der in den Einstellungen hinterlegten Dropbox-URL.</p>
			
			<?php if ( $config_valid['dropbox_ready'] && $progress['status'] !== 'processing' ) : ?>
				<div class="action-buttons">
					<button data-source="dropbox" class="button button-primary button-large csv-import-btn"
					   onclick="return confirm('Dropbox Import wirklich starten?');">
						🚀 Dropbox Import starten
					</button>
				</div>
				<div class="info-message">
					<strong>Bereit:</strong> Dropbox-URL konfiguriert und erreichbar
				</div>
			<?php else : ?>
				<div class="action-buttons">
					<button class="button button-primary button-large" disabled>
						🚀 Dropbox Import starten
					</button>
				</div>
				<div class="error-message">
					<?php if ( $progress['status'] === 'processing' ) : ?>
						⏳ Import läuft bereits
					<?php else : ?>
						⚠️ Konfiguration unvollständig oder Dropbox-URL fehlt/ist ungültig.
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<!-- BOX 2: Lokaler Import (Oben Rechts) -->
		<div class="csv-import-box">
			<h3>
				<span class="step-number <?php echo $config_valid['local_ready'] ? 'completed' : 'pending'; ?>">2</span>
				<span class="step-icon">📁</span>
				Lokaler Import
			</h3>
			
			<?php if ( $config_valid['local_ready'] ) : ?>
				<span class="status-indicator status-success">✅ Datei gefunden</span>
			<?php else : ?>
				<span class="status-indicator status-error">❌ Datei nicht gefunden</span>
			<?php endif; ?>
			
			<p>Importiert die CSV-Datei vom in den Einstellungen hinterlegten lokalen Serverpfad.</p>
			
			<?php if ( $config_valid['local_ready'] && $progress['status'] !== 'processing' ) : ?>
				<div class="action-buttons">
					<button data-source="local" class="button button-primary button-large csv-import-btn"
					   onclick="return confirm('Lokalen Import wirklich starten?');">
						🚀 Lokalen Import starten
					</button>
				</div>
				<div class="info-message">
					<strong>Bereit:</strong> CSV-Datei auf Server gefunden und lesbar
				</div>
			<?php else : ?>
				<div class="action-buttons">
					<button class="button button-primary button-large" disabled>
						🚀 Lokalen Import starten
					</button>
				</div>
				<div class="error-message">
					<?php if ( $progress['status'] === 'processing' ) : ?>
						⏳ Import läuft bereits
					<?php else : ?>
						⚠️ Konfiguration unvollständig oder lokale CSV-Datei nicht gefunden/lesbar.
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<!-- BOX 3: System Status (Unten Links) -->
		<div class="csv-import-box">
			<h3>
				<span class="step-number completed">3</span>
				<span class="step-icon">📊</span>
				System Status
			</h3>
			
			<?php
			$health = csv_import_system_health_check();
			$health_count = count(array_filter($health));
			$total_checks = count($health);
			$all_healthy = $health_count === $total_checks;
			?>
			
			<?php if ( $all_healthy ) : ?>
				<span class="status-indicator status-success">✅ System gesund</span>
			<?php else : ?>
				<span class="status-indicator status-error">⚠️ <?php echo ($total_checks - $health_count); ?> Probleme</span>
			<?php endif; ?>
			
			<p>Überprüfung der Systemvoraussetzungen und aktuellen Status.</p>
			
			<?php
			$health_labels = [
				'memory_ok'         => 'Memory Limit',
				'php_version_ok'    => 'PHP Version',
				'disk_space_ok'     => 'Freier Speicher',
				'permissions_ok'    => 'Schreibrechte',
				'time_ok'           => 'Ausführungszeit',
				'curl_ok'           => 'cURL Extension',
				'wp_version_ok'     => 'WordPress Version',
				'import_locks_ok'   => 'Import Locks',
				'no_stuck_processes' => 'Hängende Prozesse'
			];
			?>
			
			<ul class="status-list" style="margin: 10px 0; font-size: 13px;">
				<?php $shown = 0; ?>
				<?php foreach ( $health as $check => $status ) : ?>
					<?php if(isset($health_labels[$check]) && $shown < 6): ?>
					<li style="margin: 3px 0;">
						<?php echo $status ? '✅' : '❌'; ?>
						<span style="margin-left: 5px;"><?php echo esc_html( $health_labels[ $check ] ); ?></span>
					</li>
					<?php $shown++; ?>
					<?php endif; ?>
				<?php endforeach; ?>
				<?php if (count($health) > 6): ?>
					<li style="color: #8c8f94; font-style: italic;">... und <?php echo (count($health) - 6); ?> weitere</li>
				<?php endif; ?>
			</ul>
			
			<div class="action-buttons">
				<button type="button" class="button" onclick="csvImportSystemHealth()">🔧 Vollständiger Check</button>
			</div>
		</div>

		<!-- BOX 4: Statistiken & Import-Status (Unten Rechts) -->
		<div class="csv-import-box">
			<h3>
				<span class="step-number completed">4</span>
				<span class="step-icon">📈</span>
				Statistiken & Status
			</h3>
			
			<span class="status-indicator status-success">📊 Daten verfügbar</span>
			
			<p>Übersicht über vergangene Imports und aktuelle Systemstatistiken.</p>
			
			<?php $stats = csv_import_get_stats(); ?>
			<ul class="status-list" style="margin: 10px 0; font-size: 13px;">
				<li style="margin: 5px 0;">
					<strong>Gesamt importiert:</strong> 
					<span style="color: #00a32a; font-weight: bold;"><?php echo esc_html( get_option( 'csv_import_total_imported', 0 ) ); ?></span>
				</li>
				<li style="margin: 5px 0;">
					<strong>Letzter Import:</strong><br>
					<span style="color: #646970;">
						<?php
						$last_run = get_option( 'csv_import_last_run' );
						echo esc_html( $last_run ? mysql2date( 'd.m.Y H:i', $last_run ) : 'Nie' );
						?>
					</span>
				</li>
				<li style="margin: 5px 0;">
					<strong>Letzte Anzahl:</strong> 
					<span style="color: #2271b1;"><?php echo esc_html( get_option( 'csv_import_last_count', 0 ) ); ?></span>
				</li>
				<li style="margin: 5px 0;">
					<strong>Letzte Quelle:</strong> 
					<span style="color: #646970;"><?php echo esc_html( get_option( 'csv_import_last_source', 'Keine' ) ); ?></span>
				</li>
			</ul>
			
			<div class="action-buttons">
				<button type="button" class="button" onclick="location.reload();">📊 Aktualisieren</button>
			</div>
			
			<?php if ( get_option( 'csv_import_total_imported', 0 ) > 0 ) : ?>
				<div class="success-message">
					<strong>Erfolg:</strong> Letzte Importe erfolgreich abgeschlossen
				</div>
			<?php else : ?>
				<div class="info-message">
					<strong>Info:</strong> Noch keine Importe durchgeführt
				</div>
			<?php endif; ?>
		</div>

	</div>

	<!-- 🎯 WICHTIG: Ursprüngliche Bottom-Actions bleiben erhalten -->
	<div class="csv-dashboard-footer">
		<div class="bottom-actions">
			<a href="<?php echo esc_url( admin_url( 'tools.php?page=csv-import-settings' ) ); ?>"
			   class="button button-secondary">⚙️ Alle Einstellungen</a>
			<button type="button" class="button button-secondary" onclick="location.reload();">🔄 Seite aktualisieren</button>

			<?php // NEUER RESET-BUTTON BLEIBT ERHALTEN ?>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url('tools.php?page=csv-import&csv_emergency_reset=1'), 'csv_import_emergency_reset' ) ); ?>"
			   class="button button-danger" style="color: #d63638; border-color: #d63638;"
			   onclick="return confirm('Notfall-Reset wirklich durchführen? Alle Sperren werden entfernt.');">
				🚨 Notfall-Reset
			</a>
		</div>
		
		<p style="margin-top: 15px;">
			💡 <strong>Brauchen Sie Hilfe?</strong> 
			Schauen Sie in die <a href="#" target="_blank">Dokumentation</a> oder kontaktieren Sie den 
			<a href="#" target="_blank">Support</a>.
		</p>
	</div>
</div>