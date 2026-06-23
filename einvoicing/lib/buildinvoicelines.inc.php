<?php
/* Copyright (C) 2025		SuperAdmin					<daoud.mouhamed@gmail.com>
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
 * \file    einvoicing/lib/buildinvoicelines.inc.php
 * \ingroup einvoicing
 * \brief   Code to generate the array of invoice and lines
 */


/**
 * @var Conf 		$conf
 * @var DoliDB     	$db
 * @var Societe    	$mysoc
 * @var Translate 	$langs
 * @var User       	$user
 *
 * @var Translate 	$outputlangs
 * @var Facture    	$invoice
 * @var CIIProtocol|FacturXProtocol	$this
 */
'
@phan-var-force Translate 	$outputlangs
@phan-var-force Facture   	$invoice
@phan-var-force CIIProtocol|FacturXProtocol	$this
';

// Use customer language
if (empty($outputlangs) || ! ($outputlangs instanceof Translate)) {
	$outputlangs = $langs;
}
$newlang = '';

// Load EInvoicing class
$einvoicing = new EInvoicing($db);


$outputlang = $langs->defaultlang;

if (!is_object($invoice->thirdparty)) {
	$invoice->fetch_thirdparty();
}

$this->sourceinvoice = $invoice;

// Reload object if not a new object (to get all fields)
$tmpfacture = new Facture($db);
$object = $tmpfacture->fetch($invoice->id) > 0 ? $tmpfacture : $invoice;

if (!is_object($object->thirdparty)) {
	$object->fetch_thirdparty();
}

// =====================================================================
// Data collection into $invoiceData and $linesData arrays
// =====================================================================

// Customer references and delivery dates
$customerOrderReferenceList = [];
$deliveryDateList = [];
$this->_determineDeliveryDatesAndCustomerOrderNumbers($customerOrderReferenceList, $deliveryDateList, $object);

// Chorus
$chorus = false;
if (getDolGlobalInt('EINVOICING_USE_CHORUS')) {
	$chorus = true;
}
$promise_code = $object->array_options['options_d4d_promise_code'] ?? '';
if ($promise_code == '') {
	$promise_code = $object->ref_customer ?? '';
}
if ($promise_code == '' && !empty($customerOrderReferenceList)) {
	$promise_code = $customerOrderReferenceList[0];
}

// Bank account
$account = new Account($db);
if ($object->fk_account > 0) {
	$account->fetch($object->fk_account);
} elseif (getDolGlobalInt('FACTURE_RIB_NUMBER')) {
	$account->fetch(getDolGlobalInt('FACTURE_RIB_NUMBER'));
}

$account_proprio = '';
if ($account->id > 0) {
	$account_proprio = trim(!empty($account->proprio) ? $account->proprio : $account->owner_name);	// $account->proprio is for old version compatibility
}
if ($account_proprio == '') {
	dol_syslog('Bank account holder name is empty, please correct it, use socname instead but it could be inccorrect for XRechnung BT-85: Payment account name', LOG_WARNING);
	$account_proprio = $mysoc->name;
}

// Buyer intra VAT (calculated if missing)
if ($object->thirdparty->tva_assuj && empty($object->thirdparty->tva_intra)) {
	$object->thirdparty->tva_intra = $einvoicing->thirdpartyCalcVATIntra($object->thirdparty);
}

// Seller identifiers (mysoc)
$myidprof          = idprof($mysoc);
$mySchemeIdProf    = $this->getIEC6523Code($mysoc->country_code);
$myGlobalIdProf    = idprof($mysoc);
$mySchemeGlobalIdProf = $this->getIEC6523Code($mysoc->country_code, 1);
$myUri             = $einvoicing->getSellerCommunicationURI(0);
$mySchemeUri       = $this->getIEC6523Code($mysoc->country_code, 2);

// Buyer identifiers (thirdparty)
$idprof            = thirdpartyidprof($object) ?? '';
$schemeIdProf      = $this->getIEC6523Code($object->thirdparty->country_code);
$globalIdProf      = thirdpartyidprof($object) ?? '';
$schemeGlobalIdProf = $this->getIEC6523Code($object->thirdparty->country_code, 1);
$uri               = $einvoicing->getBuyerCommunicationURI($object->thirdparty, $object);
$reg = array();
if (preg_match('/(\d+):(.+)/', $uri, $reg)) {
	$uri		= $reg[2];
	$schemeUri  = $reg[1];
} else {
	$schemeUri  = $this->getIEC6523Code($object->thirdparty->country_code, 2);
}
// In case of sample tests, we may have this const defined to overwrite buyer Einvoice address ID.
// In common case, this should not be used
if (defined('EINVOICING_FORCE_BUYER_EID')) {
	$uri               = constant('EINVOICING_FORCE_BUYER_EID');
	$schemeUri         = "0225";
}

