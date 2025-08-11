<?php
/**
 * View-Datei für die Debug Seite.
 * NEUE VERSION: Modernes Grid-Layout.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
    <div class="csv-dashboard-header">
        <h1>🛠️ CSV Import Debug</h1>
        <p>Technische Informationen zur Fehlersuche und Systemanalyse.</p>
    </div>

    <?php
    if ( isset( $cleanup_message ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $cleanup_message ) . '</p></div>';
    }
    ?>

    <div class="csv-import-dashboard">
        <div class="csv-import-box">
            <h3><span class="step-icon">🔒</span> Aktive Import-Locks</h3>
            <p>Diese Einträge verhindern, dass ein neuer Import gestartet wird. Sie sollten normalerweise nur während eines Imports vorhanden sein.</p>
            <div class="sample-data-container" style="max-height: 200px;">
                <?php if ( !empty( $locks ) ) : ?>
                    <table class="wp-list-table widefat striped">
                        <thead><tr><th>Name</th><th>Wert</th></tr></thead>
                        <tbody>
                            <?php foreach ( $locks as $lock ) : ?>
                                <tr>
                                    <td><code><?php echo esc_html($lock->option_name); ?></code></td>
                                    <td><pre style="white-space: pre-wrap; word-break: break-all;"><?php echo esc_html(print_r(maybe_unserialize($lock->option_value), true)); ?></pre></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <div class="info-message"><strong>Info:</strong> Keine aktiven Import-Locks gefunden.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="csv-import-box">
            <h3><span class="step-icon">⌛</span> Hängende Prozesse</h3>
            <p>Dies sind geplante Aufgaben (Cron-Jobs), die möglicherweise feststecken.</p>
             <div class="sample-data-container" style="max-height: 200px;">
                <?php if ( !empty( $stuck_jobs ) ) : ?>
                    <table class="wp-list-table widefat striped">
                        <thead><tr><th>Hook</th><th>Status</th><th>Geplant am (GMT)</th></tr></thead>
                        <tbody>
                            <?php foreach ( $stuck_jobs as $job ) : ?>
                                <tr>
                                    <td><code><?php echo esc_html($job->hook); ?></code></td>
                                    <td><?php echo esc_html($job->status); ?></td>
                                    <td><?php echo esc_html($job->scheduled_date_gmt); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <div class="info-message"><strong>Info:</strong> Keine hängenden Prozesse gefunden.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="csv-import-box" style="grid-column: 1 / -1;">
             <h3><span class="step-icon">🧹</span> System bereinigen</h3>
             <p>Mit dieser Aktion können Sie alle Locks und hängenden Prozesse manuell entfernen. Nutzen Sie dies nur, wenn Sie sicher sind, dass kein Import mehr läuft.</p>
            <form method="post">
                <?php wp_nonce_field('csv_import_debug'); ?>
                <div class="action-buttons">
                    <?php submit_button('Alle Locks & Prozesse bereinigen', 'delete large', 'csv_import_cleanup', false, ['onclick' => "return confirm('Achtung: Dies ist eine Notfall-Funktion. Wirklich alles bereinigen?');"]); ?>
                </div>
            </form>
        </div>
    </div>
</div>
