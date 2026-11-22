<?php
// ============================================================
// includes/semester_archive.php
// Forward-only semester advancement + auto-archive + reset.
// School years are ONLY referenced as labels (managed elsewhere).
// This file NEVER creates or modifies chmsu_school_years rows.
// ============================================================

if (!function_exists('sa_getCurrentSemester')) {

    function sa_getCurrentSemester($conn) {
        $r = $conn->query("SELECT * FROM chmsu_semesters WHERE is_current = 1 LIMIT 1");
        return ($r && $r->num_rows > 0) ? $r->fetch_assoc() : null;
    }

    function sa_ensureSequenceNumbers($conn) {
        $chk = $conn->query("SHOW COLUMNS FROM chmsu_semesters LIKE 'sequence_no'");
        if ($chk && $chk->num_rows == 0) {
            $conn->query("ALTER TABLE chmsu_semesters ADD COLUMN sequence_no INT(11) DEFAULT 0");
        }

        $hasSY = false;
        $syChk = $conn->query("SHOW COLUMNS FROM chmsu_semesters LIKE 'school_year_id'");
        if ($syChk && $syChk->num_rows > 0) $hasSY = true;

        $orderSY = $hasSY ? "COALESCE(school_year_id, 0) ASC," : "";

        $all = $conn->query("
            SELECT id, semester_name FROM chmsu_semesters
            ORDER BY
                $orderSY
                CASE
                    WHEN semester_name LIKE '%1st%' OR semester_name LIKE '%First%' THEN 1
                    WHEN semester_name LIKE '%2nd%' OR semester_name LIKE '%Second%' THEN 2
                    WHEN semester_name LIKE '%Summer%' THEN 3
                    ELSE 4
                END ASC,
                id ASC
        ");
        if (!$all) return;
        $seq = 1;
        while ($row = $all->fetch_assoc()) {
            $id = intval($row['id']);
            $conn->query("UPDATE chmsu_semesters SET sequence_no = $seq WHERE id = $id");
            $seq++;
        }
    }

    function sa_getNextLogicalSemester($conn, $currentSem) {
        if (!$currentSem) {
            $r = $conn->query("SELECT * FROM chmsu_semesters
                               WHERE archived_at IS NULL
                               ORDER BY sequence_no ASC, id ASC LIMIT 1");
            return ($r && $r->num_rows > 0) ? $r->fetch_assoc() : null;
        }
        $curSeq = intval($currentSem['sequence_no']);
        $r = $conn->query("SELECT * FROM chmsu_semesters
                           WHERE archived_at IS NULL AND sequence_no > $curSeq
                           ORDER BY sequence_no ASC, id ASC LIMIT 1");
        return ($r && $r->num_rows > 0) ? $r->fetch_assoc() : null;
    }

    /**
     * Pick the school_year_id for the next semester.
     *   - 1st Sem → same SY
     *   - 2nd Sem → next SY by id order (or same SY if none higher)
     * NEVER creates an SY.
     */
    function sa_pickNextSchoolYearId($conn, $currentSem) {
        $hasSY = false;
        $syChk = $conn->query("SHOW COLUMNS FROM chmsu_semesters LIKE 'school_year_id'");
        if ($syChk && $syChk->num_rows > 0) $hasSY = true;
        if (!$hasSY) return 0;

        $curSyId = !empty($currentSem['school_year_id']) ? intval($currentSem['school_year_id']) : 0;
        $name    = $currentSem['semester_name'];
        $is1st   = (stripos($name, '1st') !== false) || (stripos($name, 'First') !== false);
        $is2nd   = (stripos($name, '2nd') !== false) || (stripos($name, 'Second') !== false);

        if ($is1st) return $curSyId;

        if ($is2nd && $curSyId > 0) {
            $tblChk = $conn->query("SHOW TABLES LIKE 'chmsu_school_years'");
            if (!$tblChk || $tblChk->num_rows == 0) return $curSyId;

            $nextSy = $conn->query("SELECT id FROM chmsu_school_years
                                    WHERE id > $curSyId
                                    ORDER BY id ASC LIMIT 1")->fetch_assoc();
            if ($nextSy) return intval($nextSy['id']);
            return $curSyId;
        }

        return $curSyId;
    }

    /**
     * Auto-create the next logical semester (only if it doesn't exist).
     * School year only LINKED — never created.
     */
    function sa_ensureNextSemesterExists($conn, $currentSem) {
        if (!$currentSem) return null;

        $existing = sa_getNextLogicalSemester($conn, $currentSem);
        if ($existing) return $existing;

        $name = $currentSem['semester_name'];
        $is1st = (stripos($name, '1st') !== false) || (stripos($name, 'First') !== false);
        $is2nd = (stripos($name, '2nd') !== false) || (stripos($name, 'Second') !== false);

        if ($is1st) {
            $newName = 'Second Semester';
            $newCode = '2nd Sem';
        } elseif ($is2nd) {
            $newName = 'First Semester';
            $newCode = '1st Sem';
        } else {
            return null;
        }

        $newSyId = sa_pickNextSchoolYearId($conn, $currentSem);

        $hasSY = false;
        $syChk = $conn->query("SHOW COLUMNS FROM chmsu_semesters LIKE 'school_year_id'");
        if ($syChk && $syChk->num_rows > 0) $hasSY = true;

        if ($hasSY && $newSyId > 0) {
            $ex = $conn->query("SELECT * FROM chmsu_semesters
                                WHERE semester_name = '" . $conn->real_escape_string($newName) . "'
                                AND school_year_id = $newSyId LIMIT 1");
            if ($ex && $ex->num_rows > 0) return $ex->fetch_assoc();

            $conn->query("INSERT INTO chmsu_semesters
                          (semester_name, semester_code, school_year_id, sequence_no)
                          VALUES ('" . $conn->real_escape_string($newName) . "',
                                  '" . $conn->real_escape_string($newCode) . "',
                                  $newSyId, 999999)");
        } else {
            $ex = $conn->query("SELECT * FROM chmsu_semesters
                                WHERE semester_name = '" . $conn->real_escape_string($newName) . "' LIMIT 1");
            if ($ex && $ex->num_rows > 0) return $ex->fetch_assoc();

            $conn->query("INSERT INTO chmsu_semesters
                          (semester_name, semester_code, sequence_no)
                          VALUES ('" . $conn->real_escape_string($newName) . "',
                                  '" . $conn->real_escape_string($newCode) . "',
                                  999999)");
        }

        $newId = intval($conn->insert_id);
        sa_ensureSequenceNumbers($conn);

        return $conn->query("SELECT * FROM chmsu_semesters WHERE id = $newId")->fetch_assoc();
    }

    function sa_getMostRecentArchived($conn) {
        $r = $conn->query("SELECT * FROM chmsu_semesters
                           WHERE archived_at IS NOT NULL
                           ORDER BY archived_at DESC, sequence_no DESC LIMIT 1");
        return ($r && $r->num_rows > 0) ? $r->fetch_assoc() : null;
    }

    function sa_isMostRecentArchived($conn, $semId) {
        $recent = sa_getMostRecentArchived($conn);
        return $recent && intval($recent['id']) === intval($semId);
    }

    /**
     * Archive an old semester.
     * Old clearance rows are NOT touched — they keep their old semester_id,
     * so all role History pages will show them automatically.
     * Feed posts of this semester are flagged as archived.
     */
    function sa_archiveOldSemester($conn, $semId, $adminUsername) {
        $semId = intval($semId);
        if ($semId <= 0) return;

        $admin_esc = $conn->real_escape_string($adminUsername);

        $hasFeedSem = false;
        $feedChk = $conn->query("SHOW COLUMNS FROM chmsu_feed LIKE 'semester_id'");
        if ($feedChk && $feedChk->num_rows > 0) $hasFeedSem = true;
        if ($hasFeedSem) {
            $conn->query("UPDATE chmsu_feed
                          SET is_archived = 1
                          WHERE semester_id = $semId AND is_archived = 0");
        }

        $conn->query("UPDATE chmsu_semesters
                      SET is_current = 0,
                          is_active = 0,
                          archived_at = NOW(),
                          archived_by = '$admin_esc',
                          archived_reason = 'Superseded by new current semester'
                      WHERE id = $semId");
    }

    function sa_seedClearanceForSemester($conn, $newSemesterId) {
        $newSemesterId = intval($newSemesterId);
        if ($newSemesterId <= 0) return;

        $chk = $conn->query("SHOW COLUMNS FROM chmsu_clearance_status LIKE 'semester_id'");
        if ($chk && $chk->num_rows == 0) {
            $conn->query("ALTER TABLE chmsu_clearance_status ADD COLUMN semester_id INT(11) DEFAULT NULL");
        }

        $students = $conn->query("SELECT chmsu_student_id FROM chmsu_students_master WHERE is_archived = 0");
        $offices  = $conn->query("SELECT office_name FROM chmsu_offices");

        $officeList = [];
        while ($o = $offices->fetch_assoc()) $officeList[] = $o['office_name'];

        if ($students && !empty($officeList)) {
            $checkStmt = $conn->prepare("SELECT id FROM chmsu_clearance_status
                                         WHERE student_id = ? AND office_name = ? AND semester_id = ? LIMIT 1");
            $insStmt = $conn->prepare("INSERT INTO chmsu_clearance_status
                (student_id, office_name, semester_id, status, note, updated_by, updated_at)
                VALUES (?, ?, ?, 'pending', '', NULL, NOW())");

            while ($s = $students->fetch_assoc()) {
                $sid = $s['chmsu_student_id'];
                foreach ($officeList as $on) {
                    $checkStmt->bind_param("ssi", $sid, $on, $newSemesterId);
                    $checkStmt->execute();
                    $checkStmt->store_result();
                    if ($checkStmt->num_rows === 0) {
                        $insStmt->bind_param("ssi", $sid, $on, $newSemesterId);
                        $insStmt->execute();
                    }
                    $checkStmt->free_result();
                }
            }
            $checkStmt->close();
            $insStmt->close();
        }
    }

    /**
     * Set a semester as current.
     * - $targetId > 0 → jump to specific semester (any non-current).
     * - $targetId = 0 → auto-advance to next (auto-created if missing).
     * Always: archive old, activate new, seed clearance.
     */
    function sa_advanceSemester($conn, $targetId, $adminUsername, $ipAddress = '') {
        $targetId = intval($targetId);
        $current  = sa_getCurrentSemester($conn);
        $fromId   = $current ? intval($current['id']) : 0;

        if ($targetId > 0) {
            $target = $conn->query("SELECT * FROM chmsu_semesters WHERE id = $targetId")->fetch_assoc();
            if (!$target) return ['ok' => false, 'message' => 'Target semester not found'];
            if ($fromId === $targetId) return ['ok' => false, 'message' => 'That semester is already the current one'];
        } else {
            if (!$current) {
                $first = $conn->query("SELECT * FROM chmsu_semesters
                                       WHERE archived_at IS NULL
                                       ORDER BY sequence_no ASC, id ASC LIMIT 1")->fetch_assoc();
                if (!$first) return ['ok' => false, 'message' => 'No semester available'];
                $target = $first;
                $targetId = intval($first['id']);
            } else {
                $next = sa_getNextLogicalSemester($conn, $current);
                if (!$next) $next = sa_ensureNextSemesterExists($conn, $current);
                if (!$next) return ['ok' => false, 'message' => 'Could not determine next semester.'];
                $target = $next;
                $targetId = intval($next['id']);
            }
        }

        // 1. Archive old
        if ($fromId > 0 && $fromId !== $targetId) {
            sa_archiveOldSemester($conn, $fromId, $adminUsername);
        }

        // 2. Activate new
        $conn->query("UPDATE chmsu_semesters
                      SET is_current = 1, is_active = 1,
                          archived_at = NULL, archived_by = NULL, archived_reason = NULL
                      WHERE id = $targetId");

        // Sync current school year to match the active semester
        if (!empty($target['school_year_id'])) {
            $targetSyId = intval($target['school_year_id']);
            $conn->query("UPDATE chmsu_school_years SET is_current = 0");
            $conn->query("UPDATE chmsu_school_years SET is_current = 1 WHERE id = $targetSyId");
        }

        // Sync current school year to match the active semester
        if (!empty($target['school_year_id'])) {
            $targetSyId = intval($target['school_year_id']);
            $conn->query("UPDATE chmsu_school_years SET is_current = 0");
            $conn->query("UPDATE chmsu_school_years SET is_current = 1 WHERE id = $targetSyId");
        }

        // 3. Fresh pending clearances
        sa_seedClearanceForSemester($conn, $targetId);

        // 4. Log
        $fromVal   = $fromId > 0 ? $fromId : 'NULL';
        $admin_esc = $conn->real_escape_string($adminUsername);
        $ip_esc    = $conn->real_escape_string($ipAddress);
        $conn->query("INSERT INTO chmsu_semester_transitions
                      (from_semester_id, to_semester_id, transition_type, reason, admin_username, ip_address)
                      VALUES ($fromVal, $targetId, 'advance', 'Set as current', '$admin_esc', '$ip_esc')");

        return ['ok' => true, 'message' => 'Semester set as current successfully!', 'from_id' => $fromId, 'to_id' => $targetId];
    }

    /**
     * Restore the most recently archived semester.
     */
    function sa_rollbackSemester($conn, $targetId, $adminUsername, $ipAddress = '') {
        $targetId = intval($targetId);
        $target   = $conn->query("SELECT * FROM chmsu_semesters WHERE id = $targetId")->fetch_assoc();
        if (!$target) return ['ok' => false, 'message' => 'Target semester not found'];

        if (!sa_isMostRecentArchived($conn, $targetId)) {
            return ['ok' => false, 'message' => 'Only the most recently archived semester can be restored'];
        }

        $current = sa_getCurrentSemester($conn);
        $fromId  = $current ? intval($current['id']) : 0;

        if ($fromId > 0 && $fromId !== $targetId) {
            $conn->query("UPDATE chmsu_semesters
                          SET is_current = 0, is_active = 1,
                              archived_at = NULL, archived_by = NULL, archived_reason = NULL
                          WHERE id = $fromId");
        }

        $conn->query("UPDATE chmsu_semesters
                      SET is_current = 1, is_active = 1,
                          archived_at = NULL, archived_by = NULL, archived_reason = NULL
                      WHERE id = $targetId");

        // Sync current school year to match the active semester
        if (!empty($target['school_year_id'])) {
            $targetSyId = intval($target['school_year_id']);
            $conn->query("UPDATE chmsu_school_years SET is_current = 0");
            $conn->query("UPDATE chmsu_school_years SET is_current = 1 WHERE id = $targetSyId");
        }

        sa_seedClearanceForSemester($conn, $targetId);

        $fromVal   = $fromId > 0 ? $fromId : 'NULL';
        $admin_esc = $conn->real_escape_string($adminUsername);
        $ip_esc    = $conn->real_escape_string($ipAddress);
        $conn->query("INSERT INTO chmsu_semester_transitions
                      (from_semester_id, to_semester_id, transition_type, reason, admin_username, ip_address)
                      VALUES ($fromVal, $targetId, 'rollback', 'Restore most recent archive', '$admin_esc', '$ip_esc')");

        return ['ok' => true, 'message' => 'Semester restored successfully!', 'from_id' => $fromId, 'to_id' => $targetId];
    }
}