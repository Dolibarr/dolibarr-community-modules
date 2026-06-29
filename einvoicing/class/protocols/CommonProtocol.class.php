<?php
/* Copyright (C) 2025-2026       Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2025-2026       Mohamed DAOUD               <mdaoud@dolicloud.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */


/**
 * \file    einvoicing/class/protocols/CommonProtocol.class.php
 * \ingroup einvoicing
 * \brief   Common methods for all AP protocols.
 */

trait CommonProtocol
{
	/**
	 * Determine Factur-X BillingProcessID (Cadre / Mode de facturation)
	 * according to French e-invoicing
	 *
	 * BillingProcessID allowed values:
	 *
	 * STANDARD INVOICE (initial submission)
	 * --------------------------------------
	 * B1 : Products invoice
	 * S1 : Services invoice
	 * M1 : Mixed invoice (products + services non-accessory)
	 *
	 * INVOICE (already paid)
	 * -------------------------------------------
	 * B2 : Products invoice
	 * S2 : Services invoice
	 * M2 : Mixed invoice (products + services non-accessory)
	 *
	 * FINAL INVOICE AFTER DEPOSIT
	 * ----------------------------
	 * B4 : Final products invoice (after deposit)
	 * S4 : Final services invoice (after deposit)
	 * M4 : Final mixed invoice (after deposit)
	 *
	 * SPECIFIC CASES
	 * --------------
	 * S5 : Services invoice issued by subcontractor
	 * S6 : Services invoice issued by co-contractor
	 *
	 * E-REPORTING CASE (VAT already collected)
	 * -----------------------------------------
	 * B7 : Products invoice already reported (VAT already collected)
	 * S7 : Services invoice already reported (VAT already collected)
	 *
	 * Notes:
	 * - Prefix meaning:
	 *     B = Products
	 *     S = Services
	 *     M = Mixed (products + services non-accessory)
	 *
	 * @param  Facture $invoice Dolibarr invoice object
	 * @return string  BillingProcessID
	 */
	public function getBillingProcessID($invoice)
	{
		$hasProduct  = false;
		$hasService  = false;

		// Check invoice lines to determine if invoice contains products, services or both
		if (!empty($invoice->lines)) {
			foreach ($invoice->lines as $line) {
				if ((int) $line->product_type === 0) {
					$hasProduct = true;
				}

				if ((int) $line->product_type === 1) {
					$hasService = true;
				}
			}
		}

		// Determine prefix B / S / M (B1, B2, B3, B4 / S1, S2, S3, S4 / M1, M2, M3, M4)
		if ($hasProduct && $hasService) {
			$prefix = 'M';
		} elseif ($hasService && !$hasProduct) {
			$prefix = 'S';
		} else {
			// Default to products
			$prefix = 'B';
		}

		// Determine suffix 1 (initial invoice) or 2 (already paid invoice) according to invoice status and payment information and if the invoice contain a line a deposit (prepayment) so final invoice after deposit then suffix is 4
		if ($invoice->status == Facture::STATUS_CLOSED && empty($invoice->close_code)) {
			return $prefix . '2';
		} else {
			// Check if the invoice contains a deposit (prepayment) line
			$hasDepositLine = false;
			if (!empty($invoice->lines)) {
				foreach ($invoice->lines as $line) {
					if ($line->desc == '(DEPOSIT)') {
						$hasDepositLine = true;
						break;
					}
				}
			}
			if ($hasDepositLine) {
				return $prefix . '4';
			}
			return $prefix . '1';
		}
	}

	/**
	 * Find paymentMean number
	 *
	 * @param  CommonInvoice 	$invoice 			object name we look for
	 * @return integer                      paymentMeanId for HorstOeko libs
	 ************************************************/
	private function _getPaymentMeanNumber($invoice)
	{
		$paymentMeanId = 97;
		//"Must be defined between trading parties" for empty values
		switch ($invoice->mode_reglement_code) {
			case 'CB':
				$paymentMeanId = 54;
				break;
				//Credit Card
			case 'CHQ':
				$paymentMeanId = 20;
				break;
				//Check
			case 'FAC':
				$paymentMeanId = 1;
				break;
				//Local payment method
			case 'LIQ':
				$paymentMeanId = 10;
				break;
				//Cash
			case 'PRE':
				$paymentMeanId = 59;
				break;
				//SEPA direct debit
			case 'TIP':
				$paymentMeanId = 45;
				break;
				//Bank Transfer with document
			case 'TRA':
				$paymentMeanId = 23;
				break;
				//Check
			case 'VAD':
				$paymentMeanId = 68;
				break;
				//Online Payment
			case 'VIR':
				$paymentMeanId = 30;
				break;
		}
		return $paymentMeanId;
	}


	/**
	 * Map type of invoices dolibarr <-> facturx
	 *
	 * @param 	CommonInvoice	$object 	The invoice object
	 * @return  string|null 				code of invoice type
	 */
	private function _getTypeOfInvoice($object)
	{
		$map = [
			CommonInvoice::TYPE_STANDARD        => '380',
			CommonInvoice::TYPE_REPLACEMENT     => '384',
			CommonInvoice::TYPE_CREDIT_NOTE     => '381',
			CommonInvoice::TYPE_DEPOSIT         => '386',
			CommonInvoice::TYPE_SITUATION       => '380',				// Process situation invoice as common invoice
		];

		// TODO Manage the credit note of a deposit invoice ?

		return $map[$object->type] ?? null;
	}


	/**
	 * Return IEC_6523 code (https://docs.peppol.eu/poacc/billing/3.0/codelist/ICD/)
	 * This list of codes describes schemes codes for thirdparties but also products. This functions returns need for thirdparty schemes only.
	 *
	 * @param	string		$country_code		Country code
	 * @param	int			$global				Use 0 for legal ID, use 1 for a global ID, use 2 for URI.
	 * @return string code
	 */
	private function getIEC6523Code($country_code, $global = 0)
	{
		$retour = "";
		switch ($country_code) {
			case 'BE':
				if ($global == 1 || $global == 2) {
					$retour = "0208";
				} else {
					$retour = "0008";
				}
				break;
			case 'DE':
				$retour = "0000";
				break;
			case 'FR':
				if ($global == 1 || $global == 2) {
					$retour = "0225";	// SIREN or SIREN_XXX.  	Einvoice global ID, example: "000000002" or URI OD, example "315143296_1939"
				} else {
					$retour = "0002";	// SIREN.	Used for LegalOrganization, example: "315143296"
				}
				break;
			default:
				if ($global == 1 || $global == 2) {
					$retour = "0060";	// DUNS
					// $retour = "EM";	// Emails
				} else {
					$retour = "0060";	// DUNS
					// $retour = "EM";	// Emails
				}
		}
		return $retour;
	}


	/**
	 * Generate a sample E-invoice for demonstration or testing purposes (for Dolibarr version >= 24.0)
	 *
	 * This method creates a dummy invoice with representative data
	 * to illustrate the E-invoice structure without using real business information.
	 *
	 * @param	EInvoicing			$einvoicing			EInvoicing
	 * @param   Societe|null			$thirdpartySeller		Optional third party object to use for generating the sample invoice. If null, a dummy third party will be created.
	 * @param   Societe|null			$thirdpartyBuyer		Optional third party object to use for generating the sample invoice. If null, a dummy third party will be created.
	 * @param   array<string,mixed>		$options				More options
	 * @return 	-1|array<string,string> 							Path or content of the generated sample invoice.
	 */
	public function generateSampleInvoice($einvoicing, $thirdpartySeller = null, $thirdpartyBuyer = null, $options = array())
	{
		global $conf, $langs, $mysoc;

		dol_mkdir($conf->einvoicing->dir_temp);

		$outputlangs = $langs;		// TODO Use the target language

		require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
		$tmpinvoice = new Facture($this->db);
		$tmpinvoice->initAsSpecimen('nolines');

		$tmpinvoice->ref .= '-' . dol_print_date(dol_now(), '%y%m%d-%H%M%S');
		if (!empty($options['invoicetype'])) {
			$tmpinvoice->type = $options['invoicetype'];
		}

		// Reference of original invoice in case of credit note
		if ($tmpinvoice->type == Facture::TYPE_CREDIT_NOTE) {
			$tmpinvoice->fk_facture_source = $options['referencedinvoice'] ?? 'FA0000-SPECIMEN';
		}

		$line = new FactureLigne($this->db);
		$line->desc = $langs->trans("Description") . " 1";
		$line->qty = 5;
		$line->subprice = 100.05;		// unit price (no discount yet)
		// get_default_tva() requires Societe objects (strictly typed in core, no null allowed on PHP 8+).
		// For the specimen, fall back to our own company ($mysoc) when no third party is provided.
		$sampleSeller = ($thirdpartySeller instanceof Societe) ? $thirdpartySeller : $mysoc;
		$sampleBuyer = ($thirdpartyBuyer instanceof Societe) ? $thirdpartyBuyer : $mysoc;
		$line->tva_tx = get_default_tva($sampleSeller, $sampleBuyer);
		$line->localtax1_tx = 0;
		$line->localtax2_tx = 0;
		$line->remise_percent = 10;
		$line->fk_product = 0;

		include_once DOL_DOCUMENT_ROOT.'/core/lib/price.lib.php';
		// Force MAIN_APPLY_DISCOUNT_ON_UNIT_PRICE_THEN_ROUND_BEFORE_MULTIPLICATION_BY_QTY, so we are sure sample is valid at the initial object.
		// TODO Make this sample generation working with any configuration of discount.
		$conf->global->MAIN_APPLY_DISCOUNT_ON_UNIT_PRICE_THEN_ROUND_BEFORE_MULTIPLICATION_BY_QTY = 2;

		$tmp = calcul_price_total($line->qty, $line->subprice, $line->remise_percent, $line->tva_tx, 0, 0, 0, 'HT', 0, 0);

		$line->total_ht = $tmp[0];
		$line->total_ttc = $tmp[2];
		$line->total_tva = $tmp[1];
		$line->multicurrency_tx = 2;
		$line->multicurrency_total_ht = 2 * $line->total_ht;
		$line->multicurrency_total_ttc = 2 * $line->total_ttc;
		$line->multicurrency_total_tva = 2 * $line->total_tva;

		$tmpinvoice->lines[] = $line;

		$tmpinvoice->total_ht       += $line->total_ht;
		$tmpinvoice->total_tva      += $line->total_tva;
		$tmpinvoice->total_ttc      += $line->total_ttc;

		$tmpinvoice->multicurrency_total_ht       += $line->multicurrency_total_ht;
		$tmpinvoice->multicurrency_total_tva      += $line->multicurrency_total_tva;
		$tmpinvoice->multicurrency_total_ttc      += $line->multicurrency_total_ttc;


		require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';


		// Set $mysoc if seller is not myself (when we want to generate a sample invoice for a purchase).
		$keyforconst = 'EINVOICING_' . getDolGlobalString('EINVOICING_PDP') . '_ROUTING_ID';
		$savmysoc = null;
		$savEINVOICING_ROUTING_ID = null;
		if ($thirdpartySeller instanceof Societe) {
			$savmysoc = $mysoc;
			$savEINVOICING_ROUTING_ID = getDolGlobalString($keyforconst);

			$mysoc = $thirdpartySeller;
			$conf->global->EINVOICING_SUPERPDP_ROUTING_ID = idprof($thirdpartySeller);
		} else {
			$thirdpartySeller = $mysoc;
		}
		//var_dump(($savmysoc ? $savmysoc->name : ''), $mysoc->name, $thirdpartyBuyer->name);


		if ($thirdpartyBuyer instanceof Societe) {
			$tmpthirdparty = $thirdpartyBuyer;
		} else {
			$tmpthirdparty = new Societe($this->db);
			$tmpthirdparty->initAsSpecimen();
			if ($thirdpartySeller->idprof1 == "000000001") {
				// Example Burger Queen on SuperPDP Network
				$tmpthirdparty->idprof1 = '000000002';
				$tmpthirdparty->idprof2 = '00000000200010';
				$tmpthirdparty->tva_intra = 'FR12000000002';
				define('EINVOICING_FORCE_BUYER_EID', getDolGlobalString('EINVOICING_DEMO_ROUTING_BURGER_QUEEN', '315143296_1940')); // vary into demo accounts
			} else {
				// Example Tricatel on SuperPDP Network
				$tmpthirdparty->idprof1 = '000000001';
				$tmpthirdparty->idprof2 = '00000000100010';
				$tmpthirdparty->tva_intra = 'FR12000000001';
				define('EINVOICING_FORCE_BUYER_EID', getDolGlobalString('EINVOICING_DEMO_ROUTING_TRICATEL', '315143296_1939'));
			}
		}
		$tmpinvoice->thirdparty = $tmpthirdparty;
		$tmpinvoice->socid = $tmpthirdparty->id;			// 0 for specimen

		require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';
		$tmpcontact = new Contact($this->db);
		$tmpcontact->initAsSpecimen();
		$tmpcontact->socid = $tmpthirdparty->id;			// 0 for specimen
		$tmpinvoice->contact = $tmpcontact;


		// Generate the Dolibarr PDF of the invoice
		$tmpinvoice->generateDocument($tmpinvoice->model_pdf, $outputlangs);

		// For invoice with ->specimen=1, the file is SPECIMEN.pdf so we rename it into ref
		$dir = $conf->invoice->multidir_output[$conf->entity];
		$srcfile = $dir . '/SPECIMEN.pdf';
		$destfile = $dir . '/' . dol_sanitizeFileName($tmpinvoice->ref) . '.pdf';

		dol_move($srcfile, $destfile, '0', 1);


		// Generate the EInvoice file
		$pathOfEInvoice = $this->generateInvoice($tmpinvoice, $outputlangs);

		// Restore switched variables if we changed $mysoc for generation of the sample invoice
		if (!empty($savmysoc)) {
			$mysoc = $savmysoc;
			$conf->global->$keyforconst = $savEINVOICING_ROUTING_ID;

			$savmysoc = null;
			$savEINVOICING_ROUTING_ID = null;
		}

		// Restore name SPECIMEN.pdf
		dol_move($destfile, $srcfile, '0', 1);

		// Move EInvoice file into the temp directory
		if (is_numeric($pathOfEInvoice) && $pathOfEInvoice < 0) {
			$result = $pathOfEInvoice;
		} else {
			$newPathOfEInvoice = $dir . '/temp/' . basename($pathOfEInvoice);
			dol_move($pathOfEInvoice, $newPathOfEInvoice, '0', 1);

			$result = array('path' => $newPathOfEInvoice, 'ref' => $tmpinvoice->ref);
		}

		return $result;
	}


