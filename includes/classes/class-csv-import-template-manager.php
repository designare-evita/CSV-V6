<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direkten Zugriff verhindern
}
// ===================================================================
// TEMPLATE MANAGEMENT SYSTEM
// ===================================================================

class CSV_Import_Template_Manager {
    
    public static function create_template_from_post($post_id, $template_name) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('post_not_found', 'Post nicht gefunden');
        }
        
        $template_data = [
            'name' => sanitize_text_field($template_name),
            'post_type' => $post->post_type,
            'post_content' => $post->post_content,
            'meta_data' => get_post_meta($post_id),
            'created_at' => current_time('mysql'),
            'created_by' => get_current_user_id()
        ];
        
        $templates = get_option('csv_import_templates', []);
        $template_id = uniqid('tpl_');
        $templates[$template_id] = $template_data;
        
        update_option('csv_import_templates', $templates);
        
        return $template_id;
    }
    
    public static function get_templates() {
        return get_option('csv_import_templates', []);
    }
    
    public static function get_template($template_id) {
        $templates = self::get_templates();
        return $templates[$template_id] ?? null;
    }
    
    public static function delete_template($template_id) {
        $templates = self::get_templates();
        if (isset($templates[$template_id])) {
            unset($templates[$template_id]);
            update_option('csv_import_templates', $templates);
            return true;
        }
        return false;
    }
    
    public static function apply_template($template_id, $data) {
        $template = self::get_template($template_id);
        if (!$template) {
            return new WP_Error('template_not_found', 'Template nicht gefunden');
        }
        
        // Template-Content mit Daten befüllen
        $content = $template['post_content'];
        foreach ($data as $key => $value) {
            $content = str_replace('{{' . $key . '}}', wp_kses_post($value), $content);
        }
        
        return [
            'post_type' => $template['post_type'],
            'post_content' => $content,
            'meta_data' => $template['meta_data']
        ];
    }
}