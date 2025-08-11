<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direkten Zugriff verhindern
}
// ===================================================================
// CSV VALIDATION & PREPROCESSING
// ===================================================================

class CSV_Import_Validator {
    
    public static function comprehensive_csv_check($csv_content, $config) {
        $issues = [];
        $warnings = [];
        $info = [];
        
        // Basis-Checks
        if (empty($csv_content)) {
            $issues[] = 'CSV-Datei ist leer';
            return ['issues' => $issues, 'warnings' => $warnings, 'info' => $info];
        }
        
        $size = strlen($csv_content);
        $info['file_size'] = size_format($size);
        
        if ($size > 100 * 1024 * 1024) { // 100MB
            $warnings[] = 'Sehr große CSV-Datei (' . size_format($size) . ') - Import könnte lange dauern';
        }
        
        // Encoding-Check
        $encoding = mb_detect_encoding($csv_content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($encoding !== 'UTF-8') {
            $warnings[] = "CSV-Encoding ist $encoding (empfohlen: UTF-8)";
        }
        $info['encoding'] = $encoding ?: 'Unbekannt';
        
        // CSV-Struktur analysieren
        $csv_content = csv_import_normalize_line_endings($csv_content);
        $lines = explode("\n", $csv_content);
        $info['total_lines'] = count($lines);
        
        if (count($lines) < 2) {
            $issues[] = 'CSV muss mindestens Header und eine Datenzeile enthalten';
            return ['issues' => $issues, 'warnings' => $warnings, 'info' => $info];
        }
        
        // Header analysieren
        $header_line = array_shift($lines);
        $header = str_getcsv($header_line);
        $info['columns'] = count($header);
        $info['column_names'] = $header;
        
        // Duplizierte Spalten prüfen
        $duplicates = array_diff_assoc($header, array_unique($header));
        if (!empty($duplicates)) {
            $issues[] = 'Duplizierte Spaltennamen gefunden: ' . implode(', ', array_unique($duplicates));
        }
        
        // Leere Spaltennamen
        $empty_columns = array_filter($header, function($col) { return empty(trim($col)); });
        if (!empty($empty_columns)) {
            $warnings[] = count($empty_columns) . ' leere Spaltennamen gefunden';
        }
        
        // Erforderliche Spalten prüfen
        $missing_required = array_diff($config['required_columns'], $header);
        if (!empty($missing_required)) {
            $issues[] = 'Fehlende erforderliche Spalten: ' . implode(', ', $missing_required);
        }
        
        // Datenzeilen analysieren (Sample)
        $data_lines = array_filter($lines, 'trim');
        $info['data_rows'] = count($data_lines);
        
        $sample_size = min(100, count($data_lines)); // Erste 100 Zeilen analysieren
        $column_inconsistencies = 0;
        $empty_rows = 0;
        
        foreach (array_slice($data_lines, 0, $sample_size) as $i => $line) {
            if (empty(trim($line))) {
                $empty_rows++;
                continue;
            }
            
            $row = str_getcsv($line);
            
            // Spaltenanzahl prüfen
            if (count($row) !== count($header)) {
                $column_inconsistencies++;
            }
        }
        
        if ($empty_rows > 0) {
            $warnings[] = "$empty_rows leere Zeilen in der CSV gefunden";
        }
        
        if ($column_inconsistencies > 0) {
            $percentage = round(($column_inconsistencies / $sample_size) * 100, 1);
            if ($percentage > 10) {
                $issues[] = "$column_inconsistencies von $sample_size Zeilen haben falsche Spaltenanzahl ($percentage%)";
            } else {
                $warnings[] = "$column_inconsistencies von $sample_size Zeilen haben falsche Spaltenanzahl ($percentage%)";
            }
        }
        
        // Performance-Schätzung
        $estimated_time = self::estimate_import_time(count($data_lines));
        $info['estimated_import_time'] = $estimated_time;
        
        if ($estimated_time > 300) { // 5 Minuten
            $warnings[] = 'Import wird voraussichtlich länger als 5 Minuten dauern';
        }
        
        return [
            'issues' => $issues,
            'warnings' => $warnings,
            'info' => $info
        ];
    }
    
    private static function estimate_import_time($row_count) {
        // Schätzung basierend auf Erfahrungswerten:
        // ~2-5 Sekunden pro 100 Zeilen je nach Komplexität
        $base_time_per_100 = 3; // Sekunden
        return ceil(($row_count / 100) * $base_time_per_100);
    }
    
    public static function preprocess_csv($csv_content, $options = []) {
        $defaults = [
            'remove_empty_rows' => true,
            'trim_values' => true,
            'convert_encoding' => true,
            'normalize_line_endings' => true
        ];
        
        $options = array_merge($defaults, $options);
        
        // Encoding konvertieren
        if ($options['convert_encoding']) {
            $encoding = mb_detect_encoding($csv_content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
            if ($encoding && $encoding !== 'UTF-8') {
                $csv_content = mb_convert_encoding($csv_content, 'UTF-8', $encoding);
            }
        }
        
        // Line endings normalisieren
        if ($options['normalize_line_endings']) {
            $csv_content = csv_import_normalize_line_endings($csv_content);
        }
        
        // Weitere Preprocessing-Schritte...
        if ($options['remove_empty_rows'] || $options['trim_values']) {
            $lines = explode("\n", $csv_content);
            $processed_lines = [];
            
            foreach ($lines as $line) {
                if ($options['remove_empty_rows'] && empty(trim($line))) {
                    continue;
                }
                
                if ($options['trim_values']) {
                    $row = str_getcsv($line);
                    $row = array_map('trim', $row);
                    $line = self::array_to_csv_line($row);
                }
                
                $processed_lines[] = $line;
            }
            
            $csv_content = implode("\n", $processed_lines);
        }
        
        return $csv_content;
    }
    
    private static function array_to_csv_line($array) {
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, $array);
        rewind($fp);
        $line = rtrim(fgets($fp), "\n");
        fclose($fp);
        return $line;
    }
}