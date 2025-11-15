<?php
/**
 * Override del controlador de PDFInvoice (Front Controller)
 * Intercepta la generación de PDFs de facturas para usar FacturaScripts
 */

class PdfInvoiceController extends PdfInvoiceControllerCore
{
    protected function processGenerateInvoicePDF()
    {
        // Intentar generar factura desde FacturaScripts
        if ($this->generateFSInvoice()) {
            return; // Ya se ha redirigido a FacturaScripts
        }

        // Si no hay factura de FacturaScripts, usar el método original
        parent::processGenerateInvoicePDF();
    }

    private function generateFSInvoice()
    {
        // Verificar que el módulo esté activo y configurado
        if (!Module::isInstalled('fsinvoices') || !Module::isEnabled('fsinvoices')) {
            return false;
        }

        // Obtener configuración
        $fs_host = Configuration::get('FSINVOICES_DB_HOST');
        $fs_name = Configuration::get('FSINVOICES_DB_NAME');
        $fs_user = Configuration::get('FSINVOICES_DB_USER');
        $fs_pass = Configuration::get('FSINVOICES_DB_PASS');
        $fs_url = Configuration::get('FSINVOICES_URL');
        $fs_prefix = Configuration::get('FSINVOICES_TABLE_PREFIX');

        if (empty($fs_host) || empty($fs_name) || empty($fs_user) || empty($fs_url)) {
            return false;
        }

        // Obtener ID del pedido
        $id_order = (int)Tools::getValue('id_order');
        if (!$id_order) {
            return false;
        }

        try {
            // Conectar a FacturaScripts
            $fs_conn = new mysqli($fs_host, $fs_user, $fs_pass, $fs_name);

            if ($fs_conn->connect_error) {
                return false;
            }

            $fs_conn->set_charset('utf8');

            // Buscar el idalbaran en ps_orders
            $table_ps_orders = $fs_prefix . 'ps_orders';
            $query = "SELECT idalbaran FROM `{$table_ps_orders}` WHERE id = " . (int)$id_order;
            $result = $fs_conn->query($query);

            if (!$result || $result->num_rows == 0) {
                $fs_conn->close();
                return false;
            }

            $row = $result->fetch_assoc();
            $idalbaran = (int)$row['idalbaran'];

            if (!$idalbaran) {
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

            $result = $fs_conn->query($query);

            if (!$result || $result->num_rows == 0) {
                $fs_conn->close();
                return false;
            }

            $row = $result->fetch_assoc();
            $idfactura = (int)$row['idfactura'];

            $fs_conn->close();

            if (!$idfactura) {
                return false;
            }

            // Redirigir a FacturaScripts para servir el PDF
            $pdf_url = rtrim($fs_url, '/') . '/index.php?page=plantillas_pdf&factura=TRUE&id=' . $idfactura;

            // Redirigir al PDF de FacturaScripts
            header('Location: ' . $pdf_url);
            exit;

        } catch (Exception $e) {
            return false;
        }
    }
}
