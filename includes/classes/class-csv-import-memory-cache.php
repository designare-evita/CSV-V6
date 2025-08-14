<?php
/**
 * CSV Import Pro Memory Cache System
 * Version: 1.1 - Memory Safe Edition
 * 
 * Implementiert ein MEMORY-SICHERES Cache System für das CSV Import Pro Plugin
 * - Mehrfach-Loading-Schutz
 * - Aggressive Memory Management
 * - Emergency Stop Mechanismen
 * - Reduzierte Cache-Limits für Stabilität
 */

if (!defined('ABSPATH')) {
    exit;
}

// Verhindere Mehrfach-Loading - KRITISCH!
if (defined('CSV_IMPORT_MEMORY_CACHE_LOADED')) {
    return;
}
define('CSV_IMPORT_MEMORY_CACHE_LOADED', true);

// Memory Check vor Initialisierung
$current_memory = memory_get_usage(true);
$memory_limit_bytes = function_exists('wp_convert_hr_to_bytes') ? 
                     wp_convert_hr_to_bytes(ini_get('memory_limit')) : 
                     (int)ini_get('memory_limit') * 1024 * 1024;

if ($current_memory > ($memory_limit_bytes * 0.8)) { // 80% des Limits
    error_log('[CSV Import Cache] Memory zu hoch (' . size_format($current_memory) . '), Cache-System nicht geladen');
    return;
}

/**
 * Memory-sichere Hauptklasse für das Cache System
 */
class CSV_Import_Memory_Cache {
    
    // Cache Namespaces
    const CACHE_CONFIG = 'csv_config';
    const CACHE_CSV_DATA = 'csv_data';
    const CACHE_TEMPLATES = 'csv_templates';
    const CACHE_META = 'csv_meta';
    const CACHE_QUERIES = 'csv_queries';
    const CACHE_VALIDATION = 'csv_validation';
    const CACHE_STATS = 'csv_stats';
    
