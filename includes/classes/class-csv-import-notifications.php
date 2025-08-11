<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direkten Zugriff verhindern
}
// ===================================================================
// NOTIFICATION SYSTEM
// ===================================================================

class CSV_Import_Notifications {

    public static function init() {
        add_action('csv_import_completed', [__CLASS__, 'send_completion_notification'], 10, 2);
        add_action('csv_import_failed', [__CLASS__, 'send_failure_notification'], 10, 2);
    }

    public static function send_completion_notification($result, $source) {
        $settings = get_option('csv_import_notification_settings', [
            'email_on_success' => false,
            'email_on_failure' => true,
            'recipients' => [get_option('admin_email')]
        ]);

        if (!$settings['email_on_success']) {
            return;
        }

        $subject = sprintf('[%s] CSV Import erfolgreich abgeschlossen', get_bloginfo('name'));
        $message  = "Der CSV-Import wurde erfolgreich abgeschlossen.\n\n";
        $message .= "Details:\n";
        $message .= "- Quelle: $source\n";
        $message .= "- Zeit: " . current_time('Y-m-d H:i:s') . "\n";
        $message .= "- Ergebnis: " . $result['message'] . "\n";

        foreach ($settings['recipients'] as $recipient) {
            wp_mail($recipient, $subject, $message);
        }
    }

    public static function send_failure_notification($error, $source) {
        $settings = get_option('csv_import_notification_settings', [
            'email_on_success' => false,
            'email_on_failure' => true,
            'recipients' => [get_option('admin_email')]
        ]);

        if (!$settings['email_on_failure']) {
            return;
        }

        $subject = sprintf('[%s] CSV Import fehlgeschlagen', get_bloginfo('name'));
        $message  = "Der CSV-Import ist fehlgeschlagen.\n\n";
        $message .= "Details:\n";
        $message .= "- Quelle: $source\n";
        $message .= "- Zeit: " . current_time('Y-m-d H:i:s') . "\n";
        $message .= "- Fehler: $error\n\n";
        $message .= "Import-Dashboard: " . admin_url('tools.php?page=csv-import') . "\n";

        foreach ($settings['recipients'] as $recipient) {
            wp_mail($recipient, $subject, $message);
        }
    }

    public static function update_notification_settings($settings) {
        $defaults = [
            'email_on_success' => false,
            'email_on_failure' => true,
            'recipients' => [get_option('admin_email')]
        ];
        $settings = array_merge($defaults, $settings);
        $settings['recipients'] = array_filter($settings['recipients'], 'is_email');
        if (empty($settings['recipients'])) {
            $settings['recipients'] = [get_option('admin_email')];
        }
        update_option('csv_import_notification_settings', $settings);
        return $settings;
    }
}