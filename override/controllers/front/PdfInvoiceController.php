<?php
/**
 * Override del controlador de PDFInvoice (Front Controller)
 * Intercepta la generación de PDFs de facturas para usar FacturaScripts
 */

class PdfInvoiceController extends PdfInvoiceControllerCore
{
    public function init()
    {
        error_log('[FSInvoices] PdfInvoiceController::init() llamado');

        // Intentar generar factura desde FacturaScripts
        if ($this->generateFSInvoice()) {
            error_log('[FSInvoices] Redirigido a FacturaScripts desde init()');
            return;
        }

        parent::init();
    }

    protected function processGenerateInvoicePDF()
    {
        error_log('[FSInvoices] PdfInvoiceController::processGenerateInvoicePDF() llamado');

        // Intentar generar factura desde FacturaScripts
        if ($this->generateFSInvoice()) {
            error_log('[FSInvoices] Redirigido a FacturaScripts desde processGenerateInvoicePDF()');
            return;
        }

        error_log('[FSInvoices] No se redirigió, usando PrestaShop original');
        parent::processGenerateInvoicePDF();
    }

    private function generateFSInvoice()
    {
        error_log('[FSInvoices] generateFSInvoice() ejecutándose...');

        // Verificar que el módulo esté activo y configurado
        if (!Module::isInstalled('fsinvoices') || !Module::isEnabled('fsinvoices')) {
            error_log('[FSInvoices] Módulo no instalado o no activado');
            return false;
        }

        // Obtener configuración
        $fs_host = Configuration::get('FSINVOICES_DB_HOST');
        $fs_name = Configuration::get('FSINVOICES_DB_NAME');
        $fs_user = Configuration::get('FSINVOICES_DB_USER');
        $fs_pass = Configuration::get('FSINVOICES_DB_PASS');
        $fs_url = Configuration::get('FSINVOICES_URL');
        $fs_prefix = Configuration::get('FSINVOICES_TABLE_PREFIX');

        error_log('[FSInvoices] Config - Host: ' . $fs_host . ', DB: ' . $fs_name . ', URL: ' . $fs_url);

        if (empty($fs_host) || empty($fs_name) || empty($fs_user) || empty($fs_url)) {
            error_log('[FSInvoices] Configuración incompleta');
            return false;
        }

        // Obtener ID del pedido
        $id_order = (int)Tools::getValue('id_order');
        error_log('[FSInvoices] ID Order: ' . $id_order);

        if (!$id_order) {
            error_log('[FSInvoices] No hay id_order');
            return false;
        }

        try {
            // Conectar a FacturaScripts
            $fs_conn = new mysqli($fs_host, $fs_user, $fs_pass, $fs_name);

            if ($fs_conn->connect_error) {
                error_log('[FSInvoices] Error conexión BD: ' . $fs_conn->connect_error);
                return false;
            }

            $fs_conn->set_charset('utf8');

            // Buscar el idalbaran en ps_orders
            $table_ps_orders = $fs_prefix . 'ps_orders';
            $query = "SELECT idalbaran FROM `{$table_ps_orders}` WHERE id = " . (int)$id_order;
            error_log('[FSInvoices] Query ps_orders: ' . $query);

            $result = $fs_conn->query($query);

            if (!$result || $result->num_rows == 0) {
                error_log('[FSInvoices] No se encontró el pedido en ps_orders');
                $fs_conn->close();
                return false;
            }

            $row = $result->fetch_assoc();
            $idalbaran = (int)$row['idalbaran'];
            error_log('[FSInvoices] ID Albaran: ' . $idalbaran);

            if (!$idalbaran) {
                error_log('[FSInvoices] idalbaran es 0');
                $fs_conn->close();
                return false;
            }

            // Buscar la factura asociada al albarán
            $table_facturas = $fs_prefix . 'facturascli';
            $table_albaranes = $fs_prefix . 'albaranescli';

            $query = "SELECT f.idfactura
                      FROM `{$table_albaranes}` a
                      INNER JOIN `{$table_facturas}` f ON a.idfactura = f.idfactura
                      WHERE a.idalbaran = " . (int)$idalbaran;

            error_log('[FSInvoices] Query factura: ' . $query);
            $result = $fs_conn->query($query);

            if (!$result || $result->num_rows == 0) {
                error_log('[FSInvoices] No se encontró factura para el albarán');
                $fs_conn->close();
                return false;
            }

            $row = $result->fetch_assoc();
            $idfactura = (int)$row['idfactura'];
            error_log('[FSInvoices] ID Factura: ' . $idfactura);

            $fs_conn->close();

            if (!$idfactura) {
                error_log('[FSInvoices] idfactura es 0');
                return false;
            }

            // Redirigir a FacturaScripts para servir el PDF
            $pdf_url = rtrim($fs_url, '/') . '/index.php?page=plantillas_pdf&factura=TRUE&id=' . $idfactura;
            error_log('[FSInvoices] Redirigiendo a: ' . $pdf_url);

            // Redirigir al PDF de FacturaScripts
            header('Location: ' . $pdf_url);
            exit;

        } catch (Exception $e) {
            error_log('[FSInvoices] Exception: ' . $e->getMessage());
            return false;
        }
    }
}
