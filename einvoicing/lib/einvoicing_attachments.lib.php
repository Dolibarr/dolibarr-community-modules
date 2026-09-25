<?php
/* Copyright (C) 2026		Pierre Grasswill			<da.grumpf@gmail.com>
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
 */

/**
 * \file    einvoicing/lib/einvoicing_attachments.lib.php
 * \ingroup einvoicing
 * \brief   Files of a customer invoice embedded in its e-invoice as additional supporting documents (BG-24).
 *
 * The tab of the module lists the files of the invoice, uploaded from it or from the Documents tab, and a
 * file joins the e-invoice when it is ticked there. The mark lives on the row of the file in llx_ecm_files,
 * in its extraparams column, a JSON field the core keeps for "other parameters" and never writes itself.
 * It holds the BT-123 code of the file, empty when the file goes out without one, and a second key when
 * the file is unticked, so that its code is kept. A file without the mark is not sent.
 */

dol_include_once('/einvoicing/class/protocols/CIIProtocol.class.php');

/** Key of the mark in llx_ecm_files.extraparams */
const EINVOICING_ATTACHMENT_EXTRAPARAM = 'einvoicing_bg24';
/** Key set in llx_ecm_files.extraparams when the file is unticked, its code being kept */
const EINVOICING_ATTACHMENT_EXTRAPARAM_OFF = 'einvoicing_bg24_off';


/**
 * Codes BR-FR-17 accepts in BT-123, the description of an additional supporting document.
 * Any other value is rejected as fatal by the CTC-FR schematron, so the file goes out without BT-123
 * rather than with a free text.
 *
 * @return string[]
 */
function einvoicingAttachmentCodes()
{
	return array(
		'RIB', 'LISIBLE', 'FEUILLE_DE_STYLE', 'PJA', 'BORDEREAU_SUIVI', 'DOCUMENT_ANNEXE', 'BON_LIVRAISON',
		'BON_COMMANDE', 'BORDEREAU_SUIVI_VALIDATION', 'ETAT_ACOMPTE', 'FACTURE_PAIEMENT_DIRECT',
		'RECAPITULATIF_COTRAITANCE',
	);
}

/**
 * Mime code (BT-125-1) of an attached file, read from its extension.
 * Only the mime codes EN 16931 allows are known, the same list the reception side extracts.
 *
 * @param	string		$filename	File name
 * @return	string					Mime code, '' when the file cannot be embedded
 */
function einvoicingAttachmentMimeCode($filename)
{
	$extension = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
	if ($extension === 'jpeg') {
		$extension = 'jpg';
	}
	$mimecode = array_search($extension, CIIProtocol::ATTACHMENT_MIME_EXTENSIONS, true);

	return $mimecode === false ? '' : (string) $mimecode;
}

/**
 * Directory of an invoice, as the core writes it in llx_ecm_files.filepath (relative to DOL_DATA_ROOT).
 *
 * @param	string		$dir		Absolute directory of the invoice
 * @return	string
 */
function einvoicingAttachmentIndexPath($dir)
{
	$reldir = preg_replace('/^'.preg_quote(DOL_DATA_ROOT, '/').'/', '', (string) $dir);

	return trim((string) $reldir, '/\\');
}

/**
 * Files of an invoice the e-invoice can carry, whatever tab they were uploaded from, with their mark.
 * The invoice PDF and the generated e-invoice are left out: they are the invoice itself, not a document of it.
 *
 * @param	DoliDB		$db			Database handler
 * @param	CommonInvoice	$invoice	Customer invoice
 * @return	array<string,array{rowid:int,filename:string,fullname:string,code:string,embed:bool,size:int,date:int}>	Keyed by file name, rowid 0 when the file is not indexed
 */
