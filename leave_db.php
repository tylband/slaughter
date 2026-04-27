<?php
/**
 * leave_db.php — PDO connection + all DB query functions for the Leave Credits module.
 *
 * Uses the same cgmhris database as pds_db.php (PDS_DB_* env vars).
 * Exposes $leave_conn (PDO) and a set of functions used by leave_api.php.
 *
 * Tables used:
 *   tbl_syl_leave_form               — individual leave ledger entries
 *   tbl_syl_leave_available_balance  — per-employee per-year forwarded balances
 *   tbl_syl_leave_credits_earned     — lookup: days present + LWOP → credits earned
 *   tbl_syl_leave_conversion_of_working_day — lookup: time value + type → day fraction
 *   tbl_syl_employee_masterlist      — employee list (PIID, name, dept, etc.)
 */

if (!function_exists('loadEnv')) {
    function loadEnv($path) {
        if (!file_exists($path)) return;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
            $name  = trim($name);
            $value = trim($value);
            if ($name && !array_key_exists($name, $_ENV)) {
                putenv("$name=$value");
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
    }
}
loadEnv(__DIR__ . '/.env');

$_lv_host = getenv('DB_HOST') ?: 'localhost';
$_lv_db   = getenv('DB_NAME') ?: 'hrmis';
$_lv_user = getenv('DB_USER') ?: 'root';
$_lv_pass = getenv('DB_PASS') ?: '';

try {
    $leave_conn = new PDO(
        "mysql:host=$_lv_host;dbname=$_lv_db;charset=utf8",
        $_lv_user,
        $_lv_pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $leave_conn->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    error_log('leave_db.php: ' . $e->getMessage());
    header('Content-Type: application/json');
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Leave database unavailable']);
    exit;
}

// ── Payroll-based employee source (regular + casual consolidated) ─────────────
// Regular: tbl_syl_payroll_parent → tbl_template_payroll
// Casual:  tbl_syl_payroll_parent_casual → tbl_template_payroll_casual → bridged
//          to tbl_template_payroll by matching Name, so both show under the same
//          consolidated department label.
//
// OPTIMIZED: the "latest PPID per TID" lookup is pre-computed once per unique
// template (≈33 rows) via a derived table, instead of running a correlated
// subquery for every row in tbl_syl_payroll_parent (potentially thousands).

define('_LV_EMP_BASE_WHERE', '1');
define('_LV_EMP_DEPT_EXPR',  'MIN(emp_src.Department)');
define('_LV_EMP_POS_EXPR',   'MIN(emp_src.Position)');
define('_LV_EMP_STATUS_EXPR',
    "(SELECT sr.Status
      FROM tbl_service_record sr
      WHERE sr.PIID = pi.PIID
        AND sr.Status IS NOT NULL
        AND sr.Status <> ''
      ORDER BY COALESCE(sr.Inc_Date_To, sr.Inc_Date_from, sr.Date) DESC, sr.Inc_Date_from DESC
      LIMIT 1)"
);
define('_LV_EMP_ROSTER_TABLE', 'tbl_syl_leave_employee_roster');
define('_LV_EMP_ROSTER_LOCK', 'lv_emp_roster_refresh');
define('_LV_EMP_ROSTER_TTL_SECONDS', 21600);

function lv_normalize_employee_status(?string $service_status, bool $is_casual): string {
    $status = strtoupper(trim((string)$service_status));

    if ($status === 'REGULAR' || $status === 'PERMANENT') {
        return 'Permanent';
    }
    if ($status === 'CASUAL') {
        return 'Casual';
    }

    return $is_casual ? 'Casual' : 'Permanent';
}

function lv_employee_roster_create_sql(string $table): string {
    return "CREATE TABLE IF NOT EXISTS `$table` (
        `PIID` varchar(20) NOT NULL,
        `ID_NUM` varchar(20) DEFAULT NULL,
        `Surname` varchar(80) DEFAULT NULL,
        `Firstname` varchar(80) DEFAULT NULL,
        `MiddleName` varchar(80) DEFAULT NULL,
        `Department` varchar(255) DEFAULT NULL,
        `Position` varchar(255) DEFAULT NULL,
        `IsCasual` tinyint(1) NOT NULL DEFAULT 0,
        `End_Date` varchar(255) DEFAULT NULL,
        `PayYear` varchar(20) DEFAULT NULL,
        `Status` varchar(255) DEFAULT NULL,
        `FirstGovServiceDate` date DEFAULT NULL,
        `SourceFingerprint` varchar(64) DEFAULT NULL,
        `SnapshotYear` int(11) NOT NULL,
        `SnapshotMonth` int(11) NOT NULL,
        `RefreshedAt` datetime NOT NULL,
        PRIMARY KEY (`PIID`),
        KEY `idx_lv_roster_name` (`Surname`,`Firstname`),
        KEY `idx_lv_roster_firstname` (`Firstname`,`Surname`),
        KEY `idx_lv_roster_department` (`Department`),
        KEY `idx_lv_roster_snapshot` (`SnapshotYear`,`SnapshotMonth`,`RefreshedAt`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
}

function lv_employee_roster_ensure_schema(PDO $conn, ?string $table = null): void {
    $table = $table ?: _LV_EMP_ROSTER_TABLE;
    $conn->exec(lv_employee_roster_create_sql($table));
    $col_stmt = $conn->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?'
    );
    $col_stmt->execute([$table, 'SourceFingerprint']);
    if ((int)$col_stmt->fetchColumn() === 0) {
        $conn->exec('ALTER TABLE `' . $table . '` ADD COLUMN `SourceFingerprint` varchar(64) DEFAULT NULL AFTER `FirstGovServiceDate`');
    }
}

function lv_employee_roster_source_fingerprint(PDO $conn): string {
    $row = $conn->query(
        "SELECT MD5(CONCAT_WS('|',
            (SELECT COUNT(*) FROM tblpersonalinformation),
            (SELECT COALESCE(MAX(PIID), 0) FROM tblpersonalinformation),
            (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', PIID, ID_NUM, SurName, FirstName, MiddleName))), 0) FROM tblpersonalinformation),
            (SELECT COUNT(*) FROM tbl_syl_payroll_parent WHERE isDeleted = 0 AND Quencina = 'whole'),
            (SELECT COALESCE(MAX(CONCAT_WS('|', Date_Updated, PPID, Year, End_Num, TID)), '') FROM tbl_syl_payroll_parent WHERE isDeleted = 0 AND Quencina = 'whole'),
            (SELECT COUNT(*) FROM tbl_syl_payroll_parent_casual WHERE isDeleted = 0 AND Quencina = 'whole'),
            (SELECT COALESCE(MAX(CONCAT_WS('|', Date_Updated, PPID, Year, End_Num, TID)), '') FROM tbl_syl_payroll_parent_casual WHERE isDeleted = 0 AND Quencina = 'whole'),
            (SELECT COUNT(*) FROM tbl_template_payroll),
            (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', TID, Name, isDeleted))), 0) FROM tbl_template_payroll),
            (SELECT COUNT(*) FROM tbl_template_payroll_casual),
            (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', TID, Name, isDeleted))), 0) FROM tbl_template_payroll_casual),
            (SELECT COUNT(*) FROM tbl_service_record),
            (SELECT COALESCE(MAX(CONCAT_WS('|', COALESCE(Last_Updated, ''), COALESCE(Inc_Date_To, ''), COALESCE(Inc_Date_from, ''), COALESCE(Date, ''), COALESCE(PIID, ''), COALESCE(Status, ''))), '') FROM tbl_service_record)
        )) AS fingerprint"
    )->fetch() ?: [];

    return (string)($row['fingerprint'] ?? '');
}

function lv_employee_roster_meta(PDO $conn): array {
    lv_employee_roster_ensure_schema($conn);
    $row = $conn->query(
        'SELECT COUNT(*) AS row_count,
                MAX(SnapshotYear) AS SnapshotYear,
                MAX(SnapshotMonth) AS SnapshotMonth,
                MAX(RefreshedAt) AS RefreshedAt,
                MAX(SourceFingerprint) AS SourceFingerprint
         FROM ' . _LV_EMP_ROSTER_TABLE
    )->fetch() ?: [];

    return [
        'row_count' => (int)($row['row_count'] ?? 0),
        'SnapshotYear' => (int)($row['SnapshotYear'] ?? 0),
        'SnapshotMonth' => (int)($row['SnapshotMonth'] ?? 0),
        'RefreshedAt' => (string)($row['RefreshedAt'] ?? ''),
        'SourceFingerprint' => (string)($row['SourceFingerprint'] ?? ''),
    ];
}

function lv_employee_roster_is_fresh(array $meta, int $target_year, int $target_month, string $source_fingerprint = ''): bool {
    if (($meta['row_count'] ?? 0) <= 0) {
        return false;
    }
    if ((int)($meta['SnapshotYear'] ?? 0) !== $target_year) {
        return false;
    }
    if ((int)($meta['SnapshotMonth'] ?? 0) !== $target_month) {
        return false;
    }

    $refreshed_at = trim((string)($meta['RefreshedAt'] ?? ''));
    if ($refreshed_at === '') {
        return false;
    }

    $ts = strtotime($refreshed_at);
    if ($ts === false) {
        return false;
    }

    if ($source_fingerprint !== '' && ($meta['SourceFingerprint'] ?? '') !== '') {
        if (!hash_equals((string)$meta['SourceFingerprint'], $source_fingerprint)) {
            return false;
        }
    }

    return (time() - $ts) <= _LV_EMP_ROSTER_TTL_SECONDS;
}

function lv_employee_roster_refresh(PDO $conn, int $target_year, int $target_month, ?string $source_fingerprint = null): void {
    lv_employee_roster_ensure_schema($conn);

    $shadow = _LV_EMP_ROSTER_TABLE . '_shadow';
    $swap   = _LV_EMP_ROSTER_TABLE . '_old';
    $source_fingerprint = $source_fingerprint ?? lv_employee_roster_source_fingerprint($conn);

    $conn->exec('DROP TABLE IF EXISTS `' . $shadow . '`');
    $conn->exec('DROP TABLE IF EXISTS `' . $swap . '`');
    $conn->exec('CREATE TABLE `' . $shadow . '` LIKE `' . _LV_EMP_ROSTER_TABLE . '`');

    $join = lv_emp_join_sql($target_year, $target_month);
    $sql = 'INSERT INTO `' . $shadow . '`
                (PIID, ID_NUM, Surname, Firstname, MiddleName, Department, Position,
                 IsCasual, End_Date, PayYear, Status, FirstGovServiceDate, SourceFingerprint,
                 SnapshotYear, SnapshotMonth, RefreshedAt)
            SELECT CAST(pi.PIID AS CHAR(20)) AS PIID,
                   MIN(pi.ID_NUM) AS ID_NUM,
                   MIN(pi.SurName) AS Surname,
                   MIN(pi.FirstName) AS Firstname,
                   MIN(pi.MiddleName) AS MiddleName,
                   ' . _LV_EMP_DEPT_EXPR . ' AS Department,
                   ' . _LV_EMP_POS_EXPR . ' AS Position,
                   MAX(emp_src.IsCasual) AS IsCasual,
                   MIN(emp_src.End_Date) AS End_Date,
                   MIN(emp_src.PayYear) AS PayYear,
                   ' . _LV_EMP_STATUS_EXPR . ' AS Status,
                   (SELECT MIN(COALESCE(sr.Inc_Date_from, sr.Date))
                    FROM tbl_service_record sr
                    WHERE sr.PIID = pi.PIID
                      AND COALESCE(sr.Inc_Date_from, sr.Date) IS NOT NULL) AS FirstGovServiceDate,
                   :source_fingerprint AS SourceFingerprint,
                   :snapshot_year AS SnapshotYear,
                   :snapshot_month AS SnapshotMonth,
                   NOW() AS RefreshedAt
            FROM ' . $join['sql'] . '
            WHERE ' . _LV_EMP_BASE_WHERE . '
            GROUP BY pi.PIID';
    $stmt = $conn->prepare($sql);
    $stmt->execute($join['params'] + [
        ':source_fingerprint' => $source_fingerprint,
        ':snapshot_year' => $target_year,
        ':snapshot_month' => $target_month,
    ]);

    $conn->exec(
        'RENAME TABLE `' . _LV_EMP_ROSTER_TABLE . '` TO `' . $swap . '`, `' .
        $shadow . '` TO `' . _LV_EMP_ROSTER_TABLE . '`'
    );
    $conn->exec('DROP TABLE IF EXISTS `' . $swap . '`');
}

function lv_ensure_employee_roster(PDO $conn, ?int $target_year = null, ?int $target_month = null): void {
    $target_year = $target_year ?: (int)date('Y');
    $target_month = $target_month ?: (int)date('n');

    $meta = lv_employee_roster_meta($conn);
    $source_fingerprint = lv_employee_roster_source_fingerprint($conn);
    if (lv_employee_roster_is_fresh($meta, $target_year, $target_month, $source_fingerprint)) {
        return;
    }

    $lock_stmt = $conn->prepare('SELECT GET_LOCK(:lock_name, 30)');
    $lock_stmt->execute([':lock_name' => _LV_EMP_ROSTER_LOCK]);
    $locked = (int)$lock_stmt->fetchColumn() === 1;
    if (!$locked) {
        return;
    }

    try {
        $meta = lv_employee_roster_meta($conn);
        if (!lv_employee_roster_is_fresh($meta, $target_year, $target_month, $source_fingerprint)) {
            lv_employee_roster_refresh($conn, $target_year, $target_month, $source_fingerprint);
        }
    } finally {
        $unlock_stmt = $conn->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $unlock_stmt->execute([':lock_name' => _LV_EMP_ROSTER_LOCK]);
    }
}

function lv_payroll_source_sql(int $target_year, int $cutoff_end_num): array {
    $prev_year = $target_year - 1;
    $cutoff_end_num = max(1, min(12, $cutoff_end_num));

    $sql = "(SELECT p.PIID, tp.Name AS Department, p.Position, p.End_Date, p.year AS PayYear, p.TID,
                    0 AS IsCasual
             FROM tbl_syl_payroll_parent p
             INNER JOIN tbl_template_payroll tp ON p.TID = tp.TID AND tp.isDeleted = 0
             INNER JOIN (
                 SELECT tid_list.TID,
                        COALESCE(
                            (SELECT p1.End_Date
                             FROM tbl_syl_payroll_parent p1
                             WHERE p1.TID = tid_list.TID
                               AND p1.year = :r_curr_year_end
                               AND p1.End_num <= :r_curr_cutoff_end
                               AND p1.isDeleted = 0
                               AND p1.Quencina = 'whole'
                             ORDER BY p1.Date_Updated DESC
                             LIMIT 1),
                            (SELECT p2.End_Date
                             FROM tbl_syl_payroll_parent p2
                             WHERE p2.TID = tid_list.TID
                               AND p2.year = :r_prev_year_end
                               AND p2.End_num <= 12
                               AND p2.isDeleted = 0
                               AND p2.Quencina = 'whole'
                             ORDER BY p2.Date_Updated DESC
                             LIMIT 1)
                        ) AS End_Date,
                        COALESCE(
                            (SELECT p1.year
                             FROM tbl_syl_payroll_parent p1
                             WHERE p1.TID = tid_list.TID
                               AND p1.year = :r_curr_year_pay
                               AND p1.End_num <= :r_curr_cutoff_pay
                               AND p1.isDeleted = 0
                               AND p1.Quencina = 'whole'
                             ORDER BY p1.Date_Updated DESC
                             LIMIT 1),
                            (SELECT p2.year
                             FROM tbl_syl_payroll_parent p2
                             WHERE p2.TID = tid_list.TID
                               AND p2.year = :r_prev_year_pay
                               AND p2.End_num <= 12
                               AND p2.isDeleted = 0
                               AND p2.Quencina = 'whole'
                             ORDER BY p2.Date_Updated DESC
                             LIMIT 1)
                        ) AS PayYear
                 FROM (SELECT DISTINCT TID FROM tbl_template_payroll WHERE isDeleted = 0) tid_list
             ) latest ON latest.TID = p.TID
                      AND latest.End_Date = p.End_Date
                      AND latest.PayYear = p.year
             WHERE p.isDeleted = 0
               AND p.Quencina = 'whole'

             UNION

             SELECT pc.PIID, tp.Name AS Department, pc.Position, pc.End_Date, pc.year AS PayYear, pc.TID,
                    1 AS IsCasual
             FROM tbl_syl_payroll_parent_casual pc
             INNER JOIN tbl_template_payroll_casual tpc ON pc.TID = tpc.TID AND tpc.isDeleted = 0
             INNER JOIN tbl_template_payroll tp ON tp.Name = tpc.Name AND tp.isDeleted = 0
             INNER JOIN (
                 SELECT tid_list.TID,
                        COALESCE(
                            (SELECT pc1.End_Date
                             FROM tbl_syl_payroll_parent_casual pc1
                             WHERE pc1.TID = tid_list.TID
                               AND pc1.year = :c_curr_year_end
                               AND pc1.End_num <= :c_curr_cutoff_end
                               AND pc1.isDeleted = 0
                               AND pc1.Quencina = 'whole'
                             ORDER BY pc1.Date_Updated DESC
                             LIMIT 1),
                            (SELECT pc2.End_Date
                             FROM tbl_syl_payroll_parent_casual pc2
                             WHERE pc2.TID = tid_list.TID
                               AND pc2.year = :c_prev_year_end
                               AND pc2.End_num <= 12
                               AND pc2.isDeleted = 0
                               AND pc2.Quencina = 'whole'
                             ORDER BY pc2.Date_Updated DESC
                             LIMIT 1)
                        ) AS End_Date,
                        COALESCE(
                            (SELECT pc1.year
                             FROM tbl_syl_payroll_parent_casual pc1
                             WHERE pc1.TID = tid_list.TID
                               AND pc1.year = :c_curr_year_pay
                               AND pc1.End_num <= :c_curr_cutoff_pay
                               AND pc1.isDeleted = 0
                               AND pc1.Quencina = 'whole'
                             ORDER BY pc1.Date_Updated DESC
                             LIMIT 1),
                            (SELECT pc2.year
                             FROM tbl_syl_payroll_parent_casual pc2
                             WHERE pc2.TID = tid_list.TID
                               AND pc2.year = :c_prev_year_pay
                               AND pc2.End_num <= 12
                               AND pc2.isDeleted = 0
                               AND pc2.Quencina = 'whole'
                             ORDER BY pc2.Date_Updated DESC
                             LIMIT 1)
                        ) AS PayYear
                 FROM (SELECT DISTINCT TID FROM tbl_template_payroll_casual WHERE isDeleted = 0) tid_list
             ) latest ON latest.TID = pc.TID
                      AND latest.End_Date = pc.End_Date
                      AND latest.PayYear = pc.year
             WHERE pc.isDeleted = 0
               AND pc.Quencina = 'whole'
            ) emp_src";

    return [
        'sql' => $sql,
        'params' => [
            ':r_curr_year_end' => $target_year,
            ':r_curr_cutoff_end' => $cutoff_end_num,
            ':r_prev_year_end' => $prev_year,
            ':r_curr_year_pay' => $target_year,
            ':r_curr_cutoff_pay' => $cutoff_end_num,
            ':r_prev_year_pay' => $prev_year,
            ':c_curr_year_end' => $target_year,
            ':c_curr_cutoff_end' => $cutoff_end_num,
            ':c_prev_year_end' => $prev_year,
            ':c_curr_year_pay' => $target_year,
            ':c_curr_cutoff_pay' => $cutoff_end_num,
            ':c_prev_year_pay' => $prev_year,
        ],
    ];
}

function lv_emp_join_sql(int $target_year, int $cutoff_end_num): array {
    $src = lv_payroll_source_sql($target_year, $cutoff_end_num);
    return [
        'sql' => $src['sql'] . ' INNER JOIN tblpersonalinformation pi ON pi.PIID = emp_src.PIID',
        'params' => $src['params'],
    ];
}

// ── Employee queries ──────────────────────────────────────────────────────────

/**
 * Batch-fetch the latest Status for a list of PIIDs from tbl_service_record.
 * Returns [PIID => Status] map. Single query replaces N correlated subqueries.
 */
function lv_batch_statuses(PDO $conn, array $piids): array {
    if (empty($piids)) return [];
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare(
        "SELECT PIID, Status
         FROM tbl_service_record
         WHERE PIID IN ($placeholders)
           AND Status IS NOT NULL AND Status <> ''
         ORDER BY PIID,
                  COALESCE(Inc_Date_To, Inc_Date_from, Date) DESC,
                  Inc_Date_from DESC"
    );
    $stmt->execute(array_values($piids));
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!array_key_exists($row['PIID'], $result)) {
            $result[$row['PIID']] = $row['Status'];
        }
    }
    return $result;
}