	/**
	 * Synchronize or create a Dolibarr thirdparty based on E-invoice seller information.
	 *
	 * @param array     $sellerInfo 	Array containing seller information extracted from E-invoice
	 * @param string    $priority 		Fill priority ('dolibarr' or 'pdp'). If both data are available, which one to prefer
	 * @param string    $flowId 		Flow identifier source of the thirdparty.
	 * @return array{res:int, message:string, actioncode:string|null, actionurl:string|null, action:string|null}   Returns array with 'res' (ID of the synchronized or created/updated thirdparty, -1 on error) with a 'message' and an optional 'actioncode', 'actionurl', and 'action'.
	 */
	private function _syncOrCreateThirdpartyFromEInvoiceSeller($sellerInfo, $priority = 'dolibarr', $flowId = '')
	{
		/**
		 * Scenario to find or create a thirdparty based on E-invoice seller information:
		 *
		 * 1. Try to find thirdparty by global IDs (SIREN, VAT number ...)
		 * 1.1 If found, update thirdparty information with provided data
		 *
		 * 2. If not found, try to find thirdparty by closest match (findNearest)
		 * 2.1 If found one match, update thirdparty information with provided data
		 * 2.2 If found multiple matches, log warning and return error
		 *
		 * 3. If still not found, create new thirdparty with provided data
		 */
		global $db, $langs, $user, $conf;
		require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

		$thirdparty = new Societe($db);
		$einvoicing = new EInvoicing($db);
		$thirdpartyId = -1;
		// True when the third party was resolved through a structured identifier (SIREN/SIRET/routing/VAT)
		// and not through a fuzzy name match (findNearest). Used to raise a non-blocking name-mismatch
		// warning only when identification did not rely on the (descriptive) name itself. See issue #309.
		$matchedByStructuredIdentifier = false;

		$sellerCountryCode = $sellerInfo['sellercountry'] ?? '';

		// The legal id (e.g. SIREN) is often carried only in the SpecifiedLegalOrganization
		// (sellerLegalOrgId/Scheme) and left out of sellerGlobalIds. Merge it in so the lookup,
		// update and creation steps below all populate the matching idprof field (e.g. idprof1).
		if (!empty($sellerInfo['sellerLegalOrgId']) && !empty($sellerInfo['sellerLegalOrgScheme'])) {
			if (empty($sellerInfo['sellerGlobalIds']) || !is_array($sellerInfo['sellerGlobalIds'])) {
				$sellerInfo['sellerGlobalIds'] = array();
			}
			if (empty($sellerInfo['sellerGlobalIds'][$sellerInfo['sellerLegalOrgScheme']])) {
				$sellerInfo['sellerGlobalIds'][$sellerInfo['sellerLegalOrgScheme']] = $sellerInfo['sellerLegalOrgId'];
			}
		}

		// Step 1: Try to find thirdparty by global IDs
		if (!empty($sellerInfo['sellerGlobalIds']) && is_array($sellerInfo['sellerGlobalIds'])) {
			foreach ($sellerInfo['sellerGlobalIds'] as $idScheme => $globalId) {
				if (!empty($globalId)) {
					// Map scheme to idprof field (0002 = SIREN)
					// TODO Use function idprof() ?
					$idprofField = $this->_mapGlobalIdSchemeToIdprof($idScheme, $sellerCountryCode);
					if (!empty($idprofField)) {
						$result = 0;
						// Fetch thirdparty by corresponding idprof field
						if ($idprofField === 'idprof1') { // SIREN
							$result = $thirdparty->fetch(0, '', '', '', $globalId);
						}
						if ($idprofField === 'idprof2') { // SIRET
							$result = $thirdparty->fetch(0, '', '', '', '', $globalId);
						}
						if ($idprofField === 'idprof3') {
							$result = $thirdparty->fetch(0, '', '', '', '', '', $globalId);
						}
						if ($idprofField === 'idprof4') {
							$result = $thirdparty->fetch(0, '', '', '', '', '', '', $globalId);
						}
						if ($idprofField === 'idprof5') {
							$result = $thirdparty->fetch(0, '', '', '', '', '', '', '', $globalId);
						}
						if ($idprofField === 'idprof6') {
							$result = $thirdparty->fetch(0, '', '', '', '', '', '', '', '', $globalId);
						}

						if ($result > 0) {
							$thirdpartyId = $thirdparty->id;
							$matchedByStructuredIdentifier = true;
							dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Found thirdparty by ' . $idScheme . ': ' . $thirdpartyId);
							break;
						}
					}
				}
			}
		}
		// Step 2: Try to find using VAT number if not found by global IDs
		if ($thirdpartyId < 0) {
			if (!empty($sellerInfo['sellerTaxRegistations']['VA'])) {
				$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe WHERE REPLACE(tva_intra, ' ', '') = '" . $db->escape($einvoicing->removeSpaces($sellerInfo['sellerTaxRegistations']['VA'])) . "' AND entity IN (". getEntity('societe').")";
				$resql = $db->query($sql);
				if ($resql) {
					if ($db->num_rows($resql) > 1) {
						dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Error: Multiple thirdparties found for VAT number: ' . $sellerInfo['sellerTaxRegistations']['VA'], LOG_ERR);
						$obj1 = $db->fetch_object($resql);
						$obj2 = $db->fetch_object($resql);
						return array(
							'res' => -1,
							'message' => 'Multiple thirdparties found for VAT number: ' . $sellerInfo['sellerTaxRegistations']['VA'],
							'actioncode' => 'DUPLICATE_THIRDPARTIES',
							'action' => 'Merge the 2 thirdparties',
							'actiondata' => array('thirdpartyid1' => $obj1->rowid, 'thirdpartyid2' => $obj2->rowid)
						);
					} elseif ($db->num_rows($resql) === 1) {
						$obj = $db->fetch_object($resql);
						$result = $thirdparty->fetch($obj->rowid);
						if ($result > 0) {
							$thirdpartyId = $thirdparty->id;
							$matchedByStructuredIdentifier = true;
							dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Found thirdparty by VAT number: ' . $thirdpartyId);
						}
					}
				}
			}
		}

		// Step 3: If not found, try to find by findNearest function
		if ($thirdpartyId < 0) {
			if (method_exists($thirdparty, 'findNearest')) {
				$result = $thirdparty->findNearest(
					0,
					$sellerInfo['sellername'] ?? '',
					$sellerInfo['sellername'] ?? '',
					'',
					'',
					'',
					'',
					'',
					'',
					'',
					$sellerInfo['sellercontactemailaddr'] ?? '',
					$sellerInfo['sellername'] ?? ''
				); // TODO: we can add phone, address and vat number to improve matching
			} else {	// Compat method for old versions
				$result = findNearest(
					0,
					$sellerInfo['sellername'] ?? '',
					$sellerInfo['sellername'] ?? '',
					'',
					'',
					'',
					'',
					'',
					'',
					'',
					$sellerInfo['sellercontactemailaddr'] ?? '',
					$sellerInfo['sellername'] ?? ''
				);
			}

			if ($result > 0) {
				// findNearest() RETURNS the rowid (it does not populate $thirdparty->id).
				$thirdpartyId = $result;
				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Found thirdparty by findNearest: ' . $thirdpartyId);
			}
		}

		// Identifier-based match: raise a NON-BLOCKING warning when the descriptive name carried by the
		// e-invoice does not match the linked third party. Under EN 16931 / the French CTC framework, a
		// supplier is identified and routed by its structured identifier (SIREN 0002, SIRET 0009, routing
		// 0225) and VAT number, never by name. Seller name (BT-27) and trading name (BT-28) are descriptive
		// fields, so a mismatch must not block import or routing, but it is a legitimate data-quality /
		// mis-attachment / fraud signal worth surfacing. See issue #309.
		$nameMismatchWarning = '';
		if ($thirdpartyId > 0 && $matchedByStructuredIdentifier) {
			$invoiceNames = array($sellerInfo['sellername'] ?? '', $sellerInfo['sellerTradingName'] ?? '');
			$dolibarrNames = array($thirdparty->name, $thirdparty->name_alias);
			if (!$this->_companyNamesAreConsistent($invoiceNames, $dolibarrNames)) {
				$invoiceName = trim($sellerInfo['sellername'] ?? '');
				if ($invoiceName === '') {
					$invoiceName = trim($sellerInfo['sellerTradingName'] ?? '');
				}
				$nameMismatchWarning = $langs->trans('EInvoiceSupplierNameMismatchWarning', $invoiceName, $thirdparty->name);
				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller ' . $nameMismatchWarning, LOG_WARNING);
				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller ' . $nameMismatchWarning, LOG_WARNING, 0, '_einvoicing');
				setEventMessages($nameMismatchWarning, null, 'warnings');
			}
		}

		// Step 3: Create or update thirdparty

		//$thirdpartyId = -2; // For testing

		// if found, update information
		if ($thirdpartyId > 0) {
			// if complete info is disabled, we return directly the thirdpartyId
			if (getDolGlobalInt('EINVOICING_THIRDPARTIES_COMPLETE_INFO')) {
				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Complete info disabled, returning existing thirdparty: ' . $thirdpartyId);
				return array(
					'res' => $thirdpartyId,
					'message' => 'Existing thirdparty used without update: ' . $thirdpartyId . ($nameMismatchWarning !== '' ? ' - ' . $nameMismatchWarning : '')
				);
			}

			dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Updating existing thirdparty: ' . $thirdpartyId);
			// TODO: MAYBE we should call PDP to retrieve more information

			$thirdparty = new Societe($db);
			$thirdparty->fetch($thirdpartyId);

			// Update thirdparty information based on priority
			if ($priority === 'pdp') { // Overwrite Dolibarr data with AP data
				$thirdparty->name = $sellerInfo['sellername'] ?? $thirdparty->name;
				$thirdparty->address = $sellerInfo['sellerlineone'] ?? $thirdparty->address;
				if (!empty($sellerInfo['sellerlinetwo'])) {
					$thirdparty->address .= "\n" . $sellerInfo['sellerlinetwo'];
				}
				if (!empty($sellerInfo['sellerlinethree'])) {
					$thirdparty->address .= "\n" . $sellerInfo['sellerlinethree'];
				}
				$thirdparty->zip = $sellerInfo['sellerpostcode'] ?? $thirdparty->zip;
				$thirdparty->town = $sellerInfo['sellercity'] ?? $thirdparty->town;
				$thirdparty->country_code = $sellerInfo['sellercountry'] ?? $thirdparty->country_code;
				$thirdparty->email = $sellerInfo['sellercontactemailaddr'] ?? $thirdparty->email;
				$thirdparty->phone = $sellerInfo['sellercontactphoneno'] ?? $thirdparty->phone;
				$thirdparty->fax = $sellerInfo['sellercontactfaxno'] ?? $thirdparty->fax;

				// Set identification numbers
				if (!empty($sellerInfo['sellerGlobalIds']) && is_array($sellerInfo['sellerGlobalIds'])) {
					foreach ($sellerInfo['sellerGlobalIds'] as $idScheme => $globalId) {
						if (!empty($globalId)) {
							$idprofField = $this->_mapGlobalIdSchemeToIdprof($idScheme, $sellerCountryCode);
							if (!empty($idprofField)) {
								$thirdparty->$idprofField = $einvoicing->removeSpaces($globalId);
							}
						}
					}
				}
				if (!empty($sellerInfo['sellerTaxRegistations']['VA'])) {
					$thirdparty->tva_intra = $einvoicing->removeSpaces($sellerInfo['sellerTaxRegistations']['VA']);
					$thirdparty->tva_assuj = 1;
				}
			} elseif ($priority === 'dolibarr') { // Fill only empty fields from pdp data
				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Keeping existing thirdparty data and fill only empty fields as priority is dolibarr: ' . $thirdpartyId);

				if (empty($thirdparty->name) && !empty($sellerInfo['sellername'])) {
					$thirdparty->name = $sellerInfo['sellername'];
				}
				if (empty($thirdparty->address) && !empty($sellerInfo['sellerlineone'])) {
					$thirdparty->address = $sellerInfo['sellerlineone'];
					if (!empty($sellerInfo['sellerlinetwo'])) {
						$thirdparty->address .= "\n" . $sellerInfo['sellerlinetwo'];
					}
					if (!empty($sellerInfo['sellerlinethree'])) {
						$thirdparty->address .= "\n" . $sellerInfo['sellerlinethree'];
					}
				}
				if (empty($thirdparty->zip) && !empty($sellerInfo['sellerpostcode'])) {
					$thirdparty->zip = $sellerInfo['sellerpostcode'];
				}
				if (empty($thirdparty->town) && !empty($sellerInfo['sellercity'])) {
					$thirdparty->town = $sellerInfo['sellercity'];
				}
				if (empty($thirdparty->country_code) && !empty($sellerInfo['sellercountry'])) {
					$thirdparty->country_code = $sellerInfo['sellercountry'];
				}
				if (empty($thirdparty->email) && !empty($sellerInfo['sellercontactemailaddr'])) {
					$thirdparty->email = $sellerInfo['sellercontactemailaddr'];
				}
				if (empty($thirdparty->phone) && !empty($sellerInfo['sellercontactphoneno'])) {
					$thirdparty->phone = $sellerInfo['sellercontactphoneno'];
				}
				if (empty($thirdparty->fax) && !empty($sellerInfo['sellercontactfaxno'])) {
					$thirdparty->fax = $sellerInfo['sellercontactfaxno'];
				}
				// Set identification numbers if empty
				if (!empty($sellerInfo['sellerGlobalIds']) && is_array($sellerInfo['sellerGlobalIds'])) {
					foreach ($sellerInfo['sellerGlobalIds'] as $idScheme => $globalId) {
						if (!empty($globalId)) {
							$idprofField = $this->_mapGlobalIdSchemeToIdprof($idScheme, $sellerCountryCode);
							if (!empty($idprofField) && empty($thirdparty->$idprofField)) {
								$thirdparty->$idprofField = $einvoicing->removeSpaces($globalId);
							}
						}
					}
				}
				if (!empty($sellerInfo['sellerTaxRegistations']['VA']) && empty($thirdparty->tva_intra)) {
					$thirdparty->tva_intra = $einvoicing->removeSpaces($sellerInfo['sellerTaxRegistations']['VA']);
					$thirdparty->tva_assuj = 1;
				}
			}
			// Si le tiers n'est pas encore fournisseur, on le marque comme tel
			// (ex. prospect/client qui reçoit sa 1ère facture fournisseur).
			if (!$thirdparty->fournisseur) {
				$thirdparty->fournisseur = 1;
				$thirdparty->code_fournisseur = 'auto';
			}

			$result = $thirdparty->update(0, $user);
			if ($result < 0) {
				$this->error = $thirdparty->error;
				$this->errors = $thirdparty->errors;

				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Error updating thirdparty: ' . implode(',', array_merge(array($thirdparty->error), $thirdparty->errors)), LOG_ERR);
				return array(
					'res' => -1,
					'message' => 'Thirdparty update error: ' . implode(',', array_merge(array($thirdparty->error), $thirdparty->errors)).'.'
				);
			} else {
				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Updated thirdparty: ' . $thirdpartyId);
				return array(
					'res' => $thirdpartyId,
					'message' => 'Thirdparty ' . $thirdparty->name . ' updated successfully.' . ($nameMismatchWarning !== '' ? ' - ' . $nameMismatchWarning : '')
				);
			}
		}

		// if not found, create new thirdparty
		if ($thirdpartyId < 0 && getDolGlobalInt('EINVOICING_THIRDPARTIES_AUTO_GENERATION')) {
			dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Creating new thirdparty: ' . $sellerInfo['sellername']);

			$thirdparty = new Societe($db);

			$thirdparty->name = $sellerInfo['sellername'] ?? 'Unknown Supplier name';
			$thirdparty->address = $sellerInfo['sellerlineone'] ?? '';
			if (!empty($sellerInfo['sellerlinetwo'])) {
				$thirdparty->address .= "\n" . $sellerInfo['sellerlinetwo'];
			}
			if (!empty($sellerInfo['sellerlinethree'])) {
				$thirdparty->address .= "\n" . $sellerInfo['sellerlinethree'];
			}
			$thirdparty->zip = $sellerInfo['sellerpostcode'] ?? '';
			$thirdparty->town = $sellerInfo['sellercity'] ?? '';
			$thirdparty->country_code = $sellerInfo['sellercountry'] ?? '';
			$thirdparty->email = $sellerInfo['sellercontactemailaddr'] ?? '';
			$thirdparty->phone = $sellerInfo['sellercontactphoneno'] ?? '';
			$thirdparty->fax = $sellerInfo['sellercontactfaxno'] ?? '';

			// Set identification numbers
			if (!empty($sellerInfo['sellerGlobalIds']) && is_array($sellerInfo['sellerGlobalIds'])) {
				foreach ($sellerInfo['sellerGlobalIds'] as $idScheme => $globalId) {
					if (!empty($globalId)) {
						$idprofField = $this->_mapGlobalIdSchemeToIdprof($idScheme, $sellerCountryCode);
						if (!empty($idprofField)) {
							$thirdparty->$idprofField = $einvoicing->removeSpaces($globalId);
						}
					}
				}
			}

			if (!empty($sellerInfo['sellerTaxRegistations']['VA'])) {
				$thirdparty->tva_intra = $einvoicing->removeSpaces($sellerInfo['sellerTaxRegistations']['VA']);
				$thirdparty->tva_assuj = 1;
			}

			// Set as supplier
			$thirdparty->fournisseur = 1;
			$thirdparty->code_fournisseur = 'auto';

			$result = $thirdparty->create($user);
			if ($result > 0) {
				$thirdpartyId = $thirdparty->id;

				// Add entry in einvoicing_extlinks table to mark that this thirdparty is imported from PDP
				$einvoicing->insertOrUpdateExtLink($thirdpartyId, $thirdparty->element, $flowId);

				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Created new thirdparty: ' . $thirdpartyId);
				return array('res' => $thirdpartyId, 'message' => 'Thirdparty ' . $thirdparty->name . ' created successfully');
			} else {
				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Error creating thirdparty: ' . $thirdparty->error, LOG_ERR);
				return array('res' => -1, 'message' => 'Thirdparty creation error: ' . implode("\n", $thirdparty->errors));
			}
		} else {
			dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Auto-creation of thirdparties is disabled', LOG_ERR);

			$sellername = trim($sellerInfo['sellername'] ?? '');
			$selleremail = trim($sellerInfo['sellercontactemailaddr'] ?? '');
			$sellervat = trim($sellerInfo['sellerTaxRegistations']['VA'] ?? '');

			$createParams = [];

			if (!empty($sellername)) {
				$createParams['name'] = $sellername;
			}
			if (!empty($selleremail)) {
				$createParams['email'] = $selleremail;
			}
			if (!empty($sellervat)) {
				$createParams['vatnumber'] = $sellervat;
			}
			if (!empty($sellerInfo['sellerGlobalIds']) && is_array($sellerInfo['sellerGlobalIds'])) {
				foreach ($sellerInfo['sellerGlobalIds'] as $idScheme => $globalId) {
					if (!empty($globalId)) {
						$idprofField = $this->_mapGlobalIdSchemeToIdprof($idScheme, $sellerCountryCode);
						if (!empty($idprofField)) {
							$createParams[$idprofField] = $globalId;
						}
					}
				}
			}

			// Create URL to prefill thirdparty creation form
			$createUrl = DOL_URL_ROOT . '/societe/card.php?action=create&type=f';
			if (!empty($createParams)) {
				$createUrl .= '&' . http_build_query($createParams);
			}
			$createUrl .= '&backtopage=' . urlencode(dol_buildpath('/einvoicing/document_list.php', 1));

			$errorDetails = [];
			$actiondata = [];
			if (!empty($sellername)) {
				$errorDetails[] = 'Supplier: ' . $sellername;
				$actiondata[] = array('name' => $sellername);
			}
			if (!empty($selleremail)) {
				$errorDetails[] = 'Email: ' . $selleremail;
				$actiondata[] = array('email' => $selleremail);
			}
			if (!empty($sellervat)) {
				$errorDetails[] = 'Vat number: ' . $sellervat;
				$actiondata[] = array('vatnumber' => $sellervat);
			}
			if (!empty($sellerInfo['sellerGlobalIds']) && is_array($sellerInfo['sellerGlobalIds'])) {
				foreach ($sellerInfo['sellerGlobalIds'] as $idScheme => $globalId) {
					if (!empty($globalId)) {
						$idprofField = $this->_mapGlobalIdSchemeToIdprof($idScheme, $sellerCountryCode);
						if (!empty($idprofField)) {
							$errorDetails[] = $idprofField.': ' . $globalId;
							$actiondata[] = array($idprofField => $globalId);
						}
					}
				}
			}

			$detailsStr = !empty($errorDetails) ? ' [' . implode(' - ', $errorDetails) . ']' : '';

			$message = 'Unable to find supplier' . $detailsStr . '. Auto-creation of thirdparties is disabled in settings.';

			$action = $langs->trans('CreateSupplierManually');
			$action .= '<a class="butAction small" href="' . dol_escape_htmltag($createUrl) . '" target="_blank">';
			$action .= '<i class="fas fa-plus-circle"></i> ';
			$action .= $langs->trans('CreateSupplier');
			$action .= '</a>';

			return array(
				'res' => -1,
				'message' => $message,
				'actioncode' => 'THIRDPARTY_NOT_FOUND',
				'actionurl' => $createUrl,
				'action' => $action,
				'actiondata' => $actiondata
			);
		}
	}

