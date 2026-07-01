<?php
// api.php
// REST API backend for Bundesjugendspiele Webapp.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/calc.php';

$action = $_GET['action'] ?? '';

try {
    $pdo = get_db_connection();
    
    switch ($action) {
        case 'get_classes':
            $stmt = $pdo->query("SELECT DISTINCT klasse FROM students ORDER BY klasse");
            $classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
            send_json(['success' => true, 'classes' => $classes]);
            break;
            
        case 'get_students':
            $klasse = $_GET['klasse'] ?? '';
            if (empty($klasse)) {
                send_error("Class parameter is required", 400);
            }
            
            $stmt = $pdo->prepare("
                SELECT s.id, s.klasse, s.name, s.vorname, s.geburtsjahr, s.geschlecht,
                       r.ausdauer_leistung, r.sprint_leistung, r.sprung_leistung, r.wurf_leistung,
                       r.ausdauer_punkte, r.sprint_punkte, r.sprung_punkte, r.wurf_punkte,
                       r.gesamt_punkte, r.urkunde, r.note
                FROM students s
                LEFT JOIN results r ON s.id = r.student_id
                WHERE s.klasse = ?
                ORDER BY s.name ASC, s.vorname ASC
            ");
            $stmt->execute([$klasse]);
            $students = $stmt->fetchAll();
            
            // Format floats nicely for JSON
            foreach ($students as &$st) {
                $st['sprint_leistung'] = $st['sprint_leistung'] !== null ? floatval($st['sprint_leistung']) : null;
                $st['sprung_leistung'] = $st['sprung_leistung'] !== null ? floatval($st['sprung_leistung']) : null;
                $st['wurf_leistung'] = $st['wurf_leistung'] !== null ? floatval($st['wurf_leistung']) : null;
                $st['ausdauer_punkte'] = intval($st['ausdauer_punkte'] ?? 0);
                $st['sprint_punkte'] = intval($st['sprint_punkte'] ?? 0);
                $st['sprung_punkte'] = intval($st['sprung_punkte'] ?? 0);
                $st['wurf_punkte'] = intval($st['wurf_punkte'] ?? 0);
                $st['gesamt_punkte'] = intval($st['gesamt_punkte'] ?? 0);
            }
            
            send_json(['success' => true, 'students' => $students]);
            break;
            
        case 'save_result':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_error("Method not allowed", 405);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                send_error("Invalid JSON body", 400);
            }
            
            $student_id = intval($input['student_id'] ?? 0);
            if ($student_id <= 0) {
                send_error("Valid Student ID is required", 400);
            }
            
            // 1. Fetch student details to run calculation
            $stmt = $pdo->prepare("SELECT klasse, name, vorname, geburtsjahr, geschlecht FROM students WHERE id = ?");
            $stmt->execute([$student_id]);
            $st = $stmt->fetch();
            if (!$st) {
                send_error("Student not found", 404);
            }
            
            // Normalize inputs
            $ausdauer = !empty($input['ausdauer']) ? trim($input['ausdauer']) : null;
            $sprint = isset($input['sprint']) && $input['sprint'] !== '' ? floatval($input['sprint']) : null;
            $sprung = isset($input['sprung']) && $input['sprung'] !== '' ? floatval($input['sprung']) : null;
            $wurf = isset($input['wurf']) && $input['wurf'] !== '' ? floatval($input['wurf']) : null;
            
            // 2. Perform BJS calculations
            $calc = BJSCalculator::calculate(
                $st['geschlecht'],
                $st['geburtsjahr'],
                $st['klasse'],
                $ausdauer,
                $sprint,
                $sprung,
                $wurf
            );
            
            // 3. Upsert results table
            $stmt = $pdo->prepare("
                INSERT INTO results (
                    student_id, ausdauer_leistung, sprint_leistung, sprung_leistung, wurf_leistung,
                    ausdauer_punkte, sprint_punkte, sprung_punkte, wurf_punkte, gesamt_punkte, urkunde, note, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(student_id) DO UPDATE SET
                    ausdauer_leistung = excluded.ausdauer_leistung,
                    sprint_leistung = excluded.sprint_leistung,
                    sprung_leistung = excluded.sprung_leistung,
                    wurf_leistung = excluded.wurf_leistung,
                    ausdauer_punkte = excluded.ausdauer_punkte,
                    sprint_punkte = excluded.sprint_punkte,
                    sprung_punkte = excluded.sprung_punkte,
                    wurf_punkte = excluded.wurf_punkte,
                    gesamt_punkte = excluded.gesamt_punkte,
                    urkunde = excluded.urkunde,
                    note = excluded.note,
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([
                $student_id,
                $ausdauer,
                $sprint,
                $sprung,
                $wurf,
                $calc['ausdauer_punkte'],
                $calc['sprint_punkte'],
                $calc['sprung_punkte'],
                $calc['wurf_punkte'],
                $calc['gesamt_punkte'],
                $calc['urkunde'],
                $calc['note']
            ]);
            
            send_json([
                'success' => true,
                'message' => 'Result saved successfully',
                'calculations' => $calc
            ]);
            break;
            
        case 'get_dashboard':
            // 1. Get overview stats
            $total_students = intval($pdo->query("SELECT COUNT(*) FROM students")->fetchColumn());
            $participated_students = intval($pdo->query("SELECT COUNT(*) FROM results WHERE gesamt_punkte > 0")->fetchColumn());
            
            // Urkunden counts
            $ehren_cnt = intval($pdo->query("SELECT COUNT(*) FROM results WHERE urkunde = 'Ehrenurkunde'")->fetchColumn());
            $sieger_cnt = intval($pdo->query("SELECT COUNT(*) FROM results WHERE urkunde = 'Siegerurkunde'")->fetchColumn());
            $teilnehmer_cnt = intval($pdo->query("SELECT COUNT(*) FROM results WHERE urkunde = 'Teilnehmerurkunde'")->fetchColumn());
            $keine_cnt = $total_students - $participated_students;
            
            // 2. Class statistics (recreates the Excel "Auswertung" sheet)
            $class_stats_stmt = $pdo->query("
                SELECT s.klasse,
                       COUNT(s.id) as total,
                       SUM(CASE WHEN r.gesamt_punkte > 0 THEN 1 ELSE 0 END) as participated,
                       SUM(CASE WHEN r.urkunde = 'Ehrenurkunde' THEN 1 ELSE 0 END) as ehren,
                       SUM(CASE WHEN r.urkunde = 'Siegerurkunde' THEN 1 ELSE 0 END) as sieger,
                       SUM(CASE WHEN r.urkunde = 'Teilnehmerurkunde' THEN 1 ELSE 0 END) as teilnehmer,
                       ROUND(AVG(CASE WHEN r.gesamt_punkte > 0 THEN r.gesamt_punkte ELSE NULL END), 1) as avg_punkte
                FROM students s
                LEFT JOIN results r ON s.id = r.student_id
                GROUP BY s.klasse
                ORDER BY s.klasse ASC
            ");
            $class_stats = $class_stats_stmt->fetchAll();
            
            // 3. Best Performances (Records)
            // Helper to get top performanced student
            $top_sprint_m = get_top_performance($pdo, 'M', 'sprint_leistung', 'ASC');
            $top_sprint_w = get_top_performance($pdo, 'W', 'sprint_leistung', 'ASC'); // lower is better
            
            $top_ausdauer_m = get_top_performance($pdo, 'M', 'ausdauer_leistung', 'ASC', true); // lower is better
            $top_ausdauer_w = get_top_performance($pdo, 'W', 'ausdauer_leistung', 'ASC', true);
            
            $top_sprung_m = get_top_performance($pdo, 'M', 'sprung_leistung', 'DESC'); // higher is better
            $top_sprung_w = get_top_performance($pdo, 'W', 'sprung_leistung', 'DESC');
            
            $top_wurf_m = get_top_performance($pdo, 'M', 'wurf_leistung', 'DESC');
            $top_wurf_w = get_top_performance($pdo, 'W', 'wurf_leistung', 'DESC');
            
            $top_points_m = get_top_performance($pdo, 'M', 'gesamt_punkte', 'DESC');
            $top_points_w = get_top_performance($pdo, 'W', 'gesamt_punkte', 'DESC');

            send_json([
                'success' => true,
                'overview' => [
                    'total_students' => $total_students,
                    'participated_students' => $participated_students,
                    'participation_rate' => $total_students > 0 ? round(($participated_students / $total_students) * 100, 1) : 0,
                    'ehrenurkunden' => $ehren_cnt,
                    'siegerurkunden' => $sieger_cnt,
                    'teilnehmerurkunden' => $teilnehmer_cnt,
                    'keine_teilnahme' => $keine_cnt
                ],
                'class_stats' => $class_stats,
                'records' => [
                    'sprint' => ['M' => $top_sprint_m, 'W' => $top_sprint_w],
                    'ausdauer' => ['M' => $top_ausdauer_m, 'W' => $top_ausdauer_w],
                    'sprung' => ['M' => $top_sprung_m, 'W' => $top_sprung_w],
                    'wurf' => ['M' => $top_wurf_m, 'W' => $top_wurf_w],
                    'punkte' => ['M' => $top_points_m, 'W' => $top_points_w]
                ]
            ]);
            break;
            
        case 'get_top3':
            send_json([
                'success' => true,
                'sprint' => get_top3_by_event($pdo, 'sprint_leistung', 'ASC'),
                'sprung' => get_top3_by_event($pdo, 'sprung_leistung', 'DESC'),
                'wurf' => get_top3_by_event($pdo, 'wurf_leistung', 'DESC'),
                'ausdauer' => get_top3_by_event($pdo, 'ausdauer_leistung', 'ASC'),
                'punkte' => get_top3_by_event($pdo, 'gesamt_punkte', 'DESC')
            ]);
            break;
            
        case 'reset_db':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                send_error("Method not allowed", 405);
            }
            
            // Run importer script
            ob_start();
            include __DIR__ . '/init_db.php';
            $output = ob_get_clean();
            
            send_json([
                'success' => true,
                'message' => 'Database reset and imported successfully',
                'output' => $output
            ]);
            break;
            
        default:
            send_error("Action not found", 404);
            break;
    }
} catch (Exception $e) {
    send_error($e->getMessage(), 500);
}

function send_json($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit(0);
}

function send_error($message, $code = 500) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit(0);
}

function get_top_performance($pdo, $gender, $column, $order = 'DESC', $is_time = false) {
    // Select the best value that is not null or empty
    $where_clause = "s.geschlecht = ? AND r.{$column} IS NOT NULL AND r.{$column} != ''";
    if ($column === 'gesamt_punkte') {
        $where_clause .= " AND r.{$column} > 0";
    }
    
    $stmt = $pdo->prepare("
        SELECT s.vorname, s.name, s.klasse, r.{$column} as leistung
        FROM students s
        JOIN results r ON s.id = r.student_id
        WHERE {$where_clause}
        ORDER BY r.{$column} {$order}
        LIMIT 1
    ");
    $stmt->execute([$gender]);
    $res = $stmt->fetch();
    
    if (!$res) {
        return null;
    }
    
    $val = $res['leistung'];
    if ($column === 'gesamt_punkte') {
        $val = intval($val) . " Punkte";
    } elseif ($column === 'sprung_leistung' || $column === 'wurf_leistung') {
        $val = floatval($val) . " m";
    } elseif ($column === 'sprint_leistung') {
        $val = floatval($val) . " sek";
    }
    
    return [
        'name' => $res['vorname'] . ' ' . $res['name'],
        'klasse' => $res['klasse'],
        'leistung' => $val
    ];
}

function get_top3_by_event($pdo, $column, $order = 'DESC') {
    $where_clause = "r.{$column} IS NOT NULL AND r.{$column} != ''";
    if ($column === 'gesamt_punkte') {
        $where_clause .= " AND r.{$column} > 0";
    }
    
    $points_col = ($column === 'gesamt_punkte') ? "r.gesamt_punkte" : "r." . str_replace('_leistung', '_punkte', $column);
    
    $res = [];
    foreach (['M', 'W'] as $gender) {
        $stmt = $pdo->prepare("
            SELECT s.vorname, s.name, s.klasse, r.{$column} as leistung, {$points_col} as punkte
            FROM students s
            JOIN results r ON s.id = r.student_id
            WHERE s.geschlecht = ? AND {$where_clause}
            ORDER BY r.{$column} {$order}
            LIMIT 3
        ");
        $stmt->execute([$gender]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $formatted = [];
        foreach ($rows as $row) {
            $val = $row['leistung'];
            if ($column === 'gesamt_punkte') {
                $val = intval($val) . " P.";
            } elseif ($column === 'sprung_leistung' || $column === 'wurf_leistung') {
                $val = floatval($val) . " m";
            } elseif ($column === 'sprint_leistung') {
                $val = floatval($val) . " sek";
            }
            
            $formatted[] = [
                'name' => $row['vorname'] . ' ' . $row['name'],
                'klasse' => $row['klasse'],
                'leistung' => $val,
                'punkte' => intval($row['punkte'])
            ];
        }
        $res[$gender] = $formatted;
    }
    return $res;
}