/**
 * Search employees eligible for leave credits from the active DTR template roster.
 *
 * Returns ['rows'=>[], 'total'=>int, 'page'=>int, 'page_size'=>int, 'total_pages'=>int].
 *
 * - dept uses exact match (value always comes from dropdown).
 * - surname/firstname/piid use prefix match (faster index scan than %like%).
 * - Status is fetched in one batch query after the main result set (no N+1).
 */
function lv_search_employees(
    PDO    $conn,
    string $surname   = '',
    string $firstname = '',
    string $dept      = '',
    string $piid      = '',
    int    $page      = 1,
    int    $page_size = 50
): array {
    lv_ensure_employee_roster($conn);
    $where  = ['1'];
    $params = [];

    if ($surname !== '') {
        $where[] = 'Surname LIKE :surname';
        $params[':surname'] = "$surname%";
    }
    if ($firstname !== '') {
        $where[] = 'Firstname LIKE :firstname';
        $params[':firstname'] = "$firstname%";
    }
    if ($dept !== '') {
        $where[] = 'Department = :dept';
        $params[':dept'] = $dept;
    }
    if ($piid !== '') {
        $where[] = 'PIID LIKE :piid';
        $params[':piid'] = "$piid%";
    }

    $where_sql = implode(' AND ', $where);

    $count_stmt = $conn->prepare(
        'SELECT COUNT(*) FROM ' . _LV_EMP_ROSTER_TABLE . ' WHERE ' . $where_sql
    );
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    $page      = max(1, $page);
    $page_size = max(1, $page_size);
    $total_pages = $total > 0 ? (int)ceil($total / $page_size) : 1;
    $offset    = ($page - 1) * $page_size;

    // Main data query — Status excluded; fetched separately below
    $sql = 'SELECT PIID, ID_NUM, Surname, Firstname, MiddleName,
                   Department, Position, IsCasual, End_Date, PayYear, Status
            FROM ' . _LV_EMP_ROSTER_TABLE . '
            WHERE ' . $where_sql . '
            ORDER BY Department, Surname, Firstname
            LIMIT ' . $page_size . ' OFFSET ' . $offset;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Single batch query for all statuses — replaces one correlated subquery per row
    foreach ($rows as &$row) {
        $row['Status'] = lv_normalize_employee_status(
            $row['Status'] ?? null,
            !empty($row['IsCasual'])
        );
    }
    unset($row);

    return [
        'rows'        => $rows,
        'total'       => $total,
        'page'        => $page,
        'page_size'   => $page_size,
        'total_pages' => $total_pages,
    ];
}

