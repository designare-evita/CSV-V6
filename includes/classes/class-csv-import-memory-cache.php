<?php
/**
 * CSV Import Pro Memory Cache System
 * Version: 1.0
 * 
 * Implementiert ein intelligentes Memory Cache System für das CSV Import Pro Plugin
 * - Object Cache für Meta-Daten, Templates und Konfigurationen
 * - CSV Data Cache für verarbeitete CSV-Daten
 * - Query Cache für WordPress Database Queries
 * - Auto-Invalidation und Memory Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hauptklasse für das Memory Cache System
 */
class CSV_Import_Memory_Cache {
    
    // Cache Namespaces für verschiedene Datentypen
    const CACHE_CONFIG = 'csv_config';
    const CACHE_CSV_DATA = 'csv_data';
    const CACHE_TEMPLATES = 'csv_templates';
    const CACHE_META = 'csv_meta';
    const CACHE_QUERIES = 'csv_queries';
    const CACHE_VALIDATION = 'csv_validation';
    const CACHE_STATS = 'csv_stats';
    
    // Memory Management
    private static $max_memory_usage = 134217728; // 128MB
    private static $cache_hit_ratio = [];
    private static $performance_metrics = [];
    
    // Cache Stores
    private static $object_cache = [];
    private static $csv_cache = [];
    private static $query_cache = [];
    private static $validation_cache = [];
    
