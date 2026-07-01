<?php
// init_db.php
// Import student data and existing results from JSON files into SQLite database.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/calc.php';

echo "Initializing database...\n";
$pdo = get_db_connection();

// Clear existing tables
echo "Wiping existing data...\n";
$pdo->exec("DELETE FROM results");
$pdo->exec("DELETE FROM students");
$pdo->exec("DELETE FROM sqlite_sequence WHERE name='students'");

// Load students
$students_file = __DIR__ . '/db/students.json';
if (!file_exists($students_file)) {
    die("Error: students.json not found at $students_file\n");
}
$students = json_decode(file_get_contents($students_file), true);
echo "Loaded " . count($students) . " students from JSON.\n";

$insert_student = $pdo->prepare("INSERT INTO students (klasse, name, vorname, geburtsjahr, geschlecht) VALUES (?, ?, ?, ?, ?)");

$pdo->beginTransaction();
$student_map = [];
$count = 0;
foreach ($students as $s) {
    $insert_student->execute([
        $s['klasse'],
        $s['name'],
        $s['vorname'],
        $s['geburtsjahr'],
        $s['geschlecht']
    ]);
    $id = $pdo->lastInsertId();
    // Create a unique key for matching: class|name|firstname
    $key = strtolower($s['klasse'] . '|' . $s['name'] . '|' . $s['vorname']);
    $student_map[$key] = [
        'id' => $id,
        'gender' => $s['geschlecht'],
        'birth_year' => $s['geburtsjahr'],
        'klasse' => $s['klasse']
    ];
    $count++;
}
$pdo->commit();
echo "Inserted $count students.\n";

// Load existing results
$results_file = __DIR__ . '/db/existing_results.json';
if (!file_exists($results_file)) {
    die("Error: existing_results.json not found at $results_file\n");
}
$existing_results = json_decode(file_get_contents($results_file), true);
echo "Loaded " . count($existing_results) . " existing result rows from JSON.\n";

$insert_result = $pdo->prepare("INSERT INTO results (
    student_id, ausdauer_leistung, sprint_leistung, sprung_leistung, wurf_leistung,
    ausdauer_punkte, sprint_punkte, sprung_punkte, wurf_punkte, gesamt_punkte, urkunde, note
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$pdo->beginTransaction();
$res_count = 0;
foreach ($existing_results as $res) {
    $key = strtolower($res['klasse'] . '|' . $res['name'] . '|' . $res['vorname']);
    if (isset($student_map[$key])) {
        $st = $student_map[$key];
        
        $ausdauer = $res['ausdauer'] !== '' ? $res['ausdauer'] : null;
        $sprint = $res['sprint'] !== '' ? floatval($res['sprint']) : null;
        $sprung = $res['sprung'] !== '' ? floatval($res['sprung']) : null;
        $wurf = $res['wurf'] !== '' ? floatval($res['wurf']) : null;
        
        // Calculate points and grade/certificate
        try {
            $calc = BJSCalculator::calculate(
                $st['gender'],
                $st['birth_year'],
                $st['klasse'],
                $ausdauer,
                $sprint,
                $sprung,
                $wurf
            );
            
            $insert_result->execute([
                $st['id'],
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
            $res_count++;
        } catch (Exception $e) {
            echo "Error calculating for student $key: " . $e->getMessage() . "\n";
        }
    } else {
        echo "Warning: Student not found for result key: $key\n";
    }
}
$pdo->commit();
echo "Successfully imported $res_count student results.\n";
echo "Database initialization complete.\n";
