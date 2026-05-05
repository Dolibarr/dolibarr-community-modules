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
 * \file    lib/buildinvoicelines.inc.php
 * \ingroup pdpconnectfr
 * \brief   Code to generate the array of invoice and lines
 */



// Use customer language
if (empty($outputlangs) || ! ($outputlangs instanceof Translate)) {
	$outputlangs = $langs;
}
$newlang = '';

$this->sourceinvoice = $invoice;
$outputlang = $langs->defaultlang;

// Load PDPConnectFr class
$pdpconnectfr = new PdpConnectFr($db);

// Reload object
$facture = new Facture($db);
$object = $facture->fetch($invoice->id) > 0 ? $facture : $invoice;
if (!is_object($invoice->thirdparty)) {
	$invoice->fetch_thirdparty();
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
$chorusErrors = [];
if (getDolGlobalInt('PDPCONNECTFR_USE_CHORUS')) {
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
} else {
	$account->fetch(getDolGlobalString('FACTURX_DEFAULT_BANK_ACCOUNT'));
}
$account_proprio = trim($account->owner_name);
if ($account_proprio == '') {
	dol_syslog('Bank account holder name is empty, please correct it, use socname instead but it could be inccorrect for XRechnung BT-85: Payment account name', LOG_WARNING);
	$account_proprio = $mysoc->name;
}

// Buyer intra VAT (calculated if missing)
if ($object->thirdparty->tva_assuj && empty($object->thirdparty->tva_intra)) {
	$object->thirdparty->tva_intra = $pdpconnectfr->thirdpartyCalcVATIntra($object->thirdparty);
}

// Seller identifiers (mysoc)
$myidprof          = idprof($mysoc);
$mySchemeIdProf    = $this->getIEC6523Code($mysoc->country_code);
$myGlobalIdProf    = idprof($mysoc);
$mySchemeGlobalIdProf = $this->getIEC6523Code($mysoc->country_code, 1);
$myUri             = $pdpconnectfr->getSellerCommunicationURI(0);
$mySchemeUri       = $this->getIEC6523Code($mysoc->country_code, 2);

// Buyer identifiers (thirdparty)
$idprof            = thirdpartyidprof($object) ?? '';
$schemeIdProf      = $this->getIEC6523Code($object->thirdparty->country_code);
$globalIdProf      = thirdpartyidprof($object) ?? '';
$schemeGlobalIdProf = $this->getIEC6523Code($object->thirdparty->country_code, 1);
$uri               = $pdpconnectfr->getBuyerCommunicationURI($object->thirdparty);
$schemeUri         = $this->getIEC6523Code($object->thirdparty->country_code, 2);

// Seller contact
$usercontacts = $object->getIdContact('internal', 'SALESREPFOLL');
$object->user = null;
if (!empty($usercontacts) && $object->fetch_user($usercontacts[0]) > 0) {
	$salerepresentative_name          = $object->user->getFullName($outputlangs);
	$salerepresentative_office_phone  = $object->user->office_phone;
	$salerepresentative_office_fax    = $object->user->office_fax;
	$salerepresentative_email         = $object->user->email;
} else {
	$salerepresentative_name          = $user->getFullName($outputlangs);
	$salerepresentative_office_phone  = $user->office_phone;
	$salerepresentative_office_fax    = $user->office_fax;
	$salerepresentative_email         = $user->email;
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

// Project
if (! ($invoice->project instanceof Project)) {
	$invoice->fetchProject();
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
			'type' => '381' // Credit note
		];
		dol_syslog(get_class($this) . '::generateXML Set source invoice reference ' . $sourceFact->ref . ' for credit note ' . $object->ref);
	} else {
		dol_syslog(get_class($this) . '::generateXML Cannot fetch source invoice id=' . $object->fk_facture_source . ' for credit note ' . $object->ref, LOG_WARNING);
	}
}

// Collect lines into $linesData array
$linesData         = [];
$tabTVA            = [];
$grand_total_ht    = $grand_total_tva = $grand_total_ttc = 0;
$prepaidAmount     = 0;
$depositlines      = [];
$billing_period    = [];
$numligne          = 1;