/**
 * Get a single employee row by PIID.
 */
function lv_get_employee(PDO $conn, string $piid): ?array {
    lv_ensure_employee_roster($conn);
    $stmt = $conn->prepare(
        'SELECT PIID, ID_NUM, Surname, Firstname, MiddleName, Department, Position,
                IsCasual, End_Date, PayYear, FirstGovServiceDate, Status
         FROM ' . _LV_EMP_ROSTER_TABLE . '
         WHERE PIID = :piid
         LIMIT 1'
    );
    $stmt->execute([':piid' => $piid]);
    $row = $stmt->fetch() ?: null;
    if ($row) {
        $row['Status'] = lv_normalize_employee_status($row['Status'] ?? null, !empty($row['IsCasual']));
    }
    return $row;
}

/**
 * Get all distinct departments from payroll template tables.
 *
 * Regular template names are the canonical display values. Casual template
 * names are consolidated into matching regular template names, so the
 * dropdown only shows the regular department labels.
 */
function lv_get_departments(PDO $conn): array {
    $stmt = $conn->query(
        "SELECT DISTINCT Department
         FROM (
             SELECT tp.Name AS Department
             FROM tbl_template_payroll tp
             WHERE tp.isDeleted = 0

             UNION

             SELECT tp.Name AS Department
             FROM tbl_template_payroll_casual tpc
             INNER JOIN tbl_template_payroll tp ON tp.Name = tpc.Name AND tp.isDeleted = 0
             WHERE tpc.isDeleted = 0
         ) dept_src
         WHERE Department IS NOT NULL AND Department <> ''
         ORDER BY Department"
    );
    return array_column($stmt->fetchAll(), 'Department');
}

// ── Leave ledger queries ──────────────────────────────────────────────────────

/**
 * Get all leave records for an employee in a given year, ordered chronologically.
 */
function lv_get_records(PDO $conn, string $piid, int $year): array {
    $stmt = $conn->prepare(
        'SELECT LID, PIID, Type_of_Records, Date_of_Filing, Period_From, Period_To,
                VacEarn, VacWP, VacBal, VacWOP,
                SickEarn, SickWP, SickBal, SickWOP,
                Particulars, DateAction, DateProcessed, RecordedBy, Remarks,
                no_avail_VL, no_avail_SL, no_avail_mone_VL, no_avail_mone_SL,
                no_avail_SP, no_avail_P, no_avail_mone
         FROM tbl_syl_leave_form
         WHERE PIID = :piid
           AND Date_of_Filing BETWEEN :date_from AND :date_to
           AND isDeleted = 0
         ORDER BY Date_of_Filing ASC, DateProcessed ASC, Period_From ASC, LID ASC'
    );
    $stmt->execute([
        ':piid' => $piid,
        ':date_from' => sprintf('%04d-01-01', $year),
        ':date_to' => sprintf('%04d-12-31', $year),
    ]);
    return $stmt->fetchAll();
}