// Seller contact
$usercontacts = $object->getIdContact('internal', 'SALESREPFOLL');
$object->user = null;
if (!empty($usercontacts) && $object->fetch_user($usercontacts[0]) > 0) {
	$salerepresentative_name          = $object->user->getFullName($outputlangs);
	$salerepresentative_office_phone  = $object->user->office_phone;
	$salerepresentative_office_fax    = $object->user->office_fax;
	$salerepresentative_email         = $object->user->email;
} else {
	// No sales representative assigned to the invoice: the seller contact (BG-6) must describe the
	// seller, so fall back to the emitting company ($mysoc), not the logged-in user. See issue #252.
	$salerepresentative_name          = $mysoc->name;
	$salerepresentative_office_phone  = $mysoc->phone;
	$salerepresentative_office_fax    = $mysoc->fax;
	$salerepresentative_email         = $mysoc->email;
}
if (empty($salerepresentative_office_phone)) {
	$salerepresentative_office_phone = $mysoc->phone;
}
if (empty($salerepresentative_office_fax)) {
	$salerepresentative_office_fax = $mysoc->fax;
}
if (empty($salerepresentative_email)) {
	$salerepresentative_email = $mysoc->email;
}


$outputlangs = $langs;
// Output language (client lang)
if (isset($object->thirdparty->default_lang)) {
	$newlang = $object->thirdparty->default_lang;
}
// @phan-suppress-next-line PhanUndeclaredProperty
if (isset($object->default_lang)) {
	$newlang = $object->default_lang;
}
if (GETPOST('lang_id', 'alphanohtml') != "") {
	$newlang = GETPOST('lang_id', 'alphanohtml');
}
if (!empty($newlang)) {
	$outputlangs = new Translate("", $conf);
	$outputlangs->setDefaultLang($newlang);
}
$outputlangs->load("einvoicing@einvoicing");


// Project
if (! ($object->project instanceof Project)) {
	if (method_exists($object, 'fetchProject')) {
		$object->fetchProject();
	} else {
		$object->fetch_project();
	}
}

$invoiceRefDocs = [];

// Source invoice (credit note)
if ($object->type == $object::TYPE_CREDIT_NOTE && !empty($object->fk_facture_source)) {
	$sourceFact = new Facture($this->db);
	if ($sourceFact->fetch($object->fk_facture_source) > 0) {
		$sourceFactDate = new DateTime(dol_print_date($sourceFact->date, 'dayrfc'));
		$invoiceRefDocs[] = [
			'ref' => $sourceFact->ref,
			'date' => $sourceFactDate,
			'type' => '381' 				// 381 = Credit note
		];
		dol_syslog(get_class($this) . '::generateXML Set source invoice reference ' . $sourceFact->ref . ' for credit note ' . $object->ref);
	} else {
		if ($object->id == 0) { // Specimen case.
			$specimenRefDoc = $object->fk_facture_source ?? 'FA0000-SPECIMEN';
			$sourceFactDate = new DateTime(dol_print_date(dol_now() - 100, 'dayrfc'));
			$invoiceRefDocs[] = [
				'ref' => $specimenRefDoc,
				'date' => $sourceFactDate,
				'type' => '381' 				// 381 = Credit note
			];
			dol_syslog(get_class($this) . '::generateXML Set source invoice reference ' . $specimenRefDoc . ' for credit note specimen ' . $object->ref);
		} else {
			dol_syslog(get_class($this) . '::generateXML Cannot fetch source invoice id=' . $object->fk_facture_source . ' for credit note ' . $object->ref, LOG_WARNING);
		}
	}
}

// Collect lines into $linesData array
$linesData         	= [];
$taxBreakdown		= [];
$lines_total_ht 	= $lines_total_tva = $lines_total_ttc = 0;
$grand_total_ht    	= $grand_total_tva = $grand_total_ttc = 0;
$prepaidAmount     	= 0;
$depositlines      	= [];
$globalDiscounts	= [];
$billing_period    	= [];
$numligne          	= 1;

