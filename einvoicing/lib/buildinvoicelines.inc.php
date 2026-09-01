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
 * @phan-file-suppress PhanAccessMethodPrivate
 */

/**
 * @var Conf 		$conf
 * @var DoliDB     	$db
 * @var Societe    	$mysoc
 * @var Translate 	$langs
 * @var User       	$user
 * @var Societe 	$buyerParty
 * @var Facture 	$object
 *
 * @var Translate 	$outputlangs
 * @var Facture    	$invoice
 * @var CIIProtocol|FacturXProtocol	$this
 */
'
@phan-var-force Translate 	$langs
@phan-var-force Translate 	$outputlangs
@phan-var-force Facture   	$invoice
@phan-var-force CIIProtocol|FacturXProtocol	$this
';

// Use customer language
if (!isset($outputlangs) || !($outputlangs instanceof Translate)) {
	$outputlangs = $langs;
}
$newlang = '';

// calcul_price_total(), used below to get the amounts of a line from the same place the invoice got
// them. The protocols reach this file from several entry points, not all of which load the library.
require_once DOL_DOCUMENT_ROOT.'/core/lib/price.lib.php';

// Load EInvoicing class
$einvoicing = new EInvoicing($db);


$outputlang = (string) $langs->defaultlang;

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
$this->determineDeliveryDatesAndCustomerOrderNumbers($customerOrderReferenceList, $deliveryDateList, $object);

// Chorus
$chorus = false;
if (getDolGlobalInt('EINVOICING_USE_CHORUS')) {
	$chorus = true;
}
$promise_code = $object->array_options['options_d4d_promise_code'] ?? '';
if ($promise_code == '') {
	// Dolibarr "Réf. client" holds the customer's purchase order number -> BT-13 (see issue #302).
	// The property is ref_client on recent versions and ref_customer on some older ones; accept both.
	$promise_code = $object->ref_client ?? ($object->ref_customer ?? '');
}
if ($promise_code == '' && !empty($customerOrderReferenceList)) {
	$promise_code = $customerOrderReferenceList[0];
}

