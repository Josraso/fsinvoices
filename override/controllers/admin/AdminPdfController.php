<?php
/**
 * Override del controlador Admin de PDFs
 * Intercepta la generación de PDFs de facturas en el backoffice
 */

class AdminPdfController extends AdminPdfControllerCore
{
    public function postProcess()
    {
        // Solo interceptamos si es una factura
        if (Tools::getValue('submitAction') == 'generateInvoicePDF') {
            if ($this->generateFSInvoice()) {
                return; // Ya se ha servido el PDF de FacturaScripts
            }
        }

        parent::postProcess();
    }

    private function generateFSInvoice()
    {
        error_log('[FSInvoices Admin] generateFSInvoice ejecutándose...');

        // Verificar que el módulo esté activo
        if (!Module::isInstalled('fsinvoices') || !Module::isEnabled('fsinvoices')) {
            error_log('[FSInvoices Admin] Módulo no instalado o no activado');
            return false;
        }

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

        error_log('[FSInvoices Admin] ID Order: ' . $id_order);

        if (!$id_order) {
            error_log('[FSInvoices Admin] No hay id_order');
            return false;
        }

        // Obtener la referencia del pedido en PrestaShop
        $order = new Order($id_order);
        if (!Validate::isLoadedObject($order)) {
            error_log('[FSInvoices Admin] Pedido no encontrado en PrestaShop');
            return false;
        }

        $order_reference = $order->reference;
        error_log('[FSInvoices Admin] Order Reference: ' . $order_reference);

        // Obtener configuración
        $fs_host = Configuration::get('FSINVOICES_DB_HOST');
        $fs_name = Configuration::get('FSINVOICES_DB_NAME');
        $fs_user = Configuration::get('FSINVOICES_DB_USER');
        $fs_pass = Configuration::get('FSINVOICES_DB_PASS');
        $fs_url = Configuration::get('FSINVOICES_URL');
        $fs_prefix = Configuration::get('FSINVOICES_TABLE_PREFIX');
        $fs_web_user = Configuration::get('FSINVOICES_FS_USER');
        $fs_web_pass = Configuration::get('FSINVOICES_FS_PASS');

        error_log('[FSInvoices Admin] Config - Host: ' . $fs_host . ', DB: ' . $fs_name . ', URL: ' . $fs_url);

        if (empty($fs_host) || empty($fs_name) || empty($fs_user) || empty($fs_url)) {
            error_log('[FSInvoices Admin] Configuración incompleta');
            return false;
        }

        if (empty($fs_web_user) || empty($fs_web_pass)) {
            error_log('[FSInvoices Admin] Faltan credenciales de FacturaScripts');
            return false;
        }

        try {
            // Conectar a FacturaScripts
            $fs_conn = new mysqli($fs_host, $fs_user, $fs_pass, $fs_name);

            if ($fs_conn->connect_error) {
                error_log('[FSInvoices Admin] Error conexión BD: ' . $fs_conn->connect_error);
                return false;
            }

            $fs_conn->set_charset('utf8');

            // Buscar el idalbaran en ps_orders usando la REFERENCIA
            $table_ps_orders = $fs_prefix . 'ps_orders';
            $query = "SELECT idalbaran FROM `{$table_ps_orders}` WHERE referencia = '" . $fs_conn->real_escape_string($order_reference) . "'";
            error_log('[FSInvoices Admin] Query ps_orders: ' . $query);

            $result = $fs_conn->query($query);

            if (!$result || $result->num_rows == 0) {
                error_log('[FSInvoices Admin] No se encontró el pedido en ps_orders');
                $fs_conn->close();
                $this->showAdminError('El pedido no se encuentra en FacturaScripts. Verifique que el pedido esté importado correctamente.');
                return false;
            }

            $row = $result->fetch_assoc();
            $idalbaran = (int)$row['idalbaran'];
            error_log('[FSInvoices Admin] ID Albaran: ' . $idalbaran);

            if (!$idalbaran) {
                error_log('[FSInvoices Admin] idalbaran es 0');
                $fs_conn->close();
                $this->showAdminError('El pedido no tiene albarán asociado en FacturaScripts. Genere primero el albarán.');
                return false;
            }

            // Buscar la factura asociada al albarán
            $table_facturas = $fs_prefix . 'facturascli';
            $table_albaranes = $fs_prefix . 'albaranescli';

            $query = "SELECT f.idfactura, f.codigo
                      FROM `{$table_albaranes}` a
                      INNER JOIN `{$table_facturas}` f ON a.idfactura = f.idfactura
                      WHERE a.idalbaran = " . (int)$idalbaran;

            error_log('[FSInvoices Admin] Query factura: ' . $query);
            $result = $fs_conn->query($query);

            if (!$result || $result->num_rows == 0) {
                error_log('[FSInvoices Admin] No se encontró factura para el albarán');
                $fs_conn->close();
                $this->showAdminError('El albarán no tiene factura asociada. Genere primero la factura desde el albarán en FacturaScripts.');
                return false;
            }

            $row = $result->fetch_assoc();
            $idfactura = (int)$row['idfactura'];
            $codigo_factura = $row['codigo'];
            error_log('[FSInvoices Admin] ID Factura: ' . $idfactura . ', Código: ' . $codigo_factura);

            $fs_conn->close();

            if (!$idfactura) {
                error_log('[FSInvoices Admin] idfactura es 0');
                return false;
            }

            // Descargar el PDF con autenticación
            $pdf_content = $this->downloadAuthenticatedPDF($fs_url, $idfactura, $fs_web_user, $fs_web_pass);

            if ($pdf_content === false) {
                error_log('[FSInvoices Admin] Error al descargar el PDF desde FacturaScripts');
                $this->showAdminError('No se pudo descargar la factura desde FacturaScripts. Verifique las credenciales de acceso.');
                return false;
            }

            // Servir el PDF con página de descarga visual usando el código de FacturaScripts
            $this->servePDFWithFeedback($pdf_content, 'factura_' . $codigo_factura . '.pdf');
            exit;

        } catch (Exception $e) {
            error_log('[FSInvoices Admin] Exception: ' . $e->getMessage());
            return false;
        }
    }