foreach ($object->lines as $line) {
	$isDepositLine = 0;

	// Skip title / subtotal / page-break lines. These are product_type 9 pseudo-lines that carry no VAT, so
	// they must not reach getCategoryRate() (would trigger a VATEX exemption error on rate 0 / no code).
	// Detection is centralized in _isLineFromExternalModule(), which covers both the legacy modSubtotal
	// module and the native core subtotal feature.
	$isSubTotalLine = $this->_isLineFromExternalModule($line, $object->element, 'modSubtotal');
	if ($isSubTotalLine) {
		continue;
	}

	// For credit notes EN16931 requires positive amounts
	if ($object->type == $object::TYPE_CREDIT_NOTE) {
		$line->subprice     = abs($line->subprice);
		$line->subprice_ttc = abs($line->subprice_ttc);
		$line->total_ht     = abs($line->total_ht);
		$line->total_ttc    = abs($line->total_ttc);
		$line->total_tva    = abs($line->total_tva);
		$line->qty          = abs($line->qty);
	}

	// VAT category and exemption reason of the line
	$tmparray = $this->getCategoryRate($line, $mysoc, $object);

	$categoryVAT = $tmparray['categoryVAT'];
	$exemptionReason = $tmparray['ExemptionReason'];
	$exemptionReasonCode = $tmparray['ExemptionReasonCode'];

	// if ($line->subprice < 0 || $line->subprice_ttc < 0) {
	// 	throw new Exception("NEGATIVE_UNIT_PRICE_NOT_ALLOWED: Unit price in lines can't be negative. Try to edit the line with ID " . $line->id);
	// }

	// Deposit line - When the final invoice has a line from a deposit invoice, we must store the deposit invoice line + reference
	// This is the first method described into XP_Z12-014 using the line into field BT-153 / BT-154
	// The second method need to use the field BT-113. We don't use it as we use the first method.
	$depositFactRef  = null;
	$depositFactDate = null;
	if ($line->desc == '(DEPOSIT)') {
		$isDepositLine   = 1;
		$depositFactRef  = "";
		$depositFactDate = new DateTime();

		$discount    = new DiscountAbsolute($this->db);
		$resdiscount = $discount->fetch($line->fk_remise_except);
		dol_syslog("Fetch discount " . $line->fk_remise_except . ", res=" . $resdiscount, LOG_DEBUG);

		if ($resdiscount > 0) {
			$origFact    = new Facture($this->db);
			$resOrigFact = $origFact->fetch($discount->fk_facture_source);
			dol_syslog("Fetch origFact " . $discount->fk_facture_source . ", res=" . $resOrigFact, LOG_DEBUG);
			if ($resOrigFact > 0) {
				$depositFactRef  = $origFact->ref;
				$depositFactDate = new DateTime(dol_print_date($origFact->date, 'dayrfc'));
			}
		}
		$prepaidAmount += abs($line->total_ttc);
		$line->qty      = -$line->qty;				// For a deposit, ->qty should be -1.
		$line->subprice = abs($line->subprice);

		$depositlines[] = [
			'lineId'      => $numligne,
			'invoiceRef'  => $depositFactRef,		// BT-153
			'invoiceDate' => $depositFactDate,
		];

		// Ref of parent deposit invoice
		$invoiceRefDocs[] = [
			'ref' => $depositFactRef,				// BT-25 EXT-FR-FE-BG-06
			'date' => $depositFactDate,				// BT-26 EXT-FR-FE-BG-06
			'type' => '386' 						// 386 = Deposit invoice EXT-FR-FE-137 EXT-FR-FE-02
		];
	}

	// Discount line (Amount) - When fk_remise_except > 0 this is a global discount.
	if ($line->desc != '(DEPOSIT)' && $line->fk_remise_except > 0) {
		$isDiscountLine = 1;

		$discount    = new DiscountAbsolute($this->db);
		$resdiscount = $discount->fetch($line->fk_remise_except);
		dol_syslog("Fetch discount " . $line->fk_remise_except . ", res=" . $resdiscount, LOG_DEBUG);

		$globalDiscounts[] = array(
			'value' => (float) $discount->total_ht,
			'reason' => $discount->description ?? 'REMISE',
			'taxRate' => (float) $discount->tva_tx,
			'categoryVAT' => $categoryVAT,
		);

		// Add (or update) VAT rate to $taxBreakdown
		if (!isset($taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')])) {
			$taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')] = ['tva_tx' => '', 'vat_src_code' => '', 'categoryVAT' => '', 'ExemptionReasonCode' => '', 'ExemptionReason' => '', 'totalHT' => 0, 'totalTVA' => 0];
		}
		$taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')]['tva_tx'] = $line->tva_tx;
		$taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')]['vat_src_code'] = $line->vat_src_code;
		$taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')]['categoryVAT'] = $categoryVAT;
		$taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')]['ExemptionReasonCode'] = $exemptionReasonCode;
		$taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')]['ExemptionReason'] = $exemptionReason;

		$taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')]['totalHT']  -= $discount->total_ht;
		$taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')]['totalTVA'] -= $discount->total_tva;


		$grand_total_ht  -= $discount->total_ht;
		$grand_total_ttc -= $discount->total_ttc;
		$grand_total_tva -= $discount->total_tva;

		continue;	// We don't want to add this line into linesData as it is not a real line but a global discount. It will be added into the headerAllowancesCharges section.
	}

	// Discount line (Percent) - When remise_percent > 0.
	$LineDiscountPercent = (float) ($line->remise_percent ?? 0);

	// Product labels (multilangs)
	$libelle = $description = "";
	if ($newlang != "") {
		if (!isset($line->multilangs)) {
			$tmpproduct = new Product($db);
			$resproduct = $tmpproduct->fetch($line->fk_product);
			if ($resproduct > 0) {
				$getm = $tmpproduct->getMultiLangs();
				if ($getm < 0) {
					dol_syslog("EInvoicing error fetching multilang for product error is " . $tmpproduct->error, LOG_DEBUG);
				}
				$line->multilangs = $tmpproduct->multilangs;
			} else {
				dol_syslog("EInvoicing error fetching product", LOG_DEBUG);
			}
		}
		if (isset($line->multilangs)) {
			$libelle     = $line->multilangs[$newlang]["label"];
			$description = $line->multilangs[$newlang]["description"];
		}
	}
	if (empty($libelle)) {
		$libelle = $line->product_label ? $line->product_label : "";
	}
	if (empty($description)) {
		$description = $line->desc ? dol_string_nohtmltag($line->desc, 0) : "";
	}
	if (empty($libelle) && !empty($description)) {
		$libelle = dol_trunc(dolGetFirstLineOfText(dol_string_nohtmltag($description)), 49, 'right', 'UTF-8', 1);
		if ($libelle == $description) {
			$description = "";
		}
	}

	// Billing period of the line
	$linePeriodStart = null;
	$linePeriodEnd   = null;
	if (!empty($line->date_start)) {
		$billing_period["start"][$numligne] = $line->date_start;
		$linePeriodStart = $this->_tsToDateTime($line->date_start);
	}
	if (!empty($line->date_end)) {
		$billing_period["end"][$numligne] = $line->date_end;
		$linePeriodEnd = $this->_tsToDateTime($line->date_end);
	}


	// Set amounts for the line

	$line_unit_price = $line->subprice;
	$line_unit_price = price2num($line_unit_price, 2);		// Must be rounded to 2 digits. Not used directly, may be used as intermediate data.

	$line_unit_price_ttc = $line->subprice_ttc;
	$line_unit_price_ttc = price2num($line_unit_price_ttc, 2);	// Must be rounded to 2 digits.

	$amountdiscount = 0;
	$line_unit_price_with_discount = $line_unit_price;
	if ($line->remise_percent) {
		$amountdiscount = price2num($line_unit_price * $line->remise_percent / 100, 2);
		$line_unit_price_with_discount = price2num($line_unit_price - $amountdiscount, 2);
	}

	// We need to recalculate the total using the Unit price rounded (netpriceamount) * Quantity, and rounding all temporary calculations to 2.
	// This means we may get a different result than Dolibarr default calculation if:
	// - MAIN_APPLY_DISCOUNT_ON_UNIT_PRICE_THEN_ROUND_BEFORE_MULTIPLICATION_BY_QTY was not set (if Einvoice is on, it is recommended to set it to 2 or 'MU' with unit price of 2, so accuracy will be reduced to match einvoice rule)
	// or if
	// - MAIN_APPLY_DISCOUNT_ON_UNIT_PRICE_THEN_ROUND_BEFORE_MULTIPLICATION_BY_QTY is set to a value different than 2, or, if set to 'MU', if the currency accuracy for unit price has a different number of decimals than 2.
	$line_total_ht = price2num($line_unit_price_with_discount * $line->qty, 2);
	$line_total_tva = price2num($line_unit_price_with_discount * $line->qty * ($line->tva_tx > 0 ? number_format($line->tva_tx, 2, '.', '') / 100 : 0), 2);
	$line_total_ttc = price2num($line_total_ht + $line_total_tva, 2);

	// Uncomment for test using the most accurate possible calculation (but not following the e-invoice rule to round to 2 digit at each step)
	/*
	$line_unit_price = price2num($line->subprice, 'MU');
	$line_unit_price_with_discount = price2num($line->subprice * (1 - $line->remise_percent / 100), 'MU');
	$line_total_ht = $line->total_ht;
	$line_total_tva = $line->total_tva;
	$line_total_ttc = $line->total_ttc;
	*/

	// Progress / situation invoices (facture de situation): Dolibarr keeps the FULL (100%) line
	// amount in subprice*qty, while the amount actually billed for THIS situation - already net of
	// the progress billed by previous situations - lives in $line->total_ht (the value shown on the
	// PDF). Without correction the e-invoice would bill 100% of every line (issue #258). We fold the
	// progress fraction into the unit price (BT-146) and keep the real billed quantity (BT-129), so
	// BT-146 * BT-129 stays consistent with the line net amount BT-131. Unit price and quantity are
	// both emitted with 2 decimals, so scaling the price - not the quantity - preserves that
	// consistency even for non-round progress percentages (e.g. 33.33%).
	if ($object->type == $object::TYPE_SITUATION) {
		$line_full_ht = $line_total_ht;	// = $line_unit_price_with_discount * $line->qty (100% of the line)
		$progress_ratio = ($line_full_ht != 0.0) ? ((float) $line->total_ht / $line_full_ht) : 0.0;

		$line_unit_price = price2num($line_unit_price * $progress_ratio, 2);
		$line_total_ht   = price2num($line_unit_price_with_discount * $progress_ratio * $line->qty, 2);
		$line_total_tva  = price2num($line_total_ht * ($line->tva_tx > 0 ? number_format($line->tva_tx, 2, '.', '') / 100 : 0), 2);
		$line_total_ttc  = price2num($line_total_ht + $line_total_tva, 2);
	}

	// Add (or update) VAT rate to $taxBreakdown
	if (!isset($taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')])) {
		$taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')] = ['tva_tx' => '', 'vat_src_code' => '', 'categoryVAT' => '', 'ExemptionReasonCode' => '', 'ExemptionReason' => '', 'totalHT' => 0, 'totalTVA' => 0];
	}
	$taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')]['tva_tx'] = $line->tva_tx;
	$taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')]['vat_src_code'] = $line->vat_src_code;
	$taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')]['categoryVAT'] = $categoryVAT;
	$taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')]['ExemptionReasonCode'] = $exemptionReasonCode;
	$taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')]['ExemptionReason'] = $exemptionReason;

	$taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')]['totalHT']  += $line_total_ht;
	$taxBreakdown[$line->tva_tx.($line->vat_src_code ? ' ('.$line->vat_src_code.')' : '')]['totalTVA'] += $line_total_tva;

	$lines_total_ht  += $line_total_ht;
	$lines_total_ttc += $line_total_ttc;
	$lines_total_tva += $line_total_tva;

	$grand_total_ht  += $line_total_ht;
	$grand_total_ttc += $line_total_ttc;
	$grand_total_tva += $line_total_tva;



	// Filling $linesData (based on $lineTemplate)
	$linesData[$numligne] = [
		'lineid'                    => $numligne,
		'linestatuscode'            => 'NA',
		'linestatusreasoncode'      => 'NA',
		'lineNote'                  => null,

		'prodname'                  => $libelle,			// BT-153
		'proddesc'                  => $description,		// BT-154
		'prodsellerid'              => $line->product_ref ? $line->product_ref : "0000",
		'prodbuyerid'               => null,
		'prodglobalidtype'          => null,
		'prodglobalid'              => null,
		'prodmultilangs'            => [],
		'prodClassificationCode'    => null,
		'prodClassificationScheme'  => null,
		'prodOriginCountry'         => null,

		// Mandatory by Factur-X, EN 16931
		// This is the unit price, excluding tax. We can use
		// $line_unit_price_with_discount
		// or
		//$line_unit_price but we must add block TradeAllowanceCharge
		//'netpriceamount'            => $line_unit_price_with_discount,		// BT-148 / BT-146
		'netpriceamount'            => $line_unit_price,		// BT-148 / BT-146
		'netpricebasisquantity'     => null,
		'netpricebasisquantityunitcode' => null,

		'billedquantity'            => $line->qty,
		'billedquantityunitcode'    => "C62",
		'chargeFreeQuantity'        => null,
		'chargeFreeQuantityunitcode' => null,
		'packageQuantity'           => null,
		'packageQuantityunitcode'   => null,

		'lineTotalAmount'           => $line_total_ht,
		'totalAllowanceChargeAmount' => null,

		// For section ApplicableTradeTax
		'categoryCode'              => $categoryVAT,
		'typeCode'                  => 'VAT',
		'rateApplicablePercent'     => $line->tva_tx > 0 ? number_format($line->tva_tx, 2, '.', '') : '0.00',

		'tva_tx'                    => $line->tva_tx,				// For comments only
		'vat_src_code'              => $line->vat_src_code ?? '',	// For comments only
		'ExemptionReason'           => $exemptionReason,			// Set when vat rate is 0
		'ExemptionReasonCode'       => $exemptionReasonCode,		// Set when vat rate is 0

		'calculatedAmount'          => null,

		'lineAllowances'            => [],
		'lineGrossPriceAllowances'  => [],
		'lineremisepercent'         => $line->remise_percent ?? 'NA',

		'linePeriodStart'           => $linePeriodStart,
		'linePeriodEnd'             => $linePeriodEnd,

		'additionalRefDocs'         => [],

		'isDepositLine'             => (bool) $isDepositLine,
		'depositInvoiceRef'         => $depositFactRef,
		'depositInvoiceDate'        => $depositFactDate,

		'parentDocumentNo'          => null,
		'is_deposit'                => $isDepositLine,
		'fk_remise'                 => $line->fk_remise_except ?? null,

		'discountPercent'       	=> $LineDiscountPercent,
	];



	// If a unit price including tax is known (rarely)
	if ($line_unit_price_ttc) {
		// This section seems not required.
		// It can be used if the price base is including tax (TTC) and without discount (= Catalog public unit price for individual customers)
		$linesData[$numligne]['grosspriceamount'] = $line_unit_price_ttc;
		$linesData[$numligne]['grosspricebasisquantity'] = null;
		$linesData[$numligne]['grosspricebasisquantityunitcode'] = null;
	}

	$numligne++;
}

// already used credit note amount
$usedcreditnoteamount = 0;
$usedcreditnote = array();
$sql = "SELECT re.rowid, re.amount_ht, re.amount_tva, re.amount_ttc,";
$sql .= " re.description, re.fk_facture_source";
$sql .= " FROM ".MAIN_DB_PREFIX."societe_remise_except as re";
$sql .= " WHERE fk_facture = ".((int) $object->id) ." AND description = '(CREDIT_NOTE)'";
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$usedcreditnoteamount += abs($obj->amount_ttc);

		// Add used credit note into reference documents of invoice
		$usedCreditNoteFact = new Facture($this->db);
		if ($usedCreditNoteFact->fetch($obj->fk_facture_source) > 0) {
			$usedCreditNoteFactDate = new DateTime(dol_print_date($usedCreditNoteFact->date, 'dayrfc'));
			$invoiceRefDocs[] = [
				'ref' => $usedCreditNoteFact->ref,
				'date' => $usedCreditNoteFactDate,
				'type' => '381'
			];
		} else {
			dol_syslog("Error " . $db->error() . " when looking for credit note linked to invoice to calculate prepaid amount for invoice " . $object->id, LOG_WARNING);
		}
	}
} else {
	dol_syslog("Error " . $db->error() . " when looking for credit note linked to invoice to calculate prepaid amount for invoice " . $object->id, LOG_WARNING);
}

