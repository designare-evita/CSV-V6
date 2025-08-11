<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direkten Zugriff verhindern
}
// ===================================================================
// IMPORT PROFILES SYSTEM
// ===================================================================

class CSV_Import_Profile_Manager {
    
    public static function save_profile($name, $config) {
        $profiles = get_option('csv_import_profiles', []);
        
        $profile_id = sanitize_key($name);
        $profiles[$profile_id] = [
            'name' => sanitize_text_field($name),
            'config' => $config,
            'created_at' => current_time('mysql'),
            'created_by' => get_current_user_id(),
            'last_used' => null,
            'use_count' => 0
        ];
        
        update_option('csv_import_profiles', $profiles);
        
        return $profile_id;
    }
    
    public static function get_profiles() {
        return get_option('csv_import_profiles', []);
    }
    
    public static function get_profile($profile_id) {
        $profiles = self::get_profiles();
        return $profiles[$profile_id] ?? null;
    }
    
    public static function load_profile($profile_id) {
        $profile = self::get_profile($profile_id);
        if (!$profile) {
            return false;
        }
        
        // Konfiguration laden
        foreach ($profile['config'] as $key => $value) {
            update_option('csv_import_' . $key, $value);
        }
        
        // Nutzungsstatistik aktualisieren
        $profiles = self::get_profiles();
        $profiles[$profile_id]['last_used'] = current_time('mysql');
        $profiles[$profile_id]['use_count']++;
        update_option('csv_import_profiles', $profiles);
        
        return true;
    }
    
    public static function delete_profile($profile_id) {
        $profiles = self::get_profiles();
        if (isset($profiles[$profile_id])) {
            unset($profiles[$profile_id]);
            update_option('csv_import_profiles', $profiles);
            return true;
        }
        return false;
    }
}