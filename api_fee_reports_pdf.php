<?php
// Start output buffering to prevent any unwanted output from breaking PDF
ob_start();

require_once 'config.php';
require_once 'token_auth.php';

// Set timezone to match user's timezone
date_default_timezone_set('Asia/Shanghai');

// Authenticate user with token-based authentication
$user_auth = TokenAuth::authenticate($conn);
if (!$user_auth) {
    ob_end_clean();
    header("HTTP/1.0 401 Unauthorized");
    echo 'Unauthorized';
    exit();
}

// Clear any buffered output before sending PDF
ob_end_clean();

require_once '../vendor/autoload.php';

// Custom PDF class with logos in header
class CustomPDF extends TCPDF {
    public $logoPath = '';
    public $ceedmoLogoPath = '';
    public $pngCanProcess = true;
    
    public function setLogoPaths($cityPath, $ceedmoPath, $pngCanProcess = true) {
        $this->logoPath = $cityPath;
        $this->ceedmoLogoPath = $ceedmoPath;
        $this->pngCanProcess = $pngCanProcess;
    }
    
    public function Header() {
        $logoWidth = 20;
        $logoHeight = 20;
        
        // Add left logo (ceedmo - JPG format)
        if (!empty($this->ceedmoLogoPath) && file_exists($this->ceedmoLogoPath)) {
            try {
                $this->Image($this->ceedmoLogoPath, 15, 10, $logoWidth, $logoHeight, 'JPG', '', 'T', false, 300, 'L');
            } catch (Exception $e) {
                // Skip if error
            }
        }
        
        // Add right logo (city - PNG, skip if can't process)
        if ($this->pngCanProcess && !empty($this->logoPath) && file_exists($this->logoPath)) {
            try {
                $this->Image($this->logoPath, $this->getPageWidth() - 15 - $logoWidth, 10, $logoWidth, $logoHeight, 'PNG', '', 'T', false, 300, 'R');
            } catch (Exception $e) {
                // Skip if error
            }
        }
        
        // Add system name centered
        $this->SetFont('helvetica', 'B', 14);
        $this->SetXY(0, 12, true);
        $this->Cell(0, 10, 'Slaughter House Management System', 0, 1, 'C');
        
        // Reset font
        $this->SetFont('helvetica', '', 10);
    }
}

// Helper function to format money for TCPDF (replaces peso sign with P for compatibility)
function formatMoneyPDF($amount) {
    return 'P ' . number_format((float)$amount, 2);
}

// Create new PDF document
$pdf = new CustomPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set logo paths
$logoPath = dirname(__FILE__) . '/../system/image/citylogo.png';
$ceedmoLogoPath = dirname(__FILE__) . '/../system/image/vetlogo.jpg';

// Check if PNG can be processed (needs Imagick or GD)
$pngCanProcess = extension_loaded('imagick') || extension_loaded('gd');

// Only set PNG path if it can be processed
if (!$pngCanProcess) {
    $logoPath = ''; // Skip PNG if extension not available
}

$pdf->setLogoPaths($logoPath, $ceedmoLogoPath, $pngCanProcess);

// Set document information
$pdf->SetCreator('Slaughter House Management System');
$pdf->SetAuthor('System');
$pdf->SetTitle('Slaughter Fee Report');
$pdf->SetSubject('Fee Report');

// Set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// Set margins
$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP + 10, PDF_MARGIN_RIGHT);
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

// Set auto page breaks
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

// Set image scale factor
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Set font
$pdf->SetFont('helvetica', '', 10);

// Get parameters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
$fee_id = isset($_GET['fee_id']) ? (int)$_GET['fee_id'] : null;

// Get the logged-in user's name and position
$user_id = $user_auth['user_id'];
$stmt_user = $conn->prepare("SELECT Name as full_name, position FROM tbl_users WHERE UID = ?");
$stmt_user->execute([$user_id]);
$userData = $stmt_user->fetch(PDO::FETCH_ASSOC);

$prepared_by_name = $userData['full_name'] ?? $user_auth['username'];
$prepared_by_position = $userData['position'] ?? '';