    // Memory Management - REDUZIERTE LIMITS
    private static $max_memory_usage = 16777216; // 16MB statt 128MB
    private static $emergency_mode = false;
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
        'memory_usage' => 0,
        'emergency_stops' => 0
    ];
    
    /**
     * Initialisiert das Cache System - Memory Safe
     */
    public static function init() {
        // Prüfe ob bereits im Emergency Mode
        if (self::$emergency_mode) {
            return;
        }
        
        // Memory Check vor jeder Initialisierung
        if (!self::check_memory_before_operation()) {
            return;
        }
        
        // Reduzierte Hook-Registrierung
        add_action('init', [__CLASS__, 'setup_wordpress_cache_integration'], 999);
        add_action('shutdown', [__CLASS__, 'log_cache_performance'], 1);
        
        // Memory Limit berechnen
        self::$max_memory_usage = self::calculate_safe_cache_size();
        
        if (function_exists('csv_import_log')) {
            csv_import_log('debug', 'Memory Cache System initialisiert (Safe Mode)', [
                'max_cache_size' => size_format(self::$max_memory_usage),
                'current_memory' => size_format(memory_get_usage(true)),
                'php_memory_limit' => ini_get('memory_limit')
            ]);
        }
    }
    
    /**
     * KRITISCHER Memory Check vor jeder Operation
     */
    private static function check_memory_before_operation(): bool {
        $current = memory_get_usage(true);
        $limit_bytes = function_exists('wp_convert_hr_to_bytes') ? 
                      wp_convert_hr_to_bytes(ini_get('memory_limit')) : 
                      (int)str_replace('M', '', ini_get('memory_limit')) * 1024 * 1024;
        
        // Bei 85% des Memory Limits → Emergency Stop
        if ($current > ($limit_bytes * 0.85)) {
            self::emergency_stop();
            error_log('[CSV Import Cache] EMERGENCY STOP - Memory Limit fast erreicht: ' . 
                     size_format($current) . ' / ' . size_format($limit_bytes));
            return false;
        }
        
        // Bei 500MB+ → Warnung und Cache reduzieren
        if ($current > 524288000) { // 500MB
            self::$max_memory_usage = 8388608; // Reduziere auf 8MB
            error_log('[CSV Import Cache] Hoher Memory-Verbrauch, Cache reduziert: ' . size_format($current));
        }
        
        return true;
    }
    
    /**
     * Emergency Stop - Alle Cache-Daten löschen
     */
    public static function emergency_stop(): void {
        self::$emergency_mode = true;
        self::$stats['emergency_stops']++;
        
        // Alle Cache-Arrays leeren
        self::$object_cache = [];
        self::$csv_cache = [];
        self::$query_cache = [];
        self::$validation_cache = [];
        
        // Cache-Limit auf Minimum
        self::$max_memory_usage = 1048576; // 1MB
        
        // Hooks entfernen um weitere Operationen zu verhindern
        remove_all_actions('csv_import_before_processing');
        remove_all_actions('csv_import_after_processing');
        remove_all_actions('csv_import_daily_maintenance');
        
        error_log('[CSV Import Cache] EMERGENCY STOP ausgeführt - Alle Cache-Daten gelöscht');
    }
    
    /**
     * Sichere Cache-Größe berechnen
     */
    private static function calculate_safe_cache_size(): int {
        $current_usage = memory_get_usage(true);
        $memory_limit = ini_get('memory_limit');
        
        // Bei hohem Memory-Verbrauch → Minimal-Cache
        if ($current_usage > 419430400) { // 400MB
            return 4194304; // 4MB
        }
        
        if ($memory_limit === '-1') {
            return 16777216; // 16MB bei unbegrenztem Memory
        }
        
        if (function_exists('wp_convert_hr_to_bytes')) {
            $memory_bytes = wp_convert_hr_to_bytes($memory_limit);
        } else {
            $memory_bytes = (int)str_replace('M', '', $memory_limit) * 1024 * 1024;
        }
        
        $available = $memory_bytes - $current_usage;
        
        // Sehr konservativ: Nur 2% des verfügbaren Speichers
        $cache_size = min(
            max($available * 0.02, 4194304), // Minimum 4MB
            16777216 // Maximum 16MB
        );
        
        return (int) $cache_size;
    }
    
    // ===================================================================
    // HAUPT-CACHE METHODEN - Memory Safe
    // ===================================================================
    
    /**
     * Holt einen Wert aus dem Cache - Memory Safe
     */
    public static function get(string $namespace, string $key, $default = null) {
        // Emergency Mode Check
        if (self::$emergency_mode) {
            return $default;
        }
        
        // Memory Check
        if (!self::check_memory_before_operation()) {
            return $default;
        }
        
        $cache_key = self::build_cache_key($namespace, $key);
        
        // Memory Cache prüfen
        $value = self::get_from_memory_cache($cache_key);
        
        if ($value !== null) {
            self::$stats['hits']++;
            self::track_cache_hit($namespace, $key);
            return $value;
        }
        
        // WordPress Object Cache als Fallback (nur wenn Memory OK)
        if (memory_get_usage(true) < (self::$max_memory_usage * 2)) {
            $value = self::get_from_object_cache($cache_key);
            
            if ($value !== false) {
                // Nur in Memory Cache wenn Platz vorhanden
                if (self::can_cache_value_safe($value)) {
                    self::set_memory_cache($cache_key, $value);
                }
                self::$stats['hits']++;
                return $value;
            }
        }
        
        self::$stats['misses']++;
        self::track_cache_miss($namespace, $key);
        
        return $default;
    }
    
    /**
     * Setzt einen Wert im Cache - Memory Safe
     */
    public static function set(string $namespace, string $key, $value, int $ttl = 3600): bool {
        // Emergency Mode Check
        if (self::$emergency_mode) {
            return false;
        }
        
        // Memory Check
        if (!self::check_memory_before_operation()) {
            return false;
        }
        
        $cache_key = self::build_cache_key($namespace, $key);
        
        // Prüfe ob Wert cachebar ist
        if (!self::can_cache_value_safe($value)) {
            if (function_exists('csv_import_log')) {
                csv_import_log('debug', 'Cache: Wert zu groß oder Memory-Druck', [
                    'key' => substr($cache_key, 0, 50),
                    'size' => strlen(serialize($value)),
                    'current_memory' => size_format(memory_get_usage(true))
                ]);
            }
            
            // Versuche nur WordPress Object Cache
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
     * Löscht einen Wert aus dem Cache - Memory Safe
     */
    public static function delete(string $namespace, string $key): bool {
        // Emergency Mode Check
        if (self::$emergency_mode) {
            return true; // Fake success
        }
        
        // Memory Check
        if (!self::check_memory_before_operation()) {
            return false;
        }
        
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
     * Löscht alle Werte eines Namespaces - Memory Safe
     */
    public static function flush_namespace(string $namespace): int {
        // Emergency Mode Check
        if (self::$emergency_mode) {
            return 0;
        }
        
        $deleted = 0;
        $prefix = $namespace . ':';
        
        // Memory Cache durchsuchen
        foreach (self::$object_cache as $key => $value) {
            if (strpos($key, $prefix) === 0) {
                unset(self::$object_cache[$key]);
                $deleted++;
            }
        }
        
        if (function_exists('csv_import_log')) {
            csv_import_log('debug', "Cache Namespace '{$namespace}' geleert", [
                'deleted_items' => $deleted
            ]);
        }
        
        return $deleted;
    }
    
    // ===================================================================
    // MEMORY SAFE CACHE OPERATIONEN
    // ===================================================================
    
    /**
     * Prüft ob ein Wert sicher gecacht werden kann
     */
    private static function can_cache_value_safe($value): bool {
        $serialized_size = strlen(serialize($value));
        $current_usage = self::get_current_cache_memory_usage();
        
        // Größe-Checks
        if ($serialized_size > 1048576) { // Mehr als 1MB → Nein
            return false;
        }
        
        if ($serialized_size > (self::$max_memory_usage * 0.1)) { // Mehr als 10% des Cache → Nein
            return false;
        }
        
        // Memory-Druck Check
        if (($current_usage + $serialized_size) > self::$max_memory_usage) {
            // Versuche Platz zu schaffen
            self::evict_cache_items_safe($serialized_size);
            
            $new_usage = self::get_current_cache_memory_usage();
            if (($new_usage + $serialized_size) > self::$max_memory_usage) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Räumt Cache-Einträge sicher frei
     */
    private static function evict_cache_items_safe(int $needed_space): void {
        $freed = 0;
        $evicted = 0;
        
        // Einfache FIFO Eviction - älteste Einträge zuerst
        $items_to_remove = [];
        
        foreach (self::$object_cache as $key => $item) {
            if ($freed >= $needed_space) {
                break;
            }
            
            $item_size = strlen(serialize($item));
            $items_to_remove[] = $key;
            $freed += $item_size;
            $evicted++;
            
            // Sicherheits-Limit
            if ($evicted >= 50) {
                break;
            }
        }
        
        // Entferne Items
        foreach ($items_to_remove as $key) {
            unset(self::$object_cache[$key]);
        }
        
        self::$stats['evictions'] += $evicted;
        
        if (function_exists('csv_import_log')) {
            csv_import_log('debug', 'Cache Eviction durchgeführt', [
                'freed_bytes' => $freed,
                'evicted_items' => $evicted,
                'needed_space' => $needed_space
            ]);
        }
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
    
    /**
     * Memory Cache Operationen
     */
    private static function get_from_memory_cache(string $key) {
        if (!isset(self::$object_cache[$key])) {
            return null;
        }
        
        $item = self::$object_cache[$key];
        
        // TTL prüfen
        if (isset($item['expires']) && $item['expires'] > 0 && $item['expires'] < time()) {
            unset(self::$object_cache[$key]);
            return null;
        }
        
        // Access Time aktualisieren
        if (isset(self::$object_cache[$key])) {
            self::$object_cache[$key]['last_access'] = time();
        }
        
        return $item['data'] ?? null;
    }
    
    private static function set_memory_cache(string $key, $value, int $ttl = 3600): bool {
        $expires = $ttl > 0 ? time() + $ttl : 0;
        
        self::$object_cache[$key] = [
            'data' => $value,
            'expires' => $expires,
            'created' => time(),
            'last_access' => time()
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
    // SPEZIALISIERTE CACHE METHODEN - Memory Safe
    // ===================================================================
    
    /**
     * Cached Konfigurationsdaten
     */
    public static function get_config(string $config_key = null) {
        if (self::$emergency_mode) {
            return $config_key ? null : [];
        }
        
        if ($config_key) {
            return self::get(self::CACHE_CONFIG, $config_key);
        }
        
        $full_config = self::get(self::CACHE_CONFIG, 'full_config');
        
        if ($full_config === null) {
            if (function_exists('csv_import_get_config')) {
                $full_config = csv_import_get_config();
            } else {
                $full_config = [];
            }
            
            // Nur kleine Configs cachen
            if (strlen(serialize($full_config)) < 10240) { // Unter 10KB
                self::set(self::CACHE_CONFIG, 'full_config', $full_config, 1800);
            }
        }
        
        return $full_config;
    }
    
    /**
     * Cached Template-Daten
     */
    public static function get_template(int $template_id) {
        if (self::$emergency_mode) {
            return null;
        }
        
        $template = self::get(self::CACHE_TEMPLATES, "template_{$template_id}");
        
        if ($template === null) {
            $template_post = get_post($template_id);
            if ($template_post) {
                $template = [
                    'post' => $template_post,
                    'meta' => get_post_meta($template_id),
                    'cached_at' => time()
                ];
                
                // Nur kleine Templates cachen
                if (strlen(serialize($template)) < 51200) { // Unter 50KB
                    self::set(self::CACHE_TEMPLATES, "template_{$template_id}", $template, 3600);
                }
            }
        }
        
        return $template;
    }
    
    // ===================================================================
    // PERFORMANCE TRACKING - Reduziert
    // ===================================================================
    
    /**
     * Verfolgt Cache Hits
     */
    private static function track_cache_hit(string $namespace, string $key): void {
        if (!isset(self::$cache_hit_ratio[$namespace])) {
            self::$cache_hit_ratio[$namespace] = ['hits' => 0, 'total' => 0];
        }
        
        self::$cache_hit_ratio[$namespace]['hits']++;
        self::$cache_hit_ratio[$namespace]['total']++;
    }
    
    /**
     * Verfolgt Cache Misses
     */
    private static function track_cache_miss(string $namespace, string $key): void {
        if (!isset(self::$cache_hit_ratio[$namespace])) {
            self::$cache_hit_ratio[$namespace] = ['hits' => 0, 'total' => 0];
        }
        
        self::$cache_hit_ratio[$namespace]['total']++;
    }
    
    /**
     * Loggt Cache Performance
     */
    public static function log_cache_performance(): void {
        $total_requests = self::$stats['hits'] + self::$stats['misses'];
        
        if ($total_requests === 0) {
            return;
        }
        
        $hit_rate = round((self::$stats['hits'] / $total_requests) * 100, 2);
        $memory_usage = self::get_current_cache_memory_usage();
        
        $performance = [
            'hit_rate' => $hit_rate,
            'total_requests' => $total_requests,
            'memory_usage' => size_format($memory_usage),
            'emergency_stops' => self::$stats['emergency_stops'],
            'emergency_mode' => self::$emergency_mode,
            'cache_efficiency' => $hit_rate > 60 ? 'good' : ($hit_rate > 30 ? 'medium' : 'poor')
        ];
        
        // Performance-Metriken für Monitoring
        update_option('csv_import_cache_performance', $performance, false);
    }
    
    // ===================================================================
    // WORDPRESS INTEGRATION - Minimal
    // ===================================================================
    
    /**
     * WordPress Cache Integration Setup
     */
    public static function setup_wordpress_cache_integration(): void {
        // Nur wenn nicht im Emergency Mode
        if (self::$emergency_mode) {
            return;
        }
        
        // Gruppe für WordPress Object Cache registrieren
        wp_cache_add_global_groups(['csv_import']);
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
            'memory_usage_percent' => self::$max_memory_usage > 0 ? 
                                    round((self::get_current_cache_memory_usage() / self::$max_memory_usage) * 100, 2) : 0,
            'stats' => self::$stats,
            'emergency_mode' => self::$emergency_mode,
            'namespace_stats' => self::$cache_hit_ratio
        ];
    }
    
    /**
     * Cache-Status für Admin-Interface
     */
    public static function get_cache_status(): array {
        $stats = self::get_cache_stats();
        
        return [
            'enabled' => !self::$emergency_mode,
            'healthy' => !self::$emergency_mode && $stats['hit_rate'] > 30,
            'performance' => self::$emergency_mode ? 'emergency' : 
                           ($stats['hit_rate'] > 60 ? 'excellent' : 
                           ($stats['hit_rate'] > 30 ? 'good' : 'poor')),
            'memory_pressure' => $stats['memory_usage_percent'] > 80,
            'stats' => $stats
        ];
    }
    
    /**
     * Cache komplett leeren
     */
    public static function flush_all_cache(): int {
        $cleared_items = count(self::$object_cache);
        
        self::$object_cache = [];
        self::$csv_cache = [];
        self::$query_cache = [];
        self::$validation_cache = [];
        
        // WordPress Object Cache auch leeren
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('csv_import');
        }
        
        // Emergency Mode zurücksetzen
        self::$emergency_mode = false;
        
        if (function_exists('csv_import_log')) {
            csv_import_log('info', 'Gesamter Cache geleert', [
                'cleared_items' => $cleared_items
            ]);
        }
        
        return $cleared_items;
    }
    
    // ===================================================================
    // DUMMY METHODEN für Kompatibilität
    // ===================================================================
    
    public static function get_csv_validation($source, $config_hash) {
        return self::get(self::CACHE_VALIDATION, "validation_{$source}_{$config_hash}");
    }
    
    public static function set_csv_validation($source, $config_hash, $validation, $ttl = 600) {
        return self::set(self::CACHE_VALIDATION, "validation_{$source}_{$config_hash}", $validation, $ttl);
    }
    
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
}

// ===================================================================
// CACHE INTEGRATION FUNKTIONEN - Minimal
// ===================================================================

/**
 * Cached Version von csv_import_get_config()
 */
function csv_import_get_config_cached(): array {
    return CSV_Import_Memory_Cache::get_config();
}

/**
 * Cache komplett leeren (für Debug/Wartung)
 */
function csv_import_flush_all_cache(): int {
    return CSV_Import_Memory_Cache::flush_all_cache();
}

// ===================================================================
// ADMIN INTEGRATION - Vereinfacht
// ===================================================================

/**
 * Vereinfachte Admin-Klasse
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
            <h1>🛡️ CSV Import Memory Cache (Safe Mode)</h1>
            
            <?php if ($cache_status['stats']['emergency_mode']): ?>
            <div class="notice notice-error">
                <p><strong>⚠️ EMERGENCY MODE AKTIV</strong> - Cache läuft im Sicherheitsmodus wegen Memory-Problemen.</p>
            </div>
            <?php endif; ?>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                
                <div style="background: white; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
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
                    
                    <div style="margin: 15px 0;">
                        <strong>Hit Rate:</strong> <?php echo $stats['hit_rate']; ?>%
                    </div>
                    
                    <div style="margin: 15px 0;">
                        <strong>Emergency Stops:</strong> <?php echo $stats['stats']['emergency_stops']; ?>
                    </div>
                </div>
                
                <div style="background: white; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h3>🛠️ Cache Verwaltung</h3>
                    
                    <div style="margin: 15px 0;">
                        <button type="button" class="button button-primary" onclick="csvCacheFlush()">
                            🗑️ Cache leeren
                        </button>
                        <p class="description">Leert alle gecachten Daten und setzt Emergency Mode zurück.</p>
                    </div>
                    
                    <div id="cache-action-result" style="margin-top: 15px;"></div>
                </div>
            </div>
            
            <script>
            function csvCacheFlush() {
                jQuery.post(ajaxurl, {
                    action: 'csv_cache_flush',
                    nonce: '<?php echo wp_create_nonce('csv_cache_nonce'); ?>'
                }, function(response) {
                    const resultDiv = document.getElementById('cache-action-result');
                    if (response.success) {
                        resultDiv.innerHTML = '<div class="notice notice-success"><p>✅ Cache erfolgreich geleert</p></div>';
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        resultDiv.innerHTML = '<div class="notice notice-error"><p>❌ Fehler beim Cache leeren</p></div>';
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
// DASHBOARD WIDGET - Memory Safe
// ===================================================================

/**
 * Memory-sichere Dashboard Widget Darstellung
 */
function csv_import_render_safe_cache_widget() {
    if (!class_exists('CSV_Import_Memory_Cache')) {
        echo '<p>Cache System nicht verfügbar.</p>';
        return;
    }
    
    try {
        $cache_status = CSV_Import_Memory_Cache::get_cache_status();
        $stats = $cache_status['stats'];
        
        // Performance Badge Farbe
        $badge_color = '#2271b1'; // Standard blau
        if ($cache_status['stats']['emergency_mode']) {
            $badge_color = '#d63638'; // Rot für Emergency
        } elseif ($cache_status['performance'] === 'excellent') {
            $badge_color = '#00a32a'; // Grün für Excellent
        }
        
        ?>
        <div style="display: flex; gap: 15px; align-items: center; margin-bottom: 15px;">
            <div style="background: <?php echo esc_attr($badge_color); ?>; color: white; padding: 10px; border-radius: 4px; text-align: center; min-width: 80px; font-weight: bold;">
                <div style="font-size: 16px;"><?php echo esc_html($stats['hit_rate']); ?>%</div>
                <div style="font-size: 11px;">Hit Rate</div>
            </div>
            
            <div style="flex: 1; font-size: 13px; line-height: 1.4;">
                <div><strong>Items:</strong> <?php echo esc_html(number_format($stats['total_items'])); ?></div>
                <div><strong>Memory:</strong> <?php echo esc_html(size_format($stats['memory_usage'])); ?></div>
                <div>
                    <strong>Status:</strong> 
                    <span style="color: <?php echo $cache_status['enabled'] ? 'green' : 'red'; ?>;">
                        <?php 
                        if ($cache_status['stats']['emergency_mode']) {
                            echo '🚨 Emergency';
                        } elseif ($cache_status['healthy']) {
                            echo '✅ Gesund';
                        } else {
                            echo '⚠️ Probleme';
                        }
                        ?>
                    </span>
                </div>
                
                <?php if ($stats['stats']['emergency_stops'] > 0): ?>
                <div style="margin-top: 5px; color: #d63638; font-size: 12px;">
                    Emergency Stops: <?php echo $stats['stats']['emergency_stops']; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($cache_status['stats']['emergency_mode']): ?>
        <div style="margin: 10px 0; padding: 8px; background: #fcf0f1; border-left: 4px solid #d63638; font-size: 12px; border-radius: 0 4px 4px 0;">
            <strong>Emergency Mode:</strong> Cache läuft im Sicherheitsmodus.
        </div>
        <?php endif; ?>
        
        <div style="margin: 10px 0 0 0; text-align: center;">
            <a href="<?php echo esc_url(admin_url('tools.php?page=csv-import-cache')); ?>" class="button button-small">
                🛠️ Cache verwalten
            </a>
        </div>
        <?php
        
    } catch (Exception $e) {
        echo '<div style="margin: 10px 0; padding: 8px; background: #fcf0f1; border-left: 4px solid #d63638; font-size: 12px;">';
        echo '<strong>Cache Fehler:</strong> ' . esc_html($e->getMessage());
        echo '</div>';
        
        error_log('CSV Import Cache Widget Error: ' . $e->getMessage());
    }
}

// Dashboard Widget CSS - Minimal und sicher
add_action('admin_head', function() {
    if (!function_exists('get_current_screen') || get_current_screen()->id !== 'dashboard') {
        return;
    }
    ?>
    <style type="text/css">
    #csv_import_cache_performance {
        background: #fff !important;
        border: 1px solid #c3c4c7 !important;
        position: relative !important;
    }
    
    #csv_import_cache_performance .hndle {
        background: #fff !important;
        border-bottom: 1px solid #c3c4c7 !important;
        color: #1d2327 !important;
    }
    
    #csv_import_cache_performance .inside {
        background: #fff !important;
        padding: 12px !important;
    }
    
    /* Entferne problematische Pseudo-Elemente */
    #csv_import_cache_performance::before,
    #csv_import_cache_performance::after {
        display: none !important;
    }
    </style>
    <?php
});

// Dashboard Widget Registration - Memory Safe
add_action('wp_dashboard_setup', function() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    wp_add_dashboard_widget(
        'csv_import_cache_performance',
        'CSV Import Cache (Safe Mode)',
        function() {
            echo '<div style="background: #fff; margin: 0; padding: 0;">';
            csv_import_render_safe_cache_widget();
            echo '</div>';
        }
    );
});

