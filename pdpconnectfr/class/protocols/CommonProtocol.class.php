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
 * \file    pdpconnectfr/class/protocols/CommonProtocol.class.php
 * \ingroup pdpconnectfr
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

		// TODO Manage the credit note of a deposit invoice

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
	 * Synchronize or create a Dolibarr thirdparty based on E-invoice seller information.
	 *
	 * @param array     $sellerInfo 	Array containing seller information extracted from E-invoice
	 * @param string    $priority 		Fill priority ('dolibarr' or 'pdp'). If both data are available, which one to prefer
	 * @param string    $flowId 		Flow identifier source of the thirdparty.
	 * @return array{res:int, message:string, actioncode:string|null, actionurl:string|null, action:string|null}   Returns array with 'res' (ID of the synchronized or created thirdparty, -1 on error) with a 'message' and an optional 'actioncode', 'actionurl', and 'action'.
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
		global $db, $langs, $user;
		require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

		$thirdparty = new Societe($db);
		$pdpconnectfr = new PdpConnectFr($db);
		$thirdpartyId = -1;

		$sellerCountryCode = $sellerInfo['sellercountry'] ?? '';

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
				$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe WHERE REPLACE(tva_intra, ' ', '') = '" . $db->escape($pdpconnectfr->removeSpaces($sellerInfo['sellerTaxRegistations']['VA'])) . "'";
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
				$thirdpartyId = $thirdparty->id;
				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Found thirdparty by findNearest: ' . $thirdpartyId);
			}
		}

		// Step 3: Create or update thirdparty

		//$thirdpartyId = -2; // For testing

		// if found, update information
		if ($thirdpartyId > 0) {
			// if complete info is disabled, we return directly the thirdpartyId
			if (getDolGlobalInt('PDPCONNECTFR_THIRDPARTIES_COMPLETE_INFO')) {
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
								$thirdparty->$idprofField = $pdpconnectfr->removeSpaces($globalId);
							}
						}
					}
				}
				if (!empty($sellerInfo['sellerTaxRegistations']['VA'])) {
					$thirdparty->tva_intra = $pdpconnectfr->removeSpaces($sellerInfo['sellerTaxRegistations']['VA']);
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
								$thirdparty->$idprofField = $pdpconnectfr->removeSpaces($globalId);
							}
						}
					}
				}
				if (!empty($sellerInfo['sellerTaxRegistations']['VA']) && empty($thirdparty->tva_intra)) {
					$thirdparty->tva_intra = $pdpconnectfr->removeSpaces($sellerInfo['sellerTaxRegistations']['VA']);
					$thirdparty->tva_assuj = 1;
				}
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
		if ($thirdpartyId < 0 && getDolGlobalInt('PDPCONNECTFR_THIRDPARTIES_AUTO_GENERATION')) {
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
							$thirdparty->$idprofField = $pdpconnectfr->removeSpaces($globalId);
						}
					}
				}
			}

			if (!empty($sellerInfo['sellerTaxRegistations']['VA'])) {
				$thirdparty->tva_intra = $pdpconnectfr->removeSpaces($sellerInfo['sellerTaxRegistations']['VA']);
				$thirdparty->tva_assuj = 1;
			}

			// Set as supplier
			$thirdparty->fournisseur = 1;
			$thirdparty->code_fournisseur = 'auto';

			$result = $thirdparty->create($user);
			if ($result > 0) {
				$thirdpartyId = $thirdparty->id;

				// Add entry in pdpconnectfr_extlinks table to mark that this thirdparty is imported from PDP
				$pdpconnectfr->insertOrUpdateExtLink($thirdpartyId, $thirdparty->element, $flowId);

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
			$createUrl .= '&backtopage=' . urlencode(dol_buildpath('/pdpconnectfr/document_list.php', 1));

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

		$pdpconnectfr = new PdpConnectFr($db);

		// Search in product supplier prices table using prodsellerid
		$sql = "SELECT p.rowid ";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product as p ";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product_fournisseur_price as pfp ON pfp.fk_product = p.rowid ";
		$sql .= " WHERE pfp.product_supplier_id = '" . $db->escape($lineData['prodsellerid']) . "' ";
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
		$resFetchP = $pdpconnectfr->fetchDefaultRouting($lineData['supplierId'], 'product');
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
		if (getDolGlobalInt('PDPCONNECTFR_PRODUCTS_AUTO_GENERATION')) {
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
			$product->import_key = AbstractPDPProvider::$PDPCONNECTFR_LAST_IMPORT_KEY; // It does not work here, so we will update it after creation
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

				// Add entry in pdpconnectfr_extlinks table to mark product as created from e-invoice
				$pdpconnectfr->insertOrUpdateExtLink($productId, $product->element, $flowId);

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

			$errorDetails = [];
			$createParams = [];
			$actiondata = ['ref' => $prodRef, 'supplierref' => $prodSupplierRef, 'name' => $prodName];

			if (!empty($prodRef) && $prodRef !== "0000") {
				$errorDetails[] = 'Ref: '.$prodRef;

				$createParams['ref'] = 'EI-' . dol_sanitizeFileName(!empty($lineData['prodsellerid'] && $lineData['prodsellerid'] !== "0000") ? $lineData['prodsellerid'] : uniqid());

				$createParams['ref_ext'] = $prodRef;
			}
			if (!empty($prodSupplierRef)) {
				$errorDetails[] = 'Supplier ref: ' . $prodSupplierRef;
				$createParams['supplierref'] = $prodSupplierRef;
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
			$createUrl .= '&backtopage=' . urlencode(dol_buildpath('/pdpconnectfr/document_list.php', 1));

			$detailsStr = !empty($errorDetails) ? ' [' . implode(' - ', $errorDetails) . ']' : '';

			$message = 'Unable to find product' . $detailsStr . '. Auto-creation of products is disabled in settings.';

			$action = $langs->trans('CreateProductManually') . ' ';
			$action .= '<a class="butAction small" href="' . dol_escape_htmltag($createUrl) . '" target="_blank">';
			$action .= '<i class="fas fa-plus-circle"></i> ';
			$action .= $langs->trans('CreateProduct');
			$action .= '</a>';

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
					// Can be $categoryVAT = E or AE
					if (1 == 2) {	// Autoliquidation (the VAT is declared by the customer that pay it directly to the government). TODO Not yet managed.
						$categoryVAT = 'AE';	// Autoliquidation
						$exemptionReasonCode = 'VATEX-'.($seller->country_code == 'FR' ? 'FR' : 'EU').'-AE';	// VATEX-EU-AE or VATEX-FR-AE
						$exemptionReason = 'Autoliquidation';
					} else {
						$categoryVAT = 'E';		// Exempt from VAT (self-entrepreneurs, doctor, ...)
						$exemptionReasonCode = getDolGlobalString('MAIN_INFO_SOCIETE_VAT_EXEMPTION_CODE');
						$exemptionReason = getDolGlobalString('MAIN_INFO_SOCIETE_VAT_EXEMPTION_REASON');
						if ($seller->country_code == 'FR') {
							// List of VATEX: https://docs.peppol.eu/poacc/billing/3.0/codelist/vatex/
							// TVA non applicable article 293B CGI (auto-entrepreneurs, volume sous seuil): VATEX-FR-FRANCHISE (rule BR-FR-CO-16)
							// TVA non applicable article 261-4 CGI (nature non soumis à TVA, comme médecin): VATEX-FR-CGI261-4
							// TVA non applicable - Vente objet :  art = VATEX-FR-I, antiquité = VATEX-FR-J
							// TVA non applicable - Vente agence voyage:  VATEX-EU-D
							$exemptionReasonCode = getDolGlobalString('MAIN_INFO_SOCIETE_VAT_EXEMPTION_CODE', 'VATEX-FR-FRANCHISE');		// VATEX-FR-FRANCHISE, VATEX-FR-CGI261-1, VATEX-FR-CGI261-4, ...
							$exemptionReason = getDolGlobalString('MAIN_INFO_SOCIETE_VAT_EXEMPTION_REASON', 'Tax exempted');
						}
						if (empty($exemptionReasonCode)) {
							if ((float) DOl_VERSION < 24.0) {
								throw new Exception('MISSINGSETUP: Your organization is configured to not use VAT. In this case, you must enter into the constant MAIN_INFO_SOCIETE_VAT_EXEMPTION_CODE the reason code of exemption (VATEX-FR-CGI261-4, VATEX-FR-CGI261-4.');
							} else {
								throw new Exception('MISSINGSETUP: Your organization is configured to not use VAT. In this case, you must enter into the reason code of exemption in the setup of your organization (VATEX-FR-CGI261-4, VATEX-FR-CGI261-4.');
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
					// The sell is from an EU country to the same country, reason depends on the line itself (on the vat race/code used)
					if ((float) DOL_VERSION < 24.0) {
						// We must use the reason found in the constant MAIN_VAT_EXEMPTION_CODE_FOR_0.00_XXXX
						$vat_rate = price2num($vat_rate, 2);
						$constantforvatex = "MAIN_VAT_EXEMPTION_CODE_FOR_" . $vat_rate.($vat_src_code ? "_". $vat_src_code : '');
						$vatex = getDolGlobalString($constantforvatex);

						if (empty($vatex)) {
							$errormsg = $langs->trans("UnknownVATEX1", $id, '0', $vat_src_code);
							$errormsg .= ' '.$langs->trans("UnknownVATEX2a", $constantforvatex);
							//$exemptionReason .= ' '.$langs->trans("ClickHere", $constantforvatex);		// Go on other setup page

							throw new Exception('MISSINGSETUP: '.$errormsg);
						} else {
							$exemptionReasonCode = $vatex;
							$exemptionReason = '';
						}
					} else {
						// We must use the reason found on the vat code definition in the dictionnary table.
						global $db, $mysoc;
						$sql = "SELECT einvoice_vatex FROM ".MAIN_DB_PREFIX."c_tva";
						$sql .= " WHERE taux = ".((float) $vat_rate);
						$sql .= " AND active = 1";
						$sql .= " AND fk_pays = '".$db->escape($mysoc->country_code)."'";
						$sql .= " AND code = '".$db->escape($vat_src_code)."'";
						$resql = $db->query($sql);
						if ($resql) {
							$obj = $db->fetch_object($resql);
							if ($obj) {
								$exemptionReasonCode = $obj->einvoice_vatex;
							}
						}

						if (empty($vatex)) {
							$errormsg = $langs->trans("UnknownVATEX1", $id, '0', $vat_src_code);
							$errormsg .= ' '.$langs->trans("UnknownVATEX2b", $constantforvatex);
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
}
