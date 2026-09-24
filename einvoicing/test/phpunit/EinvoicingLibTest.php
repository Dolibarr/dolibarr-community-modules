<?php
/* Copyright (C) 2026 Pierre Grasswill
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
 * or see https://www.gnu.org/
 */

/**
 *      \file       test/phpunit/EinvoicingLibTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the functions of einvoicing/lib/einvoicing.lib.php: the tax
 *                  identifier of the seller (BT-31 / BT-32), the VAT point date code (BT-8), the
 *                  invoicing period derived from the lines (BG-14) and the redirect allowlist of
 *                  the OAuth callback page.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// DOLIBARR_HTDOCS is honoured first so the module can be tested against any of the supported cores
// without moving the working copy.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('einvoicing/lib/einvoicing.lib.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class EinvoicingLibTest extends CommonClassTest
{
	/**
	 * A seller, described the way Societe::setMysoc() describes $mysoc.
	 *
	 * @param	int|string	$assuj		->tva_assuj, which the core fills from FACTURE_TVAOPTION
	 * @param	string		$vatNumber	Intra-community VAT number, '' for a company that has none
	 * @param	string		$siren		Professional id 1
	 * @return	Societe
	 */
	private function seller($assuj, $vatNumber, $siren = '000000001')
	{
		global $db;

		$seller = new Societe($db);
		$seller->name = 'Seller';
		$seller->tva_assuj = $assuj;
		$seller->tva_intra = $vatNumber;
		$seller->idprof1 = $siren;

		return $seller;
	}

	/**
	 * The module follows what the core made of the company setup: a seller subject to VAT declares its
	 * VAT number, and nothing about the existing documents changes.
	 *
	 * @return void
	 */
	public function testTheCompanySetupDecidesWhenSubjectToVat()
	{
		$seller = $this->seller(1, 'FR87892304189');

		$this->assertSame('standard', einvoicingSellerVatRegime($seller));
		$this->assertSame(
			array(array('type' => 'VA', 'value' => 'FR87892304189')),
			einvoicingSellerTaxRegistrations($seller)
		);
	}

	/**
	 * "VAT is not used" is FACTURE_TVAOPTION 0, which setMysoc() turns into tva_assuj 0. The seller has
	 * no VAT identifier to declare and its SIREN becomes the tax registration identifier, which is what
	 * BR-E-02 accepts in its place.
	 *
	 * @return void
	 */
	public function testTheSirenIsDeclaredWhenNotSubjectToVat()
	{
		$seller = $this->seller(0, '');

		$this->assertSame('franchise', einvoicingSellerVatRegime($seller));
		$this->assertSame(
			array(array('type' => 'FC', 'value' => '000000001')),
			einvoicingSellerTaxRegistrations($seller)
		);
	}

	/**
	 * setMysoc() does not hand over the same type on every supported core, and the module supports
	 * Dolibarr 17 and up. Up to 19 it assigns the constant as it is read from the database:
	 *
	 *   17 / 18 / 19    $this->tva_assuj = $conf->global->FACTURE_TVAOPTION;   ->  the string '0' / '1'
	 *   20 and above    $this->tva_assuj = getDolGlobalInt('FACTURE_TVAOPTION'); ->  the int 0 / 1
	 *
	 * Both forms are pinned here rather than left to whichever core happens to run the suite, since
	 * the answer must not depend on it.
	 *
	 * @return void
	 */
	public function testBothTypesSetMysocHandsOverAreUnderstood()
	{
		// Dolibarr 17 to 19.
		$this->assertSame('franchise', einvoicingSellerVatRegime($this->seller('0', '')));
		$this->assertSame('standard', einvoicingSellerVatRegime($this->seller('1', 'FR87892304189')));

		// Dolibarr 20 and above.
		$this->assertSame('franchise', einvoicingSellerVatRegime($this->seller(0, '')));
		$this->assertSame('standard', einvoicingSellerVatRegime($this->seller(1, 'FR87892304189')));

		// And the registration that follows from each, which is the point of the derivation.
		$this->assertSame(
			array(array('type' => 'FC', 'value' => '000000001')),
			einvoicingSellerTaxRegistrations($this->seller('0', ''))
		);
		$this->assertSame(
			array(array('type' => 'VA', 'value' => 'FR87892304189')),
			einvoicingSellerTaxRegistrations($this->seller('1', 'FR87892304189'))
		);
	}

	/**
	 * admin/company.php only ever writes 1 or 0, but the tva_assuj of a thirdparty also holds the
	 * literal forms and get_default_tva() reads them. This must answer the same as the core does.
	 *
	 * @return void
	 */
	public function testTheLiteralFormsAreReadTheWayTheCoreReadsThem()
	{
		$this->assertSame('franchise', einvoicingSellerVatRegime($this->seller('franchise', '')));
		$this->assertSame('standard', einvoicingSellerVatRegime($this->seller('reel', 'FR87892304189')));
	}

	/**
	 * The company setup is the only thing that decides, and no setting of this module can contradict
	 * it. A second place to state the regime would be a second place for it to be stated differently -
	 * a document declaring exempt lines while claiming a VAT registration, or the reverse - since the
	 * VAT category of each line is derived from the same ->tva_assuj by getCategoryRate(). Any constant
	 * left over from a version that offered the choice is ignored.
	 *
	 * @return void
	 */
	public function testNoModuleSettingCanContradictTheCompanySetup()
	{
		global $conf;

		$conf->global->EINVOICING_SELLER_VAT_REGIME = 'franchise';
		$this->assertSame('standard', einvoicingSellerVatRegime($this->seller(1, 'FR87892304189')));

		$conf->global->EINVOICING_SELLER_VAT_REGIME = 'standard';
		$this->assertSame('franchise', einvoicingSellerVatRegime($this->seller(0, '')));

		unset($conf->global->EINVOICING_SELLER_VAT_REGIME);
	}

	/**
	 * A seller subject to VAT that simply left the field empty must keep getting the explicit
	 * BADVATNUMBER message naming what to fill in. Falling back on its SIREN would build a document
	 * that passes BR-E-02 while stating the company is not registered for VAT, and would hide the
	 * missing field instead of reporting it.
	 *
	 * @return void
	 */
	public function testASellerSubjectToVatWithNoNumberDeclaresNothing()
	{
		$this->assertSame(array(), einvoicingSellerTaxRegistrations($this->seller(1, '')));
	}

	/**
	 * A company with no professional id recorded has nothing to declare either; the caller must not be
	 * handed an entry with an empty value, which would write an empty ram:ID.
	 *
	 * @return void
	 */
	public function testNoRegistrationIsBuiltWithoutAnIdentifierToPutInIt()
	{
		$this->assertSame(array(), einvoicingSellerTaxRegistrations($this->seller(0, '', '')));
	}

	/** @var array<string,string|null> Constants this test overwrites, as they were before */
	private $savedconstants = array();

	/** @var string|null Value of the allowlist constant before this test overwrote it */
	private $savedallowlist = null;

	/** @var bool Whether the constant existed at all before this test */
	private $hadallowlist = false;

	/**
	 * CommonClassTest keeps a reference to $conf, not a copy, so the constants written here would
	 * survive the class and reach whatever runs next. Give them back their initial value.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		foreach ($this->savedconstants as $key => $value) {
			if ($value === null) {
				unset($conf->global->$key);
			} else {
				$conf->global->$key = $value;
			}
		}
		$this->savedconstants = array();

		if ($this->hadallowlist) {
			$conf->global->EINVOICING_SUPERPDPVIAPARTNER_ONLY_DOMAIN = $this->savedallowlist;
		} else {
			unset($conf->global->EINVOICING_SUPERPDPVIAPARTNER_ONLY_DOMAIN);
		}
		$this->hadallowlist = false;
		$this->savedallowlist = null;

		parent::tearDown();
	}

	/**
	 * Set the VAT mode of the Tax/VAT module setup, the way its setup page does.
	 *
	 * @param	string	$product	'invoice' or 'payment'
	 * @param	string	$service	'invoice' or 'payment'
	 * @return	void
	 */
	private function setVatMode($product, $service)
	{
		global $conf;

		foreach (array('TAX_MODE_SELL_PRODUCT', 'TAX_MODE_SELL_SERVICE', 'EINVOICING_VAT_POINT_DATE_CODE') as $key) {
			if (!array_key_exists($key, $this->savedconstants)) {
				$this->savedconstants[$key] = isset($conf->global->$key) ? $conf->global->$key : null;
			}
		}

		$conf->global->TAX_MODE_SELL_PRODUCT = $product;
		$conf->global->TAX_MODE_SELL_SERVICE = $service;
		unset($conf->global->EINVOICING_VAT_POINT_DATE_CODE);
	}

	/**
	 * TAX_MODE 0, the French default. BT-8 is what XP Z12-012 annexe A makes of it, the declaration of
	 * the option for the payment of VAT on debits, so a seller who did not take that option declares
	 * nothing on a goods invoice: it has no option to signal and its VAT falls due on a delivery the
	 * document already dates. A document carrying a service is taxed on collection and says so.
	 *
	 * @return void
	 */
	public function testStandardSchemeDeclaresCollectionOnlyWhenItApplies()
	{
		$this->setVatMode('invoice', 'payment');

		$this->assertSame('', einvoicingVatPointDateCode(true, false));
		$this->assertSame('72', einvoicingVatPointDateCode(false, true));
		$this->assertSame('72', einvoicingVatPointDateCode(true, true));
		$this->assertFalse(einvoicingVatOnDebits());

		$this->assertFalse(einvoicingVatDueOnCollection(true, false));
		$this->assertTrue(einvoicingVatDueOnCollection(false, true));
		$this->assertTrue(einvoicingVatDueOnCollection(true, true));
	}

	/**
	 * TAX_MODE 1, "TVA d'apres les debits": the option is general and prevails over every invoice
	 * issued (G1.43), so every document declares 5 whatever it carries, and carries the legal mention
	 * that goes with the option. BR-FR-MAP-03 makes that mandatory on the service invoices. The down
	 * payment is the exception the socle itself carves out.
	 *
	 * @return void
	 */
	public function testDebitsSchemeDeclaresTheInvoiceDateEverywhere()
	{
		$this->setVatMode('invoice', 'invoice');

		$this->assertSame('5', einvoicingVatPointDateCode(true, false));
		$this->assertSame('5', einvoicingVatPointDateCode(false, true));
		$this->assertSame('72', einvoicingVatPointDateCode(true, false, true));
		$this->assertTrue(einvoicingVatOnDebits());

		$this->assertFalse(einvoicingVatDueOnCollection(true, false));
		$this->assertFalse(einvoicingVatDueOnCollection(false, true));
	}

	/**
	 * TAX_MODE 2: everything falls due on collection.
	 *
	 * @return void
	 */
	public function testEverythingOnPaymentDeclaresThePaymentDate()
	{
		$this->setVatMode('payment', 'payment');

		$this->assertSame('72', einvoicingVatPointDateCode(true, false));
		$this->assertSame('72', einvoicingVatPointDateCode(false, true));
		$this->assertFalse(einvoicingVatOnDebits());

		$this->assertTrue(einvoicingVatDueOnCollection(true, false));
		$this->assertTrue(einvoicingVatDueOnCollection(false, false));
	}

	/**
	 * @phpcs:ignore
	 * "La TVA est exigible a l'encaissement de l'acompte pour les livraisons de biens comme pour les prestations de service, meme avec option sur les debits" (XP Z12-014 annexe A).
	 * So a down payment declares the payment date whatever the VAT mode and whatever regime the seller declared, and
	 * Dolibarr building every down payment line as a goods line is why it has to be said explicitly.
	 *
	 * @return void
	 */
	public function testDownPaymentAlwaysDeclaresTheCollection()
	{
		$this->setVatMode('invoice', 'payment');
		$this->assertSame('72', einvoicingVatPointDateCode(true, false, true));

		$this->setVatMode('payment', 'payment');
		$this->assertSame('72', einvoicingVatPointDateCode(true, false, true));

		$this->setVatMode('invoice', 'invoice');
		$this->assertSame('72', einvoicingVatPointDateCode(true, false, true));
		$this->assertSame('72', einvoicingVatPointDateCode(false, true, true));
	}

	/**
	 * A document whose lines are all pseudo-lines (title, subtotal, page break) leaves both flags
	 * false, and there is then no operation taxed on collection to declare.
	 *
	 * @return void
	 */
	public function testDocumentWithoutAnyTaxedLineDeclaresNothing()
	{
		$this->setVatMode('invoice', 'payment');
		$this->assertSame('', einvoicingVatPointDateCode(false, false));
		$this->assertFalse(einvoicingVatDueOnCollection(false, false));
	}

	/**
	 * The exigibility scheme is Dolibarr's, and no setting of this module can contradict it: the same
	 * TAX_MODE the document reads is what the VAT report of the core declares on, so a module override
	 * would have the invoice tell the buyer one regime while its seller declares under another. A
	 * constant left over from the version that offered the choice is ignored, whatever value it holds.
	 *
	 * @return void
	 */
	public function testNoModuleSettingCanContradictTheVatMode()
	{
		global $conf;

		foreach (array('5', '29', '72', 'auto', '3') as $leftover) {
			$this->setVatMode('invoice', 'payment');
			$conf->global->EINVOICING_VAT_POINT_DATE_CODE = $leftover;

			$this->assertSame('', einvoicingVatPointDateCode(true, false), 'leftover '.$leftover);
			$this->assertSame('72', einvoicingVatPointDateCode(false, true), 'leftover '.$leftover);
			$this->assertFalse(einvoicingVatOnDebits(), 'leftover '.$leftover);
			$this->assertFalse(einvoicingVatDueOnCollection(true, false), 'leftover '.$leftover);
			$this->assertTrue(einvoicingVatDueOnCollection(false, true), 'leftover '.$leftover);
		}
	}

	/**
	 * The accumulator buildinvoicelines.inc.php fills, in the shape it fills it: one entry per line
	 * that has the date, keyed by the line number, and the key itself absent when no line has any.
	 *
	 * @param	array<int,?string>	$starts		date_start of each line, null when it has none
	 * @param	array<int,?string>	$ends		date_end of each line, null when it has none
	 * @return	array<string,array<int,int>>
	 */
	private function billingPeriod($starts, $ends)
	{
		$collected = array();
		foreach (array('start' => $starts, 'end' => $ends) as $side => $dates) {
			foreach ($dates as $numligne => $date) {
				if ($date !== null) {
					$collected[$side][$numligne] = (int) (new DateTime($date))->getTimestamp();
				}
			}
		}

		return $collected;
	}

	/**
	 * The period of the document is the one covering its lines: the earliest start and the latest end.
	 *
	 * @return void
	 */
	public function testThePeriodCoversEveryLine()
	{
		$period = einvoicingInvoicingPeriodFromLines($this->billingPeriod(
			array(1 => '2026-06-01', 2 => '2026-05-15', 3 => '2026-07-01'),
			array(1 => '2026-06-30', 2 => '2026-05-31', 3 => '2026-07-31')
		));

		$this->assertSame('2026-05-15', dol_print_date($period['start'], '%Y-%m-%d'));
		$this->assertSame('2026-07-31', dol_print_date($period['end'], '%Y-%m-%d'));
	}

	/**
	 * A single line with a period gives the document that period, unchanged.
	 *
	 * @return void
	 */
	public function testASingleLinePeriodBecomesTheDocumentPeriod()
	{
		$period = einvoicingInvoicingPeriodFromLines($this->billingPeriod(
			array(1 => '2026-06-01'),
			array(1 => '2026-06-30')
		));

		$this->assertSame('2026-06-01', dol_print_date($period['start'], '%Y-%m-%d'));
		$this->assertSame('2026-06-30', dol_print_date($period['end'], '%Y-%m-%d'));
	}

	/**
	 * One side alone is a period the norm accepts, so the other side stays empty rather than being
	 * invented from the invoice date or from the side that is known.
	 *
	 * @return void
	 */
	public function testOneSideAloneIsKeptAsItIs()
	{
		$startOnly = einvoicingInvoicingPeriodFromLines($this->billingPeriod(array(1 => '2026-06-01'), array()));
		$this->assertSame('2026-06-01', dol_print_date($startOnly['start'], '%Y-%m-%d'));
		$this->assertNull($startOnly['end']);

		$endOnly = einvoicingInvoicingPeriodFromLines($this->billingPeriod(array(), array(1 => '2026-06-30')));
		$this->assertNull($endOnly['start']);
		$this->assertSame('2026-06-30', dol_print_date($endOnly['end'], '%Y-%m-%d'));
	}

	/**
	 * An invoice whose lines carry no period at all declares none, which is what the documents
	 * generated before this existed did.
	 *
	 * @return void
	 */
	public function testNothingIsDerivedFromLinesWithoutDates()
	{
		$this->assertSame(array('start' => null, 'end' => null), einvoicingInvoicingPeriodFromLines(array()));
		$this->assertSame(array('start' => null, 'end' => null), einvoicingInvoicingPeriodFromLines(array('start' => array(), 'end' => array())));
	}

	/**
	 * A period that starts after it ends is refused rather than emitted: BR-29 wants the end date on
	 * or after the start date, and a document refused whole because of a header nobody filled in would
	 * be worse than carrying the periods on the lines only. Reachable with one line billed from March
	 * with no end, next to another billed until January with no start.
	 *
	 * @return void
	 */
	public function testAContradictoryPeriodIsNotDerived()
	{
		$period = einvoicingInvoicingPeriodFromLines($this->billingPeriod(
			array(1 => '2026-03-01'),
			array(2 => '2026-01-31')
		));

		$this->assertSame(array('start' => null, 'end' => null), $period);
	}

	/**
	 * The two dates being equal is a one-day period, not a contradiction.
	 *
	 * @return void
	 */
	public function testAOneDayPeriodIsKept()
	{
		$period = einvoicingInvoicingPeriodFromLines($this->billingPeriod(
			array(1 => '2026-06-15'),
			array(1 => '2026-06-15')
		));

		$this->assertSame('2026-06-15', dol_print_date($period['start'], '%Y-%m-%d'));
		$this->assertSame('2026-06-15', dol_print_date($period['end'], '%Y-%m-%d'));
	}

	/**
	 * Set the partner domain allowlist, the way an administrator does.
	 *
	 * @param	string|null	$domains	Comma separated domains, or null to leave the option unset
	 * @return	void
	 */
	private function setAllowlist($domains)
	{
		global $conf;

		if (!$this->hadallowlist && $this->savedallowlist === null) {
			$this->hadallowlist = isset($conf->global->EINVOICING_SUPERPDPVIAPARTNER_ONLY_DOMAIN);
			$this->savedallowlist = $this->hadallowlist ? $conf->global->EINVOICING_SUPERPDPVIAPARTNER_ONLY_DOMAIN : null;
		}

		if ($domains === null) {
			unset($conf->global->EINVOICING_SUPERPDPVIAPARTNER_ONLY_DOMAIN);
		} else {
			$conf->global->EINVOICING_SUPERPDPVIAPARTNER_ONLY_DOMAIN = $domains;
		}
	}

	/**
	 * A declared partner domain, and its subdomains, are the destinations the proxy may use.
	 *
	 * @return void
	 */
	public function testAllowedDomainAndItsSubdomainsPass()
	{
		$this->setAllowlist('partner.tld');

		$this->assertTrue(einvoicingIsAllowedRedirectUrl('https://partner.tld/callback'), 'The declared domain itself must be allowed');
		$this->assertTrue(einvoicingIsAllowedRedirectUrl('https://sub.partner.tld/callback'), 'A subdomain of the declared domain must be allowed');
		$this->assertTrue(einvoicingIsAllowedRedirectUrl('http://partner.tld/callback'), 'Plain http on the declared domain must be allowed');
		$this->assertTrue(einvoicingIsAllowedRedirectUrl('HTTPS://PARTNER.TLD/callback'), 'Host comparison must not depend on the case');
	}

	/**
	 * A host that merely ends with an allowed domain is a different host. Matching on the suffix
	 * alone let an attacker register "notpartner.tld" and receive the tokens meant for "partner.tld".
	 *
	 * @return void
	 */
	public function testHostThatOnlyEndsWithAnAllowedDomainIsRefused()
	{
		$this->setAllowlist('partner.tld');

		$this->assertFalse(einvoicingIsAllowedRedirectUrl('https://notpartner.tld/callback'), 'A suffix match must not be enough, the boundary is a dot');
		$this->assertFalse(einvoicingIsAllowedRedirectUrl('https://partner.tld.evil.tld/callback'), 'The allowed domain must not be a mere prefix of the host either');
		$this->assertFalse(einvoicingIsAllowedRedirectUrl('https://evil.tld/callback'), 'An unrelated domain must be refused');
	}

	/**
	 * "https://partner.tld@evil.tld/" reads as the allowed domain but the browser goes to evil.tld:
	 * everything before the "@" is a user name. The decision is taken on the host parse_url() gives,
	 * never on the look of the string.
	 *
	 * @return void
	 */
	public function testAnAllowedDomainPlacedInTheUserInfoIsRefused()
	{
		$this->setAllowlist('partner.tld');

		$this->assertFalse(einvoicingIsAllowedRedirectUrl('https://partner.tld@evil.tld/callback'), 'The allowed domain used as a user name must not make the URL allowed');
		$this->assertFalse(einvoicingIsAllowedRedirectUrl('https://evil.tld#@partner.tld'), 'An allowed domain placed in the fragment must not make the URL allowed');
		$this->assertTrue(einvoicingIsAllowedRedirectUrl('http://partner.tld:8080/callback'), 'A port on an allowed domain does not change the host');
	}

	/**
	 * Several partner domains may be declared, separated by commas and possibly by spaces.
	 *
	 * @return void
	 */
	public function testEveryDeclaredDomainOfTheListIsHonoured()
	{
		$this->setAllowlist('first.tld, second.tld');

		$this->assertTrue(einvoicingIsAllowedRedirectUrl('https://first.tld/callback'), 'The first domain of the list must be allowed');
		$this->assertTrue(einvoicingIsAllowedRedirectUrl('https://second.tld/callback'), 'A domain declared after a space must be allowed too');
		$this->assertFalse(einvoicingIsAllowedRedirectUrl('https://third.tld/callback'), 'A domain outside the list must be refused');
	}

	/**
	 * TRANSITION. Nothing ever set EINVOICING_SUPERPDPVIAPARTNER_ONLY_DOMAIN, so an empty list is
	 * still read as "every domain", and every proxy deployment keeps working on the day of the
	 * update. This is the very case the security report is about: it is held open on purpose, the
	 * setup pages warn about it, and this test is what will have to be flipped when the option
	 * becomes mandatory.
	 *
	 * @return void
	 */
	public function testAnEmptyAllowlistStillAcceptsEveryDomainForNow()
	{
		$this->setAllowlist(null);

		$this->assertTrue(einvoicingIsAllowedRedirectUrl('https://evil.tld/callback'), 'The transition step accepts any domain while the option is unset');

		global $dolibarr_main_url_root;
		$ownhost = parse_url((string) $dolibarr_main_url_root, PHP_URL_HOST);
		if (is_string($ownhost) && $ownhost !== '') {
			$this->assertTrue(einvoicingIsAllowedRedirectUrl('https://'.$ownhost.'/custom/einvoicing/admin/setup.php'), 'The instance itself stays a valid destination');
		}
	}

	/**
	 * The transition above only lifts the domain comparison. The shape of the destination is judged
	 * in every case: a scheme relative "//evil.tld" or a "javascript:" payload must never reach a
	 * Location header, allowlist or no allowlist.
	 *
	 * @return void
	 */
	public function testTheShapeOfTheUrlIsCheckedEvenWithAnEmptyAllowlist()
	{
		$this->setAllowlist(null);

		$this->assertFalse(einvoicingIsAllowedRedirectUrl(''), 'An empty destination must be refused whatever the allowlist');
		$this->assertFalse(einvoicingIsAllowedRedirectUrl('//evil.tld/callback'), 'A scheme relative URL must be refused whatever the allowlist');
		$this->assertFalse(einvoicingIsAllowedRedirectUrl('javascript:alert(1)'), 'A javascript payload must be refused whatever the allowlist');
		$this->assertFalse(einvoicingIsAllowedRedirectUrl('https:///callback'), 'An URL without a host must be refused whatever the allowlist');
	}

	/**
	 * Whatever the allowlist says, only an absolute http(s) URL may reach a Location header. A
	 * scheme relative "//evil.tld" is resolved by the browser to the attacker host, and a
	 * "javascript:" payload turns the redirect into a script execution.
	 *
	 * @return void
	 */
	public function testOnlyAbsoluteHttpUrlsAreAccepted()
	{
		$this->setAllowlist('partner.tld');

		$this->assertFalse(einvoicingIsAllowedRedirectUrl(''), 'An empty destination must be refused');
		$this->assertFalse(einvoicingIsAllowedRedirectUrl('//partner.tld/callback'), 'A scheme relative URL must be refused');
		$this->assertFalse(einvoicingIsAllowedRedirectUrl('/custom/einvoicing/admin/setup.php'), 'A relative path must be refused');
		$this->assertFalse(einvoicingIsAllowedRedirectUrl('javascript:alert(1)'), 'A javascript payload must be refused');
		$this->assertFalse(einvoicingIsAllowedRedirectUrl('ftp://partner.tld/callback'), 'A scheme other than http(s) must be refused');
		$this->assertFalse(einvoicingIsAllowedRedirectUrl('https:///callback'), 'An URL without a host must be refused');
	}
}
