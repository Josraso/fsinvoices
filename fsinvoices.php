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
        // Instalar overrides
        if (!$this->installOverrides()) {
            return false;
        }

        return parent::install()
            && $this->registerHook('actionPDFInvoiceRender')
            && $this->registerHook('displayPDFInvoice');
    }

    private function installOverrides()
    {
        try {
            // Copiar overrides de admin
            $source_admin = dirname(__FILE__) . '/override/controllers/admin/AdminPdfController.php';
            $dest_admin = _PS_ROOT_DIR_ . '/override/controllers/admin/AdminPdfController.php';

            if (file_exists($source_admin)) {
                if (!is_dir(dirname($dest_admin))) {
                    mkdir(dirname($dest_admin), 0755, true);
                }
                copy($source_admin, $dest_admin);
            }

            // Copiar overrides de front
            $source_front = dirname(__FILE__) . '/override/controllers/front/PdfInvoiceController.php';
            $dest_front = _PS_ROOT_DIR_ . '/override/controllers/front/PdfInvoiceController.php';

            if (file_exists($source_front)) {
                if (!is_dir(dirname($dest_front))) {
                    mkdir(dirname($dest_front), 0755, true);
                }
                copy($source_front, $dest_front);
            }

            // Eliminar cache de clases para que los overrides se carguen
            if (file_exists(_PS_ROOT_DIR_ . '/cache/class_index.php')) {
                unlink(_PS_ROOT_DIR_ . '/cache/class_index.php');
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function uninstall()
    {
        // Eliminar overrides
        $this->uninstallOverrides();

        return Configuration::deleteByName('FSINVOICES_DB_HOST')
            && Configuration::deleteByName('FSINVOICES_DB_NAME')
            && Configuration::deleteByName('FSINVOICES_DB_USER')
            && Configuration::deleteByName('FSINVOICES_DB_PASS')
            && Configuration::deleteByName('FSINVOICES_PATH')
            && Configuration::deleteByName('FSINVOICES_TABLE_PREFIX')
            && Configuration::deleteByName('FSINVOICES_URL')
            && parent::uninstall();
    }

    private function uninstallOverrides()
    {
        try {
            // Eliminar override de admin
            $override_admin = _PS_ROOT_DIR_ . '/override/controllers/admin/AdminPdfController.php';
            if (file_exists($override_admin)) {
                unlink($override_admin);
            }

            // Eliminar override de front
            $override_front = _PS_ROOT_DIR_ . '/override/controllers/front/PdfInvoiceController.php';
            if (file_exists($override_front)) {
                unlink($override_front);
            }

            // Eliminar cache de clases
            if (file_exists(_PS_ROOT_DIR_ . '/cache/class_index.php')) {
                unlink(_PS_ROOT_DIR_ . '/cache/class_index.php');
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
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
}