    // Cache Statistics
    private static $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'evictions' => 0,
        'memory_usage' => 0
    ];
    
    /**
     * Initialisiert das Cache System
     */
    public static function init() {
        // WordPress Object Cache Integration
        add_action('init', [__CLASS__, 'setup_wordpress_cache_integration']);
        
        // Memory Management Hooks
        add_action('csv_import_before_processing', [__CLASS__, 'prepare_cache_for_import']);
        add_action('csv_import_after_processing', [__CLASS__, 'cleanup_import_cache']);
        
        // Performance Monitoring
        add_action('shutdown', [__CLASS__, 'log_cache_performance']);
        
        // Automatische Bereinigung
        add_action('csv_import_daily_maintenance', [__CLASS__, 'daily_cache_maintenance']);
        
        // Memory Limit setzen basierend auf verfügbarem Speicher
        self::$max_memory_usage = self::calculate_optimal_cache_size();
        
        csv_import_log('debug', 'Memory Cache System initialisiert', [
            'max_cache_size' => size_format(self::$max_memory_usage),
            'php_memory_limit' => ini_get('memory_limit')
        ]);
    }
    
    // ===================================================================
    // HAUPT-CACHE METHODEN
    // ===================================================================
    
    /**
     * Holt einen Wert aus dem Cache
     * 
     * @param string $namespace Cache Namespace
     * @param string $key Cache Key
     * @param mixed $default Fallback Wert
     * @return mixed Cached Wert oder Default
     */
    public static function get(string $namespace, string $key, $default = null) {
        $cache_key = self::build_cache_key($namespace, $key);
        
        // Prüfe verschiedene Cache-Ebenen
        $value = self::get_from_memory_cache($cache_key);
        
        if ($value !== null) {
            self::$stats['hits']++;
            self::track_cache_hit($namespace, $key);
            return $value;
        }
        
        // WordPress Object Cache als Fallback
        $value = self::get_from_object_cache($cache_key);
        
        if ($value !== false) {
            // Zurück in Memory Cache für schnelleren Zugriff
            self::set_memory_cache($cache_key, $value);
            self::$stats['hits']++;
            return $value;
        }
        
        self::$stats['misses']++;
        self::track_cache_miss($namespace, $key);
        
        return $default;
    }
    
    /**
     * Setzt einen Wert im Cache
     * 
     * @param string $namespace Cache Namespace
     * @param string $key Cache Key
     * @param mixed $value Zu cachender Wert
     * @param int $ttl Time to Live in Sekunden
     * @return bool Erfolg
     */
    public static function set(string $namespace, string $key, $value, int $ttl = 3600): bool {
        $cache_key = self::build_cache_key($namespace, $key);
        
        // Memory Management prüfen
        if (!self::can_cache_value($value)) {
            csv_import_log('debug', 'Cache: Wert zu groß für Memory Cache', [
                'key' => $cache_key,
                'size' => strlen(serialize($value))
            ]);
            
            // Nur in WordPress Object Cache
            return self::set_object_cache($cache_key, $value, $ttl);
        }
        
        // Memory Cache
        $success = self::set_memory_cache($cache_key, $value, $ttl);
        
        // WordPress Object Cache für Persistenz
        self::set_object_cache($cache_key, $value, $ttl);
        
        if ($success) {
            self::$stats['sets']++;
        }
        
        return $success;
    }
    
    /**
     * Löscht einen Wert aus dem Cache
     */
    public static function delete(string $namespace, string $key): bool {
        $cache_key = self::build_cache_key($namespace, $key);
        
        $deleted = false;
        
        // Aus Memory Cache löschen
        if (isset(self::$object_cache[$cache_key])) {
            unset(self::$object_cache[$cache_key]);
            $deleted = true;
        }
        
        // Aus WordPress Object Cache löschen
        wp_cache_delete($cache_key, 'csv_import');
        
        return $deleted;
    }
    
    /**
     * Löscht alle Werte eines Namespaces
     */
    public static function flush_namespace(string $namespace): int {
        $deleted = 0;
        $prefix = $namespace . ':';
        
        // Memory Cache durchsuchen
        foreach (self::$object_cache as $key => $value) {
            if (strpos($key, $prefix) === 0) {
                unset(self::$object_cache[$key]);
                $deleted++;
            }
        }
        
        csv_import_log('debug', "Cache Namespace '{$namespace}' geleert", [
            'deleted_items' => $deleted
        ]);
        
        return $deleted;
    }
    
    // ===================================================================
    // SPEZIALISIERTE CACHE METHODEN
    // ===================================================================
    
    /**
     * Cached Konfigurationsdaten
     */
    public static function get_config(string $config_key = null) {
        if ($config_key) {
            return self::get(self::CACHE_CONFIG, $config_key);
        }
        
        $full_config = self::get(self::CACHE_CONFIG, 'full_config');
        
        if ($full_config === null) {
            $full_config = csv_import_get_config();
            self::set(self::CACHE_CONFIG, 'full_config', $full_config, 1800); // 30 Min
        }
        
        return $full_config;
    }
    
    /**
     * Cached Template-Daten
     */
    public static function get_template(int $template_id) {
        $template = self::get(self::CACHE_TEMPLATES, "template_{$template_id}");
        
        if ($template === null) {
            $template_post = get_post($template_id);
            if ($template_post) {
                $template = [
                    'post' => $template_post,
                    'meta' => get_post_meta($template_id),
                    'cached_at' => time()
                ];
                
                self::set(self::CACHE_TEMPLATES, "template_{$template_id}", $template, 3600);
            }
        }
        
        return $template;
    }
    
    /**
     * Cached CSV-Validierung
     */
    public static function get_csv_validation(string $source, string $config_hash) {
        $cache_key = "validation_{$source}_{$config_hash}";
        return self::get(self::CACHE_VALIDATION, $cache_key);
    }
    
    public static function set_csv_validation(string $source, string $config_hash, array $validation, int $ttl = 600) {
        $cache_key = "validation_{$source}_{$config_hash}";
        return self::set(self::CACHE_VALIDATION, $cache_key, $validation, $ttl);
    }
    
    /**
     * Cached CSV-Daten mit Kompression
     */
    public static function get_csv_data(string $source_hash) {
        $compressed_data = self::get(self::CACHE_CSV_DATA, "data_{$source_hash}");
        
        if ($compressed_data && function_exists('gzuncompress')) {
            return unserialize(gzuncompress($compressed_data));
        }
        
        return $compressed_data;
    }
    
    public static function set_csv_data(string $source_hash, array $data, int $ttl = 1800) {
        // Große CSV-Daten komprimieren
        if (function_exists('gzcompress') && count($data) > 100) {
            $compressed = gzcompress(serialize($data), 6);
            return self::set(self::CACHE_CSV_DATA, "data_{$source_hash}", $compressed, $ttl);
        }
        
        return self::set(self::CACHE_CSV_DATA, "data_{$source_hash}", $data, $ttl);
    }
    
    /**
     * Cached Database Queries
     */
    public static function get_query_result(string $query_hash) {
        return self::get(self::CACHE_QUERIES, "query_{$query_hash}");
    }
    
    public static function set_query_result(string $query_hash, $result, int $ttl = 300) {
        return self::set(self::CACHE_QUERIES, "query_{$query_hash}", $result, $ttl);
    }
    
    /**
     * Cached Statistiken
     */
    public static function get_stats(string $stats_type) {
        return self::get(self::CACHE_STATS, $stats_type, []);
    }
    
    public static function set_stats(string $stats_type, array $stats, int $ttl = 900) {
        return self::set(self::CACHE_STATS, $stats_type, $stats, $ttl);
    }
    
    // ===================================================================
    // MEMORY MANAGEMENT
    // ===================================================================
    
    /**
     * Berechnet optimale Cache-Größe basierend auf verfügbarem Speicher
     */
    private static function calculate_optimal_cache_size(): int {
        $memory_limit = ini_get('memory_limit');
        
        if ($memory_limit === '-1') {
            return 268435456; // 256MB wenn unbegrenzt
        }
        
        $memory_bytes = wp_convert_hr_to_bytes($memory_limit);
        $current_usage = memory_get_usage(true);
        $available = $memory_bytes - $current_usage;
        
        // Verwende maximal 25% des verfügbaren Speichers für Cache
        $cache_size = min(
            max($available * 0.25, 67108864), // Minimum 64MB
            268435456 // Maximum 256MB
        );
        
        return (int) $cache_size;
    }
    
    /**
     * Prüft ob ein Wert gecacht werden kann
     */
    private static function can_cache_value($value): bool {
        $serialized_size = strlen(serialize($value));
        $current_usage = self::get_current_cache_memory_usage();
        
        // Prüfe ob genug Speicher verfügbar
        if (($current_usage + $serialized_size) > self::$max_memory_usage) {
            // Versuche Platz zu schaffen
            self::evict_cache_items($serialized_size);
            
            $new_usage = self::get_current_cache_memory_usage();
            if (($new_usage + $serialized_size) > self::$max_memory_usage) {
                return false;
            }
        }
        
        // Prüfe Einzelwert-Größe (max 25% des Cache)
        if ($serialized_size > (self::$max_memory_usage * 0.25)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Räumt Cache-Einträge für neuen Speicher frei
     */
    private static function evict_cache_items(int $needed_space): void {
        $freed = 0;
        $evicted = 0;
        
        // LRU Eviction - älteste Einträge zuerst
        $items_by_access = [];
        
        foreach (self::$object_cache as $key => $item) {
            if (isset($item['last_access'])) {
                $items_by_access[$key] = $item['last_access'];
            }
        }
        
        asort($items_by_access);
        
        foreach ($items_by_access as $key => $last_access) {
            if ($freed >= $needed_space) {
                break;
            }
            
            if (isset(self::$object_cache[$key])) {
                $item_size = strlen(serialize(self::$object_cache[$key]['data']));
                unset(self::$object_cache[$key]);
                
                $freed += $item_size;
                $evicted++;
            }
        }
        
        self::$stats['evictions'] += $evicted;
        
        csv_import_log('debug', 'Cache Eviction durchgeführt', [
            'freed_bytes' => $freed,
            'evicted_items' => $evicted,
            'needed_space' => $needed_space
        ]);
    }
    
    /**
     * Berechnet aktuellen Memory Cache Verbrauch
     */
    private static function get_current_cache_memory_usage(): int {
        $total = 0;
        
        foreach (self::$object_cache as $item) {
            $total += strlen(serialize($item));
        }
        
        self::$stats['memory_usage'] = $total;
        return $total;
    }
    
    // ===================================================================
    // INTERNE CACHE OPERATIONEN
    // ===================================================================
    
    /**
     * Memory Cache Operationen
     */
    private static function get_from_memory_cache(string $key) {
        if (!isset(self::$object_cache[$key])) {
            return null;
        }
        
        $item = self::$object_cache[$key];
        
        // TTL prüfen
        if (isset($item['expires']) && $item['expires'] < time()) {
            unset(self::$object_cache[$key]);
            return null;
        }
        
        // Access Time aktualisieren für LRU
        self::$object_cache[$key]['last_access'] = time();
        
        return $item['data'];
    }
    
    private static function set_memory_cache(string $key, $value, int $ttl = 3600): bool {
        $expires = $ttl > 0 ? time() + $ttl : 0;
        
        self::$object_cache[$key] = [
            'data' => $value,
            'expires' => $expires,
            'created' => time(),
            'last_access' => time(),
            'hit_count' => 0
        ];
        
        return true;
    }
    
    /**
     * WordPress Object Cache Integration
     */
    private static function get_from_object_cache(string $key) {
        return wp_cache_get($key, 'csv_import');
    }
    
    private static function set_object_cache(string $key, $value, int $ttl = 3600): bool {
        return wp_cache_set($key, $value, 'csv_import', $ttl);
    }
    
    /**
     * Cache Key Builder
     */
    private static function build_cache_key(string $namespace, string $key): string {
        return $namespace . ':' . md5($key);
    }
    
    // ===================================================================
    // PERFORMANCE TRACKING
    // ===================================================================
    
    /**
     * Verfolgt Cache Hits für Analytics
     */
    private static function track_cache_hit(string $namespace, string $key): void {
        if (!isset(self::$cache_hit_ratio[$namespace])) {
            self::$cache_hit_ratio[$namespace] = ['hits' => 0, 'total' => 0];
        }
        
        self::$cache_hit_ratio[$namespace]['hits']++;
        self::$cache_hit_ratio[$namespace]['total']++;
    }
    
    /**
     * Verfolgt Cache Misses für Analytics
     */
    private static function track_cache_miss(string $namespace, string $key): void {
        if (!isset(self::$cache_hit_ratio[$namespace])) {
            self::$cache_hit_ratio[$namespace] = ['hits' => 0, 'total' => 0];
        }
        
        self::$cache_hit_ratio[$namespace]['total']++;
    }
    
    /**
     * Loggt Cache Performance beim Shutdown
     */
    public static function log_cache_performance(): void {
        $total_requests = self::$stats['hits'] + self::$stats['misses'];
        
        if ($total_requests === 0) {
            return; // Keine Cache-Aktivität
        }
        
        $hit_rate = round((self::$stats['hits'] / $total_requests) * 100, 2);
        $memory_usage = self::get_current_cache_memory_usage();
        
        $performance = [
            'hit_rate' => $hit_rate,
            'total_requests' => $total_requests,
            'memory_usage' => size_format($memory_usage),
            'cache_efficiency' => $hit_rate > 60 ? 'good' : ($hit_rate > 30 ? 'medium' : 'poor'),
            'namespace_stats' => self::$cache_hit_ratio
        ];
        
        // Nur bei schlechter Performance oder Debug-Modus loggen
        if ($hit_rate < 50 || (defined('WP_DEBUG') && WP_DEBUG)) {
            csv_import_log('debug', 'Cache Performance Report', $performance);
        }
        
        // Performance-Metriken für Monitoring
        update_option('csv_import_cache_performance', $performance, false);
    }
    
    // ===================================================================
    // IMPORT-SPEZIFISCHE CACHE FUNKTIONEN
    // ===================================================================
    
    /**
     * Bereitet Cache für Import vor
     */
    public static function prepare_cache_for_import(): void {
        // Temporäre Cache-Vergrößerung für Import
        $original_limit = self::$max_memory_usage;
        self::$max_memory_usage = min($original_limit * 1.5, 536870912); // Max 512MB
        
        csv_import_log('debug', 'Cache für Import vorbereitet', [
            'original_limit' => size_format($original_limit),
            'import_limit' => size_format(self::$max_memory_usage)
        ]);
    }
    
    /**
     * Bereinigt Cache nach Import
     */
    public static function cleanup_import_cache(): void {
        // CSV-Daten Cache leeren (da nach Import nicht mehr benötigt)
        self::flush_namespace(self::CACHE_CSV_DATA);
        
        // Validierungen behalten aber Query Cache leeren
        self::flush_namespace(self::CACHE_QUERIES);
        
        // Cache-Limit zurücksetzen
        self::$max_memory_usage = self::calculate_optimal_cache_size();
        
        csv_import_log('debug', 'Cache nach Import bereinigt');
    }
    
    /**
     * Invalidiert spezifische Cache-Bereiche
     */
    public static function invalidate_config_cache(): void {
        self::flush_namespace(self::CACHE_CONFIG);
    }
    
    public static function invalidate_template_cache(int $template_id = null): void {
        if ($template_id) {
            self::delete(self::CACHE_TEMPLATES, "template_{$template_id}");
        } else {
            self::flush_namespace(self::CACHE_TEMPLATES);
        }
    }
    
    // ===================================================================
    // WARTUNG UND CLEANUP
    // ===================================================================
    
    /**
     * Tägliche Cache-Wartung
     */
    public static function daily_cache_maintenance(): void {
        $cleaned_items = 0;
        $freed_memory = 0;
        
        // Abgelaufene Einträge entfernen
        foreach (self::$object_cache as $key => $item) {
            if (isset($item['expires']) && $item['expires'] > 0 && $item['expires'] < time()) {
                $freed_memory += strlen(serialize($item));
                unset(self::$object_cache[$key]);
                $cleaned_items++;
            }
        }
        
        // Cache-Statistiken zurücksetzen
        self::$stats = [
            'hits' => 0,
            'misses' => 0,
            'sets' => 0,
            'evictions' => 0,
            'memory_usage' => self::get_current_cache_memory_usage()
        ];
        
        csv_import_log('info', 'Cache Wartung abgeschlossen', [
            'cleaned_items' => $cleaned_items,
            'freed_memory' => size_format($freed_memory),
            'current_usage' => size_format(self::$stats['memory_usage'])
        ]);
    }
    
    /**
     * WordPress Cache Integration Setup
     */
    public static function setup_wordpress_cache_integration(): void {
        // Gruppe für WordPress Object Cache registrieren
        wp_cache_add_global_groups(['csv_import']);
        
        // Cache-Hooks für automatische Invalidierung
        add_action('save_post', function($post_id) {
            // Template-Cache invalidieren wenn Template geändert wird
            $template_id = get_option('csv_import_template_id');
            if ($post_id == $template_id) {
                self::invalidate_template_cache($post_id);
            }
        });
        
        add_action('update_option', function($option_name, $old_value, $new_value) {
            // Config-Cache invalidieren bei Einstellungsänderungen
            if (strpos($option_name, 'csv_import_') === 0) {
                self::invalidate_config_cache();
            }
        }, 10, 3);
    }
    
    // ===================================================================
    // ÖFFENTLICHE UTILITY METHODEN
    // ===================================================================
    
    /**
     * Holt aktuelle Cache-Statistiken
     */
    public static function get_cache_stats(): array {
        $total_requests = self::$stats['hits'] + self::$stats['misses'];
        $hit_rate = $total_requests > 0 ? round((self::$stats['hits'] / $total_requests) * 100, 2) : 0;
        
        return [
            'hit_rate' => $hit_rate,
            'total_items' => count(self::$object_cache),
            'memory_usage' => self::get_current_cache_memory_usage(),
            'memory_limit' => self::$max_memory_usage,
            'memory_usage_percent' => round((self::get_current_cache_memory_usage() / self::$max_memory_usage) * 100, 2),
            'stats' => self::$stats,
            'namespace_stats' => self::$cache_hit_ratio
        ];
    }
    
    /**
     * Cache-Status für Admin-Interface
     */
    public static function get_cache_status(): array {
        $stats = self::get_cache_stats();
        
        return [
            'enabled' => true,
            'healthy' => $stats['hit_rate'] > 30,
            'performance' => $stats['hit_rate'] > 60 ? 'excellent' : ($stats['hit_rate'] > 30 ? 'good' : 'poor'),
            'memory_pressure' => $stats['memory_usage_percent'] > 80,
            'stats' => $stats
        ];
    }
    
    /**
     * Cache komplett leeren (für Debug/Wartung)
     */
    public static function flush_all_cache(): int {
        $cleared_items = count(self::$object_cache);
        
        self::$object_cache = [];
        self::$csv_cache = [];
        self::$query_cache = [];
        self::$validation_cache = [];
        
        // WordPress Object Cache auch leeren
        wp_cache_flush_group('csv_import');
        
        csv_import_log('info', 'Gesamter Cache geleert', [
            'cleared_items' => $cleared_items
        ]);
        
        return $cleared_items;
    }
}

// ===================================================================
// CACHE INTEGRATION FUNKTIONEN
// ===================================================================

/**
 * Integration mit bestehenden CSV Import Funktionen
 */

/**
 * Cached Version von csv_import_get_config()
 */
function csv_import_get_config_cached(): array {
    return CSV_Import_Memory_Cache::get_config();
}

/**
 * Cached Template Loader
 */
function csv_import_get_template_cached(int $template_id) {
    return CSV_Import_Memory_Cache::get_template($template_id);
}

/**
 * Cached CSV Validation
 */
function csv_import_validate_csv_cached(string $source, array $config): array {
    $config_hash = md5(serialize($config));
    
    $cached_validation = CSV_Import_Memory_Cache::get_csv_validation($source, $config_hash);
    
    if ($cached_validation !== null) {
        csv_import_log('debug', 'CSV Validation aus Cache geladen', [
            'source' => $source,
            'config_hash' => substr($config_hash, 0, 8)
        ]);
        return $cached_validation;
    }
    
    // Fallback zur originalen Funktion
    $validation = csv_import_validate_csv_source($source, $config);
    
    // Nur erfolgreiche Validierungen cachen
    if ($validation['valid']) {
        CSV_Import_Memory_Cache::set_csv_validation($source, $config_hash, $validation);
    }
    
    return $validation;
}

/**
 * Database Query Caching Wrapper
 */
function csv_import_cached_query(string $query, array $args = []): array {
    global $wpdb;
    
    $query_hash = md5($query . serialize($args));
    
    $cached_result = CSV_Import_Memory_Cache::get_query_result($query_hash);
    
    if ($cached_result !== null) {
        return $cached_result;
    }
    
    // Query ausführen
    if (!empty($args)) {
        $prepared_query = $wpdb->prepare($query, $args);
    } else {
        $prepared_query = $query;
    }
    
    $result = $wpdb->get_results($prepared_query, ARRAY_A);
    
    // Nur erfolgreiche Queries mit Ergebnissen cachen
    if (!empty($result)) {
        CSV_Import_Memory_Cache::set_query_result($query_hash, $result, 300); // 5 Min TTL
    }
    
    return $result;
}

// ===================================================================
// ADMIN INTEGRATION
// ===================================================================

/**
 * Admin-Interface für Cache Management
 */
class CSV_Import_Cache_Admin {
    
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_cache_admin_page']);
        add_action('wp_ajax_csv_cache_flush', [__CLASS__, 'ajax_flush_cache']);
        add_action('wp_ajax_csv_cache_stats', [__CLASS__, 'ajax_get_stats']);
    }
    
    public static function add_cache_admin_page() {
        add_submenu_page(
            'tools.php',
            'CSV Import Cache',
            'CSV Cache',
            'manage_options',
            'csv-import-cache',
            [__CLASS__, 'render_cache_page']
        );
    }
    
    public static function render_cache_page() {
        $cache_status = CSV_Import_Memory_Cache::get_cache_status();
        $stats = $cache_status['stats'];
        
        ?>
        <div class="wrap">
            <h1>🚀 CSV Import Memory Cache</h1>
            
            <div class="csv-cache-dashboard" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                
                <!-- Cache Status -->
                <div class="cache-status-box" style="background: white; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h3>📊 Cache Status</h3>
                    
                    <div style="margin: 15px 0;">
                        <strong>Speicher:</strong> <?php echo size_format($stats['memory_usage']); ?> von <?php echo size_format($stats['memory_limit']); ?>
                        <div style="background: #f1f1f1; height: 20px; border-radius: 3px; margin-top: 5px;">
                            <div style="background: <?php echo $stats['memory_usage_percent'] > 80 ? '#d63638' : '#2271b1'; ?>; height: 100%; width: <?php echo $stats['memory_usage_percent']; ?>%; border-radius: 3px;"></div>
                        </div>
                    </div>
                    
                    <div style="margin: 15px 0;">
                        <strong>Cache Items:</strong> <?php echo number_format($stats['total_items']); ?>
                    </div>
                </div>
                
                <!-- Cache Aktionen -->
                <div class="cache-actions-box" style="background: white; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h3>⚙️ Cache Verwaltung</h3>
                    
                    <div style="margin: 15px 0;">
                        <button type="button" class="button button-primary" onclick="csvCacheFlush()">
                            🗑️ Cache komplett leeren
                        </button>
                        <p class="description">Löscht alle gecachten Daten. Performance wird vorübergehend reduziert.</p>
                    </div>
                    
                    <div style="margin: 15px 0;">
                        <button type="button" class="button button-secondary" onclick="csvCacheRefreshStats()">
                            📊 Statistiken aktualisieren
                        </button>
                    </div>
                    
                    <div id="cache-action-result" style="margin-top: 15px;"></div>
                </div>
                
                <!-- Detaillierte Statistiken -->
                <div class="cache-details-box" style="grid-column: 1 / -1; background: white; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h3>📈 Detaillierte Cache-Statistiken</h3>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 15px;">
                        
                        <div>
                            <h4>Zugriffe</h4>
                            <ul style="list-style: none; padding: 0;">
                                <li><strong>Hits:</strong> <?php echo number_format($stats['stats']['hits']); ?></li>
                                <li><strong>Misses:</strong> <?php echo number_format($stats['stats']['misses']); ?></li>
                                <li><strong>Sets:</strong> <?php echo number_format($stats['stats']['sets']); ?></li>
                                <li><strong>Evictions:</strong> <?php echo number_format($stats['stats']['evictions']); ?></li>
                            </ul>
                        </div>
                        
                        <div>
                            <h4>Namespace Performance</h4>
                            <?php foreach ($stats['namespace_stats'] as $namespace => $ns_stats): ?>
                                <?php $ns_hit_rate = $ns_stats['total'] > 0 ? round(($ns_stats['hits'] / $ns_stats['total']) * 100, 1) : 0; ?>
                                <div style="margin: 5px 0;">
                                    <strong><?php echo esc_html($namespace); ?>:</strong> <?php echo $ns_hit_rate; ?>%
                                    <div style="background: #f1f1f1; height: 10px; border-radius: 3px; margin-top: 2px;">
                                        <div style="background: #00a32a; height: 100%; width: <?php echo $ns_hit_rate; ?>%; border-radius: 3px;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div>
                            <h4>System Info</h4>
                            <ul style="list-style: none; padding: 0;">
                                <li><strong>PHP Memory:</strong> <?php echo ini_get('memory_limit'); ?></li>
                                <li><strong>WP Object Cache:</strong> <?php echo wp_using_ext_object_cache() ? 'Aktiv' : 'Standard'; ?></li>
                                <li><strong>Cache Healthy:</strong> <?php echo $cache_status['healthy'] ? '✅ Ja' : '❌ Nein'; ?></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            
            <script>
            function csvCacheFlush() {
                if (!confirm('Cache wirklich komplett leeren? Dies kann die Performance vorübergehend beeinträchtigen.')) {
                    return;
                }
                
                jQuery.post(ajaxurl, {
                    action: 'csv_cache_flush',
                    nonce: '<?php echo wp_create_nonce('csv_cache_nonce'); ?>'
                }, function(response) {
                    const resultDiv = document.getElementById('cache-action-result');
                    if (response.success) {
                        resultDiv.innerHTML = '<div class="notice notice-success"><p>✅ Cache erfolgreich geleert: ' + response.data.cleared_items + ' Einträge entfernt</p></div>';
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        resultDiv.innerHTML = '<div class="notice notice-error"><p>❌ Fehler: ' + response.data.message + '</p></div>';
                    }
                });
            }
            
            function csvCacheRefreshStats() {
                jQuery.post(ajaxurl, {
                    action: 'csv_cache_stats',
                    nonce: '<?php echo wp_create_nonce('csv_cache_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    }
                });
            }
            </script>
        </div>
        <?php
    }
    
    public static function ajax_flush_cache() {
        check_ajax_referer('csv_cache_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Keine Berechtigung']);
        }
        
        $cleared_items = CSV_Import_Memory_Cache::flush_all_cache();
        
        wp_send_json_success([
            'cleared_items' => $cleared_items,
            'message' => 'Cache erfolgreich geleert'
        ]);
    }
    
    public static function ajax_get_stats() {
        check_ajax_referer('csv_cache_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Keine Berechtigung']);
        }
        
        $stats = CSV_Import_Memory_Cache::get_cache_stats();
        wp_send_json_success($stats);
    }
}

