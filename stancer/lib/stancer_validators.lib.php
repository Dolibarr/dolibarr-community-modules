<?php
/* Copyright (C) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    stancer/lib/stancer_validators.lib.php
 * \ingroup stancer
 * \brief   Pure validation/sanitization helpers for Stancer API payloads.
 *          NO Dolibarr dependency: loadable in unit tests via require_once.
 *          Constraints follow docs/202603-openapi.json (CardIn, SepaIn, CustomerIn).
 *          Real-world rules not in the spec (e.g. "number digits only") are
 *          documented inline.
 */

/**
 * Sanitize a credit card payload for Stancer API (CardIn schema).
 *
 * @param  array  $data  Raw input. Expected keys: cbnumber, cbexp_month,
 *                       cbexp_year, cbccv, cbname (optional).
 * @return array|int     Sanitized payload ready for createCard, or
 *                       array('error' => string) on validation failure.
 */
function stancerSanitizeCardData(array $data)
{
	$rawNumber = isset($data['cbnumber']) ? (string) $data['cbnumber'] : '';
	$rawCvc    = isset($data['cbccv']) ? (string) $data['cbccv'] : '';
	$rawName   = isset($data['cbname']) ? (string) $data['cbname'] : '';
	$expMonth  = isset($data['cbexp_month']) ? (int) $data['cbexp_month'] : 0;
	$expYear   = isset($data['cbexp_year']) ? (int) $data['cbexp_year'] : 0;

	// Real-world rule observed via API 422 response: "Must contain only digits".
	// Not declared in OpenAPI but enforced by the server. Strip everything else
	// (spaces from input mask, dashes, dots, NBSP...).
	$number = preg_replace('/\D+/', '', $rawNumber);
	$cvc    = preg_replace('/\D+/', '', $rawCvc);

	if ($number === null || $number === '') {
		return array('error' => 'cbnumber is empty after sanitization');
	}

	// OpenAPI CardIn: number minLength=13, maxLength=19
	$len = strlen($number);
	if ($len < 13 || $len > 19) {
		return array('error' => 'cbnumber length=' . $len . ' out of [13,19]');
	}

	// OpenAPI CardIn: cvc minLength=3, maxLength=4
	$cvcLen = strlen($cvc);
	if ($cvcLen < 3 || $cvcLen > 4) {
		return array('error' => 'cbccv length=' . $cvcLen . ' out of [3,4]');
	}

	// OpenAPI CardIn: exp_year [2019,2099], exp_month [1,12]
	if ($expMonth < 1 || $expMonth > 12) {
		return array('error' => 'cbexp_month=' . $expMonth . ' out of [1,12]');
	}
	if ($expYear < 2019 || $expYear > 2099) {
		return array('error' => 'cbexp_year=' . $expYear . ' out of [2019,2099]');
	}

	// OpenAPI CardIn: name maxLength=64. Trim whitespace, truncate hard.
	$name = trim($rawName);
	if (strlen($name) > 64) {
		$name = substr($name, 0, 64);
	}

	return array(
		'number'    => $number,
		'exp_month' => $expMonth,
		'exp_year'  => $expYear,
		'cvc'       => $cvc,
		'name'      => $name,
	);
}

/**
 * Sanitize a SEPA payload for Stancer API (SepaIn schema).
 *
 * @param  array  $data  Raw input. Expected keys: iban, bic (optional), name.
 * @return array|int     Sanitized payload, or array('error' => string).
 */
function stancerSanitizeSepaData(array $data)
{
	$rawIban = isset($data['iban']) ? (string) $data['iban'] : '';
	$rawBic  = isset($data['bic']) ? (string) $data['bic'] : '';
	$rawName = isset($data['name']) ? (string) $data['name'] : '';

	// Strip whitespace, uppercase. IBAN is 15-34 alphanumeric chars per ISO 13616.
	$iban = strtoupper(preg_replace('/\s+/', '', $rawIban));
	$bic  = strtoupper(preg_replace('/\s+/', '', $rawBic));

	if ($iban === null || $iban === '') {
		return array('error' => 'iban is empty after sanitization');
	}
	if (!preg_match('/^[A-Z0-9]+$/', $iban)) {
		return array('error' => 'iban contains non-alphanumeric chars');
	}
	$ibanLen = strlen($iban);
	if ($ibanLen < 15 || $ibanLen > 34) {
		return array('error' => 'iban length=' . $ibanLen . ' out of [15,34]');
	}

	if ($bic !== '') {
		// BIC: 8 or 11 chars, A-Z and 0-9
		$bicLen = strlen($bic);
		if (($bicLen != 8 && $bicLen != 11) || !preg_match('/^[A-Z0-9]+$/', $bic)) {
			return array('error' => 'bic format invalid (got "' . $bic . '")');
		}
	}

	$name = trim($rawName);
	if (strlen($name) > 64) {
		$name = substr($name, 0, 64);
	}

	$out = array('iban' => $iban, 'name' => $name);
	if ($bic !== '') {
		$out['bic'] = $bic;
	}
	return $out;
}