	/**
	 * Find or create a Dolibarr product based on Einvoice line data
	 * @param array $lineData Array containing invoice line data extracted from XML
	 * @param string $flowId Flow identifier source of the product. Used for logging purposes.
	 *
	 * @return array{res:int, message:string, actioncode:string|null, actionurl:string|null, action:string|null}   Returns array with 'res' (ID of the found or created product, -1 on error) with a 'message' and an optional 'action'.
	 */
	private function _findOrCreateProductFromEinvoiceLine($lineData, $flowId = '')
	{
		/*
		 * PRODUCT MATCHING FOR SUPPLIER INVOICE (XML invoice line => Dolibarr product)
		 *
		 * This matching strategy attempts to find or create a product based on
		 * XML invoice line data, following a priority-based approach.
		 *
		 * 1. Search in product supplier prices table using prodsellerid
		 *    - Ok if match found
		 *    - ko, continue to step 2
		 *
		 * 2. Global ID (prodglobalid + prodglobalidtype) and prodglobalidtype = '0160' search by barcode
		 *    - ok if match found
		 *    - KO if Other schemes or no match, continue to step 3
		 *
		 * 3. if Buyer Reference (prodbuyerid) is available search prodbuyerid = internal product reference
		 *    - ok if match found
		 *    - ko, continue to step 4
		 *
		 * 4. Text Search using prodname
		 *    - ok if match found
		 *    - ko if multiple matches or no match, continue to create product
		 *
		 * 5. If no match found after all steps:
		 *    - Automatic product creation (with extrafield source=Einvoice and to be verified tag)
		 *    - Use this product for supplier invoice line (with extrafield to be verified tag)
		 *    - Add supplier price information (if not added automatically by Dolibarr)
		 */
		global $db, $user, $langs;

		$einvoicing = new EInvoicing($db);

		// Search in product supplier prices table using prodsellerid (the ref of product of the vendor)
		$sql = "SELECT p.rowid ";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product as p ";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product_fournisseur_price as pfp ON pfp.fk_product = p.rowid ";
		$sql .= " WHERE pfp.ref_fourn = '" . $db->escape($lineData['prodsellerid']) . "' ";
		$sql .= " AND pfp.fk_soc = " . intval($lineData['supplierId']) . " ";
		$sql .= " AND p.entity IN (" . getEntity('product') . ")";
		$sql .= " LIMIT 1";
		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) > 0) {
			$obj = $db->fetch_object($resql);
			dol_syslog(get_class($this) . '::_findOrCreateProductFromEinvoiceLine Found product by prodsellerid: ' . $obj->rowid);
			return array('res' => $obj->rowid, 'message' => 'Product found by prodsellerid');
			// No match found, continue to next step
		}

		// Global ID (prodglobalid + prodglobalidtype) and prodglobalidtype = '0160' search by barcode
		// TODO

		// if Buyer Reference (prodbuyerid) is available search prodbuyerid = internal product reference
		if (!empty($lineData['prodbuyerid'])) {
			$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
			$sql .= " WHERE ref = '" . $db->escape($lineData['prodbuyerid']) . "' OR rowid = '" . $db->escape($lineData['prodbuyerid']) . "' ";
			$sql .= " AND entity IN (" . getEntity('product') . ")";
			$sql .= " LIMIT 1";
			$resql = $db->query($sql);
			if ($resql && $db->num_rows($resql) > 0) {
				$obj = $db->fetch_object($resql);
				dol_syslog(get_class($this) . '::_findOrCreateProductFromEinvoiceLine Found product by prodbuyerid: ' . $obj->rowid);
				return array('res' => $obj->rowid, 'message' => 'Product found by prodbuyerid');
			}
		}

		// Check with EI- prefix for product inmported using prodsellerid as internal reference with EI- prefix
		if (!empty($lineData['prodsellerid']) && $lineData['prodsellerid'] !== "0000") {
			$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
			$sql .= " WHERE ref = 'EI-" . $db->escape($lineData['prodsellerid']) . "'";
			$sql .= " AND entity IN (" . getEntity('product') . ")";
			$sql .= " LIMIT 1";
			$resql = $db->query($sql);
			if ($resql && $db->num_rows($resql) > 0) {
				$obj = $db->fetch_object($resql);
				dol_syslog(get_class($this) . '::_findOrCreateProductFromEinvoiceLine Found product by prodsellerid with EI- prefix: ' . $obj->rowid);
				return array('res' => $obj->rowid, 'message' => 'Product found by prodsellerid with EI- prefix');
			}
		}

		// Text Search using prodname
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
		$sql .= " WHERE label = '" . $db->escape($lineData['prodname']) . "'";
		$sql .= " AND entity IN (" . getEntity('product') . ")";
		$resql = $db->query($sql);
		if ($resql) {
			if ($db->num_rows($resql) === 1) {
				$obj = $db->fetch_object($resql);
				dol_syslog(get_class($this) . '::_findOrCreateProductFromEinvoiceLine Found product by text search: ' . $obj->rowid);
				return array('res' => $obj->rowid, 'message' => 'Product found by text search');
			}
		}

		// If not found, we check by using the default product ID on thirdpary level
		$resFetchP = $einvoicing->fetchDefaultRouting($lineData['supplierId'], 'product');
		if (!empty($resFetchP) && $resFetchP != '-1') {
			$product_id = (string) $resFetchP;		// Can be 'idprod_123' (product id) or '456' (supplier ref id)
			if (preg_match('/^idprod_/', $product_id)) {
				$productId = str_replace('idprod_', '', $product_id);
				$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
				$sql .= " WHERE rowid = '" . (int) $productId . "'";
				$sql .= " AND entity IN (" . getEntity('product') . ")";
				$sql .= " LIMIT 1";
				$resql = $db->query($sql);
				if ($resql && $db->num_rows($resql) > 0) {
					$obj = $db->fetch_object($resql);
					dol_syslog(get_class($this) . '::_findOrCreateProductFromEinvoiceLine Default routing product found for supplier=' . $lineData['supplierId'] . ' product=' . $obj->rowid);
					return array('res' => $obj->rowid, 'message' => 'Line product not found, but a default routing product ID was found for this supplier');
				}
			} else {
				// We search in product supplier prices table.
				$sql = "SELECT pfp.fk_product";
				$sql .= " FROM " . MAIN_DB_PREFIX . "product_fournisseur_price as pfp";
				$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product as p";
				$sql .= " ON p.rowid = pfp.fk_product";
				$sql .= " WHERE pfp.rowid = " . ((int) $product_id);
				$sql .= " AND pfp.fk_soc = " . ((int) $lineData['supplierId']);
				$sql .= " AND p.entity IN (" . getEntity('product') . ")";
				$sql .= " LIMIT 1";
				$resql = $db->query($sql);
				if ($resql && $db->num_rows($resql) > 0) {
					$obj = $db->fetch_object($resql);
					dol_syslog(get_class($this) . '::_findOrCreateProductFromEinvoiceLine Default routing product found for supplier=' . $lineData['supplierId'] . ' product=' . $obj->fk_product);
					return array('res' => $obj->fk_product, 'message' => 'Line product not found, but a default routing product was found for this supplier');
				}
			}
		}


		// If no match found after all steps: Create new product
		if (getDolGlobalInt('EINVOICING_PRODUCTS_AUTO_GENERATION')) {
			// Auto-create prouct
			$product = new Product($db);
			$product->type 		= $this->_detectProductTypeFromEinvoiceLine($lineData);
			$product->ref 		= 'EI-' . dol_sanitizeFileName(!empty($lineData['prodsellerid'] && $lineData['prodsellerid'] !== "0000") ? $lineData['prodsellerid'] : uniqid());
			$product->ref_ext 	= trim($lineData['prodsellerid'] ?? '');
			$product->label 	= !empty($lineData['prodname'])
				? $lineData['prodname']
				: 'Imported product from supplier invoice (Ref: ' . $lineData['parentDocumentNo'] . ')';
			$product->description = trim($lineData['proddesc'] ?? '');
			$product->tva_tx 	= (float) ($lineData['rateApplicablePercent'] ?? 0);
			$product->status 	= 0; // Status not to sell
			$product->status_buy = 1; // Status to buy
			$product->note_private = 'Product created automatically from E-invoice import.';
			$product->import_key = AbstractPDPProvider::$EINVOICING_LAST_IMPORT_KEY; // It does not work here, so we will update it after creation
			// Set barcode if global ID is provided and is a GTIN/EAN type
			if (!empty($lineData['prodglobalid']) && !empty($lineData['prodglobalidtype']) && in_array($lineData['prodglobalidtype'], ['0160', '0011'])) {
				$product->barcode = $lineData['prodglobalid'];
				$product->barcode_type = getDolGlobalInt('PRODUIT_DEFAULT_BARCODE_TYPE', 0);
			} else {
				$product->barcode = 'auto';
			}
			// Validate before creation
			$resCheck = $product->check();
			if ($resCheck < 0) {
				dol_syslog(__METHOD__ . ' Product check failed: ' . $product->error, LOG_ERR);
				return array('res' => -1, 'message' => 'Product check failed: ' . implode("\n", $product->errors));
			}

			// Create product
			$resCreate = $product->create($user);
			if ($resCreate > 0) {
				$productId = $product->id;

				// Set import_key
				$sql = "UPDATE " . MAIN_DB_PREFIX . "product SET import_key = '" . $db->escape($product->import_key) . "'";
				$sql .= " WHERE rowid = " . ((int) $productId);
				$db->query($sql);

				// Add entry in einvoicing_extlinks table to mark product as created from e-invoice
				$einvoicing->insertOrUpdateExtLink($productId, $product->element, $flowId);

				dol_syslog(__METHOD__ . ' New product created (ID: ' . $productId . ')');
				return [
					'res' => $productId,
					'message' => 'Product successfully created from E-invoice import',
				];
			}

			// Error on creation
			dol_syslog(__METHOD__ . ' Product creation error: ' . $product->error, LOG_ERR);
			return [
				'res' => -1,
				'message' => 'Product creation error: ' . $product->error,
			];
		} else {
			// Suggest manual creation of product
			dol_syslog(get_class($this) . '::_findOrCreateProductFromEinvoiceLine Auto-creation of products is disabled', LOG_ERR);

			$prodRef = trim($lineData['prodbuyerid'] ?? '');
			$prodSupplierRef = trim($lineData['prodsellerid'] ?? '');
			$prodName = trim($lineData['prodname'] ?? '');
			$prodDesc = trim($lineData['proddesc'] ?? '');
			$vendorId = $lineData['supplierId'];

			$errorDetails = [];
			$createParams = [];
			$actiondata = ['ref' => $prodRef, 'supplierref' => $prodSupplierRef, 'name' => $prodName];

			if (!empty($prodRef) && $prodRef !== "0000") {
				$errorDetails[] = 'Ref: '.$prodRef;

				$createParams['ref'] = 'EI-' . dol_sanitizeFileName(!empty($lineData['prodsellerid'] && $lineData['prodsellerid'] !== "0000") ? $lineData['prodsellerid'] : uniqid());

				$createParams['ref_ext'] = $prodRef;
			}
			if (!empty($vendorId)) {
				$errorDetails[] = 'Vendor id: ' . $vendorId;
				$createParams['socid'] = $vendorId;							// TODO Dolibarr must be able to handle this parameter
			}
			if (!empty($prodSupplierRef)) {
				$errorDetails[] = 'Supplier ref: ' . $prodSupplierRef;
				$createParams['supplierref'] = $prodSupplierRef;			// TODO Dolibarr must be able to handle this parameter
			}
			if (!empty($prodName)) {
				$errorDetails[] = 'Name: ' . $prodName;
				$createParams['label'] = $prodName;
			}
			if (!empty($prodDesc)) {
				//$errorDetails[] = 'Description: ' . $prodDesc;
				$createParams['desc'] = $prodDesc;
			}

			// Detect product type to prefill form
			$createParams['type'] = $this->_detectProductTypeFromEinvoiceLine($lineData);
			$createParams['tva_tx'] = (float) ($lineData['rateApplicablePercent'] ?? 0);
			$createParams['status'] = 1; // Active
			if (!empty($lineData['prodglobalid']) && !empty($lineData['prodglobalidtype']) && in_array($lineData['prodglobalidtype'], ['0160', '0011'])) {
				$createParams['barcode'] = $lineData['prodglobalid'];
				$createParams['barcode_type'] = getDolGlobalInt('PRODUIT_DEFAULT_BARCODE_TYPE', 0);
			} else {
				$createParams['barcode'] = 'auto';
			}

			// Create URL to prefill product creation form
			$createUrl = DOL_URL_ROOT . '/product/card.php?action=create';
			if (!empty($createParams)) {
				$createUrl .= '&' . http_build_query($createParams);
			}
			$createUrl .= '&backtopage=' . urlencode(dol_buildpath('/einvoicing/document_list.php', 1));

			$detailsStr = !empty($errorDetails) ? ' [' . implode(' - ', $errorDetails) . ']' : '';

			$message = 'Unable to find product' . $detailsStr . '. Auto-creation of products is disabled in settings.';

			$action = $langs->trans('CreateProductManually') . ' ';
			$action .= '<a class="butAction smallpaddingimp" href="' . dol_escape_htmltag($createUrl) . '" target="_blank">';
			$action .= '<i class="fas fa-plus-circle"></i> ';
			$action .= $langs->trans('CreateTheProduct');
			$action .= '</a>';

			/*
			$createSupplierRefUrl = 'todo';
			$sellerName ='xxx';

			$action .= $langs->trans("or");
			$action .= '<a class="butAction smallpaddingimp" href="' . dol_escape_htmltag($createSupplierRefUrl) . '" target="_blank">';
			$action .= '<i class="fas fa-plus-circle"></i> ';
			$action .= $langs->trans('CreateSupplierRef').' '.dol_trunc($prodSupplierRef, 8).' for '.dol_trunc($sellerName, 8);
			$action .= '</a>';
			*/

			return array(
				'res' => -1,
				'message' => $message,
				'actioncode' => 'PRODUCT_NOT_FOUND',
				'actionurl' => $createUrl,
				'action' => $action,				// label of sentence to make action
				'actiondata' => $actiondata			// array of paramto use for URL to make action
			);
		}
	}


	/**
	 * Map global ID scheme to Dolibarr idprof field
	 *
	 * @param 	string 	$scheme 		Global ID scheme code
	 * @param	string	$countrycode	Country code
	 * @return 	string 					Corresponding idprof field name
	 */
	private function _mapGlobalIdSchemeToIdprof($scheme, $countrycode = '')
	{
		$map = [
			'0002' => 'idprof1',	// SIREN
			'0225' => 'idprof1',	// SIREN
			'0009' => 'idprof2',	// SIRET
		];

		return $map[$scheme] ?? '';
	}


	/**
	 * Normalize a company name for tolerant comparison.
	 *
	 * Applies the normalization recommended for e-invoicing name checks so that purely descriptive
	 * differences do not raise false positives: strip accents, lowercase, drop common legal forms
	 * (SARL, SAS, GmbH, Ltd...) and collapse everything that is not a letter or a digit. The result is a
	 * comparison key, not a displayable name.
	 *
	 * @param 	string 	$name 	Raw company name
	 * @return 	string 			Normalized comparison key (may be an empty string)
	 */
	private function _normalizeCompanyNameForComparison($name)
	{
		$name = trim((string) $name);
		if ($name === '') {
			return '';
		}

		// Strip accents then lowercase so "Société" and "SOCIETE" compare equal
		$name = strtolower(dol_string_unaccent($name));

		// Remove common legal forms (whole words only) to avoid false positives on suffixes
		$legalForms = array(
			'sarl', 'sas', 'sasu', 'sa', 'eurl', 'sci', 'snc', 'scop', 'scs', 'sca', 'gie', 'ei',
			'societe', 'ste', 'ets', 'etablissements', 'cie', 'gmbh', 'ug', 'ag', 'kg', 'ohg',
			'ltd', 'limited', 'llc', 'inc', 'corp', 'co', 'plc', 'bv', 'nv', 'srl', 'spa', 'sl',
		);
		$name = preg_replace('/\b(' . implode('|', $legalForms) . ')\b/', ' ', $name);

		// Keep only alphanumeric characters (drops punctuation, spaces, &, -, etc.)
		$name = preg_replace('/[^a-z0-9]/', '', (string) $name);

		return (string) $name;
	}


	/**
	 * Check whether a name carried by an e-invoice is consistent with the linked third party.
	 *
	 * Each candidate name is normalized (see _normalizeCompanyNameForComparison) and a match is accepted
	 * when any invoice name equals or is contained in any Dolibarr name (or vice versa), so that a trading
	 * name vs. legal name difference does not trigger a warning. When either side has no usable name after
	 * normalization, the comparison is inconclusive and the names are considered consistent (no false alarm).
	 *
	 * @param 	string[] 	$invoiceNames 	Candidate names from the e-invoice (e.g. BT-27 seller name, BT-28 trading name)
	 * @param 	string[] 	$dolibarrNames 	Candidate names from the linked third party (e.g. nom, name_alias)
	 * @return 	bool 						True if consistent (or not comparable), false on a genuine mismatch
	 */
	private function _companyNamesAreConsistent(array $invoiceNames, array $dolibarrNames)
	{
		$normInvoice = array();
		foreach ($invoiceNames as $candidate) {
			$key = $this->_normalizeCompanyNameForComparison($candidate);
			if ($key !== '') {
				$normInvoice[$key] = $key;
			}
		}
		$normDolibarr = array();
		foreach ($dolibarrNames as $candidate) {
			$key = $this->_normalizeCompanyNameForComparison($candidate);
			if ($key !== '') {
				$normDolibarr[$key] = $key;
			}
		}

		// Not enough data to compare -> do not raise a warning
		if (empty($normInvoice) || empty($normDolibarr)) {
			return true;
		}

		foreach ($normInvoice as $invoiceName) {
			foreach ($normDolibarr as $dolibarrName) {
				if ($invoiceName === $dolibarrName
					|| strpos($invoiceName, $dolibarrName) !== false
					|| strpos($dolibarrName, $invoiceName) !== false) {
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * Determine if a invoice line corresponds to a product (0) or a service (1)
	 *
	 * @param 	array 	$line 	Invoice line data
	 * @return 	int 			0 = product / 1 = service
	 */
	private function _detectProductTypeFromEinvoiceLine(array $line): int
	{
		$globalId = trim($line['prodglobalid'] ?? '');
		$globalIdType = trim($line['prodglobalidtype'] ?? '');
		$sellerId = trim($line['prodsellerid'] ?? '');
		$unitCode = strtoupper(trim($line['billedquantityunitcode'] ?? ''));
		$name = strtolower($line['prodname'] ?? '');
		$desc = strtolower($line['proddesc'] ?? '');

		// A. Global ID known => product
		// EAN = 0088
		$productGlobalIdTypes = ['0160', '0011', '0002', '0023', '0004', '0001', '0088']; // GTIN/UPC/EAN...
		if ($globalId !== '' && in_array($globalIdType, $productGlobalIdTypes, true)) {
			return 0;
		}

		// B. Units typical for services
		$serviceUnits = ['HUR', 'HRS', 'DAY', 'MON', 'ANN', 'MIN', 'WEE', 'E48']; // hours, days, months...
		if (in_array($unitCode, $serviceUnits, true)) {
			return 1;
		}

		// C. Piece but no seller reference => likely service
		if ($sellerId === '' || $sellerId === '0000') {
			return 1;
		}

		// D. Keywords indicating service
		$keywordsService = ['service', 'prestation', 'maintenance', 'installation', 'abonnement', 'support', 'forfait', 'consult'];
		foreach ($keywordsService as $kw) {
			if (stripos($name, $kw) !== false || stripos($desc, $kw) !== false) {
				return 1;
			}
		}

		// Fallback = service
		return 0;
	}

	/**
	 * Get a timestamp and return a php DateTime object
	 *
	 * @param	int		$ts			Timestamp
	 * @return 	\DateTime|null 		DateTime object or null if $ts is empty
	 */
	private function _tsToDateTime($ts)
	{
		dol_syslog("call _tsToDateTime for {$ts} ...");
		if (empty($ts)) {
			return null;
		}
		$dt = new \DateTime();
		$dt->setTimestamp($ts);
		return $dt;
	}

	/**
	 * Check if a given VAT rate is valid for a specific country based on the c_tva table in the database.
	 *
	 * @param 	string	$vatrate		Vat rate to check (e.g. '20' for 20%)
	 * @param 	string	$countryCode	Country code to check the VAT rate against (e.g. 'FR' for France)
	 * @return 	boolean					Returns true if the VAT rate is valid for the given country, false otherwise.
	 */
	public function checkIfVatRateIsValid($vatrate, $countryCode)
	{
		if ($countryCode == 'FR') {
			// Check rule BR-FR-16 For AFNOR Einvoice - List in XP-Z12-012
			$validRatesString = ['0', '10', '13', '20', '8.5', '19.6', '2.1', '5.5', '7', '20.6', '1.05', '0.9', '1.75', '9.2', '9.6'];
			//$valtotest = price2num((float) $vatrate, '', 1);
			if (!in_array($vatrate, $validRatesString)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the category of the VAT rate and the VATEX code and reason.
	 *
	 * @param 	CommonInvoiceLine		$line			Invoice line
	 * @param 	Societe 				$seller			Seller
	 * @param 	Societe					$buyer			Buyer
	 * @return 	array<string,string>					array('categoryVAT' => Category of VAT rate ('S', 'K', 'E', 'G'), 'ExemptionReason' => '', 'ExemptionReasonCode => '')
	 */
	public function getCategoryRate($line, $seller, $buyer)
	{
		global $langs;

		$vat_rate = $line->tva_tx;
		$vat_src_code = $line->vat_src_code;
		$id = $line->id;

		$errormsg = '';

		// List of VATEX codes and reasons imported from https://docs.peppol.eu/poacc/billing/3.0/codelist/vatex/
		$VATEX_CODE_LIST = array(
			'VATEX-EU-79-C' => array('reason' => 'Exempt based on article 79, point c of Council Directive 2006/112/EC', 'details' => 'Exemptions relating to repayment of expenditures. Remark, Repayment of expenditure is not an exemption in the sense of the VAT Directive but may be handled as such in the context of the EN16931.'),
			'VATEX-EU-132' => array('reason' => 'Exempt based on article 132 of Council Directive 2006/112/EC', 'details' => 'Exemptions for certain activities in public interest.'),
			'VATEX-EU-132-1A' => array('reason' => 'Exempt based on article 132, section 1 (a) of Council Directive 2006/112/EC', 'details' => 'The supply by the public postal services of services other than passenger transport and telecommunications services, and the supply of goods incidental thereto.'),
			'VATEX-EU-132-1B' => array('reason' => 'Exempt based on article 132, section 1 (b) of Council Directive 2006/112/EC', 'details' => 'Hospital and medical care and closely related activities undertaken by bodies governed by public law or, under social conditions comparable with those applicable to bodies governed by public law, by hospitals, centres for medical treatment or diagnosis and other duly recognised establishments of a similar nature'),
			'VATEX-EU-132-1C' => array('reason' => 'Exempt based on article 132, section 1 (c) of Council Directive 2006/112/EC', 'details' => 'The provision of medical care in the exercise of the medical and paramedical professions as defined by the Member State concerned.'),
			'VATEX-EU-132-1D' => array('reason' => 'Exempt based on article 132, section 1 (d) of Council Directive 2006/112/EC', 'details' => 'The supply of human organs, blood and milk.'),
			'VATEX-EU-132-1E' => array('reason' => 'Exempt based on article 132, section 1 (e) of Council Directive 2006/112/EC', 'details' => 'The supply of services by dental technicians in their professional capacity and the supply of dental prostheses by dentists and dental technicians.'),
			'VATEX-EU-132-1F' => array('reason' => 'Exempt based on article 132, section 1 (f) of Council Directive 2006/112/EC', 'details' => 'The supply of services by independent groups of persons, who are carrying on an activity which is exempt from VAT or in relation to which they are not taxable persons, for the purpose of rendering their members the services directly necessary for the exercise of that activity, where those groups merely claim from their members exact reimbursement of their share of the joint expenses, provided that such exemption is not likely to cause distortion of competition.'),
			'VATEX-EU-132-1G' => array('reason' => 'Exempt based on article 132, section 1 (g) of Council Directive 2006/112/EC', 'details' => 'The supply of services and of goods closely linked to welfare and social security work, including those supplied by old people\'s homes, by bodies governed by public law or by other bodies recognised by the Member State concerned as being devoted to social wellbeing.'),
			'VATEX-EU-132-1H' => array('reason' => 'Exempt based on article 132, section 1 (h) of Council Directive 2006/112/EC', 'details' => '"The supply of services and of goods closely linked to the protection of children and young persons by bodies governed by public law or by other organisations recognised by the Member State concerned as being devoted to social wellbeing"'),
			'VATEX-EU-132-1I' => array('reason' => 'Exempt based on article 132, section 1 (i) of Council Directive 2006/112/EC', 'details' => '" The provision of children\'s or young people\'s education, school or university education, vocational training or retraining, including the supply of services and of goods closely related thereto, by bodies governed by public law having such as their aim or by other organisations recognised by the Member State concerned as having similar objects."'),
			'VATEX-EU-132-1J' => array('reason' => 'Exempt based on article 132, section 1 (j) of Council Directive 2006/112/EC', 'details' => 'Tuition given privately by teachers and covering school or university education.'),
			'VATEX-EU-132-1K' => array('reason' => 'Exempt based on article 132, section 1 (k) of Council Directive 2006/112/EC', 'details' => 'The supply of staff by religious or philosophical institutions for the purpose of the activities referred to in points (b), (g), (h) and (i) and with a view to spiritual welfare.'),
			'VATEX-EU-132-1L' => array('reason' => 'Exempt based on article 132, section 1 (l) of Council Directive 2006/112/EC', 'details' => 'The supply of services, and the supply of goods closely linked thereto, to their members in their common interest in return for a subscription fixed in accordance with their rules by non-profitmaking organisations with aims of a political, trade-union, religious, patriotic, philosophical, philanthropic or civic nature, provided that this exemption is not likely to cause distortion of competition.'),
			'VATEX-EU-132-1M' => array('reason' => 'Exempt based on article 132, section 1 (m) of Council Directive 2006/112/EC', 'details' => 'The supply of certain services closely linked to sport or physical education by non-profit-making organisations to persons taking part in sport or physical education.'),
			'VATEX-EU-132-1N' => array('reason' => 'Exempt based on article 132, section 1 (n) of Council Directive 2006/112/EC', 'details' => 'The supply of certain cultural services, and the supply of goods closely linked thereto, by bodies governed by public law or by other cultural bodies recognised by the Member State concerned.'),
			'VATEX-EU-132-1O' => array('reason' => 'Exempt based on article 132, section 1 (o) of Council Directive 2006/112/EC', 'details' => '"The supply of services and goods, by organisations whose activities are exempt pursuant to points (b), (g), (h), (i), (l), (m) and (n), in connection with fund-raising events organised exclusively for their own benefit, provided that exemption is not likely to cause distortion of competition."'),
			'VATEX-EU-132-1P' => array('reason' => 'Exempt based on article 132, section 1 (p) of Council Directive 2006/112/EC', 'details' => 'The supply of transport services for sick or injured persons in vehicles specially designed for the purpose, by duly authorised bodies.'),
			'VATEX-EU-132-1Q' => array('reason' => 'Exempt based on article 132, section 1 (q) of Council Directive 2006/112/EC', 'details' => 'The activities, other than those of a commercial nature, carried out by public radio and television bodies.'),
			'VATEX-EU-143' => array('reason' => 'Exempt based on article 143 of Council Directive 2006/112/EC', 'details' => 'Exemptions on importation.'),
			'VATEX-EU-143-1A' => array('reason' => 'Exempt based on article 143, section 1 (a) of Council Directive 2006/112/EC', 'details' => 'The final importation of goods of which the supply by a taxable person would in all circumstances be exempt within their respective territory.'),
			'VATEX-EU-143-1B' => array('reason' => 'Exempt based on article 143, section 1 (b) of Council Directive 2006/112/EC', 'details' => 'The final importation of goods governed by Council Directives 69/169/EEC (1), 83/181/EEC (2) and 2006/79/EC (3).'),
			'VATEX-EU-143-1C' => array('reason' => 'Exempt based on article 143, section 1 (c) of Council Directive 2006/112/EC', 'details' => 'The final importation of goods, in free circulation from a third territory forming part of the Community customs territory, which would be entitled to exemption under point (b) if they had been imported within the meaning of the first paragraph of Article 30'),
			'VATEX-EU-143-1D' => array('reason' => 'Exempt based on article 143, section 1 (d) of Council Directive 2006/112/EC', 'details' => 'The importation of goods dispatched or transported from a third territory or a third country into a Member State other than that in which the dispatch or transport of the goods ends, where the supply of such goods by the importer designated or recognised under Article 201 as liable for payment of VAT is exempt under Article 138.'),
			'VATEX-EU-143-1E' => array('reason' => 'Exempt based on article 143, section 1 (e) of Council Directive 2006/112/EC', 'details' => 'The reimportation, by the person who exported them, of goods in the state in which they were exported, where those goods are exempt from customs duties.'),
			'VATEX-EU-143-1F' => array('reason' => 'Exempt based on article 143, section 1 (f) of Council Directive 2006/112/EC', 'details' => 'The importation, under diplomatic and consular arrangements, of goods which are exempt from customs duties.'),
			'VATEX-EU-143-1FA' => array('reason' => 'Exempt based on article 143, section 1 (fa) of Council Directive 2006/112/EC', 'details' => '"The importation of goods by the European Community, the European Atomic Energy Community, the European Central Bank or the European Investment Bank, or by the bodies set up by the Communities to which the Protocol of 8 April 1965 on the privileges and immunities of the European Communities applies, within the limits and under the conditions of that Protocol and the agreements for its implementation or the headquarters agreements, in so far as it does not lead to distortion of competition"'),
			'VATEX-EU-143-1G' => array('reason' => 'Exempt based on article 143, section 1 (g) of Council Directive 2006/112/EC', 'details' => '" The importation of goods by international bodies, other than those referred to in point (fa), recognised as such by the public authorities of the host Member State, or by members of such bodies, within the limits and under the conditions laid down by the international conventions establishing the bodies or by headquarters agreements"'),
			'VATEX-EU-143-1H' => array('reason' => 'Exempt based on article 143, section 1 (h) of Council Directive 2006/112/EC', 'details' => 'The importation of goods, into Member States party to the North Atlantic Treaty, by the armed forces of other States party to that Treaty for the use of those forces or the civilian staff accompanying them or for supplying their messes or canteens where such forces take part in the common defence effort.'),
			'VATEX-EU-143-1I' => array('reason' => 'Exempt based on article 143, section 1 (i) of Council Directive 2006/112/EC', 'details' => 'The importation of goods by the armed forces of the United Kingdom stationed in the island of Cyprus pursuant to the Treaty of Establishment concerning the Republic of Cyprus, dated 16 August 1960, which are for the use of those forces or the civilian staff accompanying them or for supplying their messes or canteens.'),
			'VATEX-EU-143-1J' => array('reason' => 'Exempt based on article 143, section 1 (j) of Council Directive 2006/112/EC', 'details' => 'The importation into ports, by sea fishing undertakings, of their catches, unprocessed or after undergoing preservation for marketing but before being supplied.'),
			'VATEX-EU-143-1K' => array('reason' => 'Exempt based on article 143, section 1 (k) of Council Directive 2006/112/EC', 'details' => 'The importation of gold by central banks.'),
			'VATEX-EU-143-1L' => array('reason' => 'Exempt based on article 143, section 1 (l) of Council Directive 2006/112/EC', 'details' => 'The importation of gas through a natural gas system or any network connected to such a system or fed in from a vessel transporting gas into a natural gas system or any upstream pipeline network, of electricity or of heat or cooling energy through heating or cooling networks.'),
			'VATEX-EU-144' => array('reason' => 'Exempt based on article 144 of Council Directive 2006/112/EC', 'details' => 'Exemptions for services linked to the import of goods'),
			'VATEX-EU-146-1E' => array('reason' => 'Exempt based on article 146 section 1 (e) of Council Directive 2006/112/EC', 'details' => 'Exempt Exemptions for services linked to the export of goods'),
			'VATEX-EU-148' => array('reason' => 'Exempt based on article 148 of Council Directive 2006/112/EC', 'details' => 'Exemptions related to international transport.'),
			'VATEX-EU-148-A' => array('reason' => 'Exempt based on article 148, section (a) of Council Directive 2006/112/EC', 'details' => 'Fuel supplies for commercial international transport vessels'),
			'VATEX-EU-148-B' => array('reason' => 'Exempt based on article 148, section (b) of Council Directive 2006/112/EC', 'details' => 'Fuel supplies for fighting ships in international transport.'),
			'VATEX-EU-148-C' => array('reason' => 'Exempt based on article 148, section (c) of Council Directive 2006/112/EC', 'details' => 'Maintenance, modification, chartering and hiring of international transport vessels.'),
			'VATEX-EU-148-D' => array('reason' => 'Exempt based on article 148, section (d) of Council Directive 2006/112/EC', 'details' => 'Supply to of other services to commercial international transport vessels.'),
			'VATEX-EU-148-E' => array('reason' => 'Exempt based on article 148, section (e) of Council Directive 2006/112/EC', 'details' => 'Fuel supplies for aircraft on international routes.'),
			'VATEX-EU-148-F' => array('reason' => 'Exempt based on article 148, section (f) of Council Directive 2006/112/EC', 'details' => 'Maintenance, modification, chartering and hiring of aircraft on international routes.'),
			'VATEX-EU-148-G' => array('reason' => 'Exempt based on article 148, section (g) of Council Directive 2006/112/EC', 'details' => 'Supply to of other services to aircraft on international routes.'),
			'VATEX-EU-151' => array('reason' => 'Exempt based on article 151 of Council Directive 2006/112/EC', 'details' => 'Exemptions relating to certain Transactions treated as exports.'),
			'VATEX-EU-151-1A' => array('reason' => 'Exempt based on article 151, section 1 (a) of Council Directive 2006/112/EC', 'details' => 'The supply of goods or services under diplomatic and consular arrangements.'),
			'VATEX-EU-151-1AA' => array('reason' => 'Exempt based on article 151, section 1 (aa) of Council Directive 2006/112/EC', 'details' => 'The supply of goods or services to the European Community, the European Atomic Energy Community, the European Central Bank or the European Investment Bank, or to the bodies set up by the Communities to which the Protocol of 8 April 1965 on the privileges and immunities of the European Communities applies, within the limits and under the conditions of that Protocol and the agreements for its implementation or the headquarters agreements, in so far as it does not lead to distortion of competition.'),
			'VATEX-EU-151-1B' => array('reason' => 'Exempt based on article 151, section 1 (b) of Council Directive 2006/112/EC', 'details' => 'The supply of goods or services to international bodies, other than those referred to in point (aa), recognised as such by the public authorities of the host Member States, and to members of such bodies, within the limits and under the conditions laid down by the international conventions establishing the bodies or by headquarters agreements.'),
			'VATEX-EU-151-1C' => array('reason' => 'Exempt based on article 151, section 1 (c) of Council Directive 2006/112/EC', 'details' => 'The supply of goods or services within a Member State which is a party to the North Atlantic Treaty, intended either for the armed forces of other States party to that Treaty for the use of those forces, or of the civilian staff accompanying them, or for supplying their messes or canteens when such forces take part in the common defence effort.'),
			'VATEX-EU-151-1D' => array('reason' => 'Exempt based on article 151, section 1 (d) of Council Directive 2006/112/EC', 'details' => 'The supply of goods or services to another Member State, intended for the armed forces of any State which is a party to the North Atlantic Treaty, other than the Member State of destination itself, for the use of those forces, or of the civilian staff accompanying them, or for supplying their messes or canteens when such forces take part in the common defence effort.'),
			'VATEX-EU-151-1E' => array('reason' => 'Exempt based on article 151, section 1 (e) of Council Directive 2006/112/EC', 'details' => 'The supply of goods or services to the armed forces of the United Kingdom stationed in the island of Cyprus pursuant to the Treaty of Establishment concerning the Republic of Cyprus, dated 16 August 1960, which are for the use of those forces, or of the civilian staff accompanying them, or for supplying their messes or canteens.'),
			'VATEX-EU-159' => array('reason' => 'Exempt based on article 159 of Council Directive 2006/112/EC', 'details' => 'Exemptions for services linked to supplies of goods intended to be placed under customs warehouses, warehouses other than customs warehouses and similar arrangements.'),
			'VATEX-EU-309' => array('reason' => 'Exempt based on article 309 of Council Directive 2006/112/EC', 'details' => 'Travel agents performed outside of EU.'),
			'VATEX-EU-AE' => array('reason' => 'Reverse charge', 'details' => 'Supports EN 16931-1 rule BR-AE-10 - Only use with VAT category code AE'),
			'VATEX-EU-D' => array('reason' => 'Intra-Community acquisition from second hand means of transport', 'details' => 'Second-hand means of transport - Indication that VAT has been paid according to the relevant transitional arrangements - Only use with VAT category code E'),
			'VATEX-EU-F' => array('reason' => 'Intra-Community acquisition of second hand goods', 'details' => 'Second-hand goods - Indication that the VAT margin scheme for second-hand goods has been applied. - Only use with VAT category code E'),
			'VATEX-EU-G' => array('reason' => 'Export outside the EU', 'details' => 'Supports EN 16931-1 rule BR-G-10 - Only use with VAT category code G'),
			'VATEX-EU-I' => array('reason' => 'Intra-Community acquisition of works of art', 'details' => 'Works of art - Indication that the VAT margin scheme for works of art has been applied. - Only use with VAT category code E'),
			'VATEX-EU-IC' => array('reason' => 'Intra-Community supply', 'details' => 'Supports EN 16931-1 rule BR-IC-10 - Only use with VAT category code K'),
			'VATEX-EU-O' => array('reason' => 'Not subject to VAT', 'details' => 'Supports EN 16931-1 rule BR-O-10 - Only use with VAT category code O'),
			'VATEX-EU-J' => array('reason' => 'Intra-Community acquisition of collectors items and antiques', 'details' => 'Collectors\' items and antiques - Indication that the VAT margin scheme for collector’s items and antiques has been applied. - Only use with VAT category code E'),
			'VATEX-FR-FRANCHISE' => array('reason' => 'France domestic VAT franchise in base', 'details' => 'For domestic invoicing in France'),
			'VATEX-FR-CNWVAT' => array('reason' => 'France domestic Credit Notes without VAT, due to supplier forfeit of VAT for discount', 'details' => 'For domestic Credit Notes only in France'),
			'VATEX-EU-153' => array('reason' => 'Exempt based on article 153 of Council Directive 2006/112/EC', 'details' => ''),
			'VATEX-FR-CGI261-1' => array('reason' => 'Exempt based on 1 of article 261 of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI261-2' => array('reason' => 'Exempt based on 2 of article 261 of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI261-3' => array('reason' => 'Exempt based on 3 of article 261 of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI261-4' => array('reason' => 'Exempt based on 4 of article 261 of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI261-5' => array('reason' => 'Exempt based on 5 of article 261 of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI261-7' => array('reason' => 'Exempt based on 7 of article 261 of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI261-8' => array('reason' => 'Exempt based on 8 of article 261 of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI261A' => array('reason' => 'Exempt based on article 261 A of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI261B' => array('reason' => 'Exempt based on article 261 B of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI261C-1' => array('reason' => 'Exempt based on 1° of article 261 C of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI261C-2' => array('reason' => 'Exempt based on 2° of article 261 C of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI261C-3' => array('reason' => 'Exempt based on 3° of article 261 C of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI261D-1' => array('reason' => 'Exempt based on 1° of article 261 D of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI261D-1BIS' => array('reason' => 'Exempt based on 1°bis of article 261 D of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI261D-2' => array('reason' => 'Exempt based on 2° of article 261 D of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI261D-3' => array('reason' => 'Exempt based on 3° of article 261 D of the Code Général des Impôts (CGI ; General tax code) Exonération de TVA - Article 261 D-3° du Code Général des Impôts', 'details' => ''),
			'VATEX-FR-CGI261D-4' => array('reason' => 'Exempt based on 4° of article 261 D of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI261E-1' => array('reason' => 'Exempt based on 1° of article 261 E of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI261E-2' => array('reason' => 'Exempt based on 2° of article 261 E of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI277A' => array('reason' => 'Exempt based on article 277 A of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI275' => array('reason' => 'Exempt based on article 275 of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-298SEXDECIESA' => array('reason' => 'Exempt based on article 298 sexdecies A of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-CGI295' => array('reason' => 'Exempt based on article 295 of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-FR-AE' => array('reason' => 'Exempt based on 2 of article 283 of the Code Général des Impôts (CGI ; General tax code)', 'details' => ''),
			'VATEX-EU-135-1' => array('reason' => 'Exempt based on article 135, section 1 of Council Directive 2006/112/EC', 'details' => ''),
		);

		$exemptionReason = null;		// BT-120
		$exemptionReasonCode = null;	// BT-121 - Must contain a VATEX code. https://docs.peppol.eu/poacc/billing/3.0/codelist/vatex/

		if ($vat_rate > 0) {
			$categoryVAT = 'S';

			if (empty($seller->tva_intra)) {
				throw new Exception('BADVATNUMBER: The VAT number of the thirdparty ' . $buyer->thirdparty->name . ' is mandatory when there is a non null VAT on at least on line.');
			}
			if (!$this->checkIfVatRateIsValid($vat_rate, $seller->country_code)) {
				throw new Exception('BADVATRATE[BR-FR-16]: The VAT rate ' . $vat_rate . ($id ? ' on line ' . $id : '') . ' is not a valid string value for country ' . $seller->country_code . '.');
			}
		} else {
			if ($seller->isInEEC()) {
				$categoryVAT = 'K';

				if (empty($seller->tva_assuj)) {
					// Can be $categoryVAT = E (VAT exempted) or AE (Autoliquidation)
					if (1 == 2) {	// Autoliquidation (the VAT is declared by the customer that pay it directly to the government). TODO Not implemented.
						// Note: the option ACCOUNTING_FORCE_ENABLE_VAT_REVERSE_CHARGE is for purchase invoices only and is used to dispatch vat differently in accounting..
						$categoryVAT = 'AE';	// Autoliquidation
						$exemptionReasonCode = 'VATEX-'.($seller->country_code == 'FR' ? 'FR' : 'EU').'-AE';	// VATEX-EU-AE or VATEX-FR-AE
						$exemptionReason = 'Autoliquidation';
					} else {
						$categoryVAT = 'E';		// Exempt from VAT (self-entrepreneurs, doctor, ...)
						$exemptionReasonCode = getDolGlobalString('MAIN_INFO_SOCIETE_VAT_EXEMPTION_CODE');
						$exemptionReason = getDolGlobalString('MAIN_INFO_SOCIETE_VAT_EXEMPTION_REASON');
						if ($seller->country_code == 'FR') {
							// List of VATEX: https://docs.peppol.eu/poacc/billing/3.0/codelist/vatex/
							// TVA non applicable article 293B CGI (auto-entrepreneurs, volume sous seuil, defined into setup of company):   VATEX-FR-FRANCHISE (rule BR-FR-CO-16), the default one
							$exemptionReasonCode = getDolGlobalString('MAIN_INFO_SOCIETE_VAT_EXEMPTION_CODE', 'VATEX-FR-FRANCHISE');		// VATEX-FR-FRANCHISE, VATEX-FR-CGI261-1, VATEX-FR-CGI261-4, VATEX-EU-79-C...
							$exemptionReason = getDolGlobalString('MAIN_INFO_SOCIETE_VAT_EXEMPTION_REASON', 'Tax exempted - TVA en franchise');
						}
						if (empty($exemptionReasonCode)) {
							if ((float) DOl_VERSION < 24.0) {
								throw new Exception('MISSINGSETUP: Your organization is configured to not use VAT. In this case, you must enter into the constant MAIN_INFO_SOCIETE_VAT_EXEMPTION_CODE the reason code of exemption (VATEX-FR-CGI261-1, VATEX-FR-CGI261-4, VATEX-EU-79C.');
							} else {
								throw new Exception('MISSINGSETUP: Your organization is configured to not use VAT. In this case, you must enter into the reason code of exemption in the setup of your organization (VATEX-FR-CGI261-1, VATEX-FR-CGI261-4, VATEX-EU-79C.');
							}
						}
					}
				} elseif (!$buyer->thirdparty->isInEEC()) {
					$categoryVAT = 'G';
					$exemptionReasonCode = 'VATEX-EU-G';
					$exemptionReason = 'Exportation outside UE';
				} elseif ($buyer->thirdparty->isInEEC() && $seller->country_code != $buyer->thirdparty->country_code) {
					$categoryVAT = 'K';		// Intra communautary VAT
					$exemptionReasonCode = 'VATEX-EU-IC';
					$exemptionReason = 'Intracommunautary VAT';
				} else {
					$categoryVAT = 'E';		// Exempt from VAT (product).

					// The sell is from an EU country to the same country, reason depends on the product line itself (reason saved into the VAT rate/code used)
					if ((float) DOL_VERSION < 24.0) {
						// We must use the reason found in the constant MAIN_VAT_EXEMPTION_CODE_FOR_0.00_XXXX
						// List of VATEX: https://docs.peppol.eu/poacc/billing/3.0/codelist/vatex/
						// TVA non applicable article 261-4 CGI (nature non soumis à TVA, comme médecin): VATEX-FR-CGI261-4
						// TVA non applicable - Vente objet art :       VATEX-FR-I
						// TVA non applicable - Vente objet antiquité : VATEX-FR-J
						// TVA non applicable - Vente agence voyage:    VATEX-EU-D
						// TVA non applicable - Debours (VAT paid by customer):  VATEX-EU-79-C
						$vatex = '';

						// We try to find code in the vat code definition in the dictionary table (code only because einvoice_vatex does not exists).
						global $db, $mysoc;

						$sql = "SELECT code FROM ".MAIN_DB_PREFIX."c_tva";
						$sql .= " WHERE taux = ".((float) $vat_rate);
						$sql .= " AND active = 1";
						$sql .= " AND fk_pays = ".((int) $mysoc->country_id);
						$sql .= " AND (code = '".$db->escape($vat_src_code)."')";
						$resql = $db->query($sql);
						if ($resql) {
							$obj = $db->fetch_object($resql);
							if ($obj) {
								if (preg_match('/^VATEX/i', $obj->code)) {
									$vatex = strtoupper((string) $obj->code);
								}
							}
						}

						$vat_rate = price2num($vat_rate, 2);

						if (empty($vatex)) {
							$constantforvatex = "MAIN_VAT_EXEMPTION_CODE_FOR_" . $vat_rate.($vat_src_code ? "_". $vat_src_code : '');
							$vatex = strtoupper(getDolGlobalString($constantforvatex));
						}

						if (empty($vatex)) {
							$errormsg = $langs->trans("UnknownVATEX1", $id, '0', $vat_src_code);
							$errormsg .= '<br>'.$langs->trans("UnknownVATEX2a", '0', ($vat_src_code ? $vat_src_code : "''"), $constantforvatex);
							//$exemptionReason .= ' '.$langs->trans("ClickHere", $constantforvatex);		// Go on other setup page

							throw new Exception('MISSINGSETUP: '.$errormsg);
						} else {
							$exemptionReasonCode = $vatex;
							$exemptionReason = '';
						}
					} else {
						$vatex = '';

						// We try to find code in the vat code definition in the dictionary table (einvoice_vatex else code).
						global $db, $mysoc;

						$sql = "SELECT code, einvoice_vatex FROM ".MAIN_DB_PREFIX."c_tva";
						$sql .= " WHERE taux = ".((float) $vat_rate);
						$sql .= " AND active = 1";
						$sql .= " AND fk_pays = ".((int) $mysoc->country_id);
						$sql .= " AND (code = '".$db->escape($vat_src_code)."')";
						$resql = $db->query($sql);
						if ($resql) {
							$obj = $db->fetch_object($resql);
							if ($obj) {
								$vatex = strtoupper((string) $obj->einvoice_vatex);
								if (empty($vatex) && preg_match('/^VATEX/i', $obj->code)) {
									$vatex = strtoupper((string) $obj->code);
								}
							}
						}

						if (empty($vatex)) {
							$urltovatdic = DOL_URL_ROOT.'/admin/dict.php?id=10';
							$errormsg = $langs->trans("UnknownVATEX1", $id, '0', $vat_src_code);
							$errormsg .= '<br>'.$langs->trans("UnknownVATEX2b", '0', ($vat_src_code ? $vat_src_code : "''"), $urltovatdic);
							//$errormsg .= ' '.$langs->trans("ClickHere", $constantforvatex);		// Go on dictionary page

							throw new Exception('MISSINGSETUP: '.$errormsg);
						} else {
							$exemptionReasonCode = $vatex;
							$exemptionReason = '';
						}
					}
				}
			} else {
				$categoryVAT = 'Z';		// Seller is not in EU
			}
		}

		// If we have a code but no reason, we try to find the reason in the list of VATEX codes, otherwise we use the code as reason.
		$exemptionReason = $exemptionReason ?: ($VATEX_CODE_LIST[(string) $exemptionReasonCode]['reason'] ?? $exemptionReasonCode);

		return array('categoryVAT' => $categoryVAT, 'ExemptionReason' => $exemptionReason, 'ExemptionReasonCode' => $exemptionReasonCode);
	}


	/**
	 *    Check line type from external module ?
	 *
	 * @param  object $line       line we work on
	 * @param  string $element    line object element (for special case like shipping)
	 * @param  string $searchName module name we look for
	 * @return boolean                        true if the line is a special one and was created by the module we ask for
	 ************************************************/
	private function _isLineFromExternalModule($line, $element, $searchName)
	{
		global $db;
		if ($element == 'shipping' || $element == 'delivery') {
			$fk_origin_line = $line->fk_origin_line;
			$line = new OrderLine($db);
			$line->fetch($fk_origin_line);
		}
		if ((int) $line->product_type != 9) {
			return false;
		}
		// Legacy: line created by the given external module, matched on its special_code.
		if ($line->special_code == $this->_getModNumber($searchName)) {
			return true;
		}
		// The title / subtotal feature is now part of the Dolibarr core (htdocs/subtotals, trait
		// CommonSubtotal with $PRODUCT_TYPE = 9 and special_code = SUBTOTALS_SPECIAL_CODE). Such lines
		// no longer carry the legacy modSubtotal module number, but any product_type 9 line is a
		// title / subtotal / page-break pseudo-line, so we treat them all as subtotal lines.
		return $searchName == 'modSubtotal';
	}

	/**
	 * Find module number
	 *
	 * @param  string 	$modName 	Module name we look for
	 * @return integer              -1 if KO, 0 not found or module number if Ok
	 */
	private function _getModNumber($modName)
	{
		global $db;
		if (class_exists($modName)) {
			$objMod = new $modName($db);
			return $objMod->numero;
		}
		return 0;
	}
}