// ===================================================================
// CACHE-ENHANCED IMPORT FUNCTIONS
// ===================================================================

/**
 * Cache-optimierte Version der wichtigsten Import-Funktionen
 */

/**
 * Cached Post Meta Loader
 */
function csv_import_get_post_meta_cached(int $post_id, string $meta_key = '', bool $single = false) {
    $cache_key = "post_meta_{$post_id}_{$meta_key}_" . ($single ? '1' : '0');
    
    $cached_meta = CSV_Import_Memory_Cache::get(CSV_Import_Memory_Cache::CACHE_META, $cache_key);
    
    if ($cached_meta !== null) {
        return $cached_meta;
    }
    
    $meta_value = get_post_meta($post_id, $meta_key, $single);
    
    // Cache für 30 Minuten
    CSV_Import_Memory_Cache::set(CSV_Import_Memory_Cache::CACHE_META, $cache_key, $meta_value, 1800);
    
    return $meta_value;
}

/**
 * Cached Duplicate Check
 */
function csv_import_check_duplicate_cached(string $post_title, string $post_type): ?int {
    $cache_key = "duplicate_check_" . md5($post_title . $post_type);
    
    $cached_result = CSV_Import_Memory_Cache::get(CSV_Import_Memory_Cache::CACHE_QUERIES, $cache_key);
    
    if ($cached_result !== null) {
        return $cached_result ?: null;
    }
    
    $existing_post = get_page_by_title($post_title, OBJECT, $post_type);
    $post_id = $existing_post ? $existing_post->ID : 0;
    
    // Cache für 5 Minuten (Duplikate ändern sich während Import nicht)
    CSV_Import_Memory_Cache::set(CSV_Import_Memory_Cache::CACHE_QUERIES, $cache_key, $post_id, 300);
    
    return $post_id ?: null;
}

