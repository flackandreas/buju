<?php
// calc.php
// Athletic calculations logic for Bundesjugendspiele.

class BJSCalculator {
    private static $rules = null;

    private static function load_rules() {
        if (self::$rules === null) {
            $path = __DIR__ . '/db/extracted_data.json';
            if (!file_exists($path)) {
                throw new Exception("Rules data file not found at $path");
            }
            self::$rules = json_decode(file_get_contents($path), true);
        }
    }

    private static function time_to_seconds($time_str) {
        if (empty($time_str)) return null;
        $time_str = trim($time_str);
        if (strpos($time_str, ':') !== false) {
            $parts = explode(':', $time_str);
            if (count($parts) == 2) {
                return intval($parts[0]) * 60 + floatval($parts[1]);
            }
        }
        return floatval($time_str);
    }

    private static function lookup_points($table, $event, $val) {
        if ($val === null || $val === '' || $val === 0 || $val === 0.0) {
            return 0;
        }

        if (!isset($table[$event])) {
            return 0;
        }

        $list = $table[$event];
        if (empty($list)) {
            return 0;
        }

        if ($event === 'ausdauer') {
            // Ausdauer time (MM:SS) - Lower is better. Match mode 1 (exact or next larger numerical time)
            $val_sec = self::time_to_seconds($val);
            if ($val_sec === null) return 0;
            
            $best_match = null;
            $min_larger_leistung = null;
            
            foreach ($list as $item) {
                $item_sec = self::time_to_seconds($item['leistung']);
                if ($item_sec === null) continue;
                
                if ($item_sec == $val_sec) {
                    return intval($item['punkte']);
                }
                
                // We want the next larger time in the list (since slower time represents lower achievement)
                // e.g. if student runs 263s, we match 265s (which is larger than 263s)
                if ($item_sec > $val_sec) {
                    if ($min_larger_leistung === null || $item_sec < $min_larger_leistung) {
                        $min_larger_leistung = $item_sec;
                        $best_match = $item;
                    }
                }
            }
            
            return $best_match ? intval($best_match['punkte']) : 0;
            
        } elseif ($event === 'sprint') {
            // Sprint time (seconds) - Lower is better. Match mode 1 (exact or next larger numerical time)
            $val_float = floatval($val);
            $best_match = null;
            $min_larger_leistung = null;
            
            foreach ($list as $item) {
                $item_val = floatval($item['leistung']);
                if ($item_val == $val_float) {
                    return intval($item['punkte']);
                }
                
                if ($item_val > $val_float) {
                    if ($min_larger_leistung === null || $item_val < $min_larger_leistung) {
                        $min_larger_leistung = $item_val;
                        $best_match = $item;
                    }
                }
            }
            
            return $best_match ? intval($best_match['punkte']) : 0;
            
        } elseif ($event === 'sprung' || $event === 'wurf') {
            // Distance (meters) - Higher is better. Match mode -1 (exact or next smaller numerical distance)
            $val_float = floatval($val);
            $best_match = null;
            $max_smaller_leistung = null;
            
            foreach ($list as $item) {
                $item_val = floatval($item['leistung']);
                if ($item_val == $val_float) {
                    return intval($item['punkte']);
                }
                
                if ($item_val < $val_float) {
                    if ($max_smaller_leistung === null || $item_val > $max_smaller_leistung) {
                        $max_smaller_leistung = $item_val;
                        $best_match = $item;
                    }
                }
            }
            
            return $best_match ? intval($best_match['punkte']) : 0;
        }

        return 0;
    }