// Already paid deposits
$getAlreadyPaid = $object->getSommePaiement();

$prepaidAmount  = $object->sumpayed + $getAlreadyPaid + $usedcreditnoteamount;

// Delivery date
$deliveryDate = !empty($deliveryDateList)
	? new DateTime(dol_print_date($deliveryDateList[0], 'dayrfc'))
	: new DateTime(dol_print_date($object->date, 'dayrfc'));



// Filling $invoiceData (based on $invoiceTemplate)
$invoiceData = [
	// Document part
	'documentno'           => $object->ref,												// BT-25
	'documenttypecode'     => $this->_getTypeOfInvoice($object),						// BT-3 Set the type of invoice (standard, deposit, credit note)
	'documentdate'         => new DateTime(dol_print_date($object->date, 'dayrfc')),	// BT-26
	'invoiceCurrency'      => $object->multicurrency_code,
	'taxCurrency'          => null,
	'documentname'         => null,
	'documentlanguage'     => $outputlang,
	'effectiveSpecifiedPeriod' => 'NA',

	'documentDeliveryDate' => $deliveryDate,

	'invoicingPeriodStart' => null,
	'invoicingPeriodEnd'   => null,

	'businessProcessId'    => $this->getBillingProcessID($object),		// B1, B2, B3, B4 / S1, S2, S3, S4 / M1, M2, M3, M4
	'isTestDocument'       => !empty($object->specimen),

	// Notes
	'documentNotePublic'   => $object->note_public ?: "",
	'documentNotePMT'      => getDolGlobalString('EINVOICING_PMT') ?: $outputlangs->trans("NoInvoiceCollectionFees"),
	'documentNotePMD'      => getDolGlobalString('EINVOICING_PMD') ?: $outputlangs->trans('NoLatePaymentFees'),
	'documentNoteAAB'      => getDolGlobalString('EINVOICING_AAB') ?: $outputlangs->trans('NoEarlyPaymentDiscount'),
	'documentNotes'        => [],

	// Seller part
	'sellername'                => $mysoc->name,
	'sellerids'                 => $myidprof,

	'sellerlineone'             => $mysoc->address      ?? 'ADDRESS EMPTY',
	'sellerlinetwo'             => "",
	'sellerlinethree'           => "",
	'sellerpostcode'            => $mysoc->zip          ?? 'ZIP EMPTY',
	'sellercity'                => $mysoc->town         ?? 'NO TOWN',
	'sellercountry'             => $mysoc->country_code ?? 'COUNTRY NOT SET',
	'sellersubdivision'         => null,

	'sellercontactpersonname'   => $salerepresentative_name,
	'sellercontactdepartmentname' => null,
	'sellercontactphoneno'      => $salerepresentative_office_phone,
	'sellercontactfaxno'        => $salerepresentative_office_fax,
	'sellercontactemailaddr'    => $salerepresentative_email,

	'sellerCommunicationUriScheme' => $mySchemeUri,
	'sellerCommunicationUri'    => $myUri,

	'sellerGlobalIds'           => [['schemeID' => $mySchemeGlobalIdProf, 'value' => $myGlobalIdProf]],
	'sellerTaxRegistations'     => [['type' => 'VA', 'value' => $mysoc->tva_intra ?? 'FRSPECIMEN']],
	'sellervatnumber'           => $mysoc->tva_intra ?? 'FRSPECIMEN',

	'sellerLegalOrgId'          => $myidprof,
	'sellerLegalOrgScheme'      => $mySchemeIdProf,
	'sellerTradingName'         => $mysoc->name ?? 'SPECIMEN',

	// Buyer part
	'buyername'                 => $object->thirdparty->name ?? 'CUSTOMER',
	'buyerids'                  => $idprof ?: 'IDPROF',

	'buyerlineone'              => $object->thirdparty->address      ?? 'ADDRESS',
	'buyerlinetwo'              => "",
	'buyerlinethree'            => "",
	'buyerpostcode'             => $object->thirdparty->zip          ?? 'ZIP',
	'buyercity'                 => $object->thirdparty->town         ?? 'TOWN',
	'buyercountry'              => $object->thirdparty->country_code ?? 'COUNTRY',
	'buyersubdivision'          => null,

	'buyervatnumber'            => $object->thirdparty->tva_intra ?? '',
	'buyerGlobalIds'            => [['schemeID' => $schemeGlobalIdProf, 'value' => $globalIdProf]],

	'buyerLegalOrgId'           => $idprof,
	'buyerLegalOrgScheme'       => $schemeIdProf,
	'buyerTradingName'          => $object->thirdparty->name,

	'buyerReference'            => $object->array_options['options_d4d_service_code'] ?? null,

	// URIUniversalCommunication
	'buyerCommunicationUriScheme' => $schemeUri,
	'buyerCommunicationUri'    	=> $uri,

	'buyercontactpersonname'    => null,
	'buyercontactemailaddr'     => null,
	'buyercontactphoneno'       => null,

	// Totals parts
	'grandTotalAmount'          => $grand_total_ttc,
	'duePayableAmount'          => $grand_total_ttc - $prepaidAmount,
	'lineTotalAmount'           => $lines_total_ht,
	'chargeTotalAmount'         => 0.0,
	'allowanceTotalAmount'      => array_sum(array_column($globalDiscounts, 'value')), // We sum all global discounts defined in the invoice
	'taxBasisTotalAmount'       => $grand_total_ht,
	'taxTotalAmount'            => $grand_total_tva,
	'roundingAmount'            => null,
	'totalPrepaidAmount'        => $prepaidAmount,

	'iban_id'                   => $account->id,
	'iban'                      => $einvoicing->removeSpaces($account->iban),
	'bic'                       => $einvoicing->removeSpaces($account->bic),
	'accountName'               => $account_proprio,
	'accountRef'                => $account->ref,
	'accountLabel'              => $account->label,

	'paymentDueDate'            => new DateTime(dol_print_date($object->date_lim_reglement, 'dayrfc')),
	'paymentTermsText'          => $langs->transnoentitiesnoconv("PaymentConditions") . ": " . $langs->transnoentitiesnoconv("PaymentCondition" . $object->cond_reglement_code),

	// Allowances / charges part
	'headerAllowancesCharges'   => [],

	// Referenced documents part
	'invoiceRefDocs'            => $invoiceRefDocs,		// BG-3
	'orderReference'            => $promise_code,
	'contractReference'         => $object->array_options['options_d4d_contract_number'] ?? null,
	'despatchAdviceRef'         => null,

	// VAT breakdown for section ApplicableHeaderTradeSettlement
	'taxBreakdown'              => $taxBreakdown,

	// Internal data (useful for the builder)
	'_chorus'                   => $chorus,
	'_depositlines'             => $depositlines,
	'_globalDiscounts'          => $globalDiscounts,
	'_customerOrderReferenceList' => $customerOrderReferenceList,
	'_project'                  => ($object->project instanceof Project) ? $object->project : null,
];


