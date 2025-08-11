<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direkten Zugriff verhindern
}
// ===================================================================
// BACKUP & RECOVERY SYSTEM
// ===================================================================

class CSV_Import_Backup_Manager {
    private static $backup_table = 'csv_import_backups';

    public static function init() {
        // Dieser Hook wird jetzt zentral in der Haupt-Plugin-Datei aufgerufen.
        add_action('csv_import_post_created', [__CLASS__, 'backup_post'], 10, 3);
    }

    public static function create_backup_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$backup_table;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            import_session varchar(32) NOT NULL,
            post_id bigint(20) NOT NULL,
            post_data longtext NOT NULL,
            meta_data longtext,
            import_source varchar(20) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY import_session (import_session),
            KEY post_id (post_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function backup_post($post_id, $session_id, $source) {
        global $wpdb;
        $post = get_post($post_id);
        if (!$post) return false;

        $meta_data = get_post_meta($post_id);
        $table_name = $wpdb->prefix . self::$backup_table;

        return $wpdb->insert(
            $table_name,
            [
                'import_session' => $session_id,
                'post_id'        => $post_id,
                'post_data'      => maybe_serialize($post),
                'meta_data'      => maybe_serialize($meta_data),
                'import_source'  => $source
            ],
            ['%s', '%d', '%s', '%s', '%s']
        );
    }

    public static function rollback_import($session_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$backup_table;
        $backups = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE import_session = %s ORDER BY id DESC",
            $session_id
        ));

        $restored = 0;
        $errors = [];

        foreach ($backups as $backup) {
            $result = wp_delete_post($backup->post_id, true);
            if ($result) {
                $restored++;
            } else {
                $errors[] = "Post {$backup->post_id} konnte nicht gelöscht werden";
            }
        }

        $wpdb->delete($table_name, ['import_session' => $session_id], ['%s']);

        return [
            'success'  => empty($errors),
            'restored' => $restored,
            'errors'   => $errors
        ];
    }

    public static function get_import_sessions($limit = 20) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$backup_table;

        return $wpdb->get_results($wpdb->prepare("
            SELECT
                import_session,
                import_source,
                COUNT(*) as post_count,
                MIN(created_at) as import_date
            FROM $table_name
            GROUP BY import_session
            ORDER BY import_date DESC
            LIMIT %d
        ", $limit));
    }

    public static function cleanup_old_backups($days = 30) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$backup_table;

        return $wpdb->query($wpdb->prepare("
            DELETE FROM $table_name
            WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
        ", $days));
    }
}