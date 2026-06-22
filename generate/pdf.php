<?php
// Lightweight PDF wrapper using mPDF (optional). Install mPDF locally and upload vendor/ if needed.
// Note: mPDF is best used on PHP 8.1+ with lean HTML/CSS templates.
// Keep SOW styles minimal for shared hosting memory limits, and ensure /uploads/sows/ exists and is writable.
function generatePDFfromHTML(string $html, string $filename): string {
    // Save path relative to project root
    $outDir = __DIR__ . '/../uploads/sows';
    if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
        error_log('Unable to create PDF output directory: ' . $outDir);
        return '';
    }
    if (!is_writable($outDir)) {
        error_log('PDF output directory is not writable: ' . $outDir);
        return '';
    }

    $outPath = $outDir . '/' . basename($filename);

    if (class_exists('\Mpdf\Mpdf')) {
        try {
            $mpdf = new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4']);
            $mpdf->WriteHTML($html);
            $mpdf->Output($outPath, 'F');
            return '/uploads/sows/' . basename($filename);
        } catch (Exception $e) {
            error_log('mPDF error: ' . $e->getMessage());
        }
    }

    // Fallback: write HTML file for manual conversion
    file_put_contents($outPath . '.html', $html);
    return '/uploads/sows/' . basename($filename) . '.html';
}