// ===================================================================
// SIMPLIFIED UTILITY FUNCTIONS
// ===================================================================

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
 * Cache Status Check
 */
function csv_import_cache_is_healthy(): bool {
    if (!class_exists('CSV_Import_Memory_Cache')) {
        return false;
    }
    
    $status = CSV_Import_Memory_Cache::get_cache_status();
    return $status['enabled'] && $status['healthy'];
}

// ===================================================================
// CACHE INITIALIZATION - Memory Safe
// ===================================================================

// Cache System initialisieren - Mit Priorität und Error Handling
add_action('plugins_loaded', function() {
    try {
        // Prüfe Memory vor Initialisierung
        $current_memory = memory_get_usage(true);
        if ($current_memory > 524288000) { // 500MB
            error_log('[CSV Import Cache] Memory zu hoch für Initialisierung: ' . size_format($current_memory));
            return;
        }
        
        CSV_Import_Memory_Cache::init();
        CSV_Import_Cache_Admin::init();
        
        if (function_exists('csv_import_log')) {
            csv_import_log('info', 'CSV Import Memory Cache System geladen (Safe Mode)');
        }
        
    } catch (Exception $e) {
        error_log('[CSV Import Cache] Initialisierung fehlgeschlagen: ' . $e->getMessage());
    }
}, 20); // Späte Priorität

