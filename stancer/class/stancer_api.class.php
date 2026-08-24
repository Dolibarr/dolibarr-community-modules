<?php
/* Copyright (C) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
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
 * \file    class/stancer_api.class.php
 * \ingroup stancer
 * \brief   Direct API client for Stancer payment gateway (without external library)
 */

require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';

/**
 * Class StancerApi
 *
 * Direct REST API client for Stancer payment gateway.
 * Uses Dolibarr's getURLContent() for HTTP requests.
 */
class StancerApi
{
	const API_VERSION_V1 = 'v1';
	const API_VERSION_V2 = 'v2';

	const API_BASE_URL = 'https://api.stancer.com';

	// Legacy constants for backwards compatibility
	const API_URL_LIVE = 'https://api.stancer.com/v2';
	const API_URL_TEST = 'https://api.stancer.com/v2';

	/**
	 * @var StancerApi|null Singleton instance
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return StancerApi
	 */
	public static function getInstance()
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * @var string API base URL
	 */
	private $apiUrl;

	/**
	 * @var string Public API key
	 */
	private $publicKey;

	/**
	 * @var string Secret API key
	 */
	private $secretKey;

	/**
	 * @var bool Live mode flag
	 */
	private $liveMode;

	/**
	 * @var string API version (v1 or v2)
	 */
	private $apiVersion;

	/**
	 * @var string Last error message
	 */
	public $error = '';

	/**
	 * @var array Last error details
	 */
	public $errors = array();

	/**
	 * @var int Last HTTP response code
	 */
	public $lastHttpCode = 0;

	/**
	 * @var array Last raw response
	 */
	public $lastResponse = array();

	/**
	 * Constructor
	 *
	 * @param string|null $publicKey  Public API key (null = use Dolibarr config)
	 * @param string|null $secretKey  Secret API key (null = use Dolibarr config)
	 * @param bool|null   $liveMode   Live mode (null = use Dolibarr config)
	 * @param string      $apiVersion  API version: 'v1' or 'v2' (default: v2)
	 */
	public function __construct($publicKey = null, $secretKey = null, $liveMode = null, $apiVersion = self::API_VERSION_V2)
	{
		global $conf;

		if ($liveMode === null) {
			$this->liveMode = (getDolGlobalString('STANCER_IS_PROD', '0') == '1');
		} else {
			$this->liveMode = $liveMode;
		}

		if ($publicKey === null) {
			$this->publicKey = $this->liveMode
				? getDolGlobalString('STANCER_PROD_PUBLIC_KEY', '')
				: getDolGlobalString('STANCER_TEST_PUBLIC_KEY', '');
		} else {
			$this->publicKey = $publicKey;
		}

		if ($secretKey === null) {
			$this->secretKey = $this->liveMode
				? getDolGlobalString('STANCER_PROD_PRIVATE_KEY', '')
				: getDolGlobalString('STANCER_TEST_PRIVATE_KEY', '');
		} else {
			$this->secretKey = $secretKey;
		}

		$this->apiVersion = $apiVersion;
		$this->apiUrl = self::API_BASE_URL . '/' . $this->apiVersion;
	}

