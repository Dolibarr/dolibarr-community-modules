<?php
/* Copyright (C) 2026       solauv
 * Copyright (C) 2026		MDW	<mdeweerd@users.noreply.github.com>
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
 * \file    einvoicing/class/helpers/SupplierInvoiceHelper.class.php
 * \ingroup einvoicing
 * \brief   Utility class for supplier invoices.
 * 			This file is mainly used when EINVOICING_SUPPLIER_INVOICE_CHECK_CONSISTENCY_ON_VALIDATION is set but
 * 			this option is seriously bugged. Do not use it.
 */

dol_include_once('einvoicing/class/protocols/ProtocolManager.class.php');
dol_include_once('einvoicing/class/document.class.php');
dol_include_once('fourn/class/fournisseur.facture.class.php');

/**
 * Class SupplierInvoiceHelper
 */
class SupplierInvoiceHelper
{
	/**
	 * Close code set on a Dolibarr supplier invoice abandoned because its refusal was confirmed
	 * by the e-invoicing platform (PDP/PA). Distinct from the standard close codes (abandon,
	 * replaced, ...) so it can be reliably excluded from the accountancy transfer screen.
	 */
	public const CLOSECODE_PDPREFUSED = 'pdp_refused';

	/**
	 * Compare amounts according to a number of digit after comma and return true if they are equal.
	 *
	 * @param float $amount1    The first amount to compare
	 * @param float $amount2    The second amount to compare
	 * @param ?int $roundPrecision The number of digits after coma to apply round()
	 * @return bool True if amounts are equal
	 */
	private static function areAmountsEqual($amount1, $amount2, ?int $roundPrecision = null): bool
	{
		if (!isset($roundPrecision)) {
			$roundPrecision = getDolGlobalInt('einvoicing_SUPPLIER_INVOICE_COMPARISON_ROUND_PRECISION', 3);
		}

		return (round($amount1, $roundPrecision) === round($amount2, $roundPrecision));
	}