// Payment mode
if ($object->mode_reglement_code) {
	$invoiceData['paymentMeansCode'] = $this->_getPaymentMeanNumber($object);
	$invoiceData['paymentMeansText'] = $langs->transnoentitiesnoconv("PaymentType" . $object->mode_reglement_code);
}


// Delivery address (CII ShipToTradeParty / BG-15)
// Resolve a deliver-to address and expose it so the CII builder can emit a dedicated deliver-to
// party. Resolution priority:
//   1) external "SHIPPING" contact attached to the invoice;
//   2) fallback: delivery address carried by a linked shipment (expedition.fk_delivery_address).
// buildShipToTradePartyBuilder function only emits the node when the resolved address
// actually differs from the buyer (bill-to) address and carries a country code; otherwise it falls
// back to the buyer party. Nothing resolved => keys stay unset => ship-to = buyer is preserved.
$shipAddress = null;
if (method_exists($object, 'liste_contact')) {
	$shipContacts = $object->liste_contact(-1, 'external', 0, 'SHIPPING');
	if (is_array($shipContacts) && count($shipContacts) > 0) {
		if (count($shipContacts) > 1) {
			dol_syslog('einvoicing: invoice ' . $object->id . ' has ' . count($shipContacts) . ' external SHIPPING contacts; using the first (contact id ' . $shipContacts[0]['id'] . ')', LOG_WARNING);
		}
		require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';
		$shipContact = new Contact($db);
		if ($shipContact->fetch($shipContacts[0]['id']) > 0) {
			$shipName = trim($shipContact->getFullName($outputlangs));
			if ($shipName === '') {
				$shipName = $object->thirdparty->name;
			}
			$shipAddress = array(
				'name'    => $shipName,
				'address' => $shipContact->address,
				'zip'     => $shipContact->zip,
				'town'    => $shipContact->town,
				'country' => $shipContact->country_code,
			);
		}
	}
}