	/**
	 * Make an API request
	 *
	 * @param string     $method   HTTP method (GET, POST, PATCH, DELETE)
	 * @param string     $endpoint API endpoint (e.g., '/checkout/', '/customers/cust_xxx')
	 * @param array|null $data     Data to send (for POST/PATCH)
	 * @return array|false         Response data or false on error
	 */
	public function request($method, $endpoint, $data = null)
	{
		$this->error = '';
		$this->errors = array();

		$url = $this->apiUrl . $endpoint;

		$headers = array(
			'Authorization: Basic ' . base64_encode($this->secretKey . ':'),
			'Content-Type: application/json',
			'Accept: application/json',
		);

		$param = '';
		if ($data !== null && in_array($method, array('POST', 'PATCH', 'PUT'))) {
			$param = json_encode($data);
		}

		$postorget = 'GET';
		if ($method === 'POST') {
			$postorget = 'POSTALREADYFORMATED';
		} elseif ($method === 'PATCH' || $method === 'PUT') {
			$postorget = 'PUTALREADYFORMATED';
		} elseif ($method === 'DELETE') {
			$postorget = 'DELETE';
		}

		dol_syslog("StancerApi::request $method $endpoint", LOG_DEBUG);

		$response = getURLContent($url, $postorget, $param, 1, $headers, array('https'), 0);

		$this->lastHttpCode = isset($response['http_code']) ? (int) $response['http_code'] : 0;
		$this->lastResponse = $response;

		if (!empty($response['curl_error_no'])) {
			$this->error = 'CURL Error: ' . $response['curl_error_msg'];
			$this->errors[] = $this->error;
			dol_syslog("StancerApi::request CURL error: " . $this->error, LOG_ERR);
			return false;
		}

		$content = isset($response['content']) ? $response['content'] : '';
		$decoded = json_decode($content, true);

		if ($this->lastHttpCode >= 400) {
			$this->error = 'HTTP Error ' . $this->lastHttpCode;
			// F2: never log the raw response body (it may contain IBAN/email/PII).
			// Log only the decoded error type/message.
			$errForLog = '';
			if (is_array($decoded) && isset($decoded['error'])) {
				$e = $decoded['error'];
				if (is_string($e)) {
					$errForLog = $e;
				} elseif (is_array($e)) {
					$errForLog = (isset($e['type']) ? $e['type'] : '') . ' ' . (isset($e['message']) ? $e['message'] : '');
				}
			}
			dol_syslog("StancerApi::request HTTP " . $this->lastHttpCode . " error: " . trim($errForLog), LOG_ERR);
			if (is_array($decoded) && isset($decoded['error'])) {
				$errorData = $decoded['error'];
				if (is_string($errorData)) {
					$this->error .= ': ' . $errorData;
				} elseif (is_array($errorData)) {
					if (isset($errorData['type'])) {
						$this->errors['type'] = $errorData['type'];
					}
					$this->error .= ': ' . json_encode($errorData);
				}
			} elseif (is_array($decoded) && isset($decoded['errors'])) {
				// Some Stancer endpoints return a plural "errors" array
				$this->error .= ': ' . json_encode($decoded['errors']);
			} elseif (!empty($content)) {
				// Fallback: append raw body (truncated) when no known error key
				$this->error .= ': ' . dol_trunc($content, 500, 'right', 'UTF-8', 1);
			}
			$this->errors[] = $this->error;
			dol_syslog("StancerApi::request HTTP error: " . $this->error, LOG_ERR);
			return false;
		}

		return $decoded;
	}

	// ========================================================================
	// PAYMENTS
	// ========================================================================

	/**
	 * Create a payment
	 *
	 * @param array $data Payment data (amount, currency, customer, card/sepa, etc.)
	 * @return array|false
	 */
	public function createPayment($data)
	{
		return $this->request('POST', '/checkout/', $data);
	}

	/**
	 * Get a payment by ID
	 *
	 * @param string $paymentId Payment ID (paym_xxx)
	 * @return array|false
	 */
	public function getPayment($paymentId)
	{
		return $this->request('GET', '/checkout/' . $paymentId);
	}

	/**
	 * Update a payment (e.g., capture)
	 *
	 * @param string $paymentId Payment ID
	 * @param array  $data      Data to update
	 * @return array|false
	 */
	public function updatePayment($paymentId, $data)
	{
		return $this->request('PATCH', '/checkout/' . $paymentId, $data);
	}

	/**
	 * List payments with filters
	 *
	 * @param array $filters Filters (created, start, limit, order_id, unique_id)
	 * @return array|false
	 */
	public function listPayments($filters = array())
	{
		$query = !empty($filters) ? '?' . http_build_query($filters) : '';
		return $this->request('GET', '/checkout/' . $query);
	}

	/**
	 * Capture a payment
	 *
	 * @param string $paymentId Payment ID
	 * @param int    $amount    Amount to capture in cents (optional, full capture if null)
	 * @return array|false
	 */
	public function capturePayment($paymentId, $amount = null)
	{
		$data = null;
		if ($amount !== null) {
			$data = array('amount' => $amount);
		}
		return $this->request('POST', '/checkout/' . $paymentId . '/capture', $data);
	}

	// ========================================================================
	// CUSTOMERS
	// ========================================================================

	/**
	 * Create a customer
	 *
	 * @param array $data Customer data (email, mobile, name)
	 * @return array|false
	 */
	public function createCustomer($data)
	{
		return $this->request('POST', '/customers/', $data);
	}