function lv_get_dtr_suggested_undertime(PDO $conn, string $piid, string $from, string $to = ''): array {
    $piid = trim($piid);
    $from = trim($from);
    $to = trim($to) !== '' ? trim($to) : $from;
    if ($piid === '' || $from === '') {
        return ['minutes' => 0, 'hours' => 0, 'display_minutes' => 0, 'from' => $from, 'to' => $to];
    }

    $start = strtotime($from);
    $end = strtotime($to);
    if ($start === false || $end === false) {
        return ['minutes' => 0, 'hours' => 0, 'display_minutes' => 0, 'from' => $from, 'to' => $to];
    }
    if ($end < $start) {
        [$from, $to] = [$to, $from];
    }

    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(TIME_TO_SEC(Undertime)), 0) AS undertime_seconds
         FROM tbldtr
         WHERE PIID = :piid
           AND DTR_Date BETWEEN :date_from AND :date_to
           AND Undertime IS NOT NULL
           AND Undertime <> '00:00:00'"
    );
    $stmt->execute([
        ':piid' => $piid,
        ':date_from' => $from,
        ':date_to' => $to,
    ]);

    $seconds = (int)($stmt->fetchColumn() ?: 0);
    $totalMinutes = (int)floor($seconds / 60);

    return [
        'minutes' => $totalMinutes,
        'hours' => (int)floor($totalMinutes / 60),
        'display_minutes' => $totalMinutes % 60,
        'from' => $from,
        'to' => $to,
    ];
}

/**
 * Get a single leave record by LID.
 */
function lv_get_record(PDO $conn, int $lid): ?array {
    $stmt = $conn->prepare(
        'SELECT * FROM tbl_syl_leave_form WHERE LID = :lid AND isDeleted = 0 LIMIT 1'
    );
    $stmt->execute([':lid' => $lid]);
    return $stmt->fetch() ?: null;
}

function lv_main_type(string $type): string {
    $parts = explode('|', $type, 2);
    return trim((string)($parts[0] ?? $type));
}

function lv_is_wellness_type(string $type): bool {
    return strtolower(lv_main_type($type)) === 'wellness leave';
}

function lv_type_includes_weekends(string $type): bool {
    $normalized = strtolower(trim($type));
    return in_array($normalized, ['maternity leave', 'study leave'], true);
}

function lv_count_leave_days_between(string $from, string $to, bool $include_weekends = false): float {
    if ($from === '' || $to === '') return 0.0;

    $start = strtotime($from . ' 00:00:00');
    $end = strtotime($to . ' 00:00:00');
    if ($start === false || $end === false || $end < $start) return 0.0;

    $count = 0.0;
    for ($ts = $start; $ts <= $end; $ts = strtotime('+1 day', $ts)) {
        $day = (int)date('w', $ts);
        if ($include_weekends || ($day !== 0 && $day !== 6)) {
            $count += 1.0;
        }
    }

    return $count;
}

function lv_record_leave_days(array $record): float {
    $type = lv_main_type((string)($record['Type_of_Records'] ?? ''));
    return lv_count_leave_days_between(
        (string)($record['Period_From'] ?? ''),
        (string)($record['Period_To'] ?? ''),
        lv_type_includes_weekends($type)
    );
}

function lv_wellness_days_for_record(array $record): float {
    if (!lv_is_wellness_type((string)($record['Type_of_Records'] ?? ''))) {
        return 0.0;
    }

    return lv_record_leave_days($record);
}

function lv_wellness_days_used(PDO $conn, string $piid, int $year, int $exclude_lid = 0): float {
    $used = 0.0;
    foreach (lv_get_records($conn, $piid, $year) as $row) {
        if ($exclude_lid > 0 && (int)($row['LID'] ?? 0) === $exclude_lid) {
            continue;
        }
        $used += lv_wellness_days_for_record($row);
    }
    return round($used, 4);
}

/**
 * Insert a new leave record. Returns the new LID.
 */
function lv_insert_record(PDO $conn, array $d, string $recorded_by): int {
    $stmt = $conn->prepare(
        'INSERT INTO tbl_syl_leave_form
            (PIID, Type_of_Records, Date_of_Filing, Period_From, Period_To,
             VacEarn, VacWP, VacBal, VacWOP,
            SickEarn, SickWP, SickBal, SickWOP,
            Particulars, DateAction, DateProcessed, RecordedBy,
            no_avail_VL, no_avail_SL, no_avail_mone_VL, no_avail_mone_SL,
            no_avail_SP, no_avail_P, no_avail_mone, Remarks, isDeleted)
         VALUES
            (:piid, :type, :filing, :pfrom, :pto,
             :ve, :vwp, :vbal, :vwop,
             :se, :swp, :sbal, :swop,
             :particulars, :date_action, :date_processed, :recorded_by,
             :nvl, :nsl, :nmvl, :nmsl,
             :nsp, :np, :nmone, :remarks, 0)'
    );
    $stmt->execute([
        ':piid'       => $d['PIID'],
        ':type'       => $d['Type_of_Records'],
        ':filing'     => $d['Date_of_Filing'],
        ':pfrom'      => $d['Period_From'],
        ':pto'        => $d['Period_To'],
        ':ve'         => $d['VacEarn']  ?? 0,
        ':vwp'        => $d['VacWP']   ?? 0,
        ':vbal'       => $d['VacBal']  ?? 0,
        ':vwop'       => $d['VacWOP']  ?? 0,
        ':se'         => $d['SickEarn'] ?? 0,
        ':swp'        => $d['SickWP']  ?? 0,
        ':sbal'       => $d['SickBal'] ?? 0,
        ':swop'       => $d['SickWOP'] ?? 0,
        ':particulars'=> $d['Particulars'] ?? '',
        ':date_action'=> $d['DateAction'] ?? '',
        ':date_processed'=> !empty($d['DateProcessed']) ? $d['DateProcessed'] : date('Y-m-d H:i:s'),
        ':recorded_by'=> !empty($d['RecordedBy']) ? $d['RecordedBy'] : $recorded_by,
        ':nvl'        => $d['no_avail_VL']      ?? 0,
        ':nsl'        => $d['no_avail_SL']      ?? 0,
        ':nmvl'       => $d['no_avail_mone_VL'] ?? 0,
        ':nmsl'       => $d['no_avail_mone_SL'] ?? 0,
        ':nsp'        => $d['no_avail_SP']      ?? 0,
        ':np'         => $d['no_avail_P']       ?? 0,
        ':nmone'      => $d['no_avail_mone']    ?? 0,
        ':remarks'    => $d['Remarks']          ?? '',
    ]);
    return (int)$conn->lastInsertId();
}

/**
 * Update an existing leave record by LID.
 */
function lv_update_record(PDO $conn, int $lid, array $d, string $recorded_by): void {
    $stmt = $conn->prepare(
        'UPDATE tbl_syl_leave_form SET
            Type_of_Records = :type,
            Date_of_Filing  = :filing,
            Period_From     = :pfrom,
            Period_To       = :pto,
            VacEarn  = :ve,  VacWP  = :vwp,  VacBal  = :vbal,  VacWOP  = :vwop,
            SickEarn = :se,  SickWP = :swp,  SickBal = :sbal,  SickWOP = :swop,
            Particulars     = :particulars,
            DateAction      = :date_action,
            DateProcessed   = :date_processed,
            RecordedBy      = :recorded_by,
            no_avail_VL     = :nvl,
            no_avail_SL     = :nsl,
            no_avail_mone_VL= :nmvl,
            no_avail_mone_SL= :nmsl,
            no_avail_SP     = :nsp,
            no_avail_P      = :np,
            no_avail_mone   = :nmone,
            Remarks         = :remarks
         WHERE LID = :lid AND isDeleted = 0'
    );
    $stmt->execute([
        ':lid'        => $lid,
        ':type'       => $d['Type_of_Records'],
        ':filing'     => $d['Date_of_Filing'],
        ':pfrom'      => $d['Period_From'],
        ':pto'        => $d['Period_To'],
        ':ve'         => $d['VacEarn']  ?? 0,
        ':vwp'        => $d['VacWP']   ?? 0,
        ':vbal'       => $d['VacBal']  ?? 0,
        ':vwop'       => $d['VacWOP']  ?? 0,
        ':se'         => $d['SickEarn'] ?? 0,
        ':swp'        => $d['SickWP']  ?? 0,
        ':sbal'       => $d['SickBal'] ?? 0,
        ':swop'       => $d['SickWOP'] ?? 0,
        ':particulars'=> $d['Particulars'] ?? '',
        ':date_action'=> $d['DateAction'] ?? '',
        ':date_processed'=> !empty($d['DateProcessed']) ? $d['DateProcessed'] : date('Y-m-d H:i:s'),
        ':recorded_by'=> !empty($d['RecordedBy']) ? $d['RecordedBy'] : $recorded_by,
        ':nvl'        => $d['no_avail_VL']      ?? 0,
        ':nsl'        => $d['no_avail_SL']      ?? 0,
        ':nmvl'       => $d['no_avail_mone_VL'] ?? 0,
        ':nmsl'       => $d['no_avail_mone_SL'] ?? 0,
        ':nsp'        => $d['no_avail_SP']      ?? 0,
        ':np'         => $d['no_avail_P']       ?? 0,
        ':nmone'      => $d['no_avail_mone']    ?? 0,
        ':remarks'    => $d['Remarks']          ?? '',
    ]);
}

/**
 * Soft-delete a leave record.
 */
function lv_delete_record(PDO $conn, int $lid): void {
    $stmt = $conn->prepare('UPDATE tbl_syl_leave_form SET isDeleted = 1 WHERE LID = :lid');
    $stmt->execute([':lid' => $lid]);
}