    private function downloadAuthenticatedPDF($fs_url, $idfactura, $username, $password)
    {
        $base_url = rtrim($fs_url, '/');

        // Inicializar cURL con manejo de cookies
        $cookie_file = tempnam(sys_get_temp_dir(), 'fs_cookie_');

        // Paso 1: Hacer login en FacturaScripts 2019
        // FacturaScripts 2019 usa los campos: user y password
        $login_url = $base_url . '/index.php';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $login_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'user' => $username,
            'password' => $password
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        error_log('[FSInvoices Admin] Haciendo login en FacturaScripts con usuario: ' . $username);
        $login_response = curl_exec($ch);
        $login_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        error_log('[FSInvoices Admin] Login HTTP Code: ' . $login_http_code);
        error_log('[FSInvoices Admin] Login response (primeros 500 chars): ' . substr($login_response, 0, 500));

        if ($login_response === false) {
            error_log('[FSInvoices Admin] Error en login cURL: ' . curl_error($ch));
            curl_close($ch);
            @unlink($cookie_file);
            return false;
        }

        // Paso 2: Descargar el PDF usando la sesión autenticada
        $pdf_url = $base_url . '/index.php?page=plantillas_pdf&factura=TRUE&id=' . $idfactura;
        error_log('[FSInvoices Admin] Descargando PDF desde: ' . $pdf_url);

        curl_setopt($ch, CURLOPT_URL, $pdf_url);
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_HTTPGET, true);

        $pdf_content = curl_exec($ch);
        $pdf_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        error_log('[FSInvoices Admin] PDF HTTP Code: ' . $pdf_http_code);
        error_log('[FSInvoices Admin] PDF Content-Type: ' . $content_type);
        error_log('[FSInvoices Admin] PDF Size: ' . strlen($pdf_content) . ' bytes');

        curl_close($ch);
        @unlink($cookie_file);

        // Verificar que sea un PDF válido
        if ($pdf_content === false || $pdf_http_code != 200) {
            error_log('[FSInvoices Admin] Error al descargar PDF');
            return false;
        }

        // Verificar que el contenido sea PDF (empieza con %PDF)
        if (substr($pdf_content, 0, 4) !== '%PDF') {
            error_log('[FSInvoices Admin] El contenido descargado no es un PDF válido');
            error_log('[FSInvoices Admin] Primeros 500 caracteres: ' . substr($pdf_content, 0, 500));
            return false;
        }

        return $pdf_content;
    }

    private function servePDFWithFeedback($pdf_content, $filename)
    {
        $pdf_base64 = base64_encode($pdf_content);

        echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Descargando factura</title>
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
        <div class="icon" id="icon">📄</div>
        <h2 id="title">Preparando factura</h2>
        <p id="message">Por favor, espera un momento...</p>
        <div class="spinner" id="spinner"></div>
        <div class="status" id="status"></div>
        <button onclick="goBack()" id="backBtn">Volver</button>
    </div>
    <script>
        const pdfData = "' . $pdf_base64 . '";
        const filename = "' . htmlspecialchars($filename) . '";
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
            document.getElementById("title").textContent = "¡Factura descargada!";
            document.getElementById("message").textContent = "La factura se ha descargado correctamente.";
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
