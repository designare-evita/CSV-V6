<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direkten Zugriff verhindern
}
// ===================================================================
// PERFORMANCE MONITORING
// ===================================================================

class CSV_Import_Performance_Monitor {
    private static $start_time;
    private static $checkpoints = [];
    
    public static function start() {
        self::$start_time = microtime(true);
        self::$checkpoints = [];
        self::checkpoint('import_start');
    }
    
    public static function checkpoint($label) {
        if (!self::$start_time) {
            self::start();
        }
        
        self::$checkpoints[$label] = [
            'time' => microtime(true),
            'memory' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }
    
    public static function get_stats() {
        if (!self::$start_time) {
            return null;
        }
        
        $total_time = microtime(true) - self::$start_time;
        
        $stats = [
            'total_time' => round($total_time, 2),
            'total_time_formatted' => self::format_time($total_time),
            'peak_memory' => memory_get_peak_usage(true),
            'peak_memory_formatted' => size_format(memory_get_peak_usage(true)),
            'checkpoints' => []
        ];
        
        $prev_time = self::$start_time;
        foreach (self::$checkpoints as $label => $data) {
            $duration = round($data['time'] - $prev_time, 2);
            $stats['checkpoints'][$label] = [
                'duration' => $duration,
                'duration_formatted' => self::format_time($duration),
                'memory' => size_format($data['memory']),
                'peak_memory' => size_format($data['peak_memory'])
            ];
            $prev_time = $data['time'];
        }
        
        return $stats;
    }
    
    private static function format_time($seconds) {
        if ($seconds < 60) {
            return round($seconds, 1) . 's';
        } elseif ($seconds < 3600) {
            return round($seconds / 60, 1) . 'min';
        } else {
            return round($seconds / 3600, 1) . 'h';
        }
    }
    
    public static function log_performance($import_result) {
        $stats = self::get_stats();
        if (!$stats) return;
        
        CSV_Import_Error_Handler::handle(
            CSV_Import_Error_Handler::LEVEL_INFO,
            sprintf(
                'Import Performance: %s, Peak Memory: %s',
                $stats['total_time_formatted'],
                $stats['peak_memory_formatted']
            ),
            $stats
        );
        
        // Performance-Warnung bei langsamen Imports
        if ($stats['total_time'] > 300) { // 5 Minuten
            CSV_Import_Error_Handler::handle(
                CSV_Import_Error_Handler::LEVEL_WARNING,
                'Import dauerte länger als 5 Minuten - Performance-Optimierung empfohlen'
            );
        }
        
        // Memory-Warnung
        if ($stats['peak_memory'] > 256 * 1024 * 1024) { // 256MB
            CSV_Import_Error_Handler::handle(
                CSV_Import_Error_Handler::LEVEL_WARNING,
                'Hoher Memory-Verbrauch - kleinere Batch-Größe empfohlen'
            );
        }
    }
}