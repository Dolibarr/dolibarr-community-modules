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

dol_include_once('einvoicing/class/providers/AbstractPDPProvider.class.php');

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

	/************************************************
	 * Find paymentMean number
	 *
	 * @param  object 	$invoice 			object name we look for
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

		// Step 3: Create or update thirdparty

		//$thirdpartyId = -2; // For testing

		// if found, update information
		if ($thirdpartyId > 0) {
			// if complete info is disabled, we return directly the thirdpartyId
			if (getDolGlobalInt('EINVOICING_THIRDPARTIES_COMPLETE_INFO')) {
				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Complete info disabled, returning existing thirdparty: ' . $thirdpartyId);
				return array(
					'res' => $thirdpartyId,
					'message' => 'Existing thirdparty used without update: ' . $thirdpartyId
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
					'message' => 'Thirdparty ' . $thirdparty->name . ' updated successfully.'
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
				$errorDetails[] = $langs->trans('ErrorDetailsVendorId') . ' : ' . $vendorId;
				$createParams['socid'] = $vendorId;							// TODO Dolibarr must be able to handle this parameter
			}
			if (!empty($prodSupplierRef)) {
				$errorDetails[] = $langs->trans('ErrorDetailsSupplierRef') .' : ' . $prodSupplierRef;
				$createParams['supplierref'] = $prodSupplierRef;			// TODO Dolibarr must be able to handle this parameter
			}
			if (!empty($prodName)) {
				$errorDetails[] = $langs->trans('Name') . ' : ' . $prodName;
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

			$message = $langs->trans('ErrorUnableToFindProduct', $detailsStr);

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
				'action' => $action,
				'actiondata' => $actiondata
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

		$exemptionReason = null;		// BT-120
		$exemptionReasonCode = null;	// BT-121 - Mut contains a VATEX code. https://docs.peppol.eu/poacc/billing/3.0/codelist/vatex/

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

						// We try to find code in the vat code definition in the dictionnary table (code only because einvoice_vatex does not exists).
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

						// We try to find code in the vat code definition in the dictionnary table (einvoice_vatex else code).
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

		return array('categoryVAT' => $categoryVAT, 'ExemptionReason' => $exemptionReason, 'ExemptionReasonCode' => $exemptionReasonCode);
	}


	/************************************************
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
		if ($line->product_type == 9 && $line->special_code == $this->_getModNumber($searchName)) {
			return true;
		} else {
			return false;
		}
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

	/**
	 * Remove attachment nodes to get a smaller XML
	 * @param string $xmlData The XML data to process
	 * @return string Cleaned XML
	 */
	public static function removeAttachmentFromXml(string $xmlData): string
	{
		$xmlDoc = new DOMDocument();
		if (!$xmlDoc->loadXML($xmlData)) {
			throw new Exception(__METHOD__ . " : failed to load XML data");
		}

		// Remove AttachedDocument nodes
		$xpath = new DOMXPath($xmlDoc);
		// Voluntary use non namespace specific path (to not have to manage different CII namespaces)
		$attachedDocumentNodes = $xpath->query('//*[local-name()="AdditionalReferencedDocument"]/*[local-name()="AttachmentBinaryObject"]');

		if (count($attachedDocumentNodes) >= 1) {
			foreach ($attachedDocumentNodes as $attachedDocumentNode) {
				// Just replace node value
				$attachedDocumentNode->nodeValue = '[Removed to get a smaller XML]';
				// Or completely remove node if you prefer :
				// if ($attachedDocumentNode && isset($attachedDocumentNode->parentNode)) {
				// 	$attachedDocumentNode->parentNode->removeChild($attachedDocumentNode);
				// }
			}
			return $xmlDoc->saveXML();
		}

		return $xmlData;
	}

	/**
	 * Add lines to the supplier invoice from CII xml lines.
	 *
	 * @param  FactureFournisseur 			$supplierInvoice        Supplier invoice object
	 * @param  array				 		$parsedLines        	The list of lines we want to add to the supplier invoice
	 * @param  int 							$flowId                 Flow identifier source of the invoice.
	 * @return array{res:int, message:string, action:string|null}   Returns array with 'res' (1 on success, 0 already exists, -1 on failure) with a 'message' and an optional 'action'.
	 */
	public function createSupplierInvoiceLinesFromSource(&$supplierInvoice, $parsedLines, $flowId = '', $params = []): array
	{
		global $db, $hookmanager, $langs, $user;

		$reshook = $hookmanager->executeHooks('createSupplierInvoiceLinesFromSource', ['parsed_lines' => $parsedLines, 'flow_id' => $flowId, 'params' => $params], $supplierInvoice);

        if (!empty($reshook)) {
			return [
				'res' => $reshook
			];
        }

		$freeLines = (isset($params['free_lines']) && $params['free_lines'] == true);
		$targetFkProduct = (isset($params['target_fk_product']) ? $params['target_fk_product'] : 0);

		// Add invoice lines
		foreach ($parsedLines as $parsedLine) {
			// Add supplier ID to line for later use in product sync
			$parsedLine['supplierId'] = $supplierInvoice->socid;

			$is_deposit_line = 0;
			$fk_remise = 0;
			// --------------------------------------------------
			// Loop on linked documents at line level
			// --------------------------------------------------
			if (!empty($parsedLine['additionalRefDocs']) && is_array($parsedLine['additionalRefDocs'])) {
				foreach ($parsedLine['additionalRefDocs'] as $refDoc) {
					$lineRefDocId = $refDoc['IssuerAssignedID'] ?? null;
					$lineRefDocType = $refDoc['typeCode'] ?? null;
					$lineRefDocDate = $refDoc['issueDate'] ?? null;

					$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE ref_supplier = '" . $db->escape($lineRefDocId) . "' LIMIT 1";
					$resql = $db->query($sql);
					if ($db->num_rows($resql) != 1) {
						return [
							'res' => -1,
							'message' => 'Document "' . $lineRefDocId . '" linked to line ' . $parsedLine['lineid'] . ' was not found in Dolibarr. Please verify why this document is missing (deleted, not imported, or not provided by the supplier). To resolve this issue, you must manually create the invoice using the supplier invoice reference "' . $lineRefDocId . '".'
						];
						// TODO: Add a check before sending a final invoice after deposit to ensure that the deposit invoice has been properly sent to the PDP and successfully received.
					}

					// Load linked supplier invoice
					$linkedObject = new FactureFournisseur($db);
					$linkedObjectId = $db->fetch_object($resql)->rowid;
					$resFetchLinkedObject = $linkedObject->fetch($linkedObjectId);
					if ($resFetchLinkedObject > 0) {
						/*
						* --------------------------------------------------
						* Deposit handling
						* --------------------------------------------------
						* Deposits may be referenced:
						*  - at document level
						*  - at line level
						*
						* If the deposit is referenced at line level:
						*   → we create the discount before creating the invoice line,
						*     so it can be linked later.
						*
						* If the same deposit appears both at line and document level:
						*    line-level handling takes priority to avoid duplicates.
						*
						* If the deposit exists only at document level:
						*   → a discount line will be created later after all invoice
						*     lines are generated.
						*/
						if ($linkedObject->type == FactureFournisseur::TYPE_DEPOSIT) {
							$is_deposit_line = 1;

							// Check if deposit line is already converted to a reduction otherwise we convert it
							//require_once DOL_DOCUMENT_ROOT.'/core/class/discount.class.php';
							$discountcheck = new DiscountAbsolute($db);
							$result = $discountcheck->fetch(0, 0, $linkedObject->id);
							if ($result <= 0) {
								// Loop on each vat rate
								$amount_ht = $amount_tva = $amount_ttc = array();
								$multicurrency_amount_ht = $multicurrency_amount_tva = $multicurrency_amount_ttc = array();
								$i = 0;
								foreach ($linkedObject->lines as $line) {
									if ($line->product_type < 9 && $line->total_ht != 0) { // Remove lines with product_type greater than or equal to 9 and no need to create discount if amount is null
										$keyforvatrate = $line->tva_tx . ($line->vat_src_code ? ' (' . $line->vat_src_code . ')' : '');

										$amount_ht[$keyforvatrate] += $line->total_ht;
										$amount_tva[$keyforvatrate] += $line->total_tva;
										$amount_ttc[$keyforvatrate] += $line->total_ttc;
										$multicurrency_amount_ht[$keyforvatrate] += $line->multicurrency_total_ht;
										$multicurrency_amount_tva[$keyforvatrate] += $line->multicurrency_total_tva;
										$multicurrency_amount_ttc[$keyforvatrate] += $line->multicurrency_total_ttc;
										$i++;
									}
								}

								$discount = new DiscountAbsolute($db);
								$discount->description = '(DEPOSIT)';
								$discount->discount_type = 1; // Supplier discount
								$discount->fk_soc = $linkedObject->socid;
								$discount->socid = $linkedObject->socid;
								$discount->fk_invoice_supplier_source = $linkedObject->id;
								foreach ($amount_ht as $tva_tx => $xxx) {
									$discount->amount_ht = abs((float) $amount_ht[$tva_tx]);
									$discount->amount_tva = abs((float) $amount_tva[$tva_tx]);
									$discount->amount_ttc = abs((float) $amount_ttc[$tva_tx]);
									$discount->multicurrency_amount_ht = abs((float) $multicurrency_amount_ht[$tva_tx]);
									$discount->multicurrency_amount_tva = abs((float) $multicurrency_amount_tva[$tva_tx]);
									$discount->multicurrency_amount_ttc = abs((float) $multicurrency_amount_ttc[$tva_tx]);

									// Clean vat code
									$reg = array();
									$vat_src_code = '';
									if (preg_match('/\((.*)\)/', $tva_tx, $reg)) {
										$vat_src_code = $reg[1];
										$tva_tx = preg_replace('/\s*\(.*\)/', '', $tva_tx); // Remove code into vatrate.
									}

									$discount->tva_tx = abs((float) $tva_tx);
									$discount->vat_src_code = $vat_src_code;

									$result = $discount->create($user);
									if ($result < 0) {
										return ['res' => -1, 'message' => 'Failed to create discount for deposit line: ' . $discount->error];
										break;
									}
									$fk_remise = $result;
								}
							} else {
								// Deposit already converted so reuse existing discount
								$is_deposit_line = 1;
								$fk_remise = $discountcheck->id;
							}
						}

						/*
						* --------------------------------------------------
						* Other linked document types
						* --------------------------------------------------
						* Additional logic may be added here for other
						* document types such as credit notes, etc.
						*/
					} else {
						return ['res' => -1, 'message' => 'Document : ' . $lineRefDocId . ' linked to line ' . $parsedLine['lineid'] . ' not found in Dolibarr'];
					}
				}
			}

			$productId = 0;
			if (!$is_deposit_line && !$freeLines) {
				// Sync or create product
				$res = $this->_findOrCreateProductFromEinvoiceLine($parsedLine, $flowId);
				$return_messages[] = $res['message'];
				if ($res['res'] < 0) {
					if ($targetFkProduct > 0) {
						$productId = $targetFkProduct;
					} else {
						return [
							'res' => -1,
							'message' => $langs->trans('ErrorProductSyncOrCreationFailed') . ' ' . implode("<br>\n", $return_messages),
							'actioncode' => $res['actioncode'] ?? '',
							'actionurl' => $res['actionurl'] ?? '',
							'action' => $res['action'] ?? null,
							'actiondata' => $res['actiondata'] ?? ''
						];
					}
				} elseif ($targetFkProduct > 0) {
						$productId = $targetFkProduct;
				} else {
					$productId = $res['res'];
				}
			}

			// Add line to invoice
			$line = new SupplierInvoiceLine($db);
			//$line->desc = $prodname . (!empty($proddesc) ? "\n" . $proddesc : '');
			if (!empty($productId)) {
				$line->fk_product = $productId;
			}
			if ($is_deposit_line && !empty($fk_remise)) {
				$line->fk_remise_except = $fk_remise;
				$line->info_bits = 2;
				$line->desc = '(DEPOSIT)';
				$line->rang = -1;

				$remise_already_used_line_level_ids[] = $fk_remise;
			}
			if ($freeLines) {
				$line->description = $parsedLine['prodname'];
			}
			$line->qty = $parsedLine['billedquantity'];
			$line->subprice = $parsedLine['netpriceamount'];
			$line->tva_tx = $parsedLine['rateApplicablePercent'];
			$line->total_ht = $parsedLine['lineTotalAmount'];
			$line->total_tva = $parsedLine['calculatedAmount'] ?? 0;
			$line->total_ttc = $parsedLine['lineTotalAmount'] + ($parsedLine['calculatedAmount'] ?? 0);

			$supplierInvoice->lines[] = $line;
		}

		if (!$this->createSupplierInvoiceLinesIntoDatabase($supplierInvoice)) {
			return [
				'res' => -1,
				'message' => 'Supplier invoice line creation error',
				'actioncode' => $res['actioncode'] ?? '',
				'actionurl' => $res['actionurl'] ?? '',
				'action' => $res['action'] ?? null,
				'actiondata' => $res['actiondata'] ?? ''
			];
		}

		return ['res' => 1];
	}

	/**
	 * Create/insert supplier invoice lines in DB using $lines property of the supplier invoice object
	 * @param FactureFournisseur $supplierInvoice
	 * @return bool
	 */
	public function createSupplierInvoiceLinesIntoDatabase(FactureFournisseur $supplierInvoice): bool
	{
		foreach ($supplierInvoice->lines as $i => $val) {
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'facture_fourn_det (fk_facture_fourn, special_code, fk_remise_except)';
			$sql .= " VALUES (".((int) $supplierInvoice->id).", ".((int) $supplierInvoice->lines[$i]->special_code).", ".($supplierInvoice->lines[$i]->fk_remise_except > 0 ? ((int) $supplierInvoice->lines[$i]->fk_remise_except) : 'NULL').')';

			$resql_insert = $this->db->query($sql);
			if ($resql_insert) {
				$idligne = $this->db->last_insert_id(MAIN_DB_PREFIX.'facture_fourn_det');

				$res = $supplierInvoice->updateline(
					$idligne,
					$supplierInvoice->lines[$i]->desc ? $supplierInvoice->lines[$i]->desc : $supplierInvoice->lines[$i]->description,
					$supplierInvoice->lines[$i]->subprice,
					$supplierInvoice->lines[$i]->tva_tx.($supplierInvoice->lines[$i]->vat_src_code ? ' ('.$supplierInvoice->lines[$i]->vat_src_code.')' : ''),
					$supplierInvoice->lines[$i]->localtax1_tx,
					$supplierInvoice->lines[$i]->localtax2_tx,
					$supplierInvoice->lines[$i]->qty,
					$supplierInvoice->lines[$i]->fk_product,
					'HT',
					(!empty($supplierInvoice->lines[$i]->info_bits) ? $supplierInvoice->lines[$i]->info_bits : ''),
					$supplierInvoice->lines[$i]->product_type,
					$supplierInvoice->lines[$i]->remise_percent,
					0,
					$supplierInvoice->lines[$i]->date_start,
					$supplierInvoice->lines[$i]->date_end,
					$supplierInvoice->lines[$i]->array_options,
					$supplierInvoice->lines[$i]->fk_unit,
					$supplierInvoice->lines[$i]->multicurrency_subprice,
					$supplierInvoice->lines[$i]->ref_supplier
				);
			} else {
				return false;
			}
		}

		return true;
	}

	/**
	 * Parse the invoice header from CII XML.
	 *
	 * Special prefixes in $invoiceTemplate:
	 *   '__MULTI__<xpath>'     → returns array of child node data
	 *   '__ATTRPAIRS__<xpath>' → returns ['schemeID' => 'value', …]
	 *
	 * @param  string $rawContent Raw XML content
	 * @return array<string,mixed>
	 */
	public function parseInvoiceHeader(string $rawContent)
	{
		list(, $xpath) = $this->initXPath($rawContent);

		$data = [];

		foreach ($this->invoiceTemplate as $key => $expr) {
			// Skip PHP-native placeholders
			if (is_array($expr) || $expr === false || $expr === null) {
				$data[$key] = is_array($expr) ? [] : $expr;
				continue;
			}

			// Multi-value nodes
			if (strpos($expr, '__MULTI__') === 0) {
				$realExpr = substr($expr, strlen('__MULTI__'));
				$data[$key] = $this->parseMultiNodes($xpath, $realExpr, $key);
				continue;
			}

			// Attribute-keyed pairs
			if (strpos($expr, '__ATTRPAIRS__') === 0) {
				$realExpr = substr($expr, strlen('__ATTRPAIRS__'));
				$data[$key] = $this->getXPathAttrPairs($xpath, $realExpr);
				continue;
			}

			// Scalar values (including /@attr)
			$data[$key] = $this->getXPathValue($xpath, $expr);
		}

		// Type normalisation
		foreach (['documentdate', 'documentDeliveryDate', 'invoicingPeriodStart', 'invoicingPeriodEnd', 'paymentDueDate'] as $f) {
			if (isset($data[$f]))
				$data[$f] = $this->normDate($data[$f]);
		}
		foreach (['grandTotalAmount', 'duePayableAmount', 'lineTotalAmount', 'chargeTotalAmount', 'allowanceTotalAmount', 'taxBasisTotalAmount', 'taxTotalAmount', 'roundingAmount', 'totalPrepaidAmount'] as $f) {
			if (isset($data[$f]))
				$data[$f] = $this->toFloat($data[$f]);
		}

		return $data;
	}

	/**
	 * Parse all invoice line items from CII XML.
	 *
	 * @param  string $rawContent Raw XML content
	 * @return array<int,array<string,mixed>>
	 */
	public function parseInvoiceLines(string $rawContent): array
	{
		list(, $xpath) = $this->initXPath($rawContent);

		// Grab header documentno once so we can fill parentDocumentNo on each line
		$parentDocNo = $this->getXPathValue(
			$xpath,
			'/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID'
		);

		$lines = [];
		$nodes = $xpath->query('//ram:IncludedSupplyChainTradeLineItem');

		foreach ($nodes as $node) {
			$line = [];

			foreach ($this->lineTemplate as $key => $expr) {
				// PHP-native placeholders
				if (is_array($expr) || $expr === false) {
					$line[$key] = is_array($expr) ? [] : $expr;
					continue;
				}
				if ($key === 'parentDocumentNo') {
					$line[$key] = $parentDocNo;
					continue;
				}
				if ($key === 'is_deposit') {
					$line[$key] = 0;
					continue;
				}
				if ($key === 'fk_remise') {
					$line[$key] = null;
					continue;
				}

				// Multi-value at line level
				if (is_string($expr) && strpos($expr, '__MULTI__') === 0) {
					$realExpr = substr($expr, strlen('__MULTI__'));
					$line[$key] = $this->parseMultiNodes($xpath, $realExpr, $key, $node);
					continue;
				}

				$line[$key] = $this->getXPathValue($xpath, $expr, $node);
			}

			// Type normalisation
			foreach (['linePeriodStart', 'linePeriodEnd'] as $f) {
				if (isset($line[$f]))
					$line[$f] = $this->normDate($line[$f]);
			}
			foreach (['grosspriceamount', 'grosspricebasisquantity', 'netpriceamount', 'netpricebasisquantity', 'billedquantity', 'chargeFreeQuantity', 'packageQuantity', 'lineTotalAmount', 'totalAllowanceChargeAmount', 'rateApplicablePercent', 'calculatedAmount'] as $f) {
				if (isset($line[$f]))
					$line[$f] = $this->toFloat($line[$f]);
			}
			$line['isDepositLine'] = (bool) ($line['isDepositLine'] ?? false);

			$lines[] = $line;
		}

		return $lines;
	}
}
