<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

error_reporting(0);
ini_set('display_errors', 0);

function sendResponse($success, $message, $data = null) {
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response);
    exit();
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'generate':
        handleGenerateReceipt();
        break;
    case 'by_payment':
        handleGetReceiptByPaymentId();
        break;
    case 'get':
        handleGetReceipt();
        break;
    case 'user_receipts':
        handleGetUserReceipts();
        break;
    case 'download_pdf':
        handleDownloadPdf();
        break;
    default:
        sendResponse(false, 'Invalid action');
}

function handleGenerateReceipt() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['payment_id'])) {
        sendResponse(false, 'Invalid input. payment_id is required');
    }
    
    $paymentId = intval($input['payment_id']);
    $paymentMode = isset($input['payment_mode']) ? $input['payment_mode'] : 'Cash';
    
    if ($paymentId <= 0) {
        sendResponse(false, 'Valid payment_id is required');
    }
    
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=cozy_corner;charset=utf8mb4", "root", "");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $checkStmt = $pdo->prepare("SELECT id FROM receipts WHERE payment_id = ?");
        $checkStmt->execute([$paymentId]);
        if ($checkStmt->fetch()) {
            sendResponse(false, 'Receipt already exists for this payment');
        }
        
        $stmt = $pdo->prepare("
            SELECT rp.*, u.name as tenant_name, ro.type as room_type 
            FROM rent_payments rp 
            JOIN users u ON rp.user_id = u.id 
            JOIN rooms ro ON rp.room_id = ro.room_id 
            WHERE rp.id = ?
        ");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            sendResponse(false, 'Payment not found');
        }
        
        $baseRent = floatval($payment['amount']);
        $lateFee = floatval($payment['late_fee']);
        $previousBalance = floatval($payment['previous_month_balance']);
        $totalAmount = $baseRent + $lateFee + $previousBalance;
        $paidAmount = floatval($payment['paid_amount']);
        
        $receiptNumber = 'RC' . date('YmdHis');
        
        $stmt = $pdo->prepare("
            INSERT INTO receipts (
                payment_id, user_id, tenant_name, room_number, room_type, month_year,
                base_rent, late_fee, previous_balance, total_amount, paid_amount,
                payment_mode, payment_date, receipt_number
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $success = $stmt->execute([
            $paymentId,
            $payment['user_id'],
            $payment['tenant_name'],
            $payment['room_id'],
            $payment['room_type'],
            $payment['month_year'],
            $baseRent,
            $lateFee,
            $previousBalance,
            $totalAmount,
            $paidAmount,
            $paymentMode,
            $payment['payment_date'] ?: date('Y-m-d'),
            $receiptNumber
        ]);
        
        if ($success) {
            $receiptId = $pdo->lastInsertId();
            sendResponse(true, 'Receipt generated successfully', [
                'receipt_id' => $receiptId,
                'receipt_number' => $receiptNumber
            ]);
        } else {
            sendResponse(false, 'Failed to generate receipt');
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Database error');
    }
}

function handleGetReceiptByPaymentId() {
    $paymentId = isset($_GET['payment_id']) ? intval($_GET['payment_id']) : 0;
    
    if ($paymentId <= 0) {
        sendResponse(false, 'Valid payment_id is required');
    }
    
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=cozy_corner;charset=utf8mb4", "root", "");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("SELECT * FROM receipts WHERE payment_id = ?");
        $stmt->execute([$paymentId]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($receipt) {
            sendResponse(true, 'Receipt found', ['receipt' => $receipt]);
        } else {
            sendResponse(true, 'No receipt found', ['receipt' => null]);
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Database error');
    }
}

function handleGetReceipt() {
    $receiptId = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($receiptId <= 0) {
        sendResponse(false, 'Valid id is required');
    }
    
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=cozy_corner;charset=utf8mb4", "root", "");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("SELECT * FROM receipts WHERE id = ?");
        $stmt->execute([$receiptId]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($receipt) {
            sendResponse(true, 'Receipt found', ['receipt' => $receipt]);
        } else {
            sendResponse(false, 'Receipt not found');
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Database error');
    }
}

function handleGetUserReceipts() {
    $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    
    if ($userId <= 0) {
        sendResponse(false, 'Valid user_id is required');
    }
    
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=cozy_corner;charset=utf8mb4", "root", "");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("SELECT * FROM receipts WHERE user_id = ? ORDER BY generated_at DESC");
        $stmt->execute([$userId]);
        $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse(true, 'Receipts retrieved', ['receipts' => $receipts]);
        
    } catch (Exception $e) {
        sendResponse(false, 'Database error');
    }
}

function handleDownloadPdf() {
    $receiptId = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($receiptId <= 0) {
        sendResponse(false, 'Valid id is required');
    }
    
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=cozy_corner;charset=utf8mb4", "root", "");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("SELECT * FROM receipts WHERE id = ?");
        $stmt->execute([$receiptId]);
        $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$receipt) {
            sendResponse(false, 'Receipt not found');
        }
        
        // Generate PDF file
        $pdfResult = generatePdfFile($receipt);
        
        if ($pdfResult['success']) {
            sendResponse(true, 'PDF generated successfully', [
                'pdf_url' => $pdfResult['pdf_url'],
                'file_path' => $pdfResult['file_path'],
                'receipt_number' => $receipt['receipt_number']
            ]);
        } else {
            sendResponse(false, 'PDF generation failed: ' . $pdfResult['error']);
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'PDF generation failed: ' . $e->getMessage());
    }
}

