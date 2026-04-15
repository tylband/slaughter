<?php
require_once __DIR__ . '/cors.php';
header("Content-Type: application/json");

require_once 'db_auth.php';

function sendResponse($status, $message, $data = null, $code = 200, $meta = null) {
    http_response_code($code);

    $payload = [
        'status' => $status,
        'message' => $message
    ];

    if ($data !== null) {
        $payload['data'] = $data;
    }

    if ($meta !== null) {
        $payload['meta'] = $meta;
    }

    echo json_encode($payload);
    exit;
}

function normalizeString($value) {
    return trim((string)$value);
}

function normalizeStatus($status) {
    $normalized = strtoupper(normalizeString($status));
    $allowed = ['PENDING', 'PROCESSING', 'READY_FOR_RELEASE', 'RELEASED', 'CANCELLED'];
    if (!in_array($normalized, $allowed, true)) {
        return 'PROCESSING';
    }
    return $normalized;
}

function normalizeTestType($testType) {
    $normalized = strtoupper(normalizeString($testType));
    $allowed = ['BLOOD_CHEM'];
    if (!in_array($normalized, $allowed, true)) {
        return 'BLOOD_CHEM';
    }
    return $normalized;
}

function normalizeFlag($value) {
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_numeric($value)) {
        return ((int)$value) > 0 ? 1 : 0;
    }

    $v = strtolower(normalizeString($value));
    return in_array($v, ['1', 'true', 'yes', 'y', 'employee'], true) ? 1 : 0;
}

function normalizeDateOnly($value) {
    $value = normalizeString($value);
    if ($value === '') {
        return null;
    }

    try {
        $date = new DateTime($value);
    } catch (Exception $e) {
        return false;
    }

    return $date->format('Y-m-d');
}

function quoteIdentifier($identifier) {
    return '`' . str_replace('`', '', (string)$identifier) . '`';
}

function getTableColumns($conn, $table) {
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = $conn->query('DESCRIBE ' . quoteIdentifier($table));
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $rows = [];
    }

    $columns = [];
    foreach ($rows as $row) {
        if (isset($row['Field'])) {
            $columns[] = $row['Field'];
        }
    }

    $cache[$table] = $columns;
    return $columns;
}

function getTableColumnDefinition($conn, $table, $column) {
    try {
        $stmt = $conn->query('DESCRIBE ' . quoteIdentifier($table));
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return null;
    }

    foreach ($rows as $row) {
        if ((string)($row['Field'] ?? '') === (string)$column) {
            return $row;
        }
    }

    return null;
}