/**
 * Cached Slug Generator
 */
function csv_import_generate_unique_slug_cached(string $title, string $post_type = 'post'): string {
    static $used_slugs = [];
    
    $base_slug = sanitize_title($title);
    
    if (empty($base_slug)) {
        $base_slug = 'csv-import-post-' . uniqid();
    }
    
    $cache_key = "slug_exists_{$base_slug}_{$post_type}";
    
    // Prüfe zuerst lokale Session-Cache für bereits verwendete Slugs
    if (isset($used_slugs[$base_slug])) {
        $counter = $used_slugs[$base_slug] + 1;
        $used_slugs[$base_slug] = $counter;
        return $base_slug . '-' . $counter;
    }
    
    // Prüfe Cache ob Slug bereits existiert
    $slug_exists = CSV_Import_Memory_Cache::get(CSV_Import_Memory_Cache::CACHE_QUERIES, $cache_key);
    
    if ($slug_exists === null) {
        $existing_post = get_page_by_path($base_slug, OBJECT, $post_type);
        $slug_exists = $existing_post ? true : false;
        
        // Cache für 10 Minuten
        CSV_Import_Memory_Cache::set(CSV_Import_Memory_Cache::CACHE_QUERIES, $cache_key, $slug_exists, 600);
    }
    
    if (!$slug_exists) {
        $used_slugs[$base_slug] = 0;
        return $base_slug;
    }
    
    // Slug existiert bereits, finde alternative
    $counter = 1;
    $unique_slug = $base_slug;
    
    do {
        $unique_slug = $base_slug . '-' . $counter;
        $unique_cache_key = "slug_exists_{$unique_slug}_{$post_type}";
        
        $unique_exists = CSV_Import_Memory_Cache::get(CSV_Import_Memory_Cache::CACHE_QUERIES, $unique_cache_key);
        
        if ($unique_exists === null) {
            $existing_post = get_page_by_path($unique_slug, OBJECT, $post_type);
            $unique_exists = $existing_post ? true : false;
            
            CSV_Import_Memory_Cache::set(CSV_Import_Memory_Cache::CACHE_QUERIES, $unique_cache_key, $unique_exists, 600);
        }
        
        if (!$unique_exists) {
            break;
        }
        
        $counter++;
        
    } while ($counter <= 100); // Sicherheits-Limit
    
    $used_slugs[$base_slug] = $counter;
    return $unique_slug;
}

