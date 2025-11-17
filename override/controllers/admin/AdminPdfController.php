<?php
/**
 * Override del controlador Admin de PDFs
 * Intercepta la generación de PDFs de facturas para generar albaranes de PrestaShop
 */

class AdminPdfController extends AdminPdfControllerCore
{
    public function postProcess()
    {
        // Interceptar cuando se solicita una factura para generar un albarán en su lugar
        if (Tools::getValue('submitAction') == 'generateInvoicePDF') {
            if ($this->generateDeliverySlip()) {
                return; // Ya se ha servido el albarán de PrestaShop
            }
        }

        parent::postProcess();
    }

    private function generateDeliverySlip()
    {
        error_log('[Albaran Admin] generateDeliverySlip ejecutándose...');

        // Obtener ID del pedido (puede venir de diferentes lugares)
        $id_order = (int)Tools::getValue('id_order');

        // Si no viene en id_order, intentar con id
        if (!$id_order) {
            $id_order = (int)Tools::getValue('id');
        }

        // Si tampoco, intentar obtenerlo de id_order_invoice
        if (!$id_order) {
            $id_invoice = (int)Tools::getValue('id_order_invoice');
            if ($id_invoice) {
                $order_invoice = new OrderInvoice($id_invoice);
                if (Validate::isLoadedObject($order_invoice)) {
                    $id_order = (int)$order_invoice->id_order;
                }
            }
        }

        error_log('[Albaran Admin] ID Order: ' . $id_order);

        if (!$id_order) {
            error_log('[Albaran Admin] No hay id_order');
            return false;
        }

        // Obtener el pedido de PrestaShop
        $order = new Order($id_order);
        if (!Validate::isLoadedObject($order)) {
            error_log('[Albaran Admin] Pedido no encontrado en PrestaShop');
            return false;
        }

        $order_reference = $order->reference;
        error_log('[Albaran Admin] Order Reference: ' . $order_reference);

        try {
            // Generar el PDF del albarán usando el sistema de PrestaShop
            // Usamos la clase PDF con el template de factura pero lo llamaremos "Albarán"
            $order_invoice_collection = $order->getInvoicesCollection();

            if ($order_invoice_collection->count() == 0) {
                error_log('[Albaran Admin] El pedido no tiene facturas asociadas');
                $this->showAdminError('El pedido no tiene documentos asociados para generar el albarán.');
                return false;
            }

            // Crear el PDF usando el sistema de PrestaShop
            $pdf = new PDF($order_invoice_collection, PDF::TEMPLATE_INVOICE, Context::getContext()->smarty);

            // Obtener el contenido del PDF
            ob_start();
            $pdf->render(false); // false = no forzar descarga, solo generar contenido
            $pdf_content = ob_get_clean();

            // Si el contenido está vacío, generar directamente
            if (empty($pdf_content)) {
                // Generar el PDF y capturarlo
                $pdf_content = $pdf->render(false);
            }

            // Verificar que tenemos contenido
            if (empty($pdf_content) || strlen($pdf_content) < 100) {
                // Usar método alternativo: generar directamente a string
                $pdf = new PDF($order_invoice_collection, PDF::TEMPLATE_INVOICE, Context::getContext()->smarty);
                $pdf_content = $pdf->render('S'); // 'S' = devolver como string
            }

            // Servir el PDF con feedback visual
            $this->servePDFWithFeedback($pdf_content, 'albaran_' . $order_reference . '.pdf', 'Albarán');
            exit;

        } catch (Exception $e) {
            error_log('[Albaran Admin] Exception: ' . $e->getMessage());
            return false;
        }
    }