	/**
	 * Compare a Dolibarr supplier invoice to its related e-invoice and check they are identical
	 * using following criteria :
	 * - Currency
	 * - VAT excl. total
	 * - VAT incl. total
	 * - VAT total
	 * - Basis amount & VAT amount of each VAT rate
	 *
	 * @param FactureFournisseur $dolSupplierInvoice   The Dolibarr object to compare to e-invoice
	 *
	 * @return	array{identical:bool,errors:array}|false
	 */
	public static function checkDolInvoiceAndEInvoiceConsistency(FactureFournisseur $dolSupplierInvoice)
	{
		global $conf, $db, $langs;

		$errors = [];

		// Get supplier invoice XML data
		$xmlData = SupplierInvoiceHelper::getXmlData($dolSupplierInvoice->id);

		// Can't check consistency if there is no XML content
		if (!isset($xmlData) || $xmlData === '') {
			return false;
		}

		// Detect protocol
		$protocolManager = new ProtocolManager($db);
		$detectedProtocolName = $protocolManager->detectProtocolFromContent($xmlData);
		if (!isset($detectedProtocolName)) {
			return false;
		}
		$protocol = $protocolManager->getProtocol($detectedProtocolName);

		// Extract XML header data
		switch ($detectedProtocolName) {
			case 'CII':
				$parsedHeader = $protocol->parseInvoiceHeader($xmlData);
				break;
				// Another format can be added here
			default:
				throw new Exception('Format ' . $detectedProtocolName . ' not available for comparison');
		}

		// Currency
		$currencyCode = $dolSupplierInvoice->multicurrency_code ?? $conf->currency;
		if ($currencyCode != $parsedHeader['invoiceCurrency']) {
			$errors[] = $langs->trans('SupplierInvoiceComparisonCurrencyDifference', $parsedHeader['invoiceCurrency'], $currencyCode);
		}

		// -----------------------------------------------------------------
		// 		Compare amount depending VAT calculation mode 1 & 2
		// -----------------------------------------------------------------

		// Mode 1 : round VAT amount of each line and then sum rounded amounts
		// Mode 2 : sum VAT amount of each line and then round total

		// ? Start transaction to be able to calculate VAT amounts in 2 different modes :
		// ? - do it this way because VAT calculation is directly made in update_price() method which also updates database, but in our case, we don't want to update database
		// ? - our need here is to calculate in mode 1 and mode 2 without to have to rewrite all VAT calculation logic

		/* FIXME Disabled. Generates critical problem. Adding a rollback inside a more global transaction break all workflows. On Postgresql, it also cancel any following commits.
		 * A check to compare data is a readonly operation and should NEVER open transaction and try to modify data.
		 */
		/*
		$db->begin();

		$calculationRules = [
			'current',
			'totalofround',
			'roundoftotal',
		];

		$amountErrors = [];

		foreach ($calculationRules as $calculationRule) {
			if ($calculationRule != 'current') {
				$noDatabaseUpdate = 0;
				$dolSupplierInvoice->update_price(0, (($calculationRule == 'totalofround') ? '0' : '1'), $noDatabaseUpdate, $dolSupplierInvoice->thirdparty);
				$dolSupplierInvoice->fetch($dolSupplierInvoice->id);
			}

			// VAT excl. total
			if (!self::areAmountsEqual(floatval($dolSupplierInvoice->total_ht), $parsedHeader['lineTotalAmount'])) {
				$amountErrors[$calculationRule][] = $langs->trans('SupplierInvoiceComparisonTotalVatExclDifference', $parsedHeader['lineTotalAmount'], floatval($dolSupplierInvoice->total_ht));
			}

			// VAT incl. total
			if (!self::areAmountsEqual(floatval($dolSupplierInvoice->total_ttc), $parsedHeader['grandTotalAmount'])) {
				$amountErrors[$calculationRule][] = $langs->trans('SupplierInvoiceComparisonTotalVatInclDifference', $parsedHeader['grandTotalAmount'], floatval($dolSupplierInvoice->total_ttc));
			}

			// VAT total
			if (!self::areAmountsEqual(floatval($dolSupplierInvoice->total_tva), $parsedHeader['taxTotalAmount'])) {
				$amountErrors[$calculationRule][] = $langs->trans('SupplierInvoiceComparisonTotalVatDifference', $parsedHeader['taxTotalAmount'], floatval($dolSupplierInvoice->total_tva));
			}

			$dolSupplierInvoiceVatDetails = self::getVatDetails($dolSupplierInvoice);
			foreach ($parsedHeader['taxBreakdown'] as $taxDetailsByRate) {
				if ($taxDetailsByRate['typeCode'] === 'VAT') {
					if (array_key_exists((string) $taxDetailsByRate['rateApplicablePercent'], $dolSupplierInvoiceVatDetails)) {
						$dolVatAmount = floatval($dolSupplierInvoiceVatDetails[(string) $taxDetailsByRate['rateApplicablePercent']]['vat_amount']);
						$dolVatBasis   = floatval($dolSupplierInvoiceVatDetails[(string) $taxDetailsByRate['rateApplicablePercent']]['vat_basis_amount']);

						if (!self::areAmountsEqual($dolVatBasis, $taxDetailsByRate['basisAmount'])) {
							$amountErrors[$calculationRule][] = $langs->trans('SupplierInvoiceComparisonVatBasisDifference', $taxDetailsByRate['rateApplicablePercent'], $taxDetailsByRate['basisAmount'], $dolVatBasis);
						}
						if (!self::areAmountsEqual($dolVatAmount, $taxDetailsByRate['calculatedAmount'])) {
							$amountErrors[$calculationRule][] = $langs->trans('SupplierInvoiceComparisonVatRateDifference', $taxDetailsByRate['rateApplicablePercent'], $taxDetailsByRate['calculatedAmount'], $dolVatAmount);
						}
					} else {
						$amountErrors[$calculationRule][] = $langs->trans('SupplierInvoiceComparisonVatRateNotFound', $taxDetailsByRate['rateApplicablePercent']);
					}
				}
			}

			if (count($amountErrors['current']) == 0) {
				break;
			}
		}

		if (count($amountErrors['current']) > 0) {
			$errors = array_merge($errors, $amountErrors['current']);

			if ($amountErrors['current'] == $amountErrors['totalofround'] && count($amountErrors['roundoftotal']) === 0) {
				$errors[] = $langs->trans('SupplierInvoiceComparisonSuggestVatCalculationMode', 2);
			} elseif ($amountErrors['current'] == $amountErrors['roundoftotal'] && count($amountErrors['totalofround']) === 0) {
				$errors[] = $langs->trans('SupplierInvoiceComparisonSuggestVatCalculationMode', 1);
			}
		}

		// Rollback because we don't want to persist in database the changes made by the different calls to update_price() (see comment before the $db->begin() for more details)
		$db->rollback();
		*/

		return [
			'identical' => (count($errors) == 0),
			'errors' => $errors,
		];
	}