/**
 * Cached Image Download Status
 */
function csv_import_get_image_download_status_cached(string $image_url): array {
    $cache_key = "image_status_" . md5($image_url);
    
    $cached_status = CSV_Import_Memory_Cache::get(CSV_Import_Memory_Cache::CACHE_QUERIES, $cache_key);
    
    if ($cached_status !== null) {
        return $cached_status;
    }
    
    // Basis-Validierung der URL
    if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
        $status = ['valid' => false, 'error' => 'Invalid URL'];
        CSV_Import_Memory_Cache::set(CSV_Import_Memory_Cache::CACHE_QUERIES, $cache_key, $status, 3600);
        return $status;
    }
    
    // HEAD-Request für schnelle Validierung
    $response = wp_remote_head($image_url, [
        'timeout' => 10,
        'redirection' => 3
    ]);
    
    if (is_wp_error($response)) {
        $status = ['valid' => false, 'error' => $response->get_error_message()];
    } else {
        $http_code = wp_remote_retrieve_response_code($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        
        $status = [
            'valid' => $http_code === 200,
            'http_code' => $http_code,
            'content_type' => $content_type,
            'is_image' => strpos($content_type, 'image/') === 0
        ];
    }
    
    // Cache für 1 Stunde (Bilder-URLs ändern sich selten)
    CSV_Import_Memory_Cache::set(CSV_Import_Memory_Cache::CACHE_QUERIES, $cache_key, $status, 3600);
    
    return $status;
}

// ===================================================================
// CACHE INTEGRATION HOOKS
// ===================================================================

/**
 * Integration in bestehende CSV Import Workflows
 */
add_action('csv_import_before_row_processing', function($row_index, $total_rows) {
    // Cache Warming für Template bei jedem 10. Post
    if ($row_index % 10 === 0) {
        $template_id = get_option('csv_import_template_id');
        if ($template_id) {
            CSV_Import_Memory_Cache::get_template($template_id);
        }
    }
}, 10, 2);

add_action('csv_import_after_post_created', function($post_id, $session_id, $source) {
    // Duplicate-Check Cache für erstellten Post invalidieren
    $post = get_post($post_id);
    if ($post) {
        $cache_key = "duplicate_check_" . md5($post->post_title . $post->post_type);
        CSV_Import_Memory_Cache::delete(CSV_Import_Memory_Cache::CACHE_QUERIES, $cache_key);
    }
}, 10, 3);

add_action('wp_ajax_csv_import_validate', function() {
    // Cache Validation Results
    $type = sanitize_key($_POST['type'] ?? '');
    if (in_array($type, ['dropbox', 'local'])) {
        $config = csv_import_get_config();
        
        // Verwende cached Validation falls verfügbar
        $validation = csv_import_validate_csv_cached($type, $config);
        
        if ($validation['valid']) {
            wp_send_json_success($validation);
        } else {
            wp_send_json_error($validation);
        }
        return;
    }
});

// ===================================================================
// PERFORMANCE OPTIMIERUNGEN
// ===================================================================

/**
 * Cache-Warming Funktionen für bessere Performance
 */
class CSV_Import_Cache_Warmer {
    
    /**
     * Wärmt wichtige Cache-Bereiche vor Import vor
     */
    public static function warm_cache_for_import(array $config): void {
        csv_import_log('debug', 'Cache Warming gestartet');
        
        // Template vorladen
        if (!empty($config['template_id'])) {
            CSV_Import_Memory_Cache::get_template($config['template_id']);
        }
        
        // Konfiguration cachen
        CSV_Import_Memory_Cache::get_config();
        
        // System Health für Monitoring
        $health = csv_import_system_health_check();
        CSV_Import_Memory_Cache::set(CSV_Import_Memory_Cache::CACHE_STATS, 'system_health', $health, 300);
        
        csv_import_log('debug', 'Cache Warming abgeschlossen');
    }
    
    /**
     * Lädt häufig verwendete Post-Meta-Felder vor
     */
    public static function preload_common_meta(array $post_ids): void {
        $common_meta_keys = [
            '_yoast_wpseo_title',
            '_yoast_wpseo_metadesc', 
            'rank_math_title',
            'rank_math_description',
            '_elementor_data',
            '_wp_page_template'
        ];
        
        foreach ($post_ids as $post_id) {
            foreach ($common_meta_keys as $meta_key) {
                csv_import_get_post_meta_cached($post_id, $meta_key, true);
            }
        }
    }
}

