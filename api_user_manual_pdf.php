<?php
ob_start();

require_once 'config.php';
require_once 'token_auth.php';

date_default_timezone_set('Asia/Manila');

$user_auth = TokenAuth::authenticate($conn);
if (!$user_auth) {
    ob_end_clean();
    header('HTTP/1.0 401 Unauthorized');
    echo 'Unauthorized';
    exit();
}

ob_end_clean();

require_once '../vendor/autoload.php';

class UserManualPDF extends TCPDF {
    public function Header() {
        $logoSize = 18;
        $logoY = 7;
        $leftLogo = dirname(__DIR__) . '/system/image/ceedmo_logo_pdf.jpg';
        $rightLogo = dirname(__DIR__) . '/system/image/citylogo_pdf.jpg';

        if (file_exists($leftLogo)) {
            $this->Image($leftLogo, 15, $logoY, $logoSize, $logoSize);
        }

        if (file_exists($rightLogo)) {
            $this->Image($rightLogo, $this->getPageWidth() - 15 - $logoSize, $logoY, $logoSize, $logoSize);
        }

        $this->SetY(9);
        $this->SetFont('helvetica', 'B', 14);
        $this->Cell(0, 8, 'Slaughterhouse Management System', 0, 1, 'C');
        $this->SetFont('helvetica', '', 10);
        $this->Cell(0, 6, 'User Manual', 0, 1, 'C');
        $this->Ln(8);
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

function inlineMarkdownToHtml($text) {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
}

function markdownToPdfHtml($markdown) {
    $lines = preg_split('/\r\n|\r|\n/', $markdown);
    $html = '';
    $listType = null;

    $closeList = function () use (&$html, &$listType) {
        if ($listType) {
            $html .= '</' . $listType . '>';
            $listType = null;
        }
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '') {
            $closeList();
            continue;
        }

        if (preg_match('/^# (.+)$/', $trimmed, $matches)) {
            $closeList();
            $html .= '<h1>' . inlineMarkdownToHtml($matches[1]) . '</h1>';
            continue;
        }

        if (preg_match('/^## (.+)$/', $trimmed, $matches)) {
            $closeList();
            $html .= '<h2>' . inlineMarkdownToHtml($matches[1]) . '</h2>';
            continue;
        }

        if (preg_match('/^### (.+)$/', $trimmed, $matches)) {
            $closeList();
            $html .= '<h3>' . inlineMarkdownToHtml($matches[1]) . '</h3>';
            continue;
        }

        if (preg_match('/^- (.+)$/', $trimmed, $matches)) {
            if ($listType !== 'ul') {
                $closeList();
                $html .= '<ul>';
                $listType = 'ul';
            }
            $html .= '<li>' . inlineMarkdownToHtml($matches[1]) . '</li>';
            continue;
        }

        if (preg_match('/^\d+\. (.+)$/', $trimmed, $matches)) {
            if ($listType !== 'ol') {
                $closeList();
                $html .= '<ol>';
                $listType = 'ol';
            }
            $html .= '<li>' . inlineMarkdownToHtml($matches[1]) . '</li>';
            continue;
        }

        $closeList();
        $html .= '<p>' . inlineMarkdownToHtml($trimmed) . '</p>';
    }

    $closeList();
    return $html;
}

$manualPath = dirname(__DIR__) . '/USER_MANUAL.md';
$manualContent = file_exists($manualPath)
    ? file_get_contents($manualPath)
    : "# Slaughterhouse Management System User Manual\n\nThe user manual file could not be found.";

$pdf = new UserManualPDF('P', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('Slaughterhouse Management System');
$pdf->SetAuthor('Slaughterhouse Management System');
$pdf->SetTitle('Slaughterhouse Management System User Manual');
$pdf->SetSubject('User Manual');
$pdf->SetMargins(15, 32, 15);
$pdf->SetHeaderMargin(8);
$pdf->SetFooterMargin(10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->SetFont('helvetica', '', 10);
$pdf->AddPage();

$html = '
<style>
    h1 { color: #065f46; font-size: 19px; margin-bottom: 10px; }
    h2 { color: #047857; font-size: 14px; margin-top: 12px; margin-bottom: 6px; border-bottom: 1px solid #d1fae5; }
    h3 { color: #065f46; font-size: 11.5px; margin-top: 8px; margin-bottom: 4px; }
    p { line-height: 1.35; margin-bottom: 5px; }
    li { line-height: 1.35; margin-bottom: 3px; }
    ul, ol { margin-bottom: 6px; }
</style>
' . markdownToPdfHtml($manualContent);

$pdf->writeHTML($html, true, false, true, false, '');

$outputMode = isset($_GET['download']) && $_GET['download'] === '1' ? 'D' : 'I';
$pdf->Output('slaughter-house-user-manual.pdf', $outputMode);
exit();
?>
