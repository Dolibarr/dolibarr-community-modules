<?php

use horstoeko\zugferd\ZugferdDocumentPdfReaderExt;

dol_include_once('einvoicing/class/protocols/ProtocolManager.class.php');
dol_include_once('einvoicing/class/document.class.php');

/**
 * \file    einvoicing/class/helpers/SupplierInvoiceHelper.class.php
 * \ingroup einvoicing
 * \brief   Utility class for supplier invoices.
 */

class SupplierInvoiceHelper
{
	/**
	 * Compare amounts according to a number of digit after coma and return true if they are equal.
	 *
	 * @param float $amount1    The first amount to compare
	 * @param float $amount2    The second amount to compare
	 * @param int $roundPrecision The number of digits after coma to apply round()
	 * @return bool
	 */
	public static function areAmountsEqual($amount1, $amount2, ?int $roundPrecision = null): bool
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
		$protocol = ProtocolManager::getProtocolFromContent($xmlData);
		if (!isset($protocol)) {
			$errors[] = $langs->trans('EinvoiceFailedToDetectXmlFormat');
			return [
				'identical' => false,
				'errors' => $errors,
			];
		}

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

		// Mode 1 : round VAT amount of each line and then sum rounded amounts
		// Mode 2 : sum VAT amount of each line and then round total

		// ? Start transaction to be able to calculate VAT amounts in 2 differents modes :
		// ? - do it this way because VAT calculation is directly made in update_price() method which also update database, but in our case, we don't want to update database
		// ? - our need here is to calculate in mode 1 and mode 2 without to have to rewrite all VAT calculation logic
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
							$amountErrors[$calculationRule][] = $langs->trans('SupplierInvoiceComparisonVatBasisDifference',  $taxDetailsByRate['rateApplicablePercent'], $taxDetailsByRate['basisAmount'], $dolVatBasis);
						}
						if (!self::areAmountsEqual($dolVatAmount, $taxDetailsByRate['calculatedAmount'])) {
							$amountErrors[$calculationRule][] = $langs->trans('SupplierInvoiceComparisonVatRateDifference',  $taxDetailsByRate['rateApplicablePercent'], $taxDetailsByRate['calculatedAmount'], $dolVatAmount);
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
				$errors[] = '💡' . $langs->trans('SupplierInvoiceComparisonSuggestVatCalculationMode', 2);
			} elseif ($amountErrors['current'] == $amountErrors['roundoftotal'] && count($amountErrors['totalofround']) === 0) {
				$errors[] = '💡' . $langs->trans('SupplierInvoiceComparisonSuggestVatCalculationMode', 1);
			}
		}

		// Rollback because we don't want to persist in database the changes made by the different calls to update_price() (see comment before the $db->begin() for more details)
		$db->rollback();

		return [
			'identical' => (count($errors) == 0),
			'errors' => $errors,
		];
	}

	/**
	 * Return VAT details (by VAT rate) from a supplier invoice
	 *
	 * @param FactureFournisseur $supplierInvoice The supplier invoice object
	 * @return array<array|array{vat_amount: int, vat_basis_amount: int>}
	 */
	public static function getVatDetails(FactureFournisseur $supplierInvoice)
	{
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
	 * - if data not found in database, try to get data from AP
	 *
	 * @param int $supplierInvoiceId The id of the supplier invoice
	 * @throws Exception
	 * @return ?string The XML data if available or null if can't get it
	 */
	public static function getXmlData(int $supplierInvoiceId): ?string
	{
		global $db, $user;

		$sql = "SELECT `rowid`, `flow_id`, `provider`, `xml_data` FROM " . MAIN_DB_PREFIX . "einvoicing_document";
		$sql .= " WHERE fk_element_type = '" . $db->escape('FactureFournisseur') . "'";
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
					$provider = $providerManager->getProvider(strtoupper($document->provider));
					$flowResponse = $provider->fetchFlowData($document->flow_id, 'Converted');

					if ($flowResponse['status_code'] != 200) {
						dol_syslog(__METHOD__ . ' : failed to get flow data for flow id n° ' . $document->flow_id . ' and for supplier invoice id n° ' . $supplierInvoiceId, LOG_ERR);
						return null;
					}

					$xmlData = ZugferdDocumentPdfReaderExt::getInvoiceDocumentContentFromContent($flowResponse['response']);
					$cleanedXmlData = Document::cleanXmlData($xmlData);
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
				dol_syslog('Duplicate entry in einvoicing_document for supplier invoice with id '.$supplierInvoiceId, LOG_ERR);
			} elseif ($db->num_rows($resql) == 0) {
				dol_syslog('No result found when searching for supplier invoice with id '.$supplierInvoiceId . ' in einvoicing_document', LOG_ERR);
			}
		}

		return null;
	}

	/**
	 * Allow to known if a supplier invoice is an e-invoice or not
	 *
	 * @param int $supplierInvoiceId The id of the supplier invoice
	 * @throws Exception
	 * @return bool
	 */
	public static function isEInvoice(int $supplierInvoiceId): bool
	{
		global $db;

		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "einvoicing_document";
		$sql .= " WHERE fk_element_type = '" . $db->escape('FactureFournisseur') . "'";
		$sql .= " AND fk_element_id = " . (int) $supplierInvoiceId;
		$sql .= " LIMIT 2";

		$resql = $db->query($sql);
		if ($resql) {
			if ($db->num_rows($resql) == 1) {
				return true;
			} elseif ($db->num_rows($resql) > 1) {
				throw new Exception('Duplicate entry in einvoicing_document for supplier invoice with id '.$supplierInvoiceId);
			}
		}
		return false;
	}

	/**
	 * Indicates if the type of import for supplier invoice lines is auto or not :
	 * - first try to get import type from societe
	 * - if not set, then use default module parameter
	 * @param int $socId The soc id to test
	 * @return bool
	 */
	public static function isSupplierImportInvoiceLinesAuto($socId)
	{
		global $db;

		$soc = new Societe($db);

		if ($socId > 0) {
			$res = $soc->fetch($socId);
			if ($res > 0 && isset($soc->array_options['options_einvoicing_supplier_invoice_lines_import_type']) && $soc->array_options['options_einvoicing_supplier_invoice_lines_import_type'] > 0) {
				return $soc->array_options['options_einvoicing_supplier_invoice_lines_import_type'] == Einvoicing::SUPPLIER_INVOICE_LINES_IMPORT_AUTO;
			} else {
				return getDolGlobalInt('EINVOICING_SUPPLIER_INVOICE_LINES_IMPORT_TYPE') == Einvoicing::SUPPLIER_INVOICE_LINES_IMPORT_AUTO;
			}
		}

		return false;
	}
}