// ===================================================================
// CACHE STATISTIKEN & MONITORING
// ===================================================================

/**
 * Cache Performance Dashboard Widget
 */
add_action('wp_dashboard_setup', function() {
    if (current_user_can('manage_options')) {
        wp_add_dashboard_widget(
            'csv_import_cache_performance',
            '🚀 CSV Import Cache Performance',
            function() {
                $cache_status = CSV_Import_Memory_Cache::get_cache_status();
                $stats = $cache_status['stats'];
                
                echo '<div style="display: flex; gap: 15px; align-items: center;">';
                
                // Performance Badge
                $badge_color = $cache_status['performance'] === 'excellent' ? '#00a32a' : 
                              ($cache_status['performance'] === 'good' ? '#f56e28' : '#d63638');
                              
                echo '<div style="background: ' . $badge_color . '; color: white; padding: 10px; border-radius: 4px; text-align: center; min-width: 100px;">';
                echo '<div style="font-size: 18px; font-weight: bold;">' . $stats['hit_rate'] . '%</div>';
                echo '<div style="font-size: 12px;">Hit Rate</div>';
                echo '</div>';
                
                echo '<div style="flex: 1;">';
                echo '<div><strong>Cache Items:</strong> ' . number_format($stats['total_items']) . '</div>';
                echo '<div><strong>Memory:</strong> ' . size_format($stats['memory_usage']) . ' / ' . size_format($stats['memory_limit']) . '</div>';
                echo '<div><strong>Status:</strong> <span style="color: ' . ($cache_status['healthy'] ? 'green' : 'red') . '">' . 
                     ($cache_status['healthy'] ? 'Gesund' : 'Probleme') . '</span></div>';
                echo '</div>';
                
                echo '</div>';
                
                if ($stats['memory_usage_percent'] > 80) {
                    echo '<div style="margin-top: 10px; padding: 8px; background: #fcf0f1; border-left: 4px solid #d63638; font-size: 12px;">';
                    echo '⚠️ <strong>Hoher Speicherverbrauch:</strong> Cache-Bereinigung empfohlen.';
                    echo '</div>';
                }
                
                echo '<div style="margin-top: 10px; text-align: center;">';
                echo '<a href="' . admin_url('tools.php?page=csv-import-cache') . '" class="button button-small">Cache verwalten</a>';
                echo '</div>';
            }
        );
    }
});

// ===================================================================
// CACHE INITIALIZATION
// ===================================================================

// Cache System initialisieren
add_action('plugins_loaded', function() {
    CSV_Import_Memory_Cache::init();
    CSV_Import_Cache_Admin::init();
    
    csv_import_log('info', 'CSV Import Memory Cache System geladen');
}, 15);

// Cache für neue Imports vorbereiten
add_action('csv_import_start', function() {
    $config = csv_import_get_config();
    CSV_Import_Cache_Warmer::warm_cache_for_import($config);
});

// Cache nach Import optimieren
add_action('csv_import_completed', function($result, $source) {
    CSV_Import_Memory_Cache::cleanup_import_cache();
}, 10, 2);

// Emergency Cache Flush bei Memory-Problemen
add_action('csv_import_memory_warning', function() {
    $cache_stats = CSV_Import_Memory_Cache::get_cache_stats();
    
    if ($cache_stats['memory_usage_percent'] > 90) {
        CSV_Import_Memory_Cache::flush_namespace(CSV_Import_Memory_Cache::CACHE_CSV_DATA);
        CSV_Import_Memory_Cache::flush_namespace(CSV_Import_Memory_Cache::CACHE_QUERIES);
        
        csv_import_log('warning', 'Emergency Cache Flush wegen hohem Speicherverbrauch', [
            'memory_usage_percent' => $cache_stats['memory_usage_percent']
        ]);
    }
});

// ===================================================================
// FINALE CACHE UTILITIES
// ===================================================================

/**
 * Hilfsfunktionen für einfache Cache-Nutzung
 */

/**
 * Einfacher Cache-Get mit Callback
 */
function csv_import_cache_remember(string $namespace, string $key, callable $callback, int $ttl = 3600) {
    $value = CSV_Import_Memory_Cache::get($namespace, $key);
    
    if ($value === null) {
        $value = $callback();
        CSV_Import_Memory_Cache::set($namespace, $key, $value, $ttl);
    }
    
    return $value;
}

/**
 * Batch Cache Operations für bessere Performance
 */
function csv_import_cache_get_multiple(string $namespace, array $keys): array {
    $results = [];
    
    foreach ($keys as $key) {
        $results[$key] = CSV_Import_Memory_Cache::get($namespace, $key);
    }
    
    return $results;
}

function csv_import_cache_set_multiple(string $namespace, array $items, int $ttl = 3600): bool {
    $success = true;
    
    foreach ($items as $key => $value) {
        if (!CSV_Import_Memory_Cache::set($namespace, $key, $value, $ttl)) {
            $success = false;
        }
    }
    
    return $success;
}

/**
* Status 
 */
function csv_import_initialize_cache_with_test_data() {
    if (!class_exists('CSV_Import_Memory_Cache')) {
        return;
    }
    
    // Generiere einige Cache-Hits
    CSV_Import_Memory_Cache::get_config();
    CSV_Import_Memory_Cache::get_config(); // Zweiter Aufruf = Hit!
}
add_action('admin_init', 'csv_import_initialize_cache_with_test_data');

/**
 * Cache Tag System für gruppenweise Invalidierung
 */
function csv_import_cache_tag(string $tag, string $namespace, string $key): void {
    $tagged_items = CSV_Import_Memory_Cache::get(CSV_Import_Memory_Cache::CACHE_META, "tags_{$tag}", []);
    $tagged_items[] = $namespace . ':' . $key;
    
    CSV_Import_Memory_Cache::set(CSV_Import_Memory_Cache::CACHE_META, "tags_{$tag}", array_unique($tagged_items), 7200);
}

function csv_import_cache_invalidate_tag(string $tag): int {
    $tagged_items = CSV_Import_Memory_Cache::get(CSV_Import_Memory_Cache::CACHE_META, "tags_{$tag}", []);
    $invalidated = 0;
    
    foreach ($tagged_items as $cache_key) {
        if (strpos($cache_key, ':') !== false) {
            list($namespace, $key) = explode(':', $cache_key, 2);
            if (CSV_Import_Memory_Cache::delete($namespace, $key)) {
                $invalidated++;
            }
        }
    }
    
    // Tag-Liste löschen
    CSV_Import_Memory_Cache::delete(CSV_Import_Memory_Cache::CACHE_META, "tags_{$tag}");
    
    return $invalidated;
}

csv_import_log('debug', 'CSV Import Memory Cache System vollständig geladen - Ready for High Performance!');

?>
                        <strong>Performance:</strong> 
                        <span style="color: <?php echo $cache_status['performance'] === 'excellent' ? 'green' : ($cache_status['performance'] === 'good' ? 'orange' : 'red'); ?>">
                            <?php echo ucfirst($cache_status['performance']); ?>
                        </span>
                    </div>
                    
                    <div style="margin: 15px 0;">
                        <strong>Hit Rate:</strong> <?php echo $stats['hit_rate']; ?>%
                        <div style="background: #f1f1f1; height: 20px; border-radius: 3px; margin-top: 5px;">
                            <div style="background: linear-gradient(90deg, #00a32a, #00ba37); height: 100%; width: <?php echo $stats['hit_rate']; ?>%; border-radius: 3px;"></div>
                        </div>
                    </div>
                    
                    <div style="margin: 15px 0;">