// Fallback: a linked shipment may carry a distinct delivery address (no SHIPPING contact needed).
if ($shipAddress === null && !empty($object->linkedObjectsIds['shipping']) && is_array($object->linkedObjectsIds['shipping'])) {
	require_once DOL_DOCUMENT_ROOT . '/expedition/class/expedition.class.php';
	require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';
	foreach ($object->linkedObjectsIds['shipping'] as $expeditionId) {
		$tmpexpedition = new Expedition($db);
		if ($tmpexpedition->fetch($expeditionId) > 0 && !empty($tmpexpedition->fk_delivery_address)) {
			$shipContact = new Contact($db);
			if ($shipContact->fetch($tmpexpedition->fk_delivery_address) > 0) {
				$shipName = trim($shipContact->getFullName($outputlangs));
				if ($shipName === '') {
					$shipName = $object->thirdparty->name;
				}
				$shipAddress = array(
					'name'    => $shipName,
					'address' => $shipContact->address,
					'zip'     => $shipContact->zip,
					'town'    => $shipContact->town,
					'country' => $shipContact->country_code,
				);
				break;
			}
		}
	}
}

if ($shipAddress !== null) {
	$invoiceData['_shipFromContactBill'] = array(
		'address' => $object->thirdparty->address,
		'zip'     => $object->thirdparty->zip,
		'town'    => $object->thirdparty->town,
		'country' => $object->thirdparty->country_code,
	);
	$invoiceData['_shipFromContactShip'] = $shipAddress;
}


