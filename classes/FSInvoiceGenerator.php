<?php
/**
 * FSInvoiceGenerator
 * Clase que genera PDFs de facturas usando la estructura de FacturaScripts 2017
 */

class FSInvoiceGenerator
{
    private $fs_host;
    private $fs_user;
    private $fs_pass;
    private $fs_name;
    private $fs_path;
    private $fs_prefix;
    private $conn;

    public function __construct($host, $user, $pass, $name, $path, $prefix = '')
    {
        $this->fs_host = $host;
        $this->fs_user = $user;
        $this->fs_pass = $pass;
        $this->fs_name = $name;
        $this->fs_path = rtrim($path, '/');
        $this->fs_prefix = $prefix;
    }

    public function generateAndServePDF($idfactura)
    {
        // Conectar a la BD de FacturaScripts
        $this->conn = new mysqli($this->fs_host, $this->fs_user, $this->fs_pass, $this->fs_name);
        
        if ($this->conn->connect_error) {
            throw new Exception('Error de conexión a FacturaScripts');
        }

        $this->conn->set_charset('utf8');

        // Cargar datos de la factura
        $factura = $this->getInvoiceData($idfactura);
        
        if (!$factura) {
            $this->conn->close();
            throw new Exception('Factura no encontrada');
        }

        // Cargar líneas de la factura
        $lineas = $this->getInvoiceLines($idfactura);

        // Cargar datos de la empresa
        $empresa = $this->getCompanyData();

        $this->conn->close();

        // Generar PDF usando el sistema de FacturaScripts
        $this->generatePDFUsingFS($factura, $lineas, $empresa);
    }

    private function getInvoiceData($idfactura)
    {
        $table = $this->fs_prefix . 'facturascli';
        $query = "SELECT * FROM `{$table}` WHERE idfactura = {$idfactura}";
        $result = $this->conn->query($query);
        
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        
        return null;
    }

