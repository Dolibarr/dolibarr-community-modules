<?php
/**
 * Stub for the TCPDF flavour of setasign/fpdi.
 *
 * vendor/ is excluded from the phan file list, so the classes the module builds on have to be
 * declared here, the way dev/tools/phan/stubs/zugferd.php does for horstoeko/zugferd. Only the
 * members FacturxTcpdfMerger uses are declared; everything else it calls comes from TCPDF, which
 * dev/tools/phan/stubs/dolibarr.php already covers.
 *
 * @see einvoicing/class/utils/FacturxTcpdfMerger.class.php
 */

namespace setasign\Fpdi\Tcpdf {
	/**
	 * FPDI page importer built on TCPDF.
	 */
	class Fpdi extends \TCPDF
	{
		/**
		 * Set the source PDF the pages are imported from.
		 *
		 * @param  string|resource $file Path, stream or stream reader of the source PDF
		 * @return int                   Number of pages of that PDF
		 */
		public function setSourceFile($file)
		{
			return 0;
		}

		/**
		 * Import a page of the source PDF as a reusable template.
		 *
		 * @param  int    $pageNumber          Page to import, 1 based
		 * @param  string $box                 Page boundary the template is cut on
		 * @param  bool   $groupXObject        Import the page as a group XObject
		 * @param  bool   $importExternalLinks Carry the external links of the page over
		 * @return mixed                       Template identifier
		 */
		public function importPage($pageNumber, $box = 'CropBox', $groupXObject = true, $importExternalLinks = false)
		{
			return null;
		}

		/**
		 * Size of an imported template.
		 *
		 * @param  mixed      $tpl    Template identifier
		 * @param  float|null $width  Width to scale to
		 * @param  float|null $height Height to scale to
		 * @return array{width:float,height:float,0:float,1:float,orientation:string}
		 */
		public function getTemplateSize($tpl, $width = null, $height = null)
		{
			return array('width' => 0.0, 'height' => 0.0, 0 => 0.0, 1 => 0.0, 'orientation' => 'P');
		}

		/**
		 * Draw an imported template on the current page.
		 *
		 * @param  mixed      $tpl            Template identifier
		 * @param  float|null $x              Abscissa
		 * @param  float|null $y              Ordinate
		 * @param  float|null $width          Width to draw at
		 * @param  float|null $height         Height to draw at
		 * @param  bool       $adjustPageSize Resize the page to the template
		 * @return array{width:float,height:float,0:float,1:float,orientation:string}
		 */
		public function useTemplate($tpl, $x = 0, $y = 0, $width = null, $height = null, $adjustPageSize = false)
		{
			return array('width' => 0.0, 'height' => 0.0, 0 => 0.0, 1 => 0.0, 'orientation' => 'P');
		}
	}
}