// ── DTR override helpers ──────────────────────────────────────────────────────

/**
 * Returns true if this leave type should generate DTR override rows.
 * Mirrors VB frmChoiceFormLeave.add_to_override() eligibility condition.
 */
function lv_dtr_eligible(string $type): bool {
    return in_array(strtolower(trim(lv_main_type($type))), [
        'vacation leave', 'sick leave', 'paternity leave', 'maternity leave',
        'terminal leave', 'solo parent leave', 'compensatory time-off (cto)',
        'rehabilitation leave', 'vacation leave w/o pay', 'sick leave w/o pay',
        'mandatory/forced leave', 'others, (specify)', 'others (specify)',
        'special leave benefits for women',
    ], true);
}

/**
 * Maps a leave type (may include "|subtype" suffix) to the Name stored in tbldtr_override.
 * Mirrors VB frmChoiceFormLeave.add_to_override() Name-mapping logic.
 */
function lv_dtr_name_for_type(string $type): string {
    $parts = explode('|', $type, 2);
    $main  = trim($parts[0] ?? $type);
    $sub   = strtolower(trim($parts[1] ?? ''));
    if ($sub !== '' && $sub !== '-n/a-' && strtolower($main) === 'others, (specify)') {
        $map = [
            'personal milestone'    => 'SPECIAL LEAVE/PM',
            'filial obligation'     => 'SPECIAL LEAVE/FL',
            'domestic emergency'    => 'SPECIAL LEAVE/DE',
            'personal transaction'  => 'SPECIAL LEAVE/PT',
            'calamity, accident hospitalization leave' => 'Calamity,Accident',
            'mourning leave'        => 'SPECIAL LEAVE/ML',
            'magna carta for women' => 'SPECIAL LEAVE/MC',
        ];
        return $map[$sub] ?? ('SPECIAL LEAVE/' . strtoupper(trim($parts[1] ?? '')));
    }
    return $main;
}

/**
 * Given a cancelled or restoration record, return the LID of its pair or null.
 * Matches the pair using stable fields (PIID + period range) instead of parsing
 * human-readable Particulars text.
 */
