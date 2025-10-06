<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: ../system/login.php");
    exit();
}

require_once '../config.php';
require_once '../vendor/autoload.php';

// Create new PDF document
$pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('Slaughter House Management System');
$pdf->SetAuthor('System');
$pdf->SetTitle('Slaughter Fee Report');
$pdf->SetSubject('Fee Report');

// Set default header data
$pdf->SetHeaderData('', 0, 'Slaughter House Management System', 'Fee Report - Generated on ' . date('Y-m-d H:i:s'));

// Set header and footer fonts
$pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
$pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

// Set default monospaced font
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

// Set margins
$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
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

try {
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
    $pdf->Cell(0, 6, 'Total Fees: ₱' . number_format($total_fees, 2), 0, 1, 'L');
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
            $pdf->Cell(15, 5, '₱' . number_format($operation['Slaughter_Fee'], 2), 1, 0, 'R');
            $pdf->Cell(15, 5, '₱' . number_format($operation['Corral_Fee'], 2), 1, 0, 'R');
            $pdf->Cell(15, 5, '₱' . number_format($operation['Ante_Mortem_Fee'], 2), 1, 0, 'R');
            $pdf->Cell(15, 5, '₱' . number_format($operation['Post_Mortem_Fee'], 2), 1, 0, 'R');
            $pdf->Cell(15, 5, '₱' . number_format($operation['Delivery_Fee'], 2), 1, 0, 'R');
            $pdf->Cell(18, 5, '₱' . number_format($operation['total_fee'], 2), 1, 1, 'R');
        }
    } else {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 10, 'No operations found for the selected date range.', 0, 1, 'C');
    }

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
    $pdf->Output($filename, 'D');

} catch (PDOException $e) {
    die('Database error: ' . $e->getMessage());
}

function formatDate($dateString) {
    $date = new DateTime($dateString);
    return $date->format('M d, Y');
}
?>