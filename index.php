<?php
// index.php
// Main landing page and frontend interface for Bundesjugendspiele Webapp.
session_start();

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Handle Login POST
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if ($username === 'buju_rstn' && $password === 'buju4232') {
        $_SESSION['logged_in'] = true;
        $_SESSION['username'] = $username;
        header('Location: index.php');
        exit;
    } else {
        $login_error = 'Ungültiger Benutzername oder Passwort.';
    }
}

$is_logged_in = $_SESSION['logged_in'] ?? false;

// If not logged in, render the login page
if (!$is_logged_in) {
    include __DIR__ . '/login_template.php';
    exit;
}

require_once __DIR__ . '/db.php';
$db_status_ok = false;
$db_status_msg = '';

try {
    $pdo = get_db_connection();
    $db_status_ok = true;
} catch (Exception $e) {
    $db_status_msg = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Bundesjugendspiele 2026 – Auswertung</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- CSS Style -->
    <link rel="stylesheet" href="css/style.css">
    
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Global JS error logger for debugging -->
    <script>
        window.onerror = function(message, source, lineno, colno, error) {
            const errDiv = document.createElement('div');
            errDiv.style.position = 'fixed';
            errDiv.style.bottom = '10px';
            errDiv.style.right = '10px';
            errDiv.style.background = '#EF4444';
            errDiv.style.color = '#FFF';
            errDiv.style.padding = '16px';
            errDiv.style.borderRadius = '8px';
            errDiv.style.zIndex = '999999';
            errDiv.style.maxWidth = '400px';
            errDiv.style.boxShadow = '0 10px 15px rgba(0,0,0,0.3)';
            errDiv.errDetails = error ? error.stack : message;
            errDiv.innerHTML = '<strong>JS Error:</strong> ' + message + ' at ' + lineno + ':' + colno + '<br><small style="opacity:0.8;">Klicken zum Kopieren</small>';
            errDiv.onclick = function() {
                navigator.clipboard.writeText(errDiv.errDetails);
                alert('Stacktrace in Zwischenablage kopiert!');
            };
            document.body.appendChild(errDiv);
            return false;
        };
    </script>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <div class="brand-logo">🏆</div>
                <div class="brand-title">
                    <h1>BJS 2026</h1>
                    <span class="sub-title">Sportauswertung</span>
                </div>
            </div>
            
            <nav class="sidebar-nav">
                <a href="#dashboard" class="nav-item active" id="nav-dashboard">
                    <span class="nav-icon">📊</span>
                    <span class="nav-label">Dashboard</span>
                </a>
                <a href="#eingabe" class="nav-item" id="nav-eingabe">
                    <span class="nav-icon">📝</span>
                    <span class="nav-label">Daten-Eingabe</span>
                </a>
                <a href="#admin" class="nav-item" id="nav-admin">
                    <span class="nav-icon">⚙️</span>
                    <span class="nav-label">Verwaltung</span>
                </a>
            </nav>
            
            <div class="sidebar-footer">
                <button class="theme-toggle-btn" id="theme-toggle" title="Design wechseln">
                    <span id="theme-toggle-text">☀️ Helles Design</span>
                </button>
                <a href="index.php?logout=1" class="logout-link" title="Abmelden">
                    <span class="logout-icon">🚪</span>
                    <span class="logout-text">Abmelden</span>
                </a>
                <div class="db-status <?php echo $db_status_ok ? 'status-ok' : 'status-error'; ?>">
                    <span class="status-indicator"></span>
                    <span class="status-text"><?php echo $db_status_ok ? 'Datenbank verbunden' : 'Fehler: ' . htmlspecialchars($db_status_msg); ?></span>
                </div>
            </div>
        </aside>
        
        <!-- Main Content Area -->
        <main class="main-content">
            <!-- Topbar -->
            <header class="topbar">
                <div class="topbar-left">
                    <h2 class="view-title" id="page-title">Dashboard</h2>
                </div>
                <div class="topbar-right" style="display: flex; gap: 12px; align-items: center;">
                    <div id="sync-status-badge" class="sync-status-badge status-online">
                        <span class="sync-status-dot"></span>
                        <span id="sync-status-text" class="sync-status-text">Online</span>
                    </div>
                    <span class="competition-year">Saison 2026</span>
                </div>
            </header>
            
            <!-- View: Dashboard -->
            <section id="view-dashboard" class="content-view active-view">
                <div class="print-actions-row no-print" style="margin-bottom: 20px; display: flex; gap: 12px;">
                    <button type="button" class="btn btn-secondary" id="btn-print-dashboard">🖨️ Dashboard drucken</button>
                    <button type="button" class="btn btn-secondary" id="btn-print-top3">🏆 Bestenliste drucken (Top 3)</button>
                </div>
                <!-- KPI cards -->
                <div class="kpi-grid">
                    <div class="kpi-card gradient-card-blue">
                        <div class="kpi-info">
                            <span class="kpi-label">Teilnehmer Quote</span>
                            <h3 class="kpi-value" id="kpi-participation-rate">0.0%</h3>
                            <span class="kpi-subtext" id="kpi-participation-summary">0 von 0 Schülern</span>
                        </div>
                        <div class="kpi-icon-bg">🏃‍♂️</div>
                    </div>
                    
                    <div class="kpi-card gradient-card-green">
                        <div class="kpi-info">
                            <span class="kpi-label">Ehrenurkunden</span>
                            <h3 class="kpi-value" id="kpi-ehrenurkunden">0</h3>
                            <span class="kpi-subtext" id="kpi-ehrenurkunden-percent">0% aller Schüler</span>
                        </div>
                        <div class="kpi-icon-bg">⭐</div>
                    </div>
                    
                    <div class="kpi-card gradient-card-orange">
                        <div class="kpi-info">
                            <span class="kpi-label">Siegerurkunden</span>
                            <h3 class="kpi-value" id="kpi-siegerurkunden">0</h3>
                            <span class="kpi-subtext" id="kpi-siegerurkunden-percent">0% aller Schüler</span>
                        </div>
                        <div class="kpi-icon-bg">🎖️</div>
                    </div>
                    
                    <div class="kpi-card gradient-card-purple">
                        <div class="kpi-info">
                            <span class="kpi-label">Teilnehmerurkunden</span>
                            <h3 class="kpi-value" id="kpi-teilnehmerurkunden">0</h3>
                            <span class="kpi-subtext" id="kpi-teilnehmerurkunden-percent">0% aller Schüler</span>
                        </div>
                        <div class="kpi-icon-bg">📜</div>
                    </div>
                </div>
                
                <!-- Charts Grid -->
                <div class="charts-grid">
                    <div class="chart-card">
                        <h4>Urkundenverteilung</h4>
                        <div class="chart-container">
                            <canvas id="chart-urkunden"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h4>Punkteschnitt nach Klassen</h4>
                        <div class="chart-container">
                            <canvas id="chart-klassen-punkte"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- School Records / Highscores -->
                <div class="dashboard-details-grid">
                    <div class="details-card school-records-card">
                        <h4>🏆 Schul-Bestleistungen (Mädchen / Jungen)</h4>
                        <div class="records-grid" id="records-container">
                            <!-- Records will be loaded via JS -->
                            <div class="record-loading">Lade Rekorde...</div>
                        </div>
                    </div>
                </div>

                <!-- Class Stats Table -->
                <div class="details-card class-table-card">
                    <h4>📊 Klassen-Auswertung (Original-Sheet Äquivalent)</h4>
                    <div class="table-responsive">
                        <table class="data-table" id="class-stats-table">
                            <thead>
                                <tr>
                                    <th>Klasse</th>
                                    <th>Teilnehmer</th>
                                    <th>Ehrenurkunden</th>
                                    <th>Siegerurkunden</th>
                                    <th>Teilnehmerurkunden</th>
                                    <th>Durchschnittspunkte</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="6" class="text-center">Lade Klassendaten...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
            
            <!-- View: Daten-Eingabe -->
            <section id="view-eingabe" class="content-view">
                <div class="eingabe-layout">
                    <!-- Left: Student List -->
                    <div class="student-list-container">
                        <div class="filter-bar">
                            <div class="class-dropdown-container">
                                <label for="class-select">Klasse wählen:</label>
                                <select id="class-select" class="form-select">
                                    <option value="">-- Klasse wählen --</option>
                                    <!-- Options will be loaded via JS -->
                                </select>
                            </div>
                            <div class="search-input-container" style="display: flex; gap: 8px;">
                                <input type="text" id="student-search" placeholder="Name suchen..." class="form-input" style="flex: 1;" disabled>
                                <button type="button" class="btn btn-secondary" id="btn-print-class" title="Klassenliste drucken" style="padding: 10px 14px;" disabled>🖨️</button>
                            </div>
                        </div>
                        
                        <div class="student-list-wrapper">
                            <div class="student-list-header">
                                <span>Schüler</span>
                                <span class="header-status">Status</span>
                            </div>
                            <ul class="student-list" id="student-list-ul">
                                <li class="no-data">Bitte wählen Sie zuerst eine Klasse aus.</li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Right: Input Form (Pane) -->
                    <div class="input-form-container" id="input-form-container">
                        <div class="empty-form-state" id="form-empty-state">
                            <div class="empty-icon">📝</div>
                            <h3>Kein Schüler ausgewählt</h3>
                            <p>Wählen Sie einen Schüler aus der Liste aus, um die sportlichen Ergebnisse einzugeben.</p>
                        </div>
                        
                        <div class="active-form-state" id="form-active-state" style="display:none;">
                            <!-- Left: Student Info & Live evaluation results panel -->
                            <div class="form-sidebar-panel">
                                <div class="student-profile">
                                    <div class="profile-avatar" id="form-avatar"></div>
                                    <div class="profile-info">
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <h3 id="form-student-name">Name</h3>
                                            <button type="button" class="btn-edit-student" id="btn-trigger-edit-student" title="Schülerdaten bearbeiten">✏️</button>
                                        </div>
                                        <span class="student-meta" id="form-student-meta">Klasse • Alter • M</span>
                                    </div>
                                </div>

                                <!-- Real-Time Evaluation Result -->
                                <div class="live-evaluation-card">
                                    <div class="evaluation-kpi">
                                        <div class="eval-stat">
                                            <span class="eval-label">Punkte</span>
                                            <span class="eval-val font-outfit" id="live-total-points">0</span>
                                        </div>
                                        <div class="eval-stat">
                                            <span class="eval-label">Urkunde</span>
                                            <span class="badge badge-gray" id="live-urkunde-badge">Keine Teilnahme</span>
                                        </div>
                                        <div class="eval-stat">
                                            <span class="eval-label">Sportnote</span>
                                            <span class="eval-val font-outfit text-primary" id="live-grade">Keine Note</span>
                                        </div>
                                    </div>
                                    <div class="lowest-points-indicator" id="live-lowest-dropped-msg">
                                        Der schlechteste Wert wird gestrichen (Dreikampf-Modus).
                                    </div>
                                </div>

                                <div class="auto-save-indicator" id="save-status">
                                    <span class="save-status-icon">✓</span>
                                    <span class="save-status-text">Gespeichert</span>
                                </div>
                            </div>
                            
                            <!-- Right: Event Inputs form -->
                            <form id="bjs-input-form" autocomplete="off" onsubmit="event.preventDefault();">
                                <input type="hidden" id="input-student-id">
                                
                                <div class="events-inputs-grid">
                                    <!-- Sprint -->
                                    <div class="event-input-group">
                                        <div class="event-title-row">
                                            <span class="event-icon">⚡</span>
                                            <label for="input-sprint" class="event-name" id="label-sprint">Sprint (50m)</label>
                                        </div>
                                        <div class="input-with-unit">
                                            <input type="number" step="0.1" min="0" id="input-sprint" placeholder="0.0" class="form-input event-field">
                                            <span class="unit">sek</span>
                                        </div>
                                        <div class="calc-points" id="calc-points-sprint">0 Punkte</div>
                                    </div>
                                    
                                    <!-- Sprung -->
                                    <div class="event-input-group">
                                        <div class="event-title-row">
                                            <span class="event-icon">🦘</span>
                                            <label for="input-sprung" class="event-name">Weitsprung</label>
                                        </div>
                                        <div class="input-with-unit">
                                            <input type="number" step="0.01" min="0" id="input-sprung" placeholder="0.00" class="form-input event-field">
                                            <span class="unit">m</span>
                                        </div>
                                        <div class="calc-points" id="calc-points-sprung">0 Punkte</div>
                                    </div>
                                    
                                    <!-- Wurf -->
                                    <div class="event-input-group">
                                        <div class="event-title-row">
                                            <span class="event-icon">☄️</span>
                                            <label for="input-wurf" class="event-name">Weitwurf (Schlagball)</label>
                                        </div>
                                        <div class="input-with-unit">
                                            <input type="number" step="0.1" min="0" id="input-wurf" placeholder="0.0" class="form-input event-field">
                                            <span class="unit">m</span>
                                        </div>
                                        <div class="calc-points" id="calc-points-wurf">0 Punkte</div>
                                    </div>
                                    
                                    <!-- Ausdauer -->
                                    <div class="event-input-group">
                                        <div class="event-title-row">
                                            <span class="event-icon">🏃‍♂️</span>
                                            <label for="input-ausdauer" class="event-name">Ausdauerlauf</label>
                                        </div>
                                        <div class="input-with-unit">
                                            <input type="text" id="input-ausdauer" placeholder="Min:Sek (z.B. 04:25)" class="form-input event-field">
                                            <span class="unit">min:sek</span>
                                        </div>
                                        <div class="calc-points" id="calc-points-ausdauer">0 Punkte</div>
                                    </div>
                                </div>
                                
                                <div class="form-actions" style="justify-content: flex-end;">
                                    <button type="button" class="btn btn-primary" id="btn-next-student">Nächster Schüler →</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </section>
            
            <!-- View: Verwaltung -->
            <section id="view-admin" class="content-view">
                <div class="admin-card">
                    <h3>⚙️ Systemsteuerung</h3>
                    <p class="text-muted">Hier können Sie den Zustand der Anwendung konfigurieren und den originalen Datenbestand importieren.</p>
                    
                    <hr class="divider">
                    
                    <div class="admin-action-row">
                        <div class="action-info">
                            <h4>Originalen Datenbestand wiederherstellen</h4>
                            <p>Dies überschreibt alle aktuellen Ergebnisse in der Datenbank und importiert die Schülerliste sowie die ursprünglichen Testergebnisse aus der Excel-Vorlage.</p>
                        </div>
                        <div class="action-btn">
                            <button type="button" class="btn btn-danger" id="btn-reset-db">Datenbank zurücksetzen</button>
                        </div>
                    </div>
                    
                    <hr class="divider">
                    
                    <div class="admin-action-row">
                        <div class="action-info">
                            <h4>Paritäts-Berechnungs-Check</h4>
                            <p>Hier können Sie den Abgleich-Skript-Pfad einsehen, um die mathematische Parität der Berechnungslogik mit den Originaldaten der Excel-Tabelle zu prüfen.</p>
                        </div>
                        <div class="action-btn">
                            <a href="verify_calculations.php" target="_blank" class="btn btn-secondary">Berechnungen prüfen</a>
                        </div>
                    </div>
                </div>
                
                <div class="admin-card text-card mt-20">
                    <h3>💡 Hinweise zur Tablet-Bedienung (iPad/Safari)</h3>
                    <ul>
                        <li><strong>Automatische Speicherung:</strong> Beim Verlassen eines Eingabefeldes oder beim Klick auf "Nächster Schüler" werden die Werte automatisch im Hintergrund per AJAX gespeichert. Der grüne Haken oben rechts signalisiert den Speichererfolg.</li>
                        <li><strong>Format für Ausdauerlauf:</strong> Bitte geben Sie die Zeiten im Format <code>MM:SS</code> ein (z.B. <code>04:25</code>). Der Punkt <code>4.25</code> wird von dieser Web-App ebenfalls automatisch erkannt und in das korrekte Zeitformat umgerechnet.</li>
                        <li><strong>Offline-Verhalten:</strong> Die Webapp speichert die Daten auf dem Server. Sollte die Verbindung kurzzeitig abbrechen, wird beim Speichern ein rotes Ausrufezeichen angezeigt. Sobald die Verbindung wieder stabil ist, versuchen Sie die Werte erneut einzugeben.</li>
                    </ul>
                </div>
            </section>
        </main>
    </div>
    
    <!-- Toast notifications -->
    <div id="toast-container" class="toast-container"></div>
    
    <!-- Dynamic Print Section (only visible during print) -->
    <div id="print-area" class="print-only"></div>

    <!-- Dialog Modal: Edit Student Profile -->
    <dialog id="dialog-edit-student" class="theme-dialog">
        <div class="dialog-content">
            <div class="dialog-header">
                <h3>✏️ Schülerdaten bearbeiten</h3>
                <button type="button" class="dialog-close-btn" id="btn-close-edit-dialog">×</button>
            </div>
            <form id="form-edit-student" autocomplete="off" onsubmit="event.preventDefault();">
                <input type="hidden" id="edit-student-id">
                
                <div class="form-group" style="margin-bottom: 12px;">
                    <label for="edit-student-vorname" style="display:block; margin-bottom:4px; font-size:12px; font-weight:600; color:var(--text-secondary);">Vorname</label>
                    <input type="text" id="edit-student-vorname" class="form-input" required>
                </div>
                
                <div class="form-group" style="margin-bottom: 12px;">
                    <label for="edit-student-nachname" style="display:block; margin-bottom:4px; font-size:12px; font-weight:600; color:var(--text-secondary);">Nachname</label>
                    <input type="text" id="edit-student-nachname" class="form-input" required>
                </div>
                
                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label for="edit-student-klasse" style="display:block; margin-bottom:4px; font-size:12px; font-weight:600; color:var(--text-secondary);">Klasse</label>
                        <input type="text" id="edit-student-klasse" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label for="edit-student-geburtsjahr" style="display:block; margin-bottom:4px; font-size:12px; font-weight:600; color:var(--text-secondary);">Geburtsjahr</label>
                        <input type="number" id="edit-student-geburtsjahr" class="form-input" required min="1990" max="2030">
                    </div>
                </div>
                
                <div class="dialog-actions" style="display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" class="btn btn-secondary" id="btn-cancel-edit-student">Abbrechen</button>
                    <button type="submit" class="btn btn-primary" id="btn-save-edit-student">Speichern</button>
                </div>
            </form>
        </div>
    </dialog>

    <!-- Load rules & app logic -->
    <script src="js/rules.js"></script>
    <script src="js/app.js"></script>
</body>
</html>
