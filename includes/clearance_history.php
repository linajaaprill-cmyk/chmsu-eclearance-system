<?php
// includes/clearance_history.php
// Shared helpers for Clearance History across all roles
//
// FIX: getHistorySemesters() now joins chmsu_semesters.school_year_id
// so each semester shows its OWN school year (not the global "current" one).
// If a legacy semester has no school_year_id (NULL), it falls back to the
// school year flagged as current (old behaviour) for backwards compatibility.

// ------------------------------------------------------------
// Small schema helpers (cached)
// ------------------------------------------------------------
function hist_has_column($conn, $table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (!isset($cache[$key])) {
        $t = $conn->real_escape_string($table);
        $c = $conn->real_escape_string($column);
        $q = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
        $cache[$key] = ($q && $q->num_rows > 0);
    }
    return $cache[$key];
}

function hist_table_exists($conn, $table) {
    static $cache = [];
    if (!isset($cache[$table])) {
        $t = $conn->real_escape_string($table);
        $q = $conn->query("SHOW TABLES LIKE '$t'");
        $cache[$table] = ($q && $q->num_rows > 0);
    }
    return $cache[$table];
}

function hist_sy_label_column($conn) {
    static $col = false;
    if ($col !== false) return $col;
    $col = null;
    if (!hist_table_exists($conn, 'chmsu_school_years')) return $col;
    foreach (['school_year', 'year_name', 'sy', 'name', 'school_year_name'] as $cand) {
        if (hist_has_column($conn, 'chmsu_school_years', $cand)) { $col = $cand; break; }
    }
    return $col;
}

/**
 * Get all ARCHIVED and USED semesters (Newest Year first, 2nd Sem before 1st Sem).
 * FIXED: Now correctly joins school_year_id, filters out the current semester,
 * AND only shows semesters that have actual clearance records.
 */
function getHistorySemesters($conn) {
    $rows = [];

    $hasSyId = hist_has_column($conn, 'chmsu_semesters', 'school_year_id');
    $hasSeq  = hist_has_column($conn, 'chmsu_semesters', 'sequence_no');
    $hasArch = hist_has_column($conn, 'chmsu_semesters', 'archived_at');
    $syCol   = hist_sy_label_column($conn);

    $cols = "s.id, s.semester_name, s.semester_code, s.is_current, s.created_at";
    $cols .= $hasSyId ? ", s.school_year_id" : ", NULL AS school_year_id";
    $cols .= $hasSeq  ? ", s.sequence_no"    : ", s.id AS sequence_no";
    $cols .= $hasArch ? ", s.archived_at, s.archived_by, s.archived_reason"
                      : ", NULL AS archived_at, NULL AS archived_by, NULL AS archived_reason";

    $from = "chmsu_semesters s";
    if ($syCol && $hasSyId) {
        // KEY FIX: Join on school_year_id, fallback to current SY only if NULL
        $cols .= ", COALESCE(sy.`$syCol`, (SELECT `$syCol` FROM chmsu_school_years WHERE is_current = 1 LIMIT 1)) AS school_year";
        $cols .= ", (sy.id IS NOT NULL) AS sy_assigned";
        $from  = "chmsu_semesters s LEFT JOIN chmsu_school_years sy ON sy.id = s.school_year_id";
    } elseif ($syCol) {
        // Legacy fallback: no school_year_id column
        $cols .= ", (SELECT `$syCol` FROM chmsu_school_years WHERE is_current = 1 LIMIT 1) AS school_year, 0 AS sy_assigned";
    } else {
        $cols .= ", NULL AS school_year, 0 AS sy_assigned";
    }

    // STRICT FILTER: 
    // 1. Not currently active (is_current = 0)
    // 2. Actually archived (archived_at IS NOT NULL)
    // 3. Has actual usage (Clearance records exist)
    $where = "s.is_current = 0";
    if ($hasArch) {
        $where .= " AND s.archived_at IS NOT NULL";
    }
    
    // Only show if there are clearance records for this semester
    $where .= " AND EXISTS (SELECT 1 FROM chmsu_clearance_status cs WHERE cs.semester_id = s.id)";

    $order = "s.school_year_id DESC";
    if ($hasSeq)  $order .= ", s.sequence_no DESC";
    $order .= ", s.id DESC";

    $q = $conn->query("SELECT $cols FROM $from WHERE $where ORDER BY $order");
    if ($q && $q->num_rows > 0) {
        while ($r = $q->fetch_assoc()) {
            $rows[] = $r;
        }
    }
    return $rows;
}