function lv_find_cancel_companion(PDO $conn, array $rec): ?int {
    $pars  = strtoupper(trim((string)($rec['Particulars'] ?? '')));
    $piid  = (string)($rec['PIID'] ?? '');
    $lid   = (int)($rec['LID'] ?? 0);
    $pfrom = trim((string)($rec['Period_From'] ?? ''));
    $pto   = trim((string)($rec['Period_To'] ?? ''));

    if ($piid === '' || $lid <= 0 || $pfrom === '') {
        return null;
    }

    $pto = $pto !== '' ? $pto : $pfrom;

    if (str_starts_with($pars, 'CANCELLED-')) {
        $stmt = $conn->prepare(
            "SELECT LID FROM tbl_syl_leave_form
             WHERE PIID = :piid
               AND isDeleted = 0
               AND LID <> :lid
               AND DATE(Period_From) = :pfrom
               AND DATE(COALESCE(NULLIF(Period_To, ''), Period_From)) = :pto
               AND UPPER(Particulars) LIKE 'RESTORATION%'
             ORDER BY LID DESC
             LIMIT 1"
        );
        $stmt->execute([
            ':piid'  => $piid,
            ':lid'   => $lid,
            ':pfrom' => $pfrom,
            ':pto'   => $pto,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['LID'] : null;
    }

    if (str_starts_with($pars, 'RESTORATION')) {
        $stmt = $conn->prepare(
            "SELECT LID FROM tbl_syl_leave_form
             WHERE PIID = :piid
               AND isDeleted = 0
               AND LID <> :lid
               AND DATE(Period_From) = :pfrom
               AND DATE(COALESCE(NULLIF(Period_To, ''), Period_From)) = :pto
               AND UPPER(Particulars) LIKE 'CANCELLED-%'
             ORDER BY LID DESC
             LIMIT 1"
        );
        $stmt->execute([
            ':piid'  => $piid,
            ':lid'   => $lid,
            ':pfrom' => $pfrom,
            ':pto'   => $pto,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['LID'] : null;
    }

    return null;
}

/**
 * Delete all DTR override rows tied to a leave record LID.
 * Removes details and person rows first (no FK cascade assumed), then the header.
 */
function lv_delete_dtr_override(PDO $conn, int $lid): void {
    $stmt = $conn->prepare('SELECT OID FROM tbldtr_override WHERE LID = :lid LIMIT 1');
    $stmt->execute([':lid' => $lid]);
    $row = $stmt->fetch();
    if (!$row) return;
    $oid = (int)$row['OID'];
    $conn->prepare('DELETE FROM tbldtr_override_details WHERE OID = :oid')->execute([':oid' => $oid]);
    $conn->prepare('DELETE FROM tbldtr_override_person  WHERE OID = :oid')->execute([':oid' => $oid]);
    $conn->prepare('DELETE FROM tbldtr_override         WHERE OID = :oid')->execute([':oid' => $oid]);
}

/**
 * Insert/replace DTR override rows for a leave record.
 *
 * Mirrors VB frmChoiceFormLeave.add_to_override():
 *   - One tbldtr_override header row  (Override_Type = "Memo")
 *   - One tbldtr_override_details row per calendar day (weekends included, 08:00–17:00)
 *   - One tbldtr_override_person row
 *
 * No-ops if the leave type is not DTR-eligible or dates are missing.
 */
function lv_upsert_dtr_override(
    PDO $conn, int $lid, string $type,
    string $period_from, string $period_to,
    string $particulars, string $piid, string $created_by
): void {
    if (!lv_dtr_eligible($type) || $period_from === '' || $period_to === '') return;

    lv_delete_dtr_override($conn, $lid);

    $now = date('Y-m-d H:i:s');
    $conn->prepare(
        'INSERT INTO tbldtr_override
            (Name, ODate, Override_Type, Remarks, LID, Created_By, Created_Date, Updated_By, Updated_Date)
         VALUES (:name, :odate, :otype, :remarks, :lid, :cby, :cdate, :uby, :udate)'
    )->execute([
        ':name'    => lv_dtr_name_for_type($type),
        ':odate'   => date('Y-m-d'),
        ':otype'   => 'Memo',
        ':remarks' => $particulars,
        ':lid'     => $lid,
        ':cby'     => $created_by,
        ':cdate'   => $now,
        ':uby'     => $created_by,
        ':udate'   => $now,
    ]);
    $oid = (int)$conn->lastInsertId();

    $det   = $conn->prepare(
        'INSERT INTO tbldtr_override_details (OID, dtr_date, time_start, time_end)
         VALUES (:oid, :dt, "08:00", "17:00")'
    );
    $start = strtotime($period_from);
    $end   = strtotime($period_to);
    if ($start !== false && $end !== false && $end >= $start) {
        for ($ts = $start; $ts <= $end; $ts = strtotime('+1 day', $ts)) {
            $det->execute([':oid' => $oid, ':dt' => date('Y-m-d', $ts)]);
        }
    }

    $conn->prepare('DELETE FROM tbldtr_override_person WHERE OID = :oid')->execute([':oid' => $oid]);
    $conn->prepare('INSERT INTO tbldtr_override_person (OID, piid) VALUES (:oid, :piid)')
         ->execute([':oid' => $oid, ':piid' => $piid]);
}

/**
 * Cancel a leave record (transaction-wrapped).
 *
 * - Prefixes Particulars with "CANCELLED-" on the original row (VacWP/SickWP kept intact)
 * - Inserts a restoration row: VacEarn = original.VacWP, SickEarn = original.SickWP
 * - Deletes the DTR override for the original LID
 * - Calls lv_recalculate() for the affected year
 *
 * Returns the new restoration row's LID.
 * Throws RuntimeException on invalid state (already cancelled, not found).
 */
function lv_cancel_record(PDO $conn, int $lid, string $filing_date, string $actor): int {
    $rec = lv_get_record($conn, $lid);
    if (!$rec) throw new RuntimeException('Record not found');
    if (str_starts_with((string)($rec['Particulars'] ?? ''), 'CANCELLED-')) {
        throw new RuntimeException('Record is already cancelled');
    }

    if (!$filing_date) $filing_date = date('Y-m-d');
    $pfrom = (string)($rec['Period_From'] ?? '');
    $pto   = (string)($rec['Period_To']   ?? '');
    $type  = (string)($rec['Type_of_Records'] ?? '');
    $pars  = (string)($rec['Particulars'] ?? '');

    $restPars = 'RESTORATION of ' . lv_main_type($type) . ' DATED '
              . date('M j, Y', strtotime($pfrom ?: 'now'));
    if ($pto && $pto !== $pfrom) {
        $restPars .= ' - ' . date('M j, Y', strtotime($pto));
    }

    $conn->beginTransaction();
    try {
        $conn->prepare('UPDATE tbl_syl_leave_form SET Particulars = :p WHERE LID = :lid')
             ->execute([':p' => 'CANCELLED-' . $pars, ':lid' => $lid]);

        $restore = [
            'PIID'             => $rec['PIID'],
            'Type_of_Records'  => $type,
            'Date_of_Filing'   => $filing_date,
            'Period_From'      => $pfrom,
            'Period_To'        => $pto,
            'VacEarn'          => (float)($rec['VacWP']  ?? 0),
            'VacWP'            => 0,
            'VacBal'           => 0,
            'VacWOP'           => 0,
            'SickEarn'         => (float)($rec['SickWP'] ?? 0),
            'SickWP'           => 0,
            'SickBal'          => 0,
            'SickWOP'          => 0,
            'Particulars'      => $restPars,
            'DateAction'       => (string)($rec['DateAction'] ?? ''),
            'DateProcessed'    => $filing_date,
            'RecordedBy'       => $actor,
            'no_avail_VL'      => (float)($rec['no_avail_VL']      ?? 0),
            'no_avail_SL'      => (float)($rec['no_avail_SL']      ?? 0),
            'no_avail_mone_VL' => (float)($rec['no_avail_mone_VL'] ?? 0),
            'no_avail_mone_SL' => (float)($rec['no_avail_mone_SL'] ?? 0),
            'no_avail_SP'      => (float)($rec['no_avail_SP']      ?? 0),
            'no_avail_P'       => (float)($rec['no_avail_P']       ?? 0),
            'no_avail_mone'    => 0,
            'Remarks'          => (string)($rec['Remarks'] ?? ''),
        ];
        $new_lid = lv_insert_record($conn, $restore, $actor);
        lv_delete_dtr_override($conn, $lid);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }

    lv_recalculate($conn, (string)$rec['PIID'], (int)date('Y', strtotime($pfrom ?: date('Y-m-d'))));
    return $new_lid;
}

/**
 * Reschedule a leave record (transaction-wrapped).
 *
 * - Clones the row with new Period_From / Period_To (keeping all deduction amounts)
 * - Appends "-RESCHEDULED TO [mm/dd/yyyy - mm/dd/yyyy]" to original Particulars
 * - Zeros original VacWP and SickWP
 * - Deletes the original DTR override; creates a new DTR override for the cloned row
 * - Calls lv_recalculate() for both affected years
 *
 * Returns the new cloned row's LID.
 * Throws RuntimeException on invalid state.
 */
function lv_reschedule_record(
    PDO $conn, int $lid, string $new_from, string $new_to, string $filing_date, string $actor
): int {
    $rec = lv_get_record($conn, $lid);
    if (!$rec) throw new RuntimeException('Record not found');
    if (str_contains((string)($rec['Particulars'] ?? ''), '-RESCHEDULED TO ')) {
        throw new RuntimeException('Record has already been rescheduled');
    }
    if (!$new_from || !$new_to) throw new RuntimeException('New Period_From and Period_To are required');

    $from_ts = strtotime($new_from);
    $to_ts   = strtotime($new_to);
    if ($from_ts === false || $to_ts === false || $to_ts < $from_ts) {
        throw new RuntimeException('Invalid new date range');
    }

    if (!$filing_date) $filing_date = date('Y-m-d');
    $pars   = (string)($rec['Particulars'] ?? '');
    $conDay = date('m/d/Y', $from_ts) . ' - ' . date('m/d/Y', $to_ts);

    $conn->beginTransaction();
    try {
        $clone = [
            'PIID'             => $rec['PIID'],
            'Type_of_Records'  => $rec['Type_of_Records'],
            'Date_of_Filing'   => $filing_date,
            'Period_From'      => $new_from,
            'Period_To'        => $new_to,
            'VacEarn'          => (float)($rec['VacEarn']  ?? 0),
            'VacWP'            => (float)($rec['VacWP']    ?? 0),
            'VacBal'           => 0,
            'VacWOP'           => (float)($rec['VacWOP']   ?? 0),
            'SickEarn'         => (float)($rec['SickEarn'] ?? 0),
            'SickWP'           => (float)($rec['SickWP']   ?? 0),
            'SickBal'          => 0,
            'SickWOP'          => (float)($rec['SickWOP']  ?? 0),
            'Particulars'      => $pars,
            'DateAction'       => (string)($rec['DateAction'] ?? ''),
            'DateProcessed'    => $filing_date,
            'RecordedBy'       => $actor,
            'no_avail_VL'      => (float)($rec['no_avail_VL']      ?? 0),
            'no_avail_SL'      => (float)($rec['no_avail_SL']      ?? 0),
            'no_avail_mone_VL' => (float)($rec['no_avail_mone_VL'] ?? 0),
            'no_avail_mone_SL' => (float)($rec['no_avail_mone_SL'] ?? 0),
            'no_avail_SP'      => (float)($rec['no_avail_SP']      ?? 0),
            'no_avail_P'       => (float)($rec['no_avail_P']       ?? 0),
            'no_avail_mone'    => (float)($rec['no_avail_mone']    ?? 0),
            'Remarks'          => (string)($rec['Remarks'] ?? ''),
        ];
        $new_lid = lv_insert_record($conn, $clone, $actor);

        $conn->prepare(
            'UPDATE tbl_syl_leave_form SET Particulars = :p, VacWP = 0, SickWP = 0 WHERE LID = :lid'
        )->execute([':p' => $pars . '-RESCHEDULED TO ' . $conDay, ':lid' => $lid]);

        lv_delete_dtr_override($conn, $lid);
        lv_upsert_dtr_override(
            $conn, $new_lid, (string)$rec['Type_of_Records'],
            $new_from, $new_to, $pars, (string)$rec['PIID'], $actor
        );

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }

    $piid      = (string)$rec['PIID'];
    $orig_year = (int)date('Y', strtotime((string)($rec['Period_From'] ?? date('Y-m-d'))));
    $new_year  = (int)date('Y', $from_ts);
    lv_recalculate($conn, $piid, $orig_year);
    if ($new_year !== $orig_year) lv_recalculate($conn, $piid, $new_year);
    return $new_lid;
}

// ── Balance forwarded ─────────────────────────────────────────────────────────

/**
 * Get the forwarded balance row for an employee for a given year.
 * Returns null if not found.
 */
function lv_get_saved_balance(PDO $conn, string $piid, int $year): ?array {
    $stmt = $conn->prepare(
        'SELECT piid, cBackVaca, cBalSick, NoAvailVL, NoAvailSL,
                MonetaryVL, MonetarySL, year
         FROM tbl_syl_leave_available_balance
         WHERE piid = :piid AND year = :year
         LIMIT 1'
    );
    $stmt->execute([':piid' => $piid, ':year' => $year]);
    return $stmt->fetch() ?: null;
}

/**
 * Build an automatic forwarded balance from the previous year's closing row.
 * Saved balances still take precedence over this derived fallback.
 */
function lv_get_auto_forwarded_balance(PDO $conn, string $piid, int $year): ?array {
    if ($year <= 0) return null;
    $source_year = $year - 1;
    if ($source_year <= 0) return null;

    $prev_records = lv_get_records($conn, $piid, $source_year);
    if (!empty($prev_records)) {
        $last = $prev_records[count($prev_records) - 1];
        return [
            'piid'        => $piid,
            'year'        => $year,
            'cBackVaca'   => (float)($last['VacBal'] ?? 0),
            'cBalSick'    => (float)($last['SickBal'] ?? 0),
            'NoAvailVL'   => (float)($last['no_avail_VL'] ?? 0),
            'NoAvailSL'   => (float)($last['no_avail_SL'] ?? 0),
            'MonetaryVL'  => (float)($last['no_avail_mone_VL'] ?? 0),
            'MonetarySL'  => (float)($last['no_avail_mone_SL'] ?? 0),
            'sourceYear'  => $source_year,
            'isAutoDerived' => 1,
        ];
    }

    $prev_saved = lv_get_saved_balance($conn, $piid, $source_year);
    if ($prev_saved) {
        return [
            'piid'        => $piid,
            'year'        => $year,
            'cBackVaca'   => (float)($prev_saved['cBackVaca'] ?? 0),
            'cBalSick'    => (float)($prev_saved['cBalSick'] ?? 0),
            'NoAvailVL'   => (float)($prev_saved['NoAvailVL'] ?? 0),
            'NoAvailSL'   => (float)($prev_saved['NoAvailSL'] ?? 0),
            'MonetaryVL'  => (float)($prev_saved['MonetaryVL'] ?? 0),
            'MonetarySL'  => (float)($prev_saved['MonetarySL'] ?? 0),
            'sourceYear'  => $source_year,
            'isAutoDerived' => 1,
        ];
    }

    return null;
}

function lv_get_balance(PDO $conn, string $piid, int $year): ?array {
    $saved = lv_get_saved_balance($conn, $piid, $year);
    if ($saved) {
        $saved['sourceYear'] = $year - 1;
        $saved['isAutoDerived'] = 0;
        return $saved;
    }

    return lv_get_auto_forwarded_balance($conn, $piid, $year);
}

/**
 * Upsert the forwarded balance row for an employee/year.
 */
function lv_upsert_balance(PDO $conn, string $piid, int $year, array $d): void {
    $stmt = $conn->prepare(
        'INSERT INTO tbl_syl_leave_available_balance
            (piid, year, cBackVaca, cBalSick, NoAvailVL, NoAvailSL, MonetaryVL, MonetarySL)
         VALUES (:piid, :year, :vac, :sick, :nvl, :nsl, :mvl, :msl)
         ON DUPLICATE KEY UPDATE
            cBackVaca  = VALUES(cBackVaca),
            cBalSick   = VALUES(cBalSick),
            NoAvailVL  = VALUES(NoAvailVL),
            NoAvailSL  = VALUES(NoAvailSL),
            MonetaryVL = VALUES(MonetaryVL),
            MonetarySL = VALUES(MonetarySL)'
    );
    $stmt->execute([
        ':piid' => $piid,
        ':year' => $year,
        ':vac'  => $d['cBackVaca']  ?? 0,
        ':sick' => $d['cBalSick']   ?? 0,
        ':nvl'  => $d['NoAvailVL']  ?? 0,
        ':nsl'  => $d['NoAvailSL']  ?? 0,
        ':mvl'  => $d['MonetaryVL'] ?? 0,
        ':msl'  => $d['MonetarySL'] ?? 0,
    ]);
}

// ── Recalculation ─────────────────────────────────────────────────────────────

/**
 * Recalculate running VL and SL balances for all records in a year.
 *
 * Mirrors VB frmRevisedLeaveMngmnt.recalculateRecord():
 *  - Starts from forwarded balance (cBackVaca / cBalSick)
 *  - Each record: balance = prev_balance + earned - with_pay - without_pay - monetized
 *  - Updates VacBal / SickBal in-place.
 */
function lv_recalculate(PDO $conn, string $piid, int $year): void {
    $balance = lv_get_balance($conn, $piid, $year);
    $vBal  = (float)($balance['cBackVaca'] ?? 0);
    $sBal  = (float)($balance['cBalSick']  ?? 0);

    $records = lv_get_records($conn, $piid, $year);
    if (empty($records)) return;

    $upd = $conn->prepare(
        'UPDATE tbl_syl_leave_form SET VacBal = :vbal, SickBal = :sbal WHERE LID = :lid'
    );

    foreach ($records as $row) {
        $vBal = $vBal
            + (float)$row['VacEarn']
            - (float)$row['VacWP']
            - (float)$row['VacWOP']
            - (float)$row['no_avail_mone_VL'];

        $sBal = $sBal
            + (float)$row['SickEarn']
            - (float)$row['SickWP']
            - (float)$row['SickWOP']
            - (float)$row['no_avail_mone_SL'];

        $upd->execute([':vbal' => round($vBal, 4), ':sbal' => round($sBal, 4), ':lid' => $row['LID']]);
    }
}

// ── Credits earned lookup table ───────────────────────────────────────────────

/**
 * Get all rows from the credits-earned lookup table.
 */
function lv_get_credits_earned(PDO $conn): array {
    $stmt = $conn->query(
        'SELECT LCEID, No_Of_Days_Present, On_Leave_Without_Pay, Leave_Credits_Earned
         FROM tbl_syl_leave_credits_earned
         ORDER BY No_Of_Days_Present ASC, On_Leave_Without_Pay ASC'
    );
    return $stmt->fetchAll();
}

/**
 * Replace the entire credits-earned lookup table with new rows.
 * Accepts array of ['days_present', 'lwop', 'credits'] maps.
 */
function lv_save_credits_earned(PDO $conn, array $rows): void {
    $conn->exec('TRUNCATE TABLE tbl_syl_leave_credits_earned');
    $stmt = $conn->prepare(
        'INSERT INTO tbl_syl_leave_credits_earned
            (No_Of_Days_Present, On_Leave_Without_Pay, Leave_Credits_Earned)
         VALUES (:dp, :lwop, :ce)'
    );
    foreach ($rows as $r) {
        $stmt->execute([
            ':dp'   => $r['days_present'],
            ':lwop' => $r['lwop'],
            ':ce'   => $r['credits'],
        ]);
    }
}

// ── Working-day conversion table ─────────────────────────────────────────────

/**
 * Get all rows from the working-day conversion table.
 */
function lv_get_conversion(PDO $conn): array {
    $stmt = $conn->query(
        "SELECT CWHMID, Time, Type, Equivalent_Day
         FROM tbl_syl_leave_conversion_of_working_day
         ORDER BY Type ASC, Time ASC"
    );
    return $stmt->fetchAll();
}

/**
 * Replace the entire conversion table with new rows.
 * Accepts array of ['time', 'type', 'equivalent_day'] maps.
 */
function lv_save_conversion(PDO $conn, array $rows): void {
    $conn->exec('TRUNCATE TABLE tbl_syl_leave_conversion_of_working_day');
    $stmt = $conn->prepare(
        'INSERT INTO tbl_syl_leave_conversion_of_working_day (Time, Type, Equivalent_Day)
         VALUES (:time, :type, :equiv)'
    );
    foreach ($rows as $r) {
        $stmt->execute([
            ':time'  => $r['time'],
            ':type'  => $r['type'],   // 'Hr' or 'Min'
            ':equiv' => $r['equivalent_day'],
        ]);
    }
}

// ── Dashboard stats ───────────────────────────────────────────────────────────

/**
 * Return aggregate counts for the dashboard stats strip.
 */
function lv_dashboard_stats(PDO $conn): array {
    $year = (int)date('Y');
    $month = (int)date('n');
    lv_ensure_employee_roster($conn, $year, $month);

    $empStmt = $conn->prepare(
        'SELECT COUNT(*) FROM ' . _LV_EMP_ROSTER_TABLE . '
         WHERE SnapshotYear = :snapshot_year
           AND SnapshotMonth = :snapshot_month'
    );
    $empStmt->execute([
        ':snapshot_year' => $year,
        ':snapshot_month' => $month,
    ]);
    $employees = (int)$empStmt->fetchColumn();

    $pds = 0; // pds_personal_info is in the cgmhris DB, not queried here

    $leaveStmt = $conn->prepare(
        "SELECT COUNT(*) FROM tbl_syl_leave_form WHERE YEAR(Period_From) = :yr AND isDeleted = 0"
    );
    $leaveStmt->execute([':yr' => $year]);
    $leave = (int)$leaveStmt->fetchColumn();

    $deptStmt = $conn->prepare(
        'SELECT COUNT(DISTINCT Department) FROM ' . _LV_EMP_ROSTER_TABLE . '
         WHERE SnapshotYear = :snapshot_year
           AND SnapshotMonth = :snapshot_month'
    );
    $deptStmt->execute([
        ':snapshot_year' => $year,
        ':snapshot_month' => $month,
    ]);
    $depts = (int)$deptStmt->fetchColumn();

    return [
        'employees'   => $employees,
        'pds'         => $pds,
        'leave'       => $leave,
        'departments' => $depts,
    ];
}

// ── Monthly / quarterly report helpers ───────────────────────────────────────

function lv_report_employee_base(PDO $conn, int $year, int $cutoff_month, string $dept = ''): array {
    lv_ensure_employee_roster($conn, $year, $cutoff_month);
    $params = [];
    $where = ['1'];

    if ($dept !== '') {
        $where[] = 'Department = :dept';
        $params[':dept'] = $dept;
    }

    $stmt = $conn->prepare(
        'SELECT PIID,
                Surname,
                Firstname,
                MiddleName,
                Department,
                Position
         FROM ' . _LV_EMP_ROSTER_TABLE . '
         WHERE SnapshotYear = :snapshot_year
           AND SnapshotMonth = :snapshot_month
           AND ' . implode(' AND ', $where) . '
         ORDER BY Department, Surname, Firstname'
    );
    $params[':snapshot_year'] = $year;
    $params[':snapshot_month'] = $cutoff_month;
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function lv_report_rows_up_to_cutoff(PDO $conn, array $piids, int $year, string $cutoff_date): array {
    if (empty($piids)) return [];
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare(
        "SELECT LID, PIID, Type_of_Records, Date_of_Filing, Period_From, Period_To,
                VacBal, SickBal, no_avail_VL, no_avail_SL,
                no_avail_mone_VL, no_avail_mone_SL,
                no_avail_SP, no_avail_P
         FROM tbl_syl_leave_form
         WHERE isDeleted = 0
           AND PIID IN ($placeholders)
           AND Date_of_Filing BETWEEN ? AND ?
         ORDER BY Date_of_Filing ASC, DateProcessed ASC, Period_From ASC, LID ASC"
    );
    $params = array_values($piids);
    $params[] = sprintf('%04d-01-01', $year);
    $params[] = $cutoff_date;
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function lv_report_rows_in_period(PDO $conn, array $piids, string $period_from, string $period_to): array {
    if (empty($piids)) return [];
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare(
        "SELECT LID, PIID, Type_of_Records, Date_of_Filing, Period_From, Period_To,
                VacWP, VacWOP, SickWP, SickWOP,
                no_avail_mone_VL, no_avail_mone_SL, no_avail_SP, no_avail_P
         FROM tbl_syl_leave_form
         WHERE isDeleted = 0
           AND PIID IN ($placeholders)
           AND Period_From BETWEEN ? AND ?
         ORDER BY Date_of_Filing ASC, DateProcessed ASC, Period_From ASC, LID ASC"
    );
    $params = array_values($piids);
    $params[] = $period_from;
    $params[] = $period_to;
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function lv_report_forwarded_balances(PDO $conn, array $piids, int $year): array {
    if (empty($piids)) return [];
    $placeholders = implode(',', array_fill(0, count($piids), '?'));
    $stmt = $conn->prepare(
        "SELECT piid, cBackVaca, cBalSick, NoAvailVL, NoAvailSL
         FROM tbl_syl_leave_available_balance
         WHERE year = ?
           AND piid IN ($placeholders)"
    );
    $params = [$year];
    foreach ($piids as $piid) {
        $params[] = $piid;
    }
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[$row['piid']] = $row;
    }
    return $rows;
}

function lv_report_type_matches(string $type, array $needles): bool {
    $type = strtolower(trim($type));
    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($type, strtolower($needle))) {
            return true;
        }
    }
    return false;
}

function lv_report_main_type(string $type): string {
    $parts = explode('|', $type, 2);
    return trim($parts[0] ?? $type);
}

function lv_report_period_display(array $row): string {
    $from = trim((string)($row['Period_From'] ?? ''));
    $to = trim((string)($row['Period_To'] ?? ''));
    $source = $from !== '' ? $from : trim((string)($row['Date_of_Filing'] ?? ''));
    if ($source === '') return '';

    $dtFrom = strtotime($source);
    if ($dtFrom === false) return $source;

    $toSource = $to !== '' ? $to : $source;
    $dtTo = strtotime($toSource);
    if ($dtTo === false) {
        return date('M j, Y', $dtFrom);
    }

    if (date('Y-m-d', $dtFrom) === date('Y-m-d', $dtTo)) {
        return date('M j, Y', $dtFrom);
    }

    if (date('Y-m', $dtFrom) === date('Y-m', $dtTo)) {
        return date('M j', $dtFrom) . '-' . date('j, Y', $dtTo);
    }

    if (date('Y', $dtFrom) === date('Y', $dtTo)) {
        return date('M j', $dtFrom) . '-' . date('M j, Y', $dtTo);
    }

    return date('M j, Y', $dtFrom) . ' - ' . date('M j, Y', $dtTo);
}

function lv_report_has_vacation_application(array $row, string $type): bool {
    $mainType = strtolower(lv_report_main_type($type));

    if (in_array($mainType, [
        'leave earned',
        'balance forwarded',
        'monetization of leave credits',
        'rehabilitation privilege',
        'audit action and findings',
    ], true)) {
        return false;
    }

    return in_array($mainType, [
        'vacation leave',
        'vacation leave w/o pay',
        'mandatory/forced leave',
        'special leave vacation leave',
        'unused force vacation leave',
        'undertime',
        'undertime (subject to vacation leave)',
    ], true);
}

function lv_report_has_sick_application(array $row, string $type): bool {
    $mainType = strtolower(lv_report_main_type($type));

    if (in_array($mainType, [
        'leave earned',
        'balance forwarded',
        'monetization of leave credits',
        'rehabilitation privilege',
        'audit action and findings',
    ], true)) {
        return false;
    }

    return in_array($mainType, [
        'sick leave',
        'sick leave w/o pay',
    ], true);
}

function lv_report_is_monetization(string $type): bool {
    return strtolower(lv_report_main_type($type)) === 'monetization of leave credits';
}

/**
 * Get leave balance report per employee for a given year and period.
 * Balances are derived from the latest ledger row on or before the selected
 * period cutoff date. Activity fields use the selected month/quarter window.
 */
function lv_report_summary(PDO $conn, int $year, ?int $month_from = null, ?int $month_to = null, string $dept = ''): array {
    $month_from = $month_from !== null ? max(1, min(12, $month_from)) : 1;
    $month_to   = $month_to   !== null ? max(1, min(12, $month_to))   : 12;
    if ($month_from > $month_to) {
        [$month_from, $month_to] = [$month_to, $month_from];
    }

    $period_from = sprintf('%04d-%02d-01', $year, $month_from);
    $cutoff_date = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $month_to)));

    $employees = lv_report_employee_base($conn, $year, $month_to, $dept);
    if (empty($employees)) return [];

    $piids = array_column($employees, 'PIID');
    $up_to_cutoff = lv_report_rows_up_to_cutoff($conn, $piids, $year, $cutoff_date);
    $in_period = lv_report_rows_in_period($conn, $piids, $period_from, $cutoff_date);
    $forwarded = lv_report_forwarded_balances($conn, $piids, $year);

    $latest_by_piid = [];
    foreach ($up_to_cutoff as $row) {
        $latest_by_piid[$row['PIID']] = $row;
    }

    $activity = [];
    foreach ($in_period as $row) {
        $piid = $row['PIID'];
        if (!isset($activity[$piid])) {
            $activity[$piid] = [
                'vac_applied_display' => '',
                'sick_applied_display' => '',
                'vac_applied_dates' => [],
                'sick_applied_dates' => [],
                'monetized_vl' => 0.0,
                'monetized_sl' => 0.0,
            ];
        }

        $type = (string)($row['Type_of_Records'] ?? '');
        $display = lv_report_period_display($row);

        if ($display !== '' && lv_report_has_vacation_application($row, $type)) {
            $activity[$piid]['vac_applied_dates'][$display] = true;
        }

        if ($display !== '' && lv_report_has_sick_application($row, $type)) {
            $activity[$piid]['sick_applied_dates'][$display] = true;
        }

        if (lv_report_is_monetization($type)) {
            $vlMonetized = (float)($row['no_avail_mone_VL'] ?? 0);
            $slMonetized = (float)($row['no_avail_mone_SL'] ?? 0);

            // Existing ledger rows often store monetization in the same
            // visible vacation/sick deduction fields shown on leave_form.
            if ($vlMonetized == 0.0) {
                $vlMonetized = (float)($row['VacWP'] ?? 0);
            }
            if ($slMonetized == 0.0) {
                $slMonetized = (float)($row['SickWP'] ?? 0);
            }

            $activity[$piid]['monetized_vl'] += $vlMonetized;
            $activity[$piid]['monetized_sl'] += $slMonetized;
        } else {
            $activity[$piid]['monetized_vl'] += (float)($row['no_avail_mone_VL'] ?? 0);
            $activity[$piid]['monetized_sl'] += (float)($row['no_avail_mone_SL'] ?? 0);
        }
    }

    $report = [];
    foreach ($employees as $emp) {
        $piid = $emp['PIID'];
        $latest = $latest_by_piid[$piid] ?? null;
        $fwd = $forwarded[$piid] ?? null;
        $act = $activity[$piid] ?? [
            'vac_applied_display' => '',
            'sick_applied_display' => '',
            'vac_applied_dates' => [],
            'sick_applied_dates' => [],
            'monetized_vl' => 0.0,
            'monetized_sl' => 0.0,
        ];

        $emp['vac_balance'] = $latest !== null
            ? (float)($latest['VacBal'] ?? 0)
            : (float)($fwd['cBackVaca'] ?? 0);
        $emp['sick_balance'] = $latest !== null
            ? (float)($latest['SickBal'] ?? 0)
            : (float)($fwd['cBalSick'] ?? 0);
        $emp['vac_applied_display'] = !empty($act['vac_applied_dates'])
            ? implode('; ', array_keys($act['vac_applied_dates']))
            : '';
        $emp['sick_applied_display'] = !empty($act['sick_applied_dates'])
            ? implode('; ', array_keys($act['sick_applied_dates']))
            : '';
        $emp['monetized_vl'] = round($act['monetized_vl'], 4);
        $emp['monetized_sl'] = round($act['monetized_sl'], 4);
        $emp['available_mandatory_forced_leave'] = $latest !== null
            ? (float)($latest['no_avail_VL'] ?? 0)
            : (float)($fwd['NoAvailVL'] ?? 0);
        $emp['available_special_leave'] = $latest !== null
            ? (float)($latest['no_avail_SL'] ?? 0)
            : (float)($fwd['NoAvailSL'] ?? 0);
        $emp['cutoff_date'] = $cutoff_date;

        if (
            $emp['vac_balance'] == 0.0 &&
            $emp['sick_balance'] == 0.0 &&
            $emp['vac_applied_display'] === '' &&
            $emp['sick_applied_display'] === '' &&
            $emp['monetized_vl'] == 0.0 &&
            $emp['monetized_sl'] == 0.0 &&
            $emp['available_mandatory_forced_leave'] == 0.0 &&
            $emp['available_special_leave'] == 0.0
        ) {
            continue;
        }

        $report[] = $emp;
    }

    return $report;
}