	/**
	 * Return VAT details (by VAT rate) from a supplier invoice
	 *
	 * @param FactureFournisseur $supplierInvoice The supplier invoice object
	 * @return array<array{vat_amount: float, vat_basis_amount: float}>
	 */
	public static function getVatDetails(FactureFournisseur $supplierInvoice)
	{
		$vatByRate = array();

		foreach ($supplierInvoice->lines as $line) {
			$rate = (string) price2num($line->tva_tx);

			if (!isset($vatByRate[$rate])) {
				$vatByRate[$rate] = array(
					'vat_basis_amount' => 0,
					'vat_amount' => 0
				);
			}

			$vatByRate[$rate]['vat_basis_amount'] += $line->total_ht;
			$vatByRate[$rate]['vat_amount'] += $line->total_tva;
		}
		return $vatByRate;
	}

	/**
	 * Try to return XML data of a supplier invoice :
	 * - first, try to get data from database
	 * - if data not found in database, try to re-get data from AP
	 *
	 * @param	int 		$supplierInvoiceId 		The id of the supplier invoice
	 * @return 	?string 							The XML data if available or null if can't get it
	 * @throws 	Exception
	 */
	public static function getXmlData(int $supplierInvoiceId): ?string
	{
		global $db, $user;

		$sql = "SELECT rowid, flow_id, provider, xml_data FROM " . MAIN_DB_PREFIX . "einvoicing_document";
		$sql .= " WHERE fk_element_type = '" . $db->escape('invoice_supplier') . "'";
		$sql .= " AND fk_element_id = " . (int) $supplierInvoiceId;
		$sql .= " LIMIT 2";

		$resql = $db->query($sql);
		if ($resql) {
			if ($db->num_rows($resql) == 1) {
				$foundDocument = $db->fetch_object($resql);

				$document = new Document($db);
				$resdoc = $document->fetch($foundDocument->rowid);

				if (empty($resdoc) || is_null($document->xml_data) || $document->xml_data == '') {
					$providerManager = new PDPProviderManager($db);
					$provider = $providerManager->getProvider(strtoupper((string) $document->provider));

					/* FIXME Disabled: Create a lof of regressions and problems:
					- We must never a dependency (like ZugferdDocumentPdfReaderExt) when common use of code does not need it.
					  This introduces regression because lib that does not work on most cases (PHP version, Dolibarr version, ...)
					- To get content of an invoice, message should not use fetchFlowData($document->flow_id, 'Converted'), because
					  result of 'Converted' is not predictable by code, it depends on your AP setup on your account.
					  So we should use code that depends on AP like we have into syncFlow() for SupplierInvoice, with a detection of
					  the type of doc received by using $detectedProtocol = $tmpProtocolManager->detectProtocolFromContent($receivedFile).

					  Solution: Move this method into the provider class.
					*/
					/*
					$flowResponse = $provider->fetchFlowData($document->flow_id, 'Converted', 'get_flow_for_supplier_invoice_by_getxmldata');

					if ($flowResponse['status_code'] != 200) {
						throw new Exception('Failed to get flow data for flow id n° ' . $document->flow_id . ' and for supplier invoice id n° ' . $supplierInvoiceId);
					}

					// $receivedFile may be a CII file (common) or Factur-X file (not common), or ...
					$receivedFile = $flowResponse['response'];

					// FIXME Bug here: $flowResponse['response'] should contains a CII file not a Factur-x file (except if your Provider was not correctly setup).
					// Having a factur-x here happen only if using the not recommended setup (recommended CII, not recommended Factur-x).
					// Note: As it may vary on setup, the type of einvoice must be guessed with "$detectedProtocol = $tmpProtocolManager->detectProtocolFromContent($receivedFile);"
					// so all the code of the getXMLData() should be moved into the provider class and must return always a XML.
					$xmlData = ZugferdDocumentPdfReaderExt::getInvoiceDocumentContentFromContent($receivedFile);
					$cleanedXmlData = Document::cleanXmlData($xmlData);
					if (Document::checkXmlDataMaxSize($cleanedXmlData)) {
						$document->xml_data = $cleanedXmlData;
						$document->update($user);
					} else {
						dol_syslog(__METHOD__. " : xml_data content is too big and can't be stored in database (16Mo max for MEDIUMTEXT)", LOG_ERR);
					}

					return $cleanedXmlData;
					*/
				}

				return $foundDocument->xml_data;
			} elseif ($db->num_rows($resql) > 1) {
				throw new Exception('Duplicate entry in einvoicing_document for supplier invoice with id '.$supplierInvoiceId);
			} elseif ($db->num_rows($resql) == 0) {
				throw new Exception('No result found when searching for supplier invoice with id '.$supplierInvoiceId . ' in einvoicing_document');
			}
		}

		return null;
	}

