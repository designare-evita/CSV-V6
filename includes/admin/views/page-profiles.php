<?php
/**
 * View-Datei für die Profil-Management Seite.
 * NEUE VERSION: Modernes Grid-Layout.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<div class="csv-dashboard-header">
		<h1>💾 CSV Import Profile</h1>
		<p>Speichern und laden Sie wiederverwendbare Import-Konfigurationen.</p>
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
			<h3><span class="step-icon">➕</span> Neues Profil speichern</h3>
			<p>Speichern Sie Ihre aktuelle CSV-Import-Konfiguration als wiederverwendbares Profil.</p>
			
			<form method="post">
				<?php wp_nonce_field( 'csv_import_save_profile' ); ?>
				<input type="hidden" name="action" value="save_profile">
				<table class="form-table compact-form">
					<tbody>
						<tr>
							<th scope="row"><label for="profile_name">Profil-Name</label></th>
							<td>
								<input type="text" id="profile_name" name="profile_name" class="regular-text" placeholder="z.B. Landingpages Standard" required>
								<p class="description">Geben Sie einen eindeutigen Namen für das Profil ein.</p>
							</td>
						</tr>
					</tbody>
				</table>
				<div class="action-buttons" style="margin-top: 10px;">
					<?php submit_button( 'Profil speichern', 'primary', 'save_profile', false ); ?>
				</div>
			</form>
		</div>
		
		<div class="csv-import-box" style="grid-column: 1 / -1;">
			<h3><span class="step-icon">📋</span> Gespeicherte Profile</h3>
			
			<div class="sample-data-container" style="max-height: none;">
				<?php if ( empty( $profiles ) ) : ?>
					<div class="info-message"><strong>Info:</strong> Es wurden noch keine Profile gespeichert.</div>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th>Profil-Name</th>
								<th>Erstellt</th>
								<th>Letzte Nutzung</th>
								<th style="width: 100px;">Nutzungen</th>
								<th style="width: 220px;">Aktionen</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $profiles as $profile_id => $profile ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $profile['name'] ); ?></strong></td>
									<td><?php echo esc_html( mysql2date( 'd.m.Y H:i', $profile['created_at'] ) ); ?></td>
									<td>
										<?php
										echo esc_html( $profile['last_used']
											? mysql2date( 'd.m.Y H:i', $profile['last_used'] )
											: 'Nie' );
										?>
									</td>
									<td><?php echo esc_html( $profile['use_count'] ); ?>x</td>
									<td>
										<form method="post" style="display: inline;">
											<?php wp_nonce_field( 'csv_import_load_profile' ); ?>
											<input type="hidden" name="action" value="load_profile">
											<input type="hidden" name="profile_id" value="<?php echo esc_attr( $profile_id ); ?>">
											<button type="submit" class="button button-primary">📂 Laden</button>
										</form>
										
										<form method="post" style="display: inline;" onsubmit="return confirm('Profil wirklich löschen?');">
											<?php wp_nonce_field( 'csv_import_delete_profile' ); ?>
											<input type="hidden" name="action" value="delete_profile">
											<input type="hidden" name="profile_id" value="<?php echo esc_attr( $profile_id ); ?>">
											<button type="submit" class="button button-secondary">🗑️ Löschen</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