// DEBUG: Log values for debugging
error_log("PDF - Fee ID: " . ($fee_id ?: 'null'));
error_log("PDF Prepared by - User ID: " . $user_id);
error_log("PDF Prepared by - Name: " . $prepared_by_name);

try {
    // Check if this is a single fee report
    if ($fee_id) {
        // Get the specific slaughter record with client info
        $stmt = $conn->prepare("
            SELECT 
                s.SID, s.Slaughter_Date, s.CID, s.BID, s.payment_status,
                CONCAT_WS(' ', c.Firstname, COALESCE(c.Middlename, ''), c.Surname) as client_name,
                cb.Business_Name
            FROM tbl_slaughter s
            LEFT JOIN tbl_clients c ON s.CID = c.CID
            LEFT JOIN tbl_client_business cb ON s.BID = cb.BID
            WHERE s.SID = ? AND (s.isdeleted IS NULL OR s.isdeleted = '0' OR s.isdeleted != '1')
            LIMIT 1
        ");
        $stmt->execute([$fee_id]);
        $slaughter = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$slaughter) {
            die('Fee entry not found');
        }
        
        // Get the details for this slaughter
        $stmt = $conn->prepare("
            SELECT 
                sd.Detail_ID, sd.No_of_Heads, sd.No_of_Kilos,
                sd.Slaughter_Fee, sd.Corral_Fee, sd.Ante_Mortem_Fee, sd.Post_Mortem_Fee, sd.Delivery_Fee,
                a.Animal
            FROM tbl_slaughter_details sd
            JOIN tbl_animals a ON sd.AID = a.AID
            WHERE sd.SID = ?
            ORDER BY sd.Detail_ID
        ");
        $stmt->execute([$fee_id]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals
        $totalSlaughter = 0;
        $totalCorral = 0;
        $totalAnteMortem = 0;
        $totalPostMortem = 0;
        $totalDelivery = 0;
        $grandTotal = 0;
        
        foreach ($details as $detail) {
            $totalSlaughter += (float)$detail['Slaughter_Fee'];
            $totalCorral += (float)$detail['Corral_Fee'];
            $totalAnteMortem += (float)$detail['Ante_Mortem_Fee'];
            $totalPostMortem += (float)$detail['Post_Mortem_Fee'];
            $totalDelivery += (float)$detail['Delivery_Fee'];
            $grandTotal += (float)$detail['Slaughter_Fee'] + (float)$detail['Corral_Fee'] + 
                          (float)$detail['Ante_Mortem_Fee'] + (float)$detail['Post_Mortem_Fee'] + 
                          (float)$detail['Delivery_Fee'];
        }
        
        // Add a page
        $pdf->AddPage();
        
        // Title
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Slaughter Fee Details', 0, 1, 'C');
        $pdf->Ln(5);
        
        // Fee information header
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 6, 'Date: ' . formatDate($slaughter['Slaughter_Date']), 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, 'Client: ' . $slaughter['client_name'], 0, 1, 'L');
        if ($slaughter['Business_Name']) {
            $pdf->Cell(0, 6, 'Business: ' . $slaughter['Business_Name'], 0, 1, 'L');
        }
        $pdf->Ln(10);
        
        // Details table
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell(50, 6, 'Animal', 1, 0, 'C', true);
        $pdf->Cell(20, 6, 'Heads', 1, 0, 'C', true);
        $pdf->Cell(25, 6, 'Kilos', 1, 0, 'C', true);
        $pdf->Cell(25, 6, 'Slaughter', 1, 0, 'C', true);
        $pdf->Cell(25, 6, 'Corral', 1, 0, 'C', true);
        $pdf->Cell(25, 6, 'Ante Mortem', 1, 0, 'C', true);
        $pdf->Cell(25, 6, 'Post Mortem', 1, 0, 'C', true);
        $pdf->Cell(25, 6, 'Delivery', 1, 0, 'C', true);
        $pdf->Cell(30, 6, 'Total', 1, 1, 'C', true);
        
        $pdf->SetFont('helvetica', '', 8);
        foreach ($details as $detail) {
            $rowTotal = (float)$detail['Slaughter_Fee'] + (float)$detail['Corral_Fee'] + 
                        (float)$detail['Ante_Mortem_Fee'] + (float)$detail['Post_Mortem_Fee'] + 
                        (float)$detail['Delivery_Fee'];
            
            $pdf->Cell(50, 5, $detail['Animal'], 1, 0, 'L');
            $pdf->Cell(20, 5, $detail['No_of_Heads'], 1, 0, 'C');
            $pdf->Cell(25, 5, number_format($detail['No_of_Kilos'], 2), 1, 0, 'R');
            $pdf->Cell(25, 5, formatMoneyPDF($detail['Slaughter_Fee']), 1, 0, 'R');
            $pdf->Cell(25, 5, formatMoneyPDF($detail['Corral_Fee']), 1, 0, 'R');
            $pdf->Cell(25, 5, formatMoneyPDF($detail['Ante_Mortem_Fee']), 1, 0, 'R');
            $pdf->Cell(25, 5, formatMoneyPDF($detail['Post_Mortem_Fee']), 1, 0, 'R');
            $pdf->Cell(25, 5, formatMoneyPDF($detail['Delivery_Fee']), 1, 0, 'R');
            $pdf->Cell(30, 5, formatMoneyPDF($rowTotal), 1, 1, 'R');
        }
        
        // Grand total
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(170, 8, 'Grand Total:', 0, 0, 'R');
        $pdf->Cell(0, 8, formatMoneyPDF($grandTotal), 0, 1, 'R');
        
        // Prepared by section
        $pdf->Ln(15);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 8, 'Prepared by:', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, $prepared_by_name, 0, 1, 'L');
        if (!empty($prepared_by_position)) {
            $pdf->Cell(0, 6, $prepared_by_position, 0, 1, 'L');
        }
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->Cell(0, 5, 'Generated on: ' . date('Y-m-d H:i:s'), 0, 1, 'L');
        
        // Output the PDF - display in browser
        $filename = 'fee_details_' . $fee_id . '_' . date('Y-m-d_H-i-s') . '.pdf';
        $pdf->Output($filename, 'I');
        exit;
    }
    
    // Original date range report logic
    // Build WHERE clause for date filtering
    $date_where = "";
    $params = [];

    if ($start_date && $end_date) {
        $date_where = "WHERE DATE(s.Slaughter_Date) BETWEEN ? AND ?";
        $params = [$start_date, $end_date];
    } elseif ($start_date) {
        $date_where = "WHERE DATE(s.Slaughter_Date) >= ?";
        $params = [$start_date];
    } elseif ($end_date) {
        $date_where = "WHERE DATE(s.Slaughter_Date) <= ?";
        $params = [$end_date];
    }

    // Get all operations within date range
    $stmt = $conn->prepare("
        SELECT DATE(s.Slaughter_Date) as date,
               s.SID,
               c.Firstname, c.Surname,
               a.Animal,
               sd.No_of_Heads, sd.No_of_Kilos,
               sd.Slaughter_Fee, sd.Corral_Fee, sd.Ante_Mortem_Fee, sd.Post_Mortem_Fee, sd.Delivery_Fee,
               (sd.Slaughter_Fee + sd.Corral_Fee + sd.Ante_Mortem_Fee + sd.Post_Mortem_Fee + sd.Delivery_Fee) as total_fee
        FROM tbl_slaughter s
        LEFT JOIN tbl_slaughter_details sd ON s.SID = sd.SID
        LEFT JOIN tbl_clients c ON s.CID = c.CID
        LEFT JOIN tbl_animals a ON sd.AID = a.AID
        {$date_where}
        ORDER BY s.Slaughter_Date DESC, s.SID DESC
    ");
    $stmt->execute($params);
    $operations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate summary statistics
    $total_operations = count($operations);
    $total_fees = 0;
    foreach ($operations as $operation) {
        $total_fees += $operation['total_fee'];
    }

    // Add a page
    $pdf->AddPage();

    // Title
    $report_title = 'Slaughter Fee Report';
    if ($start_date || $end_date) {
        $date_range = '';
        if ($start_date && $end_date) {
            $date_range = " ({$start_date} to {$end_date})";
        } elseif ($start_date) {
            $date_range = " (From {$start_date})";
        } elseif ($end_date) {
            $date_range = " (Until {$end_date})";
        }
        $report_title .= $date_range;
    }
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, $report_title, 0, 1, 'C');
    $pdf->Ln(10);

    // Summary
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Summary', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Total Operations: ' . $total_operations, 0, 1, 'L');
    $pdf->Cell(0, 6, 'Total Fees: ' . formatMoneyPDF($total_fees), 0, 1, 'L');
    $pdf->Ln(5);

    if (count($operations) > 0) {
        // Table headers
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell(20, 6, 'Date', 1, 0, 'C', true);
        $pdf->Cell(25, 6, 'Client Name', 1, 0, 'C', true);
        $pdf->Cell(20, 6, 'Animal', 1, 0, 'C', true);
        $pdf->Cell(12, 6, 'Heads', 1, 0, 'C', true);
        $pdf->Cell(15, 6, 'Kilos', 1, 0, 'C', true);
        $pdf->Cell(15, 6, 'Slaughter', 1, 0, 'C', true);
        $pdf->Cell(15, 6, 'Corral', 1, 0, 'C', true);
        $pdf->Cell(15, 6, 'Ante Mortem', 1, 0, 'C', true);
        $pdf->Cell(15, 6, 'Post Mortem', 1, 0, 'C', true);
        $pdf->Cell(15, 6, 'Delivery', 1, 0, 'C', true);
        $pdf->Cell(18, 6, 'Total Fee', 1, 1, 'C', true);

        // Table data
        $pdf->SetFont('helvetica', '', 7);
        foreach ($operations as $operation) {
            $client_name = $operation['Firstname'] . ' ' . $operation['Surname'];
            if (strlen($client_name) > 20) {
                $client_name = substr($client_name, 0, 17) . '...';
            }

            $pdf->Cell(20, 5, formatDate($operation['date']), 1, 0, 'C');
            $pdf->Cell(25, 5, $client_name, 1, 0, 'L');
            $pdf->Cell(20, 5, $operation['Animal'], 1, 0, 'L');
            $pdf->Cell(12, 5, $operation['No_of_Heads'], 1, 0, 'C');
            $pdf->Cell(15, 5, $operation['No_of_Kilos'], 1, 0, 'R');
            $pdf->Cell(15, 5, formatMoneyPDF($operation['Slaughter_Fee']), 1, 0, 'R');
            $pdf->Cell(15, 5, formatMoneyPDF($operation['Corral_Fee']), 1, 0, 'R');
            $pdf->Cell(15, 5, formatMoneyPDF($operation['Ante_Mortem_Fee']), 1, 0, 'R');
            $pdf->Cell(15, 5, formatMoneyPDF($operation['Post_Mortem_Fee']), 1, 0, 'R');
            $pdf->Cell(15, 5, formatMoneyPDF($operation['Delivery_Fee']), 1, 0, 'R');
            $pdf->Cell(18, 5, formatMoneyPDF($operation['total_fee']), 1, 1, 'R');
        }
    } else {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 10, 'No operations found for the selected date range.', 0, 1, 'C');
    }

    // Prepared by section with position
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 8, 'Prepared by:', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, $prepared_by_name, 0, 1, 'L');
    if (!empty($prepared_by_position)) {
        $pdf->Cell(0, 6, $prepared_by_position, 0, 1, 'L');
    }
    // Add generation timestamp
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 5, 'Report generated on: ' . date('Y-m-d H:i:s'), 0, 1, 'L');

    // Output the PDF
    $date_suffix = '';
    if ($start_date || $end_date) {
        if ($start_date && $end_date) {
            $date_suffix = "_{$start_date}_to_{$end_date}";
        } elseif ($start_date) {
            $date_suffix = "_from_{$start_date}";
        } elseif ($end_date) {
            $date_suffix = "_until_{$end_date}";
        }
    }
    $filename = 'fee_report' . $date_suffix . '_' . date('Y-m-d_H-i-s') . '.pdf';
    
    // Output to file first for debugging
    $pdf->Output(__DIR__ . '/../debug_' . $filename, 'F');
    
    // Then output for download
    $pdf->Output($filename, 'D');

} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}

function formatDate($dateString) {
    $date = new DateTime($dateString);
    return $date->format('M d, Y');
}
?>