    private function servePDFWithFeedback($pdf_content, $filename, $document_type = 'Factura')
    {
        $pdf_base64 = base64_encode($pdf_content);

        $doc_lower = strtolower($document_type);

        echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Descargando ' . htmlspecialchars($doc_lower) . '</title>
    <style>
        body { font-family: Arial, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); margin: 0; padding: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .container { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); max-width: 400px; text-align: center; animation: slideIn 0.4s ease; }
        @keyframes slideIn { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .icon { font-size: 64px; margin-bottom: 20px; animation: pulse 1.5s ease infinite; }
        @keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        h2 { color: #333; margin: 0 0 15px 0; font-size: 24px; }
        p { color: #666; margin: 0 0 25px 0; font-size: 16px; line-height: 1.5; }
        .status { padding: 12px 20px; background: #e8f5e9; color: #2e7d32; border-radius: 6px; margin: 20px 0; font-weight: bold; display: none; }
        .status.show { display: block; }
        .spinner { border: 3px solid #f3f3f3; border-top: 3px solid #667eea; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 20px auto; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        button { background: #667eea; color: white; border: none; padding: 12px 30px; border-radius: 6px; font-size: 16px; cursor: pointer; transition: background 0.3s; display: none; }
        button:hover { background: #5568d3; }
        button.show { display: inline-block; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon" id="icon">📦</div>
        <h2 id="title">Preparando ' . htmlspecialchars($doc_lower) . '</h2>
        <p id="message">Por favor, espera un momento...</p>
        <div class="spinner" id="spinner"></div>
        <div class="status" id="status"></div>
        <button onclick="goBack()" id="backBtn">Volver</button>
    </div>
    <script>
        const pdfData = "' . $pdf_base64 . '";
        const filename = "' . htmlspecialchars($filename) . '";
        const docType = "' . htmlspecialchars($document_type) . '";
        const docLower = "' . htmlspecialchars($doc_lower) . '";
        function base64ToBlob(base64, type = "application/pdf") {
            const binStr = atob(base64);
            const len = binStr.length;
            const arr = new Uint8Array(len);
            for (let i = 0; i < len; i++) { arr[i] = binStr.charCodeAt(i); }
            return new Blob([arr], { type: type });
        }
        function downloadPDF() {
            try {
                const blob = base64ToBlob(pdfData);
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement("a");
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
                showSuccess();
            } catch (error) { showError(); }
        }
        function showSuccess() {
            document.getElementById("icon").textContent = "✅";
            document.getElementById("title").textContent = "¡" + docType + " descargado!";
            document.getElementById("message").textContent = "El " + docLower + " se ha descargado correctamente.";
            document.getElementById("spinner").style.display = "none";
            document.getElementById("status").textContent = "Descarga completada";
            document.getElementById("status").classList.add("show");
            document.getElementById("backBtn").classList.add("show");
            setTimeout(goBack, 3000);
        }
        function showError() {
            document.getElementById("icon").textContent = "⚠️";
            document.getElementById("title").textContent = "Error al descargar";
            document.getElementById("message").textContent = "Ocurrió un error. Por favor, inténtalo de nuevo.";
            document.getElementById("spinner").style.display = "none";
            document.getElementById("backBtn").classList.add("show");
        }
        function goBack() { window.history.back(); }
        setTimeout(downloadPDF, 500);
    </script>
</body>
</html>';
        exit;
    }

    private function showAdminError($message)
    {
        echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Error - Factura no disponible</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
        .overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 9999; }
        .popup { background: white; padding: 30px 40px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-width: 500px; text-align: center; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .popup h2 { color: #e67e22; margin: 0 0 15px 0; font-size: 24px; }
        .popup p { color: #555; margin: 0 0 25px 0; font-size: 16px; line-height: 1.5; }
        .popup button { background: #3498db; color: white; border: none; padding: 12px 30px; border-radius: 5px; font-size: 16px; cursor: pointer; transition: background 0.3s; margin: 0 5px; }
        .popup button:hover { background: #2980b9; }
        .icon { font-size: 48px; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="overlay">
        <div class="popup">
            <div class="icon">⚠️</div>
            <h2>Factura no disponible</h2>
            <p>' . htmlspecialchars($message) . '</p>
            <button onclick="goBack()">Volver</button>
        </div>
    </div>
    <script>
        function goBack() {
            window.history.back();
        }
        setTimeout(goBack, 5000);
    </script>
</body>
</html>';
        exit;
    }
}
