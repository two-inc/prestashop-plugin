<?php
/**
 * Two Invoice Upload Service
 * 
 * Handles uploading PrestaShop invoices to Two's API using the three-step process:
 * 1. Request signed upload URL from Two
 * 2. Upload PDF to Google Cloud Storage using signed URL
 * 3. Poll upload status until validated
 * 
 * @author Two
 * @version 1.0.0
 * @since 2.2.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TwoInvoiceUploadService
{
    /** @var TwoPayment */
    private $module;
    
    /** @var string Two's API base URL */
    private $apiBaseUrl;
    
    /** @var string Merchant API key */
    private $apiKey;
    
    /** @var int Maximum file size (2MB) */
    const MAX_FILE_SIZE = 2097152;
    
    /** @var int Upload status polling timeout (60 seconds) */
    const POLLING_TIMEOUT = 60;
    
    /** @var int Polling interval (1 second) */
    const POLLING_INTERVAL = 1;
    
    /** @var int Maximum retry attempts for network errors */
    const MAX_RETRIES = 3;
    
    /**
     * Constructor
     * 
     * @param TwoPayment $module Module instance
     */
    public function __construct($module)
    {
        $this->module = $module;
        $this->apiBaseUrl = $this->module->host;
        $this->apiKey = Configuration::get('PS_TWO_MERCHANT_API_KEY');
    }
    
    /**
     * Upload invoice PDF to Two
     * 
     * This is the main entry point that orchestrates the three-step process.
     * 
     * @param int $id_order PrestaShop order ID
     * @param string $two_invoice_id Two's invoice ID (from order creation response)
     * @param int $index Document index (0-9, use 0 for single document)
     * @return array Result with 'success' boolean and 'message'/'error' string
     */
    public function uploadInvoice($id_order, $two_invoice_id, $index = 0)
    {
        try {
            PrestaShopLogger::addLog(
                'TwoInvoiceUpload: Starting invoice upload for Order ' . $id_order . 
                ', Two Invoice ID: ' . $two_invoice_id . ', Index: ' . $index,
                1,
                null,
                'Order',
                $id_order
            );
            
            // Validation
            if (empty($two_invoice_id)) {
                return $this->errorResult('Two invoice ID is missing');
            }
            
            if ($index < 0 || $index > 9) {
                return $this->errorResult('Invalid index (must be 0-9)');
            }
            
            // STEP 1: Get PrestaShop invoice PDF
            $invoicePdf = $this->getPrestaShopInvoicePdf($id_order);
            if (!$invoicePdf['success']) {
                return $this->errorResult($invoicePdf['error']);
            }
            
            $pdfContent = $invoicePdf['content'];
            $pdfSize = strlen($pdfContent);
            
            // Validate file size
            if ($pdfSize === 0) {
                return $this->errorResult('Invoice PDF is empty');
            }
            
            if ($pdfSize > self::MAX_FILE_SIZE) {
                return $this->errorResult(
                    'Invoice PDF exceeds maximum size (2MB). Size: ' . 
                    round($pdfSize / 1024 / 1024, 2) . 'MB'
                );
            }
            
            PrestaShopLogger::addLog(
                'TwoInvoiceUpload: Retrieved PrestaShop invoice PDF - Size: ' . 
                round($pdfSize / 1024, 2) . 'KB',
                1,
                null,
                'Order',
                $id_order
            );
            
            // STEP 2: Request signed upload URL from Two
            $signedUrlResult = $this->requestSignedUploadUrl($two_invoice_id, $index);
            if (!$signedUrlResult['success']) {
                return $this->errorResult($signedUrlResult['error']);
            }
            
            $uploadUrl = $signedUrlResult['url'];
            $uploadHeaders = $signedUrlResult['headers'];
            $uploadReference = $signedUrlResult['reference'];
            
            PrestaShopLogger::addLog(
                'TwoInvoiceUpload: Received signed URL - Reference: ' . $uploadReference,
                1,
                null,
                'Order',
                $id_order
            );
            
            // STEP 3: Upload PDF to Google Cloud Storage
            $uploadResult = $this->uploadToCloudStorage($uploadUrl, $uploadHeaders, $pdfContent);
            if (!$uploadResult['success']) {
                return $this->errorResult($uploadResult['error']);
            }
            
            PrestaShopLogger::addLog(
                'TwoInvoiceUpload: Successfully uploaded to cloud storage',
                1,
                null,
                'Order',
                $id_order
            );
            
            // STEP 4: Poll upload status
            $statusResult = $this->pollUploadStatus($uploadReference, $id_order);
            if (!$statusResult['success']) {
                return $this->errorResult($statusResult['error']);
            }
            
            PrestaShopLogger::addLog(
                'TwoInvoiceUpload: ✓ Invoice upload completed successfully for Order ' . $id_order,
                1,
                null,
                'Order',
                $id_order
            );
            
            return array(
                'success' => true,
                'message' => 'Invoice uploaded successfully',
                'reference' => $uploadReference,
            );
            
        } catch (Exception $e) {
            $error = 'Exception during invoice upload: ' . $e->getMessage();
            PrestaShopLogger::addLog(
                'TwoInvoiceUpload: ' . $error,
                3,
                null,
                'Order',
                $id_order
            );
            return $this->errorResult($error);
        }
    }
    
    /**
     * Get PrestaShop invoice PDF content
     * 
     * @param int $id_order PrestaShop order ID
     * @return array Result with 'success' and 'content'/'error'
     */
    private function getPrestaShopInvoicePdf($id_order)
    {
        try {
            $order = new Order((int)$id_order);
            if (!Validate::isLoadedObject($order)) {
                return array('success' => false, 'error' => 'Order not found');
            }
            
            // Get order invoice collection
            $invoiceCollection = $order->getInvoicesCollection();
            if (!$invoiceCollection || $invoiceCollection->count() === 0) {
                return array(
                    'success' => false,
                    'error' => 'No invoice generated yet for this order. Invoice generation may be pending.'
                );
            }
            
            // Get the first (and usually only) invoice
            $orderInvoice = $invoiceCollection->getFirst();
            if (!Validate::isLoadedObject($orderInvoice)) {
                return array('success' => false, 'error' => 'Invoice object is invalid');
            }
            
            // Generate PDF using PrestaShop's PDF generator
            // This uses the same class as when merchants download invoices from BO
            $pdf = new PDF($orderInvoice, PDF::TEMPLATE_INVOICE, Context::getContext()->smarty);
            
            // Render PDF and get raw content
            $pdfContent = $pdf->render(false); // false = return content instead of outputting
            
            if (empty($pdfContent)) {
                return array('success' => false, 'error' => 'Failed to generate PDF content');
            }
            
            return array(
                'success' => true,
                'content' => $pdfContent,
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => 'Failed to retrieve invoice PDF: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Step 1: Request signed upload URL from Two
     * 
     * PUT /uploads/v1/invoice/{invoice_id}/external_invoice/{index}
     * 
     * @param string $two_invoice_id Two's invoice ID
     * @param int $index Document index (0-9)
     * @return array Result with 'success', 'url', 'headers', 'reference' or 'error'
     */
    private function requestSignedUploadUrl($two_invoice_id, $index)
    {
        $endpoint = '/uploads/v1/invoice/' . urlencode($two_invoice_id) . '/external_invoice/' . (int)$index;
        $payload = array('content_type' => 'application/pdf');
        
        $response = $this->module->setTwoPaymentRequest($endpoint, $payload, 'PUT');
        
        // Check for HTTP status in enhanced response
        $httpStatus = isset($response['http_status']) ? (int)$response['http_status'] : 0;
        
        if ($httpStatus !== 202) {
            // Handle specific error cases
            $errorMsg = $this->parseUploadUrlError($response, $httpStatus);
            PrestaShopLogger::addLog(
                'TwoInvoiceUpload: Failed to get signed URL - HTTP ' . $httpStatus . ' - ' . $errorMsg,
                3
            );
            return array('success' => false, 'error' => $errorMsg);
        }
        
        // Validate response structure
        if (!isset($response['url']) || !isset($response['headers']) || !isset($response['reference'])) {
            return array(
                'success' => false,
                'error' => 'Invalid response from Two API (missing url/headers/reference)'
            );
        }
        
        return array(
            'success' => true,
            'url' => $response['url'],
            'headers' => $response['headers'],
            'reference' => $response['reference'],
        );
    }
    
    /**
     * Parse error message from signed URL request
     * 
     * @param array $response API response
     * @param int $httpStatus HTTP status code
     * @return string User-friendly error message
     */
    private function parseUploadUrlError($response, $httpStatus)
    {
        switch ($httpStatus) {
            case 403:
                return 'Merchant not whitelisted for invoice uploads. Please contact Two support.';
            case 404:
                return 'Invoice not found. Order may not be fulfilled yet.';
            case 409:
                return 'Invoice already uploaded for this index. Use a different index or retrieve existing upload.';
            case 422:
                return 'Validation error: Invalid content type (must be application/pdf)';
            default:
                // Try to extract error from response
                if (isset($response['error'])) {
                    return is_array($response['error']) 
                        ? json_encode($response['error']) 
                        : $response['error'];
                }
                if (isset($response['message'])) {
                    return $response['message'];
                }
                return 'Failed to request upload URL (HTTP ' . $httpStatus . ')';
        }
    }
    
    /**
     * Step 2: Upload PDF to Google Cloud Storage using signed URL
     * 
     * @param string $uploadUrl Signed URL from Two
     * @param array $uploadHeaders Headers from Two's response
     * @param string $pdfContent Raw PDF file content
     * @return array Result with 'success' or 'error'
     */
    private function uploadToCloudStorage($uploadUrl, $uploadHeaders, $pdfContent)
    {
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < self::MAX_RETRIES) {
            $attempt++;
            
            try {
                // Initialize cURL
                $ch = curl_init();
                
                // Build headers array for cURL
                $curlHeaders = array();
                foreach ($uploadHeaders as $key => $value) {
                    $curlHeaders[] = $key . ': ' . $value;
                }
                
                // Configure cURL for PUT request
                curl_setopt_array($ch, array(
                    CURLOPT_URL => $uploadUrl,
                    CURLOPT_CUSTOMREQUEST => 'PUT',
                    CURLOPT_HTTPHEADER => $curlHeaders,
                    CURLOPT_POSTFIELDS => $pdfContent,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_FOLLOWLOCATION => false, // Don't follow redirects on signed URLs
                ));
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                // Success: HTTP 200 with empty body
                if ($httpCode === 200) {
                    return array('success' => true);
                }
                
                // Handle errors
                $lastError = $this->parseCloudStorageError($httpCode, $response, $curlError);
                
                // Don't retry on client errors (4xx) - these won't succeed
                if ($httpCode >= Twopayment::HTTP_STATUS_BAD_REQUEST && $httpCode < Twopayment::HTTP_STATUS_SERVER_ERROR) {
                    break;
                }
                
                // Retry on network errors or 5xx
                if ($attempt < self::MAX_RETRIES) {
                    PrestaShopLogger::addLog(
                        'TwoInvoiceUpload: Cloud storage upload failed (attempt ' . $attempt . 
                        '/' . self::MAX_RETRIES . ') - HTTP ' . $httpCode . 
                        ' - Retrying in 2 seconds...',
                        2
                    );
                    sleep(2); // Wait before retry
                }
                
            } catch (Exception $e) {
                $lastError = 'Exception during upload: ' . $e->getMessage();
                
                if ($attempt < self::MAX_RETRIES) {
                    PrestaShopLogger::addLog(
                        'TwoInvoiceUpload: Upload exception (attempt ' . $attempt . 
                        '/' . self::MAX_RETRIES . ') - ' . $lastError,
                        2
                    );
                    sleep(2);
                }
            }
        }
        
        return array(
            'success' => false,
            'error' => 'Failed to upload to cloud storage after ' . self::MAX_RETRIES . 
                      ' attempts. Last error: ' . $lastError
        );
    }
    
    /**
     * Parse error from cloud storage upload
     * 
     * @param int $httpCode HTTP status code
     * @param string $response Response body
     * @param string $curlError cURL error if any
     * @return string Error message
     */
    private function parseCloudStorageError($httpCode, $response, $curlError)
    {
        if (!empty($curlError)) {
            return 'Network error: ' . $curlError;
        }
        
        switch ($httpCode) {
            case 403:
                return 'Upload rejected: Signature mismatch or expired URL. Headers may be incorrect.';
            case 400:
                if (strpos($response, 'too large') !== false) {
                    return 'File too large (exceeds 2MB limit)';
                }
                return 'Invalid request: ' . substr($response, 0, 200);
            default:
                return 'HTTP ' . $httpCode . ': ' . substr($response, 0, 200);
        }
    }
    
    /**
     * Step 3: Poll upload status until completed or failed
     * 
     * GET /uploads/v1/status/{reference}
     * 
     * @param string $reference Upload reference from Two
     * @param int $id_order Order ID (for logging)
     * @return array Result with 'success' or 'error'
     */
    private function pollUploadStatus($reference, $id_order)
    {
        // Initial delay before first poll (500ms)
        usleep(500000);
        
        $startTime = time();
        $pollCount = 0;
        
        while ((time() - $startTime) < self::POLLING_TIMEOUT) {
            $pollCount++;
            
            $endpoint = '/uploads/v1/status/' . urlencode($reference);
            $response = $this->module->setTwoPaymentRequest($endpoint, array(), 'GET');
            
            $httpStatus = isset($response['http_status']) ? (int)$response['http_status'] : 0;
            
            if ($httpStatus !== 200) {
                PrestaShopLogger::addLog(
                    'TwoInvoiceUpload: Status polling failed - HTTP ' . $httpStatus,
                    3,
                    null,
                    'Order',
                    $id_order
                );
                return array(
                    'success' => false,
                    'error' => 'Failed to poll upload status (HTTP ' . $httpStatus . ')'
                );
            }
            
            $status = isset($response['status']) ? strtoupper($response['status']) : '';
            
            PrestaShopLogger::addLog(
                'TwoInvoiceUpload: Poll #' . $pollCount . ' - Status: ' . $status,
                1,
                null,
                'Order',
                $id_order
            );
            
            switch ($status) {
                case 'OK':
                    return array('success' => true);
                    
                case 'INVALID':
                    return array(
                        'success' => false,
                        'error' => 'Upload validation failed. PDF may be corrupted or invalid.'
                    );
                    
                case 'PENDING':
                case 'PROCESSING':
                case 'AWAITING_UPLOAD':
                    // Continue polling - these are all valid "in-progress" states
                    sleep(self::POLLING_INTERVAL);
                    break;
                    
                default:
                    return array(
                        'success' => false,
                        'error' => 'Unknown status: ' . $status
                    );
            }
        }
        
        // Timeout reached
        PrestaShopLogger::addLog(
            'TwoInvoiceUpload: Status polling timeout after ' . self::POLLING_TIMEOUT . 
            ' seconds and ' . $pollCount . ' attempts. Status may complete asynchronously.',
            2,
            null,
            'Order',
            $id_order
        );
        
        return array(
            'success' => false,
            'error' => 'Upload status polling timeout after ' . self::POLLING_TIMEOUT . 
                      ' seconds. Upload may still complete in background.'
        );
    }
    
    /**
     * Update invoice upload status in database
     * 
     * @param int $id_order PrestaShop order ID
     * @param string $status Status: PENDING, UPLOADING, UPLOADED, FAILED
     * @param string|null $reference Upload reference
     * @param string|null $error Error message if failed
     * @return bool Success
     */
    public function updateUploadStatus($id_order, $status, $reference = null, $error = null)
    {
        $data = array(
            'two_invoice_upload_status' => pSQL($status),
        );
        
        if ($reference !== null) {
            $data['two_invoice_upload_reference'] = pSQL($reference);
        }
        
        if ($error !== null) {
            $data['two_invoice_upload_error'] = pSQL($error);
        }
        
        if ($status === 'UPLOADED') {
            $data['two_invoice_uploaded_at'] = date('Y-m-d H:i:s');
        }
        
        return Db::getInstance()->update(
            'twopayment',
            $data,
            'id_order = ' . (int)$id_order
        );
    }
    
    /**
     * Helper to create error result
     * 
     * @param string $error Error message
     * @return array
     */
    private function errorResult($error)
    {
        return array(
            'success' => false,
            'error' => $error,
        );
    }
}