foreach ($object->lines as $line) {
	$isDepositLine = 0;

	// Skip subtotal lines
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

	// if ($line->subprice < 0 || $line->subprice_ttc < 0) {
	// 	throw new Exception("NEGATIVE_UNIT_PRICE_NOT_ALLOWED: Unit price in lines can't be negative. Try to edit the line with ID " . $line->id);
	// }

	// Deposit line
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
		$line->qty      = -$line->qty;
		$line->subprice = abs($line->subprice);

		$depositlines[] = [
			'lineId'      => $numligne,
			'invoiceRef'  => $depositFactRef,
			'invoiceDate' => $depositFactDate,
		];
		$invoiceRefDocs[] = [
			'ref' => $depositFactRef,
			'date' => $depositFactDate,
			'type' => '386' // Prepayment invoice
		];
	}

	// Product labels (multilangs)
	$libelle = $description = "";
	if ($newlang != "") {
		if (!isset($line->multilangs)) {
			$tmpproduct = new Product($db);
			$resproduct = $tmpproduct->fetch($line->fk_product);
			if ($resproduct > 0) {
				$getm = $tmpproduct->getMultiLangs();
				if ($getm < 0) {
					dol_syslog("PDPConnectFR error fetching multilang for product error is " . $tmpproduct->error, LOG_DEBUG);
				}
				$line->multilangs = $tmpproduct->multilangs;
			} else {
				dol_syslog("PDPConnectFR error fetching product", LOG_DEBUG);
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

	// VAT category
	if ($line->tva_tx > 0) {
		if (empty($mysoc->tva_intra)) {
			throw new Exception('BADVATNUMBER: The VAT number of the thirdparty ' . $object->thirdparty->name . ' is mandatory when there is a non null VAT on at least on line.');
		}
		if (!$this->checkIfVatRateIsValid($line->tva_tx, $mysoc->country_code)) {
			throw new Exception('BADVATRATE[BR-FR-16]: The VAT rate ' . $line->tva_tx . ' on line ' . $line->id . ' is not a valid string value for country ' . $mysoc->country_code . '.');
		}
		$categoryVAT = 'S';
	} else {
		$categoryVAT = 'K';
		if (empty($mysoc->tva_assuj)) {
			$categoryVAT = 'E';
		} elseif (!$invoice->thirdparty->isInEEC()) {
			$categoryVAT = 'G';
		} elseif ($mysoc->isInEEC() && $invoice->thirdparty->isInEEC() && $mysoc->country_code != $invoice->thirdparty->country_code) {
			$categoryVAT = 'K';
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

	// Cumulative VAT totals
	if (!isset($tabTVA[$line->tva_tx])) {
		$tabTVA[$line->tva_tx] = ['totalHT' => 0, 'totalTVA' => 0];
	}
	$tabTVA[$line->tva_tx]['totalHT']  += $line->total_ht;
	$tabTVA[$line->tva_tx]['totalTVA'] += $line->total_tva;

	$grand_total_ht  += $line->total_ht;
	$grand_total_ttc += $line->total_ttc;
	$grand_total_tva += $line->total_tva;

	// Filling $linesData (based on $lineTemplate)
	$linesData[$numligne] = [
		'lineid'                    => $numligne,
		'linestatuscode'            => 'NA',
		'linestatusreasoncode'      => 'NA',
		'lineNote'                  => null,

		'prodname'                  => $libelle,
		'proddesc'                  => $description,
		'prodsellerid'              => $line->product_ref ? $line->product_ref : "0000",
		'prodbuyerid'               => null,
		'prodglobalidtype'          => null,
		'prodglobalid'              => null,
		'prodmultilangs'            => [],
		'prodClassificationCode'    => null,
		'prodClassificationScheme'  => null,
		'prodOriginCountry'         => null,

		'grosspriceamount'          => $line->subprice,
		'grosspricebasisquantity'   => null,
		'grosspricebasisquantityunitcode' => null,

		'netpriceamount'            => $line->subprice,
		'netpricebasisquantity'     => null,
		'netpricebasisquantityunitcode' => null,

		'billedquantity'            => $line->qty,
		'billedquantityunitcode'    => "C62",
		'chargeFreeQuantity'        => null,
		'chargeFreeQuantityunitcode' => null,
		'packageQuantity'           => null,
		'packageQuantityunitcode'   => null,

		'lineTotalAmount'           => $line->total_ht,
		'totalAllowanceChargeAmount' => null,

		'categoryCode'              => $categoryVAT,
		'typeCode'                  => 'VAT',
		'rateApplicablePercent'     => $line->tva_tx > 0 ? number_format($line->tva_tx, 2, '.', '') : '0.00',
		'calculatedAmount'          => null,
		'exemptionReason'           => null,
		'exemptionReasonCode'       => null,

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
	];

	$numligne++;
}

// Already paid deposits
$getAlreadyPaid = $object->getSommePaiement();
// $prepaidAmount  = $object->sumpayed + $prepaidAmount;
$prepaidAmount  = $object->sumpayed + $getAlreadyPaid;

// Delivery date
$deliveryDate = !empty($deliveryDateList)
	? new DateTime(dol_print_date($deliveryDateList[0], 'dayrfc'))
	: new DateTime(dol_print_date($invoice->date, 'dayrfc'));

// Filling $invoiceData (based on $invoiceTemplate)
$invoiceData = [
	// Document part
	'documentno'           => $object->ref,
	'documenttypecode'     => $this->_getTypeOfInvoice($object),
	'documentdate'         => new DateTime(dol_print_date($object->date, 'dayrfc')),
	'invoiceCurrency'      => $conf->currency,
	'taxCurrency'          => null,
	'documentname'         => null,
	'documentlanguage'     => $outputlang,
	'effectiveSpecifiedPeriod' => 'NA',

	'documentDeliveryDate' => $deliveryDate,

	'invoicingPeriodStart' => null,
	'invoicingPeriodEnd'   => null,

	'businessProcessId'    => $this->getBillingProcessID($object),
	'isTestDocument'       => !empty($invoice->specimen),

	// Notes
	'documentNotePublic'   => dol_concatdesc(
		$object->note_public ?: "",
		' - Einvoice generated by Dolibarr ' . DOL_VERSION
	),
	'documentNotePMT'      => getDolGlobalString('PDPCONNECTFR_PMT') ?: $outputlangs->trans("NoInvoiceCollectionFees"),
	'documentNotePMD'      => getDolGlobalString('PDPCONNECTFR_PMD') ?: $outputlangs->trans('NoLatePaymentFees'),
	'documentNoteAAB'      => getDolGlobalString('PDPCONNECTFR_AAB') ?: $outputlangs->trans('NoEarlyPaymentDiscount'),
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

	'buyerCommunicationUriScheme' => $schemeUri,
	'buyerCommunicationUri'    	=> $uri,

	'buyercontactpersonname'    => null,
	'buyercontactemailaddr'     => null,
	'buyercontactphoneno'       => null,

	// Totals parts
	'grandTotalAmount'          => $grand_total_ttc,
	'duePayableAmount'          => $grand_total_ttc - $prepaidAmount,
	'lineTotalAmount'           => $grand_total_ht,
	'chargeTotalAmount'         => 0.0,
	'allowanceTotalAmount'      => 0.0,
	'taxBasisTotalAmount'       => $grand_total_ht,
	'taxTotalAmount'            => $grand_total_tva,
	'roundingAmount'            => null,
	'totalPrepaidAmount'        => $prepaidAmount,

	// Payment part
	'paymentMeansCode'          => $this->_getPaymentMeanNumber($object),
	'paymentMeansText'          => $langs->transnoentitiesnoconv("PaymentType" . $object->mode_reglement_code),
	'iban'                      => $pdpconnectfr->removeSpaces($account->iban),
	'bic'                       => $pdpconnectfr->removeSpaces($account->bic),
	'accountName'               => $account_proprio,

	'paymentDueDate'            => new DateTime(dol_print_date($object->date_lim_reglement, 'dayrfc')),
	'paymentTermsText'          => $langs->transnoentitiesnoconv("PaymentConditions") . ": " . $langs->transnoentitiesnoconv("PaymentCondition" . $object->cond_reglement_code),

	// Allowances / charges part
	'headerAllowancesCharges'   => [],

	// Referenced documents part
	'invoiceRefDocs'            => $invoiceRefDocs,
	'orderReference'            => $promise_code,
	'contractReference'         => $object->array_options['options_d4d_contract_number'] ?? null,
	'despatchAdviceRef'         => null,

	// VAT breakdown
	'taxBreakdown'              => $tabTVA,

	// Internal data (useful for the builder)
	'_chorus'                   => $chorus,
	'_depositlines'             => $depositlines,
	'_customerOrderReferenceList' => $customerOrderReferenceList,
	'_project'                  => ($invoice->project instanceof Project) ? $invoice->project : null,
];



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
