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
 *      \file       test/phpunit/RedirectUrlAllowlistTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for einvoicingIsAllowedRedirectUrl(), the allowlist that guards every
 *                  redirect of public/proxy_oauthcallback.php. That page answers without
 *                  authentication and hands the caller back to the address it supplied itself in
 *                  redirect_uri; on the callback branch that address receives the access_token and the
 *                  refresh_token in its query string. Three ways of losing them are covered here:
 *                  an unset EINVOICING_SUPERPDPVIAPARTNER_ONLY_DOMAIN, which used to mean no check at
 *                  all; a host that merely ends with an allowed domain, which a suffix match accepts;
 *                  and a destination that is not an absolute http(s) URL.
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
dol_include_once('einvoicing/lib/einvoicing.lib.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class RedirectUrlAllowlistTest extends CommonClassTest
{
	/** @var string|null Value of the allowlist constant before this test overwrote it */
	private $savedallowlist = null;

	/** @var bool Whether the constant existed at all before this test */
	private $hadallowlist = false;

	/**
	 * CommonClassTest keeps a reference to $conf, not a copy, so the constant written here would
	 * survive the class and reach whatever runs next. Give it back its initial value.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

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
	 * With no partner domain declared, the option used to be read as "no check at all": any third
	 * party could drive the flow and collect the tokens on a host of its choice. Only this very
	 * instance is trusted by default now.
	 *
	 * @return void
	 */
	public function testWithoutAllowlistOnlyThisInstanceIsTrusted()
	{
		$this->setAllowlist(null);

		$this->assertFalse(einvoicingIsAllowedRedirectUrl('https://evil.tld/callback'), 'An unset allowlist must not mean that everything is allowed');

		$ownhost = parse_url(DOL_MAIN_URL_ROOT, PHP_URL_HOST);
		if (is_string($ownhost) && $ownhost !== '') {
			$this->assertTrue(einvoicingIsAllowedRedirectUrl('https://'.$ownhost.'/custom/einvoicing/admin/setup.php'), 'The instance itself stays a valid destination');
		}
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
