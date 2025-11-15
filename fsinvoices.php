<?php
/**
 * Module: FacturaScripts Invoices
 * Description: Muestra las facturas de FacturaScripts en lugar de las de PrestaShop
 * Author: Jose Guzman
 * Version: 1.0.0
 * Compatible: PrestaShop 1.6 - 1.7 - 1.8 - 9
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FSInvoices extends Module
{
    public function __construct()
    {
        $this->name = 'fsinvoices';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Jose Guzman';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('FacturaScripts Invoices');
        $this->description = $this->l('Sustituye las facturas de PrestaShop por las de FacturaScripts');
        $this->confirmUninstall = $this->l('�Est� seguro de que desea desinstalar este m�dulo?');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('actionPDFInvoiceRender')
            && $this->registerHook('displayPDFInvoice');
    }

    public function uninstall()
    {
        return Configuration::deleteByName('FSINVOICES_DB_HOST')
            && Configuration::deleteByName('FSINVOICES_DB_NAME')
            && Configuration::deleteByName('FSINVOICES_DB_USER')
            && Configuration::deleteByName('FSINVOICES_DB_PASS')
            && Configuration::deleteByName('FSINVOICES_PATH')
            && Configuration::deleteByName('FSINVOICES_TABLE_PREFIX')
            && Configuration::deleteByName('FSINVOICES_URL')
            && parent::uninstall();
    }

    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit'.$this->name)) {
            $fs_host = strval(Tools::getValue('FSINVOICES_DB_HOST'));
            $fs_name = strval(Tools::getValue('FSINVOICES_DB_NAME'));
            $fs_user = strval(Tools::getValue('FSINVOICES_DB_USER'));
            $fs_pass = strval(Tools::getValue('FSINVOICES_DB_PASS'));
            $fs_path = strval(Tools::getValue('FSINVOICES_PATH'));
            $fs_prefix = strval(Tools::getValue('FSINVOICES_TABLE_PREFIX'));
            $fs_url = strval(Tools::getValue('FSINVOICES_URL'));

            Configuration::updateValue('FSINVOICES_DB_HOST', $fs_host);
            Configuration::updateValue('FSINVOICES_DB_NAME', $fs_name);
            Configuration::updateValue('FSINVOICES_DB_USER', $fs_user);
            Configuration::updateValue('FSINVOICES_DB_PASS', $fs_pass);
            Configuration::updateValue('FSINVOICES_PATH', $fs_path);
            Configuration::updateValue('FSINVOICES_TABLE_PREFIX', $fs_prefix);
            Configuration::updateValue('FSINVOICES_URL', $fs_url);

            $output .= $this->displayConfirmation($this->l('Configuraci�n actualizada correctamente'));
            
            // Probar conexi�n
            if ($this->testFSConnection()) {
                $output .= $this->displayConfirmation($this->l('Conexi�n con FacturaScripts: OK'));
            } else {
                $output .= $this->displayError($this->l('No se pudo conectar a FacturaScripts. Verifique la configuraci�n.'));
            }
        }

        return $output.$this->displayForm();
    }

    public function displayForm()
    {
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('Configuraci�n de FacturaScripts'),
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('Host de Base de Datos'),
                    'name' => 'FSINVOICES_DB_HOST',
                    'size' => 40,
                    'required' => true,
                    'desc' => $this->l('Normalmente: localhost')
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Nombre de Base de Datos'),
                    'name' => 'FSINVOICES_DB_NAME',
                    'size' => 40,
                    'required' => true,
                    'desc' => $this->l('Nombre de la base de datos de FacturaScripts')
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Usuario de Base de Datos'),
                    'name' => 'FSINVOICES_DB_USER',
                    'size' => 40,
                    'required' => true
                ),
                array(
                    'type' => 'password',
                    'label' => $this->l('Contrase�a de Base de Datos'),
                    'name' => 'FSINVOICES_DB_PASS',
                    'size' => 40,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Ruta f�sica de FacturaScripts'),
                    'name' => 'FSINVOICES_PATH',
                    'size' => 80,
                    'required' => true,
                    'desc' => $this->l('Ejemplo: /var/www/facturascripts/')
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Prefijo de tablas (opcional)'),
                    'name' => 'FSINVOICES_TABLE_PREFIX',
                    'size' => 20,
                    'required' => false,
                    'desc' => $this->l('Dejar vac�o si no usa prefijo')
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('URL de FacturaScripts'),
                    'name' => 'FSINVOICES_URL',
                    'size' => 80,
                    'required' => true,
                    'desc' => $this->l('Ejemplo: http://localhost/facturascripts o https://midominio.com/facturascripts')
                ),
            ),
            'submit' => array(
                'title' => $this->l('Guardar'),
                'class' => 'btn btn-default pull-right'
            )
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submit'.$this->name;
        $helper->toolbar_btn = array(
            'save' => array(
                'desc' => $this->l('Guardar'),
                'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
                '&token='.Tools::getAdminTokenLite('AdminModules'),
            ),
            'back' => array(
                'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Volver')
            )
        );

        $helper->fields_value['FSINVOICES_DB_HOST'] = Configuration::get('FSINVOICES_DB_HOST');
        $helper->fields_value['FSINVOICES_DB_NAME'] = Configuration::get('FSINVOICES_DB_NAME');
        $helper->fields_value['FSINVOICES_DB_USER'] = Configuration::get('FSINVOICES_DB_USER');
        $helper->fields_value['FSINVOICES_DB_PASS'] = Configuration::get('FSINVOICES_DB_PASS');
        $helper->fields_value['FSINVOICES_PATH'] = Configuration::get('FSINVOICES_PATH');
        $helper->fields_value['FSINVOICES_TABLE_PREFIX'] = Configuration::get('FSINVOICES_TABLE_PREFIX');
        $helper->fields_value['FSINVOICES_URL'] = Configuration::get('FSINVOICES_URL');

        return $helper->generateForm($fields_form);
    }

    private function testFSConnection()
    {
        try {
            $fs_host = Configuration::get('FSINVOICES_DB_HOST');
            $fs_name = Configuration::get('FSINVOICES_DB_NAME');
            $fs_user = Configuration::get('FSINVOICES_DB_USER');
            $fs_pass = Configuration::get('FSINVOICES_DB_PASS');

            $conn = new mysqli($fs_host, $fs_user, $fs_pass, $fs_name);

            if ($conn->connect_error) {
                return false;
            }

            $conn->close();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Hook que se ejecuta cuando se genera un PDF de factura
     */
    public function hookActionPDFInvoiceRender($params)
    {
        error_log('[FSInvoices] hookActionPDFInvoiceRender ejecutado');

        if (isset($params['order'])) {
            $id_order = (int)$params['order']->id;
            error_log('[FSInvoices] Hook - ID Order: ' . $id_order);

            if ($this->redirectToFSInvoice($id_order)) {
                error_log('[FSInvoices] Redirigido desde hook');
                exit;
            }
        }
    }

    /**
     * Hook alternativo para interceptar la generación de PDFs
     */
    public function hookDisplayPDFInvoice($params)
    {
        error_log('[FSInvoices] hookDisplayPDFInvoice ejecutado');
        error_log('[FSInvoices] Params: ' . print_r($params, true));

        if (isset($params['object'])) {
            error_log('[FSInvoices] Object class: ' . get_class($params['object']));
            error_log('[FSInvoices] Object: ' . print_r($params['object'], true));

            // Intentar obtener el id_order correcto
            $id_order = null;

            // Si es OrderInvoice, tiene id_order
            if (isset($params['object']->id_order)) {
                $id_order = (int)$params['object']->id_order;
                error_log('[FSInvoices] ID Order desde id_order: ' . $id_order);
            }
            // Si es Order directamente
            elseif (isset($params['object']->id) && get_class($params['object']) == 'Order') {
                $id_order = (int)$params['object']->id;
                error_log('[FSInvoices] ID Order desde Order->id: ' . $id_order);
            }
            // Fallback: usar id genérico
            elseif (isset($params['object']->id)) {
                $id_order = (int)$params['object']->id;
                error_log('[FSInvoices] ID Order desde id genérico: ' . $id_order);
            }

            if ($id_order && $this->redirectToFSInvoice($id_order)) {
                error_log('[FSInvoices] Redirigido desde hook display');
                exit;
            }
        }
    }

    /**
     * Redirige a la factura de FacturaScripts
     */
    private function redirectToFSInvoice($id_order)
    {
        error_log('[FSInvoices] redirectToFSInvoice - ID Order: ' . $id_order);

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
            return true;

        } catch (Exception $e) {
            error_log('[FSInvoices] Exception: ' . $e->getMessage());
            return false;
        }
    }
}