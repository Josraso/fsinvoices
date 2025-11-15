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
                return; // Ya se ha generado y servido
            }
        }

        parent::postProcess();
    }

    private function generateFSInvoice()
    {
        // Verificar que el módulo esté activo
        if (!Module::isInstalled('fsinvoices') || !Module::isEnabled('fsinvoices')) {
            return false;
        }

        // Obtener configuración
        $fs_host = Configuration::get('FSINVOICES_DB_HOST');
        $fs_name = Configuration::get('FSINVOICES_DB_NAME');
        $fs_user = Configuration::get('FSINVOICES_DB_USER');
        $fs_pass = Configuration::get('FSINVOICES_DB_PASS');
        $fs_path = Configuration::get('FSINVOICES_PATH');
        $fs_prefix = Configuration::get('FSINVOICES_TABLE_PREFIX');

        if (empty($fs_host) || empty($fs_name) || empty($fs_user) || empty($fs_path)) {
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
            $query = "SELECT idalbaran FROM `{$table_ps_orders}` WHERE id = {$id_order}";
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
                      WHERE a.idalbaran = {$idalbaran}";
            
            $result = $fs_conn->query($query);

            if (!$result || $result->num_rows == 0) {
                $fs_conn->close();
                return false;
            }

            $row = $result->fetch_assoc();
            $idfactura = (int)$row['idfactura'];

            $fs_conn->close();

            // Cargar el generador de PDF
            require_once(_PS_MODULE_DIR_.'fsinvoices/classes/FSInvoiceGenerator.php');
            
            $generator = new FSInvoiceGenerator($fs_host, $fs_user, $fs_pass, $fs_name, $fs_path, $fs_prefix);
            $generator->generateAndServePDF($idfactura);

            return true;

        } catch (Exception $e) {
            return false;
        }
    }
}