// Section to control data and throw errors in case of problem, to avoid generating non compliant XML
// --------------------------------------------------------------------------------------------------
if (empty($idprof)) {
	throw new Exception('BADTHIRDPARTYPROFID: The main professional ID of the thirdparty ' . $object->name . ' is empty.');
}
if (empty($myidprof)) {
	throw new Exception('BADPROFID: The professional ID of your company is empty. Fix this in your company or module setup page.');
}
if ($mySchemeIdProf == "0002" && strlen($myidprof) != 9) {
	throw new Exception('BADPROFID: The professional ID ' . $myidprof . ' has type SIREN but length is not 9 characters. Fix this in your company or einvoice module setup page.');
}
if ($mysoc->country_code == 'FR' && !empty($mysoc->idprof1) && !empty($mysoc->idprof2)) {
	if (strpos(preg_replace('/\s+/', '', $mysoc->idprof2), preg_replace('/\s+/', '', $mysoc->idprof1)) !== 0) {
		throw new Exception('BADVALUEFORSIRENORSIRET: The seller has both a SIREN and SIRET but SIRET does not start with value of SIREN.');
	}
}
if ($object->thirdparty->country_code == 'FR' && !empty($object->thirdparty->idprof1) && !empty($object->thirdparty->idprof2)) {
	if (strpos(preg_replace('/\s+/', '', $object->thirdparty->idprof2), preg_replace('/\s+/', '', $object->thirdparty->idprof1)) !== 0) {
		throw new Exception('BADVALUEFORSIRENORSIRET: The buyer has both a SIREN "' . $object->thirdparty->idprof1 . '" and SIRET "' . $object->thirdparty->idprof2 . '" but SIRET does not start with value of SIREN.');
	}
}
if (!empty($mysoc->tva_intra) && !empty($mysoc->country_code) && substr($mysoc->tva_intra, 0, 2) != $mysoc->country_code) {
	throw new Exception('BADVATNUMBER: The VAT number of your company must start with your country code.');
}
if (!empty($object->thirdparty->tva_intra) && !empty($object->thirdparty->country_code) && substr($object->thirdparty->tva_intra, 0, 2) != $object->thirdparty->country_code) {
	throw new Exception('BADVATNUMBER: The VAT number of the thirdparty ' . $object->thirdparty->name . ' must start with its 2 letter country code.');
}


// In output, we have
// $invoiceData and $linesData