	/**
	 * Allow to know if a supplier invoice is an e-invoice or not
	 *
	 * @param int 	$supplierInvoiceId 				The id of the supplier invoice
	 * @param bool 	$checkLinkedDolObjectExistance 	Also check if linked Dol object really exists or not
	 * @throws Exception
	 * @return bool									True if invoice found.
	 */
	public static function isEInvoice(int $supplierInvoiceId, bool $checkLinkedDolObjectExistance = false): bool
	{
		global $db;

		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "einvoicing_document";
		$sql .= " WHERE fk_element_type = '" . $db->escape('invoice_supplier') . "'";
		$sql .= " AND fk_element_id = " . (int) $supplierInvoiceId;
		$sql .= " LIMIT 2";

		$resql = $db->query($sql);
		if ($resql) {
			if ($db->num_rows($resql) == 1) {
				if ($checkLinkedDolObjectExistance) {
					$factureFournisseur = new FactureFournisseur($db);
					if ($factureFournisseur->fetch((int) $supplierInvoiceId) > 0) {
						return true;
					}
				}
			} elseif ($db->num_rows($resql) > 1) {
				throw new Exception('Duplicate entry in einvoicing_document for supplier invoice with id '.$supplierInvoiceId);
			}
		}
		return false;
	}

	/**
	 * Abandon a Dolibarr supplier invoice because its refusal has been confirmed by the
	 * e-invoicing platform (PDP/PA). Validates the invoice first if it is still a draft, then
	 * cancels it with a dedicated close code so it can be excluded from the accountancy
	 * transfer screen (see ActionsEinvoicing::printFieldListWhere()).
	 *
	 * Idempotent: calling this again on an invoice already abandoned by this same rule is a
	 * no-op. A paid invoice is never touched. This idempotence check reads $object->status and
	 * $object->close_code from the in-memory object: $object must reflect the current database
	 * state (i.e. freshly fetched) for it to be reliable.
	 *
	 * The validation step runs BILL_SUPPLIER_VALIDATE normally, including the e-invoice/Dolibarr
	 * consistency check when EINVOICING_SUPPLIER_INVOICE_CHECK_CONSISTENCY_ON_VALIDATION is
	 * enabled: if that check rejects the invoice, this method fails too (returns -1) rather than
	 * abandoning it.
	 *
	 * @param	FactureFournisseur	$object			Supplier invoice to abandon
	 * @param	User				$user			User (or system user, when called from a cron) triggering the change
	 * @param	string				$reasonLabel	Label of the refusal reason, stored as the invoice close note
	 * @return	int									1 if abandoned, 0 if already abandoned by this rule (no-op), -1 on error (see $object->errors)
	 */
	public static function abandonRefusedSupplierInvoice(FactureFournisseur $object, User $user, $reasonLabel = '')
	{
		if (!empty($object->paid) || $object->status == FactureFournisseur::STATUS_CLOSED) {
			$object->errors[] = 'Can not abandon supplier invoice id ' . $object->id . ' : invoice is already paid';
			dol_syslog(__METHOD__ . ' Can not abandon supplier invoice id ' . $object->id . ' : invoice is already paid', LOG_ERR);
			return -1;
		}

		if ($object->status == FactureFournisseur::STATUS_ABANDONED && $object->close_code == self::CLOSECODE_PDPREFUSED) {
			// Already abandoned by this same rule on a previous call (ex: AJAX confirmation followed
			// by the hourly cron re-processing the same platform confirmation).
			return 0;
		}

		if ($object->status == FactureFournisseur::STATUS_DRAFT) {
			$resValidate = $object->validate($user);
			if ($resValidate < 0) {
				dol_syslog(__METHOD__ . ' Failed to validate supplier invoice id ' . $object->id . ' before abandon : ' . implode(', ', $object->errors), LOG_ERR);
				return -1;
			}
		}

		$resCancel = $object->setCanceled($user, self::CLOSECODE_PDPREFUSED, $reasonLabel);
		if ($resCancel < 0) {
			dol_syslog(__METHOD__ . ' Failed to abandon supplier invoice id ' . $object->id . ' : ' . implode(', ', $object->errors), LOG_ERR);
			return -1;
		}

		return 1;
	}