function pickColumn($columns, $candidates) {
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function ensureLaboratoryResultsTable($conn) {
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    if (!$conn) {
        $ready = false;
        return false;
    }

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS tbl_laboratory_results (
            result_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            piid VARCHAR(64) NOT NULL,
            test_name VARCHAR(150) NOT NULL,
            test_type VARCHAR(50) NOT NULL DEFAULT 'BLOOD_CHEM',
            is_employee TINYINT(1) NOT NULL DEFAULT 0,
            request_date DATE NULL,
            result_summary VARCHAR(255) NOT NULL,
            result_value TEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'PROCESSING',
            result_date DATE NULL,
            remarks TEXT NULL,
            created_by VARCHAR(80) NULL,
            updated_by VARCHAR(80) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY (result_id),
            KEY idx_lab_result_piid_date (piid, result_date),
            KEY idx_lab_result_status_date (status, result_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $existingCols = getTableColumns($conn, 'tbl_laboratory_results');
        if (!in_array('test_type', $existingCols, true)) {
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD COLUMN test_type VARCHAR(50) NOT NULL DEFAULT 'BLOOD_CHEM' AFTER test_name");
        }
        if (!in_array('is_employee', $existingCols, true)) {
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD COLUMN is_employee TINYINT(1) NOT NULL DEFAULT 0 AFTER test_type");
        }
        if (!in_array('request_date', $existingCols, true)) {
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD COLUMN request_date DATE NULL AFTER is_employee");
        }

        $resultDateDef = getTableColumnDefinition($conn, 'tbl_laboratory_results', 'result_date');
        if ($resultDateDef && strtoupper((string)($resultDateDef['Null'] ?? 'NO')) !== 'YES') {
            $conn->exec("ALTER TABLE tbl_laboratory_results MODIFY COLUMN result_date DATE NULL");
        }

        if (!in_array('or_number', $existingCols, true)) {
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD COLUMN or_number VARCHAR(50) NULL AFTER remarks");
        }
        if (!in_array('payment_required', $existingCols, true)) {
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD COLUMN payment_required TINYINT(1) NOT NULL DEFAULT 0 AFTER or_number");
        }
        if (!in_array('is_deleted', $existingCols, true)) {
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_required");
        }
        if (!in_array('lab_patient_id', $existingCols, true)) {
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD COLUMN lab_patient_id BIGINT UNSIGNED NULL AFTER piid");
            $conn->exec("ALTER TABLE tbl_laboratory_results ADD KEY idx_lab_result_lab_patient_id (lab_patient_id)");
        }

        $ready = true;
    } catch (Throwable $e) {
        error_log('Unable to ensure laboratory results table: ' . $e->getMessage());
        $ready = false;
    }

    return $ready;
}

function readPayload() {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        sendResponse('error', 'Invalid JSON format.', null, 400);
    }

    return $decoded;
}

function getPersonColumns($conn) {
    $columns = getTableColumns($conn, 'tbl_personal_details');
    if (empty($columns)) {
        return null;
    }

    $meta = [
        'piid' => pickColumn($columns, ['PIID', 'piid']),
        'surname' => pickColumn($columns, ['Surname', 'surname']),
        'firstname' => pickColumn($columns, ['FirstName', 'Firstname', 'firstname', 'first_name']),
        'middlename' => pickColumn($columns, ['MiddleName', 'Middlename', 'middlename', 'middle_name']),
        'nameext' => pickColumn($columns, ['NameExt', 'Name_Ext', 'nameext', 'name_ext']),
        'sex' => pickColumn($columns, ['Sex', 'sex']),
        'birthdate' => pickColumn($columns, ['Birthdate', 'birthdate']),
        'barangay' => pickColumn($columns, ['Barangay', 'barangay']),
        'city' => pickColumn($columns, ['City', 'city'])
    ];

    if (!$meta['piid'] || !$meta['surname'] || !$meta['firstname']) {
        return null;
    }

    return $meta;
}

function buildPersonDisplayExpr($meta) {
    $surname = quoteIdentifier($meta['surname']);
    $firstname = quoteIdentifier($meta['firstname']);
    $middlename = $meta['middlename'] ? quoteIdentifier($meta['middlename']) : null;
    $nameext = $meta['nameext'] ? quoteIdentifier($meta['nameext']) : null;
    $piid = quoteIdentifier($meta['piid']);

    $middleExpr = $middlename
        ? "IFNULL(CONCAT(NULLIF(LEFT({$middlename}, 1), ''), '. '), '')"
        : "''";

    $nameExtExpr = $nameext
        ? "IFNULL(CONCAT(NULLIF({$nameext}, ''), ' '), '')"
        : "''";

    return "CONCAT({$surname}, ', ', {$firstname}, ' ', {$middleExpr}, {$nameExtExpr}, {$piid})";
}

function getPersonByPiid($conn, $piid, $meta) {
    $stmt = $conn->prepare(
        'SELECT * FROM tbl_personal_details WHERE ' . quoteIdentifier($meta['piid']) . ' = ? LIMIT 1'
    );
    $stmt->execute([$piid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function formatPersonName($row, $meta) {
    $parts = [];

    $surname = trim((string)($row[$meta['surname']] ?? ''));
    $firstname = trim((string)($row[$meta['firstname']] ?? ''));
    $middlename = $meta['middlename'] ? trim((string)($row[$meta['middlename']] ?? '')) : '';
    $nameext = $meta['nameext'] ? trim((string)($row[$meta['nameext']] ?? '')) : '';
    $piid = trim((string)($row[$meta['piid']] ?? ''));

    if ($surname !== '') {
        $parts[] = $surname . ',';
    }
    if ($firstname !== '') {
        $parts[] = $firstname;
    }
    if ($middlename !== '') {
        $parts[] = strtoupper(substr($middlename, 0, 1)) . '.';
    }
    if ($nameext !== '') {
        $parts[] = $nameext;
    }
    if ($piid !== '') {
        $parts[] = $piid;
    }

    return trim(implode(' ', $parts));
}

// ---------------------------------------------------------------------------
// tbl_lab_personal_info helpers
// ---------------------------------------------------------------------------

function ensureLabPersonalInfoTable($conn) {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!$conn) {
        $ready = false;
        return false;
    }
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS tbl_lab_personal_info (
            lab_patient_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            piid           VARCHAR(64)  NULL,
            surname        VARCHAR(100) NOT NULL,
            first_name     VARCHAR(100) NOT NULL,
            middle_name    VARCHAR(100) NULL,
            name_ext       VARCHAR(20)  NULL,
            sex            VARCHAR(10)  NOT NULL DEFAULT '',
            birthdate      DATE         NULL,
            city           VARCHAR(100) NULL,
            barangay       VARCHAR(100) NULL,
            created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME NULL,
            PRIMARY KEY (lab_patient_id),
            UNIQUE KEY uq_lab_piid (piid),
            KEY idx_lab_patient_name (surname, first_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ready = true;
    } catch (Throwable $e) {
        error_log('Unable to ensure lab personal info table: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

function formatLabPatientDisplayName($row) {
    $parts    = [];
    $surname  = trim((string)($row['surname']     ?? ''));
    $first    = trim((string)($row['first_name']  ?? ''));
    $middle   = trim((string)($row['middle_name'] ?? ''));
    $ext      = trim((string)($row['name_ext']    ?? ''));
    $piid     = trim((string)($row['piid']        ?? ''));

    if ($surname !== '') $parts[] = $surname . ',';
    if ($first   !== '') $parts[] = $first;
    if ($middle  !== '') $parts[] = strtoupper(substr($middle, 0, 1)) . '.';
    if ($ext     !== '') $parts[] = $ext;
    if ($piid    !== '') $parts[] = $piid;

    return trim(implode(' ', $parts));
}

function getLabPatientByPiid($conn, $piid) {
    if ($piid === '') {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT * FROM tbl_lab_personal_info WHERE piid = :piid LIMIT 1'
    );
    $stmt->execute([':piid' => $piid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Upsert a patient into tbl_lab_personal_info using data from tbl_personal_details.
 * Returns the lab_patient_id on success, or 0 on failure.
 */
function upsertLabPatient($conn, $piid, $personRow, $personMeta) {
    $surname    = normalizeString($personRow[$personMeta['surname']]    ?? '');
    $firstName  = normalizeString($personRow[$personMeta['firstname']]  ?? '');
    $middleName = $personMeta['middlename'] ? normalizeString($personRow[$personMeta['middlename']] ?? '') : '';
    $nameExt    = $personMeta['nameext']    ? normalizeString($personRow[$personMeta['nameext']]    ?? '') : '';
    $sex        = $personMeta['sex']        ? normalizeString($personRow[$personMeta['sex']]        ?? '') : '';
    $birthdateRaw = $personMeta['birthdate'] ? normalizeString($personRow[$personMeta['birthdate']] ?? '') : '';
    $birthdate  = ($birthdateRaw !== '' && $birthdateRaw !== '0000-00-00') ? $birthdateRaw : null;
    $city       = $personMeta['city']       ? normalizeString($personRow[$personMeta['city']]       ?? '') : '';
    $barangay   = $personMeta['barangay']   ? normalizeString($personRow[$personMeta['barangay']]   ?? '') : '';

    try {
        $stmt = $conn->prepare(
            "INSERT INTO tbl_lab_personal_info
                (piid, surname, first_name, middle_name, name_ext, sex, birthdate, city, barangay)
             VALUES
                (:piid, :surname, :first_name, :middle_name, :name_ext, :sex, :birthdate, :city, :barangay)
             ON DUPLICATE KEY UPDATE
                surname     = VALUES(surname),
                first_name  = VALUES(first_name),
                middle_name = VALUES(middle_name),
                name_ext    = VALUES(name_ext),
                sex         = VALUES(sex),
                birthdate   = VALUES(birthdate),
                city        = VALUES(city),
                barangay    = VALUES(barangay)"
        );
        $stmt->execute([
            ':piid'        => $piid ?: null,
            ':surname'     => $surname,
            ':first_name'  => $firstName,
            ':middle_name' => $middleName ?: null,
            ':name_ext'    => $nameExt    ?: null,
            ':sex'         => $sex,
            ':birthdate'   => $birthdate,
            ':city'        => $city     ?: null,
            ':barangay'    => $barangay ?: null,
        ]);

        // Retrieve the lab_patient_id (works for both INSERT and UPDATE)
        $find = $conn->prepare(
            'SELECT lab_patient_id FROM tbl_lab_personal_info WHERE piid = :piid LIMIT 1'
        );
        $find->execute([':piid' => $piid]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['lab_patient_id'] : 0;
    } catch (Throwable $e) {
        error_log('upsertLabPatient error: ' . $e->getMessage());
        return 0;
    }
}

// ---------------------------------------------------------------------------
// tbl_lab_fees helpers
// ---------------------------------------------------------------------------

function ensureLabFeesTable($conn) {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!$conn) {
        $ready = false;
        return false;
    }
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS tbl_lab_fees (
            fee_id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            test_type      VARCHAR(50)   NOT NULL,
            test_name      VARCHAR(150)  NULL,
            amount         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            effective_from DATE          NOT NULL DEFAULT '2000-01-01',
            description    VARCHAR(255)  NULL,
            is_active      TINYINT(1)    NOT NULL DEFAULT 1,
            created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME NULL,
            PRIMARY KEY (fee_id),
            UNIQUE KEY uq_lab_fee (test_type, test_name, effective_from),
            KEY idx_lab_fee_type (test_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Migration: add effective_from column and update unique key for existing tables
        $feeCols = array_column($conn->query("SHOW COLUMNS FROM tbl_lab_fees")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        if (!in_array('effective_from', $feeCols, true)) {
            $conn->exec("ALTER TABLE tbl_lab_fees ADD COLUMN effective_from DATE NOT NULL DEFAULT '2000-01-01' AFTER amount");
            try { $conn->exec("ALTER TABLE tbl_lab_fees DROP INDEX uq_lab_fee"); } catch (Throwable $e) {}
            try { $conn->exec("ALTER TABLE tbl_lab_fees ADD UNIQUE KEY uq_lab_fee (test_type, test_name, effective_from)"); } catch (Throwable $e) {}
        }
        $ready = true;
    } catch (Throwable $e) {
        error_log('Unable to ensure lab fees table: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

/**
 * Load all active fees into a two-level array keyed by [UPPER(test_type)][lower(test_name)].
 * A NULL test_name row is stored under the '_default' key for its test_type.
 */
function loadLabFees($conn) {
    try {
        $stmt = $conn->query(
            "SELECT test_type, test_name, amount, effective_from
             FROM tbl_lab_fees
             WHERE is_active = 1
             ORDER BY test_type, test_name, effective_from DESC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $fees = [];
    foreach ($rows as $row) {
        $typeKey = strtoupper(trim((string)($row['test_type'] ?? '')));
        $nameRaw = $row['test_name'];
        $amount  = (float)($row['amount'] ?? 0);
        $eff     = (string)($row['effective_from'] ?? '2000-01-01');
        $nameKey = ($nameRaw === null || trim($nameRaw) === '') ? '_default' : strtolower(trim($nameRaw));
        $fees[$typeKey][$nameKey][] = ['eff' => $eff, 'amt' => $amount];
    }
    return $fees;
}

/**
 * Look up a fee from a pre-loaded fees array.
 * Entries per key are sorted by effective_from DESC; we return the first entry
 * whose effective_from is <= $date.  If $date is null, the latest entry is used.
 */
function lookupFee($fees, $testType, $testName, $date = null) {
    $typeKey = strtoupper(trim((string)$testType));
    $nameKey = strtolower(trim((string)$testName));
    $keys = ($nameKey !== '' && $nameKey !== '_default') ? [$nameKey, '_default'] : ['_default'];
    foreach ($keys as $key) {
        if (!isset($fees[$typeKey][$key])) continue;
        foreach ($fees[$typeKey][$key] as $entry) {
            if ($date === null || $entry['eff'] <= $date) {
                return $entry['amt'];
            }
        }
    }
    return null;
}

function requireLabAccess($userData) {
    if (!userHasModuleAccess($userData, 'subsystem_laboratory')) {
        sendResponse('error', 'Laboratory access denied.', null, 403);
    }
}

function requireLabAdmin($userData) {
    requireLabAccess($userData);
    $roleKey = strtolower(trim((string)($userData['role'] ?? 'user')));
    $isAdmin = ($roleKey === 'super_admin' || $roleKey === 'superadmin' || $roleKey === 'admin');
    if (!$isAdmin) {
        sendResponse('error', 'Administrator access required for this action.', null, 403);
    }
}

$userData = validateToken();
if (!$userData) {
    sendResponse('error', 'Invalid or expired token.', null, 401);
}

requireLabAccess($userData);

if (!$conn) {
    sendResponse('error', 'Database connection not available.', null, 500);
}

$personMeta = getPersonColumns($conn);
if (!$personMeta) {
    sendResponse('error', 'Personal details table is missing required columns.', null, 500);
}

if (!ensureLaboratoryResultsTable($conn)) {
    sendResponse('error', 'Laboratory table is unavailable.', null, 500);
}

if (!ensureLabPersonalInfoTable($conn)) {
    sendResponse('error', 'Laboratory patient info table is unavailable.', null, 500);
}

if (!ensureLabFeesTable($conn)) {
    sendResponse('error', 'Laboratory fees table is unavailable.', null, 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = normalizeString($_GET['action'] ?? 'person_search');

    if ($action === 'person_search') {
        $search = normalizeString($_GET['search'] ?? '');
        if ($search === '' || strlen($search) < 2) {
            sendResponse('success', 'No search term.', [], 200, ['count' => 0]);
        }

        $likeSearch = '%' . $search . '%';
        $results    = [];
        $foundPiids = [];

        // --- Step 1: search tbl_lab_personal_info first (returning patients) ---
        $labStmt = $conn->prepare(
            "SELECT lab_patient_id, piid, surname, first_name, middle_name, name_ext
             FROM tbl_lab_personal_info
             WHERE surname    LIKE :search
                OR first_name LIKE :search
                OR piid       LIKE :search
             ORDER BY surname ASC, first_name ASC
             LIMIT 25"
        );
        $labStmt->execute([':search' => $likeSearch]);
        foreach ($labStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[] = [
                'PIID'         => (string)($row['piid'] ?? ''),
                'Surname'      => (string)($row['surname'] ?? ''),
                'FirstName'    => (string)($row['first_name'] ?? ''),
                'MiddleName'   => (string)($row['middle_name'] ?? ''),
                'NameExt'      => (string)($row['name_ext'] ?? ''),
                'display_name' => formatLabPatientDisplayName($row),
            ];
            if ((string)($row['piid'] ?? '') !== '') {
                $foundPiids[] = (string)$row['piid'];
            }
        }

        // --- Step 2: fall back to tbl_personal_details for anyone not yet in lab table ---
        $remaining = 25 - count($results);
        if ($remaining > 0) {
            $piidCol      = quoteIdentifier($personMeta['piid']);
            $surnameCol   = quoteIdentifier($personMeta['surname']);
            $firstNameCol = quoteIdentifier($personMeta['firstname']);
            $middleNameCol = $personMeta['middlename'] ? quoteIdentifier($personMeta['middlename']) : null;
            $nameExtCol    = $personMeta['nameext']    ? quoteIdentifier($personMeta['nameext'])    : null;
            $displayExpr   = buildPersonDisplayExpr($personMeta);

            $excludeSql  = '';
            $extraParams = [];
            if (!empty($foundPiids)) {
                $placeholders = implode(',', array_fill(0, count($foundPiids), '?'));
                $excludeSql   = " AND {$piidCol} NOT IN ({$placeholders})";
                $extraParams  = $foundPiids;
            }

            $sysQuery = "SELECT
                {$piidCol} AS PIID,
                {$surnameCol} AS Surname,
                {$firstNameCol} AS FirstName,
                " . ($middleNameCol ? "{$middleNameCol} AS MiddleName," : "'' AS MiddleName,") . "
                " . ($nameExtCol ? "{$nameExtCol} AS NameExt," : "'' AS NameExt,") . "
                {$displayExpr} AS display_name
                FROM tbl_personal_details
                WHERE (
                    {$surnameCol} LIKE ?
                    OR {$firstNameCol} LIKE ?
                    OR {$piidCol} LIKE ?
                    " . ($middleNameCol ? " OR {$middleNameCol} LIKE ?" : '') . "
                    " . ($nameExtCol ? " OR {$nameExtCol} LIKE ?" : '') . "
                )
                {$excludeSql}
                ORDER BY {$surnameCol} ASC, {$firstNameCol} ASC, {$piidCol} DESC
                LIMIT {$remaining}";

            $searchParams = array_merge(
                [$likeSearch, $likeSearch, $likeSearch],
                $middleNameCol ? [$likeSearch] : [],
                $nameExtCol    ? [$likeSearch] : [],
                $extraParams
            );
            $sysStmt = $conn->prepare($sysQuery);
            $sysStmt->execute($searchParams);
            foreach ($sysStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $results[] = $row;
            }
        }

        sendResponse('success', 'Person search results.', $results, 200, ['count' => count($results)]);
    }

    if ($action === 'patient_results') {
        $piid = normalizeString($_GET['piid'] ?? '');
        if ($piid === '') {
            sendResponse('error', 'PIID is required.', null, 400);
        }

        // Prefer lab-owned personal info; fall back to tbl_personal_details for first-time patients
        $labPatient = getLabPatientByPiid($conn, $piid);
        if ($labPatient) {
            $patient = [
                'piid'         => $piid,
                'display_name' => formatLabPatientDisplayName($labPatient),
                'sex'          => (string)($labPatient['sex']      ?? ''),
                'birthdate'    => (string)($labPatient['birthdate'] ?? ''),
                'barangay'     => (string)($labPatient['barangay'] ?? ''),
                'city'         => (string)($labPatient['city']     ?? ''),
            ];
        } else {
            $person = getPersonByPiid($conn, $piid, $personMeta);
            if (!$person) {
                sendResponse('error', 'Citizen record not found.', null, 404);
            }
            $patient = [
                'piid'         => $piid,
                'display_name' => formatPersonName($person, $personMeta),
                'sex'          => $personMeta['sex']      ? (string)($person[$personMeta['sex']]      ?? '') : '',
                'birthdate'    => $personMeta['birthdate'] ? (string)($person[$personMeta['birthdate']] ?? '') : '',
                'barangay'     => $personMeta['barangay'] ? (string)($person[$personMeta['barangay']] ?? '') : '',
                'city'         => $personMeta['city']     ? (string)($person[$personMeta['city']]     ?? '') : '',
            ];
        }

        $stmt = $conn->prepare(
            "SELECT
                result_id,
                piid,
                lab_patient_id,
                test_name,
                test_type,
                is_employee,
                request_date,
                result_summary,
                result_value,
                status,
                result_date,
                remarks,
                or_number,
                payment_required,
                created_by,
                updated_by,
                created_at,
                updated_at
             FROM tbl_laboratory_results
             WHERE piid = :piid AND is_deleted = 0
             ORDER BY COALESCE(result_date, request_date) DESC, result_id DESC"
        );
        $stmt->execute([':piid' => $piid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $fees = loadLabFees($conn);
        foreach ($rows as &$row) {
            $row['status']     = normalizeStatus($row['status'] ?? 'PROCESSING');
            $feeAmt = lookupFee($fees, $row['test_type'] ?? '', $row['test_name'] ?? '', $row['request_date'] ?? null);
            $row['fee_amount'] = $feeAmt !== null ? number_format($feeAmt, 2, '.', '') : null;
        }
        unset($row);

        sendResponse('success', 'Patient laboratory records loaded.', [
            'patient' => $patient,
            'results' => $rows
        ], 200, [
            'count' => count($rows)
        ]);
    }

    if ($action === 'result_print') {
        $resultId = (int)($_GET['result_id'] ?? 0);
        if ($resultId <= 0) {
            sendResponse('error', 'Result ID is required.', null, 400);
        }

        $stmt = $conn->prepare(
            "SELECT
                result_id,
                piid,
                test_name,
                test_type,
                is_employee,
                request_date,
                result_summary,
                result_value,
                status,
                result_date,
                remarks,
                or_number,
                payment_required,
                created_by,
                updated_by,
                created_at,
                updated_at
             FROM tbl_laboratory_results
             WHERE result_id = :result_id
             LIMIT 1"
        );
        $stmt->execute([':result_id' => $resultId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            sendResponse('error', 'Result not found.', null, 404);
        }

        $resultPiid = (string)($row['piid'] ?? '');
        $row['status'] = normalizeStatus($row['status'] ?? 'PROCESSING');

        // Prefer lab-owned personal info; fall back to tbl_personal_details
        $labPatient = getLabPatientByPiid($conn, $resultPiid);
        if ($labPatient) {
            $patientInfo = [
                'piid'         => $resultPiid,
                'display_name' => formatLabPatientDisplayName($labPatient),
                'sex'          => (string)($labPatient['sex']      ?? ''),
                'birthdate'    => (string)($labPatient['birthdate'] ?? ''),
                'barangay'     => (string)($labPatient['barangay'] ?? ''),
                'city'         => (string)($labPatient['city']     ?? ''),
            ];
        } else {
            $person = getPersonByPiid($conn, $resultPiid, $personMeta);
            if (!$person) {
                sendResponse('error', 'Citizen record not found for this result.', null, 404);
            }
            $patientInfo = [
                'piid'         => $resultPiid,
                'display_name' => formatPersonName($person, $personMeta),
                'sex'          => $personMeta['sex']      ? (string)($person[$personMeta['sex']]      ?? '') : '',
                'birthdate'    => $personMeta['birthdate'] ? (string)($person[$personMeta['birthdate']] ?? '') : '',
                'barangay'     => $personMeta['barangay'] ? (string)($person[$personMeta['barangay']] ?? '') : '',
                'city'         => $personMeta['city']     ? (string)($person[$personMeta['city']]     ?? '') : '',
            ];
        }

        sendResponse('success', 'Printable result loaded.', [
            'result'  => $row,
            'patient' => $patientInfo,
        ]);
    }

    if ($action === 'dashboard') {
        $totalsStmt = $conn->query(
            "SELECT
                SUM(CASE WHEN UPPER(status) != 'CANCELLED' THEN 1 ELSE 0 END) AS total_results,
                COUNT(DISTINCT CASE WHEN UPPER(status) != 'CANCELLED' THEN piid END) AS total_patients,
                SUM(CASE WHEN UPPER(status) = 'PENDING' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN UPPER(status) = 'PROCESSING' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN UPPER(status) = 'READY_FOR_RELEASE' THEN 1 ELSE 0 END) AS ready_for_release_count,
                SUM(CASE WHEN UPPER(status) = 'RELEASED' THEN 1 ELSE 0 END) AS released_count,
                SUM(CASE WHEN UPPER(status) = 'CANCELLED' THEN 1 ELSE 0 END) AS cancelled_count,
                SUM(CASE WHEN UPPER(status) != 'CANCELLED'
                         AND DATE_FORMAT(COALESCE(result_date, request_date), '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
                         THEN 1 ELSE 0 END) AS this_month_count
             FROM tbl_laboratory_results
             WHERE is_deleted = 0"
        );
        $totals = $totalsStmt ? ($totalsStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $piidJoinCol     = 'p.' . quoteIdentifier($personMeta['piid']);
        $pSexCol         = $personMeta['sex']      ? 'p.' . quoteIdentifier($personMeta['sex'])      : 'NULL';
        $pBirthdateCol   = $personMeta['birthdate'] ? 'p.' . quoteIdentifier($personMeta['birthdate']) : 'NULL';

        // COALESCE: lab-owned data takes priority over tbl_personal_details
        $sexSelect      = "COALESCE(lpi.sex,      {$pSexCol})      AS sex_value";
        $birthdateSelect = "COALESCE(lpi.birthdate, {$pBirthdateCol}) AS birthdate_value";

        $demoStmt = $conn->query(
            "SELECT
                SUM(CASE WHEN UPPER(TRIM(sex_value)) IN ('F', 'FEMALE') THEN 1 ELSE 0 END) AS female_count,
                SUM(CASE WHEN UPPER(TRIM(sex_value)) IN ('M', 'MALE') THEN 1 ELSE 0 END) AS male_count,
                SUM(CASE WHEN sex_value IS NULL OR TRIM(sex_value) = '' OR UPPER(TRIM(sex_value)) NOT IN ('F', 'FEMALE', 'M', 'MALE') THEN 1 ELSE 0 END) AS other_sex_count,
                SUM(CASE WHEN birthdate_value IS NULL OR birthdate_value = '' OR birthdate_value = '0000-00-00' THEN 1 ELSE 0 END) AS age_unknown_count,
                SUM(CASE WHEN birthdate_value IS NOT NULL AND birthdate_value <> '' AND birthdate_value <> '0000-00-00' AND TIMESTAMPDIFF(YEAR, birthdate_value, CURDATE()) BETWEEN 0 AND 17 THEN 1 ELSE 0 END) AS age_child_count,
                SUM(CASE WHEN birthdate_value IS NOT NULL AND birthdate_value <> '' AND birthdate_value <> '0000-00-00' AND TIMESTAMPDIFF(YEAR, birthdate_value, CURDATE()) BETWEEN 18 AND 59 THEN 1 ELSE 0 END) AS age_adult_count,
                SUM(CASE WHEN birthdate_value IS NOT NULL AND birthdate_value <> '' AND birthdate_value <> '0000-00-00' AND TIMESTAMPDIFF(YEAR, birthdate_value, CURDATE()) >= 60 THEN 1 ELSE 0 END) AS age_senior_count
             FROM (
                SELECT DISTINCT
                    r.piid,
                    {$sexSelect},
                    {$birthdateSelect}
                FROM tbl_laboratory_results r
                LEFT JOIN tbl_lab_personal_info lpi ON lpi.lab_patient_id = r.lab_patient_id
                LEFT JOIN tbl_personal_details  p   ON {$piidJoinCol}     = r.piid
                WHERE r.is_deleted = 0 AND UPPER(r.status) != 'CANCELLED'
             ) d"
        );
        $demo = $demoStmt ? ($demoStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $topTestsStmt = $conn->query(
            "SELECT
                test_name,
                COUNT(*) AS record_count
             FROM tbl_laboratory_results
             WHERE is_deleted = 0 AND UPPER(status) != 'CANCELLED'
             GROUP BY test_name
             ORDER BY record_count DESC, test_name ASC
             LIMIT 6"
        );
        $topTests = $topTestsStmt ? $topTestsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $readyListStmt = $conn->query(
            "SELECT
                r.result_id,
                r.piid,
                CONCAT(
                    COALESCE(lpi.surname, p." . quoteIdentifier($personMeta['surname']) . "),
                    ', ',
                    COALESCE(lpi.first_name, p." . quoteIdentifier($personMeta['firstname']) . "),
                    ' ',
                    IFNULL(CONCAT(NULLIF(LEFT(COALESCE(lpi.middle_name" . ($personMeta['middlename'] ? ", p." . quoteIdentifier($personMeta['middlename']) : "") . ", ''), 1), ''), '. '), '')
                ) AS display_name,
                r.test_name,
                r.request_date,
                r.result_date
             FROM tbl_laboratory_results r
             LEFT JOIN tbl_lab_personal_info lpi ON lpi.lab_patient_id = r.lab_patient_id
             LEFT JOIN tbl_personal_details p ON p." . quoteIdentifier($personMeta['piid']) . " = r.piid
             WHERE r.is_deleted = 0
               AND UPPER(r.status) = 'READY_FOR_RELEASE'
             ORDER BY COALESCE(r.result_date, r.request_date) ASC, r.result_id ASC
             LIMIT 12"
        );
        $readyList = $readyListStmt ? $readyListStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $trendStmt = $conn->query(
            "SELECT
                DATE_FORMAT(COALESCE(result_date, request_date), '%Y-%m') AS ym,
                COUNT(*) AS record_count
             FROM tbl_laboratory_results
             WHERE is_deleted = 0
               AND UPPER(status) != 'CANCELLED'
               AND COALESCE(result_date, request_date) >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
             GROUP BY DATE_FORMAT(COALESCE(result_date, request_date), '%Y-%m')
             ORDER BY ym ASC"
        );
        $trendRows = $trendStmt ? $trendStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $trendMap = [];
        foreach ($trendRows as $row) {
            $key = (string)($row['ym'] ?? '');
            if ($key !== '') {
                $trendMap[$key] = (int)($row['record_count'] ?? 0);
            }
        }

        $monthly = [];
        for ($i = 5; $i >= 0; $i--) {
            $ts = strtotime("-{$i} month");
            $ym = date('Y-m', $ts);
            $monthly[] = [
                'month' => $ym,
                'label' => date('M Y', $ts),
                'record_count' => (int)($trendMap[$ym] ?? 0)
            ];
        }

        sendResponse('success', 'Dashboard data loaded.', [
            'totals' => [
                'total_results' => (int)($totals['total_results'] ?? 0),
                'total_patients' => (int)($totals['total_patients'] ?? 0),
                'pending_count' => (int)($totals['pending_count'] ?? 0),
                'processing_count' => (int)($totals['processing_count'] ?? 0),
                'ready_for_release_count' => (int)($totals['ready_for_release_count'] ?? 0),
                'released_count' => (int)($totals['released_count'] ?? 0),
                'cancelled_count' => (int)($totals['cancelled_count'] ?? 0),
                'this_month_count' => (int)($totals['this_month_count'] ?? 0)
            ],
            'demographics' => [
                'female_count' => (int)($demo['female_count'] ?? 0),
                'male_count' => (int)($demo['male_count'] ?? 0),
                'other_sex_count' => (int)($demo['other_sex_count'] ?? 0),
                'age_child_count' => (int)($demo['age_child_count'] ?? 0),
                'age_adult_count' => (int)($demo['age_adult_count'] ?? 0),
                'age_senior_count' => (int)($demo['age_senior_count'] ?? 0),
                'age_unknown_count' => (int)($demo['age_unknown_count'] ?? 0)
            ],
            'ready_for_release' => $readyList,
            'top_tests' => $topTests,
            'monthly' => $monthly
        ], 200);
    }

    if ($action === 'all_results') {
        requireLabAdmin($userData);
        $fromDate  = normalizeDateOnly($_GET['from']   ?? '');
        $toDate    = normalizeDateOnly($_GET['to']     ?? '');
        $status    = strtoupper(normalizeString($_GET['status'] ?? 'ALL'));
        $search    = normalizeString($_GET['search']   ?? '');
        $limitRaw  = (int)($_GET['limit']  ?? 500);
        $offsetRaw = (int)($_GET['offset'] ?? 0);
        $limit     = max(1, min(1000, $limitRaw));
        $offset    = max(0, $offsetRaw);

        if ($fromDate === false || $toDate === false) {
            sendResponse('error', 'Invalid date format. Use YYYY-MM-DD.', null, 400);
        }

        if ($fromDate && $toDate && strtotime($fromDate) > strtotime($toDate)) {
            sendResponse('error', 'Invalid date range: from-date is later than to-date.', null, 400);
        }

        $allowedFilter = ['ALL', 'PENDING', 'PROCESSING', 'READY_FOR_RELEASE', 'RELEASED', 'CANCELLED'];
        if (!in_array($status, $allowedFilter, true)) {
            $status = 'ALL';
        }

        $piidCol     = quoteIdentifier($personMeta['piid']);
        $surnameCol  = quoteIdentifier($personMeta['surname']);
        $firstCol    = quoteIdentifier($personMeta['firstname']);
        $midCol      = $personMeta['middlename'] ? quoteIdentifier($personMeta['middlename']) : null;
        $sexCol      = $personMeta['sex']       ? quoteIdentifier($personMeta['sex'])       : null;
        $birthCol    = $personMeta['birthdate'] ? quoteIdentifier($personMeta['birthdate']) : null;
        $brgyCol     = $personMeta['barangay']  ? quoteIdentifier($personMeta['barangay'])  : null;
        $cityCol     = $personMeta['city']      ? quoteIdentifier($personMeta['city'])      : null;

        // Name expression: prefer lab table columns, fall back to tbl_personal_details
        $nameExpr = "CONCAT(COALESCE(lpi.surname, p.{$surnameCol}), ', ', COALESCE(lpi.first_name, p.{$firstCol})" .
                    ", ' ', IFNULL(CONCAT(NULLIF(LEFT(COALESCE(lpi.middle_name" . ($midCol ? ", p.{$midCol}" : "") . ",''),1),''),'. '),'') " .
                    ")";

        $where  = ["r.is_deleted = 0"];
        $params = [];

        if ($fromDate) {
            $where[] = "r.request_date >= :from_date";
            $params[':from_date'] = $fromDate;
        }
        if ($toDate) {
            $where[] = "r.request_date <= :to_date";
            $params[':to_date'] = $toDate;
        }
        if ($status !== 'ALL') {
            $where[] = "UPPER(r.status) = :status";
            $params[':status'] = $status;
        }
        if ($search !== '') {
            $where[] = "({$nameExpr} LIKE :search OR r.piid LIKE :search2)";
            $params[':search']  = '%' . $search . '%';
            $params[':search2'] = '%' . $search . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        // Totals — join both tables; lab table takes priority
        $totStmt = $conn->prepare(
            "SELECT
                COUNT(*) AS total_records,
                SUM(CASE WHEN UPPER(r.status)='PENDING' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN UPPER(r.status)='PROCESSING' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN UPPER(r.status)='READY_FOR_RELEASE' THEN 1 ELSE 0 END) AS ready_for_release_count,
                SUM(CASE WHEN UPPER(r.status)='RELEASED' THEN 1 ELSE 0 END) AS released_count,
                SUM(CASE WHEN UPPER(r.status)='CANCELLED' THEN 1 ELSE 0 END) AS cancelled_count
             FROM tbl_laboratory_results r
             LEFT JOIN tbl_lab_personal_info lpi ON lpi.lab_patient_id = r.lab_patient_id
             LEFT JOIN tbl_personal_details  p   ON p.{$piidCol}       = r.piid
             {$whereSql}"
        );
        $totStmt->execute($params);
        $totals = $totStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Rows — COALESCE lab table over tbl_personal_details for personal fields
        $sexExpr   = "COALESCE(lpi.sex,      " . ($sexCol   ? "p.{$sexCol}"   : "NULL") . ")";
        $birthExpr = "COALESCE(lpi.birthdate," . ($birthCol ? "p.{$birthCol}" : "NULL") . ")";
        $brgyExpr  = "COALESCE(lpi.barangay, " . ($brgyCol  ? "p.{$brgyCol}"  : "NULL") . ")";
        $cityExpr  = "COALESCE(lpi.city,     " . ($cityCol  ? "p.{$cityCol}"  : "NULL") . ")";

        $rowStmt = $conn->prepare(
            "SELECT
                r.result_id,
                r.piid,
                r.lab_patient_id,
                {$nameExpr} AS display_name,
                {$sexExpr}   AS sex,
                {$birthExpr} AS birthdate,
                {$brgyExpr}  AS barangay,
                {$cityExpr}  AS city,
                r.test_name,
                r.request_date,
                r.result_date,
                UPPER(r.status) AS status,
                r.result_summary,
                r.result_value,
                r.or_number,
                r.payment_required,
                r.is_employee
             FROM tbl_laboratory_results r
             LEFT JOIN tbl_lab_personal_info lpi ON lpi.lab_patient_id = r.lab_patient_id
             LEFT JOIN tbl_personal_details  p   ON p.{$piidCol}       = r.piid
             {$whereSql}
             ORDER BY r.request_date DESC, r.result_id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $rowStmt->execute($params);
        $rows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);

        sendResponse('success', 'Results loaded.', [
            'totals' => [
                'total_records'          => (int)($totals['total_records'] ?? 0),
                'pending_count'          => (int)($totals['pending_count'] ?? 0),
                'processing_count'       => (int)($totals['processing_count'] ?? 0),
                'ready_for_release_count'=> (int)($totals['ready_for_release_count'] ?? 0),
                'released_count'         => (int)($totals['released_count'] ?? 0),
                'cancelled_count'        => (int)($totals['cancelled_count'] ?? 0),
            ],
            'results' => $rows,
        ], 200, [
            'count'  => count($rows),
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    if ($action === 'summary') {
        requireLabAdmin($userData);
        $fromDate = normalizeDateOnly($_GET['from'] ?? '');
        $toDate = normalizeDateOnly($_GET['to'] ?? '');
        $status = strtoupper(normalizeString($_GET['status'] ?? 'ALL'));

        if ($fromDate === false || $toDate === false) {
            sendResponse('error', 'Invalid date format. Use YYYY-MM-DD.', null, 400);
        }

        if ($fromDate && $toDate && strtotime($fromDate) > strtotime($toDate)) {
            sendResponse('error', 'Invalid date range: from-date is later than to-date.', null, 400);
        }

        $allowedFilter = ['ALL', 'PENDING', 'PROCESSING', 'READY_FOR_RELEASE', 'RELEASED', 'CANCELLED'];
        if (!in_array($status, $allowedFilter, true)) {
            $status = 'ALL';
        }

        $where = [];
        $params = [];

        if ($fromDate) {
            $where[] = 'DATE(COALESCE(result_date, request_date)) >= :from_date';
            $params[':from_date'] = $fromDate;
        }

        if ($toDate) {
            $where[] = 'DATE(COALESCE(result_date, request_date)) <= :to_date';
            $params[':to_date'] = $toDate;
        }

        if ($status !== 'ALL') {
            $where[] = 'UPPER(status) = :status';
            $params[':status'] = $status;
        }

        $whereSql = '';
        if (!empty($where)) {
            $whereSql = 'WHERE ' . implode(' AND ', $where);
        }

        $summaryStmt = $conn->prepare(
            "SELECT
                COUNT(*) AS total_records,
                SUM(CASE WHEN UPPER(status) = 'PENDING' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN UPPER(status) = 'PROCESSING' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN UPPER(status) = 'READY_FOR_RELEASE' THEN 1 ELSE 0 END) AS ready_for_release_count,
                SUM(CASE WHEN UPPER(status) = 'RELEASED' THEN 1 ELSE 0 END) AS released_count,
                SUM(CASE WHEN UPPER(status) = 'CANCELLED' THEN 1 ELSE 0 END) AS cancelled_count
             FROM tbl_laboratory_results
             {$whereSql}"
        );
        $summaryStmt->execute($params);
        $totals = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $breakdownStmt = $conn->prepare(
            "SELECT
                test_name,
                UPPER(status) AS status,
                COUNT(*) AS record_count,
                MIN(COALESCE(result_date, request_date)) AS first_result_date,
                MAX(COALESCE(result_date, request_date)) AS last_result_date
             FROM tbl_laboratory_results
             {$whereSql}
             GROUP BY test_name, UPPER(status)
             ORDER BY test_name ASC, status ASC"
        );
        $breakdownStmt->execute($params);
        $rows = $breakdownStmt->fetchAll(PDO::FETCH_ASSOC);

        sendResponse('success', 'Summary report generated.', [
            'totals' => [
                'total_records' => (int)($totals['total_records'] ?? 0),
                'pending_count' => (int)($totals['pending_count'] ?? 0),
                'processing_count' => (int)($totals['processing_count'] ?? 0),
                'ready_for_release_count' => (int)($totals['ready_for_release_count'] ?? 0),
                'released_count' => (int)($totals['released_count'] ?? 0),
                'cancelled_count' => (int)($totals['cancelled_count'] ?? 0)
            ],
            'breakdown' => $rows
        ], 200, [
            'count' => count($rows),
            'status_filter' => $status,
            'from' => $fromDate,
            'to' => $toDate
        ]);
    }

    if ($action === 'get_test_types') {
        // Known types defined in the recording form — always included even if no records exist yet
        $known = ['BLOOD_CHEM' => 'Blood Chem'];

        // Merge with any distinct values already recorded in the DB
        try {
            $dbTypes = $conn->query(
                "SELECT DISTINCT UPPER(TRIM(test_type)) AS test_type FROM tbl_laboratory_results
                 WHERE test_type IS NOT NULL AND test_type <> ''
                 UNION
                 SELECT DISTINCT UPPER(TRIM(test_type)) FROM tbl_lab_fees
                 WHERE test_type IS NOT NULL AND test_type <> ''"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $dbTypes = [];
        }

        foreach ($dbTypes as $t) {
            $t = strtoupper(trim($t));
            if ($t !== '' && !isset($known[$t])) {
                $known[$t] = $t; // no friendly label — use raw value
            }
        }

        $result = [];
        foreach ($known as $value => $label) {
            $result[] = ['value' => $value, 'label' => $label];
        }
        usort($result, fn($a, $b) => strcmp($a['value'], $b['value']));

        sendResponse('success', 'Test types loaded.', $result, 200, ['count' => count($result)]);
    }

    if ($action === 'get_fees') {
        $stmt = $conn->query(
            "SELECT fee_id, test_type, test_name, amount, effective_from, description, is_active, updated_at
             FROM tbl_lab_fees
             ORDER BY test_type, test_name, effective_from DESC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['fee_id']   = (int)$row['fee_id'];
            $row['amount']   = number_format((float)$row['amount'], 2, '.', '');
            $row['is_active'] = (int)$row['is_active'];
        }
        unset($row);
        sendResponse('success', 'Fee schedule loaded.', $rows, 200, ['count' => count($rows)]);
    }

    sendResponse('error', 'Unknown action.', null, 400);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = readPayload();
    $action = normalizeString($payload['action'] ?? 'save_result');

    if ($action === 'release_result') {
        $resultId = isset($payload['result_id']) ? (int)$payload['result_id'] : 0;
        if ($resultId <= 0) {
            sendResponse('error', 'Result ID is required.', null, 400);
        }

        $findStmt = $conn->prepare("SELECT result_id, status, request_date, result_date FROM tbl_laboratory_results WHERE result_id = :result_id LIMIT 1");
        $findStmt->execute([':result_id' => $resultId]);
        $existing = $findStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            sendResponse('error', 'Result not found.', null, 404);
        }

        if (strtoupper((string)($existing['status'] ?? '')) === 'CANCELLED') {
            sendResponse('error', 'Cancelled results cannot be released.', null, 400);
        }

        if (strtoupper((string)($existing['status'] ?? '')) === 'RELEASED') {
            sendResponse('success', 'Result is already released.', [
                'result_id' => $resultId,
                'status' => 'RELEASED'
            ]);
        }

        if (strtoupper((string)($existing['status'] ?? '')) !== 'READY_FOR_RELEASE') {
            sendResponse('error', 'Only results tagged as Ready for Release can be released.', null, 400);
        }

        // Result date must already be set — do not silently fill it with today's date.
        $existingResultDate = trim((string)($existing['result_date'] ?? ''));
        if ($existingResultDate === '' || $existingResultDate === '0000-00-00') {
            sendResponse('error', 'Result date is required before releasing. Edit the record and set the result date first.', null, 400);
        }

        $username = normalizeString($userData['username'] ?? 'system');
        $releaseStmt = $conn->prepare(
            "UPDATE tbl_laboratory_results
             SET
                status = 'RELEASED',
                updated_by = :updated_by
             WHERE result_id = :result_id"
        );
        $releaseStmt->execute([
            ':updated_by' => $username,
            ':result_id' => $resultId
        ]);

        sendResponse('success', 'Result marked as released.', [
            'result_id' => $resultId,
            'status' => 'RELEASED'
        ]);
    }

    if ($action === 'save_or_number') {
        $resultId = isset($payload['result_id']) ? (int)$payload['result_id'] : 0;
        $orNumber = normalizeString($payload['or_number'] ?? '');
        if (strlen($orNumber) > 50) {
            $orNumber = substr($orNumber, 0, 50);
        }

        if ($resultId <= 0) {
            sendResponse('error', 'Result ID is required.', null, 400);
        }
        if ($orNumber === '') {
            sendResponse('error', 'OR number is required.', null, 400);
        }

        $findStmt = $conn->prepare("SELECT result_id, status FROM tbl_laboratory_results WHERE result_id = :result_id LIMIT 1");
        $findStmt->execute([':result_id' => $resultId]);
        $existing = $findStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            sendResponse('error', 'Record not found.', null, 404);
        }

        // Advance status from PENDING to PROCESSING once OR number is confirmed
        $currentStatus = strtoupper((string)($existing['status'] ?? 'PROCESSING'));
        $newStatus = $currentStatus === 'PENDING' ? 'PROCESSING' : $currentStatus;

        $username = normalizeString($userData['username'] ?? 'system');
        $orStmt = $conn->prepare(
            "UPDATE tbl_laboratory_results
             SET or_number = :or_number, status = :status, updated_by = :updated_by
             WHERE result_id = :result_id"
        );
        $orStmt->execute([
            ':or_number' => $orNumber,
            ':status' => $newStatus,
            ':updated_by' => $username,
            ':result_id' => $resultId
        ]);

        sendResponse('success', 'OR number saved.', [
            'result_id' => $resultId,
            'status' => $newStatus
        ]);
    }

    if ($action === 'delete_result') {
        requireLabAdmin($userData);
        $resultId = isset($payload['result_id']) ? (int)$payload['result_id'] : 0;
        if ($resultId <= 0) {
            sendResponse('error', 'Result ID is required.', null, 400);
        }

        $findStmt = $conn->prepare("SELECT result_id FROM tbl_laboratory_results WHERE result_id = :result_id AND is_deleted = 0 LIMIT 1");
        $findStmt->execute([':result_id' => $resultId]);
        if (!$findStmt->fetch()) {
            sendResponse('error', 'Result not found.', null, 404);
        }

        $username = normalizeString($userData['username'] ?? 'system');
        $delStmt = $conn->prepare(
            "UPDATE tbl_laboratory_results
             SET is_deleted = 1, updated_by = :updated_by
             WHERE result_id = :result_id"
        );
        $delStmt->execute([':updated_by' => $username, ':result_id' => $resultId]);

        sendResponse('success', 'Record deleted.', ['result_id' => $resultId]);
    }

    if ($action === 'save_fee') {
        requireLabAdmin($userData);
        $feeId      = isset($payload['fee_id'])   ? (int)$payload['fee_id']   : 0;
        $testType   = strtoupper(normalizeString($payload['test_type']   ?? ''));
        $testName   = normalizeString($payload['test_name']   ?? '');
        $testName   = $testName !== '' ? $testName : null;
        $amountRaw  = $payload['amount'] ?? '0';
        $amount     = round((float)str_replace(',', '', (string)$amountRaw), 2);
        $effectiveFrom = normalizeDateOnly($payload['effective_from'] ?? '');
        if ($effectiveFrom === '') { $effectiveFrom = date('Y-01-01'); }
        $description = normalizeString($payload['description'] ?? '');
        $isActive   = isset($payload['is_active']) ? (int)(bool)$payload['is_active'] : 1;

        if ($testType === '') {
            sendResponse('error', 'Test type is required.', null, 400);
        }
        if (strlen($testType) > 50 || ($testName !== null && strlen($testName) > 150)) {
            sendResponse('error', 'Test type or name is too long.', null, 400);
        }
        if ($amount < 0) {
            sendResponse('error', 'Amount cannot be negative.', null, 400);
        }

        if ($feeId > 0) {
            // Update
            $chkStmt = $conn->prepare("SELECT fee_id FROM tbl_lab_fees WHERE fee_id = :id LIMIT 1");
            $chkStmt->execute([':id' => $feeId]);
            if (!$chkStmt->fetch()) {
                sendResponse('error', 'Fee record not found.', null, 404);
            }
            $updStmt = $conn->prepare(
                "UPDATE tbl_lab_fees
                 SET test_type = :test_type, test_name = :test_name, amount = :amount,
                     effective_from = :effective_from, description = :description, is_active = :is_active
                 WHERE fee_id = :fee_id"
            );
            $updStmt->execute([
                ':test_type'     => $testType,
                ':test_name'     => $testName,
                ':amount'        => $amount,
                ':effective_from' => $effectiveFrom,
                ':description'   => $description ?: null,
                ':is_active'     => $isActive,
                ':fee_id'        => $feeId,
            ]);
            sendResponse('success', 'Fee updated.', ['fee_id' => $feeId]);
        } else {
            // For null test_name, MySQL unique index allows duplicate NULLs — check manually
            if ($testName === null) {
                $dupChk = $conn->prepare("SELECT COUNT(*) FROM tbl_lab_fees WHERE test_type = :tt AND test_name IS NULL AND effective_from = :ef");
                $dupChk->execute([':tt' => $testType, ':ef' => $effectiveFrom]);
                if ((int)$dupChk->fetchColumn() > 0) {
                    sendResponse('error', 'A fee for this test type / effective date already exists.', null, 409);
                }
            }
            // Insert
            try {
                $insStmt = $conn->prepare(
                    "INSERT INTO tbl_lab_fees (test_type, test_name, amount, effective_from, description, is_active)
                     VALUES (:test_type, :test_name, :amount, :effective_from, :description, :is_active)"
                );
                $insStmt->execute([
                    ':test_type'     => $testType,
                    ':test_name'     => $testName,
                    ':amount'        => $amount,
                    ':effective_from' => $effectiveFrom,
                    ':description'   => $description ?: null,
                    ':is_active'     => $isActive,
                ]);
                sendResponse('success', 'Fee created.', ['fee_id' => (int)$conn->lastInsertId()], 201);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    sendResponse('error', 'A fee for this test type / name / effective date already exists.', null, 409);
                }
                throw $e;
            }
        }
    }

    if ($action === 'delete_fee') {
        requireLabAdmin($userData);
        $feeId = isset($payload['fee_id']) ? (int)$payload['fee_id'] : 0;
        if ($feeId <= 0) {
            sendResponse('error', 'Fee ID is required.', null, 400);
        }

        // Fetch the fee record first so we know what test_type/test_name to check against
        $feeChk = $conn->prepare("SELECT test_type, test_name FROM tbl_lab_fees WHERE fee_id = :id LIMIT 1");
        $feeChk->execute([':id' => $feeId]);
        $feeRow = $feeChk->fetch(PDO::FETCH_ASSOC);
        if (!$feeRow) {
            sendResponse('error', 'Fee record not found.', null, 404);
        }

        // Orphan check: block delete if any PENDING or PROCESSING result references this fee
        $orphanStmt = $conn->prepare(
            "SELECT COUNT(*) AS cnt
             FROM tbl_laboratory_results
             WHERE test_type  = :test_type
               AND (
                     (:test_name_null = 1 AND (test_name IS NULL OR test_name = ''))
                     OR
                     (:test_name_val  IS NOT NULL AND test_name = :test_name_val2)
                   )
               AND status     IN ('PENDING', 'PROCESSING')
               AND is_deleted = 0"
        );
        $testNameIsNull = ($feeRow['test_name'] === null || $feeRow['test_name'] === '') ? 1 : 0;
        $orphanStmt->execute([
            ':test_type'       => $feeRow['test_type'],
            ':test_name_null'  => $testNameIsNull,
            ':test_name_val'   => $testNameIsNull ? null : $feeRow['test_name'],
            ':test_name_val2'  => $testNameIsNull ? null : $feeRow['test_name'],
        ]);
        $orphanCount = (int)($orphanStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
        if ($orphanCount > 0) {
            $label = $feeRow['test_name'] ? $feeRow['test_name'] : $feeRow['test_type'];
            sendResponse(
                'error',
                "Cannot delete this fee — {$orphanCount} pending/processing result(s) still reference \"{$label}\". "
                . "Resolve or cancel those results first.",
                ['pending_count' => $orphanCount],
                409
            );
        }

        $delStmt = $conn->prepare("DELETE FROM tbl_lab_fees WHERE fee_id = :id");
        $delStmt->execute([':id' => $feeId]);
        if ($delStmt->rowCount() === 0) {
            sendResponse('error', 'Fee record not found.', null, 404);
        }
        sendResponse('success', 'Fee deleted.');
    }

    if ($action !== 'save_result') {
        sendResponse('error', 'Unknown action.', null, 400);
    }

    $piid = normalizeString($payload['piid'] ?? '');
    $testName = normalizeString($payload['test_name'] ?? '');
    $testType = normalizeTestType($payload['test_type'] ?? 'BLOOD_CHEM');
    $isEmployee = normalizeFlag($payload['is_employee'] ?? 0);
    $requestDate = normalizeDateOnly($payload['request_date'] ?? '');
    $resultSummary = normalizeString($payload['result_summary'] ?? '');
    $resultValue = normalizeString($payload['result_value'] ?? '');
    $status = normalizeStatus($payload['status'] ?? 'PROCESSING');
    $resultDate = normalizeDateOnly($payload['result_date'] ?? '');
    $remarks = normalizeString($payload['remarks'] ?? '');
    $orNumber = normalizeString($payload['or_number'] ?? '');
    if (strlen($orNumber) > 50) {
        $orNumber = substr($orNumber, 0, 50);
    }
    $resultId = isset($payload['result_id']) ? (int)$payload['result_id'] : 0;

    if ($piid === '') {
        sendResponse('error', 'PIID is required.', null, 400);
    }

    if ($testName === '') {
        sendResponse('error', 'Test name is required.', null, 400);
    }


    if ($requestDate === false || $requestDate === null) {
        sendResponse('error', 'Request date is required in YYYY-MM-DD format.', null, 400);
    }

    if ($resultDate === false) {
        sendResponse('error', 'Invalid result date format. Use YYYY-MM-DD.', null, 400);
    }

    if (in_array($status, ['READY_FOR_RELEASE', 'RELEASED'], true) && $resultDate === null) {
        sendResponse('error', 'Result date is required when status is Ready for Release or Released.', null, 400);
    }

    // Resolve patient: try lab table first, then fall back to tbl_personal_details
    $labPatientId = 0;
    $labPatient = getLabPatientByPiid($conn, $piid);
    if ($labPatient) {
        // Already in lab registry — use existing record
        $labPatientId = (int)$labPatient['lab_patient_id'];
    } else {
        // First time this patient uses the lab — copy from tbl_personal_details
        $person = getPersonByPiid($conn, $piid, $personMeta);
        if (!$person) {
            sendResponse('error', 'Citizen record not found.', null, 404);
        }
        $labPatientId = upsertLabPatient($conn, $piid, $person, $personMeta);
        if ($labPatientId === 0) {
            sendResponse('error', 'Failed to register patient in laboratory records.', null, 500);
        }
    }

    $username = normalizeString($userData['username'] ?? 'system');

    if ($resultId > 0) {
        // Fetch current status to enforce transition rules before updating
        $curStmt = $conn->prepare("SELECT status FROM tbl_laboratory_results WHERE result_id = :id AND is_deleted = 0 LIMIT 1");
        $curStmt->execute([':id' => $resultId]);
        $currentRow = $curStmt->fetch(PDO::FETCH_ASSOC);
        if (!$currentRow) {
            sendResponse('error', 'Result record not found.', null, 404);
        }
        $currentStatus = strtoupper(trim((string)($currentRow['status'] ?? '')));

        // RELEASED is a terminal state — it can only be set by the release_result action, never by a direct edit
        if ($currentStatus === 'RELEASED') {
            sendResponse('error', 'Released results cannot be edited. Create a new record if a correction is needed.', null, 400);
        }
        if ($status === 'RELEASED') {
            sendResponse('error', 'Status cannot be set to Released directly. Use the Release button instead.', null, 400);
        }

        $stmt = $conn->prepare(
            "UPDATE tbl_laboratory_results
             SET
                piid = :piid,
                lab_patient_id = :lab_patient_id,
                test_name = :test_name,
                test_type = :test_type,
                is_employee = :is_employee,
                request_date = :request_date,
                result_summary = :result_summary,
                result_value = :result_value,
                status = :status,
                result_date = :result_date,
                remarks = :remarks,
                or_number = CASE WHEN :or_number <> '' THEN :or_number ELSE or_number END,
                updated_by = :updated_by
             WHERE result_id = :result_id"
        );

        $stmt->execute([
            ':piid' => $piid,
            ':lab_patient_id' => $labPatientId,
            ':test_name' => $testName,
            ':test_type' => $testType,
            ':is_employee' => $isEmployee,
            ':request_date' => $requestDate,
            ':result_summary' => $resultSummary,
            ':result_value' => $resultValue,
            ':status' => $status,
            ':result_date' => $resultDate,
            ':remarks' => $remarks,
            ':or_number' => $orNumber,
            ':updated_by' => $username,
            ':result_id' => $resultId
        ]);

        if ($stmt->rowCount() === 0) {
            sendResponse('error', 'No result record updated.', null, 404);
        }

        $fetchStmt = $conn->prepare("SELECT test_type, test_name, payment_required, or_number FROM tbl_laboratory_results WHERE result_id = :id");
        $fetchStmt->execute([':id' => $resultId]);
        $updatedRow = $fetchStmt->fetch(PDO::FETCH_ASSOC);
        $fees = loadLabFees($conn);
        $feeAmt = lookupFee($fees, $updatedRow['test_type'] ?? '', $updatedRow['test_name'] ?? '', $requestDate ?: null);
        sendResponse('success', 'Laboratory result updated.', [
            'result_id'        => $resultId,
            'payment_required' => (int)($updatedRow['payment_required'] ?? 0),
            'or_number'        => $updatedRow['or_number'] ?? '',
            'fee_amount'       => $feeAmt !== null ? number_format($feeAmt, 2, '.', '') : null,
        ]);
    }

    // Acquire an advisory lock keyed on patient + year to prevent a race condition
    // where two simultaneous saves both pass the duplicate check before either inserts.
    $lockYear = date('Y', strtotime($requestDate));
    $lockKey  = 'lab_bc_' . md5($piid . '_' . $lockYear);
    $lockRow  = $conn->query("SELECT GET_LOCK(" . $conn->quote($lockKey) . ", 5) AS acquired")->fetch(PDO::FETCH_ASSOC);
    if (!($lockRow['acquired'] ?? 0)) {
        sendResponse('error', 'Server is busy processing another request for this patient. Please try again.', null, 503);
    }

    // Check for duplicate blood chem this year — if found, mark as payment-required.
    // This check runs inside the advisory lock so no two requests can race past it.
    $paymentRequired = 0;
    try {
        $conn->beginTransaction();

        if ($testType === 'BLOOD_CHEM') {
            $dupStmt = $conn->prepare(
                "SELECT result_id FROM tbl_laboratory_results
                 WHERE piid = :piid
                   AND test_type = 'BLOOD_CHEM'
                   AND YEAR(request_date) = YEAR(:request_date)
                   AND status != 'CANCELLED'
                 LIMIT 1"
            );
            $dupStmt->execute([':piid' => $piid, ':request_date' => $requestDate]);
            if ($dupStmt->fetch()) {
                $paymentRequired = 1;
                // Force status to PENDING until OR number is provided
                $status = 'PENDING';
            }
        }

        $stmt = $conn->prepare(
            "INSERT INTO tbl_laboratory_results (
                piid,
                lab_patient_id,
                test_name,
                test_type,
                is_employee,
                request_date,
                result_summary,
                result_value,
                status,
                result_date,
                remarks,
                or_number,
                payment_required,
                created_by,
                updated_by
             ) VALUES (
                :piid,
                :lab_patient_id,
                :test_name,
                :test_type,
                :is_employee,
                :request_date,
                :result_summary,
                :result_value,
                :status,
                :result_date,
                :remarks,
                :or_number,
                :payment_required,
                :created_by,
                :updated_by
             )"
        );

        $stmt->execute([
            ':piid'             => $piid,
            ':lab_patient_id'   => $labPatientId,
            ':test_name'        => $testName,
            ':test_type'        => $testType,
            ':is_employee'      => $isEmployee,
            ':request_date'     => $requestDate,
            ':result_summary'   => $resultSummary,
            ':result_value'     => $resultValue,
            ':status'           => $status,
            ':result_date'      => $resultDate,
            ':remarks'          => $remarks,
            ':or_number'        => $orNumber,
            ':payment_required' => $paymentRequired,
            ':created_by'       => $username,
            ':updated_by'       => $username,
        ]);

        $newId = (int)$conn->lastInsertId();
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        $conn->query("SELECT RELEASE_LOCK(" . $conn->quote($lockKey) . ")");
        sendResponse('error', 'Database error while saving result. Please try again.', null, 500);
    }

    // Release lock before responding — sendResponse calls exit() so finally won't run
    $conn->query("SELECT RELEASE_LOCK(" . $conn->quote($lockKey) . ")");

    $fees   = loadLabFees($conn);
    $feeAmt = lookupFee($fees, $testType, $testName, $requestDate ?: null);
    sendResponse('success', 'Laboratory result recorded.', [
        'result_id'        => $newId,
        'payment_required' => $paymentRequired,
        'fee_amount'       => $feeAmt !== null ? number_format($feeAmt, 2, '.', '') : null,
    ], 201);
}

sendResponse('error', 'Invalid request method.', null, 405);
?>
