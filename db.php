<?php
// db.php
// Database helper for BJS webapp using SQLite PDO.

define('DB_DIR', __DIR__ . '/db');
define('DB_FILE', DB_DIR . '/buju.sqlite');

function get_db_connection() {
    // Ensure the database directory exists and is writeable
    if (!file_exists(DB_DIR)) {
        mkdir(DB_DIR, 0777, true);
    }
    
    // Check if db file exists before connection to know if we need to initialize it
    $db_exists = file_exists(DB_FILE);
    
    try {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // If the database is newly created, initialize tables
        if (!$db_exists) {
            initialize_db_schema($pdo);
        }
        
        return $pdo;
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

function initialize_db_schema($pdo) {
    // 1. Create students table
    $pdo->exec("CREATE TABLE IF NOT EXISTS students (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        klasse TEXT NOT NULL,
        name TEXT NOT NULL,
        vorname TEXT NOT NULL,
        geburtsjahr INTEGER NOT NULL,
        geschlecht TEXT NOT NULL
    )");
    
    // Create index on klasse
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_students_klasse ON students(klasse)");
    
    // 2. Create results table
    $pdo->exec("CREATE TABLE IF NOT EXISTS results (
        student_id INTEGER PRIMARY KEY,
        ausdauer_leistung TEXT DEFAULT NULL,
        sprint_leistung REAL DEFAULT NULL,
        sprung_leistung REAL DEFAULT NULL,
        wurf_leistung REAL DEFAULT NULL,
        ausdauer_punkte INTEGER DEFAULT 0,
        sprint_punkte INTEGER DEFAULT 0,
        sprung_punkte INTEGER DEFAULT 0,
        wurf_punkte INTEGER DEFAULT 0,
        gesamt_punkte INTEGER DEFAULT 0,
        urkunde TEXT DEFAULT 'Keine Teilnahme',
        note TEXT DEFAULT 'Keine Note',
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE
    )");
}