    private function getInvoiceLines($idfactura)
    {
        $table = $this->fs_prefix . 'lineasfacturascli';
        $query = "SELECT * FROM `{$table}` WHERE idfactura = {$idfactura} ORDER BY orden ASC";
        $result = $this->conn->query($query);
        
        $lineas = array();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $lineas[] = $row;
            }
        }
        
        return $lineas;
    }

    private function getCompanyData()
    {
        $table = $this->fs_prefix . 'empresa';
        $query = "SELECT * FROM `{$table}` LIMIT 1";
        $result = $this->conn->query($query);
        
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        
        return array();
    }

    private function generatePDFUsingFS($factura, $lineas, $empresa)
    {
        // Intentar usar el sistema de impresión de FacturaScripts 2017
        $fs_base_path = $this->fs_path;
        
        // Definir constantes necesarias de FacturaScripts si no existen
        if (!defined('FS_PATH')) {
            define('FS_PATH', $fs_base_path);
        }

        // Buscar el controlador de impresión de facturas
        $possible_paths = array(
            $fs_base_path . '/controller/ventas_imprimir.php',
            $fs_base_path . '/plugins/facturacion_base/controller/ventas_imprimir.php',
            $fs_base_path . '/plugins/factura_detallada/controller/factura_detallada.php',
        );

        $imprimir_found = false;
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                // Cargar el archivo de impresión de FacturaScripts
                require_once($path);
                $imprimir_found = true;
                break;
            }
        }

        if ($imprimir_found) {
            // Intentar usar el sistema nativo de FacturaScripts
            $this->useNativeFSPrinting($factura, $fs_base_path);
        } else {
            // Si no encontramos el sistema de FacturaScripts, usar generación propia
            $this->generateCustomPDF($factura, $lineas, $empresa);
        }
    }

    private function useNativeFSPrinting($factura, $fs_base_path)
    {
        // Buscar archivo PDF ya generado
        $pdf_paths = array(
            $fs_base_path . '/tmp/facturas/' . $factura['codigo'] . '.pdf',
            $fs_base_path . '/tmp/' . $factura['codigo'] . '.pdf',
        );

        foreach ($pdf_paths as $pdf_path) {
            if (file_exists($pdf_path)) {
                // Servir PDF existente
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="factura_' . $factura['numero'] . '.pdf"');
                readfile($pdf_path);
                exit;
            }
        }

        // Si no existe, generar usando método propio
        $this->generateCustomPDF($factura, $this->getInvoiceLines($factura['idfactura']), $this->getCompanyData());
    }

    private function generateCustomPDF($factura, $lineas, $empresa)
    {
        // Cargar FPDF (que suele estar en FacturaScripts o usar uno propio)
        $fpdf_path = $this->fs_path . '/vendor/fpdf/fpdf.php';
        
        if (!file_exists($fpdf_path)) {
            // Buscar en ubicaciones alternativas
            $fpdf_paths = array(
                $this->fs_path . '/lib/fpdf/fpdf.php',
                dirname(__FILE__) . '/fpdf/fpdf.php',
            );
            
            foreach ($fpdf_paths as $alt_path) {
                if (file_exists($alt_path)) {
                    $fpdf_path = $alt_path;
                    break;
                }
            }
        }

        if (file_exists($fpdf_path)) {
            require_once($fpdf_path);
        } else {
            // Si no encontramos FPDF, mostrar error
            die('Error: No se encontró la librería FPDF de FacturaScripts');
        }

        // Crear PDF
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);

        // Cabecera
        $pdf->Cell(0, 10, $empresa['nombre'], 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 5, $empresa['direccion'], 0, 1);
        $pdf->Cell(0, 5, 'CIF: ' . $empresa['cifnif'], 0, 1);
        $pdf->Ln(10);

        // Datos de la factura
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 10, 'FACTURA ' . $factura['codserie'] . ' ' . $factura['numero'], 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 5, 'Fecha: ' . date('d/m/Y', strtotime($factura['fecha'])), 0, 1);
        $pdf->Ln(5);

        // Cliente
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 7, 'Cliente:', 0, 1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 5, $factura['nombrecliente'], 0, 1);
        $pdf->Cell(0, 5, $factura['cifnif'], 0, 1);
        $pdf->Cell(0, 5, $factura['direccion'], 0, 1);
        $pdf->Cell(0, 5, $factura['codpostal'] . ' ' . $factura['ciudad'] . ' (' . $factura['provincia'] . ')', 0, 1);
        $pdf->Ln(10);

        // Tabla de líneas
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(80, 7, 'Descripción', 1);
        $pdf->Cell(25, 7, 'Cantidad', 1, 0, 'C');
        $pdf->Cell(30, 7, 'Precio', 1, 0, 'R');
        $pdf->Cell(20, 7, 'IVA %', 1, 0, 'C');
        $pdf->Cell(35, 7, 'Total', 1, 1, 'R');

        $pdf->SetFont('Arial', '', 8);
        foreach ($lineas as $linea) {
            $pdf->Cell(80, 6, $linea['descripcion'], 1);
            $pdf->Cell(25, 6, number_format($linea['cantidad'], 2), 1, 0, 'C');
            $pdf->Cell(30, 6, number_format($linea['pvpunitario'], 2) . ' EUR', 1, 0, 'R');
            $pdf->Cell(20, 6, number_format($linea['iva'], 0), 1, 0, 'C');
            $pdf->Cell(35, 6, number_format($linea['pvptotal'], 2) . ' EUR', 1, 1, 'R');
        }

        // Totales
        $pdf->Ln(5);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(155, 7, 'Base Imponible:', 0, 0, 'R');
        $pdf->Cell(35, 7, number_format($factura['neto'], 2) . ' EUR', 0, 1, 'R');
        
        $pdf->Cell(155, 7, 'IVA:', 0, 0, 'R');
        $pdf->Cell(35, 7, number_format($factura['totaliva'], 2) . ' EUR', 0, 1, 'R');
        
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(155, 10, 'TOTAL:', 0, 0, 'R');
        $pdf->Cell(35, 10, number_format($factura['total'], 2) . ' EUR', 0, 1, 'R');

        // Observaciones
        if (!empty($factura['observaciones'])) {
            $pdf->Ln(10);
            $pdf->SetFont('Arial', 'I', 9);
            $pdf->MultiCell(0, 5, 'Observaciones: ' . $factura['observaciones']);
        }

        // Servir PDF
        $pdf->Output('D', 'factura_' . $factura['numero'] . '.pdf');
        exit;
    }
}