/**
 * Get the current semester row.
 */
function getCurrentSemester($conn) {
    return $conn->query("SELECT * FROM chmsu_semesters WHERE is_current = 1 LIMIT 1")->fetch_assoc();
}

/**
 * Get semester by ID.
 */
function getSemesterById($conn, $id) {
    $id = intval($id);
    return $conn->query("SELECT * FROM chmsu_semesters WHERE id = $id LIMIT 1")->fetch_assoc();
}

/**
 * For a student + semester, return per-office status.
 */
function getStudentSemesterStatus($conn, $student_id, $semester_id) {
    $out = [];
    $q = $conn->query("
        SELECT cs.office_name, cs.status, cs.note, cs.updated_at
        FROM chmsu_clearance_status cs
        WHERE cs.student_id = '" . $conn->real_escape_string($student_id) . "'
          AND cs.semester_id = " . intval($semester_id) . "
    ");
    if ($q && $q->num_rows > 0) {
        while ($r = $q->fetch_assoc()) {
            $out[$r['office_name']] = $r;
        }
    }
    return $out;
}

/**
 * Count offices cleared by a student in a semester.
 */
function countStudentClearedOffices($conn, $student_id, $semester_id) {
    $q = $conn->query("
        SELECT COUNT(DISTINCT office_name) as c
        FROM chmsu_clearance_status
        WHERE student_id = '" . $conn->real_escape_string($student_id) . "'
          AND semester_id = " . intval($semester_id) . "
          AND status = 'clear'
    ");
    return $q ? intval($q->fetch_assoc()['c']) : 0;
}

/**
 * Get total offices count.
 */
function getTotalOfficesCount($conn) {
    return intval($conn->query("SELECT COUNT(*) as c FROM chmsu_offices")->fetch_assoc()['c']);
}

// ============================================================
// NEW HELPERS (used by the redesigned history pages)
// ============================================================

/** "First Semester · 2026-2027" */
function hist_sem_label($sem) {
    $label = $sem['semester_name'];
    if (!empty($sem['school_year'])) $label .= ' · ' . $sem['school_year'];
    return $label;
}

/** Short chart label: "1st Sem 2026-2027" */
function hist_sem_short($sem) {
    $code = !empty($sem['semester_code']) ? $sem['semester_code'] : $sem['semester_name'];
    return trim($code . ' ' . (!empty($sem['school_year']) ? $sem['school_year'] : ''));
}

/**
 * Chronological order: school year, then sequence (2nd sem before 1st sem).
 * $newestFirst = true reverses it.
 */
function hist_sort_semesters(array $sems, $newestFirst = false) {
    usort($sems, function ($a, $b) {
        // Sort by school year (Newest first)
        $c = strcmp((string)($b['school_year'] ?? ''), (string)($a['school_year'] ?? ''));
        if ($c !== 0) return $c;

        // FIXED: Sort by sequence_no DESC (2nd Sem goes above 1st Sem)
        $qa = intval($a['sequence_no'] ?? 0);
        $qb = intval($b['sequence_no'] ?? 0);
        if ($qa !== $qb) return $qb - $qa;

        return intval($b['id']) - intval($a['id']);
    });
    return $newestFirst ? $sems : array_reverse($sems);
}

/** Group an (already sorted) list of semesters by school year label. */
function hist_group_by_school_year(array $sems) {
    $groups = [];
    foreach ($sems as $sem) {
        $key = !empty($sem['school_year']) ? $sem['school_year'] : 'No School Year';
        if (!isset($groups[$key])) $groups[$key] = [];
        $groups[$key][] = $sem;
    }
    return $groups;
}

/**
 * Registrar/Admin summary for a semester using ONE query
 * (same result as looping countStudentClearedOffices for every active student).
 */
function getSemesterClearanceSummary($conn, $semester_id, $totalOffices) {
    $sid = intval($semester_id);
    $total = intval($conn->query("SELECT COUNT(*) as c FROM chmsu_students_master WHERE is_archived = 0")->fetch_assoc()['c']);

    $fully = 0; $partial = 0; $none = 0;
    $q = $conn->query("
        SELECT x.c AS cleared, COUNT(*) AS n FROM (
            SELECT s.chmsu_student_id, COUNT(DISTINCT cs.office_name) AS c
            FROM chmsu_students_master s
            LEFT JOIN chmsu_clearance_status cs
                   ON cs.student_id = s.chmsu_student_id
                  AND cs.semester_id = $sid
                  AND cs.status = 'clear'
            WHERE s.is_archived = 0
            GROUP BY s.chmsu_student_id
        ) x
        GROUP BY x.c
    ");
    if ($q) {
        while ($r = $q->fetch_assoc()) {
            $c = intval($r['cleared']);
            $n = intval($r['n']);
            if ($c >= $totalOffices && $totalOffices > 0) $fully += $n;
            elseif ($c > 0) $partial += $n;
            else $none += $n;
        }
    }

    return [
        'total_students'    => $total,
        'fully_cleared'     => $fully,
        'partially_cleared' => $partial,
        'none'              => $none,
        'rate'              => $total > 0 ? round(($fully / $total) * 100, 1) : 0,
    ];
}

/** [student_id => cleared offices] for one semester. */
function hist_student_cleared_counts($conn, $semester_id) {
    $out = [];
    $q = $conn->query("
        SELECT student_id, COUNT(DISTINCT office_name) AS c
        FROM chmsu_clearance_status
        WHERE semester_id = " . intval($semester_id) . " AND status = 'clear'
        GROUP BY student_id
    ");
    if ($q) while ($r = $q->fetch_assoc()) $out[$r['student_id']] = intval($r['c']);
    return $out;
}

/** Approved / declined / not-processed counts for ONE office in ONE semester. */
function hist_office_stat_row($conn, $semester_id, $officeName) {
    $sid = intval($semester_id);
    $on  = $conn->real_escape_string($officeName);

    $approved = intval($conn->query("SELECT COUNT(*) as c FROM chmsu_clearance_status WHERE office_name='$on' AND semester_id=$sid AND status='clear'")->fetch_assoc()['c']);
    $declined = intval($conn->query("SELECT COUNT(*) as c FROM chmsu_clearance_status WHERE office_name='$on' AND semester_id=$sid AND status='unclear'")->fetch_assoc()['c']);
    $pending  = intval($conn->query("SELECT COUNT(*) as c FROM chmsu_students_master s LEFT JOIN chmsu_clearance_status cs ON s.chmsu_student_id=cs.student_id AND cs.office_name='$on' AND cs.semester_id=$sid WHERE s.is_archived=0 AND (cs.status IS NULL OR cs.status='pending')")->fetch_assoc()['c']);

    return ['office' => $officeName, 'approved' => $approved, 'declined' => $declined, 'pending' => $pending];
}

/** Stats for every office in one semester. */
function hist_all_offices_stats($conn, $semester_id) {
    $rows = [];
    $q = $conn->query("SELECT office_name FROM chmsu_offices ORDER BY office_name");
    if ($q) while ($o = $q->fetch_assoc()) $rows[] = hist_office_stat_row($conn, $semester_id, $o['office_name']);
    return $rows;
}

/** Feed posts of a semester (optionally only one office). Same rules as the old pages. */
function hist_feed_posts($conn, $semester_id, $officeName = null) {
    $sid = intval($semester_id);
    if ($sid <= 0) return [];

    $hasSem = hist_has_column($conn, 'chmsu_feed', 'semester_id');
    $where = [];
    if ($hasSem) $where[] = "semester_id = $sid";
    if ($officeName !== null) $where[] = "office_name = '" . $conn->real_escape_string($officeName) . "'";
    $sql = "SELECT * FROM chmsu_feed" . (empty($where) ? '' : ' WHERE ' . implode(' AND ', $where)) . " ORDER BY created_at DESC";

    $posts = [];
    $q = $conn->query($sql);
    if ($q && $q->num_rows > 0) while ($r = $q->fetch_assoc()) $posts[] = $r;
    return $posts;
}

function hist_feed_count($conn, $semester_id, $officeName = null) {
    return count(hist_feed_posts($conn, $semester_id, $officeName));
}