	/**
	 * Get a customer by ID
	 *
	 * @param string $customerId Customer ID (cust_xxx)
	 * @return array|false
	 */
	public function getCustomer($customerId)
	{
		return $this->request('GET', '/customers/' . $customerId);
	}

	/**
	 * Update a customer
	 *
	 * @param string $customerId Customer ID
	 * @param array  $data       Data to update
	 * @return array|false
	 */
	public function updateCustomer($customerId, $data)
	{
		return $this->request('PATCH', '/customers/' . $customerId, $data);
	}

	/**
	 * Delete a customer
	 *
	 * @param string $customerId Customer ID
	 * @return array|false
	 */
	public function deleteCustomer($customerId)
	{
		return $this->request('DELETE', '/customers/' . $customerId);
	}

	/**
	 * List customers with optional filters. Used by stancerAddCustomerIfNeeded()
	 * to detect a pre-existing Stancer customer (by email or mobile) BEFORE
	 * creating a new one - prevents the NITD-style duplicate where Stancer does
	 * not deduplicate server-side and the same email ends up with 2 cust_xxx.
	 *
	 * @param array $filters Optional Stancer filters (email, mobile, limit, start).
	 * @return array|false   Decoded API response, or false on error.
	 */
	public function listCustomers($filters = array())
	{
		$query = !empty($filters) ? '?' . http_build_query($filters) : '';
		return $this->request('GET', '/customers/' . $query);
	}

	// ========================================================================
	// CARDS
	// ========================================================================

	/**
	 * Create a card
	 *
	 * @param array $data Card data (number, exp_month, exp_year, cvc, name)
	 * @return array|false
	 */
	public function createCard($data)
	{
		return $this->request('POST', '/cards/', $data);
	}

	/**
	 * Get a card by ID
	 *
	 * @param string $cardId Card ID (card_xxx)
	 * @return array|false
	 */
	public function getCard($cardId)
	{
		return $this->request('GET', '/cards/' . $cardId);
	}

	/**
	 * Update a card
	 *
	 * @param string $cardId Card ID
	 * @param array  $data   Data to update
	 * @return array|false
	 */
	public function updateCard($cardId, $data)
	{
		return $this->request('PATCH', '/cards/' . $cardId, $data);
	}

	/**
	 * Delete a card
	 *
	 * @param string $cardId Card ID
	 * @return array|false
	 */
	public function deleteCard($cardId)
	{
		return $this->request('DELETE', '/cards/' . $cardId);
	}

	// ========================================================================
	// SEPA
	// ========================================================================

	/**
	 * Create a SEPA mandate
	 *
	 * @param array $data SEPA data (iban, bic, name, mandate)
	 * @return array|false
	 */
	public function createSepa($data)
	{
		return $this->request('POST', '/sepa/', $data);
	}

	/**
	 * Get a SEPA mandate by ID
	 *
	 * @param string $sepaId SEPA ID (sepa_xxx)
	 * @return array|false
	 */
	public function getSepa($sepaId)
	{
		return $this->request('GET', '/sepa/' . $sepaId);
	}

	/**
	 * Update a SEPA mandate
	 *
	 * @param string $sepaId SEPA ID
	 * @param array  $data   Data to update
	 * @return array|false
	 */
	public function updateSepa($sepaId, $data)
	{
		return $this->request('PATCH', '/sepa/' . $sepaId, $data);
	}

	/**
	 * Delete a SEPA mandate
	 *
	 * @param string $sepaId SEPA ID
	 * @return array|false
	 */
	public function deleteSepa($sepaId)
	{
		return $this->request('DELETE', '/sepa/' . $sepaId);
	}

	/**
	 * Check SEPA account (verification)
	 *
	 * @param array $data SEPA check data
	 * @return array|false
	 */
	public function checkSepa($data)
	{
		return $this->request('POST', '/sepa/check/', $data);
	}

	/**
	 * Get SEPA check status
	 *
	 * @param string $checkId Check ID
	 * @return array|false
	 */
	public function getSepaCheck($checkId)
	{
		return $this->request('GET', '/sepa/check/' . $checkId);
	}

	// ========================================================================
	// REFUNDS
	// ========================================================================

	/**
	 * Create a refund
	 *
	 * @param array $data Refund data (payment, amount)
	 * @return array|false
	 */
	public function createRefund($data)
	{
		return $this->request('POST', '/refunds/', $data);
	}