<?php
// CSS Fix für Dashboard Widget - Korrigierte Version
add_action('admin_head', function() {
    if (!function_exists('get_current_screen') || get_current_screen()->id !== 'dashboard') {
        return;
    }
    ?>
    <style type="text/css">
    #csv_import_cache_performance {
        position: relative !important;
        top: auto !important;
        left: auto !important;
        z-index: auto !important;
        transform: none !important;
    }
    #csv_import_cache_performance .inside {
        background: transparent !important;
        border: none !important;
        margin: 0 !important;
        padding: 12px !important;
    }
    
    /* Zusätzliche Styling-Verbesserungen für das Cache Dashboard Widget */
    #csv_import_cache_performance .cache-performance-grid {
        display: flex;
        gap: 15px;
        align-items: center;
        margin-bottom: 15px;
    }
    
    #csv_import_cache_performance .performance-badge {
        background: var(--badge-color, #00a32a);
        color: white;
        padding: 10px;
        border-radius: 4px;
        text-align: center;
        min-width: 100px;
        font-weight: bold;
    }
    
    #csv_import_cache_performance .cache-metrics {
        flex: 1;
        font-size: 13px;
        line-height: 1.4;
    }
    
    #csv_import_cache_performance .cache-warning {
        margin-top: 10px;
        padding: 8px;
        background: #fcf0f1;
        border-left: 4px solid #d63638;
        font-size: 12px;
        border-radius: 0 4px 4px 0;
    }
    
    #csv_import_cache_performance .cache-actions {
        margin-top: 10px;
        text-align: center;
    }
    
    /* Progress Bar Styling */
    #csv_import_cache_performance .progress-bar {
        background: #f1f1f1;
        height: 8px;
        border-radius: 4px;
        overflow: hidden;
        margin-top: 4px;
    }
    
    #csv_import_cache_performance .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #00a32a, #00ba37);
        border-radius: 4px;
        transition: width 0.3s ease;
    }
    
    /* Responsive Design für kleinere Dashboards */
    @media (max-width: 782px) {
        #csv_import_cache_performance .cache-performance-grid {
            flex-direction: column;
            gap: 10px;
        }
        
        #csv_import_cache_performance .performance-badge {
            min-width: auto;
            width: 100%;
        }
    }
    </style>
    <?php
});

<?php
// Kompletter Fix für Dashboard Widget Styling-Probleme
add_action('admin_head', function() {
    if (!function_exists('get_current_screen') || get_current_screen()->id !== 'dashboard') {
        return;
    }
    ?>
    <style type="text/css">
    /* Entferne alle störenden Styling-Elemente vom Cache Dashboard Widget */
    #csv_import_cache_performance {
        position: relative !important;
        top: auto !important;
        left: auto !important;
        z-index: auto !important;
        transform: none !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        border: 1px solid #c3c4c7 !important;
        box-shadow: 0 1px 1px rgba(0,0,0,.04) !important;
    }
    
    /* Widget Header - entferne grünen Balken und störende Elemente */
    #csv_import_cache_performance .hndle {
        background: #fff !important;
        border-bottom: 1px solid #c3c4c7 !important;
        color: #1d2327 !important;
        padding: 12px !important;
        margin: 0 !important;
        position: relative !important;
    }
    
    /* Entferne alle vor/nach Pseudo-Elemente die grüne Balken verursachen könnten */
    #csv_import_cache_performance::before,
    #csv_import_cache_performance::after,
    #csv_import_cache_performance .hndle::before,
    #csv_import_cache_performance .hndle::after,
    #csv_import_cache_performance .inside::before,
    #csv_import_cache_performance .inside::after {
        display: none !important;
        content: none !important;
        background: none !important;
        border: none !important;
        height: 0 !important;
        width: 0 !important;
    }
    
    /* Widget Content Area */
    #csv_import_cache_performance .inside {
        background: #fff !important;
        border: none !important;
        margin: 0 !important;
        padding: 12px !important;
        position: relative !important;
    }
    
    /* Entferne mögliche grüne Hintergründe von Parent-Elementen */
    #csv_import_cache_performance,
    #csv_import_cache_performance * {
        background-color: transparent !important;
    }
    
    /* Setze explizit weißen Hintergrund für das Widget selbst */
    #csv_import_cache_performance {
        background-color: #fff !important;
    }
    
    #csv_import_cache_performance .inside {
        background-color: #fff !important;
    }
    
    /* Cache Performance Grid */
    #csv_import_cache_performance .cache-performance-grid {
        display: flex;
        gap: 15px;
        align-items: center;
        margin: 0 0 15px 0;
        background: transparent !important;
    }
    
    /* Performance Badge */
    #csv_import_cache_performance .performance-badge {
        background: var(--badge-color, #00a32a) !important;
        color: white !important;
        padding: 10px !important;
        border-radius: 4px !important;
        text-align: center !important;
        min-width: 100px !important;
        font-weight: bold !important;
        border: none !important;
        box-shadow: none !important;
    }
    
    /* Cache Metrics */
    #csv_import_cache_performance .cache-metrics {
        flex: 1;
        font-size: 13px;
        line-height: 1.4;
        background: transparent !important;
    }
    
    /* Cache Warning */
    #csv_import_cache_performance .cache-warning {
        margin: 10px 0;
        padding: 8px;
        background: #fcf0f1 !important;
        border-left: 4px solid #d63638 !important;
        border-top: none !important;
        border-right: none !important;
        border-bottom: none !important;
        font-size: 12px;
        border-radius: 0 4px 4px 0;
    }
    
    /* Cache Actions */
    #csv_import_cache_performance .cache-actions {
        margin: 10px 0 0 0;
        text-align: center;
        background: transparent !important;
    }
    
    /* Progress Bar */
    #csv_import_cache_performance .progress-bar {
        background: #f1f1f1 !important;
        height: 8px !important;
        border-radius: 4px !important;
        overflow: hidden !important;
        margin-top: 4px !important;
        border: none !important;
    }
    
    #csv_import_cache_performance .progress-fill {
        height: 100% !important;
        background: linear-gradient(90deg, #00a32a, #00ba37) !important;
        border-radius: 4px !important;
        transition: width 0.3s ease !important;
        border: none !important;
    }
    
    /* Entferne mögliche grüne Themes von Parent-Containern */
    #normal-sortables #csv_import_cache_performance,
    #side-sortables #csv_import_cache_performance,
    .postbox-container #csv_import_cache_performance {
        background: #fff !important;
        border: 1px solid #c3c4c7 !important;
    }
    
    /* Spezifische Fixes für mögliche Theme-Konflikte */
    .wp-admin #csv_import_cache_performance {
        background: #fff !important;
    }
    
    /* Responsive Design */
    @media (max-width: 782px) {
        #csv_import_cache_performance .cache-performance-grid {
            flex-direction: column;
            gap: 10px;
        }
        
        #csv_import_cache_performance .performance-badge {
            min-width: auto;
            width: 100%;
        }
    }
    
    /* Debug: Temporär alle grünen Hintergründe entfernen */
    #csv_import_cache_performance,
    #csv_import_cache_performance *,
    #csv_import_cache_performance *::before,
    #csv_import_cache_performance *::after {
        background-color: transparent !important;
        background-image: none !important;
        background: transparent !important;
    }
    
    /* Dann explizit die gewünschten Hintergründe setzen */
    #csv_import_cache_performance {
        background: #fff !important;
    }
    
    #csv_import_cache_performance .inside {
        background: #fff !important;
    }
    
    #csv_import_cache_performance .performance-badge {
        background: var(--badge-color, #00a32a) !important;
    }
    
    #csv_import_cache_performance .cache-warning {
        background: #fcf0f1 !important;
    }
    
    #csv_import_cache_performance .progress-bar {
        background: #f1f1f1 !important;
    }
    
    #csv_import_cache_performance .progress-fill {
        background: linear-gradient(90deg, #00a32a, #00ba37) !important;
    }
    </style>
    <?php
});

