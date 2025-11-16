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
                die('Error: El pedido no se encuentra en FacturaScripts. Por favor, verifique que el pedido haya sido importado correctamente.');
            }

            $row = $result->fetch_assoc();
            $idalbaran = (int)$row['idalbaran'];
            error_log('[FSInvoices Admin] ID Albaran: ' . $idalbaran);

            if (!$idalbaran) {
                error_log('[FSInvoices Admin] idalbaran es 0');
                $fs_conn->close();
                die('Error: El pedido no tiene albarán asociado en FacturaScripts. Por favor, genere primero el albarán.');
            }

            // Buscar la factura asociada al albarán
            $table_facturas = $fs_prefix . 'facturascli';
            $table_albaranes = $fs_prefix . 'albaranescli';

            $query = "SELECT f.idfactura
                      FROM `{$table_albaranes}` a
                      INNER JOIN `{$table_facturas}` f ON a.idfactura = f.idfactura
                      WHERE a.idalbaran = " . (int)$idalbaran;

            error_log('[FSInvoices Admin] Query factura: ' . $query);
            $result = $fs_conn->query($query);

            if (!$result || $result->num_rows == 0) {
                error_log('[FSInvoices Admin] No se encontró factura para el albarán');
                $fs_conn->close();
                die('Error: El albarán no tiene factura asociada en FacturaScripts. Por favor, genere primero la factura desde el albarán.');
            }

            $row = $result->fetch_assoc();
            $idfactura = (int)$row['idfactura'];
            error_log('[FSInvoices Admin] ID Factura: ' . $idfactura);

            $fs_conn->close();

            if (!$idfactura) {
                error_log('[FSInvoices Admin] idfactura es 0');
                return false;
            }

            // Descargar el PDF con autenticación
            $pdf_content = $this->downloadAuthenticatedPDF($fs_url, $idfactura, $fs_web_user, $fs_web_pass);

            if ($pdf_content === false) {
                error_log('[FSInvoices Admin] Error al descargar el PDF desde FacturaScripts');
                die('Error: No se pudo descargar la factura desde FacturaScripts. Por favor, verifique las credenciales de acceso y que la factura esté generada correctamente.');
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
}