	/**
	 * Callback to invoke once an outbound lifecycle status message has been validated (confirmed
	 * or rejected by the e-invoicing platform). This is a no-op unless the message is a
	 * confirmed ('Ok') refusal (EInvoicing::STATUS_REFUSED) of a supplier invoice, in which case
	 * it abandons the corresponding Dolibarr supplier invoice (see abandonRefusedSupplierInvoice()).
	 *
	 * Errors are logged but never thrown: this callback must never break the caller that is
	 * persisting the platform confirmation (see EInvoicing::updateStatusMessageValidation()).
	 *
	 * @param	DoliDB	$db					Database handler
	 * @param	User	$user				User (or system user, when called from a cron) triggering the change
	 * @param	int		$elementId			Id of the Dolibarr supplier invoice (einvoicing_lifecycle_msg.element_id)
	 * @param	int		$lcStatus			PDP/PA status code that was sent (einvoicing_lifecycle_msg.lc_status)
	 * @param	?string	$lcReasonCode		Reason code that was sent, if any (einvoicing_lifecycle_msg.lc_reason_code)
	 * @param	string	$validationStatus	Validation status just confirmed by the platform: 'Ok', 'Pending' or 'Error'
	 * @return	int							1 if abandoned, 0 if not applicable / already done, -1 on error (logged, not blocking)
	 */
	public static function onOutboundStatusMessageValidated($db, User $user, int $elementId, int $lcStatus, ?string $lcReasonCode, string $validationStatus)
	{
		global $langs;

		if ($validationStatus !== 'Ok' || $lcStatus !== EInvoicing::STATUS_REFUSED) {
			return 0;
		}

		$object = new FactureFournisseur($db);
		$resFetch = $object->fetch($elementId);
		if ($resFetch <= 0) {
			dol_syslog(__METHOD__ . ' Failed to fetch supplier invoice id ' . $elementId, LOG_ERR);
			return -1;
		}

		$langs->load('einvoicing@einvoicing');
		$einvoicing = new EInvoicing($db);
		$reasons = $einvoicing->getReasonsByStatus(EInvoicing::STATUS_REFUSED, 0);
		$reasonLabel = (!empty($lcReasonCode) && is_array($reasons) && isset($reasons[$lcReasonCode]))
			? $langs->trans($reasons[$lcReasonCode]['label'])
			: (string) $lcReasonCode;

		// abandonRefusedSupplierInvoice() already logs the specific failure reason (validate or
		// setCanceled) - not logged again here to avoid duplicate log entries for the same error.
		return self::abandonRefusedSupplierInvoice($object, $user, $reasonLabel);
	}
}
