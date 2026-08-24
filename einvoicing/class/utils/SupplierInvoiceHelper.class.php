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
 * \file    einvoicing/class/utils/SupplierInvoiceHelper.class.php
 * \ingroup einvoicing
 * \brief   Utility class for supplier invoices.
 * 			This file is mainly used when EINVOICING_SUPPLIER_INVOICE_CHECK_CONSISTENCY_ON_VALIDATION is set but
 * 			this option is seriously bugged. Do not use it.
 */

dol_include_once('einvoicing/class/protocols/ProtocolManager.class.php');
dol_include_once('einvoicing/class/document.class.php');
dol_include_once('einvoicing/class/utils/PriceHelper.class.php');
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
	 * Compare amounts according to a number of digits after decimal point and return true if they are equal.
	 *
	 * @param float $amount1    The first amount to compare
	 * @param float $amount2    The second amount to compare
	 * @param ?int $roundPrecision The number of digits after decimal point to apply round()
	 * @return bool Whether the amounts are equal or not
	 */
	private static function areAmountsEqual($amount1, $amount2, ?int $roundPrecision = null): bool
	{
		return (self::round($amount1, $roundPrecision) === self::round($amount2, $roundPrecision));
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
		$xmlData = SupplierInvoiceHelper::getXmlData($dolSupplierInvoice->id, true);

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
		$parsedHeader = $protocol->parseInvoiceHeader($xmlData);

		// Currency
		$currencyCode = $dolSupplierInvoice->multicurrency_code ?? $conf->currency;
		if ($currencyCode != $parsedHeader['invoiceCurrency']) {
			$errors[] = $langs->trans('SupplierInvoiceComparisonCurrencyDifference', $parsedHeader['invoiceCurrency'], $currencyCode);
		}

		// -----------------------------------------------------------------
		// 		Compare amount depending VAT calculation mode 1 & 2
		// -----------------------------------------------------------------

		// ? As we can't know if VAT of supplier invoice has been calculated in mode 1 or 2,
		// ? we need to calculate VAT in 3 different modes to be able to suggest the good one (can suggest only if differences are detected) :
		// ? - 'current' : if current supplier invoice data are identical to e-invoice, no need to suggest to switch VAT mode
		// ? - 'totalofround' (mode 1) : round VAT amount of each line then sum rounded amounts
		// ? - 'roundoftotal' (mode 2) : sum VAT amount of each line then round total

		// ? NOTE : Have to recode calculation of mode 1 & mode 2 because there is currently no Dolibarr function allowing to properly
		// ? apply VAT mode 1 or 2 on the supplier object without updating database.
		// ? Previously tried with CommonObject::update_price(), but it was not appropriate because it always refetch lines data from database
		// ? instead of using current object ones.

		$calculationRules = [
			'current' => 0,
			'totalofround' => 1,
			'roundoftotal' => 2,
		];

		$amountErrors = [];

		$isCreditNote = ($dolSupplierInvoice->type == FactureFournisseur::TYPE_CREDIT_NOTE);

		foreach ($calculationRules as $calculationRule => $vatComputeMode) {
			$details = self::getInvoiceDetailsForComparison($dolSupplierInvoice, $vatComputeMode);
			$amountErrors[$calculationRule] = [];

			if ($isCreditNote) {
				$details['total_ht'] = abs($details['total_ht']);
				$details['total_ttc'] = abs($details['total_ttc']);
				$details['total_tva'] = abs($details['total_tva']);
				foreach ($details['vat_by_rate'] as $rate => $rateDetails) {
					$details['vat_by_rate'][$rate]['vat_amount'] = abs($rateDetails['vat_amount']);
					$details['vat_by_rate'][$rate]['vat_basis_amount'] = abs($rateDetails['vat_basis_amount']);
				}
			}

			// VAT excl. total
			if (!self::areAmountsEqual($details['total_ht'], $parsedHeader['taxBasisTotalAmount'])) {
				$amountErrors[$calculationRule][] = $langs->trans('SupplierInvoiceComparisonTotalVatExclDifference', $parsedHeader['taxBasisTotalAmount'], floatval($dolSupplierInvoice->total_ht));
			}

			// VAT incl. total
			if (!self::areAmountsEqual($details['total_ttc'], $parsedHeader['grandTotalAmount'])) {
				$amountErrors[$calculationRule][] = $langs->trans('SupplierInvoiceComparisonTotalVatInclDifference', $parsedHeader['grandTotalAmount'], floatval($dolSupplierInvoice->total_ttc));
			}

			// VAT total
			if (!self::areAmountsEqual($details['total_tva'], $parsedHeader['taxTotalAmount'])) {
				$amountErrors[$calculationRule][] = $langs->trans('SupplierInvoiceComparisonTotalVatDifference', $parsedHeader['taxTotalAmount'], floatval($dolSupplierInvoice->total_tva));
			}

			$dolSupplierInvoiceVatDetails = $details['vat_by_rate'];
			foreach ($parsedHeader['taxBreakdown'] as $taxDetailsByRate) {
				if ($taxDetailsByRate['typeCode'] === 'VAT') {
					$currentRate = (string) $taxDetailsByRate['rateApplicablePercent'];
					if (array_key_exists($currentRate, $dolSupplierInvoiceVatDetails)) {
						$dolVatAmount = $dolSupplierInvoiceVatDetails[$currentRate]['vat_amount'];
						$dolVatBasis = $dolSupplierInvoiceVatDetails[$currentRate]['vat_basis_amount'];

						if (!self::areAmountsEqual($dolVatBasis, $taxDetailsByRate['basisAmount'])) {
							$amountErrors[$calculationRule][] = $langs->trans('SupplierInvoiceComparisonVatBasisDifference', $currentRate, $taxDetailsByRate['basisAmount'], $dolVatBasis);
						}
						if (!self::areAmountsEqual($dolVatAmount, $taxDetailsByRate['calculatedAmount'])) {
							$amountErrors[$calculationRule][] = $langs->trans('SupplierInvoiceComparisonVatRateDifference', $currentRate, $taxDetailsByRate['calculatedAmount'], $dolVatAmount);
						}
					} else {
						$amountErrors[$calculationRule][] = $langs->trans('SupplierInvoiceComparisonVatRateNotFound', $currentRate);
					}
				}
			}

			if (count($amountErrors['current']) == 0) {
				// Don't need to calculate VAT mode 1 & 2 if supplier invoice and e-invoice are identical with current mode
				break;
			}
		}

		if (count($amountErrors['current']) > 0) {
			// If there are errors in both VAT modes (totalofround and roundoftotal), then return only the errors occurred with roundoftotal
			if (count($amountErrors['totalofround'] ?? []) > 0 && count($amountErrors['roundoftotal'] ?? []) > 0) {
				$errors = array_merge($errors, $amountErrors['roundoftotal'] ?? []);
			} else {
				$errors = array_merge($errors, $amountErrors['totalofround'] ?? [], $amountErrors['roundoftotal'] ?? []);
			}

			if ($amountErrors['current'] == $amountErrors['totalofround'] && count($amountErrors['roundoftotal']) === 0) {
				$errors[] = $langs->trans('SupplierInvoiceComparisonSuggestVatCalculationMode', 2);
			} elseif ($amountErrors['current'] == $amountErrors['roundoftotal'] && count($amountErrors['totalofround']) === 0) {
				$errors[] = $langs->trans('SupplierInvoiceComparisonSuggestVatCalculationMode', 1);
			}
		}

		return [
			'identical' => (count($errors) == 0),
			'errors' => $errors,
		];
	}

	/**
	 * Return supplier invoice details used to compare dol supplier invoice and e-invoice
	 *
	 * @param FactureFournisseur 	$supplierInvoice 	The supplier invoice object
	 * @param int 					$vatComputeMode 	The VAT mode used to calculate VAT amounts
	 * @return array{total_ht: float, total_ttc: float, total_tva: float, vat_by_rate: array<string, array{vat_amount: float, vat_basis_amount: float}>}
	 */
	private static function getInvoiceDetailsForComparison(FactureFournisseur $supplierInvoice, $vatComputeMode)
	{
		global $db;

		// If mode 0 => use current supplier invoice data
		if ($vatComputeMode == 0) {
			$details = array(
				'total_ht' => $supplierInvoice->total_ht,
				'total_ttc' => $supplierInvoice->total_ttc,
				'total_tva' => $supplierInvoice->total_tva,
				'vat_by_rate' => self::getVatDetails($supplierInvoice)
			);

			return $details;
		}

		// Manage mode 1 (totalofround) & mode 2 (roundoftotal)
		$details = array(
			'total_ht' => 0,
			'total_ttc' => 0,
			'total_tva' => 0,
		);

		$seller = new Societe($db);
		$resseller = $seller->fetch($supplierInvoice->socid);
		if ($resseller <= 0) {
			throw new Exception('Seller not found for id : ' . $supplierInvoice->socid);
		}

		$forceRoundingTotalsPrecision = ($vatComputeMode == 1 ? 'MT' : 'MU');

		foreach ($supplierInvoice->lines as $line) {
			$rate = (string) price2num($line->tva_tx);

			if (!isset($details['vat_by_rate'][$rate])) {
				$details['vat_by_rate'][$rate] = array(
					'vat_basis_amount' => 0,
					'vat_amount' => 0
				);
			}

			$useLocalTax1 = 1;
			$useLocalTax2 = 1;
			$remisePercentGlobal = 0;
			$priceBaseType = 'HT';
			$infoBits = 0;
			$localTaxes = array($line->localtax1_type, $line->localtax1_tx, $line->localtax2_type, $line->localtax2_tx);
			$progress = (isset($line->situation_percent) ? $line->situation_percent : 100);
			$multiCurrencyTx = !empty($line->multicurrency_tx) ? $line->multicurrency_tx : 1;
			$puDevise = 0;
			$multicurrencyCode = '';

			$lineTotals = PriceHelper::calculatePriceTotal(
				$line->qty,
				$line->subprice,
				$line->remise_percent,
				floatval($rate),
				$useLocalTax1,
				$useLocalTax2,
				$remisePercentGlobal,
				$priceBaseType,
				$infoBits,
				$line->product_type,
				$seller,
				$localTaxes,
				$progress,
				$multiCurrencyTx,
				$puDevise,
				$multicurrencyCode,
				$forceRoundingTotalsPrecision
			);

			$lineTotalHt = floatval($lineTotals[0]);
			$lineVatAmount = floatval($lineTotals[1]);
			$lineTotalTtc = floatval($lineTotals[2]);

			$details['vat_by_rate'][$rate]['vat_basis_amount'] += $lineTotalHt;
			$details['vat_by_rate'][$rate]['vat_amount'] += $lineVatAmount;

			$details['total_ht'] += $lineTotalHt;
			$details['total_ttc'] += $lineTotalTtc;
			$details['total_tva'] += $lineVatAmount;
		}

		$roundPrecision = 'MT';

		foreach ($details['vat_by_rate'] as $rate => $rateDetails) {
			// Use floatval() to cast to float because parsed data from einvoice are of type 'float'
			$details['vat_by_rate'][$rate]['vat_amount'] = floatval(price2num($details['vat_by_rate'][$rate]['vat_amount'], $roundPrecision));
		}

		// Use floatval() to cast to float because parsed data from einvoice are of type 'float'
		$details['total_ht'] = floatval(price2num($details['total_ht'], $roundPrecision));
		$details['total_ttc'] = floatval(price2num($details['total_ttc'], $roundPrecision));
		$details['total_tva'] = floatval(price2num($details['total_tva'], $roundPrecision));

		return $details;
	}

	/**
	 * Return VAT details (by VAT rate) from a supplier invoice
	 *
	 * @param FactureFournisseur $supplierInvoice The supplier invoice object
	 * @return array<string, array{vat_amount: float, vat_basis_amount: float}>
	 */
	public static function getVatDetails(FactureFournisseur $supplierInvoice): array
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
	 * @param 	bool 		$fetchXmlIfEmpty		Whether the XML data should be fetch again (if currently empty in database)
	 * @return 	?string 							The XML data if available or null if can't get it
	 * @throws 	Exception
	 */
	public static function getXmlData(int $supplierInvoiceId, bool $fetchXmlIfEmpty = false): ?string
	{
		global $db, $user;

		$sql = "SELECT rowid, flow_id, provider, xml_data FROM " . $db->prefix() . "einvoicing_document";
		$sql .= " WHERE fk_element_type = 'invoice_supplier'";
		$sql .= " AND fk_element_id = " . (int) $supplierInvoiceId;
		$sql .= " AND flow_type = 'SupplierInvoice'";
		$sql .= " LIMIT 2";

		$resql = $db->query($sql);
		if ($resql) {
			if ($db->num_rows($resql) == 1) {
				$foundDocument = $db->fetch_object($resql);
				$db->free($resql);

				$document = new Document($db);
				$resdoc = $document->fetch($foundDocument->rowid);

				if ((empty($resdoc) || is_null($document->xml_data) || $document->xml_data == '') && $fetchXmlIfEmpty) {
					$providerManager = new PDPProviderManager($db);
					$provider = $providerManager->getProvider(strtoupper((string) $document->provider));

					$cleanedXmlData = $provider->fetchFlowXml($document->flow_id, true);

					if (Document::checkXmlDataMaxSize($cleanedXmlData)) {
						$document->xml_data = $cleanedXmlData;
						$document->update($user);
					} else {
						dol_syslog(__METHOD__. " : xml_data content is too big and can't be stored in database (16Mo max for MEDIUMTEXT)", LOG_ERR);
					}

					return $cleanedXmlData;
				}

				return $foundDocument->xml_data;
			} elseif ($db->num_rows($resql) > 1) {
				$db->free($resql);
				throw new Exception('Duplicate entry in einvoicing_document for supplier invoice with id '.$supplierInvoiceId);
			} elseif ($db->num_rows($resql) == 0) {
				$db->free($resql);
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
	 * @param bool 	$duplicate 						Set to true when several e-invoicing documents describe the
	 *												same supplier invoice, so the caller can refuse the operation
	 * @return bool									True if invoice found.
	 */
	public static function isEInvoice(int $supplierInvoiceId, bool $checkLinkedDolObjectExistance = false, bool &$duplicate = false): bool
	{
		global $db;

		$duplicate = false;

		$sql = "SELECT rowid FROM " . $db->prefix() . "einvoicing_document";
		$sql .= " WHERE fk_element_type = 'invoice_supplier'";
		$sql .= " AND fk_element_id = " . (int) $supplierInvoiceId;
		$sql .= " AND flow_type = 'SupplierInvoice'";
		$sql .= " LIMIT 2";

		$resql = $db->query($sql);
		if (!$resql) {
			return false;
		}

		$num = $db->num_rows($resql);
		$db->free($resql);

		if ($num > 1) {
			// Several e-invoicing documents for the same supplier invoice is a data integrity problem that
			// needs a manual fix in database: the same invoice may hold diverging statuses coming from two
			// access points. The answer to "is this an e-invoice" is still yes, so this predicate says yes
			// and reports the duplicate. Throwing from here would not help: run_triggers() calls runTrigger()
			// without a try/catch, so the exception used to surface as an uncaught PHP fatal instead of the
			// message the user needs. Refusing the operation belongs to the caller.
			$duplicate = true;
			dol_syslog(__METHOD__ . ' duplicate entry in einvoicing_document for supplier invoice with id ' . $supplierInvoiceId, LOG_ERR);
		}

		if ($num <= 0) {
			return false;
		}

		if ($checkLinkedDolObjectExistance) {
			$factureFournisseur = new FactureFournisseur($db);

			return ($factureFournisseur->fetch((int) $supplierInvoiceId) > 0);
		}

		return true;
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
	 * Tell whether a received supplier invoice is a credit note correcting an invoice we refused.
	 *
	 * Refusing a received invoice cancels it: it owes nothing any more, and the vendor answers by
	 * issuing the credit note that closes the matter on its side. Accepting that credit note would
	 * acknowledge the reversal of a debt that never entered our accounts, and would leave the exchange
	 * saying two contradictory things about the same operation. The credit note follows its invoice:
	 * refused (issue #594).
	 *
	 * Only credit notes. A replacement invoice (BT-3 = 384) also references the document it corrects,
	 * and there the answer is the opposite one: it is the corrected invoice the vendor sends after a
	 * refusal, which is exactly what one has to be able to accept.
	 *
	 * The lookup is a single query on the invoice rather than a fetch: this runs on every display of a
	 * received supplier invoice card, and all it needs is the type and the reference to the source.
	 *
	 * @param	int		$supplierInvoiceId	Id of the received supplier invoice
	 * @return	int							Id of the refused source invoice, 0 when the rule does not apply
	 */
	public static function refusedSourceOfCreditNote(int $supplierInvoiceId): int
	{
		global $db;

		if ($supplierInvoiceId <= 0) {
			return 0;
		}

		$sql = "SELECT fk_facture_source FROM " . $db->prefix() . "facture_fourn";
		$sql .= " WHERE rowid = " . (int) $supplierInvoiceId;
		$sql .= " AND type = " . (int) FactureFournisseur::TYPE_CREDIT_NOTE;
		$sql .= " AND fk_facture_source > 0";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . ' SQL error: ' . $db->lasterror(), LOG_ERR, 0, '_einvoicing');
			return 0;
		}
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		if (!$obj) {
			return 0;
		}

		$sourceId = (int) $obj->fk_facture_source;

		// Only a refusal the platform confirmed counts: one still pending can still be rejected, and
		// answering the credit note on the strength of it would commit us to something not yet true.
		$einvoicing = new EInvoicing($db);
		if (!$einvoicing->hasSentStatusMessage($sourceId, 'invoice_supplier', EInvoicing::STATUS_REFUSED, 1)) {
			return 0;
		}

		return $sourceId;
	}

	/**
	 * Close the supplier invoice that a newly validated replacement invoice replaces.
	 *
	 * Dolibarr does this on the customer side - Facture::validate() cancels the replaced invoice with
	 * the close code "replaced" - but FactureFournisseur::validate() does not, so a replaced supplier
	 * invoice stayed validated, with nothing saying it had been superseded and nothing stopping it
	 * from being paid a second time (issue #549).
	 *
	 * Scope. Only the invoices this module is responsible for are touched, i.e. those exchanged
	 * through the platform: either the replacement or the invoice it replaces has to be an e-invoice.
	 * A replacement recorded by hand between two ordinary supplier invoices is left to the core.
	 *
	 * Three states are deliberately left alone:
	 * - a paid replaced invoice, because abandoning it would contradict the payment already recorded;
	 * - a draft one, which cannot be paid nor transferred to accountancy anyway, and which validating
	 *   just to cancel would give a reference it never earned;
	 * - one already closed by this same rule, so the method is idempotent.
	 *
	 * @param	FactureFournisseur	$replacement	Replacement invoice that has just been validated
	 * @param	User				$user			User validating it
	 * @return	int									1 if the replaced invoice was closed, 0 if there was nothing to do, -1 on error
	 */
	public static function closeReplacedSupplierInvoice(FactureFournisseur $replacement, User $user)
	{
		global $db;

		if ((int) $replacement->type !== FactureFournisseur::TYPE_REPLACEMENT || empty($replacement->fk_facture_source)) {
			return 0;
		}

		$sourceId = (int) $replacement->fk_facture_source;

		if (!self::isEInvoice((int) $replacement->id) && !self::isEInvoice($sourceId)) {
			return 0;
		}

		$source = new FactureFournisseur($db);
		if ($source->fetch($sourceId) <= 0) {
			dol_syslog(__METHOD__ . ' Cannot load the supplier invoice id ' . $sourceId . ' replaced by id ' . $replacement->id, LOG_ERR, 0, '_einvoicing');
			return -1;
		}

		if ($source->status == FactureFournisseur::STATUS_ABANDONED && $source->close_code == FactureFournisseur::CLOSECODE_REPLACED) {
			return 0;
		}

		if (!empty($source->paid) || $source->status == FactureFournisseur::STATUS_CLOSED) {
			dol_syslog(__METHOD__ . ' Supplier invoice id ' . $sourceId . ' is replaced by id ' . $replacement->id . ' but is already paid: left as it is', LOG_WARNING, 0, '_einvoicing');
			return 0;
		}

		if ($source->status == FactureFournisseur::STATUS_DRAFT) {
			dol_syslog(__METHOD__ . ' Supplier invoice id ' . $sourceId . ' is replaced by id ' . $replacement->id . ' but is still a draft: left as it is', LOG_INFO, 0, '_einvoicing');
			return 0;
		}

		if ($source->setCanceled($user, FactureFournisseur::CLOSECODE_REPLACED, '') < 0) {
			dol_syslog(__METHOD__ . ' Failed to close the supplier invoice id ' . $sourceId . ' replaced by id ' . $replacement->id . ' : ' . implode(', ', (array) $source->errors), LOG_ERR, 0, '_einvoicing');
			return -1;
		}

		dol_syslog(__METHOD__ . ' Supplier invoice id ' . $sourceId . ' closed as replaced by id ' . $replacement->id, LOG_DEBUG, 0, '_einvoicing');

		return 1;
	}

	/**
	 * Tell whether validating this supplier invoice has to answer its vendor with "Approved" (fr:205).
	 *
	 * Validating a received invoice in Dolibarr is the act of accepting it - it leaves the draft state to
	 * enter the accounts and become payable - so it is what the buyer answers 205 for. Four things can
	 * make the answer no:
	 *
	 *   - EINVOICING_SEND_APPROVED_ON_VALIDATION, for an instance where validating an invoice
	 *     mean approving it. The status stays available by hand from the invoice card.
	 *   - EINVOICING_DISABLE_SYNC_DOLI_TO_AP, which switches off everything this module sends.
	 *   - an invoice that never came from the platform: its vendor is not waiting for any status.
	 *   - a lifecycle already closed by a 205 or a 210 sent earlier: neither is repeated, and a refusal
	 *     is not silently turned into an approval by a later validation. Same rule as the card, which
	 *     stops offering the buttons once one of the two has been sent and confirmed.
	 *   - a credit note correcting an invoice we refused: it credits an invoice that owes nothing, so
	 *     validating it in the accounts must not answer its vendor that we accept it (issue #594).
	 *
	 * @param	EInvoicing	$einvoicing		Module object, for the lifecycle message lookup
	 * @param	int			$supplierInvoiceId	Id of the supplier invoice being validated
	 * @param	string		$elementType	Element type of that invoice ('invoice_supplier')
	 * @return	bool						True when the status has to be sent
	 */
	public static function shouldSendApprovedOnValidation($einvoicing, int $supplierInvoiceId, string $elementType = 'invoice_supplier'): bool
	{
		if (!getDolGlobalString('EINVOICING_SEND_APPROVED_ON_VALIDATION')) {
			return false;
		}
		if (getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {
			return false;
		}
		if (!self::isEInvoice($supplierInvoiceId)) {
			return false;
		}
		if ($einvoicing->hasSentStatusMessage($supplierInvoiceId, $elementType, EInvoicing::STATUS_APPROVED)) {
			return false;
		}
		if ($einvoicing->hasSentStatusMessage($supplierInvoiceId, $elementType, EInvoicing::STATUS_REFUSED)) {
			return false;
		}
		if (self::refusedSourceOfCreditNote($supplierInvoiceId) > 0) {
			return false;
		}

		return true;
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
		$reasonLabel = (!empty($lcReasonCode) && is_array($reasons) && isset($reasons[$lcReasonCode])) ? $langs->trans($reasons[$lcReasonCode]['label']) : (string) $lcReasonCode;

		// abandonRefusedSupplierInvoice() already logs the specific failure reason (validate or
		// setCanceled) - not logged again here to avoid duplicate log entries for the same error.
		return self::abandonRefusedSupplierInvoice($object, $user, $reasonLabel);
	}

	/**
	 * Find the supplier invoice of a given supplier whose ref_supplier is the given reference.
	 *
	 * The default is an exact match, which is the only safe rule while the quality of the incoming
	 * data is unknown: a wrong match on a duplicate check silently drops an invoice that is then
	 * never imported, and a wrong match on a referenced document links the new invoice to the wrong
	 * one. With the option below off, this is exactly the query the callers used to run inline.
	 *
	 * The hidden option EINVOICING_TOLERANT_SUPPLIER_REF_MATCH adds a fallback, tried only when the
	 * exact match found nothing. It accepts a ref_supplier that was typed manually with extra text
	 * around the reference, or with stray whitespace inside it, e.g.
	 * "PLTHDDD5OAAABBB - TEST-GROUP-2026-07-06-19407 - EVENEMENT 30/06/2026 A PARIS" for a document
	 * whose reference in the XML is only "TEST-GROUP-2026-07-06-19407". That fallback stays narrow:
	 * a reference shorter than EINVOICING_TOLERANT_SUPPLIER_REF_MIN_LENGTH (8) or purely numeric is
	 * never searched for as a substring, the substring must be delimited so that "FA202610" does not
	 * match "FA2026100", and an ambiguity is reported instead of guessed.
	 *
	 * @param	string|null	$ref	Reference to look for (ExchangedDocument/ID or IssuerAssignedID of the XML)
	 * @param	int			$socId	Id of the supplier thirdparty
	 * @return	int					Invoice id (>0) on a single certain match, 0 when not found, -1 on database error, -2 when several invoices match
	 */
	public static function findIdByRef($ref, int $socId): int
	{
		global $db;

		$ref = trim((string) $ref);
		if ($ref === '' || $socId <= 0) {
			return 0;
		}

		// Exact match. Always tried first, and always enough on its own.
		$sql = "SELECT rowid FROM " . $db->prefix() . "facture_fourn";
		$sql .= " WHERE ref_supplier = '" . $db->escape($ref) . "'";
		$sql .= " AND fk_soc = " . ((int) $socId);
		$sql .= " AND entity IN (" . getEntity('facture_fourn') . ")";
		$sql .= " LIMIT 1";
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . ' ' . $db->lasterror(), LOG_ERR);
			return -1;
		}
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		if ($obj) {
			return (int) $obj->rowid;
		} elseif (getDolGlobalInt('EINVOICING_TOLERANT_SUPPLIER_REF_MATCH')) {
			// Tolerant fallback, opt-in and deliberately narrow. Everything that widens the match is
			// enclosed in this branch on purpose: no code path reaches it while the option is off.
			$refNoSpaces = self::normalizeRef($ref);
			if (dol_strlen($refNoSpaces) < getDolGlobalInt('EINVOICING_TOLERANT_SUPPLIER_REF_MIN_LENGTH', 8) || ctype_digit($refNoSpaces)) {
				// A short or purely numeric reference is a substring of far too many others, do not even try
				return 0;
			}

			$sql = "SELECT rowid, ref_supplier FROM " . $db->prefix() . "facture_fourn";
			$sql .= " WHERE REPLACE(ref_supplier, ' ', '') LIKE '%" . $db->escape($db->escapeforlike($refNoSpaces)) . "%'";
			$sql .= " AND fk_soc = " . ((int) $socId);
			$sql .= " AND entity IN (" . getEntity('facture_fourn') . ")";
			$resql = $db->query($sql);
			if (!$resql) {
				dol_syslog(__METHOD__ . ' ' . $db->lasterror(), LOG_ERR);
				return -1;
			}
			$matches = array();
			while ($obj = $db->fetch_object($resql)) {
				// The SQL LIKE is only a prefilter, the delimiter rule below is what decides
				if (self::refEmbedsReference($obj->ref_supplier, $ref)) {
					$matches[(int) $obj->rowid] = (int) $obj->rowid;
				}
			}
			$db->free($resql);

			if (count($matches) > 1) {
				dol_syslog(__METHOD__ . ' several supplier invoices embed reference "' . $ref . '" for socid ' . ((int) $socId) . ', ids ' . implode(',', $matches), LOG_WARNING);
				return -2;
			}
			if (count($matches) == 1) {
				$found = (int) reset($matches);
				dol_syslog(__METHOD__ . ' reference "' . $ref . '" matched supplier invoice id ' . $found . ' by tolerant match', LOG_NOTICE);
				return $found;
			}
		}

		return 0;
	}

	/**
	 * Build the message of a lookup that findIdByRef() could not answer.
	 *
	 * A database failure and an ambiguous reference are two different problems, and neither of them
	 * is a missing document, so neither may be reported as one.
	 *
	 * @param	int			$code		Negative code returned by findIdByRef()
	 * @param	string|null	$ref		Reference that was looked for
	 * @param	string		$context	Where that reference comes from, appended to the message
	 * @return	string					Message to report to the caller
	 */
	public static function refLookupErrorMessage(int $code, $ref, string $context): string
	{
		global $db;

		if ($code == -2) {
			return 'Several supplier invoices match reference "' . $ref . '" ' . $context . ', cannot determine which one to use';
		}

		return 'Database error while looking for a supplier invoice with reference "' . $ref . '" ' . $context . ': ' . $db->lasterror();
	}

	/**
	 * Tell whether a ref_supplier embeds a reference as a delimited substring.
	 *
	 * Both values are compared without their whitespace, so that a ref_supplier typed as
	 * "FA 2026 10" matches the reference "FA202610". The reference must be bounded on both sides by
	 * a non alphanumeric character, by a removed gap or by an edge of the string, so that "FA202610"
	 * matches "PAY123 - FA202610 - dinner" but not "FA2026100". Comparison is case insensitive, as
	 * the SQL prefilter of findIdByRef() is under the usual collations.
	 *
	 * @param	string|null	$refSupplier	ref_supplier value read from database
	 * @param	string|null	$ref			Reference looked for
	 * @return	bool						True when the reference is embedded as a delimited substring
	 */
	public static function refEmbedsReference($refSupplier, $ref): bool
	{
		$needle = self::normalizeRef($ref);
		if ($needle === '') {
			return false;
		}

		// Whitespace is removed so that "FA 2026 10" matches "FA202610", but removing it must not
		// destroy the boundary it forms: remember where a gap was, it counts as a delimiter.
		$haystack = '';
		$gapBefore = array();
		$pendingGap = false;
		foreach (preg_split('//u', (string) $refSupplier, -1, PREG_SPLIT_NO_EMPTY) as $char) {
			if (preg_match('/^(\s|\xc2\xa0)$/u', $char)) {
				$pendingGap = true;
				continue;
			}
			$gapBefore[strlen($haystack)] = $pendingGap;
			$pendingGap = false;
			$haystack .= $char;
		}
		$gapBefore[strlen($haystack)] = $pendingGap;

		$length = strlen($needle);
		$offset = 0;
		while (($pos = stripos($haystack, $needle, $offset)) !== false) {
			$end = $pos + $length;
			$startIsBounded = ($pos == 0 || !empty($gapBefore[$pos]) || !ctype_alnum($haystack[$pos - 1]));
			$endIsBounded = ($end >= strlen($haystack) || !empty($gapBefore[$end]) || !ctype_alnum($haystack[$end]));
			if ($startIsBounded && $endIsBounded) {
				return true;
			}
			$offset = $pos + 1;
		}

		return false;
	}

	/**
	 * Strip from a reference the whitespace that manual entry adds (space, tab, non breaking space).
	 *
	 * @param	string|null	$value	Raw reference
	 * @return	string				Reference without any whitespace
	 */
	private static function normalizeRef($value): string
	{
		return (string) preg_replace('/(\s|\xc2\xa0)+/u', '', (string) $value);
	}

	/**
	 * Round an amount according to a number of digits after decimal point and return it.
	 *
	 * @param float $amount    		The amount to round
	 * @param ?int $roundPrecision 	The number of digits after decimal point to apply round()
	 * @return float The rounded amount
	 */
	private static function round($amount, $roundPrecision = null): float
	{
		if (!isset($roundPrecision)) {
			$roundPrecision = getDolGlobalInt('MAIN_MAX_DECIMALS_TOT', 2);
		}

		return round($amount, (int) $roundPrecision);
	}
}
