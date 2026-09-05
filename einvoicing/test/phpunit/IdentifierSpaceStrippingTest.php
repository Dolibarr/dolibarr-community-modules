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
 *      \file       test/phpunit/IdentifierSpaceStrippingTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test proving the module strips the spaces of an identifier the same way everywhere.
 *                  The module used to carry two strippers: removeAllSpaces(), which knows every kind of
 *                  space, and EInvoicing::removeSpaces(), a '/\s+/' without the /u modifier that sees
 *                  none of the non-breaking, thin or zero-width ones. idprof() built the routing id with
 *                  the first one while getSellerCommunicationURI() checked it back with the second, so a
 *                  non-breaking space pasted into idprof1 - what a SIRET copied from a web page or a PDF
 *                  carries - made the two sides disagree and, in EINVOICING_LIVE, emptied the seller URI.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// See VatPointDateCodeTest for why DOLIBARR_HTDOCS is honoured before the relative path.
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
dol_include_once('einvoicing/class/einvoicing.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class IdentifierSpaceStrippingTest extends CommonClassTest
{
	/** @var string	The SIREN of the fixtures, without any separator */
	const SIREN = '844431239';
	/** @var string	The SIRET of the same company, without any separator. It starts with the SIREN, as the check expects. */
	const SIRET = '84443123900020';

	/** @var array<string,string> Module options this test pins, saved as they were found */
	private $savedoptions = array();
	/** @var Societe|null Global $mysoc as it was found */
	private $savedmysoc = null;

	/**
	 * Pin the options getSellerCommunicationURI() reads, and give the test its own $mysoc so the
	 * company of the instance the suite runs against does not decide the result.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf, $mysoc;

		parent::setUp();

		foreach (array('EINVOICING_PDP', 'EINVOICING_SUPERPDP_ROUTING_ID', 'EINVOICING_LIVE') as $option) {
			$this->savedoptions[$option] = getDolGlobalString($option);
		}
		$this->savedmysoc = $mysoc;

		$conf->global->EINVOICING_PDP = 'SuperPDP';
	}

	/**
	 * Put back the options and the company, whatever the test did with them.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf, $mysoc;

		$mysoc = $this->savedmysoc;
		foreach ($this->savedoptions as $option => $value) {
			if ($value === '') {
				unset($conf->global->$option);
			} else {
				$conf->global->$option = $value;
			}
		}

		parent::tearDown();
	}

	/**
	 * The company of the test, written the way a user writes it: the professional id carries the
	 * separator given, which is what a value copied from a web page or from a PDF looks like.
	 *
	 * @param	string	$separator	The character written between the groups of the SIREN
	 * @return	Societe
	 */
	private function seller($separator)
	{
		global $db;

		$seller = new Societe($db);
		$seller->name = 'Seller';
		$seller->country_code = 'FR';
		$seller->idprof1 = '844' . $separator . '431' . $separator . '239';

		return $seller;
	}

	/**
	 * Every writing of a space a French identifier may carry. The key names the character, the value
	 * is the SIRET written with it, and each of them must be stripped down to the bare number.
	 *
	 * @return array<string,string>
	 */
	private function siretsWrittenWithEveryKindOfSpace()
	{
		return array(
			'ordinary space'      => '844 431 239 00020',
			'tabulation'          => "844\t431\t239\t00020",
			'line feed'           => "844\n431 239 00020",
			'non-breaking space'  => "844\xC2\xA0431\xC2\xA0239\xC2\xA000020",	// U+00A0, what a copy-paste brings
			'narrow nbsp'         => "844\xE2\x80\xAF431\xE2\x80\xAF239\xE2\x80\xAF00020",	// U+202F
			'thin space'          => "844\xE2\x80\x89431\xE2\x80\x89239\xE2\x80\x8900020",	// U+2009
			'zero width space'    => "844\xE2\x80\x8B431\xE2\x80\x8B239\xE2\x80\x8B00020",	// U+200B
			'byte order mark'     => "\xEF\xBB\xBF84443123900020",					// U+FEFF, in head of a pasted value
			'html entity'         => '844&nbsp;431&nbsp;239&nbsp;00020',
		);
	}

	/**
	 * The single stripper must see every kind of space, not only the ASCII ones.
	 *
	 * @return void
	 */
	public function testEveryKindOfSpaceIsStrippedFromAnIdentifier()
	{
		foreach ($this->siretsWrittenWithEveryKindOfSpace() as $kind => $siret) {
			$this->assertSame(self::SIRET, removeAllSpaces($siret), 'a SIRET written with a ' . $kind . ' must be cleaned');
		}
	}

	/**
	 * The public method of the class is deprecated but still reachable by another module or by a hook.
	 * It must now answer exactly what the function answers: one implementation, one result.
	 *
	 * @return void
	 */
	public function testTheDeprecatedMethodStripsExactlyLikeTheFunction()
	{
		global $db;

		$einvoicing = new EInvoicing($db);

		foreach ($this->siretsWrittenWithEveryKindOfSpace() as $kind => $siret) {
			$this->assertSame(removeAllSpaces($siret), $einvoicing->removeSpaces($siret), 'both strippers must agree on a SIRET written with a ' . $kind);
			$this->assertSame(self::SIRET, $einvoicing->removeSpaces($siret), 'the deprecated method must clean a SIRET written with a ' . $kind);
		}
	}

	/**
	 * The single stripper replaces one that never failed on anything, so it must not be more fragile
	 * than it was: a null identifier and an empty one give '', a badly encoded one keeps its bytes
	 * cleaned of the ASCII spaces (what the deprecated method used to return), and the answer is
	 * always a string, never null.
	 *
	 * @return void
	 */
	public function testTheEdgeCasesAreNoMoreFragileThanTheAsciiStrip()
	{
		global $db;

		$einvoicing = new EInvoicing($db);

		$this->assertSame('', removeAllSpaces(null), 'a null identifier is an empty one');
		$this->assertSame('', removeAllSpaces(''), 'an empty identifier stays empty');
		$this->assertSame('', $einvoicing->removeSpaces(null));
		$this->assertSame('', $einvoicing->removeSpaces(''));

		// A Latin-1 payload: "\xE9" alone is not valid UTF-8, so neither the entity decoding nor the
		// Unicode pattern can work on it. The identifier must come back stripped of its ASCII spaces
		// rather than emptied or turned into null.
		$latin1 = "SIRET\xE9 844 431 239";
		$this->assertSame("SIRET\xE9844431239", removeAllSpaces($latin1), 'a badly encoded identifier keeps its bytes');
		$this->assertSame(removeAllSpaces($latin1), $einvoicing->removeSpaces($latin1));

		foreach (array(null, '', $latin1, '844 431 239') as $value) {
			$this->assertIsString(removeAllSpaces($value), 'the stripper always answers a string');
		}
	}

	/**
	 * The stripper works on UTF-8 internally, so when the caller names another encoding it converts,
	 * strips, and must give the value back in the encoding it was handed. Two things are asserted:
	 * the round trip really enables the strip - a Latin-1 identifier separated by the single byte
	 * 0xA0 comes back as the bare number - and a byte that only exists in the source encoding is
	 * restored as it was, not left in its UTF-8 form.
	 *
	 * @return void
	 */
	public function testTheOriginalEncodingIsRestoredWhenOneIsNamed()
	{
		if (!function_exists('mb_convert_encoding')) {
			$this->markTestSkipped('mbstring is not installed, the stripper does not convert anything');
		}

		// "844 431 239" written in ISO-8859-1, where the non-breaking space is the single byte 0xA0.
		// Read as UTF-8 those bytes mean nothing, so only the conversion lets the pattern see them.
		$this->assertSame(self::SIREN, removeAllSpaces("844\xA0431\xA0239", 'ISO-8859-1'), 'a Latin-1 non-breaking space must be stripped too');

		// "SIRETé 844 431 239" in ISO-8859-1: the accented byte is not part of a space and must come
		// back as the single byte 0xE9 it was, not as the two bytes of its UTF-8 form.
		$restored = removeAllSpaces("SIRET\xE9 844 431 239", 'ISO-8859-1');
		$this->assertSame("SIRET\xE9844431239", $restored, 'the value must be given back in the encoding it was handed');
		$this->assertNotSame("SIRET\xC3\xA9844431239", $restored, 'the value must not stay converted to UTF-8');

		// Naming UTF-8 explicitly must change nothing compared to letting the stripper detect it.
		$utf8 = "844\xC2\xA0431\xC2\xA0239";
		$this->assertSame(self::SIREN, removeAllSpaces($utf8, 'UTF-8'));
		$this->assertSame(removeAllSpaces($utf8), removeAllSpaces($utf8, 'UTF-8'));
	}

	/**
	 * The bug: in live mode, a non-breaking space in the professional id emptied the seller
	 * communication URI. idprof() had stripped it to build the routing id while the check kept it,
	 * so the two sides of the same comparison never matched and the URI was dropped.
	 *
	 * @return void
	 */
	public function testTheSellerUriSurvivesANonBreakingSpaceInTheProfessionalIdInLiveMode()
	{
		global $conf, $db, $mysoc;

		$mysoc = $this->seller("\xC2\xA0");
		$conf->global->EINVOICING_SUPERPDP_ROUTING_ID = self::SIRET;
		$conf->global->EINVOICING_LIVE = 1;

		$einvoicing = new EInvoicing($db);

		$this->assertSame(self::SIRET, $einvoicing->getSellerCommunicationURI(), 'a non-breaking space in idprof1 must not empty the seller URI');
	}

	/**
	 * The same scenario with the ordinary space, which used to work and must keep working: this is the
	 * control telling a regression of the fix apart from a fixture that never exercised the bug.
	 *
	 * @return void
	 */
	public function testTheSellerUriSurvivesAnOrdinarySpaceInTheProfessionalIdInLiveMode()
	{
		global $conf, $db, $mysoc;

		$mysoc = $this->seller(' ');
		$conf->global->EINVOICING_SUPERPDP_ROUTING_ID = self::SIRET;
		$conf->global->EINVOICING_LIVE = 1;

		$einvoicing = new EInvoicing($db);

		$this->assertSame(self::SIRET, $einvoicing->getSellerCommunicationURI());
	}

	/**
	 * The check itself must keep biting: a routing id that really does not belong to the company is
	 * still refused in live mode. Stripping the spaces the same way on both sides removes the false
	 * mismatch, not the real one.
	 *
	 * @return void
	 */
	public function testTheSellerUriIsStillEmptiedInLiveModeWhenTheRoutingIdDoesNotBelongToTheCompany()
	{
		global $conf, $db, $mysoc;

		$mysoc = $this->seller("\xC2\xA0");
		$conf->global->EINVOICING_SUPERPDP_ROUTING_ID = '99999999900011';	// another company
		$conf->global->EINVOICING_LIVE = 1;

		$einvoicing = new EInvoicing($db);

		$this->assertSame('', $einvoicing->getSellerCommunicationURI(), 'a routing id of another company must still be refused in live mode');
	}

	/**
	 * When no routing id is recorded for the access point, the URI falls back on the professional id
	 * through idprof(). Both the value produced and the value checked go through the same stripper, so
	 * the fallback answers the bare SIREN whatever kind of space the user typed in it.
	 *
	 * @return void
	 */
	public function testTheFallbackOnTheProfessionalIdIsCleanedTheSameWay()
	{
		global $conf, $db, $mysoc;

		unset($conf->global->EINVOICING_SUPERPDP_ROUTING_ID);
		$conf->global->EINVOICING_LIVE = 1;

		foreach (array('ordinary space' => ' ', 'non-breaking space' => "\xC2\xA0", 'thin space' => "\xE2\x80\x89") as $kind => $separator) {
			$mysoc = $this->seller($separator);
			$einvoicing = new EInvoicing($db);

			$this->assertSame(self::SIREN, $einvoicing->getSellerCommunicationURI(), 'the fallback on idprof1 written with a ' . $kind . ' must give the bare SIREN');
		}
	}

	/**
	 * idprof() and the check of getSellerCommunicationURI() are the two ends of the same chain: what
	 * one produces, the other must recognise. Assert it directly, on every kind of space, so a future
	 * stripper added on one end only is caught here.
	 *
	 * @return void
	 */
	public function testIdprofAndTheSellerUriAgreeOnEveryKindOfSpace()
	{
		global $conf, $db, $mysoc;

		$conf->global->EINVOICING_LIVE = 1;

		foreach (array(' ', "\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89", "\xE2\x80\x8B") as $separator) {
			$mysoc = $this->seller($separator);
			$this->assertSame(self::SIREN, idprof($mysoc), 'idprof() must give the bare SIREN');

			$conf->global->EINVOICING_SUPERPDP_ROUTING_ID = idprof($mysoc);
			$einvoicing = new EInvoicing($db);

			$this->assertSame(self::SIREN, $einvoicing->getSellerCommunicationURI(), 'the URI check must recognise the routing id idprof() built');
		}
	}
}