	/**
	 * Get a refund by ID
	 *
	 * @param string $refundId Refund ID (rfnd_xxx)
	 * @return array|false
	 */
	public function getRefund($refundId)
	{
		return $this->request('GET', '/refunds/' . $refundId);
	}

	/**
	 * List refunds with filters
	 *
	 * @param array $filters Filters (created, start, limit)
	 * @return array|false
	 */
	public function listRefunds($filters = array())
	{
		$query = !empty($filters) ? '?' . http_build_query($filters) : '';
		return $this->request('GET', '/refunds/' . $query);
	}

	// ========================================================================
	// PAYOUTS
	// ========================================================================

	/**
	 * Get a payout by ID
	 *
	 * @param string $payoutId Payout ID (pout_xxx)
	 * @return array|false
	 */
	public function getPayout($payoutId)
	{
		return $this->request('GET', '/payouts/' . $payoutId);
	}

	/**
	 * List payouts with filters
	 *
	 * @param array $filters Filters (created, start, limit)
	 * @return array|false
	 */
	public function listPayouts($filters = array())
	{
		$query = !empty($filters) ? '?' . http_build_query($filters) : '';
		return $this->request('GET', '/payouts/' . $query);
	}

	/**
	 * Get payout details (payments/refunds/disputes in this payout)
	 *
	 * @param string $payoutId Payout ID
	 * @param string $type     Type: 'payments', 'refunds', 'disputes'
	 * @param array  $filters  Filters
	 * @return array|false
	 */
	public function getPayoutDetails($payoutId, $type, $filters = array())
	{
		$query = !empty($filters) ? '?' . http_build_query($filters) : '';
		return $this->request('GET', '/payouts/' . $payoutId . '/' . $type . '/' . $query);
	}

	// ========================================================================
	// DISPUTES
	// ========================================================================

	/**
	 * Get a dispute by ID
	 *
	 * @param string $disputeId Dispute ID (dspt_xxx)
	 * @return array|false
	 */
	public function getDispute($disputeId)
	{
		return $this->request('GET', '/disputes/' . $disputeId);
	}

	/**
	 * List disputes with filters
	 *
	 * @param array $filters Filters (created, start, limit)
	 * @return array|false
	 */
	public function listDisputes($filters = array())
	{
		$query = !empty($filters) ? '?' . http_build_query($filters) : '';
		return $this->request('GET', '/disputes/' . $query);
	}

	// ========================================================================
	// HELPERS
	// ========================================================================

	/**
	 * Check if in live mode
	 *
	 * @return bool
	 */
	public function isLiveMode()
	{
		return $this->liveMode;
	}

	/**
	 * Get public key (for frontend/iframe usage)
	 *
	 * @return string
	 */
	public function getPublicKey()
	{
		return $this->publicKey;
	}

	/**
	 * Convert amount from standard format to cents.
	 * Uses round() before int cast to defeat IEEE 754 float drift
	 * (e.g. (int)(40.80 * 100) = 4079, but (int)round(40.80 * 100) = 4080).
	 *
	 * @param float|string|int $amount Amount in standard format (e.g., 10.50, "10.50", 10)
	 * @return int Amount in cents (e.g., 1050)
	 */
	public static function toCents($amount)
	{
		return (int) round((float) $amount * 100);
	}

	/**
	 * Convert amount from cents to standard format
	 *
	 * @param int $cents Amount in cents
	 * @return float Amount in standard format
	 */
	public static function fromCents($cents)
	{
		return (float) ($cents / 100);
	}

	/**
	 * Get current API version
	 *
	 * @return string
	 */
	public function getApiVersion()
	{
		return $this->apiVersion;
	}

	/**
	 * Set API version
	 *
	 * @param string $version API version (v1 or v2)
	 * @return void
	 */
	public function setApiVersion($version)
	{
		$this->apiVersion = $version;
		$this->apiUrl = self::API_BASE_URL . '/' . $this->apiVersion;
	}

	/**
	 * Check if using API v2
	 *
	 * @return bool
	 */
	public function isV2()
	{
		return $this->apiVersion === self::API_VERSION_V2;
	}

	// ========================================================================
	// ADDRESSES (v2 only)
	// ========================================================================

	/**
	 * Create an address (v2 only)
	 *
	 * @param array $data Address data (line1, line2, city, zip, country, state)
	 * @return array|false
	 */
	public function createAddress($data)
	{
		return $this->request('POST', '/addresses/', $data);
	}