/**
 * Sanitize a customer payload for Stancer API (CustomerIn schema).
 *
 * @param  array  $data  Raw input. Expected keys: email, mobile (optional), name.
 * @return array|int     Sanitized payload, or array('error' => string).
 */
function stancerSanitizeCustomerData(array $data)
{
	$rawEmail  = isset($data['email']) ? (string) $data['email'] : '';
	$rawMobile = isset($data['mobile']) ? (string) $data['mobile'] : '';
	$rawName   = isset($data['name']) ? (string) $data['name'] : '';

	$email = trim($rawEmail);
	$name  = trim($rawName);

	// Mobile: keep digits and a single leading '+' (E.164-like). Real-world Stancer
	// rejects mobiles with spaces, dots or parentheses.
	$mobile = preg_replace('/[^0-9+]/', '', $rawMobile);
	if ($mobile !== '' && strlen($mobile) > 1) {
		// Strip any '+' that is not at position 0
		$mobile = ($mobile[0] === '+' ? '+' : '') . str_replace('+', '', $mobile);
	}

	if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
		return array('error' => 'email format invalid: ' . $email);
	}
	if (strlen($email) > 64) {
		return array('error' => 'email length=' . strlen($email) . ' > 64');
	}

	if ($name === '') {
		return array('error' => 'name is empty');
	}
	if (strlen($name) > 64) {
		$name = substr($name, 0, 64);
	}

	$out = array('name' => $name);
	if ($email !== '') {
		$out['email'] = $email;
	}
	if ($mobile !== '') {
		$out['mobile'] = $mobile;
	}
	return $out;
}

/**
 * Validate a Stancer API key against the expected prefix for its slot.
 *
 * Stancer documents two key flavors: production (sprod_/slive_) and test
 * (stest_/ptest_). Public and private keys share the environment suffix but
 * differ on the leading char (p* vs s*). A key pasted in the wrong slot is
 * silently accepted by Dolibarr's config save and only fails at the first API
 * call with a generic 401, which is hard to diagnose. This validator catches
 * the swap at save time.
 *
 * @param string $constName One of STANCER_TEST_PUBLIC_KEY, STANCER_TEST_PRIVATE_KEY,
 *                          STANCER_PROD_PUBLIC_KEY, STANCER_PROD_PRIVATE_KEY.
 * @param string $value     The value submitted by the admin.
 * @return bool             true if the value matches the expected prefix for
 *                          $constName, OR if $value is empty (empty means
 *                          "remove the key" which is a legitimate operation).
 *                          false otherwise.
 */
function stancerValidateApiKey($constName, $value)
{
	if ($value === '' || $value === null) {
		return true;
	}
	$rules = stancerApiKeyRules();
	if (!isset($rules[$constName])) {
		return false;
	}
	return (bool) preg_match($rules[$constName]['regex'], $value);
}

/**
 * Return the rules table for Stancer API key slots.
 *
 * Single source of truth shared by:
 *  - admin/setup.php (HTML5 pattern attribute + server-side rejection)
 *  - stancerValidateApiKey() above
 *  - StancerValidatorsTest (unit coverage)
 *
 * Each rule exposes:
 *   - regex:   PCRE used by the server-side validator (anchored).
 *   - pattern: same content as regex without the / delimiters and anchors,
 *              suitable as the HTML5 `pattern` attribute (anchors implicit).
 *   - prefix:  human-readable expected prefix(es) for error messages.
 *
 * @return array<string,array{regex:string,pattern:string,prefix:string}>
 */
function stancerApiKeyRules()
{
	return array(
		'STANCER_TEST_PUBLIC_KEY' => array(
			'regex'   => '/^ptest_[A-Za-z0-9]+$/',
			'pattern' => 'ptest_[A-Za-z0-9]+',
			'prefix'  => 'ptest_',
		),
		'STANCER_TEST_PRIVATE_KEY' => array(
			'regex'   => '/^stest_[A-Za-z0-9]+$/',
			'pattern' => 'stest_[A-Za-z0-9]+',
			'prefix'  => 'stest_',
		),
		'STANCER_PROD_PUBLIC_KEY' => array(
			'regex'   => '/^p(?:prod|live)_[A-Za-z0-9]+$/',
			'pattern' => 'p(prod|live)_[A-Za-z0-9]+',
			'prefix'  => 'pprod_/plive_',
		),
		'STANCER_PROD_PRIVATE_KEY' => array(
			'regex'   => '/^s(?:prod|live)_[A-Za-z0-9]+$/',
			'pattern' => 's(prod|live)_[A-Za-z0-9]+',
			'prefix'  => 'sprod_/slive_',
		),
	);
}