// Memory Monitor Hook
add_action('shutdown', function() {
    $memory_usage = memory_get_usage(true);
    $peak_memory = memory_get_peak_usage(true);
    
    // Logge nur bei hohem Verbrauch
    if ($peak_memory > 419430400) { // 400MB
        error_log('[CSV Import Cache] High Memory Usage detected: Current=' . 
                 size_format($memory_usage) . ', Peak=' . size_format($peak_memory));
    }
}, 1);

// Emergency Stop bei kritischem Memory-Verbrauch
add_action('wp_loaded', function() {
    $current_memory = memory_get_usage(true);
    $memory_limit = ini_get('memory_limit');
    
    if ($memory_limit !== '-1') {
        $limit_bytes = function_exists('wp_convert_hr_to_bytes') ? 
                      wp_convert_hr_to_bytes($memory_limit) : 
                      (int)str_replace('M', '', $memory_limit) * 1024 * 1024;
        
        if ($current_memory > ($limit_bytes * 0.9)) { // 90% des Limits
            if (class_exists('CSV_Import_Memory_Cache')) {
                CSV_Import_Memory_Cache::emergency_stop();
            }
            error_log('[CSV Import Cache] Emergency Stop triggered at wp_loaded: ' . 
                     size_format($current_memory) . ' / ' . size_format($limit_bytes));
        }
    }
});

// Admin Notice für Emergency Mode
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    if (class_exists('CSV_Import_Memory_Cache')) {
        $cache_status = CSV_Import_Memory_Cache::get_cache_status();
        
        if ($cache_status['stats']['emergency_mode']) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong>🚨 CSV Import Cache Emergency Mode:</strong> 
                    Das Cache-System läuft im Sicherheitsmodus wegen Memory-Problemen. 
                    <a href="<?php echo admin_url('tools.php?page=csv-import-cache'); ?>">Cache verwalten</a>
                </p>
            </div>
            <?php
        }
    }
});

// Debug Memory Info (nur bei WP_DEBUG)
if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('wp_footer', function() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $memory_usage = memory_get_usage(true);
        $peak_memory = memory_get_peak_usage(true);
        
        echo "<!-- CSV Import Memory Debug: Current=" . size_format($memory_usage) . 
             ", Peak=" . size_format($peak_memory) . " -->";
    });
}

if (function_exists('csv_import_log')) {
    csv_import_log('debug', 'CSV Import Memory Cache System vollständig geladen - MEMORY SAFE EDITION!');
}

?>
