<?php
/**
 * View-Datei für die Backup & Rollback Seite.
 * NEUE VERSION: Modernes Grid-Layout.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<div class="csv-dashboard-header">
		<h1>📦 CSV Import Backups &amp; Rollback</h1>
		<p>Machen Sie vergangene Imports rückgängig und verwalten Sie Ihre Backups.</p>
	</div>

    <?php
    if ( isset( $rollback_result ) ) { /* ... Notices ... */ }
    if ( isset( $deleted_count ) ) { /* ... Notices ... */ }
    ?>
    
    <div class="csv-import-dashboard">
        <div class="csv-import-box" style="grid-column: 1 / -1;">
            <h3><span class="step-icon">🔄</span> Import-Sessions für Rollback</h3>
            <p>Hier können Sie vergangene Imports rückgängig machen. <strong>Achtung:</strong> Ein Rollback löscht alle durch den Import erstellten Posts unwiderruflich.</p>
            
            <div class="sample-data-container" style="max-height: none;">
				<?php if ( empty( $sessions ) ) : ?>
					<div class="info-message"><strong>Info:</strong> Keine Import-Sessions mit Backups gefunden.</div>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th>Session ID</th>
								<th>Import-Datum</th>
								<th>Quelle</th>
								<th>Anzahl Posts</th>
								<th style="width: 150px;">Aktionen</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $sessions as $session ) : ?>
								<tr>
									<td><code><?php echo esc_html( $session->import_session ); ?></code></td>
									<td><?php echo mysql2date( 'd.m.Y H:i:s', $session->import_date ); ?></td>
									<td>
										<?php 
										$source_labels = [ 'dropbox' => '☁️ Dropbox', 'local' => '📁 Lokal' ];
										echo esc_html( $source_labels[ $session->import_source ] ?? $session->import_source );
										?>
									</td>
									<td><?php echo esc_html( $session->post_count ); ?> Posts</td>
									<td>
										<form method="post" onsubmit="return confirm('Wirklich alle <?php echo esc_js( $session->post_count ); ?> Posts aus diesem Import löschen?');">
											<?php wp_nonce_field( 'csv_import_rollback' ); ?>
											<input type="hidden" name="rollback_session" value="<?php echo esc_attr( $session->import_session ); ?>">
											<button type="submit" class="button button-secondary">Rollback</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
        </div>
        
        <div class="csv-import-box">
            <h3><span class="step-icon">🧹</span> Backup-Verwaltung</h3>
            <?php
            $advanced_settings = get_option( 'csv_import_advanced_settings', ['backup_retention_days' => 30] );
            $retention_days = $advanced_settings['backup_retention_days'] ?? 30;
            ?>
            <p>Alte Backups, die älter als <strong><?php echo esc_html($retention_days); ?> Tage</strong> sind, können hier manuell bereinigt werden.</p>
            <form method="post">
                <?php wp_nonce_field( 'csv_import_cleanup_backups' ); ?>
                <input type="hidden" name="cleanup_backups" value="1">
				<div class="action-buttons">
					<button type="submit" class="button" onclick="return confirm('Alle Backups älter als <?php echo esc_js($retention_days); ?> Tage wirklich löschen?');">
						🗑️ Alte Backups jetzt bereinigen
					</button>
				</div>
            </form>
            <p class="description" style="margin-top: 15px;">
                Die Aufbewahrungsdauer können Sie in den <a href="<?php echo esc_url(admin_url('tools.php?page=csv-import-advanced')); ?>">erweiterten Einstellungen</a> ändern.
            </p>
        </div>
    </div>
</div>
