<?php
/**
 * leave_api.php — REST JSON endpoints for the Leave Credits module.
 *
 * All endpoints require a valid admin Bearer token.
 * Base URL: /API/leave_api.php?action=<action>
 *
 * Actions (GET):
 *   employees           — search employees (q, surname, firstname, dept, piid)
 *   employee            — single employee (piid)
 *   departments         — list of departments
 *   records             — leave ledger rows (piid, year)
 *   balance             — forwarded balance row (piid, year)
 *   credits_earned      — lookup table rows
 *   conversion          — working-day conversion rows
 *   dashboard_stats     — aggregated counts for dashboard
 *   report              — monthly/quarterly summary (year, month_from, month_to, dept)
 *
 * Actions (POST):
 *   save_record         — insert or update a leave entry (LID optional → insert if absent)
 *   delete_record       — soft-delete (LID)
 *   save_balance        — upsert forwarded balance (piid, year, ...)
 *   save_credits_earned — replace entire credits-earned table
 *   save_conversion     — replace entire conversion table
 */

require_once __DIR__ . '/db_auth.php';
require_once __DIR__ . '/leave_db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Auth ─────────────────────────────────────────────────────────────────────

$user = validateToken();
if (!$user) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
    exit;
}
$actor = $user['username'] ?? 'HRMIS';

// ── Helpers ──────────────────────────────────────────────────────────────────

function ok($data = [])       { echo json_encode(['status' => 'success'] + $data); exit; }
function fail($msg, $code=400){ http_response_code($code); echo json_encode(['status'=>'error','message'=>$msg]); exit; }

$method = $_SERVER['REQUEST_METHOD'];
$action = trim($_GET['action'] ?? '');

// JSON body for POST/PUT
$body = [];
if (in_array($method, ['POST', 'PUT'], true)) {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
}
function bp(string $key, $default = '') { global $body; return $body[$key] ?? $default; }
function bpf(string $key): float       { return (float)bp($key, 0); }
function bpi(string $key): int         { return (int)bp($key, 0); }
function bp_upper(string $key, string $default = ''): string {
    return strtoupper(trim((string)bp($key, $default)));
}

// ── Routing ───────────────────────────────────────────────────────────────────