function einvoicingListInvoiceFiles($db, $invoice)
{
	$dir = getMultidirOutputCompat($invoice, '', 1);
	$files = array();
	if (empty($dir) || empty($invoice->id) || !is_dir($dir)) {
		return $files;
	}

	$invoicepdf = dol_sanitizeFileName($invoice->ref).'.pdf';
	foreach (dol_dir_list($dir, 'files', 0, '', null, 'name', SORT_ASC, 1) as $file) {
		if ($file['name'] === $invoicepdf || substr($file['name'], -12) === '_facturx.pdf' || einvoicingAttachmentMimeCode($file['name']) === '') {
			continue;
		}
		$files[$file['name']] = array(
			'rowid' => 0,
			'filename' => $file['name'],
			'fullname' => $file['fullname'],
			'code' => '',
			'embed' => false,
			'size' => (int) $file['size'],
			'date' => (int) $file['date'],
		);
	}
	if (empty($files)) {
		return $files;
	}

	$sql = "SELECT rowid, filename, extraparams FROM ".MAIN_DB_PREFIX."ecm_files";
	$sql .= " WHERE filepath = '".$db->escape(einvoicingAttachmentIndexPath($dir))."'";
	$sql .= " AND entity = ".((int) $invoice->entity);
	$sql .= " ORDER BY rowid";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
		return $files;
	}
	while ($obj = $db->fetch_object($resql)) {
		if (!isset($files[$obj->filename])) {
			continue;
		}
		$files[$obj->filename]['rowid'] = (int) $obj->rowid;
		$params = json_decode((string) $obj->extraparams, true);
		if (is_array($params) && array_key_exists(EINVOICING_ATTACHMENT_EXTRAPARAM, $params)) {
			$files[$obj->filename]['code'] = (string) $params[EINVOICING_ATTACHMENT_EXTRAPARAM];
			$files[$obj->filename]['embed'] = empty($params[EINVOICING_ATTACHMENT_EXTRAPARAM_OFF]);
		}
	}
	$db->free($resql);

	return $files;
}

/**
 * Files of an invoice ticked to join its e-invoice.
 *
 * @param	DoliDB		$db			Database handler
 * @param	CommonInvoice	$invoice	Customer invoice
 * @return	array<string,array{rowid:int,filename:string,fullname:string,code:string,embed:bool,size:int,date:int}>
 */
function einvoicingFetchAttachedFiles($db, $invoice)
{
	$files = array();
	foreach (einvoicingListInvoiceFiles($db, $invoice) as $filename => $file) {
		if ($file['embed']) {
			$files[$filename] = $file;
		}
	}

	return $files;
}

/**
 * Mark a file indexed in llx_ecm_files with its BT-123 code and whether it joins the e-invoice.
 * The other keys of extraparams are kept: the column belongs to the core, not to this module.
 *
 * @param	DoliDB		$db			Database handler
 * @param	int			$rowid		Row of llx_ecm_files
 * @param	string		$code		BT-123 code, '' for none
 * @param	bool		$embed		True to send the file in the e-invoice, false to keep it on the invoice only
 * @return	int						1 if OK, <0 if KO
 */
function einvoicingSetAttachmentCode($db, $rowid, $code, $embed = true)
{
	if ($code !== '' && !in_array($code, einvoicingAttachmentCodes(), true)) {
		return -2;
	}

	$resql = $db->query("SELECT extraparams FROM ".MAIN_DB_PREFIX."ecm_files WHERE rowid = ".((int) $rowid));
	$obj = $resql ? $db->fetch_object($resql) : null;
	if (!$obj) {
		return -1;
	}
	$params = json_decode((string) $obj->extraparams, true);
	if (!is_array($params)) {
		$params = array();
	}
	$params[EINVOICING_ATTACHMENT_EXTRAPARAM] = $code;
	if ($embed) {
		unset($params[EINVOICING_ATTACHMENT_EXTRAPARAM_OFF]);
	} else {
		$params[EINVOICING_ATTACHMENT_EXTRAPARAM_OFF] = 1;
	}

	$sql = "UPDATE ".MAIN_DB_PREFIX."ecm_files SET extraparams = '".$db->escape((string) json_encode($params))."'";
	$sql .= " WHERE rowid = ".((int) $rowid);

	return $db->query($sql) ? 1 : -1;
}
