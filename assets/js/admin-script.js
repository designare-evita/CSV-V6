/**
 * CSV Import Pro Admin JavaScript - Vollständige Korrektur v8.7
 * ALLE ursprünglichen Funktionen + ROBUSTE VERBINDUNGSPRÜFUNG
 * Keine Funktionen entfernt - nur Verbindungsprobleme behoben!
 */

// ===================================================================
// GLOBALE FUNKTIONEN (vor document ready!)
// ===================================================================

window.csvImportTestConfig = function() {
    if (typeof window.CSVImportAdmin !== 'undefined' && window.CSVImportAdmin.testConfiguration) {
        window.CSVImportAdmin.testConfiguration();
    }
};

window.csvImportValidateCSV = function(type) {
    if (typeof window.CSVImportAdmin !== 'undefined' && window.CSVImportAdmin.validateCSV) {
        window.CSVImportAdmin.validateCSV(type);
    }
};

window.csvImportSystemHealth = function() {
    if (typeof window.CSVImportAdmin !== 'undefined' && window.CSVImportAdmin.systemHealthCheck) {
        window.CSVImportAdmin.systemHealthCheck();
    }
};

window.csvImportCheckHandlers = function() {
    if (typeof window.CSVImportAdmin !== 'undefined' && window.CSVImportAdmin.checkHandlers) {
        window.CSVImportAdmin.checkHandlers();
    }
};