switch ($action) {

    // ── GET: employee search ─────────────────────────────────────────────────
    case 'employees':
        $q         = trim($_GET['q'] ?? '');
        $page      = max(1, (int)($_GET['page']      ?? 1));
        $page_size = min(200, max(10, (int)($_GET['page_size'] ?? 50)));
        $result = lv_search_employees(
            $leave_conn,
            trim($_GET['surname']   ?? ($q !== '' ? $q : '')),
            trim($_GET['firstname'] ?? ''),
            trim($_GET['dept']      ?? ''),
            trim($_GET['piid']      ?? ''),
            $page,
            $page_size
        );
        ok([
            'data'        => $result['rows'],
            'total'       => $result['total'],
            'page'        => $result['page'],
            'page_size'   => $result['page_size'],
            'total_pages' => $result['total_pages'],
        ]);

    // ── GET: single employee ─────────────────────────────────────────────────
    case 'employee':
        $piid = trim($_GET['piid'] ?? '');
        if (!$piid) fail('piid required');
        $emp = lv_get_employee($leave_conn, $piid);
        if (!$emp) fail('Employee not found', 404);
        ok(['data' => $emp]);

    // ── GET: departments ─────────────────────────────────────────────────────
    case 'departments':
        ok(['data' => lv_get_departments($leave_conn)]);

    case 'warm_roster':
        $year = (int)($_GET['year'] ?? date('Y'));
        $month = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
        lv_ensure_employee_roster($leave_conn, $year, $month);
        ok(['warmed' => true, 'year' => $year, 'month' => $month]);

    // ── GET: leave records ───────────────────────────────────────────────────
    case 'records':
        $piid = trim($_GET['piid'] ?? '');
        $year = (int)($_GET['year'] ?? date('Y'));
        if (!$piid) fail('piid required');
        // Normalize running balances before returning the ledger so rows
        // created outside this API (for example legacy tools) do not leave
        // stale VacBal/SickBal values visible in the web UI.
        lv_recalculate($leave_conn, $piid, $year);
        $records = lv_get_records($leave_conn, $piid, $year);
        ok(['data' => $records]);

    // ── GET: forwarded balance ───────────────────────────────────────────────
    case 'balance':
        $piid = trim($_GET['piid'] ?? '');
        $year = (int)($_GET['year'] ?? date('Y'));
        if (!$piid) fail('piid required');
        $bal = lv_get_balance($leave_conn, $piid, $year) ?? [
            'piid' => $piid, 'year' => $year,
            'cBackVaca' => 0, 'cBalSick' => 0,
            'NoAvailVL' => 0, 'NoAvailSL' => 0,
            'MonetaryVL' => 0, 'MonetarySL' => 0,
            'sourceYear' => $year - 1,
            'isAutoDerived' => 0,
        ];
        ok(['data' => $bal]);

    // ── GET: credits-earned lookup ───────────────────────────────────────────
    case 'dtr_suggested_undertime':
        $piid = trim($_GET['piid'] ?? '');
        $from = trim($_GET['from'] ?? '');
        $to = trim($_GET['to'] ?? '');
        if (!$piid) fail('piid required');
        if ($from === '') fail('from required');
        ok(['data' => lv_get_dtr_suggested_undertime($leave_conn, $piid, $from, $to)]);

    case 'credits_earned':
        ok(['data' => lv_get_credits_earned($leave_conn)]);

    // ── GET: working-day conversion ──────────────────────────────────────────
    case 'conversion':
        ok(['data' => lv_get_conversion($leave_conn)]);

    // ── GET: dashboard stats ─────────────────────────────────────────────────
    case 'dashboard_stats':
        ok(lv_dashboard_stats($leave_conn));

    // ── GET: report ──────────────────────────────────────────────────────────
    case 'report':
        $year  = (int)($_GET['year']       ?? date('Y'));
        $quarter = trim($_GET['quarter'] ?? '');
        $mfrom = isset($_GET['month_from']) && $_GET['month_from'] !== '' ? (int)$_GET['month_from'] : null;
        $mto   = isset($_GET['month_to'])   && $_GET['month_to'] !== ''   ? (int)$_GET['month_to']   : null;
        $dept  = trim($_GET['dept'] ?? '');
        if ($quarter !== '') {
            $quarter_map = [
                'Q1' => [1, 3],
                'Q2' => [4, 6],
                'Q3' => [7, 9],
                'Q4' => [10, 12],
            ];
            $quarter = strtoupper($quarter);
            if (!isset($quarter_map[$quarter])) fail('Invalid quarter');
            [$mfrom, $mto] = $quarter_map[$quarter];
        }
        ok(['data' => lv_report_summary($leave_conn, $year, $mfrom, $mto, $dept)]);

    // ── POST: save leave record (insert or update) ───────────────────────────
    case 'save_record':
        if ($method !== 'POST') fail('POST required');
        $piid = trim(bp('PIID'));
        if (!$piid) fail('PIID required');
        if (!lv_get_employee($leave_conn, $piid)) fail('Employee not found', 404);

        $d = [
            'PIID'            => $piid,
            'Type_of_Records' => bp_upper('Type_of_Records'),
            'Date_of_Filing'  => trim(bp('Date_of_Filing')),
            'Period_From'     => trim(bp('Period_From')),
            'Period_To'       => trim(bp('Period_To')),
            'VacEarn'         => bpf('VacEarn'),
            'VacWP'           => bpf('VacWP'),
            'VacBal'          => bpf('VacBal'),
            'VacWOP'          => bpf('VacWOP'),
            'SickEarn'        => bpf('SickEarn'),
            'SickWP'          => bpf('SickWP'),
            'SickBal'         => bpf('SickBal'),
            'SickWOP'         => bpf('SickWOP'),
            'Particulars'     => bp_upper('Particulars'),
            'DateAction'      => bp_upper('DateAction'),
            'DateProcessed'   => trim(bp('DateProcessed')),
            'RecordedBy'      => bp_upper('RecordedBy'),
            'no_avail_VL'     => bpf('no_avail_VL'),
            'no_avail_SL'     => bpf('no_avail_SL'),
            'no_avail_mone_VL'=> bpf('no_avail_mone_VL'),
            'no_avail_mone_SL'=> bpf('no_avail_mone_SL'),
            'no_avail_SP'     => bpf('no_avail_SP'),
            'no_avail_P'      => bpf('no_avail_P'),
            'no_avail_mone'   => bpf('no_avail_mone'),
            'Remarks'         => bp_upper('Remarks'),
        ];

        if (!$d['Period_From'] || !$d['Period_To']) fail('Period_From and Period_To are required');

        $year = (int)date('Y', strtotime($d['Period_From']));
        $lid = bpi('LID');

        if (lv_is_wellness_type($d['Type_of_Records'])) {
            $requested_wellness = lv_wellness_days_for_record($d);
            if ($requested_wellness > 5.0) {
                fail('Wellness Leave is capped at 5 days per year');
            }

            $used_wellness = lv_wellness_days_used($leave_conn, $piid, $year, $lid);
            $remaining_wellness = max(0, 5 - $used_wellness);
            if ($requested_wellness > $remaining_wellness) {
                fail('Wellness Leave exceeds the 5-day annual cap. Remaining balance: ' . number_format($remaining_wellness, 3));
            }
        }

        if ($lid > 0) {
            if (!lv_get_record($leave_conn, $lid)) fail('Record not found', 404);
            lv_update_record($leave_conn, $lid, $d, $actor);
        } else {
            $lid = lv_insert_record($leave_conn, $d, $actor);
        }

        lv_upsert_dtr_override($leave_conn, $lid, $d['Type_of_Records'], $d['Period_From'], $d['Period_To'], $d['Particulars'], $piid, $actor);
        lv_recalculate($leave_conn, $piid, $year);
        $updated = lv_get_records($leave_conn, $piid, $year);
        ok(['lid' => $lid, 'records' => $updated]);

    // ── POST: delete leave record ────────────────────────────────────────────
    case 'delete_record':
        if ($method !== 'POST') fail('POST required');
        $lid = bpi('LID');
        if (!$lid) fail('LID required');
        $rec = lv_get_record($leave_conn, $lid);
        if (!$rec) fail('Record not found', 404);
        $piid = $rec['PIID'];
        $recalc_year = (int)date('Y', strtotime($rec['Period_From']));
        $view_year = bpi('year');
        if ($view_year <= 0) {
            $view_year = (int)date('Y', strtotime($rec['Date_of_Filing'] ?: $rec['Period_From']));
        }
        $companion_lid = lv_find_cancel_companion($leave_conn, $rec);
        lv_delete_record($leave_conn, $lid);
        lv_delete_dtr_override($leave_conn, $lid);
        if ($companion_lid) {
            lv_delete_record($leave_conn, $companion_lid);
            lv_delete_dtr_override($leave_conn, $companion_lid);
        }
        lv_recalculate($leave_conn, $piid, $recalc_year);
        $updated = lv_get_records($leave_conn, $piid, $view_year);
        ok(['records' => $updated]);

    // ── POST: save forwarded balance ─────────────────────────────────────────
    case 'save_balance':
        if ($method !== 'POST') fail('POST required');
        $piid = trim(bp('piid'));
        $year = bpi('year');
        if (!$piid || !$year) fail('piid and year required');
        if (!lv_get_employee($leave_conn, $piid)) fail('Employee not found', 404);
        lv_upsert_balance($leave_conn, $piid, $year, [
            'cBackVaca'  => bpf('cBackVaca'),
            'cBalSick'   => bpf('cBalSick'),
            'NoAvailVL'  => bpf('NoAvailVL'),
            'NoAvailSL'  => bpf('NoAvailSL'),
            'MonetaryVL' => bpf('MonetaryVL'),
            'MonetarySL' => bpf('MonetarySL'),
        ]);
        lv_recalculate($leave_conn, $piid, $year);
        $updated = lv_get_records($leave_conn, $piid, $year);
        ok(['records' => $updated]);

    // ── POST: save credits-earned table ─────────────────────────────────────
    case 'save_credits_earned':
        if ($method !== 'POST') fail('POST required');
        $rows = bp('rows', []);
        if (!is_array($rows)) fail('rows must be an array');
        lv_save_credits_earned($leave_conn, $rows);
        ok(['data' => lv_get_credits_earned($leave_conn)]);

    // ── POST: save conversion table ──────────────────────────────────────────
    case 'save_conversion':
        if ($method !== 'POST') fail('POST required');
        $rows = bp('rows', []);
        if (!is_array($rows)) fail('rows must be an array');
        lv_save_conversion($leave_conn, $rows);
        ok(['data' => lv_get_conversion($leave_conn)]);

    // ── POST: cancel leave record ────────────────────────────────────────────
    case 'cancel_record':
        if ($method !== 'POST') fail('POST required');
        $lid = bpi('LID');
        if (!$lid) fail('LID required');
        $rec = lv_get_record($leave_conn, $lid);
        if (!$rec) fail('Record not found', 404);
        try {
            $new_lid = lv_cancel_record($leave_conn, $lid, trim(bp('filing_date')), $actor);
        } catch (RuntimeException $e) {
            fail($e->getMessage());
        }
        $piid_c   = (string)$rec['PIID'];
        $year_c   = (int)date('Y', strtotime((string)($rec['Period_From'] ?? date('Y-m-d'))));
        $updated  = lv_get_records($leave_conn, $piid_c, $year_c);
        ok(['new_lid' => $new_lid, 'records' => $updated]);

    // ── POST: reschedule leave record ────────────────────────────────────────
    case 'reschedule_record':
        if ($method !== 'POST') fail('POST required');
        $lid = bpi('LID');
        if (!$lid) fail('LID required');
        $rec = lv_get_record($leave_conn, $lid);
        if (!$rec) fail('Record not found', 404);
        $new_from = trim(bp('new_from'));
        $new_to   = trim(bp('new_to'));
        $filing   = trim(bp('filing_date'));
        try {
            $new_lid = lv_reschedule_record($leave_conn, $lid, $new_from, $new_to, $filing, $actor);
        } catch (RuntimeException $e) {
            fail($e->getMessage());
        }
        $piid_r   = (string)$rec['PIID'];
        $orig_yr  = (int)date('Y', strtotime((string)($rec['Period_From'] ?? date('Y-m-d'))));
        $updated  = lv_get_records($leave_conn, $piid_r, $orig_yr);
        ok(['new_lid' => $new_lid, 'records' => $updated]);

    default:
        fail('Unknown action', 404);
}