    public static function calculate($gender, $geburtsjahr, $klasse, $ausdauer, $sprint, $sprung, $wurf) {
        self::load_rules();

        $age = 2026 - intval($geburtsjahr);
        $gender = strtoupper(trim($gender));
        $stufe = '';
        if (strlen($klasse) >= 2) {
            $stufe = substr($klasse, 1, 1);
        }

        // Determine points table
        // grade 5-6 -> punkte_[m/w]_5-6
        // grade 7-9 -> punkte_[m/w]_7-9
        $is_56 = ($stufe === '5' || $stufe === '6');
        $gender_suffix = ($gender === 'W') ? 'w' : 'm';
        $table_key = $is_56 ? "punkte_{$gender_suffix}_5-6" : "punkte_{$gender_suffix}_7-9";
        
        $points_table = self::$rules[$table_key] ?? null;
        if (!$points_table) {
            throw new Exception("Points table not found for key: $table_key");
        }

        // 1. Calculate individual points
        $p_ausdauer = self::lookup_points($points_table, 'ausdauer', $ausdauer);
        $p_sprint = self::lookup_points($points_table, 'sprint', $sprint);
        $p_sprung = self::lookup_points($points_table, 'sprung', $sprung);
        $p_wurf = self::lookup_points($points_table, 'wurf', $wurf);

        // 2. Calculate Gesamtpunkte (best 3 of 4)
        $pts_array = [$p_ausdauer, $p_sprint, $p_sprung, $p_wurf];
        $total_participations = 0;
        if ($ausdauer !== null && $ausdauer !== '') $total_participations++;
        if ($sprint !== null && $sprint !== '') $total_participations++;
        if ($sprung !== null && $sprung !== '') $total_participations++;
        if ($wurf !== null && $wurf !== '') $total_participations++;

        if ($total_participations === 0) {
            return [
                'ausdauer_punkte' => 0,
                'sprint_punkte' => 0,
                'sprung_punkte' => 0,
                'wurf_punkte' => 0,
                'gesamt_punkte' => 0,
                'urkunde' => 'Keine Teilnahme',
                'note' => 'Keine Teilnahme'
            ];
        }

        // Calculate Gesamt = sum of all minus the minimum of all
        $gesamt = array_sum($pts_array) - min($pts_array);

        // 3. Calculate Certificate (Urkunde)
        $code = $age . strtolower($gender);
        $t_min = 0;
        $s_min = INF;
        $e_min = INF;

        foreach (self::$rules['formeln'] as $f) {
            if ($f['code'] === $code) {
                $t_min = floatval($f['t_min']);
                $s_min = floatval($f['s_min']);
                $e_min = floatval($f['e_min']);
                break;
            }
        }

        $urkunde = 'Teilnehmerurkunde';
        if ($gesamt >= $e_min) {
            $urkunde = 'Ehrenurkunde';
        } elseif ($gesamt >= $s_min) {
            $urkunde = 'Siegerurkunde';
        }

        // 4. Calculate Grade (Note)
        $matrix_key = ($gender === 'W') ? 'noten_maedchen' : 'noten_jungen';
        $noten_data = self::$rules[$matrix_key] ?? null;
        $note = 'Keine Note';

        if ($gesamt == 0) {
            $note = 'Keine Teilnahme';
        } elseif ($noten_data) {
            $ages_list = $noten_data['ages'];
            $matrix = $noten_data['matrix'];
            
            // Find age in matrix headers
            $age_str = sprintf("%.1f", floatval($age)); // age is float in keys like "11.0"
            
            $best_note = null;
            $max_smaller_points = null;

            foreach ($matrix as $row) {
                if (isset($row['points'][$age_str])) {
                    $row_pts = floatval($row['points'][$age_str]);
                    
                    if ($row_pts == $gesamt) {
                        $note = $row['note'];
                        break;
                    }
                    
                    if ($row_pts < $gesamt) {
                        // Descending lookup - find the largest points value <= gesamt
                        if ($max_smaller_points === null || $row_pts > $max_smaller_points) {
                            $max_smaller_points = $row_pts;
                            $best_note = $row['note'];
                        }
                    }
                }
            }
            
            if ($best_note !== null && $note === 'Keine Note') {
                $note = $best_note;
            }
        }

        return [
            'ausdauer_punkte' => $p_ausdauer,
            'sprint_punkte' => $p_sprint,
            'sprung_punkte' => $p_sprung,
            'wurf_punkte' => $p_wurf,
            'gesamt_punkte' => $gesamt,
            'urkunde' => $urkunde,
            'note' => $note
        ];
    }
}