function generatePdfFile($receipt) {
    try {
        require_once('vendor/autoload.php');
        use TCPDF;
        
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('Cozy Corner PG');
        $pdf->SetAuthor('Cozy Corner PG');
        $pdf->SetTitle('Rent Receipt - ' . $receipt['receipt_number']);
        $pdf->SetSubject('Rent Payment Receipt');
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(TRUE, 15);
        
        // Add a page
        $pdf->AddPage();
        
        // Use a font that might support Rupee symbol, or fallback to "Rs."
        $pdf->SetFont('dejavusans', 'B', 16);
        
        // Company Header
        $pdf->SetTextColor(41, 128, 185);
        $pdf->Cell(0, 10, 'COZY CORNER PG', 0, 1, 'C');
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->Cell(0, 6, '123 PG Street, City - 123456 | Phone: +91 9876543210', 0, 1, 'C');
        
        // Receipt Title
        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(8);
        $pdf->Cell(0, 10, 'RENT RECEIPT', 0, 1, 'C');
        $pdf->Ln(5);
        
        // Receipt Details
        $pdf->SetFont('dejavusans', '', 10);
        
        $details = [
            ['Receipt Number:', $receipt['receipt_number']],
            ['Date:', date('d/m/Y', strtotime($receipt['payment_date']))],
            ['Tenant Name:', $receipt['tenant_name']],
            ['Room:', $receipt['room_number'] . ' (' . $receipt['room_type'] . ')'],
            ['Month:', $receipt['month_year']],
            ['Payment Mode:', $receipt['payment_mode']]
        ];
        
        foreach ($details as $detail) {
            $pdf->Cell(50, 6, $detail[0], 0, 0);
            $pdf->SetFont('dejavusans', 'B', 10);
            $pdf->Cell(0, 6, $detail[1], 0, 1);
            $pdf->SetFont('dejavusans', '', 10);
        }
        
        $pdf->Ln(8);
        
        // Payment Breakdown Header
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, 'PAYMENT BREAKDOWN', 0, 1);
        $pdf->Ln(2);
        
        // Payment Breakdown Table
        $pdf->SetFont('dejavusans', '', 10);
        
        // Table header
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(120, 8, 'Description', 1, 0, 'L', true);
        $pdf->Cell(40, 8, 'Amount', 1, 1, 'R', true);
        
        // Table rows
        $rows = [
            ['Base Rent', $receipt['base_rent']]
        ];
        
        if ($receipt['previous_balance'] > 0) {
            $rows[] = ['Previous Balance', $receipt['previous_balance']];
        }
        
        if ($receipt['late_fee'] > 0) {
            $rows[] = ['Late Fee', $receipt['late_fee']];
        }
        
        $rows[] = ['Total Amount', $receipt['total_amount'], true];
        $rows[] = ['Paid Amount', $receipt['paid_amount'], true];
        
        foreach ($rows as $row) {
            $isTotal = isset($row[2]) && $row[2];
            if ($isTotal) {
                $pdf->SetFont('dejavusans', 'B', 10);
                if ($row[0] == 'Paid Amount') {
                    $pdf->SetFillColor(230, 255, 230);
                } else {
                    $pdf->SetFillColor(230, 240, 255);
                }
            }
            
            $pdf->Cell(120, 8, $row[0], 1, 0, 'L', $isTotal);
            
            // Try using Unicode Rupee symbol, fallback to "Rs."
            $rupeeSymbol = "₹"; // Unicode Rupee symbol
            $amountText = $rupeeSymbol . ' ' . number_format($row[1], 2);
            $pdf->Cell(40, 8, $amountText, 1, 1, 'R', $isTotal);
            
            if ($isTotal) {
                $pdf->SetFont('dejavusans', '', 10);
                $pdf->SetFillColor(255, 255, 255);
            }
        }
        
        $pdf->Ln(12);
        
        // Footer
        $pdf->SetFont('dejavusans', 'I', 9);
        $pdf->SetTextColor(128, 128, 128);
        $pdf->Cell(0, 6, 'Generated on: ' . date('d/m/Y H:i:s', strtotime($receipt['generated_at'])), 0, 1);
        $pdf->Cell(0, 6, 'This is a computer generated receipt - No signature required', 0, 1);
        
        $pdf->Ln(8);
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetTextColor(41, 128, 185);
        $pdf->Cell(0, 8, 'Thank you for your payment!', 0, 1, 'C');
        
        // Create PDFs directory if it doesn't exist
        $pdfsDir = __DIR__ . '/pdfs';
        if (!is_dir($pdfsDir)) {
            mkdir($pdfsDir, 0777, true);
        }
        
        $filename = "receipt_{$receipt['receipt_number']}.pdf";
        $filepath = $pdfsDir . '/' . $filename;
        
        $pdf->Output($filepath, 'F');
        
        $baseUrl = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
        $pdfUrl = $baseUrl . "/pdfs/" . $filename;
        
        return [
            'success' => true,
            'pdf_url' => $pdfUrl,
            'file_path' => $filepath
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

?>