// Bank account
$account = new Account($db);
if ($object->fk_account > 0) {
	$account->fetch((int) $object->fk_account);
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
$sellerTaxRegistrations = einvoicingSellerTaxRegistrations($mysoc);
$myidprof          = idprof($mysoc);
$mySchemeIdProf    = $this->getIEC6523Code($mysoc->country_code);
$myGlobalIdProf    = idprof($mysoc);
$mySchemeGlobalIdProf = $this->getIEC6523Code($mysoc->country_code, 1);
$myUri             = $einvoicing->getSellerCommunicationURI(0);
$mySchemeUri       = $this->getIEC6523Code($mysoc->country_code, 2);

// Buyer party resolution.
// The billing contact of the invoice (external BILLING contact) always describes the buyer contact
// group (BG-9): it is the point of contact the customer declared for its invoices, and nothing else
// fills that group. Whether that contact also *replaces* the buyer party itself is another matter,
// and stays opt-in behind EINVOICING_USE_BILLING_CONTACT_AS_BUYER (e.g. invoice addressed to the head
// office / "siège social"):
//   - Case B: the contact belongs to a different thirdparty (distinct legal entity) -> rebuild the
//     whole buyer (name, address, SIREN/SIRET, VAT, routing) from that thirdparty.
//   - Case A: same thirdparty -> keep its SIREN/VAT/routing, only override name/address.
$buyerParty        = $object->thirdparty;	// Societe used for legal id / VAT / routing
$buyerName         = $object->thirdparty->name;
$buyerAddress      = $object->thirdparty->address;
$buyerZip          = $object->thirdparty->zip;
$buyerTown         = $object->thirdparty->town;
$buyerCountryCode  = $object->thirdparty->country_code;
$buyerContactName  = null;
$buyerContactEmail = null;
$buyerContactPhone = null;

$billingContactIds = $object->getIdContact('external', 'BILLING');
if (!empty($billingContactIds) && $object->fetch_contact($billingContactIds[0]) > 0 && is_object($object->contact)) {
	$billingContact = $object->contact;

	// Buyer contact person fields (BG-9): name (BT-56), phone (BT-57) and email (BT-58)
	$tmpcontactname    = trim($billingContact->getFullName($outputlangs));
	$buyerContactName  = ($tmpcontactname !== '') ? $tmpcontactname : null;
	$buyerContactEmail = !empty($billingContact->email) ? $billingContact->email : null;
	$buyerContactPhone = !empty($billingContact->phone_pro) ? $billingContact->phone_pro : (!empty($billingContact->phone_mobile) ? $billingContact->phone_mobile : null);

	if (getDolGlobalInt('EINVOICING_USE_BILLING_CONTACT_AS_BUYER')) {
		$contactSocId = !empty($billingContact->fk_soc) ? $billingContact->fk_soc : $billingContact->socid;

		// Case B: billing contact attached to a different thirdparty (distinct legal entity)
		if (!empty($contactSocId) && $contactSocId != $object->socid) {
			require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
			$recipientSoc = new Societe($db);
			if ($recipientSoc->fetch($contactSocId) > 0 && idprof($recipientSoc) !== '') {
				// Compute intra VAT if missing, same as for the invoice thirdparty
				if ($recipientSoc->tva_assuj && empty($recipientSoc->tva_intra)) {
					$recipientSoc->tva_intra = $einvoicing->thirdpartyCalcVATIntra($recipientSoc);
				}
				$buyerParty       = $recipientSoc;
				$buyerName        = $recipientSoc->name;
				$buyerAddress     = $recipientSoc->address;
				$buyerZip         = $recipientSoc->zip;
				$buyerTown        = $recipientSoc->town;
				$buyerCountryCode = $recipientSoc->country_code;
				dol_syslog('einvoicing: buyer overridden by billing contact thirdparty id=' . $contactSocId . ' (distinct legal entity)', LOG_NOTICE);
			} else {
				dol_syslog('einvoicing: billing contact thirdparty id=' . $contactSocId . ' has no usable professional id, keeping invoice thirdparty as buyer', LOG_NOTICE);
				$contactSocId = 0;	// fall back to case A handling
			}
		}
	}
} elseif (getDolGlobalInt('EINVOICING_USE_BILLING_CONTACT_AS_BUYER')) {
	dol_syslog('einvoicing: EINVOICING_USE_BILLING_CONTACT_AS_BUYER is on but no usable BILLING contact found, using invoice thirdparty as buyer', LOG_NOTICE);
}
// Buyer identifiers (resolved buyer party: invoice thirdparty or billing-contact recipient)
if (!($buyerParty instanceof Societe)) {
	throw new \RuntimeException('einvoicing: invoice thirdparty is not a valid Societe (invoice id=' . $object->id . ')');
}
$idprof            = idprof($buyerParty) ?? '';
$schemeIdProf      = $this->getIEC6523Code($buyerParty->country_code);
$globalIdProf      = idprof($buyerParty) ?? '';
$schemeGlobalIdProf = $this->getIEC6523Code($buyerParty->country_code, 1);
$uri               = $einvoicing->getBuyerCommunicationURI($buyerParty, $object);
$reg = array();
if (preg_match('/(\d+):(.+)/', $uri, $reg)) {
	$uri		= (string) $reg[2];
	$schemeUri  = (string) $reg[1];
} else {
	$schemeUri  = $schemeUri  = $this->getIEC6523Code($buyerParty->country_code, 2);
}
// In case of sample tests, we may have this const defined to overwrite buyer Einvoice address ID.
// In common case, this should not be used
if (defined('EINVOICING_FORCE_BUYER_EID')) {
	$uri               = (string) constant('EINVOICING_FORCE_BUYER_EID');
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
	// @phan-suppress-next-line PhanUndeclaredProperty
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

// Buyer routing code: the Chorus Pro "code service exécutant", which BR-FR-CPRO-11 and BR-FR-CPRO-13
// of XP Z12-012 read as a private identifier of the buyer (BT-46 under scheme 0224). Without it, a
// B2G invoice to a buyer whose directory record demands a service code is rejected (issue #678).
//
// It is a SECOND ram:GlobalID on the buyer party, and only the EXTENDED profiles accept one: the
// Factur-X EN16931 Schematron caps that element at a single occurrence (FX-SCH-A-000164,
// "Element 'ram:GlobalID' may occur at maximum 1 times"), so emitting it below EXTENDED makes the
// document invalid - measured on the FNFE validator, which answers "valid, 0 failure" on the very
// same invoice built as EXTENDED-CTC-FR. The Annexe B examples say the same thing: the routing code
// appears in the EXTENDED and EXTENDED-CTC-FR files of an invoice and not in its EN16931 twin.
// Below EXTENDED the code is simply not sent, and the user is told why rather than handed a document
// an access point refuses.
$buildProfile = $this->getBuildXmlProfile();
$buyerRoutingCode = trim((string) ($object->array_options['options_d4d_service_code'] ?? ''));
if ($buyerRoutingCode !== '' && $buyerParty->country_code != 'FR') {
	// Scheme 0224 is the French routing code: it means nothing for a buyer of another country.
	$buyerRoutingCode = '';
}
if ($buyerRoutingCode !== '' && !preg_match('/^[A-Za-z0-9+\-_.]{1,100}$/', $buyerRoutingCode)) {
	// BR-FR-24 and BR-FR-26 are both "fatal" on that identifier: alphanumerics plus "+-_." only, and
	// 100 characters at most. The service code is free text typed by a user, so a value that would
	// make the whole document invalid is left out and reported, never emitted.
	$this->warnings[] = $outputlangs->trans('EInvoiceRoutingCodeRejected', $buyerRoutingCode);
	dol_syslog('einvoicing: Chorus service code "' . $buyerRoutingCode . '" breaks BR-FR-24 / BR-FR-26, BT-46 scheme 0224 not emitted', LOG_WARNING);
	$buyerRoutingCode = '';
}
if ($buyerRoutingCode !== '' && !$this->isExtendedProfile($buildProfile)) {
	$this->warnings[] = $outputlangs->trans('EInvoiceRoutingCodeNeedsExtendedProfile', $buyerRoutingCode, $buildProfile);
	dol_syslog('einvoicing: Chorus service code "' . $buyerRoutingCode . '" not emitted as BT-46 scheme 0224, profile ' . $buildProfile . ' allows a single buyer GlobalID', LOG_WARNING);
	$buyerRoutingCode = '';
}

// Buyer reference (BT-10): a reference owned by the buyer, used to route the invoice inside its own
// organisation (business unit, service reference, internal mailbox...). A core EN 16931 term with no
// relation to the public sector, which no field of the module let a private issuer fill until now.
// The Chorus Pro service code keeps feeding it when that dedicated property is empty: Annexe A of
// XP Z12-012 documents BT-10 as the "Service Executant" of the public sector, so the historical
// mapping is a documented usage of the term and is left untouched (issue #678).
$buyerReference = $einvoicing->getExtraFieldValue($object->id, $object->element, EInvoicing::EXTRAFIELD_BUYER_REFERENCE);
if (trim((string) $buyerReference) === '') {
	$buyerReference = $object->array_options['options_d4d_service_code'] ?? null;
}


// Project
if (! ($object->project instanceof Project)) {
	if (method_exists($object, 'fetchProject')) {
		$object->fetchProject();
	} else {
		$object->fetch_project();
	}
}

$invoiceRefDocs = [];

// Source invoice (credit note, replacement invoice)
// A replacement invoice (BT-3 = 384) references the invoice it corrects in the same BG-3 slot as a
// credit note does, and BR-FR-CO-04 makes that reference mandatory for it, with a "fatal" flag: one
// sent without it is refused by the access point, and a receiver has nothing to attach it to.
$refDocTypeCode = '';
if ($object->type == $object::TYPE_CREDIT_NOTE) {
	$refDocTypeCode = '381';			// 381 = Credit note
} elseif ($object->type == $object::TYPE_REPLACEMENT) {
	$refDocTypeCode = '384';			// 384 = Corrected invoice
}
if ($refDocTypeCode !== '' && !empty($object->fk_facture_source)) {
	$sourceFact = new Facture($this->db);
	if ($sourceFact->fetch($object->fk_facture_source) > 0) {
		$sourceFactDate = new DateTime(dol_print_date($sourceFact->date, 'dayrfc'));
		$invoiceRefDocs[] = [
			'ref' => $sourceFact->ref,
			'date' => $sourceFactDate,
			'type' => $refDocTypeCode
		];
		dol_syslog(get_class($this) . '::generateXML Set source invoice reference ' . $sourceFact->ref . ' for ' . $object->ref);
	} else {
		if ($object->id == 0) { // Specimen case.
			$specimenRefDoc = $object->fk_facture_source ?? 'FA0000-SPECIMEN';
			$sourceFactDate = new DateTime(dol_print_date(dol_now() - 100, 'dayrfc'));
			$invoiceRefDocs[] = [
				'ref' => $specimenRefDoc,
				'date' => $sourceFactDate,
				'type' => $refDocTypeCode
			];
			dol_syslog(get_class($this) . '::generateXML Set source invoice reference ' . $specimenRefDoc . ' for specimen ' . $object->ref);
		} else {
			dol_syslog(get_class($this) . '::generateXML Cannot fetch source invoice id=' . $object->fk_facture_source . ' for ' . $object->ref, LOG_WARNING);
		}
	}
}

// Situation invoice: BT-25/BT-26 — reference to the immediately preceding situation invoice.
// XP Z12-012 requires each situation invoice (counter > 1) to carry the reference of the previous
// one in the same cycle so the receiver can reconstruct the chain and verify the cumulated amounts.
// The first situation (counter = 1) has no predecessor and no BG-3 block.
if ($object->type == $object::TYPE_SITUATION && !empty($object->situation_counter) && $object->situation_counter > 1 && !empty($object->situation_cycle_ref)) {
	$object->fetchPreviousNextSituationInvoice();
	if (!empty($object->tab_previous_situation_invoice)) {
		// tab_previous_situation_invoice is ordered ASC by situation_counter: last element is the immediate predecessor
		$prevSituation = end($object->tab_previous_situation_invoice);
		reset($object->tab_previous_situation_invoice);
		if ($prevSituation && !empty($prevSituation->ref)) {
			$prevSituationDate = new DateTime(dol_print_date($prevSituation->date, 'dayrfc'));
			$invoiceRefDocs[] = [
				'ref'  => $prevSituation->ref,
				'date' => $prevSituationDate,
				'type' => '380'		// Situation invoices are transmitted as standard invoices (380)
			];
			dol_syslog(get_class($this) . '::generateXML Set preceding situation invoice reference ' . $prevSituation->ref . ' for situation invoice ' . $object->ref);
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
$hasServiceLine		= false;	// With the VAT mode below, drives the VAT point date code (BT-8)
$hasProductLine		= false;
// @phan-suppress-current-line PhanTypeArraySuspiciousNullable
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

	if ($line->product_type == 1) {		// Product::TYPE_SERVICE
		$hasServiceLine = true;
	} else {
		$hasProductLine = true;
	}

	// For credit notes EN16931 requires positive amounts
	if ($object->type == $object::TYPE_CREDIT_NOTE) {
		$line->subprice     = abs($line->subprice);
		if (isset($line->subprice_ttc)) {	// See the note on BT-148 below: usually not set at all
			$line->subprice_ttc = abs($line->subprice_ttc);
		}
		$line->total_ht     = abs($line->total_ht);
		$line->total_ttc    = abs($line->total_ttc);
		$line->total_tva    = abs($line->total_tva);
		$line->qty          = abs($line->qty);
	}

	// A line carrying recoverable non-collected VAT (TVA NPR) is issued exempt: getCategoryRate() reads
	// info_bits and answers category E with the reason of article 295 of the CGI, and the rate the line
	// states must go with it, since EN 16931 requires 0 there (BR-E-05) and no VAT amount (BR-E-09).
	// Dolibarr already makes the total including tax of such a line equal to its net amount, so this is
	// what makes the document claim the amount the invoice claims (issue #508).
	if (!empty($line->info_bits) && ((int) $line->info_bits & 1)) {
		$line->tva_tx = 0;
		$line->vat_src_code = '';
		$line->total_tva = 0;
		$line->total_ttc = $line->total_ht;
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
			$libelle     = $line->multilangs[$newlang]["label"];  // @phan-suppress-current-line PhanTypeArraySuspiciousNullable
			$description = $line->multilangs[$newlang]["description"];  // @phan-suppress-current-line PhanTypeArraySuspiciousNullable
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
	//$line_unit_price = price2num($line_unit_price, 4);			// Note, 4 digits seems common accuracy for unit price with einvoice, but default dolibarr setup is 'MU' so 5.

	// BT-148 gross unit price. subprice_ttc is not a declared property of FactureLigne in any
	// Dolibarr version: compta/facture/card.php sets it dynamically on the line being edited, and
	// nothing else does, so on a line read back from the database or built in memory it is simply
	// absent. Reading it unguarded raised "Undefined property", which PHPUnit turns into an error.
	// Absent means the gross unit price is unknown, which the test at the bottom already handles.
	$line_unit_price_ttc = $line->subprice_ttc ?? 0;
	//$line_unit_price_ttc = price2num($line_unit_price_ttc, 4);	// Note, 4 digits seems common accuracy for unit price with einvoice, but default dolibarr setup is 'MU' so 5.

	$line_unit_price_with_discount = $line_unit_price;
	if ($line->remise_percent) {
		$line_unit_price_with_discount = $line_unit_price * (1 - $line->remise_percent / 100);
	}
	// Progress of the line, which only a situation invoice carries: calcul_price_total() applies it
	// after the discount, exactly like the amounts of the invoice were computed.
	// The test is on the presence of the progress, not on its truthiness: a line left at 0 % has a
	// progress of zero, which used to be read as "no progress at all" and to fall back to 100 %, so
	// the document carried the whole amount of a line the invoice did not invoice yet (issue #666).
	$line_progress = 100;
	if ($object->type == $object::TYPE_SITUATION && isset($line->situation_percent) && (string) $line->situation_percent !== '') {
		$line_progress = (float) $line->situation_percent;
	}

	if ($line_progress != 100) {
		$line_unit_price_with_discount = $line_unit_price_with_discount * $line_progress / 100;
	}
	$line_unit_price_with_discount = price2num($line_unit_price_with_discount, getDolGlobalString('MAIN_APPLY_DISCOUNT_ON_UNIT_PRICE_THEN_ROUND_BEFORE_MULTIPLICATION_BY_QTY', 'MU'));

	// The amounts of the line are asked to the very function that computed the invoice
	// (calcul_price_total(), the one update_price() calls), instead of being computed a second time
	// here: the document has to state the amount the invoice states, and a second implementation
	// misses the accuracies and the options of the instance, silently, by a few cents (issue #505).
	// They are then rounded to the two decimals EN 16931 allows on an amount, which is a no-op on an
	// instance keeping the default currency accuracy.
	$localtaxes_array = array($line->localtax1_type, $line->localtax1_tx, $line->localtax2_type, $line->localtax2_tx);
	$tmpcal = calcul_price_total($line->qty, $line->subprice, $line->remise_percent, $line->tva_tx, $line->localtax1_tx, $line->localtax2_tx, 0, 'HT', $line->info_bits, $line->product_type, $mysoc, $localtaxes_array, $line_progress);

	$line_total_ht = price2num((float) $tmpcal[0], 2);
	$line_total_tva = price2num((float) $tmpcal[1], 2);
	$line_total_ttc = price2num((float) $line_total_ht + (float) $line_total_tva, 2);

	// Uncomment for test using the most accurate possible calculation (but not following the e-invoice rule to round to 2 digit at each step of calculation)
	if (getDolGlobalInt('EINVOICING_USE_DOLIBARR_ALREADY_CALCULATED_AMOUNTS')) {
		$line_unit_price = $line->subprice;								// Note, 4 digits seems common accuracy for unit price with einvoice but default dolibarr setup is 5.
		$line_unit_price_with_discount = price2num($line->subprice * (1 - $line->remise_percent / 100) * ($line_progress / 100), 'MU');
		$line_total_ht = $line->total_ht;
		$line_total_tva = $line->total_tva;
		$line_total_ttc = $line->total_ttc;
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
		'prodsellerid'              => $line->product_ref ? $line->product_ref : "",
		'prodbuyerid'               => null,
		'prodglobalidtype'          => null,
		'prodglobalid'              => null,
		'prodmultilangs'            => [],
		'prodClassificationCode'    => null,
		'prodClassificationScheme'  => null,
		'prodOriginCountry'         => null,

		// Mandatory by Factur-X, EN 16931
		// This is the unit price, excluding tax. The discount of the line is deliberately left out
		// of it: it is stated as a line allowance (BG-27) further down, whose basis is this price
		// times the quantity, which is the other way EN 16931 offers to write a discounted line and
		// the one this module has always used. Stating $line_unit_price_with_discount here instead
		// would need the TradeAllowanceCharge block of BT-148 / BT-147.
		// The progress of a situation line, on the contrary, belongs to this price: without it the
		// document states a price and a quantity whose product is not its own line amount (BT-131),
		// and a receiver rebuilding the amounts from them - this module does exactly that when it
		// imports - reads the whole line instead of the part that is invoiced (issue #672).
		'netpriceamount'            => (float) ($line_progress != 100 ? price2num($line_unit_price * $line_progress / 100, 'MU') : $line_unit_price),		// BT-146
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

// A situation invoice states, on each of its lines, the cumulative amount of the work done, and the
// core deducts the situations already invoiced from the header of the invoice
// (CommonObject::update_price(), block "Situations totals"): llx_facture.total_ttc holds the
// instalment, which is what the customer owes and what the payment screen expects. The document has
// to state the same amount, so what the previous situations already asked for is carried as a
// document level allowance: the lines keep saying what has been done, BT-106 keeps summing them, and
// BT-107 brings BT-109 (and the VAT breakdown with it) back onto the instalment (issue #674).
// The amount deducted for a line is the one its predecessor recorded, read from the invoice that
// carries it - not recomputed here, so the document deducts exactly what was invoiced before, cents
// included.
// With INVOICE_USE_SITUATION = 2 each invoice states its own share of the progress, the core deducts
// nothing and the lines already hold the instalment: there is nothing to deduct.
if (!empty($object->situation_counter) && $object->situation_counter > 1
	&& isset($object->type) && $object->type == $object::TYPE_SITUATION
	&& getDolGlobalInt('INVOICE_USE_SITUATION') == 1) {
	$previousSituations = array();		// Amounts already invoiced, per VAT rate of the current line
	foreach ($object->lines as $line) {
		if (empty($line->fk_prev_id)) {
			continue;					// A line that appears in this situation was never invoiced before
		}
		$sqlprev = "SELECT total_ht, total_tva FROM " . MAIN_DB_PREFIX . "facturedet WHERE rowid = " . ((int) $line->fk_prev_id);
		$resqlprev = $db->query($sqlprev);
		if (!$resqlprev) {
			dol_syslog("EInvoicing cannot read the previous situation line " . $line->fk_prev_id . ": " . $db->lasterror(), LOG_ERR);
			continue;
		}
		$objprev = $db->fetch_object($resqlprev);
		$db->free($resqlprev);
		if (empty($objprev) || (empty($objprev->total_ht) && empty($objprev->total_tva))) {
			continue;
		}

		// The allowance reduces the basis of a VAT rate of THIS invoice, so it is filed under the rate
		// of the line as it stands now, which is the one the breakdown knows.
		$keyforvatrate = $line->tva_tx . ($line->vat_src_code ? ' (' . $line->vat_src_code . ')' : '');
		if (!isset($taxBreakdown[$keyforvatrate])) {
			continue;
		}
		if (!isset($previousSituations[$keyforvatrate])) {
			$previousSituations[$keyforvatrate] = array('ht' => 0, 'tva' => 0);
		}
		$previousSituations[$keyforvatrate]['ht'] += (float) $objprev->total_ht;
		$previousSituations[$keyforvatrate]['tva'] += (float) $objprev->total_tva;
	}

	foreach ($previousSituations as $keyforvatrate => $alreadyinvoiced) {
		$deduction_ht = (float) price2num($alreadyinvoiced['ht'], 2);
		$deduction_tva = (float) price2num($alreadyinvoiced['tva'], 2);

		$globalDiscounts[] = array(
			'value' => $deduction_ht,
			'reason' => $langs->transnoentitiesnoconv('EInvoicingDeductionOfPreviousSituations'),
			'taxRate' => (float) $taxBreakdown[$keyforvatrate]['tva_tx'],
			'categoryVAT' => $taxBreakdown[$keyforvatrate]['categoryVAT'],
		);

		$taxBreakdown[$keyforvatrate]['totalHT'] -= $deduction_ht;
		$taxBreakdown[$keyforvatrate]['totalTVA'] -= $deduction_tva;

		$grand_total_ht -= $deduction_ht;
		$grand_total_tva -= $deduction_tva;
		$grand_total_ttc -= ($deduction_ht + $deduction_tva);
	}

	// The deduction is built line by line while the core builds it invoice by invoice: they agree on
	// every shape met so far, and a gap would mean the document is claiming something the invoice does
	// not. Say so rather than transmit it silently.
	if (isset($object->total_ht) && abs($grand_total_ht - (float) $object->total_ht) > 0.01) {
		dol_syslog("EInvoicing situation invoice " . $object->ref . ": the deduction of the previous situations gives "
			. $grand_total_ht . " where the invoice records " . $object->total_ht, LOG_WARNING);
	}
}

// Rounding convention of the totals.
// Dolibarr sums the amounts already rounded on each line ("total of round", the default), unless
// MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND is set, in which case it rounds the sum instead ("round of
// total"). update_price() then writes the difference back onto the last line of the VAT rate, so on
// such an instance the invoice recorded, printed, booked and paid carries the second convention.
// The loop above always applied the first one, so the document transmitted claimed a cent less (or
// more) than the invoice it stands for, and nothing reported it: the document stays internally
// consistent, and the tolerance BR-CO-17 allows absorbs the gap (issue #378).
// Only the VAT is concerned: on a document priced without tax update_price() never adjusts the net
// amount of a line, so the line net amounts (BT-131) and their sum (BT-106) are the same either way.
$roundTotalConstName = 'MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND';
if (in_array($object->element, array('facture_fourn', 'invoice_supplier'))) {
	$roundTotalConstName .= '_SUPPLIER';
}
if (getDolGlobalString($roundTotalConstName) == '1') {		// Same comparison as update_price(), which only treats '1' as "round of total"
	$grand_total_tva = 0;
	foreach ($taxBreakdown as $keyforvatrate => $vals) {
		$taxBreakdown[$keyforvatrate]['totalTVA'] = (float) price2num((float) $vals['totalHT'] * (float) $vals['tva_tx'] / 100, 2);
		$grand_total_tva += $taxBreakdown[$keyforvatrate]['totalTVA'];
	}
	$grand_total_ttc = (float) price2num($grand_total_ht + $grand_total_tva, 2);
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

// Amount already received for this invoice: direct payments + used credit notes.
// getSommePaiement() returns the sum of payments; on Dolibarr <= 22 it also stores that same
// value into $object->sumpayed, so adding both double-counted the payment (#372: a fully paid
// invoice reported TotalPrepaidAmount = 2x the amount and a negative DuePayableAmount).
$getAlreadyPaid = $object->getSommePaiement();

$prepaidAmount  = $getAlreadyPaid + $usedcreditnoteamount;

// Invoicing period of the document (BG-14): the earliest start and the latest end of the periods its
// lines carry, Dolibarr having no such field at invoice level. See einvoicingInvoicingPeriodFromLines()
// for why it is derived rather than left empty, and for the case it refuses to derive (issue #572).
$invoicingPeriod = einvoicingInvoicingPeriodFromLines($billing_period);
$invoicingPeriodStart = $invoicingPeriod['start'] !== null ? $this->_tsToDateTime($invoicingPeriod['start']) : null;
$invoicingPeriodEnd = $invoicingPeriod['end'] !== null ? $this->_tsToDateTime($invoicingPeriod['end']) : null;

// Delivery date
$deliveryDate = !empty($deliveryDateList)
	? new DateTime(dol_print_date($deliveryDateList[0], 'dayrfc'))
	: new DateTime(dol_print_date($object->date, 'dayrfc'));



// VAT exigibility scheme of the seller, which is the VAT mode of the Tax/VAT module setup and nothing
// else. It decides the VAT point date code the document carries (BT-8) and the legal mention that goes
// with the debits option.
$vatOnDebits      = einvoicingVatOnDebits();
$vatPointDateCode = einvoicingVatPointDateCode($hasProductLine, $hasServiceLine, $object->type == $object::TYPE_DEPOSIT);

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

	'invoicingPeriodStart' => $invoicingPeriodStart,										// BT-73
	'invoicingPeriodEnd'   => $invoicingPeriodEnd,										// BT-74

	// $prepaidAmount is what the document reports in BT-113, and BR-FR-CO-09 ties the "already paid"
	// frames to it, so the frame has to be decided from the same figure.
	// Values allowed by BR-FR-08: B1, S1, M1, B2, S2, M2, S3, B4, S4, M4, S5, S6, B7, S7, B8, S8, M8, B9, S9, M9
	'businessProcessId'    => $this->getBillingProcessID($object, $prepaidAmount),
	'isTestDocument'       => !empty($object->specimen),

	// Notes
	'documentNotePublic'   => $object->note_public ?: "",
	'documentNotePMT'      => getDolGlobalString('EINVOICING_PMT') ?: $outputlangs->transnoentities("NoInvoiceCollectionFees"),
	'documentNotePMD'      => getDolGlobalString('EINVOICING_PMD') ?: $outputlangs->transnoentities('NoLatePaymentFees'),
	'documentNoteAAB'      => getDolGlobalString('EINVOICING_AAB') ?: $outputlangs->transnoentities('NoEarlyPaymentDiscount'),
	// Legal mention that goes with the "TVA d'après les débits" option, mandatory on the invoices of a
	// seller who took it. The structured form of the same information is the VAT point date code below.
	'documentNoteTXD'      => $vatOnDebits ? $outputlangs->transnoentities('VATOnDebitsMention') : '',
	'documentNotes'        => [],

	// BT-8 (VAT point date code), which tells the buyer when the VAT falls due, hence from when it can be
	// deducted. See einvoicingVatPointDateCode() for the rule and what the French socle names.
	'vatDueDateTypeCode'   => $vatPointDateCode,

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
	// BT-31 or BT-32, whichever the VAT regime of the seller calls for - see
	// einvoicingSellerTaxRegistrations(). A seller that does not charge VAT has no BT-31 to declare and
	// must still identify itself, or every exempt line trips BR-E-02 (issue #560).
	'sellerTaxRegistations'     => $sellerTaxRegistrations,
	'sellervatnumber'           => $mysoc->tva_intra ?? 'FRSPECIMEN',

	'sellerLegalOrgId'          => $myidprof,
	'sellerLegalOrgScheme'      => $mySchemeIdProf,
	'sellerTradingName'         => $mysoc->name ?? 'SPECIMEN',

	// Buyer part
	'buyername'                 =>  $buyerName ?: 'CUSTOMER',
	'buyerids'                  => $idprof ?: 'IDPROF',

	'buyerlineone'              => $buyerAddress     ?: 'ADDRESS',
	'buyerlinetwo'              => "",
	'buyerlinethree'            => "",
	'buyerpostcode'             => $buyerZip         ?: 'ZIP',
	'buyercity'                 => $buyerTown        ?: 'TOWN',
	'buyercountry'              => $buyerCountryCode ?: 'COUNTRY',
	'buyersubdivision'          => null,

	'buyervatnumber'            => $buyerParty->tva_intra ?? '',
	'buyerGlobalIds'            => [['schemeID' => $schemeGlobalIdProf, 'value' => $globalIdProf]],
	'buyerRoutingCode'          => ($buyerRoutingCode !== '' ? $buyerRoutingCode : null),

	'buyerLegalOrgId'           => $idprof,
	'buyerLegalOrgScheme'       => $schemeIdProf,
	'buyerTradingName'          => $buyerName,

	'buyerReference'            => $buyerReference,

	// URIUniversalCommunication
	'buyerCommunicationUriScheme' => $schemeUri,
	'buyerCommunicationUri'    	=> $uri,

	'buyercontactpersonname'    => $buyerContactName,
	'buyercontactemailaddr'     => $buyerContactEmail,
	'buyercontactphoneno'       => $buyerContactPhone,

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
	$invoiceData['paymentMeansText'] = (string) $langs->transnoentitiesnoconv("PaymentType" . $object->mode_reglement_code);
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
			if ($shipContact->fetch((int) $tmpexpedition->fk_delivery_address) > 0) {
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
	throw new Exception('BADTHIRDPARTYPROFID: The main professional ID of the buyer ' . $buyerParty->name . ' is empty.');
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
if ($buyerParty->country_code == 'FR' && !empty($buyerParty->idprof1) && !empty($buyerParty->idprof2)) {
	if (strpos(preg_replace('/\s+/', '', $buyerParty->idprof2), preg_replace('/\s+/', '', $buyerParty->idprof1)) !== 0) {
		throw new Exception('BADVALUEFORSIRENORSIRET: The buyer has both a SIREN "' . $buyerParty->idprof1 . '" and SIRET "' . $buyerParty->idprof2 . '" but SIRET does not start with value of SIREN.');
	}
}
if (!empty($mysoc->tva_intra) && !empty($mysoc->country_code) && substr($mysoc->tva_intra, 0, 2) != $mysoc->country_code) {
	throw new Exception('BADVATNUMBER: The VAT number of your company must start with your country code.');
}
if (!empty($buyerParty->tva_intra) && !empty($buyerParty->country_code) && substr($buyerParty->tva_intra, 0, 2) != $buyerParty->country_code) {
	throw new Exception('BADVATNUMBER: The VAT number of the thirdparty ' . $buyerParty->name . ' must start with its 2 letter country code.');
}


// In output, we have
// $invoiceData and $linesData