	/**
	 * Get an address by ID (v2 only)
	 *
	 * @param string $addressId Address ID (addr_xxx)
	 * @return array|false
	 */
	public function getAddress($addressId)
	{
		return $this->request('GET', '/addresses/' . $addressId);
	}

	/**
	 * Update an address (v2 only)
	 *
	 * @param string $addressId Address ID
	 * @param array  $data      Data to update
	 * @return array|false
	 */
	public function updateAddress($addressId, $data)
	{
		return $this->request('PATCH', '/addresses/' . $addressId, $data);
	}

	/**
	 * Delete an address (v2 only)
	 *
	 * @param string $addressId Address ID
	 * @return array|false
	 */
	public function deleteAddress($addressId)
	{
		return $this->request('DELETE', '/addresses/' . $addressId);
	}

	// ========================================================================
	// MANDATES (v2 only)
	// ========================================================================

	/**
	 * Create a mandate (v2 only)
	 *
	 * @param array $data Mandate data
	 * @return array|false
	 */
	public function createMandate($data)
	{
		return $this->request('POST', '/mandates/', $data);
	}

	/**
	 * Get a mandate by ID (v2 only)
	 *
	 * @param string $mandateId Mandate ID (sdmm_xxx)
	 * @return array|false
	 */
	public function getMandate($mandateId)
	{
		return $this->request('GET', '/mandates/' . $mandateId);
	}

	/**
	 * Get mandate PDF (v2 only)
	 *
	 * @param string $mandateId Mandate ID
	 * @return string|false PDF content or false on error
	 */
	public function getMandatePdf($mandateId)
	{
		$url = $this->apiUrl . '/mandates/' . $mandateId . '.pdf';

		$headers = array(
			'Authorization: Basic ' . base64_encode($this->secretKey . ':'),
			'Accept: application/pdf',
		);

		$response = getURLContent($url, 'GET', '', 1, $headers, array('https'), 0);

		$this->lastHttpCode = isset($response['http_code']) ? (int) $response['http_code'] : 0;
		$this->lastResponse = $response;

		if (!empty($response['curl_error_no'])) {
			$this->error = 'CURL Error: ' . $response['curl_error_msg'];
			return false;
		}

		if ($this->lastHttpCode >= 400) {
			$this->error = 'HTTP Error ' . $this->lastHttpCode;
			return false;
		}

		return isset($response['content']) ? $response['content'] : false;
	}

	/**
	 * List mandates (v2 only)
	 *
	 * @param array $filters Filters (start, limit)
	 * @return array|false
	 */
	public function listMandates($filters = array())
	{
		$query = !empty($filters) ? '?' . http_build_query($filters) : '';
		return $this->request('GET', '/mandates/' . $query);
	}

	// ========================================================================
	// SEPA v2 extensions
	// ========================================================================

	/**
	 * Validate IBAN only (v2 only)
	 *
	 * @param array $data IBAN data (iban)
	 * @return array|false
	 */
	public function validateIbanOnly($data)
	{
		return $this->request('POST', '/sepa/ibanonly/', $data);
	}

	/**
	 * Generate SEPA check (v2 only)
	 *
	 * @param array $data SEPA check generation data
	 * @return array|false
	 */
	public function generateSepaCheck($data)
	{
		return $this->request('POST', '/sepa/check/generate', $data);
	}

	// ========================================================================
	// CUSTOMER extensions (v2 only)
	// ========================================================================

	/**
	 * Get customer payment intents (v2 only)
	 *
	 * @param string $customerId Customer ID
	 * @param array  $filters    Filters (start, limit)
	 * @return array|false
	 */
	public function getCustomerPaymentIntents($customerId, $filters = array())
	{
		$query = !empty($filters) ? '?' . http_build_query($filters) : '';
		return $this->request('GET', '/customers/' . $customerId . '/payment_intents' . $query);
	}

	/**
	 * Get customer subscriptions (v2 only)
	 *
	 * @param string $customerId Customer ID
	 * @param array  $filters    Filters (start, limit)
	 * @return array|false
	 */
	public function getCustomerSubscriptions($customerId, $filters = array())
	{
		$query = !empty($filters) ? '?' . http_build_query($filters) : '';
		return $this->request('GET', '/customers/' . $customerId . '/subscriptions' . $query);
	}
}
