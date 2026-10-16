<?php
/* Copyright (C) 2026		Nicolas Vidal				<nicolas.vidal@atm-consulting.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * or see https://www.gnu.org/
 */

/**
 *      \file       test/phpunit/FacturxContainerAttachmentsTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for FacturXProtocol::extractContainerAttachments()
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
dol_include_once('einvoicing/class/protocols/FacturXProtocol.class.php');
require_once DOL_DOCUMENT_ROOT . '/includes/tecnickcom/tcpdf/tcpdf.php';
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * A TCPDF that embeds the files it is given, the way an access point builds the container it returns.
 */
class FacturxContainerAttachmentsTestPdf extends TCPDF
{
	/**
	 * @param	string	$name	Name the file is embedded under
	 * @param	string	$path	File to embed
	 * @return	void
	 */
	public function embed($name, $path)
	{
		$this->embeddedfiles[$name] = array('f' => ++$this->n, 'n' => ++$this->n, 'file' => $path);
	}
}

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class FacturxContainerAttachmentsTest extends CommonClassTest
{
	/** @var string[] */
	private $tempFiles = array();

	/**
	 * @return	void
	 */
	protected function tearDown(): void
	{
		foreach ($this->tempFiles as $file) {
			if (file_exists($file)) {
				unlink($file);
			}
		}
		$this->tempFiles = array();
		parent::tearDown();
	}

	/**
	 * @param	string	$content	Content of the file
	 * @return	string				Its path
	 */
	private function tempFile($content)
	{
		$path = tempnam(sys_get_temp_dir(), 'einv');
		file_put_contents($path, $content);
		$this->tempFiles[] = $path;

		return $path;
	}

	/**
	 * @return	string	Content of a one page PDF
	 */
	private function onePagePdf()
	{
		$pdf = new TCPDF();
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(false);
		$pdf->AddPage();
		$pdf->Write(0, 'Vendor invoice');

		return $pdf->Output('', 'S');
	}

	/**
	 * @param	array<string,string>	$files	Name => content of the files to embed
	 * @return	string							Content of the container
	 */
	private function container(array $files)
	{
		$pdf = new FacturxContainerAttachmentsTestPdf();
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(false);
		$pdf->AddPage();
		foreach ($files as $name => $content) {
			$pdf->embed($name, $this->tempFile($content));
		}

		return $pdf->Output('', 'S');
	}

	/**
	 * The vendor's PDF beside the invoice XML is returned, and the invoice XML is not.
	 *
	 * @return	void
	 */
	public function testTheVendorPdfBesideTheInvoiceIsReturned()
	{
		$vendorPdf = $this->onePagePdf();
		$container = $this->container(array('INVOICE12345.pdf' => $vendorPdf, 'factur-x.xml' => '<rsm:CrossIndustryInvoice/>'));

		$attachments = FacturXProtocol::extractContainerAttachments($container);

		$this->assertCount(1, $attachments);
		$this->assertSame('INVOICE12345', $attachments[0]['filename']);
		$this->assertSame('pdf', $attachments[0]['extension']);
		$this->assertSame('application/pdf', $attachments[0]['mimecode']);
		$this->assertSame($vendorPdf, $attachments[0]['content']);
	}

	/**
	 * A file is kept only when its content is what its name says, among the types EN 16931 allows.
	 *
	 * @return	void
	 */
	public function testOnlyAllowedTypesWithMatchingContentAreReturned()
	{
		$png = "\x89PNG\r\n\x1a\n" . str_repeat("\0", 16);
		$container = $this->container(array(
			'photo.PNG' => $png,
			'fake.pdf' => 'MZ not a pdf',
			'tool.exe' => $this->onePagePdf(),
			'lines.csv' => "ref;qty\nA;1\n",
		));

		$attachments = FacturXProtocol::extractContainerAttachments($container);

		$this->assertSame(array('photo.png', 'lines.csv'), array_map(function ($a) {
			return $a['filename'] . '.' . $a['extension'];
		}, $attachments));
	}

	/**
	 * A control character in an embedded file name is dropped, so that moving the file cannot throw.
	 *
	 * @return	void
	 */
	public function testAControlCharacterInAFileNameIsDropped()
	{
		$container = $this->container(array("fac\0ture.pdf" => $this->onePagePdf()));

		$attachments = FacturXProtocol::extractContainerAttachments($container);

		$this->assertCount(1, $attachments);
		$this->assertSame('facture', $attachments[0]['filename']);
		$this->assertSame('pdf', $attachments[0]['extension']);
	}

	/**
	 * A container that cannot be read gives no attachment instead of stopping the import.
	 *
	 * @return	void
	 */
	public function testATruncatedContainerHasNoAttachment()
	{
		$container = $this->container(array('lines.csv' => "ref;qty\nA;1\n"));

		$this->assertSame(array(), FacturXProtocol::extractContainerAttachments(substr($container, 0, (int) (strlen($container) / 2))));
	}

	/**
	 * An attachment named like the received document, its readable view or an attachment already stored is not overwritten.
	 *
	 * @return	void
	 */
	public function testAnAttachmentNamedLikeAStoredFileGetsItsRank()
	{
		global $conf, $db;

		$savedPdp = getDolGlobalString('EINVOICING_PDP');
		$conf->global->EINVOICING_PDP = 'SuperPDP';
		$method = new ReflectionMethod(CIIProtocol::class, 'embeddedAttachmentSuffix');
		$method->setAccessible(true);
		$protocol = new CIIProtocol($db);

		try {
			$this->assertSame('EInvoice_2', $method->invoke($protocol, 'EInvoice', 'pdf', 1, array()));
			$this->assertSame('superpdp_3', $method->invoke($protocol, 'superpdp', 'pdf', 2, array()));
			$this->assertSame('photo_2', $method->invoke($protocol, 'photo', 'png', 1, array('photo.png')));
			$this->assertSame('photo', $method->invoke($protocol, 'photo', 'pdf', 1, array('photo.png')));
		} finally {
			if ($savedPdp === '') {
				unset($conf->global->EINVOICING_PDP);
			} else {
				$conf->global->EINVOICING_PDP = $savedPdp;
			}
		}
	}

	/**
	 * Anything that is not a PDF, such as a CII document, carries no container to read.
	 *
	 * @return	void
	 */
	public function testANonPdfDocumentHasNoContainerAttachment()
	{
		$this->assertSame(array(), FacturXProtocol::extractContainerAttachments('<?xml version="1.0"?><rsm:CrossIndustryInvoice/>'));
		$this->assertSame(array(), CIIProtocol::extractContainerAttachments($this->container(array('INVOICE1.pdf' => $this->onePagePdf()))));
	}
}