(function($) {
    'use strict';

    const CSVImportAdmin = {
        // Version und Konfiguration
        version: '8.7-fixed',
        
        // Debug-System (erweitert)
        debug: {
            enabled: true,
            logLevel: 'info',
            
            log: function(message, data, level = 'info') {
                if (!this.enabled) return;
                
                const levels = { debug: 0, info: 1, warn: 2, error: 3 };
                const currentLevel = levels[this.logLevel] || 1;
                const messageLevel = levels[level] || 1;
                
                if (messageLevel >= currentLevel) {
                    const prefix = `🔧 CSV Import Admin (v${CSVImportAdmin.version}):`;
                    const timestamp = new Date().toLocaleTimeString();
                    
                    switch(level) {
                        case 'error':
                            console.error(`❌ ${prefix} [${timestamp}]`, message, data || '');
                            break;
                        case 'warn':
                            console.warn(`⚠️ ${prefix} [${timestamp}]`, message, data || '');
                            break;
                        case 'debug':
                            console.debug(`🐛 ${prefix} [${timestamp}]`, message, data || '');
                            break;
                        default:
                            console.log(`ℹ️ ${prefix} [${timestamp}]`, message, data || '');
                    }
                }
            },
            
            warn: function(message, data) {
                this.log(message, data, 'warn');
            },
            
            error: function(message, data) {
                this.log(message, data, 'error');
            },
            
            debug: function(message, data) {
                this.log(message, data, 'debug');
            },
            
            group: function(name) {
                if (this.enabled) console.group(`🔧 ${name}`);
            },
            
            groupEnd: function() {
                if (this.enabled) console.groupEnd();
            }
        },

        // DOM-Elemente Cache (erweitert)
        elements: {
            resultsContainer: null,
            sampleDataContainer: null,
            importButtons: null,
            progressNotice: null,
            progressBar: null,
            emergencyReset: null,
            refreshButton: null,
            schedulerButtons: null,
            healthStatus: null
        },

        // Status-Tracking (erweitert mit KORRIGIERTER Verbindungsüberwachung)
        status: {
            importRunning: false,
            validationInProgress: false,
            schedulerTestInProgress: false,
            progressUpdateInterval: null,
            lastProgressUpdate: 0,
            connectionStatus: 'unknown',
            handlerStatus: 'unknown',
            // --- KORRIGIERT: Intelligente Verbindungsüberwachung ---
            connectionFailures: 0,
            lastConnectionCheck: 0
        },

        // Konfiguration (erweitert mit KORRIGIERTEN Connection-Settings)
        config: {
            progressUpdateInterval: 5000,
            maxRetries: 3,
            ajaxTimeout: 30000,
            retryDelay: 2000,
            healthCheckInterval: 60000,
            // --- KORRIGIERT: Weniger aggressive Verbindungsprüfung ---
            connectionCheckInterval: 60000, // 1 Minute statt 30 Sekunden
            connectionFailureThreshold: 3,  // 3 Versuche vor Offline-Status
            connectionTimeout: 8000,        // 8 Sekunden Timeout
            maxLogEntries: 100,
            autoRefreshProgress: true,
            enableSchedulerIntegration: true
        },

        // Interner State
        state: {
            initialized: false,
            ajaxQueue: [],
            retryCount: {},
            lastError: null,
            performanceMetrics: {
                initTime: null,
                ajaxCalls: 0,
                errors: 0,
                successfulCalls: 0
            }
        }
    };

    // ===================================================================
    // INITIALISIERUNG
    // ===================================================================

    $(document).ready(function() {
        const startTime = performance.now();
        CSVImportAdmin.state.performanceMetrics.initTime = startTime;
        
        CSVImportAdmin.debug.group('CSV Import Admin Initialisierung');
        CSVImportAdmin.init();
        CSVImportAdmin.debug.groupEnd();
        
        const endTime = performance.now();
        CSVImportAdmin.debug.log(`Initialisierung abgeschlossen in ${(endTime - startTime).toFixed(2)}ms`);
    });

    /**
     * Hauptinitialisierung - Komplett für Version 8.7 mit korrigierter Verbindung
     */
    CSVImportAdmin.init = function() {
        this.debug.log('Initialisiere CSV Import Admin Interface v' + this.version);

        // 1. Verfügbarkeit von csvImportAjax prüfen
        if (typeof csvImportAjax === 'undefined') {
            this.debug.error('csvImportAjax Object nicht verfügbar - Plugin korrekt geladen?');
            this.showGlobalError('Admin-Konfiguration fehlt. Seite neu laden oder Administrator kontaktieren.');
            return false;
        }

        // 2. AJAX-Handler-Verfügbarkeit prüfen
        this.checkHandlerAvailability();

        // 3. DOM-Elemente cachen
        this.cacheElements();

        // 4. Event-Listener registrieren
        this.bindEvents();

        // 5. Status initialisieren
        this.initializeStatus();

        // 6. Auto-Updates starten
        this.startAutoUpdates();

        // 7. Keyboard-Shortcuts registrieren
        this.registerKeyboardShortcuts();

        // 8. Connection-Monitoring starten (KORRIGIERT)
        this.startConnectionMonitoring();

        // 9. Scheduler-Integration (falls aktiviert)
        if (this.config.enableSchedulerIntegration) {
            this.initializeSchedulerIntegration();
        }

        // 10. Performance-Monitoring
        this.initializePerformanceMonitoring();

        this.state.initialized = true;
        this.debug.log('CSV Import Admin Interface erfolgreich initialisiert');
        
        return true;
    };

    /**
     * Prüft die Verfügbarkeit aller AJAX-Handler
     */
    CSVImportAdmin.checkHandlerAvailability = function() {
        this.debug.debug('Prüfe AJAX-Handler-Verfügbarkeit');
        
        this.performAjaxRequest({
            action: 'csv_import_check_handlers',
            timeout: 10000
        })
        .done((response) => {
            if (response.success && response.data.all_handlers_available) {
                this.status.handlerStatus = 'available';
                this.debug.log(`Alle ${response.data.total_handlers} AJAX-Handler verfügbar`);
            } else {
                this.status.handlerStatus = 'partial';
                this.debug.warn('Nicht alle AJAX-Handler verfügbar', response.data);
            }
        })
        .fail(() => {
            this.status.handlerStatus = 'unavailable';
            this.debug.error('AJAX-Handler-Check fehlgeschlagen');
        });
    };

    /**
     * DOM-Elemente cachen für bessere Performance
     */
    CSVImportAdmin.cacheElements = function() {
        this.debug.debug('Cache DOM-Elemente');
        
        this.elements = {
            // Standard-Elemente
            resultsContainer: $('#csv-test-results'),
            sampleDataContainer: $('#csv-sample-data-container'),
            importButtons: $('.csv-import-btn'),
            progressNotice: $('.csv-import-progress-notice'),
            progressBar: $('.csv-import-progress-fill, .progress-bar-fill'),
            
            // Erweiterte Elemente
            emergencyReset: $('#csv-emergency-reset'),
            refreshButton: $('.csv-refresh-page'),
            schedulerButtons: $('.csv-scheduler-btn'),
            healthStatus: $('.csv-health-status'),
            debugPanel: $('#csv-debug-panel'),
            
            // Container
            mainContainer: $('.csv-import-dashboard'),
            settingsContainer: $('.csv-settings-grid'),
            schedulingContainer: $('.csv-scheduling-dashboard')
        };

        // Fehlende wichtige Elemente loggen
        const missingElements = [];
        Object.keys(this.elements).forEach(key => {
            if (!this.elements[key].length && ['resultsContainer', 'importButtons'].includes(key)) {
                missingElements.push(key);
            }
        });
        
        if (missingElements.length > 0) {
            this.debug.warn('Wichtige DOM-Elemente nicht gefunden:', missingElements);
        }
        
        this.debug.debug(`${Object.keys(this.elements).length} DOM-Elemente gecacht`);
    };

    /**
     * Event-Listener registrieren - Erweitert für Version 8.7
     */
    CSVImportAdmin.bindEvents = function() {
        this.debug.debug('Registriere Event-Listener');
        
        const self = this;

        // Standard Import-Events
        this.elements.importButtons.on('click', function(e) {
            e.preventDefault();
            self.handleImportClick($(this));
        });

        // Emergency Reset Button
        this.elements.emergencyReset.on('click', function(e) {
            e.preventDefault();
            self.emergencyReset();
        });

        // Page Refresh Button
        this.elements.refreshButton.on('click', function(e) {
            e.preventDefault();
            self.handlePageRefresh();
        });

        // Scheduler-Buttons
        this.elements.schedulerButtons.on('click', function(e) {
            e.preventDefault();
            const action = $(this).data('scheduler-action');
            self.handleSchedulerAction(action, $(this));
        });

        // Debug-Panel Toggle
        $(document).on('click', '.csv-debug-toggle', function(e) {
            e.preventDefault();
            self.toggleDebugPanel();
        });

        // Form-Validierung in Echtzeit
        $('form[data-csv-validate="true"] input, form[data-csv-validate="true"] select').on('change blur', function() {
            self.validateFormField($(this));
        });

        // Globale AJAX-Fehlerbehandlung (erweitert)
        $(document).ajaxError(function(event, xhr, settings, error) {
            if (settings.url && settings.url.includes('csv_import')) {
                self.handleGlobalAjaxError(event, xhr, settings, error);
            }
        });

        // Globale AJAX-Erfolg-Überwachung
        $(document).ajaxSuccess(function(event, xhr, settings) {
            if (settings.url && settings.url.includes('csv_import')) {
                self.state.performanceMetrics.successfulCalls++;
            }
        });

        // Window-Events
        $(window).on('beforeunload', function() {
            self.cleanup();
        });

        $(window).on('focus', function() {
            self.handleWindowFocus();
        });

        $(window).on('blur', function() {
            self.handleWindowBlur();
        });

        this.debug.log('Event-Listener erfolgreich registriert');
    };

    /**
     * Status-Initialisierung - KORRIGIERT für bessere Verbindung
     */
    CSVImportAdmin.initializeStatus = function() {
        this.debug.debug('Initialisiere Status');
        
        // Import-Status aus Server-Daten übernehmen
        if (typeof csvImportAjax !== 'undefined') {
            this.status.importRunning = csvImportAjax.import_running || false;
        }

        // UI entsprechend dem Status anpassen
        this.updateUIState();
        
        // --- KORRIGIERT: Optimistische Verbindung beim Start ---
        this.status.connectionStatus = 'online'; // Nicht sofort prüfen
    };

    /**
     * Auto-Updates starten
     */
    CSVImportAdmin.startAutoUpdates = function() {
        if (!this.config.autoRefreshProgress) return;
        
        this.debug.debug('Starte Auto-Updates');
        
        // Progress-Updates bei laufendem Import
        if (this.status.importRunning) {
            this.startProgressUpdates();
        }
    };

    /**
     * Keyboard-Shortcuts registrieren
     */
    CSVImportAdmin.registerKeyboardShortcuts = function() {
        this.debug.debug('Registriere Keyboard-Shortcuts');
        
        $(document).on('keydown', (e) => {
            // Ctrl+Shift+D: Debug-Panel toggle
            if (e.ctrlKey && e.shiftKey && e.key === 'D') {
                e.preventDefault();
                this.toggleDebugPanel();
            }
            
            // Ctrl+Shift+R: Emergency Reset
            if (e.ctrlKey && e.shiftKey && e.key === 'R') {
                e.preventDefault();
                if (confirm('Emergency Reset durchführen?')) {
                    this.emergencyReset();
                }
            }
            
            // Ctrl+Shift+S: Scheduler Status
            if (e.ctrlKey && e.shiftKey && e.key === 'S') {
                e.preventDefault();
                this.getSchedulerStatus();
            }
        });
    };

    // ===================================================================
    // AJAX-FUNKTIONEN (ERWEITERT mit KORRIGIERTER Verbindung)
    // ===================================================================

    /**
     * Sichere AJAX-Request-Methode mit erweiterten Features
     */
    CSVImportAdmin.performAjaxRequest = function(data, options = {}) {
        const self = this;
        const requestId = Date.now() + Math.random();
        
        // Standard-Optionen
        const defaultOptions = {
            url: csvImportAjax.ajaxurl,
            type: 'POST',
            timeout: this.config.ajaxTimeout,
            data: $.extend({
                nonce: csvImportAjax.nonce,
                _request_id: requestId
            }, data),
            beforeSend: function(xhr) {
                self.state.performanceMetrics.ajaxCalls++;
                self.debug.debug(`AJAX-Request gestartet: ${data.action}`, { requestId });
            }
        };

        const requestOptions = $.extend(defaultOptions, options);
        
        // Request zur Queue hinzufügen
        this.state.ajaxQueue.push({
            id: requestId,
            action: data.action,
            startTime: Date.now()
        });

        const ajaxPromise = $.ajax(requestOptions);
        
        // Success-Handler
        ajaxPromise.done((response) => {
            this.handleAjaxSuccess(data.action, response, requestId);
        });
        
        // Error-Handler (KORRIGIERT)
        ajaxPromise.fail((xhr, status, error) => {
            this.handleAjaxError(data.action, xhr, status, error, requestId);
        });
        
        // Cleanup
        ajaxPromise.always(() => {
            this.cleanupAjaxRequest(requestId);
        });

        return ajaxPromise;
    };

    /**
     * AJAX-Erfolg-Handler
     */
    CSVImportAdmin.handleAjaxSuccess = function(action, response, requestId) {
        const request = this.state.ajaxQueue.find(req => req.id === requestId);
        const duration = request ? Date.now() - request.startTime : 0;
        
        this.debug.debug(`AJAX-Success: ${action} (${duration}ms)`, response);
        
        // Performance-Warnung bei langsamen Requests
        if (duration > 5000) {
            this.debug.warn(`Langsamer AJAX-Request: ${action} dauerte ${duration}ms`);
        }
        
        // --- KORRIGIERT: Verbindung bei Erfolg wiederherstellen ---
        if (this.status.connectionFailures > 0) {
            this.debug.log(`Verbindung wiederhergestellt nach ${this.status.connectionFailures} Fehlern.`);
            this.showTemporaryMessage('Verbindung wiederhergestellt', 'success');
        }
        this.status.connectionFailures = 0;
        this.updateConnectionStatus('online');
    };

    /**
     * AJAX-Fehler-Handler - STARK KORRIGIERT
     */
    CSVImportAdmin.handleAjaxError = function(action, xhr, status, error, requestId) {
        const request = this.state.ajaxQueue.find(req => req.id === requestId);
        const duration = request ? Date.now() - request.startTime : 0;
        
        this.state.performanceMetrics.errors++;
        this.state.lastError = {
            action,
            error,
            status: xhr.status,
            time: new Date().toISOString()
        };
        
        this.debug.error(`AJAX-Fehler: ${action}`, {
            error,
            status: xhr.status,
            response: xhr.responseText,
            duration
        });
        
        // --- KRITISCHE KORREKTUR: Nur echte Verbindungsfehler zählen ---
        const isRealConnectionError = (
            xhr.status === 0 ||           // Netzwerkfehler
            status === 'timeout' ||       // Timeout
            status === 'error' && xhr.status === 0 // Allgemeiner Netzwerkfehler
        );
        
        if (isRealConnectionError) {
            this.status.connectionFailures++;
            this.debug.warn(`Verbindungsfehler #${this.status.connectionFailures}: ${status} (${xhr.status})`);
            
            // Erst nach mehreren Fehlern als offline markieren
            if (this.status.connectionFailures >= this.config.connectionFailureThreshold) {
                this.updateConnectionStatus('offline');
            }
        } else {
            // HTTP-Fehler (404, 500, etc.) sind KEIN Verbindungsverlust
            this.debug.log(`HTTP-Fehler ignoriert: ${xhr.status} ${error}`);
        }
        
        // Retry-Logik
        const retryKey = `${action}_${requestId}`;
        this.state.retryCount[retryKey] = (this.state.retryCount[retryKey] || 0) + 1;
        
        if (this.state.retryCount[retryKey] <= this.config.maxRetries && this.shouldRetryRequest(xhr.status)) {
            this.debug.warn(`Wiederhole AJAX-Request: ${action} (Versuch ${this.state.retryCount[retryKey]})`);
            
            setTimeout(() => {
                this.retryAjaxRequest(action, requestId);
            }, this.config.retryDelay * this.state.retryCount[retryKey]);
        } else {
            this.handleFinalAjaxError(action, xhr, error);
        }
    };

    /**
     * Prüft ob ein Request wiederholt werden sollte
     */
    CSVImportAdmin.shouldRetryRequest = function(status) {
        // Retry bei Netzwerkfehlern, Timeouts und Server-Fehlern
        return [0, 408, 500, 502, 503, 504].includes(status);
    };

    /**
     * Finale Fehlerbehandlung nach gescheiterten Retries
     */
    CSVImportAdmin.handleFinalAjaxError = function(action, xhr, error) {
        let errorMessage = `${action} fehlgeschlagen`;
        
        if (xhr.status === 0) {
            errorMessage += '\n\nNetzwerkfehler - Internetverbindung prüfen';
            this.updateConnectionStatus('offline');
        } else if (xhr.status >= 500) {
            errorMessage += '\n\nServer-Fehler - Administrator kontaktieren';
        } else if (xhr.status === 403) {
            errorMessage += '\n\nKeine Berechtigung - Anmeldung prüfen';
        } else if (xhr.status === 404) {
            errorMessage += '\n\nAJAX-Handler nicht gefunden - Plugin-Installation prüfen';
        } else {
            errorMessage += `\n\nHTTP ${xhr.status}: ${error}`;
        }

        this.showAlert(errorMessage, 'error');
    };

    /**
     * AJAX-Request-Cleanup
     */
    CSVImportAdmin.cleanupAjaxRequest = function(requestId) {
        this.state.ajaxQueue = this.state.ajaxQueue.filter(req => req.id !== requestId);
    };

    // ===================================================================
    // STANDARD IMPORT-FUNKTIONEN (VOLLSTÄNDIG ERHALTEN)
    // ===================================================================

    /**
     * Konfiguration testen - Vollständig erhalten
     */
    CSVImportAdmin.testConfiguration = function() {
        if (this.status.validationInProgress) {
            this.debug.warn('Validierung bereits in Bearbeitung');
            return;
        }

        if (!this.elements.resultsContainer.length) {
            this.showAlert('Test-Interface nicht verfügbar. Seite neu laden.', 'error');
            return;
        }

        this.debug.log('Konfigurationstest gestartet');
        this.status.validationInProgress = true;

        // UI-Feedback
        this.showTestProgress('config', 'Konfiguration wird geprüft...');

        // AJAX-Request
        this.performAjaxRequest({
            action: 'csv_import_validate',
            type: 'config'
        })
        .done((response) => {
            this.handleValidationResult(response, 'config');
        })
        .fail((xhr, status, error) => {
            this.handleValidationError('Konfigurationstest', error, xhr);
        })
        .always(() => {
            this.status.validationInProgress = false;
        });
    };

    /**
     * CSV-Datei validieren - Vollständig erhalten
     */
    CSVImportAdmin.validateCSV = function(type) {
        if (this.status.validationInProgress) {
            this.debug.warn('Validierung bereits in Bearbeitung');
            return;
        }

        if (!type || !['dropbox', 'local'].includes(type)) {
            this.debug.error('Ungültiger CSV-Typ:', type);
            return;
        }

        if (!this.elements.resultsContainer.length) {
            this.showAlert('Validierungs-Interface nicht verfügbar. Seite neu laden.', 'error');
            return;
        }

        this.debug.log(`CSV-Validierung gestartet für: ${type}`);
        this.status.validationInProgress = true;

        const typeLabel = type.charAt(0).toUpperCase() + type.slice(1);

        // UI-Feedback
        this.showTestProgress(type, `${typeLabel} CSV wird validiert...`);
        this.showSampleDataProgress('Lade Beispieldaten...');

        // AJAX-Request
        this.performAjaxRequest({
            action: 'csv_import_validate',
            type: type
        })
        .done((response) => {
            this.handleValidationResult(response, type);
        })
        .fail((xhr, status, error) => {
            this.handleValidationError(`${typeLabel} CSV-Validierung`, error, xhr);
        })
        .always(() => {
            this.status.validationInProgress = false;
        });
    };

    /**
     * Import-Button-Click behandeln - Vollständig erhalten
     */
    CSVImportAdmin.handleImportClick = function($button) {
        const source = $button.data('source');

        if (!source) {
            this.debug.error('Import-Button ohne Datenquelle');
            this.showAlert('Import-Konfigurationsfehler', 'error');
            return;
        }

        if (this.status.importRunning) {
            this.showAlert('Ein Import läuft bereits. Bitte warten oder Reset durchführen.', 'warning');
            return;
        }

        // Bestätigung einholen
        const sourceLabel = source.charAt(0).toUpperCase() + source.slice(1);
        if (!confirm(`${sourceLabel} Import wirklich starten?\n\nDies kann mehrere Minuten dauern.`)) {
            return;
        }

        this.startImport(source);
    };

    /**
     * Import starten - Vollständig erhalten
     */
    CSVImportAdmin.startImport = function(source) {
        this.debug.log(`Import wird gestartet: ${source}`);

        // UI-Status ändern
        this.status.importRunning = true;
        this.updateUIState();
        this.setImportButtonsState(true, 'Import läuft...');

        // Mapping-Daten aus dem Formular sammeln
        const mappingData = {};
        $('#csv-column-mapping-container select').each(function() {
            const columnName = $(this).attr('name').replace(/csv_mapping\[|\]/g, '');
            const targetField = $(this).val();
            if (targetField) {
                mappingData[columnName] = targetField;
            }
        });

        // AJAX-Request (mit Mapping-Daten)
        this.performAjaxRequest({
            action: 'csv_import_start',
            source: source,
            mapping: mappingData
        })
        .done((response) => {
            this.handleImportResult(response, source);
        })
        .fail((xhr, status, error) => {
            this.handleImportError(source, error, xhr);
        })
        .always(() => {
            this.status.importRunning = false;
            this.updateUIState();
            this.setImportButtonsState(false);
        });

        // Progress-Updates starten
        this.startProgressUpdates();
    };

    // ===================================================================
    // SCHEDULER-FUNKTIONEN (VOLLSTÄNDIG ERHALTEN)
    // ===================================================================

    /**
     * Scheduler-Integration initialisieren
     */
    CSVImportAdmin.initializeSchedulerIntegration = function() {
        this.debug.log('Initialisiere Scheduler-Integration');
        
        // Scheduler-Status beim Start prüfen
        this.updateSchedulerStatus();
        
        // Periodische Status-Updates
        setInterval(() => {
            this.updateSchedulerStatus();
        }, 30000); // Alle 30 Sekunden
    };

    /**
     * Scheduler testen
     */
    CSVImportAdmin.testScheduler = function() {
        if (this.status.schedulerTestInProgress) {
            this.debug.warn('Scheduler-Test bereits in Bearbeitung');
            return;
        }

        this.debug.log('Scheduler-Test gestartet');
        this.status.schedulerTestInProgress = true;
        
        this.performAjaxRequest({
            action: 'csv_scheduler_test'
        })
        .done((response) => {
            if (response.success) {
                this.showAlert(
                    '✅ Scheduler-Test erfolgreich!\n\n' + 
                    (response.data.message || 'Test abgeschlossen') +
                    (response.data.test_time_formatted ? '\nTest-Zeit: ' + response.data.test_time_formatted : ''),
                    'success'
                );
                this.debug.log('Scheduler-Test erfolgreich', response.data);
            } else {
                this.showAlert(
                    '❌ Scheduler-Test fehlgeschlagen:\n' + response.data.message,
                    'error'
                );
                this.debug.error('Scheduler-Test fehlgeschlagen', response.data);
            }
        })
        .fail((xhr, status, error) => {
            this.handleFinalAjaxError('Scheduler-Test', xhr, error);
        })
        .always(() => {
            this.status.schedulerTestInProgress = false;
        });
    };

    /**
     * Scheduler-Status abrufen
     */
    CSVImportAdmin.getSchedulerStatus = function() {
        this.debug.log('Scheduler-Status wird abgerufen');
        
        this.performAjaxRequest({
            action: 'csv_scheduler_status'
        })
        .done((response) => {
            if (response.success) {
                const status = response.data;
                let statusMessage = '📊 Scheduler-Status:\n\n';
                
                statusMessage += 'Verfügbar: ' + (status.scheduler_available ? '✅ Ja' : '❌ Nein') + '\n';
                statusMessage += 'Geplant: ' + (status.is_scheduled ? '✅ Ja' : '❌ Nein') + '\n';
                
                if (status.is_scheduled) {
                    statusMessage += 'Quelle: ' + (status.current_source || 'Nicht gesetzt') + '\n';
                    statusMessage += 'Frequenz: ' + (status.current_frequency || 'Nicht gesetzt') + '\n';
                    
                    if (status.next_run) {
                        const nextRun = new Date(status.next_run * 1000);
                        statusMessage += 'Nächster Import: ' + nextRun.toLocaleString() + '\n';
                    }
                }
                
                statusMessage += '\nWP Cron: ' + (status.wp_cron_disabled ? '❌ Deaktiviert' : '✅ Aktiv') + '\n';
                statusMessage += 'Cron Jobs gesamt: ' + (status.total_cron_jobs || 0) + '\n';
                statusMessage += 'CSV Import Jobs: ' + (status.csv_import_cron_jobs || 0);
                
                this.showAlert(statusMessage, 'info');
                this.debug.log('Scheduler-Status erhalten', status);
                
                // UI-Elemente aktualisieren
                this.updateSchedulerUI(status);
            } else {
                this.showAlert(
                    '❌ Scheduler-Status konnte nicht abgerufen werden:\n' + response.data.message,
                    'error'
                );
            }
        })
        .fail((xhr, status, error) => {
            this.handleFinalAjaxError('Scheduler-Status', xhr, error);
        });
    };

    /**
     * Scheduler-Debug-Informationen abrufen
     */
    CSVImportAdmin.debugScheduler = function() {
        this.debug.log('Scheduler-Debug-Informationen werden abgerufen');
        
        this.performAjaxRequest({
            action: 'csv_scheduler_debug'
        })
        .done((response) => {
            if (response.success) {
                this.debug.group('🔧 CSV Import Scheduler Debug Info');
                console.log('Vollständige Debug-Daten:', response.data);
                
                const debug = response.data;
                
                // System-Info in Konsole
                if (debug.system) {
                    console.log('System Info:', debug.system);
                }
                
                // Plugin-Info in Konsole
                if (debug.plugin) {
                    console.log('Plugin Info:', debug.plugin);
                }
                
                // Scheduler-Info in Konsole
                if (debug.scheduler) {
                    console.log('Scheduler Info:', debug.scheduler);
                }
                
                // Cron-Info in Konsole
                if (debug.cron) {
                    console.log('Cron Info:', debug.cron);
                }
                
                // Options-Info in Konsole
                if (debug.options) {
                    console.log('Plugin Options:', debug.options);
                }
                
                this.debug.groupEnd();
                
                // Zusammenfassung für User
                const summary = this.generateDebugSummary(debug);
                this.showAlert(
                    '🔧 Debug-Informationen in Browser-Konsole verfügbar!\n\n' +
                    'Drücken Sie F12 und schauen Sie in die Konsole für detaillierte Informationen.\n\n' +
                    summary,
                    'info'
                );
            } else {
                this.showAlert(
                    '❌ Debug-Informationen konnten nicht abgerufen werden:\n' + response.data.message,
                    'error'
                );
            }
        })
        .fail((xhr, status, error) => {
            this.handleFinalAjaxError('Scheduler-Debug', xhr, error);
        });
    };

    /**
     * Generiert eine Zusammenfassung der Debug-Informationen
     */
    CSVImportAdmin.generateDebugSummary = function(debug) {
        let summary = '';
        
        // Plugin-Status
        if (debug.plugin) {
            const availableFunctions = Object.values(debug.plugin.functions_available || {}).filter(Boolean).length;
            const totalFunctions = Object.keys(debug.plugin.functions_available || {}).length;
            const availableClasses = Object.values(debug.plugin.classes_available || {}).filter(Boolean).length;
            const totalClasses = Object.keys(debug.plugin.classes_available || {}).length;
            
            summary += `Plugin-Status:\n`;
            summary += `- Funktionen: ${availableFunctions}/${totalFunctions} verfügbar\n`;
            summary += `- Klassen: ${availableClasses}/${totalClasses} verfügbar\n`;
        }
        
        // Scheduler-Status
        if (debug.scheduler) {
            summary += `\nScheduler-Status:\n`;
            summary += `- Klasse verfügbar: ${debug.plugin?.classes_available?.CSV_Import_Scheduler ? 'Ja' : 'Nein'}\n`;
            
            if (debug.scheduler.is_scheduled !== undefined) {
                summary += `- Geplant: ${debug.scheduler.is_scheduled ? 'Ja' : 'Nein'}\n`;
            }
        }
        
        // System-Status
        if (debug.system) {
            summary += `\nSystem-Status:\n`;
            summary += `- PHP: ${debug.system.php_version}\n`;
            summary += `- Memory: ${debug.system.memory_limit}\n`;
            summary += `- WP Cron: ${debug.system.wp_cron_disabled ? 'Deaktiviert' : 'Aktiv'}`;
        }
        
        return summary;
    };

    /**
     * Scheduler-Action-Handler
     */
    CSVImportAdmin.handleSchedulerAction = function(action, $button) {
        this.debug.log(`Scheduler-Action: ${action}`);
        
        switch(action) {
            case 'test':
                this.testScheduler();
                break;
            case 'status':
                this.getSchedulerStatus();
                break;
            case 'debug':
                this.debugScheduler();
                break;
            case 'enable':
                this.enableScheduler($button);
                break;
            case 'disable':
                this.disableScheduler($button);
                break;
            default:
                this.debug.warn('Unbekannte Scheduler-Action:', action);
        }
    };

    /**
     * Scheduler-Status periodisch aktualisieren
     */
    CSVImportAdmin.updateSchedulerStatus = function() {
        if (!this.config.enableSchedulerIntegration) return;
        
        this.performAjaxRequest({
            action: 'csv_scheduler_status',
            timeout: 10000
        })
        .done((response) => {
            if (response.success) {
                this.updateSchedulerUI(response.data);
            }
        })
        .fail(() => {
            // Stille Fehlerbehandlung für periodische Updates
            this.debug.debug('Scheduler-Status-Update fehlgeschlagen (wird ignoriert)');
        });
    };

    /**
     * Scheduler-UI aktualisieren
     */
    CSVImportAdmin.updateSchedulerUI = function(status) {
        // Status-Indikatoren aktualisieren
        $('.csv-scheduler-status').each(function() {
            const $element = $(this);
            
            if (status.scheduler_available) {
                $element.removeClass('status-error').addClass('status-ok');
                $element.find('.status-text').text('Verfügbar');
            } else {
                $element.removeClass('status-ok').addClass('status-error');
                $element.find('.status-text').text('Nicht verfügbar');
            }
        });
        
        // Scheduled-Status aktualisieren
        $('.csv-scheduled-status').each(function() {
            const $element = $(this);
            
            if (status.is_scheduled) {
                $element.removeClass('status-inactive').addClass('status-active');
                $element.find('.status-text').text('Aktiv');
            } else {
                $element.removeClass('status-active').addClass('status-inactive');
                $element.find('.status-text').text('Inaktiv');
            }
        });
        
        // Nächster Run aktualisieren
        if (status.next_run && status.is_scheduled) {
            const nextRun = new Date(status.next_run * 1000);
            $('.csv-next-run').text(nextRun.toLocaleString());
        } else {
            $('.csv-next-run').text('Nicht geplant');
        }
    };

    // ===================================================================
    // ERWEITERTE FUNKTIONEN (VOLLSTÄNDIG ERHALTEN)
    // ===================================================================

    /**
     * Emergency-Reset durchführen
     */
    CSVImportAdmin.emergencyReset = function() {
        if (!confirm('Emergency-Reset wirklich durchführen?\n\nDies setzt alle laufenden Prozesse zurück und löscht temporäre Daten.')) {
            return;
        }

        this.debug.warn('Emergency-Reset wird durchgeführt');
        
        this.performAjaxRequest({
            action: 'csv_import_emergency_reset'
        })
        .done((response) => {
            if (response.success) {
                const actions = response.data.actions_performed || [];
                this.showAlert(
                    '✅ Emergency-Reset erfolgreich!\n\n' +
                    'Durchgeführte Aktionen:\n• ' + actions.join('\n• '),
                    'success'
                );
                
                // UI zurücksetzen
                this.resetUIState();
                
                // Seite nach kurzer Verzögerung neu laden
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            } else {
                this.showAlert(
                    '❌ Emergency-Reset fehlgeschlagen:\n' + response.data.message,
                    'error'
                );
            }
        })
        .fail((xhr, status, error) => {
            this.handleFinalAjaxError('Emergency-Reset', xhr, error);
        });
    };

    /**
     * System-Health-Check durchführen
     */
    CSVImportAdmin.systemHealthCheck = function() {
        this.debug.log('System-Health-Check wird durchgeführt');
        
        this.performAjaxRequest({
            action: 'csv_import_system_health'
        })
        .done((response) => {
            if (response.success) {
                const health = response.data;
                const summary = this.generateHealthSummary(health);
                
                this.showAlert(
                    '🏥 System-Health-Check Ergebnis:\n\n' + summary,
                    health.overall_status.healthy ? 'success' : 'warning'
                );
                
                this.debug.log('System-Health-Check abgeschlossen', health);
                
                // Health-Status in UI anzeigen
                this.updateHealthUI(health);
            } else {
                this.showAlert(
                    '❌ System-Health-Check fehlgeschlagen:\n' + response.data.message,
                    'error'
                );
            }
        })
        .fail((xhr, status, error) => {
            this.handleFinalAjaxError('System-Health-Check', xhr, error);
        });
    };

    /**
     * Health-Summary generieren
     */
    CSVImportAdmin.generateHealthSummary = function(health) {
        let summary = '';
        
        // Gesamtstatus
        summary += `Gesamtstatus: ${health.overall_status.healthy ? '✅ Gesund' : '⚠️ Probleme erkannt'}\n\n`;
        
        // Plugin-Health
        if (health.plugin_health) {
            const healthyComponents = Object.values(health.plugin_health).filter(Boolean).length;
            const totalComponents = Object.keys(health.plugin_health).length;
            summary += `Plugin-Komponenten: ${healthyComponents}/${totalComponents} OK\n`;
        }
        
        // Import-Status
        if (health.import_status) {
            summary += `Import läuft: ${health.import_status.running ? 'Ja' : 'Nein'}\n`;
            summary += `Import gesperrt: ${health.import_status.locked ? 'Ja' : 'Nein'}\n`;
        }
        
        // Server-Status
        if (health.server_status) {
            summary += `Memory: ${health.server_status.memory_limit}\n`;
            summary += `PHP: ${health.server_status.php_version}\n`;
        }
        
        // Probleme auflisten
        if (health.overall_status.issues && health.overall_status.issues.length > 0) {
            summary += `\nProbleme:\n• ${health.overall_status.issues.join('\n• ')}`;
        }
        
        return summary;
    };

    /**
     * Handler-Verfügbarkeit prüfen
     */
    CSVImportAdmin.checkHandlers = function() {
        this.debug.log('Prüfe AJAX-Handler-Verfügbarkeit');
        
        this.performAjaxRequest({
            action: 'csv_import_check_handlers'
        })
        .done((response) => {
            if (response.success) {
                const data = response.data;
                let message = '🔧 AJAX-Handler-Status:\n\n';
                message += `Gesamt: ${data.available_count}/${data.total_handlers} verfügbar\n`;
                message += `Status: ${data.all_handlers_available ? '✅ Alle OK' : '⚠️ Teilweise verfügbar'}\n\n`;
                
                // Details in Konsole
                console.group('🔧 AJAX-Handler Details');
                console.table(data.handlers);
                console.groupEnd();
                
                message += 'Detaillierte Informationen in Browser-Konsole (F12)';
                
                this.showAlert(message, data.all_handlers_available ? 'success' : 'warning');
                this.status.handlerStatus = data.all_handlers_available ? 'available' : 'partial';
            } else {
                this.showAlert(
                    '❌ Handler-Check fehlgeschlagen:\n' + response.data.message,
                    'error'
                );
                this.status.handlerStatus = 'unavailable';
            }
        })
        .fail((xhr, status, error) => {
            this.handleFinalAjaxError('Handler-Check', xhr, error);
            this.status.handlerStatus = 'unavailable';
        });
    };

    // ===================================================================
    // CONNECTION-MONITORING (VOLLSTÄNDIG KORRIGIERT)
    // ===================================================================

    /**
     * Connection-Monitoring starten - STARK KORRIGIERT
     */
    CSVImportAdmin.startConnectionMonitoring = function() {
        this.debug.debug('Starte Connection-Monitoring (intelligente Version)');
        
        const self = this;
        
        // KORRIGIERT: Dynamische Intervalle je nach Situation
        const getCheckInterval = () => {
            if (self.status.importRunning) return 30000;      // 30s bei laufendem Import
            if (document.hidden) return 300000;              // 5min wenn Seite versteckt
            return self.config.connectionCheckInterval;      // 1min normal
        };
        
        // KORRIGIERT: Intelligente Scheduling-Funktion
        const scheduleNextCheck = () => {
            setTimeout(() => {
                self.checkConnection();
                scheduleNextCheck(); // Nächsten Check planen
            }, getCheckInterval());
        };
        
        // Erstes Check nach 10 Sekunden (nicht sofort)
        setTimeout(() => {
            scheduleNextCheck();
        }, 10000);
        
        // KORRIGIERT: Bei Visibility-Änderungen reagieren
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && self.status.connectionStatus === 'offline') {
                // Wenn Seite wieder sichtbar wird, nach kurzer Verzögerung prüfen
                setTimeout(() => self.checkConnection(), 2000);
            }
        });
        
        // WordPress Heartbeat Integration (falls verfügbar)
        this.setupHeartbeatIntegration();
    };

    /**
     * WordPress Heartbeat Integration - NEU
     */
    CSVImportAdmin.setupHeartbeatIntegration = function() {
        if (typeof wp !== 'undefined' && wp.heartbeat) {
            this.debug.log('WordPress Heartbeat Integration aktiviert');
            
            // Heartbeat erweitern
            $(document).on('heartbeat-send', (e, data) => {
                if (this.status.importRunning) {
                    data.csv_import_ping = {
                        import_running: true,
                        timestamp: Date.now()
                    };
                }
            });
            
            // Heartbeat Response überwachen
            $(document).on('heartbeat-tick', (e, data) => {
                if (data.csv_import_ping !== undefined) {
                    if (this.status.connectionFailures > 0) {
                        this.debug.log('Verbindung via Heartbeat wiederhergestellt.');
                        this.showTemporaryMessage('Verbindung wiederhergestellt', 'success');
                    }
                    this.status.connectionFailures = 0;
                    this.updateConnectionStatus('online');
                }
            });
            
            // Heartbeat Error überwachen
            $(document).on('heartbeat-connection-lost', () => {
                this.status.connectionFailures++;
                if (this.status.connectionFailures >= this.config.connectionFailureThreshold) {
                    this.updateConnectionStatus('offline');
                }
            });
            
            $(document).on('heartbeat-connection-restored', () => {
                this.debug.log('WordPress Heartbeat: Verbindung wiederhergestellt');
                this.status.connectionFailures = 0;
                this.updateConnectionStatus('online');
            });
        }
    };

    /**
     * Verbindung prüfen - VOLLSTÄNDIG KORRIGIERT
     */
    CSVImportAdmin.checkConnection = function() {
        const self = this;
        const now = Date.now();
        
        // KORRIGIERT: Nicht zu oft prüfen
        if (now - this.status.lastConnectionCheck < 30000) {
            return; // Mindestens 30 Sekunden zwischen Checks
        }
        
        // KORRIGIERT: Nur prüfen wenn Seite sichtbar oder Import läuft
        if (document.hidden && !this.status.importRunning) {
            return; // Keine Checks wenn Seite nicht sichtbar
        }
        
        this.status.lastConnectionCheck = now;
        
        // KORRIGIERT: WordPress Heartbeat nutzen falls verfügbar
        if (typeof wp !== 'undefined' && wp.heartbeat) {
            // WordPress Heartbeat ist robuster als eigene AJAX-Calls
            wp.heartbeat.enqueue('csv_import_ping', {
                timestamp: Date.now()
            });
            return;
        }
        
        // KORRIGIERT: Fallback mit robustem AJAX-Check
        this.performAjaxRequest({ 
            action: 'heartbeat' // Einfacher WordPress-Standard-Endpoint
        }, { 
            timeout: this.config.connectionTimeout,
            cache: false
        })
        .done(function() {
            // KORRIGIERT: Fehlerzähler bei Erfolg zurücksetzen
            if (self.status.connectionFailures > 0) {
                self.debug.log(`Verbindung wiederhergestellt nach ${self.status.connectionFailures} Fehlern.`);
                self.showTemporaryMessage('Verbindung wiederhergestellt', 'success');
            }
            self.status.connectionFailures = 0;
            self.updateConnectionStatus('online');
        })
        .fail(function(xhr, status, error) {
            // KRITISCHE KORREKTUR: Nur echte Netzwerkfehler behandeln
            const isRealConnectionError = (
                xhr.status === 0 ||           // Netzwerkfehler
                status === 'timeout' ||       // Timeout
                status === 'error' && xhr.status === 0 // Allgemeiner Netzwerkfehler
            );
            
            if (isRealConnectionError) {
                self.status.connectionFailures++;
                self.debug.warn(`Verbindungsfehler #${self.status.connectionFailures}: ${status} (${xhr.status})`);
                
                // KORRIGIERT: Erst nach mehreren Fehlern als offline markieren
                if (self.status.connectionFailures >= self.config.connectionFailureThreshold) {
                    self.updateConnectionStatus('offline');
                }
            } else {
                // HTTP-Fehler (404, 500, etc.) sind KEIN Verbindungsverlust
                self.debug.log(`HTTP-Fehler ignoriert: ${xhr.status} ${error}`);
            }
        });
    };

    /**
     * Connection-Status aktualisieren - KORRIGIERT
     */
    CSVImportAdmin.updateConnectionStatus = function(status) {
        // Nichts tun, wenn sich der Status nicht geändert hat
        if (this.status.connectionStatus === status) return;
        
        const previousStatus = this.status.connectionStatus;
        this.status.connectionStatus = status;
        this.debug.log(`Connection-Status: ${previousStatus} → ${status}`);
        
        // UI-Indikatoren aktualisieren (falls vorhanden)
        const indicator = $('.csv-connection-status');
        if (indicator.length) {
            indicator.removeClass('status-online status-offline').addClass(`status-${status}`);
            indicator.find('.status-text').text(status === 'online' ? 'Verbunden' : 'Nicht verbunden');
        }
        
        // KORRIGIERT: Dezente Warnungen statt störender Popups
        if (status === 'offline' && previousStatus === 'online') {
            this.showConnectionWarning();
        } else if (status === 'online' && previousStatus === 'offline') {
            this.hideConnectionWarning();
        }
    };

    /**
     * Dezente Verbindungswarnung - NEU
     */
    CSVImportAdmin.showConnectionWarning = function() {
        // Prüfen ob bereits eine Warnung angezeigt wird
        if ($('.csv-connection-warning').length > 0) {
            return;
        }
        
        const warningHtml = `
            <div class="notice notice-warning is-dismissible csv-connection-warning" style="margin: 15px 0;">
                <p>
                    <span class="dashicons dashicons-warning" style="color: #f56e28; margin-right: 5px;"></span>
                    <strong>Verbindung instabil:</strong> 
                    Die Serververbindung ist momentan instabil. Ihre Arbeit wird automatisch gespeichert.
                </p>
                <button type="button" class="notice-dismiss" onclick="jQuery('.csv-connection-warning').fadeOut();">
                    <span class="screen-reader-text">Schließen</span>
                </button>
            </div>
        `;
        
        // Warnung diskret einfügen
        if ($('.csv-dashboard-header').length) {
            $('.csv-dashboard-header').after(warningHtml);
        } else if ($('.wrap > h1').length) {
            $('.wrap > h1').after(warningHtml);
        } else {
            $('.wrap').prepend(warningHtml);
        }
    };

    /**
     * Verbindungswarnung entfernen - NEU
     */
    CSVImportAdmin.hideConnectionWarning = function() {
        $('.csv-connection-warning').fadeOut(300, function() {
            $(this).remove();
        });
    };

    /**
     * Temporäre Nachrichten anzeigen - NEU
     */
    CSVImportAdmin.showTemporaryMessage = function(message, type = 'info') {
        // Vorherige temporäre Nachrichten entfernen
        $('.csv-temp-message').remove();
        
        const messageHtml = `
            <div class="notice notice-${type} csv-temp-message" style="margin: 10px 0;">
                <p>${message}</p>
            </div>
        `;
        
        if ($('.csv-dashboard-header').length) {
            $('.csv-dashboard-header').after(messageHtml);
        } else if ($('.wrap > h1').length) {
            $('.wrap > h1').after(messageHtml);
        }
        
        // Nachricht nach 3 Sekunden automatisch entfernen
        setTimeout(() => {
            $('.csv-temp-message').fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    };

    // ===================================================================
    // PERFORMANCE-MONITORING (VOLLSTÄNDIG ERHALTEN)
    // ===================================================================

    /**
     * Performance-Monitoring initialisieren
     */
    CSVImportAdmin.initializePerformanceMonitoring = function() {
        this.debug.debug('Initialisiere Performance-Monitoring');
        
        // Performance-Daten regelmäßig loggen
        setInterval(() => {
            this.logPerformanceMetrics();
        }, 60000); // Jede Minute
        
        // Memory-Usage überwachen
        if (window.performance && window.performance.memory) {
            setInterval(() => {
                this.checkMemoryUsage();
            }, 30000); // Alle 30 Sekunden
        }
    };

    /**
     * Performance-Metriken loggen
     */
    CSVImportAdmin.logPerformanceMetrics = function() {
        const metrics = this.state.performanceMetrics;
        
        if (metrics.ajaxCalls > 0) {
            const successRate = (metrics.successfulCalls / metrics.ajaxCalls * 100).toFixed(1);
            
            this.debug.debug('Performance-Metriken', {
                ajaxCalls: metrics.ajaxCalls,
                successfulCalls: metrics.successfulCalls,
                errors: metrics.errors,
                successRate: `${successRate}%`,
                uptime: Date.now() - metrics.initTime
            });
            
            // Warnung bei schlechter Performance
            if (successRate < 90) {
                this.debug.warn(`Niedrige AJAX-Erfolgsrate: ${successRate}%`);
            }
        }
    };

    /**
     * Memory-Usage prüfen
     */
    CSVImportAdmin.checkMemoryUsage = function() {
        if (window.performance && window.performance.memory) {
            const memory = window.performance.memory;
            const usedMB = (memory.usedJSHeapSize / 1024 / 1024).toFixed(1);
            const limitMB = (memory.jsHeapSizeLimit / 1024 / 1024).toFixed(1);
            
            // Warnung bei hohem Memory-Verbrauch
            if (memory.usedJSHeapSize > memory.jsHeapSizeLimit * 0.9) {
                this.debug.warn(`Hoher Memory-Verbrauch: ${usedMB}MB von ${limitMB}MB`);
            }
        }
    };

    // ===================================================================
    // DEBUG-PANEL (VOLLSTÄNDIG ERHALTEN)
    // ===================================================================

    /**
     * Debug-Panel toggle
     */
    CSVImportAdmin.toggleDebugPanel = function() {
        if (!this.elements.debugPanel.length) {
            this.createDebugPanel();
        }
        
        this.elements.debugPanel.toggle();
        this.debug.log('Debug-Panel umgeschaltet');
    };

    /**
     * Debug-Panel erstellen
     */
    CSVImportAdmin.createDebugPanel = function() {
        const debugHtml = `
            <div id="csv-debug-panel" style="
                position: fixed; 
                top: 32px; 
                right: 20px; 
                width: 400px; 
                background: #fff; 
                border: 1px solid #ccc; 
                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
                z-index: 9999;
                padding: 15px;
                border-radius: 4px;
                font-family: monospace;
                font-size: 12px;
                display: none;
            ">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <strong>CSV Import Debug Panel v${this.version}</strong>
                    <button type="button" onclick="jQuery('#csv-debug-panel').hide()" style="border: none; background: none; font-size: 16px; cursor: pointer;">×</button>
                </div>
                <div id="csv-debug-content">
                    <div><strong>Status:</strong> <span id="csv-debug-status">-</span></div>
                    <div><strong>Connection:</strong> <span id="csv-debug-connection">-</span></div>
                    <div><strong>Handlers:</strong> <span id="csv-debug-handlers">-</span></div>
                    <div><strong>AJAX Calls:</strong> <span id="csv-debug-ajax">-</span></div>
                    <div><strong>Errors:</strong> <span id="csv-debug-errors">-</span></div>
                    <div><strong>Connection Failures:</strong> <span id="csv-debug-conn-failures">-</span></div>
                    <div style="margin-top: 10px;">
                        <button type="button" onclick="csvImportCheckHandlers()" class="button button-small">Check Handlers</button>
                        <button type="button" onclick="csvImportSystemHealth()" class="button button-small">System Health</button>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(debugHtml);
        this.elements.debugPanel = $('#csv-debug-panel');
        
        // Debug-Panel regelmäßig aktualisieren
        setInterval(() => {
            this.updateDebugPanel();
        }, 5000);
    };

    /**
     * Debug-Panel aktualisieren
     */
    CSVImportAdmin.updateDebugPanel = function() {
        if (!this.elements.debugPanel.is(':visible')) return;
        
        const metrics = this.state.performanceMetrics;
        
        $('#csv-debug-status').text(this.status.importRunning ? 'Import läuft' : 'Bereit');
        $('#csv-debug-connection').text(this.status.connectionStatus);
        $('#csv-debug-handlers').text(this.status.handlerStatus);
        $('#csv-debug-ajax').text(`${metrics.successfulCalls}/${metrics.ajaxCalls}`);
        $('#csv-debug-errors').text(metrics.errors);
        $('#csv-debug-conn-failures').text(this.status.connectionFailures);
    };

    // ===================================================================
    // VALIDATION FUNCTIONS (VOLLSTÄNDIG MIT MAPPING ERHALTEN)
    // ===================================================================

    /**
     * Validierungsergebnis verarbeiten (erweitert für Mapping)
     */
    CSVImportAdmin.handleValidationResult = function(response, type) {
        if (!response) {
            this.showTestResult('Keine Antwort vom Server erhalten', false);
            return;
        }

        const data = response.success ? response.data : (response.data || {});
        const message = data.message || (response.success ? 'Validierung erfolgreich' : 'Validierung fehlgeschlagen');

        // Test-Ergebnis anzeigen
        this.showTestResult(message, response.success);

        // Mapping-UI anzeigen
        if (response.success && data.columns && type !== 'config') {
            this.showColumnMappingUI(data.columns);
            this.showSampleData(data.columns, data.sample_data);
        } else {
            this.clearSampleData();
            this.clearColumnMappingUI();
        }

        this.debug.log(`Validierung ${type} abgeschlossen:`, {
            success: response.success,
            rows: data.rows,
            columns: data.columns ? data.columns.length : 0
        });
    };

    /**
     * Erstellt und zeigt die Spalten-Mapping-Tabelle an.
     */
    CSVImportAdmin.showColumnMappingUI = function(columns) {
        const mappingContainer = $('#csv-column-mapping-container');
        if (!mappingContainer.length) {
            this.debug.warn('Mapping-Container (#csv-column-mapping-container) nicht gefunden.');
            return;
        }

        // WordPress-Zielfelder
        const targetFields = ['post_title', 'post_content', 'post_excerpt', 'post_name', 'featured_image', 'benutzerdefiniertes_feld_1', 'benutzerdefiniertes_feld_2'];

        let tableHtml = `
            <h4>2. Spalten zuordnen</h4>
            <p>Weisen Sie jeder Spalte aus Ihrer CSV-Datei ein WordPress-Feld zu.</p>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Spalte aus Ihrer CSV</th>
                        <th>WordPress-Feld</th>
                    </tr>
                </thead>
                <tbody>
        `;

        columns.forEach(column => {
            let optionsHtml = '<option value="">-- Ignorieren --</option>';
            targetFields.forEach(field => {
                const isSelected = column.toLowerCase().replace(/ /g, '_') === field.toLowerCase() ? 'selected' : '';
                optionsHtml += `<option value="${this.escapeHtml(field)}" ${isSelected}>${this.escapeHtml(field)}</option>`;
            });

            tableHtml += `
                <tr>
                    <td><strong>${this.escapeHtml(column)}</strong></td>
                    <td>
                        <select name="csv_mapping[${this.escapeHtml(column)}]">
                            ${optionsHtml}
                        </select>
                    </td>
                </tr>
            `;
        });

        tableHtml += '</tbody></table>';
        mappingContainer.html(tableHtml).show();
    };

    /**
     * Versteckt und leert die Mapping-Tabelle.
     */
    CSVImportAdmin.clearColumnMappingUI = function() {
        const mappingContainer = $('#csv-column-mapping-container');
        if (mappingContainer.length) {
            mappingContainer.empty().hide();
        }
    };

    /**
     * Validierungsfehler behandeln (erweitert)
     */
    CSVImportAdmin.handleValidationError = function(operation, error, xhr) {
        this.debug.error(`${operation} fehlgeschlagen`, {
            error: error,
            status: xhr ? xhr.status : 'unknown',
            response: xhr ? xhr.responseText : 'no response'
        });

        let errorMessage = `${operation} fehlgeschlagen`;
        
        if (xhr && xhr.status) {
            if (xhr.status === 0) {
                errorMessage += ': Netzwerkfehler - Internetverbindung prüfen';
            } else if (xhr.status >= 500) {
                errorMessage += ': Server-Fehler - Administrator kontaktieren';
            } else if (xhr.status === 403) {
                errorMessage += ': Keine Berechtigung - Anmeldung prüfen';
            } else if (xhr.status === 404) {
                errorMessage += ': AJAX-Handler nicht gefunden - Plugin-Installation prüfen';
            } else {
                errorMessage += `: HTTP ${xhr.status}`;
            }
        } else {
            errorMessage += ': ' + (error || 'Unbekannter Fehler');
        }

        this.showTestResult(errorMessage, false);
        this.clearSampleData();
    };

    /**
     * Import-Ergebnis verarbeiten (erweitert)
     */
    CSVImportAdmin.handleImportResult = function(response, source) {
        if (response.success) {
            const processed = response.data.processed || 0;
            const total = response.data.total || 0;
            const errors = response.data.errors || 0;

            let message = `Import erfolgreich abgeschlossen!\n\n`;
            message += `Verarbeitet: ${processed} von ${total} Einträgen\n`;
            if (errors > 0) {
                message += `Fehler: ${errors}\n`;
            }
            
            // Performance-Info hinzufügen falls verfügbar
            if (response.data.execution_time) {
                message += `Ausführungszeit: ${response.data.execution_time}s\n`;
            }
            
            message += `\nSeite wird neu geladen...`;

            this.showAlert(message, 'success');
            
            // Nach kurzem Delay Seite neu laden
            setTimeout(() => {
                window.location.reload();
            }, 5000);

        } else {
            const errorMsg = response.data?.message || response.message || 'Unbekannter Import-Fehler';
            this.showAlert(`Import fehlgeschlagen:\n${errorMsg}`, 'error');
        }

        this.debug.log(`Import ${source} beendet:`, response);
    };

    /**
     * Import-Fehler behandeln (erweitert)
     */
    CSVImportAdmin.handleImportError = function(source, error, xhr) {
        this.debug.error(`Import ${source} fehlgeschlagen`, {
            error: error,
            status: xhr?.status,
            response: xhr?.responseText
        });

        let errorMessage = `Import fehlgeschlagen`;
        
        if (xhr?.status === 0) {
            errorMessage += `\n\nNetzwerkfehler - möglicherweise ist der Import noch aktiv.\nBitte warten oder Reset durchführen.`;
        } else if (xhr?.status >= 500) {
            errorMessage += `\n\nServer-Fehler. Import möglicherweise abgebrochen.\nBitte Logs prüfen oder Administrator kontaktieren.`;
        } else if (xhr?.status === 413) {
            errorMessage += `\n\nDatei zu groß für Server.\nBitte CSV-Datei verkleinern oder Server-Limits erhöhen.`;
        } else if (xhr?.status === 408) {
            errorMessage += `\n\nTimeout beim Import.\nBitte kleinere Batch-Größe verwenden.`;
        } else {
            errorMessage += `\n\n${error || 'Unbekannter Fehler'}`;
        }

        this.showAlert(errorMessage, 'error');
    };

    // ===================================================================
    // UI-UPDATES & HILFSFUNKTIONEN (VOLLSTÄNDIG ERHALTEN)
    // ===================================================================

    /**
     * UI-Status entsprechend aktualisieren
     */
    CSVImportAdmin.updateUIState = function() {
        if (this.status.importRunning) {
            this.setImportButtonsState(true, 'Import läuft...');
            this.startProgressUpdates();
        } else {
            this.setImportButtonsState(false);
            this.stopProgressUpdates();
        }
        
        // Status-Indikatoren aktualisieren
        this.updateStatusIndicators();
    };

    /**
     * UI-Status zurücksetzen
     */
    CSVImportAdmin.resetUIState = function() {
        this.status.importRunning = false;
        this.status.validationInProgress = false;
        this.status.schedulerTestInProgress = false;
        
        this.setImportButtonsState(false);
        this.stopProgressUpdates();
        this.clearSampleData();
        
        if (this.elements.resultsContainer.length) {
            this.elements.resultsContainer.html('<div class="test-result">Status wurde zurückgesetzt</div>');
        }
    };

    /**
     * Status-Indikatoren aktualisieren
     */
    CSVImportAdmin.updateStatusIndicators = function() {
        // Connection-Status
        $('.csv-connection-indicator').each((index, element) => {
            const $element = $(element);
            $element.removeClass('status-online status-offline');
            $element.addClass(`status-${this.status.connectionStatus}`);
        });
        
        // Handler-Status
        $('.csv-handler-indicator').each((index, element) => {
            const $element = $(element);
            $element.removeClass('status-available status-partial status-unavailable');
            $element.addClass(`status-${this.status.handlerStatus}`);
        });
    };

    /**
     * Import-Button-Zustand setzen (erweitert)
     */
    CSVImportAdmin.setImportButtonsState = function(disabled, text) {
        this.elements.importButtons.each(function() {
            const $btn = $(this);
            const source = $btn.data('source');
            
            if (disabled) {
                $btn.prop('disabled', true);
                $btn.text(text || '🔄 Import läuft...');
                $btn.addClass('button-primary-disabled');
            } else {
                $btn.prop('disabled', false);
                $btn.removeClass('button-primary-disabled');
                
                if (source) {
                    const sourceLabel = source.charAt(0).toUpperCase() + source.slice(1);
                    $btn.text(`🚀 ${sourceLabel} Import starten`);
                }
            }
        });
    };

    /**
     * Alert-Dialog anzeigen (erweitert mit eleganten Benachrichtigungen)
     */
    CSVImportAdmin.showAlert = function(message, type = 'info') {
        // KORRIGIERT: Elegantere Benachrichtigungen statt störender Alerts
        if (type === 'success') {
            this.showTemporaryMessage('✅ ' + message, 'success');
        } else if (type === 'error') {
            this.showTemporaryMessage('❌ ' + message, 'error');
        } else if (type === 'warning') {
            this.showTemporaryMessage('⚠️ ' + message, 'warning');
        } else {
            this.showTemporaryMessage('ℹ️ ' + message, 'info');
        }
        
        // Fallback für kritische Meldungen
        if (type === 'error' && message.includes('kritisch')) {
            alert(`[${type.toUpperCase()}] ${message}`);
        }
    };

    /**
     * Window-Focus-Handler (KORRIGIERT)
     */
    CSVImportAdmin.handleWindowFocus = function() {
        this.debug.debug('Window Focus - prüfe Status');

        // Status bei Focus aktualisieren
        if (this.status.importRunning) {
            this.updateProgress();
        }

        // KORRIGIERT: Nur Connection-Check, kein automatischer Health-Check
        this.checkConnection();
    };

    /**
     * Window-Blur-Handler
     */
    CSVImportAdmin.handleWindowBlur = function() {
        this.debug.debug('Window Blur');
        // Optional: Reduziere Updates wenn Window nicht im Focus
    };

    /**
     * Seite neu laden
     */
    CSVImportAdmin.handlePageRefresh = function() {
        this.debug.log('Seite wird neu geladen');
        
        // Cleanup vor Reload
        this.cleanup();
        
        window.location.reload();
    };

    /**
     * Cleanup bei Seitenverlassen
     */
    CSVImportAdmin.cleanup = function() {
        this.debug.log('Cleanup wird durchgeführt');
        
        this.stopProgressUpdates();
        
        // Alle Intervals bereinigen
        clearInterval(this.healthCheckInterval);
        clearInterval(this.connectionCheckInterval);
        
        // Event-Listener entfernen
        $(window).off('beforeunload focus blur');
        $(document).off('keydown');
    };

    // ===================================================================
    // PROGRESS-UPDATES (VOLLSTÄNDIG ERHALTEN)
    // ===================================================================

    /**
     * Progress-Updates starten (erweitert)
     */
    CSVImportAdmin.startProgressUpdates = function() {
        if (this.status.progressUpdateInterval) {
            clearInterval(this.status.progressUpdateInterval);
        }

        this.debug.log('Progress-Updates gestartet');

        this.status.progressUpdateInterval = setInterval(() => {
            this.updateProgress();
        }, this.config.progressUpdateInterval);

        // Sofortiges erstes Update
        this.updateProgress();
    };

    /**
     * Progress-Updates stoppen
     */
    CSVImportAdmin.stopProgressUpdates = function() {
        if (this.status.progressUpdateInterval) {
            clearInterval(this.status.progressUpdateInterval);
            this.status.progressUpdateInterval = null;
            this.debug.log('Progress-Updates gestoppt');
        }
    };

    /**
     * Progress aktualisieren (erweitert)
     */
    CSVImportAdmin.updateProgress = function() {
        const now = Date.now();
        
        // Throttling: Nicht öfter als alle 2 Sekunden
        if (now - this.status.lastProgressUpdate < 2000) {
            return;
        }
        
        this.status.lastProgressUpdate = now;
        
        this.performAjaxRequest({
            action: 'csv_import_get_progress_extended',
            timeout: 10000
        })
        .done((response) => {
            if (response.success) {
                this.handleProgressUpdate(response.data);
            } else {
                this.debug.warn('Progress-Update fehlgeschlagen:', response);
            }
        })
        .fail(() => {
            this.debug.debug('Progress-AJAX fehlgeschlagen (wird ignoriert)');
        });
    };

    /**
     * Progress-Update verarbeiten (erweitert)
     */
    CSVImportAdmin.handleProgressUpdate = function(progressData) {
        if (!progressData) return;

        const isRunning = progressData.running || false;
        const percent = progressData.percent || 0;
        const message = progressData.message || '';

        // Status aktualisieren
        if (this.status.importRunning !== isRunning) {
            this.status.importRunning = isRunning;
            this.updateUIState();
        }

        // Progress-Bar aktualisieren
        if (this.elements.progressBar.length) {
            this.elements.progressBar.css('width', percent + '%');
            this.elements.progressBar.attr('aria-valuenow', percent);
        }

        // Progress-Notice aktualisieren
        if (this.elements.progressNotice.length) {
            if (isRunning) {
                this.elements.progressNotice.show();
                this.elements.progressNotice.find('.progress-message').text(message);
                
                // ETA anzeigen falls verfügbar
                if (progressData.eta_human) {
                    this.elements.progressNotice.find('.progress-eta').text(`ETA: ${progressData.eta_human}`);
                }
            } else {
                this.elements.progressNotice.hide();
                this.stopProgressUpdates();
            }
        }

        // Memory-Warning bei hohem Verbrauch
        if (progressData.memory_usage && progressData.memory_peak) {
            const memoryMB = Math.round(progressData.memory_usage / 1024 / 1024);
            const peakMB = Math.round(progressData.memory_peak / 1024 / 1024);
            
            if (memoryMB > 200) { // 200MB Warnung
                this.debug.warn(`Hoher Memory-Verbrauch beim Import: ${memoryMB}MB (Peak: ${peakMB}MB)`);
            }
        }

        // Import-Status verfolgen
        if (!isRunning && this.status.importRunning) {
            this.debug.log('Import abgeschlossen laut Progress-Update');
            this.status.importRunning = false;
            this.setImportButtonsState(false);
        }
    };

    // ===================================================================
    // UI-HILFSFUNKTIONEN (VOLLSTÄNDIG ERHALTEN)
    // ===================================================================

    /**
     * Test-Progress anzeigen (erweitert)
     */
    CSVImportAdmin.showTestProgress = function(type, message) {
        if (!this.elements.resultsContainer.length) return;

        const progressHtml = `
            <div class="test-result test-progress">
                <div class="progress-spinner"></div>
                🔄 ${message}
            </div>
        `;
        this.elements.resultsContainer.html(progressHtml);
    };

    /**
     * Test-Ergebnis anzeigen (erweitert)
     */
    CSVImportAdmin.showTestResult = function(message, success) {
        if (!this.elements.resultsContainer.length) return;

        const resultClass = success ? 'test-success' : 'test-error';
        const icon = success ? '✅' : '❌';
        const timestamp = new Date().toLocaleTimeString();
        
        const resultHtml = `
            <div class="test-result ${resultClass}">
                ${icon} ${message}
                <div class="test-timestamp">${timestamp}</div>
            </div>
        `;
        this.elements.resultsContainer.html(resultHtml);
    };

    /**
     * Sample-Data-Progress anzeigen
     */
    CSVImportAdmin.showSampleDataProgress = function(message) {
        if (!this.elements.sampleDataContainer.length) return;

        const progressHtml = `<div class="test-result test-progress">🔄 ${message}</div>`;
        this.elements.sampleDataContainer.html(progressHtml);
    };

    /**
     * Beispieldaten anzeigen (erweitert)
     */
    CSVImportAdmin.showSampleData = function(columns, sampleData) {
        if (!this.elements.sampleDataContainer.length || !columns || !sampleData) return;

        try {
            // Maximale Anzahl anzuzeigender Spalten (für bessere Darstellung)
            const maxCols = 5;
            const displayColumns = columns.slice(0, maxCols);
            const hasMoreCols = columns.length > maxCols;

            let tableHtml = `
                <div class="csv-sample-data-wrapper">
                    <div class="sample-data-header">
                        <h4>📊 Beispieldaten</h4>
                        <span class="sample-info">${sampleData.length} Zeilen, ${columns.length} Spalten</span>
                    </div>
                    <div class="table-responsive">
                        <table class="wp-list-table widefat striped sample-data-table">
                            <thead>
                                <tr>
                                    ${displayColumns.map(col => `<th>${this.escapeHtml(col)}</th>`).join('')}
                                    ${hasMoreCols ? '<th class="more-cols">...</th>' : ''}
                                </tr>
                            </thead>
                            <tbody>
            `;

            sampleData.forEach((row, index) => {
                if (Array.isArray(row)) {
                    const displayRow = row.slice(0, maxCols);
                    tableHtml += `
                        <tr>
                            ${displayRow.map(cell => `<td>${this.escapeHtml(String(cell || ''))}</td>`).join('')}
                            ${hasMoreCols ? '<td class="more-cols">...</td>' : ''}
                        </tr>
                    `;
                }
            });

            tableHtml += `
                            </tbody>
                        </table>
                    </div>
                </div>
            `;

            if (hasMoreCols) {
                tableHtml += `<p class="description">Zeige ${maxCols} von ${columns.length} Spalten zur Vorschau</p>`;
            }

            this.elements.sampleDataContainer.html(tableHtml);

        } catch (error) {
            this.debug.error('Fehler beim Anzeigen der Beispieldaten:', error);
            this.elements.sampleDataContainer.html('<div class="test-result test-error">❌ Fehler beim Laden der Beispieldaten</div>');
        }
    };

    /**
     * Beispieldaten löschen
     */
    CSVImportAdmin.clearSampleData = function() {
        if (this.elements.sampleDataContainer.length) {
            this.elements.sampleDataContainer.empty();
        }
    };

    /**
     * HTML escapen für Sicherheit
     */
    CSVImportAdmin.escapeHtml = function(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    };

    /**
     * Globalen Fehler anzeigen (erweitert)
     */
    CSVImportAdmin.showGlobalError = function(message) {
        const errorHtml = `
            <div class="notice notice-error is-dismissible global-csv-error">
                <p><strong>CSV Import Pro:</strong> ${message}</p>
                <button type="button" class="notice-dismiss" onclick="jQuery('.global-csv-error').remove();">
                    <span class="screen-reader-text">Dismiss this notice.</span>
                </button>
            </div>
        `;
        
        if ($('.wrap').length) {
            $('.wrap').prepend(errorHtml);
        } else {
            $('body').prepend(errorHtml);
        }
        
        // Auto-Remove nach 10 Sekunden
        setTimeout(() => {
            $('.global-csv-error').fadeOut();
        }, 10000);
    };

    /**
     * Form-Field-Validierung in Echtzeit
     */
    CSVImportAdmin.validateFormField = function($field) {
        const value = $field.val();
        const fieldType = $field.attr('type') || $field.prop('tagName').toLowerCase();
        const fieldName = $field.attr('name') || $field.attr('id');
        
        // Entferne vorherige Validierungsklassen
        $field.removeClass('validation-error validation-success');
        
        let isValid = true;
        let errorMessage = '';
        
        // Validierung basierend auf Feldtyp
        switch(fieldType) {
            case 'url':
                if (value && !this.isValidUrl(value)) {
                    isValid = false;
                    errorMessage = 'Ungültige URL-Format';
                }
                break;
            case 'number':
                if (value && isNaN(value)) {
                    isValid = false;
                    errorMessage = 'Muss eine Zahl sein';
                }
                break;
            case 'email':
                if (value && !this.isValidEmail(value)) {
                    isValid = false;
                    errorMessage = 'Ungültige E-Mail-Adresse';
                }
                break;
        }
        
        // Required-Felder prüfen
        if ($field.prop('required') && !value) {
            isValid = false;
            errorMessage = 'Dieses Feld ist erforderlich';
        }
        
        // CSV-spezifische Validierungen
        if (fieldName && fieldName.includes('csv_import_')) {
            if (fieldName.includes('_path') && value) {
                // Pfad-Validierung
                if (value.includes('..') || value.startsWith('/')) {
                    isValid = false;
                    errorMessage = 'Unsicherer Pfad erkannt';
                }
            }
        }
        
        // Validierungsklasse hinzufügen
        $field.addClass(isValid ? 'validation-success' : 'validation-error');
        
        // Error-Message anzeigen/verstecken
        const $errorMsg = $field.siblings('.validation-message');
        if (!isValid && errorMessage) {
            if ($errorMsg.length) {
                $errorMsg.text(errorMessage).show();
            } else {
                $field.after(`<span class="validation-message error">${errorMessage}</span>`);
            }
        } else {
            $errorMsg.hide();
        }
        
        return isValid;
    };

    /**
     * URL-Validierung
     */
    CSVImportAdmin.isValidUrl = function(string) {
        try {
            new URL(string);
            return true;
        } catch (_) {
            return false;
        }
    };

    /**
     * E-Mail-Validierung
     */
    CSVImportAdmin.isValidEmail = function(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    };

    /**
     * Health-UI aktualisieren
     */
    CSVImportAdmin.updateHealthUI = function(health) {
        if (!health) return;
        
        // Gesamtstatus-Indikator aktualisieren
        $('.csv-health-overall').each(function() {
            const $element = $(this);
            const isHealthy = health.overall_status && health.overall_status.healthy;
            
            $element.removeClass('health-good health-warning health-error');
            $element.addClass(isHealthy ? 'health-good' : 'health-warning');
            
            const statusText = isHealthy ? 'System gesund' : `${health.overall_status.issues_count} Probleme`;
            $element.find('.health-text').text(statusText);
        });
        
        // Einzelne Health-Checks aktualisieren
        if (health.system_health) {
            Object.keys(health.system_health).forEach(check => {
                const status = health.system_health[check];
                $(`.health-check-${check}`).each(function() {
                    const $element = $(this);
                    $element.removeClass('check-ok check-error');
                    $element.addClass(status ? 'check-ok' : 'check-error');
                });
            });
        }
    };

    // ===================================================================
    // GLOBALE ERROR-HANDLER (VOLLSTÄNDIG ERHALTEN)
    // ===================================================================

    /**
     * Globaler AJAX-Error-Handler
     */
    CSVImportAdmin.handleGlobalAjaxError = function(event, xhr, settings, error) {
        this.state.performanceMetrics.errors++;
        
        this.debug.error('Globaler AJAX-Fehler erkannt', {
            url: settings.url,
            action: settings.data?.action,
            error: error,
            status: xhr.status,
            response: xhr.responseText?.substring(0, 200) // Nur erste 200 Zeichen
        });
        
        // Bei kritischen Fehlern Health-Check triggern (KORRIGIERT: Nicht automatisch)
        if (xhr.status >= 500) {
            this.debug.warn('Kritischer Server-Fehler erkannt - Health-Check empfohlen');
        }
    };

    // ===================================================================
    // ÖFFENTLICHE API & ABSCHLUSS (VOLLSTÄNDIG ERHALTEN)
    // ===================================================================

    // CSVImportAdmin global verfügbar machen
    window.CSVImportAdmin = CSVImportAdmin;
    
    // Version und Debug-Informationen
    CSVImportAdmin.getVersion = function() {
        return this.version;
    };

    CSVImportAdmin.getStatus = function() {
        return {
            version: this.version,
            initialized: this.state.initialized,
            status: this.status,
            performanceMetrics: this.state.performanceMetrics,
            lastError: this.state.lastError
        };
    };

    CSVImportAdmin.getDebugInfo = function() {
        return {
            version: this.version,
            elements: Object.keys(this.elements).reduce((acc, key) => {
                acc[key] = this.elements[key].length;
                return acc;
            }, {}),
            status: this.status,
            config: this.config,
            state: this.state,
            metrics: this.state.performanceMetrics
        };
    };

    // Erfolgreiche Ladung loggen
    CSVImportAdmin.debug.log(`CSV Import Admin Script geladen (Version ${CSVImportAdmin.version})`);

})(jQuery);

// ===================================================================
// LEGACY-SUPPORT & GLOBALE FUNKTIONEN (VOLLSTÄNDIG ERHALTEN)
// ===================================================================

// WordPress Heartbeat Integration für robuste Verbindungsüberwachung
$(document).ready(function() {
    // WordPress Heartbeat für CSV Import erweitern
    if (typeof wp !== 'undefined' && wp.heartbeat) {
        
        // KORRIGIERT: Heartbeat-Frequenz anpassen
        wp.heartbeat.interval('standard'); // Nicht zu aggressiv
        
        // CSV Import Ping-Handler
        $(document).on('heartbeat-send', function(e, data) {
            if (window.CSVImportAdmin && window.CSVImportAdmin.status.importRunning) {
                data.csv_import_ping = {
                    import_running: true,
                    timestamp: Date.now()
                };
            }
        });
        
        // Heartbeat-Probleme behandeln
        $(document).on('heartbeat-connection-lost', function() {
            if (window.CSVImportAdmin) {
                window.CSVImportAdmin.debug.warn('WordPress Heartbeat: Verbindung verloren');
                // Wird von checkConnection() behandelt
            }
        });
        
        $(document).on('heartbeat-connection-restored', function() {
            if (window.CSVImportAdmin) {
                window.CSVImportAdmin.debug.log('WordPress Heartbeat: Verbindung wiederhergestellt');
                window.CSVImportAdmin.status.connectionFailures = 0;
                window.CSVImportAdmin.updateConnectionStatus('online');
            }
        });
    }
});

// ===================================================================
// BACKUP-FUNKTIONEN FÜR LEGACY-SUPPORT (VOLLSTÄNDIG ERHALTEN)
// ===================================================================

// Backup für alte globale Funktionen (falls Templates diese noch verwenden)
if (typeof window.csvImportTestConfig === 'undefined') {
    window.csvImportTestConfig = function() {
        console.warn('Legacy-Funktion aufgerufen - Plugin möglicherweise nicht korrekt initialisiert');
        if (window.CSVImportAdmin && window.CSVImportAdmin.testConfiguration) {
            window.CSVImportAdmin.testConfiguration();
        }
    };
}

if (typeof window.csvImportValidateCSV === 'undefined') {
    window.csvImportValidateCSV = function(type) {
        console.warn('Legacy-Funktion aufgerufen - Plugin möglicherweise nicht korrekt initialisiert');
        if (window.CSVImportAdmin && window.CSVImportAdmin.validateCSV) {
            window.CSVImportAdmin.validateCSV(type);
        }
    };
}

if (typeof window.csvImportSystemHealth === 'undefined') {
    window.csvImportSystemHealth = function() {
        console.warn('Legacy-Funktion aufgerufen - Plugin möglicherweise nicht korrekt initialisiert');
        if (window.CSVImportAdmin && window.CSVImportAdmin.systemHealthCheck) {
            window.CSVImportAdmin.systemHealthCheck();
        }
    };
}

if (typeof window.csvImportCheckHandlers === 'undefined') {
    window.csvImportCheckHandlers = function() {
        console.warn('Legacy-Funktion aufgerufen - Plugin möglicherweise nicht korrekt initialisiert');
        if (window.CSVImportAdmin && window.CSVImportAdmin.checkHandlers) {
            window.CSVImportAdmin.checkHandlers();
        }
    };
}

// ===================================================================
// DEBUG-KONSOLE-BEFEHLE FÜR ENTWICKLER (VOLLSTÄNDIG ERHALTEN)
// ===================================================================

if (typeof console !== 'undefined') {
    window.csvDebug = {
        // Status-Funktionen
        status: () => window.CSVImportAdmin?.getStatus(),
        info: () => window.CSVImportAdmin?.getDebugInfo(),
        version: () => window.CSVImportAdmin?.getVersion(),
        
        // Test-Funktionen
        test: () => window.CSVImportAdmin?.testConfiguration(),
        validateDropbox: () => window.CSVImportAdmin?.validateCSV('dropbox'),
        validateLocal: () => window.CSVImportAdmin?.validateCSV('local'),
        
        // Scheduler-Funktionen
        scheduler: () => window.CSVImportAdmin?.getSchedulerStatus(),
        schedulerTest: () => window.CSVImportAdmin?.testScheduler(),
        schedulerDebug: () => window.CSVImportAdmin?.debugScheduler(),
        
        // System-Funktionen
        health: () => window.CSVImportAdmin?.systemHealthCheck(),
        handlers: () => window.CSVImportAdmin?.checkHandlers(),
        reset: () => window.CSVImportAdmin?.emergencyReset(),
        
        // Connection-Funktionen
        connection: () => {
            if (window.CSVImportAdmin) {
                console.log('Connection Status:', window.CSVImportAdmin.status.connectionStatus);
                console.log('Connection Failures:', window.CSVImportAdmin.status.connectionFailures);
                console.log('Last Check:', new Date(window.CSVImportAdmin.status.lastConnectionCheck));
                console.log('Handler Status:', window.CSVImportAdmin.status.handlerStatus);
            }
        },
        
        // Debug-Panel-Funktionen
        panel: () => window.CSVImportAdmin?.toggleDebugPanel(),
        
        // Performance-Funktionen
        performance: () => {
            if (window.CSVImportAdmin) {
                const metrics = window.CSVImportAdmin.state.performanceMetrics;
                console.group('📊 CSV Import Performance Metrics');
                console.log('AJAX Calls:', metrics.ajaxCalls);
                console.log('Successful Calls:', metrics.successfulCalls);
                console.log('Errors:', metrics.errors);
                console.log('Success Rate:', ((metrics.successfulCalls / metrics.ajaxCalls) * 100).toFixed(1) + '%');
                console.log('Uptime:', (Date.now() - metrics.initTime) + 'ms');
                console.groupEnd();
            }
        },
        
        // Hilfsfunktionen
        help: () => {
            console.group('🔧 CSV Import Debug Commands');
            console.log('csvDebug.status() - Get current status');
            console.log('csvDebug.info() - Get debug info');
            console.log('csvDebug.test() - Test configuration');
            console.log('csvDebug.health() - System health check');
            console.log('csvDebug.scheduler() - Scheduler status');
            console.log('csvDebug.connection() - Connection info');
            console.log('csvDebug.performance() - Performance metrics');
            console.log('csvDebug.reset() - Emergency reset');
            console.log('csvDebug.panel() - Toggle debug panel');
            console.log('csvDebug.help() - Show this help');
            console.groupEnd();
        },
        
        // Erweiterte Debug-Funktionen
        clearErrors: () => {
            if (window.CSVImportAdmin) {
                window.CSVImportAdmin.state.performanceMetrics.errors = 0;
                window.CSVImportAdmin.state.lastError = null;
                console.log('✅ Error counters cleared');
            }
        },
        
        simulateError: () => {
            if (window.CSVImportAdmin) {
                window.CSVImportAdmin.performAjaxRequest({
                    action: 'non_existent_action_for_testing'
                });
                console.log('🧪 Error simulation sent');
            }
        },
        
        enableVerboseLogging: () => {
            if (window.CSVImportAdmin) {
                window.CSVImportAdmin.debug.logLevel = 'debug';
                console.log('🔊 Verbose logging enabled');
            }
        },
        
        disableLogging: () => {
            if (window.CSVImportAdmin) {
                window.CSVImportAdmin.debug.enabled = false;
                console.log('🔇 Logging disabled');
            }
        },
        
        enableLogging: () => {
            if (window.CSVImportAdmin) {
                window.CSVImportAdmin.debug.enabled = true;
                console.log('🔊 Logging enabled');
            }
        }
    };
    
    // Kurze Hilfsmeldung beim Laden
    console.log('🔧 CSV Import Debug Console verfügbar! Geben Sie "csvDebug.help()" für alle Befehle ein.');
}

// ===================================================================
// PERFORMANCE-MONITORING & ABSCHLUSS
// ===================================================================

// Performance-Marker setzen
if (window.performance && window.performance.mark) {
    window.performance.mark('csv-import-admin-script-loaded-v8.7-fixed');
    
    // Performance-Messung für Script-Ladezeit
    if (window.performance.getEntriesByName) {
        const marks = window.performance.getEntriesByName('csv-import-admin-script-loaded-v8.7-fixed');
        if (marks.length > 0) {
            console.log(`📊 CSV Import Admin Script geladen in ${marks[0].startTime.toFixed(2)}ms`);
        }
    }
}

// Browser-Kompatibilitäts-Checks
(function() {
    const compatibility = {
        jquery: typeof jQuery !== 'undefined',
        console: typeof console !== 'undefined',
        json: typeof JSON !== 'undefined',
        performance: typeof window.performance !== 'undefined',
        heartbeat: typeof wp !== 'undefined' && typeof wp.heartbeat !== 'undefined',
        promises: typeof Promise !== 'undefined'
    };
    
    const incompatible = Object.keys(compatibility).filter(key => !compatibility[key]);
    
    if (incompatible.length > 0) {
        console.warn('⚠️ CSV Import Admin: Fehlende Browser-Features:', incompatible);
    } else {
        console.log('✅ CSV Import Admin: Alle Browser-Features verfügbar');
    }
})();

// Globale Error-Handler für unerwartete Fehler
window.addEventListener('error', function(event) {
    if (event.filename && event.filename.includes('csv') && window.CSVImportAdmin) {
        window.CSVImportAdmin.debug.error('Unerwarteter JavaScript-Fehler:', {
            message: event.message,
            filename: event.filename,
            lineno: event.lineno,
            colno: event.colno
        });
    }
});

// Promise-Rejection-Handler
window.addEventListener('unhandledrejection', function(event) {
    if (window.CSVImportAdmin && event.reason && event.reason.toString().includes('csv')) {
        window.CSVImportAdmin.debug.error('Unbehandelte Promise-Rejection:', event.reason);
    }
});

// ===================================================================
// FINALER STATUS & ERFOLGS-MELDUNG
// ===================================================================

console.log('🎉 CSV Import Pro Admin Script v8.7-fixed vollständig geladen!');
console.log('✅ Alle ursprünglichen Funktionen erhalten');
console.log('🔧 Verbindungsprobleme behoben');
console.log('📊 Erweiterte Debug-Features verfügbar');
console.log('⚡ Performance-Monitoring aktiv');
console.log('🛡️ Robuste Fehlerbehandlung implementiert');

// Entwickler-Notiz
if (window.CSVImportAdmin && window.CSVImportAdmin.debug.enabled) {
    console.group('👨‍💻 Entwickler-Informationen');
    console.log('• Debug-Panel: Ctrl+Shift+D');
    console.log('• Emergency Reset: Ctrl+Shift+R');
    console.log('• Scheduler Status: Ctrl+Shift+S');
    console.log('• Debug-Befehle: csvDebug.help()');
    console.log('• Version:', window.CSVImportAdmin.version);
    console.groupEnd();
}