// JavaScript-Fix für dynamische Styling-Probleme
add_action('admin_footer', function() {
    if (!function_exists('get_current_screen') || get_current_screen()->id !== 'dashboard') {
        return;
    }
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Entferne alle grünen Styling-Probleme
        function fixCacheWidgetStyling() {
            var $widget = $('#csv_import_cache_performance');
            
            if ($widget.length) {
                // Entferne problematische CSS-Klassen
                $widget.removeClass().addClass('postbox');
                
                // Entferne störende Inline-Styles
                $widget.css({
                    'position': 'static',
                    'transform': 'none',
                    'top': 'auto',
                    'left': 'auto',
                    'background': '#fff',
                    'background-color': '#fff',
                    'background-image': 'none',
                    'margin': '0',
                    'padding': '0',
                    'border': '1px solid #c3c4c7',
                    'border-radius': '0'
                });
                
                // Prüfe auf Parent-Container mit grünem Hintergrund
                $widget.parents().each(function() {
                    var $parent = $(this);
                    var bgColor = $parent.css('background-color');
                    
                    // Entferne grüne Hintergründe von Parents
                    if (bgColor && (bgColor.includes('rgb(0, 163, 42)') || bgColor.includes('#00a32a') || bgColor.includes('green'))) {
                        $parent.css('background', 'transparent');
                    }
                });
                
                // Stelle sicher, dass der Widget-Header korrekt ist
                $widget.find('.hndle').css({
                    'background': '#fff',
                    'background-color': '#fff',
                    'border-bottom': '1px solid #c3c4c7',
                    'color': '#1d2327'
                });
                
                // Stelle sicher, dass der Content-Bereich korrekt ist
                $widget.find('.inside').css({
                    'background': '#fff',
                    'background-color': '#fff',
                    'padding': '12px'
                });
            }
        }
        
        // Sofort ausführen
        fixCacheWidgetStyling();
        
        // Nach 100ms nochmal für den Fall dass andere Scripts interferieren
        setTimeout(fixCacheWidgetStyling, 100);
        
        // Bei AJAX-Reloads des Dashboards
        $(document).on('ajaxComplete', function() {
            setTimeout(fixCacheWidgetStyling, 50);
        });
    });
    </script>
    <?php
});

// Alternative Widget-Registrierung mit cleanem HTML
add_action('wp_dashboard_setup', function() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Entferne das alte Widget falls vorhanden
    remove_meta_box('csv_import_cache_performance', 'dashboard', 'normal');
    remove_meta_box('csv_import_cache_performance', 'dashboard', 'side');
    
    // Registriere Widget neu mit sauberem Callback
    wp_add_dashboard_widget(
        'csv_import_cache_performance',
        'CSV Import Cache Performance', // Entferne Emoji aus dem Titel
        function() {
            // Saubere HTML-Ausgabe ohne störende Elemente
            echo '<div style="background: #fff; margin: 0; padding: 0;">';
            csv_import_render_clean_cache_widget();
            echo '</div>';
        }
    );
});

// Saubere Widget-Darstellung ohne störende Elemente
function csv_import_render_clean_cache_widget() {
    if (!class_exists('CSV_Import_Memory_Cache')) {
        echo '<p>Cache System nicht verfügbar.</p>';
        return;
    }
    
    try {
        $cache_status = CSV_Import_Memory_Cache::get_cache_status();
        $stats = $cache_status['stats'];
        
        // Performance Badge Farbe
        $badge_color = match($cache_status['performance']) {
            'excellent' => '#00a32a',
            'good' => '#f56e28',
            default => '#d63638'
        };
        
        ?>
        <div class="cache-performance-grid">
            <div class="performance-badge" style="background: <?php echo esc_attr($badge_color); ?> !important; color: white; padding: 10px; border-radius: 4px; text-align: center; min-width: 100px; font-weight: bold;">
                <div style="font-size: 18px;"><?php echo esc_html($stats['hit_rate']); ?>%</div>
                <div style="font-size: 12px;">Hit Rate</div>
            </div>
            
            <div class="cache-metrics" style="flex: 1; font-size: 13px; line-height: 1.4;">
                <div><strong>Cache Items:</strong> <?php echo esc_html(number_format($stats['total_items'])); ?></div>
                <div><strong>Memory:</strong> <?php echo esc_html(size_format($stats['memory_usage'])); ?> / <?php echo esc_html(size_format($stats['memory_limit'])); ?></div>
                <div>
                    <strong>Status:</strong> 
                    <span style="color: <?php echo $cache_status['healthy'] ? 'green' : 'red'; ?>;">
                        <?php echo $cache_status['healthy'] ? 'Gesund' : 'Probleme'; ?>
                    </span>
                </div>
                
                <div style="margin-top: 8px;">
                    <small>Memory Usage: <?php echo esc_html($stats['memory_usage_percent']); ?>%</small>
                    <div class="progress-bar" style="background: #f1f1f1; height: 8px; border-radius: 4px; margin-top: 4px;">
                        <div class="progress-fill" style="height: 100%; background: linear-gradient(90deg, #00a32a, #00ba37); border-radius: 4px; width: <?php echo esc_attr($stats['memory_usage_percent']); ?>%;"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($stats['memory_usage_percent'] > 80): ?>
        <div class="cache-warning" style="margin: 10px 0; padding: 8px; background: #fcf0f1; border-left: 4px solid #d63638; font-size: 12px; border-radius: 0 4px 4px 0;">
            <strong>Hoher Speicherverbrauch:</strong> Cache-Bereinigung empfohlen.
        </div>
        <?php endif; ?>
        
        <div class="cache-actions" style="margin: 10px 0 0 0; text-align: center;">
            <a href="<?php echo esc_url(admin_url('tools.php?page=csv-import-cache')); ?>" class="button button-small">
                Cache verwalten
            </a>
        </div>
        <?php
        
    } catch (Exception $e) {
        echo '<div style="margin: 10px 0; padding: 8px; background: #fcf0f1; border-left: 4px solid #d63638; font-size: 12px;">';
        echo '<strong>Cache Fehler:</strong> ' . esc_html($e->getMessage());
        echo '</div>';
    }
}

// Debug-Funktion um CSS-Konflikte zu identifizieren
add_action('admin_footer', function() {
    if (defined('WP_DEBUG') && WP_DEBUG && get_current_screen()->id === 'dashboard') {
        ?>
        <script>
        // Debug: Finde CSS-Regeln die grüne Hintergründe setzen
        jQuery(document).ready(function($) {
            var $widget = $('#csv_import_cache_performance');
            if ($widget.length) {
                console.log('CSV Cache Widget Debug Info:');
                console.log('Widget Background:', $widget.css('background-color'));
                console.log('Widget Classes:', $widget.attr('class'));
                console.log('Parent Backgrounds:', $widget.parents().map(function() { 
                    return $(this).css('background-color'); 
                }).get());
            }
        });
        </script>
        <?php
    }
});
?>
