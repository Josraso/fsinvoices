<?php
/**
 * Override del controlador de PDFInvoice (Front Controller)
 * Intercepta la generación de PDFs de facturas para usar FacturaScripts
 */

class PdfInvoiceController extends PdfInvoiceControllerCore
{
    public function init()
    {
        error_log('[FSInvoices Front] PdfInvoiceController::init() llamado');

        // Intentar generar factura desde FacturaScripts
        if ($this->generateFSInvoice()) {
            error_log('[FSInvoices Front] PDF servido desde init()');
            return;
        }

        parent::init();
    }

    protected function processGenerateInvoicePDF()
    {
        error_log('[FSInvoices Front] PdfInvoiceController::processGenerateInvoicePDF() llamado');

        // Intentar generar factura desde FacturaScripts
        if ($this->generateFSInvoice()) {
            error_log('[FSInvoices Front] PDF servido desde processGenerateInvoicePDF()');
            return;
        }

        error_log('[FSInvoices Front] No se sirvió PDF de FS, usando PrestaShop original');
        parent::processGenerateInvoicePDF();
    }

    private function generateFSInvoice()
    {
        error_log('[FSInvoices Front] generateFSInvoice() ejecutándose...');

        // Verificar que el módulo esté activo y configurado
        if (!Module::isInstalled('fsinvoices') || !Module::isEnabled('fsinvoices')) {
            error_log('[FSInvoices Front] Módulo no instalado o no activado');
            return false;
        }

        // Obtener ID del pedido
        $id_order = (int)Tools::getValue('id_order');
        error_log('[FSInvoices Front] ID Order: ' . $id_order);

        if (!$id_order) {
            error_log('[FSInvoices Front] No hay id_order');
            return false;
        }

        // Obtener la referencia del pedido en PrestaShop
        $order = new Order($id_order);
        if (!Validate::isLoadedObject($order)) {
            error_log('[FSInvoices Front] Pedido no encontrado en PrestaShop');
            return false;
        }

        $order_reference = $order->reference;
        error_log('[FSInvoices Front] Order Reference: ' . $order_reference);

        // Obtener configuración
        $fs_host = Configuration::get('FSINVOICES_DB_HOST');
        $fs_name = Configuration::get('FSINVOICES_DB_NAME');
        $fs_user = Configuration::get('FSINVOICES_DB_USER');
        $fs_pass = Configuration::get('FSINVOICES_DB_PASS');
        $fs_url = Configuration::get('FSINVOICES_URL');
        $fs_prefix = Configuration::get('FSINVOICES_TABLE_PREFIX');
        $fs_web_user = Configuration::get('FSINVOICES_FS_USER');
        $fs_web_pass = Configuration::get('FSINVOICES_FS_PASS');

        error_log('[FSInvoices Front] Config - Host: ' . $fs_host . ', DB: ' . $fs_name . ', URL: ' . $fs_url);

        if (empty($fs_host) || empty($fs_name) || empty($fs_user) || empty($fs_url)) {
            error_log('[FSInvoices Front] Configuración incompleta');
            return false;
        }

        if (empty($fs_web_user) || empty($fs_web_pass)) {
            error_log('[FSInvoices Front] Faltan credenciales de FacturaScripts');
            return false;
        }

        try {
            // Conectar a FacturaScripts
            $fs_conn = new mysqli($fs_host, $fs_user, $fs_pass, $fs_name);

            if ($fs_conn->connect_error) {
                error_log('[FSInvoices Front] Error conexión BD: ' . $fs_conn->connect_error);
                return false;
            }

            $fs_conn->set_charset('utf8');

            // Buscar el idalbaran en ps_orders usando la REFERENCIA
            $table_ps_orders = $fs_prefix . 'ps_orders';
            $query = "SELECT idalbaran FROM `{$table_ps_orders}` WHERE referencia = '" . $fs_conn->real_escape_string($order_reference) . "'";
            error_log('[FSInvoices Front] Query ps_orders: ' . $query);

            $result = $fs_conn->query($query);

            if (!$result || $result->num_rows == 0) {
                error_log('[FSInvoices Front] No se encontró el pedido en ps_orders');
                $fs_conn->close();
                $this->showErrorPopup('Lo sentimos, esta factura aún no está disponible. Por favor, inténtelo más tarde.');
                return false;
            }

            $row = $result->fetch_assoc();
            $idalbaran = (int)$row['idalbaran'];
            error_log('[FSInvoices Front] ID Albaran: ' . $idalbaran);

            if (!$idalbaran) {
                error_log('[FSInvoices Front] idalbaran es 0');
                $fs_conn->close();
                $this->showErrorPopup('Lo sentimos, esta factura aún no está disponible. Por favor, inténtelo más tarde.');
                return false;
            }

            // Buscar la factura asociada al albarán
            $table_facturas = $fs_prefix . 'facturascli';
            $table_albaranes = $fs_prefix . 'albaranescli';

            $query = "SELECT f.idfactura
                      FROM `{$table_albaranes}` a
                      INNER JOIN `{$table_facturas}` f ON a.idfactura = f.idfactura
                      WHERE a.idalbaran = " . (int)$idalbaran;

            error_log('[FSInvoices Front] Query factura: ' . $query);
            $result = $fs_conn->query($query);

            if (!$result || $result->num_rows == 0) {
                error_log('[FSInvoices Front] No se encontró factura para el albarán');
                $fs_conn->close();
                $this->showErrorPopup('Lo sentimos, esta factura aún no está disponible. Por favor, inténtelo más tarde.');
                return false;
            }

            $row = $result->fetch_assoc();
            $idfactura = (int)$row['idfactura'];
            error_log('[FSInvoices Front] ID Factura: ' . $idfactura);

            $fs_conn->close();

            if (!$idfactura) {
                error_log('[FSInvoices Front] idfactura es 0');
                return false;
            }

            // Descargar el PDF con autenticación
            $pdf_content = $this->downloadAuthenticatedPDF($fs_url, $idfactura, $fs_web_user, $fs_web_pass);

            if ($pdf_content === false) {
                error_log('[FSInvoices Front] Error al descargar el PDF desde FacturaScripts');
                $this->showErrorPopup('Lo sentimos, no se pudo obtener la factura en este momento. Por favor, inténtelo más tarde.');
                return false;
            }

            // Servir el PDF directamente sin mostrar la URL
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="factura_' . $order_reference . '.pdf"');
            header('Content-Length: ' . strlen($pdf_content));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');

            echo $pdf_content;
            exit;

        } catch (Exception $e) {
            error_log('[FSInvoices Front] Exception: ' . $e->getMessage());
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

        error_log('[FSInvoices Front] Haciendo login en FacturaScripts con usuario: ' . $username);
        $login_response = curl_exec($ch);
        $login_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        error_log('[FSInvoices Front] Login HTTP Code: ' . $login_http_code);
        error_log('[FSInvoices Front] Login response (primeros 500 chars): ' . substr($login_response, 0, 500));

        if ($login_response === false) {
            error_log('[FSInvoices Front] Error en login cURL: ' . curl_error($ch));
            curl_close($ch);
            @unlink($cookie_file);
            return false;
        }

        // Paso 2: Descargar el PDF usando la sesión autenticada
        $pdf_url = $base_url . '/index.php?page=plantillas_pdf&factura=TRUE&id=' . $idfactura;
        error_log('[FSInvoices Front] Descargando PDF desde: ' . $pdf_url);

        curl_setopt($ch, CURLOPT_URL, $pdf_url);
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_HTTPGET, true);

        $pdf_content = curl_exec($ch);
        $pdf_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        error_log('[FSInvoices Front] PDF HTTP Code: ' . $pdf_http_code);
        error_log('[FSInvoices Front] PDF Content-Type: ' . $content_type);
        error_log('[FSInvoices Front] PDF Size: ' . strlen($pdf_content) . ' bytes');

        curl_close($ch);
        @unlink($cookie_file);

        // Verificar que sea un PDF válido
        if ($pdf_content === false || $pdf_http_code != 200) {
            error_log('[FSInvoices Front] Error al descargar PDF');
            return false;
        }

        // Verificar que el contenido sea PDF (empieza con %PDF)
        if (substr($pdf_content, 0, 4) !== '%PDF') {
            error_log('[FSInvoices Front] El contenido descargado no es un PDF válido');
            error_log('[FSInvoices Front] Primeros 500 caracteres: ' . substr($pdf_content, 0, 500));
            return false;
        }

        return $pdf_content;
    }

    private function showErrorPopup($message)
    {
        echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Factura no disponible</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
        .overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 9999; }
        .popup { background: white; padding: 30px 40px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-width: 500px; text-align: center; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .popup h2 { color: #e74c3c; margin: 0 0 15px 0; font-size: 24px; }
        .popup p { color: #555; margin: 0 0 25px 0; font-size: 16px; line-height: 1.5; }
        .popup button { background: #3498db; color: white; border: none; padding: 12px 30px; border-radius: 5px; font-size: 16px; cursor: pointer; transition: background 0.3s; }
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
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.close();
            }
        }
        setTimeout(goBack, 5000);
    </script>
</body>
</html>';
        exit;
    }
}
