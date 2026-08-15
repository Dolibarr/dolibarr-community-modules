<?php
/* Copyright (C) 2025-2026 InPoint Automation Sp z o.o.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    ksef/class/ksef_client.class.php
 * \ingroup ksef
 * \brief   Client
 */

dol_include_once('/ksef/lib/ksef.lib.php');

use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\PublicKeyLoader;

class KsefClient
{
    private $db;
    private $api_url;
    private $environment;
    private $nip;
    private $ksef_token;
    private $session_token;
    private $refresh_token;
    private $session_expiry;
    private $timeout;
    private $public_key_pem;
    public $errors = [];
    public $error;
    public $last_error_code;
    public $last_error_details = [];
    public $last_http_code;
    public $retry_after_seconds;

    private $sessionExpiryBuffer = 300;
    private $auth_method;
    private $auth_certificate_pem;
    private $auth_certificate_password;

    const API_TEST = 'https://api-test.ksef.mf.gov.pl/api/v2';
    const API_DEMO = 'https://api-demo.ksef.mf.gov.pl/api/v2';
    const API_PROD = 'https://api.ksef.mf.gov.pl/api/v2';

    // KSeF Error Codes (from GitHub issue #325)
    const ERROR_CODES = array(
        9101 => 'Nieprawidłowy dokument',
        9102 => 'Brak podpisu',
        9103 => 'Przekroczona liczba dozwolonych podpisów',
        9104 => 'Niewystarczająca liczba wymaganych podpisów',
        9105 => 'Nieprawidłowa treść podpisu',
        9106 => 'Nieprawidłowa liczba referencji podpisu',
        9107 => 'Niezgodność lub nieprawidłowa referencja podpisanych danych',
        9108 => 'Nieprawidłowa liczba danych referencji podpisu',
        9109 => 'Brak danych referencji podpisu',
        9110 => 'Brak referencji do danych podpisu',
        20001 => 'Niedozwolone sekcje dokumentu',
        20002 => 'Niedozwolone sekcje dokumentu [DTD]',
        20003 => 'Niedozwolone sekcje dokumentu [CDATA]',
        20004 => 'Nieprawidłowo zakodowana treść (base64)',
        20005 => 'Nieprawidłowo zaszyfrowana treść',
        21001 => 'Nieczytelna treść',
        21111 => 'Nieprawidłowe wyzwanie autoryzacyjne',
        21112 => 'Nieprawidłowy czas tokena',
        21113 => 'Żądanie autoryzacji wygasło',
        21114 => 'Nieprawidłowy kontekst Profilu Zaufanego (PZ)',
        21115 => 'Nieprawidłowy certyfikat',
        21116 => 'Nieprawidłowy token',
        21121 => 'Limit żądań osiągnięty',
        21132 => 'Brak treści żądania wysyłki potwierdzenia',
        21133 => 'Brak treści faktury żądania wysyłki faktury',
        21134 => 'Brak treści potwierdzenia żądania wysyłki potwierdzenia',
        21135 => 'Brak definicji pakietu',
        21136 => 'Brak definicji szyfrowania',
        21137 => 'Brak sygnatury pliku faktury',
        21138 => 'Brak sygnatury pliku potwierdzenia',
        21139 => 'Brak sygnatury pliku pakietu',
        21140 => 'Brak sygnatury pakietu',
        21141 => 'Brak listy części pakietu',
        21142 => 'Nieprawidłowy typ algorytmu szyfrowania',
        21143 => 'Nieprawidłowy typ klucza szyfrującego',
        21144 => 'Nieprawidłowy typ wektora inicjalizacyjnego',
        21145 => 'Nieprawidłowy typ dokumentu',
        21146 => 'Nieprawidłowy typ żądania wysyłki faktury',
        21147 => 'Sprzeczny typ żądania wysyłki faktury',
        21148 => 'Brak numeru referencyjnego',
        21149 => 'Brak sesji',
        21150 => 'Sesja wsadowa wygasła',
        21151 => 'Sesja wsadowa nieaktywna',
        21153 => 'Sesja interaktywna nieaktywna',
        21154 => 'Sesja interaktywna zakończona',
        21156 => 'Nieprawidłowa definicja części pakietu',
        21157 => 'Nieprawidłowy rozmiar części pakietu',
        21158 => 'Nieprawidłowy skrót części pakietu',
        21159 => 'Nieprawidłowy podpis',
        21160 => 'Nieprawidłowy kontekst',
        21161 => 'Incorrect range',
        21162 => 'Nieprawidłowe żądanie',
        21164 => 'Faktura o podanym identyfikatorze nie istnieje',
        21167 => 'Nie znaleziono zapytania o paczkę z podanym numerem referencyjnym',
        21168 => 'Poświadczenia o podanym identyfikatorze nie istnieją',
        21169 => 'Brak autoryzacji lub faktura o podanym identyfikatorze nie istnieje',
        21170 => 'Sesja interaktywna wygasła',
        21171 => 'Brak tokena sesyjnego',
        21172 => 'Pusta treść żądania',
        21173 => 'Brak sesji o wskazanym numerze referencyjnym',
        21174 => 'Brak nazwy części',
        21175 => 'Wynik zapytania o podanym identyfikatorze nie istnieje',
        21176 => 'Duplikat faktury w kontekście sesji',
        21177 => 'Przekroczona maksymalna liczba wyników. Doprecyzuj kryteria',
        21204 => 'Pakiet nie może być zduplikowany',
        21205 => 'Pakiet nie może być pusty',
        21206 => 'Część listy pakietu nie może być pusta',
        21207 => 'Lista elementów pakietu nie może być pusta',
        21208 => 'Czas oczekiwania na requesty upload lub finish został przekroczony',
        21211 => 'Nieprawidłowa deklaracja formularza dokumentu',
        21212 => 'Nieprawidłowy wystawca dokumentu',
        21213 => 'Nieprawidłowy klucz szyfrujący',
        21214 => 'Nieprawidłowe kodowanie dokumentu',
        21215 => 'Nieprawidłowy kontekst szyfrowania',
        21216 => 'Nieprawidłowa kompresja',
        21217 => 'Nieprawidłowe kodowanie znaków',
        21218 => 'Duplikat faktury w kontekście pakietu',
        21301 => 'Brak autoryzacji',
        21302 => 'Token nieaktywny',
        21303 => 'Token unieważniony',
        21304 => 'Brak uwierzytelnienia',
        21305 => 'Brak uwierzytelnienia certyfikatu',
        21401 => 'Dokument nie jest zgodny ze schemą (xsd)',
        21402 => 'Nieprawidłowy rozmiar pliku',
        21403 => 'Nieprawidłowy skrót pliku',
        21404 => 'Nieprawidłowy format dokumentu (json)',
        21405 => 'Dokument nie jest zgodny ze schemą (json)',
        21406 => 'Konflikt podpisu i typu uwierzytelnienia',
        21407 => 'Nieprawidłowy podmiot podpisu',
        21408 => 'Nieprawidłowy numer referencyjny',
        21409 => 'Token o podanym identyfikatorze nie istnieje',
        23001 => 'Brak treści',
        // Session status codes
        100 => 'Sesja interaktywna otwarta',
        200 => 'Przetwarzanie zakończone sukcesem',
        440 => 'Duplikat faktury - numer faktury już istnieje w KSeF',
        445 => 'Błąd weryfikacji, brak poprawnych faktur'
    );

    public function __construct($db, $environment = null)
    {
        require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';

        $this->db = $db;

        if ($environment === null) {
            $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');
        }
        $this->environment = strtoupper($environment);

        switch ($this->environment) {
            case 'PRODUCTION':
                $this->api_url = self::API_PROD;
                break;
            case 'DEMO':
                $this->api_url = self::API_DEMO;
                break;
            case 'TEST':
            default:
                $this->api_url = self::API_TEST;
                break;
        }

        $envKey = $this->environment;
        $this->nip = getDolGlobalString('KSEF_COMPANY_NIP', '');
        $this->auth_method = getDolGlobalString('KSEF_AUTH_METHOD_' . $envKey, 'token');
        $encrypted_token = getDolGlobalString('KSEF_AUTH_TOKEN_' . $envKey, '');
        $this->ksef_token = !empty($encrypted_token) ? dol_decode($encrypted_token) : '';
        $this->auth_certificate_pem = '';
        $this->auth_private_key_pem = '';
        $this->timeout = getDolGlobalInt('KSEF_TIMEOUT', 30);
    }

    /**
     * @brief Loads and decrypts authentication certificate and private key
     * @return bool True if loaded successfully
     * @called_by authenticateWithCertificate()
     */
    private function loadAuthCertificateCredentials()
    {
        $credentials = ksefLoadAuthCertificate($this->environment);
        if (!$credentials) {
            $this->error = 'Authentication certificate not properly configured';
            return false;
        }

        $this->auth_certificate_pem = $credentials['certificate_pem'];
        $this->auth_private_key_pem = $credentials['private_key_pem'];

        dol_syslog("KsefClient: Authentication credentials loaded successfully", LOG_DEBUG);
        return true;
    }

    /**
     * @brief Gets error description from code
     * @param $code Error code
     * @return string Error description
     * @called_by Various error handlers
     */
    public function getErrorDescription($code)
    {
        return isset(self::ERROR_CODES[$code]) ? self::ERROR_CODES[$code] : 'Unknown error';
    }


    /**
     * @brief Parses API error response
     * @param $response JSON response
     * @return array Error details
     * @called_by makeRequest()
     */
    private function parseErrorResponse($response)
    {
        $errorDetails = array(
            'code' => null,
            'description' => null,
            'details' => array(),
            'service_code' => null,
            'timestamp' => null
        );

        $data = json_decode($response, true);

        if (isset($data['exception']['exceptionDetailList'])) {
            foreach ($data['exception']['exceptionDetailList'] as $exception) {
                $code = isset($exception['exceptionCode']) ? (int)$exception['exceptionCode'] : null;
                $desc = isset($exception['exceptionDescription']) ? $exception['exceptionDescription'] : '';
                $details = isset($exception['details']) ? $exception['details'] : array();

                $errorDetails['code'] = $code;
                $errorDetails['description'] = $desc;
                $errorDetails['details'] = array_merge($errorDetails['details'], $details);
            }
        }

        if (isset($data['exception']['serviceCode'])) {
            $errorDetails['service_code'] = $data['exception']['serviceCode'];
        }

        if (isset($data['exception']['timestamp'])) {
            $errorDetails['timestamp'] = $data['exception']['timestamp'];
        }

        if (isset($data['status'])) {
            if (!$errorDetails['code'] && isset($data['status']['code'])) {
                $errorDetails['code'] = (int)$data['status']['code'];
            }
            if (!$errorDetails['description'] && isset($data['status']['description'])) {
                $errorDetails['description'] = $data['status']['description'];
            }
        }

        return $errorDetails;
    }


    /**
     * @brief Formats error message for display
     * @param $errorDetails Error details array
     * @return string Formatted message
     * @called_by makeRequest()
     */
    public function formatErrorMessage($errorDetails)
    {
        $message = '';

        if ($errorDetails['code']) {
            $knownDesc = $this->getErrorDescription($errorDetails['code']);
            $message .= "KSeF Error {$errorDetails['code']}: {$knownDesc}";

            if ($errorDetails['description'] && $errorDetails['description'] != $knownDesc) {
                $message .= " ({$errorDetails['description']})";
            }
        } elseif ($errorDetails['description']) {
            $message .= "KSeF Error: {$errorDetails['description']}";
        }

        if (!empty($errorDetails['details'])) {
            $message .= "\nDetails: " . implode('; ', $errorDetails['details']);
        }

        if ($errorDetails['service_code']) {
            $message .= "\nService Code: {$errorDetails['service_code']}";
        }

        return $message;
    }

    /**
     * @brief Checks invoice status in session
     * @param $sessionRef Session reference
     * @param $invoiceRef Invoice reference
     * @return array|false Status result
     * @called_by submitInvoice(), KsefSubmission::processPendingSubmissions()
     */
    public function checkInvoiceInSession($sessionRef, $invoiceRef)
    {
        if (!$this->authenticate()) {
            $this->error = "Authentication failed";
            return false;
        }

        try {
            $response = $this->makeRequest('GET',
                "/sessions/{$sessionRef}/invoices/{$invoiceRef}",
                null,
                array(
                    "Authorization: Bearer {$this->session_token}",
                    'Accept: application/json'
                )
            );

            if (!$response) throw new Exception('Failed to check invoice status in session');

            $statusData = json_decode($response, true);

            $result = array(
                'processing_code' => isset($statusData['processingCode']) ? $statusData['processingCode'] : null,
                'processing_description' => isset($statusData['processingDescription']) ? $statusData['processingDescription'] : null,
                'status' => 'UNKNOWN',
                'ksef_number' => isset($statusData['ksefReferenceNumber']) ? $statusData['ksefReferenceNumber'] : null,
                'timestamp' => isset($statusData['timestamp']) ? $statusData['timestamp'] : null,
                'status_details' => isset($statusData['statusDetails']) ? $statusData['statusDetails'] : array(),
                'raw_response' => $statusData
            );

            if ($result['processing_code'] === 200) {
                $result['status'] = 'ACCEPTED';
            } elseif ($result['processing_code'] >= 400) {
                $result['status'] = 'REJECTED';
            } else {
                $result['status'] = 'PENDING';
            }

            if ($result['status'] == 'REJECTED') {
                $this->last_error_code = $result['processing_code'];
                $this->last_error_details = array(
                    'code' => $result['processing_code'],
                    'description' => $result['processing_description'],
                    'details' => $result['status_details']
                );
                dol_syslog("KsefClient: Invoice rejected - " . $this->formatErrorMessage($this->last_error_details), LOG_ERR);
            }

            return $result;

        } catch (Exception $e) {
            $this->error = "Invoice status check failed: " . $e->getMessage();
            dol_syslog("KsefClient::checkInvoiceInSession ERROR: " . $this->error, LOG_ERR);
            return false;
        }
    }


    /**
     * @brief Checks session status
     * @param $sessionRef Session reference
     * @return array|false Session status
     * @called_by submitInvoice()
     */
    public function checkSessionStatus($sessionRef)
    {
        if (!$this->authenticate()) {
            $this->error = "Authentication failed";
            return false;
        }

        try {
            $response = $this->makeRequest('GET',
                "/sessions/{$sessionRef}",
                null,
                array(
                    "Authorization: Bearer {$this->session_token}",
                    'Accept: application/json'
                )
            );

            if (!$response) throw new Exception('Failed to check session status');

            $statusData = json_decode($response, true);

            $result = array(
                'status_code' => isset($statusData['status']['code']) ? $statusData['status']['code'] : null,
                'status_description' => isset($statusData['status']['description']) ? $statusData['status']['description'] : null,
                'valid_until' => isset($statusData['validUntil']) ? $statusData['validUntil'] : null,
                'invoice_count' => isset($statusData['invoiceCount']) ? $statusData['invoiceCount'] : 0,
                'failed_invoice_count' => isset($statusData['failedInvoiceCount']) ? $statusData['failedInvoiceCount'] : 0,
                'raw_response' => $statusData
            );

            if ($result['status_code'] >= 400 || $result['failed_invoice_count'] > 0) {
                $this->last_error_code = $result['status_code'];
                $this->last_error_details = array(
                    'code' => $result['status_code'],
                    'description' => $result['status_description'],
                    'details' => array("Failed invoices: {$result['failed_invoice_count']}")
                );
            }

            return $result;

        } catch (Exception $e) {
            $this->error = "Session status check failed: " . $e->getMessage();
            dol_syslog("KsefClient::checkSessionStatus ERROR: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Sets request timeout
     * @param $timeout Timeout in seconds
     * @return void
     * @called_by KSEF::submitInvoice()
     */
    public function setTimeout($timeout)
    {
        $this->timeout = (int)$timeout;
    }

    /**
     * @brief Tests connection to KSeF API
     * @return bool True if connected
     * @called_by setup.php, howtouse.php
     */
    public function testConnection()
    {
        try {
            dol_syslog("KsefClient: Testing connection to {$this->environment}", LOG_INFO);
            $response = $this->makeRequest('GET', '/security/public-key-certificates', null, ['Accept: application/json']);

            if ($response === false) {
                $this->error = "Failed to connect to KSeF API";
                return false;
            }

            $keys = json_decode($response, true);
            if (empty($keys) || !is_array($keys)) {
                $this->error = "Invalid response from KSeF API";
                return false;
            }
            return true;

        } catch (Exception $e) {
            $this->error = "Connection test failed: " . $e->getMessage();
            return false;
        }
    }

    /**
     * @brief Loads KSeF public key certificate
     * @return bool True if loaded
     * @called_by authenticate()
     */
    private function loadKsefPublicKey()
    {
        try {
            $response = $this->makeRequest('GET', '/security/public-key-certificates', null, ['Accept: application/json']);

            if (!$response) throw new Exception('Failed to fetch public key certificates');

            $certificates = json_decode($response, true);
            if (empty($certificates) || !is_array($certificates)) throw new Exception('Invalid certificates response');

            foreach ($certificates as $cert) {
                if (in_array('KsefTokenEncryption', $cert['usage'] ?? [], true)) {
                    $this->public_key_pem = "-----BEGIN CERTIFICATE-----\n"
                        . chunk_split($cert['certificate'], 64, "\n")
                        . "-----END CERTIFICATE-----\n";
                    return true;
                }
            }
            throw new Exception('No certificate with KsefTokenEncryption usage found');

        } catch (Exception $e) {
            $this->error = "Failed to load public key: " . $e->getMessage();
            return false;
        }
    }


    /**
     * @brief Checks if session is authenticated
     * @return bool True if authenticated
     * @called_by authenticate()
     */
    private function isAuthenticated()
    {
        if (empty($this->session_token)) return false;
        if (!empty($this->session_expiry) && ($this->session_expiry - $this->sessionExpiryBuffer) < time()) {
            $this->session_token = null;
            $this->refresh_token = null;
            return false;
        }
        return true;
    }


    /**
     * @brief Lazy-load phpseclib crypto
     */
    private function loadCrypto()
    {
        require_once __DIR__ . '/../lib/vendor/autoload.php';
    }

    /**
     * @brief Submits invoice to KSeF API
     * @param $invoiceXml FA3 XML string
     * @return array|false Submission result
     * @called_by KSEF::submitInvoice()
     * @calls authenticate(), makeRequest(), checkInvoiceInSession(), checkSessionStatus()
     */
    public function submitInvoice($invoiceXml, $options = array())
    {
        $this->loadCrypto();
        if (!$this->authenticate()) {
            $this->error = "Authentication failed";
            return false;
        }

        try {
            dol_syslog("KsefClient: Starting invoice submission", LOG_INFO);

            $keysJson = $this->makeRequest('GET', '/security/public-key-certificates', null, array('Accept: application/json'));
            if (!$keysJson) throw new Exception('Failed to get public keys');

            $keys = json_decode($keysJson, true);
            $pubKeyPem = null;
            foreach ($keys as $key) {
                if (in_array('SymmetricKeyEncryption', $key['usage'], true)) {
                    $pubKeyPem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($key['certificate'], 64, "\n") . "-----END CERTIFICATE-----\n";
                    break;
                }
            }
            if (!$pubKeyPem) throw new Exception('No suitable public key found for symmetric encryption');

            $symmetricKey = random_bytes(32);
            $iv = random_bytes(16);

            $pubKey = PublicKeyLoader::load($pubKeyPem);
            $pubKey = $pubKey->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
            $encryptedSymmetricKey = $pubKey->encrypt($symmetricKey);

            $encryptedInvoice = openssl_encrypt($invoiceXml, 'AES-256-CBC', $symmetricKey, OPENSSL_RAW_DATA, $iv);
            if ($encryptedInvoice === false) throw new Exception('Failed to encrypt invoice XML');

            $sessionRequest = array(
                'formCode' => array('systemCode' => 'FA (3)', 'schemaVersion' => '1-0E', 'value' => 'FA'),
                'encryption' => array('encryptedSymmetricKey' => base64_encode($encryptedSymmetricKey), 'initializationVector' => base64_encode($iv))
            );

            if (!empty($options['offline_mode'])) {
                $sessionRequest['offlineMode'] = true;
                dol_syslog("KsefClient: Submitting in OFFLINE mode", LOG_INFO);
            }

            $sessionResp = $this->makeRequest('POST', '/sessions/online', json_encode($sessionRequest), array(
                "Authorization: Bearer {$this->session_token}",
                'Content-Type: application/json',
                'Accept: application/json',
            ));

            if (!$sessionResp) throw new Exception('Failed to open invoice submission session');

            $sessionData = json_decode($sessionResp, true);
            $sessionRef = $sessionData['referenceNumber'] ?? null;
            if (!$sessionRef) throw new Exception('Session reference number missing');

            sleep(1);

            $invoiceHash = !empty($options['invoice_hash'])
                ? $options['invoice_hash']
                : base64_encode(hash('sha256', $invoiceXml, true));

            $invoicePayload = array(
                'invoiceHash' => $invoiceHash,
                'invoiceSize' => strlen($invoiceXml),
                'encryptedInvoiceHash' => base64_encode(hash('sha256', $encryptedInvoice, true)),
                'encryptedInvoiceSize' => strlen($encryptedInvoice),
                'encryptedInvoiceContent' => base64_encode($encryptedInvoice)
            );

            if (!empty($options['corrected_hash'])) {
                $invoicePayload['hashOfCorrectedInvoice'] = $options['corrected_hash'];
                dol_syslog("KsefClient: Including correction reference to hash: " . substr($options['corrected_hash'], 0, 20) . "...", LOG_INFO);
            }

            $invoiceResp = $this->makeRequest('POST', "/sessions/online/{$sessionRef}/invoices", json_encode($invoicePayload), array(
                "Authorization: Bearer {$this->session_token}",
                'Content-Type: application/json',
                'Accept: application/json'
            ));
            if (!$invoiceResp) throw new Exception('Failed to submit invoice');

            $invoiceResult = json_decode($invoiceResp, true);
            $invoiceRef = $invoiceResult['referenceNumber'] ?? null;
            if (!$invoiceRef) throw new Exception('Invoice reference missing');

            dol_syslog("KsefClient: Invoice submitted with reference: $invoiceRef", LOG_INFO);

            // Poll for completion
            $maxAttempts = 30;
            $attempt = 0;
            $successfulCount = null;

            while ($attempt < $maxAttempts && $successfulCount === null) {
                sleep(2);
                $attempt++;

                $sessionStatusResp = $this->makeRequest('GET', "/sessions/{$sessionRef}", null, array(
                    "Authorization: Bearer {$this->session_token}",
                    'Accept: application/json'
                ));

                if ($sessionStatusResp) {
                    $sessionStatus = json_decode($sessionStatusResp, true);

                    if ($attempt % 3 == 0) {
                        $invoiceStatusResp = $this->makeRequest('GET', "/sessions/{$sessionRef}/invoices/{$invoiceRef}", null, array(
                            "Authorization: Bearer {$this->session_token}",
                            'Accept: application/json'
                        ));
                        if ($invoiceStatusResp) {
                            $invStatus = json_decode($invoiceStatusResp, true);
                            if (isset($invStatus['status']['code']) && $invStatus['status']['code'] == 400) {
                                $this->last_error_code = $invStatus['processingCode'] ?? null;
                                $this->last_error_details = $invStatus;
                                throw new Exception('Invoice rejected during processing: ' . ($invStatus['status']['description'] ?? 'Unknown error'));
                            }
                        }
                    }

                    if (isset($sessionStatus['successfulInvoiceCount'])) {
                        $successfulCount = $sessionStatus['successfulInvoiceCount'];
                        break;
                    } else if (isset($sessionStatus['failedInvoiceCount']) && $sessionStatus['failedInvoiceCount'] > 0) {
                        $invoiceStatus = $this->checkInvoiceInSession($sessionRef, $invoiceRef);

                        if ($invoiceStatus) {
                            dol_syslog("KsefClient: Invoice status: " . json_encode($invoiceStatus), LOG_DEBUG);

                            $rawResponse = $invoiceStatus['raw_response'] ?? null;
                            if ($rawResponse && isset($rawResponse['status']['code']) && $rawResponse['status']['code'] == 440) {
                                $originalKsefNumber = $rawResponse['status']['extensions']['originalKsefNumber'] ?? null;
                                $this->last_error_code = 440;
                                $this->last_error_details = array(
                                    'code' => 440,
                                    'description' => $rawResponse['status']['description'] ?? 'Duplicate invoice number',
                                    'details' => $rawResponse['status']['details'] ?? [],
                                    'original_ksef_number' => $originalKsefNumber,
                                    'original_session' => $rawResponse['status']['extensions']['originalSessionReferenceNumber'] ?? null,
                                    'raw_response' => $rawResponse,
                                    'session_status' => $sessionStatus
                                );

                                $errorMsg = "Duplicate invoice number - already exists in KSeF";
                                if ($originalKsefNumber) {
                                    $errorMsg .= " as " . $originalKsefNumber;
                                }
                                throw new Exception($errorMsg);
                            }

                            $this->last_error_code = $invoiceStatus['processing_code'] ?? null;
                            $this->last_error_details = array(
                                'code' => $invoiceStatus['processing_code'] ?? null,
                                'description' => $invoiceStatus['processing_description'] ?? '',
                                'details' => $invoiceStatus['status_details'] ?? [],
                                'raw_response' => $invoiceStatus['raw_response'] ?? null,
                                'session_status' => $sessionStatus
                            );

                            $errorMsg = "Invoice validation failed";
                            if (!empty($invoiceStatus['processing_description'])) {
                                $errorMsg .= ": " . $invoiceStatus['processing_description'];
                            }
                            if (!empty($invoiceStatus['status_details'])) {
                                $errorMsg .= " - " . implode('; ', $invoiceStatus['status_details']);
                            }
                            throw new Exception($errorMsg);
                        }

                        $this->last_error_details = array(
                            'session_status' => $sessionStatus,
                            'failed_count' => $sessionStatus['failedInvoiceCount'] ?? 'unknown'
                        );
                        throw new Exception('Invoice validation failed during processing (no detail available)');
                    }
                }
            }

            if ($successfulCount === null) throw new Exception("Invoice processing timeout");

            $this->makeRequest('POST', "/sessions/online/{$sessionRef}/close", null, array("Authorization: Bearer {$this->session_token}"));
            sleep(1);

            $invoicesResp = $this->makeRequest('GET', "/sessions/{$sessionRef}/invoices", null, array(
                "Authorization: Bearer {$this->session_token}",
                'Accept: application/json'
            ));

            if ($invoicesResp) {
                $invoicesData = json_decode($invoicesResp, true);
                if (!empty($invoicesData['invoices'][0]['ksefNumber'])) {
                    $ksefNumber = $invoicesData['invoices'][0]['ksefNumber'];
                    $invoiceHash = $invoicesData['invoices'][0]['invoiceHash'] ?? null;

                    $upoXml = null;
                    try {
                        $upoResp = $this->makeRequest('GET', "/sessions/{$sessionRef}/invoices/{$invoiceRef}/upo", null, array(
                            "Authorization: Bearer {$this->session_token}",
                            'Accept: application/octet-stream'
                        ));
                        if ($upoResp) {
                            $upoXml = $upoResp;
                        } else {
                            dol_syslog("KsefClient: UPO download returned false - HTTP " . $this->last_http_code . " - " . $this->error, LOG_WARNING);
                        }
                    } catch (Exception $e) {
                        dol_syslog("KsefClient: UPO download exception: " . $e->getMessage(), LOG_WARNING);
                    }

                    return array(
                        'status' => 'ACCEPTED',
                        'reference_number' => $invoiceRef,
                        'session_reference' => $sessionRef,
                        'ksef_number' => $ksefNumber,
                        'invoice_hash' => $invoiceHash,
                        'upo_xml' => $upoXml
                    );
                }
            }

            throw new Exception('Could not retrieve KSeF number from session');

        } catch (Exception $e) {
            $this->error = "Invoice submission error: " . $e->getMessage();
            dol_syslog("KsefClient: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Authenticates with KSeF API
     * @return bool True if authenticated
     * @called_by submitInvoice(), checkStatus(), downloadUPO()
     * @calls loadKsefPublicKey(), makeRequest(), authenticateWithCertificate()
     */
    public function authenticate()
    {
        $this->loadCrypto();
        if ($this->isAuthenticated()) return true;

        if ($this->auth_method == 'certificate') {
            return $this->authenticateWithCertificate();
        }

        if (empty($this->ksef_token)) throw new Exception('KSeF token not configured');

        $challengeResp = $this->makeRequest('POST', '/auth/challenge', json_encode(array('contextIdentifier' => array('type' => 'Nip', 'value' => $this->nip))), array('Content-Type: application/json', 'Accept: application/json'));
        if (!$challengeResp) throw new Exception('Failed to obtain authentication challenge');

        $challengeData = json_decode($challengeResp, true);
        if (empty($challengeData['challenge'])) throw new Exception('Invalid challenge response from KSeF');

        $challenge = $challengeData['challenge'];
        $timestamp = $challengeData['timestamp'];

        if (empty($this->public_key_pem)) {
            if (!$this->loadKsefPublicKey()) throw new Exception('Unable to load KSeF public key certificate');
        }

        $dt = new DateTime($timestamp, new DateTimeZone('UTC'));
        $toEncrypt = $this->ksef_token . '|' . $dt->format('Uv');

        $key = PublicKeyLoader::load($this->public_key_pem);
        $key = $key->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
        $encryptedB64 = base64_encode($key->encrypt($toEncrypt));

        $authResp = $this->makeRequest('POST', '/auth/ksef-token', json_encode(array(
            'challenge' => $challenge,
            'contextIdentifier' => array('type' => 'Nip', 'value' => $this->nip),
            'encryptedToken' => $encryptedB64
        )), array('Content-Type: application/json', 'Accept: application/json'));

        if (!$authResp) throw new Exception('Authentication request failed');

        $authData = json_decode($authResp, true);
        $authToken = $authData['authenticationToken']['token'] ?? null;
        $referenceNumber = $authData['referenceNumber'] ?? null;

        if (!$authToken || !$referenceNumber) throw new Exception('Invalid authentication response received');

        // Poll status
        $maxAttempts = 10;
        $pollInterval = 2;
        $statusCode = 0;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $statusResp = $this->makeRequest('GET', '/auth/' . $referenceNumber, null, array("Authorization: Bearer {$authToken}", 'Accept: application/json'));
            if (!$statusResp) {
                if ($attempt < $maxAttempts) sleep($pollInterval);
                continue;
            }
            $statusData = json_decode($statusResp, true);
            $statusCode = isset($statusData['status']['code']) ? (int)$statusData['status']['code'] : 0;

            if ($statusCode === 200) break;
            if ($statusCode >= 400) throw new Exception("Authentication failed (status $statusCode)");
            if ($attempt < $maxAttempts) sleep($pollInterval);
        }

        if ($statusCode !== 200) throw new Exception("Authentication status verification timeout");

        $redeemResp = $this->makeRequest('POST', '/auth/token/redeem', json_encode(array('referenceNumber' => $referenceNumber, 'authenticationToken' => array('token' => $authToken))), array("Authorization: Bearer {$authToken}", "Content-Type: application/json", "Accept: application/json"));

        if (!$redeemResp) throw new Exception('Token redeem request failed');

        $tokenData = json_decode($redeemResp, true);
        $this->session_token = $tokenData['accessToken']['token'] ?? null;
        $this->refresh_token = $tokenData['refreshToken']['token'] ?? null;
        $this->session_expiry = isset($tokenData['accessToken']['validUntil']) ? strtotime($tokenData['accessToken']['validUntil']) : null;

        if (!$this->session_token) throw new Exception('Invalid tokens returned on redeem');
        return true;
    }

    /**
     * @brief Authenticates with KSeF API using certificate with XAdES
     * @return bool True if authenticated
     * @called_by authenticate()
     */
    private function authenticateWithCertificate()
    {
        if (!$this->loadAuthCertificateCredentials()) {
            throw new Exception($this->error);
        }

        $challengeResp = $this->makeRequest('POST', '/auth/challenge',
            json_encode(array('contextIdentifier' => array('type' => 'Nip', 'value' => $this->nip))),
            array('Content-Type: application/json', 'Accept: application/json')
        );

        if (!$challengeResp) {
            throw new Exception('Failed to obtain authentication challenge');
        }

        $challengeData = json_decode($challengeResp, true);
        if (empty($challengeData['challenge'])) {
            throw new Exception('Invalid challenge response from KSeF');
        }

        $challenge = $challengeData['challenge'];
        $timestamp = $challengeData['timestamp'];

        $authTokenRequestXml = $this->buildAuthTokenRequestXml($challenge, $timestamp);

        $signedXml = $this->signXadesAuthRequest($authTokenRequestXml);

        if (!$signedXml) {
            throw new Exception('Failed to sign authentication request: ' . $this->error);
        }

        $authResp = $this->makeRequest('POST', '/auth/xades-signature', $signedXml, array(
            'Content-Type: application/xml',
            'Accept: application/json'
        ));

        if (!$authResp) {
            throw new Exception('Certificate authentication request failed: ' . $this->error);
        }

        $authData = json_decode($authResp, true);
        $authToken = $authData['authenticationToken']['token'] ?? null;
        $referenceNumber = $authData['referenceNumber'] ?? null;

        if (!$authToken || !$referenceNumber) {
            dol_syslog("KsefClient: Auth response: " . $authResp, LOG_ERR);
            throw new Exception('Invalid authentication response received');
        }

        $maxAttempts = 20;
        $pollInterval = 3;
        $statusCode = 0;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $statusResp = $this->makeRequest('GET', '/auth/' . $referenceNumber, null,
                array("Authorization: Bearer {$authToken}", 'Accept: application/json')
            );

            if (!$statusResp) {
                if ($attempt < $maxAttempts) sleep($pollInterval);
                continue;
            }

            $statusData = json_decode($statusResp, true);
            $statusCode = isset($statusData['status']['code']) ? (int)$statusData['status']['code'] : 0;

            dol_syslog("KsefClient: Auth status poll attempt $attempt: code $statusCode", LOG_DEBUG);

            if ($statusCode === 200) break;
            if ($statusCode >= 400) {
                $desc = $statusData['status']['description'] ?? 'Unknown error';
                throw new Exception("Certificate authentication failed (status $statusCode): $desc");
            }
            if ($attempt < $maxAttempts) sleep($pollInterval);
        }

        if ($statusCode !== 200) {
            throw new Exception("Certificate authentication status verification timeout");
        }

        $redeemResp = $this->makeRequest('POST', '/auth/token/redeem',
            json_encode(array('referenceNumber' => $referenceNumber, 'authenticationToken' => array('token' => $authToken))),
            array("Authorization: Bearer {$authToken}", "Content-Type: application/json", "Accept: application/json")
        );

        if (!$redeemResp) {
            throw new Exception('Token redeem request failed');
        }

        $tokenData = json_decode($redeemResp, true);
        $this->session_token = $tokenData['accessToken']['token'] ?? null;
        $this->refresh_token = $tokenData['refreshToken']['token'] ?? null;
        $this->session_expiry = isset($tokenData['accessToken']['validUntil']) ? strtotime($tokenData['accessToken']['validUntil']) : null;

        if (!$this->session_token) {
            throw new Exception('Invalid tokens returned on redeem');
        }

        return true;
    }

    /**
     * @brief Builds AuthTokenRequest XML for certificate authentication (KSeF API v2)
     * @param string $challenge Challenge string from /auth/challenge
     * @param string $timestamp Timestamp from challenge (NOT USED in v2 auth request XML)
     * @return string XML string
     * @called_by authenticateWithCertificate()
     */
    private function buildAuthTokenRequestXml($challenge, $timestamp)
    {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $namespace = 'http://ksef.mf.gov.pl/auth/token/2.0';

        $root = $xml->createElementNS($namespace, 'AuthTokenRequest');
        $root->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:schemaLocation',
            'http://ksef.mf.gov.pl/auth/token/2.0 http://ksef.mf.gov.pl/schema/gtw/svc/auth/request/2.0/authv2.xsd');
        $xml->appendChild($root);

        $challengeEl = $xml->createElement('Challenge', $challenge);
        $root->appendChild($challengeEl);

        $contextIdent = $xml->createElement('ContextIdentifier');
        $nipEl = $xml->createElement('Nip', $this->nip);
        $contextIdent->appendChild($nipEl);
        $root->appendChild($contextIdent);

        $subjectType = $xml->createElement('SubjectIdentifierType', 'certificateSubject');
        $root->appendChild($subjectType);

        return $xml->saveXML();
    }

    /**
     * @brief Signs XML with XAdES-BES using authentication certificate
     * @param string $xml XML to sign
     * @return string|false Signed XML or false on error
     * @called_by authenticateWithCertificate()
     */
    private function signXadesAuthRequest($xml)
    {
        try {
            $privateKey = openssl_pkey_get_private($this->auth_private_key_pem);
            if (!$privateKey) {
                throw new Exception('Failed to load private key: ' . openssl_error_string());
            }

            $keyDetails = openssl_pkey_get_details($privateKey);
            $isEcKey = ($keyDetails['type'] == OPENSSL_KEYTYPE_EC);

            $dom = new DOMDocument();
            $dom->loadXML($xml);
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = false;

            $canonicalXml = $dom->C14N(true, false);
            $digest = base64_encode(hash('sha256', $canonicalXml, true));

            $signatureId = 'Signature-' . bin2hex(random_bytes(8));
            $signedPropertiesId = 'SignedProperties-' . bin2hex(random_bytes(8));
            $keyInfoId = 'KeyInfo-' . bin2hex(random_bytes(8));
            $signingTime = gmdate('Y-m-d\TH:i:s\Z');

            $certDer = ksefPemToDer($this->auth_certificate_pem);
            $certDigest = base64_encode(hash('sha256', $certDer, true));

            $certInfo = openssl_x509_parse($this->auth_certificate_pem);
            $issuerName = ksefFormatIssuerNameRfc2253($certInfo['issuer']);
            $serialNumber = $certInfo['serialNumber'];

            $signedPropertiesXml = $this->buildSignedProperties(
                $signedPropertiesId, $signingTime, $certDigest, $issuerName, $serialNumber
            );

            $spDom = new DOMDocument();
            $spDom->loadXML($signedPropertiesXml);
            $spCanonical = $spDom->C14N(true, false);
            $spDigest = base64_encode(hash('sha256', $spCanonical, true));

            $signatureMethod = $isEcKey
                ? 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256'
                : 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

            $signedInfoXml = '<ds:SignedInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#">' .
                '<ds:CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>' .
                '<ds:SignatureMethod Algorithm="' . $signatureMethod . '"/>' .
                '<ds:Reference URI="">' .
                '<ds:Transforms>' .
                '<ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>' .
                '<ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>' .
                '</ds:Transforms>' .
                '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>' .
                '<ds:DigestValue>' . $digest . '</ds:DigestValue>' .
                '</ds:Reference>' .
                '<ds:Reference URI="#' . $signedPropertiesId . '" Type="http://uri.etsi.org/01903#SignedProperties">' .
                '<ds:Transforms>' .
                '<ds:Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>' .
                '</ds:Transforms>' .
                '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>' .
                '<ds:DigestValue>' . $spDigest . '</ds:DigestValue>' .
                '</ds:Reference>' .
                '</ds:SignedInfo>';

            $siDom = new DOMDocument();
            $siDom->loadXML($signedInfoXml);
            $canonicalSignedInfo = $siDom->C14N(true, false);

            $signature = '';
            if (!openssl_sign($canonicalSignedInfo, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                throw new Exception('Signing failed: ' . openssl_error_string());
            }

            if ($isEcKey) {
                $curveName = $keyDetails['ec']['curve_name'] ?? 'prime256v1';
                $componentSize = 32;
                if (strpos($curveName, '384') !== false) {
                    $componentSize = 48;
                } elseif (strpos($curveName, '521') !== false) {
                    $componentSize = 66;
                }
                $signature = ksefConvertEcdsaDerToRaw($signature, $componentSize);
            }

            $signatureValue = base64_encode($signature);

            $certClean = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s/', '', $this->auth_certificate_pem);

            $signatureElement = '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="' . $signatureId . '">' .
                $signedInfoXml .
                '<ds:SignatureValue>' . $signatureValue . '</ds:SignatureValue>' .
                '<ds:KeyInfo Id="' . $keyInfoId . '">' .
                '<ds:X509Data>' .
                '<ds:X509Certificate>' . $certClean . '</ds:X509Certificate>' .
                '</ds:X509Data>' .
                '</ds:KeyInfo>' .
                '<ds:Object>' .
                '<xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Target="#' . $signatureId . '">' .
                $signedPropertiesXml .
                '</xades:QualifyingProperties>' .
                '</ds:Object>' .
                '</ds:Signature>';

            $signatureDom = new DOMDocument();
            $signatureDom->loadXML($signatureElement);
            $signatureNode = $dom->importNode($signatureDom->documentElement, true);
            $dom->documentElement->appendChild($signatureNode);

            $result = $dom->saveXML();

            dol_syslog("KsefClient: XAdES signature created successfully", LOG_DEBUG);

            return $result;

        } catch (Exception $e) {
            $this->error = "XAdES signing failed: " . $e->getMessage();
            dol_syslog("KsefClient::signXadesAuthRequest ERROR: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Builds SignedProperties element for XAdES-BES signature
     * @param string $id SignedProperties ID attribute
     * @param string $signingTime ISO 8601 signing timestamp
     * @param string $certDigest Base64-encoded SHA-256 digest of certificate
     * @param string $issuerName Issuer DN in RFC 2253 format
     * @param string $serialNumber Certificate serial number
     * @return string SignedProperties XML string
     * @called_by signXadesAuthRequest()
     */
    private function buildSignedProperties($id, $signingTime, $certDigest, $issuerName, $serialNumber)
    {
        $issuerSerialV2Base64 = ksefGenerateIssuerSerialV2DER($this->auth_certificate_pem);

        return '<xades:SignedProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Id="' . $id . '">' .
            '<xades:SignedSignatureProperties>' .
            '<xades:SigningTime>' . $signingTime . '</xades:SigningTime>' .
            '<xades:SigningCertificateV2>' .
            '<xades:Cert>' .
            '<xades:CertDigest>' .
            '<ds:DigestMethod xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>' .
            '<ds:DigestValue xmlns:ds="http://www.w3.org/2000/09/xmldsig#">' . $certDigest . '</ds:DigestValue>' .
            '</xades:CertDigest>' .
            '<xades:IssuerSerialV2>' . $issuerSerialV2Base64 . '</xades:IssuerSerialV2>' .
            '</xades:Cert>' .
            '</xades:SigningCertificateV2>' .
            '</xades:SignedSignatureProperties>' .
            '</xades:SignedProperties>';
    }

    /**
     * @brief Terminates current session
     * @return bool True if terminated
     * @called_by External cleanup
     * @calls makeRequest()
     */
    public function terminateSession()
    {
        if (empty($this->session_token)) return true;
        try {
            $this->makeRequest('DELETE', '/auth/sessions/current', null, array("Authorization: Bearer {$this->session_token}", "Accept: application/json"));
            $this->session_token = null;
            $this->refresh_token = null;
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @brief Checks submission status
     * @param $referenceNumber Reference number
     * @return array|false Status result
     * @called_by KSEF::checkStatus()
     * @calls makeRequest()
     */
    public function checkStatus($referenceNumber)
    {
        if (!$this->authenticate()) {
            $this->error = "Authentication failed";
            return false;
        }

        try {
            $response = $this->makeRequest('GET', "/invoices/status/{$referenceNumber}", null, array("Authorization: Bearer {$this->session_token}", 'Accept: application/json'));
            if (!$response) throw new Exception('Failed to check invoice status');

            $statusData = json_decode($response, true);
            $status = 'UNKNOWN';
            if (isset($statusData['processingCode'])) {
                if ($statusData['processingCode'] === 200) $status = 'ACCEPTED';
                elseif ($statusData['processingCode'] >= 400) $status = 'REJECTED';
                else $status = 'PENDING';
            }

            return array(
                'status' => $status,
                'ksef_number' => $statusData['ksefReferenceNumber'] ?? null,
                'processing_code' => $statusData['processingCode'] ?? null,
                'message' => $statusData['message'] ?? null,
                'timestamp' => $statusData['timestamp'] ?? null
            );

        } catch (Exception $e) {
            $this->error = "Status check failed: " . $e->getMessage();
            return false;
        }
    }

    /**
     * @brief Downloads UPO XML
     * @param $ksefNumber KsefService number
     * @param $sessionRef Session reference (optional)
     * @return string|false UPO XML
     * @called_by KSEF::downloadUPO(), submitInvoice()
     * @calls makeRequest()
     */
    public function downloadUPO($ksefNumber, $sessionRef = null)
    {
        if (!$this->authenticate()) return false;

        try {
            if (!empty($sessionRef)) {
                $endpoint = "/sessions/{$sessionRef}/invoices/ksef/{$ksefNumber}/upo";
            } else {
                $endpoint = "/invoices/{$ksefNumber}/upo";
            }
            $upoXml = $this->makeRequest('GET', $endpoint, null, array("Authorization: Bearer {$this->session_token}", 'Accept: application/octet-stream'));
            if ($upoXml) return $upoXml;
            dol_syslog("KsefClient::downloadUPO failed - HTTP " . $this->last_http_code . " - " . $this->error, LOG_WARNING);
            throw new Exception("Failed to download UPO");
        } catch (Exception $e) {
            $this->error = "UPO download failed: " . $e->getMessage();
            return false;
        }
    }

    /**
     * @brief Makes HTTP request to KSeF API
     * @param $method HTTP method
     * @param $endpoint API endpoint
     * @param $data Request data
     * @param $headers HTTP headers
     * @return string|false Response or false
     * @called_by authenticate(), submitInvoice(), checkStatus(), downloadUPO()
     * @calls parseErrorResponse(), formatErrorMessage()
     */
    protected function makeRequest($method, $endpoint, $data = null, $headers = array())
    {
        $url = $this->api_url . $endpoint;

        $defaultHeaders = array(
            'Accept: application/json',
            'User-Agent: Dolibarr-KSeF/2.0',
        );
        $headers = array_merge($defaultHeaders, $headers);

        $this->retry_after_seconds = null;
        $this->last_http_code = null;
        $responseHeaders = [];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) < 2) {
                return $len;
            }
            $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);
            return $len;
        });

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                break;
            case 'GET':
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                break;
            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                break;
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $this->last_http_code = $httpCode;

        if ($curlError) {
            $this->error = "Curl error: $curlError";
            dol_syslog("KsefClient::makeRequest Curl error: $curlError", LOG_ERR);
            return false;
        }

        if ($httpCode >= 400) {
            $errorDetails = $this->parseErrorResponse($response);
            $this->last_error_code = $errorDetails['code'];
            $this->last_error_details = $errorDetails;

            if ($httpCode == 429) {
                if (isset($responseHeaders['retry-after'])) {
                    $this->retry_after_seconds = (int)$responseHeaders['retry-after'];
                    dol_syslog("KsefClient::makeRequest Rate limited (429), Retry-After: {$this->retry_after_seconds}s", LOG_WARNING);
                } else {
                    $this->retry_after_seconds = 60;
                    dol_syslog("KsefClient::makeRequest Rate limited (429) but no Retry-After header, defaulting to 60s", LOG_WARNING);
                }
            }

            $this->error = "HTTP $httpCode: " . $this->formatErrorMessage($errorDetails);

            dol_syslog("KsefClient::makeRequest Error: " . $this->error, LOG_ERR);
            dol_syslog("KsefClient::makeRequest Response: " . $response, LOG_DEBUG);

            return false;
        }

        return $response;
    }


    /**
     * Fetch KSeF's RSA public key used to encrypt the AES key
     * @return string|false PEM-encoded public key or false on error
     */
    public function fetchKsefPublicKey()
    {
        try {
            $url = $this->api_url . '/security/public-key-certificates';
            dol_syslog("KsefClient::fetchKsefPublicKey Fetching from: $url", LOG_DEBUG);

            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => array(
                    'Accept: application/json',
                    'Content-Type: application/json'
                ),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
            ));

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new Exception('cURL error: ' . $curlError);
            }

            if ($httpCode !== 200) {
                throw new Exception("HTTP error $httpCode: " . substr($response, 0, 500));
            }

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON response: ' . json_last_error_msg());
            }

            dol_syslog("KsefClient::fetchKsefPublicKey Response: " . substr($response, 0, 500), LOG_DEBUG);

            $certificates = null;

            if (isset($data['certificates'])) {
                $certificates = $data['certificates'];
            } elseif (is_array($data) && isset($data[0])) {
                $certificates = $data;
            }

            if (empty($certificates)) {
                throw new Exception('No certificates in response. Response: ' . substr($response, 0, 200));
            }

            dol_syslog("KsefClient::fetchKsefPublicKey Found " . count($certificates) . " certificates", LOG_DEBUG);

            $encryptionCert = null;

            foreach ($certificates as $index => $cert) {
                $rawUsage = isset($cert['usage']) ? $cert['usage'] : null;
                $isSymmetricKeyEncryption = false;
                if (is_array($rawUsage)) {
                    foreach ($rawUsage as $usageValue) {
                        if (is_string($usageValue) && stripos($usageValue, 'SymmetricKeyEncryption') !== false) {
                            $isSymmetricKeyEncryption = true;
                            break;
                        }
                    }
                } elseif (is_string($rawUsage) && !empty($rawUsage)) {
                    $isSymmetricKeyEncryption = (stripos($rawUsage, 'SymmetricKeyEncryption') !== false);
                }

                if ($isSymmetricKeyEncryption) {
                    $encryptionCert = $cert;
                    dol_syslog("KsefClient::fetchKsefPublicKey Found SymmetricKeyEncryption certificate at index $index", LOG_INFO);
                    break;
                }
            }

            if (!$encryptionCert) {
                throw new Exception('No suitable encryption certificate found');
            }

            $certData = $encryptionCert['certificate'] ?? $encryptionCert['cert'] ?? $encryptionCert['publicKey'] ?? null;

            if (empty($certData)) {
                throw new Exception('Certificate data is empty');
            }

            $pemCert = "-----BEGIN CERTIFICATE-----\n"
                . chunk_split($certData, 64, "\n")
                . "-----END CERTIFICATE-----\n";

            dol_syslog("KsefClient::fetchKsefPublicKey Successfully loaded certificate", LOG_DEBUG);
            return $pemCert;

        } catch (Exception $e) {
            $this->error = "Fetch public key failed: " . $e->getMessage();
            dol_syslog("KsefClient::fetchKsefPublicKey Error: " . $this->error, LOG_ERR);
            return false;
        }
    }


    /**
     * Generate export encryption data: local AES-256 key + IV, AES key RSA-encrypted with KSeF public key
     * @return array|false Encryption data or false on error
     */
    public function generateEncryptionData()
    {
        $this->loadCrypto();
        try {
            $aesKey = random_bytes(32);
            $iv = random_bytes(16);

            $publicKeyPem = $this->fetchKsefPublicKey();
            if (!$publicKeyPem) {
                throw new Exception("Failed to fetch KSeF public key");
            }

            $key = PublicKeyLoader::load($publicKeyPem);
            $key = $key->withPadding(RSA::ENCRYPTION_OAEP)
                ->withHash('sha256')
                ->withMGFHash('sha256');

            $encryptedAesKey = $key->encrypt($aesKey);

            dol_syslog("KsefClient::generateEncryptionData Generated AES key and encrypted with RSA (OAEP SHA-256)", LOG_DEBUG);

            return array(
                'aes_key' => $aesKey,
                'iv' => $iv,
                'encryption_info' => array(
                    'encryptedSymmetricKey' => base64_encode($encryptedAesKey),
                    'initializationVector' => base64_encode($iv),
                ),
            );

        } catch (Exception $e) {
            $this->error = "Generate encryption data failed: " . $e->getMessage();
            dol_syslog("KsefClient::generateEncryptionData Error: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * Initialize HWM-based invoice export
     * @param string $fromDateISO Start date in ISO 8601 format (continuation point)
     * @param string $subjectType Subject type: 'subject1', 'subject2', etc.
     * @param array $encryptionInfo Encryption info from generateEncryptionData()['encryption_info']
     * @param string|null $toDateISO Optional end date (omit for continuous sync)
     * @return array|false Response with referenceNumber or false on error
     */
    public function initHwmExport($fromDateISO, $subjectType, $encryptionInfo, $toDateISO = null)
    {
        if (!$this->authenticate()) {
            $this->error = "Authentication failed";
            return false;
        }

        try {
            $dateRange = array(
                'dateType' => 'permanentStorage',
                'from' => $fromDateISO,
                'restrictToPermanentStorageHwmDate' => true,
            );

            if ($toDateISO !== null) {
                $dateRange['to'] = $toDateISO;
            }

            $exportRequest = array(
                'filters' => array(
                    'subjectType' => strtolower($subjectType),
                    'dateRange' => $dateRange,
                ),
                'encryption' => $encryptionInfo,
            );

            $response = $this->makeRequest(
                'POST',
                '/invoices/exports',
                json_encode($exportRequest),
                array(
                    "Authorization: Bearer {$this->session_token}",
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-KSeF-Feature: include-metadata',
                )
            );

            if (!$response) {
                throw new Exception('Failed to init export: ' . $this->error);
            }

            $data = json_decode($response, true);

            if (empty($data['referenceNumber'])) {
                throw new Exception('No reference number in response');
            }

            dol_syslog("KsefClient::initHwmExport Started export: " . $data['referenceNumber'], LOG_INFO);

            return array(
                'referenceNumber' => $data['referenceNumber'],
                'timestamp' => $data['timestamp'] ?? null,
            );

        } catch (Exception $e) {
            $this->error = "Init HWM export failed: " . $e->getMessage();
            dol_syslog("KsefClient::initHwmExport Error: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * Get export status with HWM data
     * @param string $referenceNumber Export reference number
     * @return array|false Status data or false on error
     */
    public function getExportStatus($referenceNumber)
    {
        if (!$this->authenticate()) {
            $this->error = "Authentication failed";
            return false;
        }

        try {
            $response = $this->makeRequest(
                'GET',
                '/invoices/exports/' . urlencode($referenceNumber),
                null,
                array(
                    "Authorization: Bearer {$this->session_token}",
                    'Accept: application/json'
                )
            );

            if (!$response) {
                throw new Exception('Failed to get export status: ' . $this->error);
            }

            $data = json_decode($response, true);

            $result = array(
                'status' => 'PROCESSING',
                'processingCode' => null,
                'parts' => array(),
                'isTruncated' => false,
                'permanentStorageHwmDate' => null,
                'lastPermanentStorageDate' => null,
                'numberOfElements' => 0,
                'raw' => $data,
            );

            $code = null;
            if (isset($data['status']['code'])) {
                $code = (int)$data['status']['code'];
            } elseif (isset($data['processingCode'])) {
                $code = (int)$data['processingCode'];
            }

            if ($code !== null) {
                $result['processingCode'] = $code;

                if ($code === 200) {
                    $result['status'] = 'COMPLETED';
                } elseif ($code >= 400) {
                    $result['status'] = 'FAILED';
                } elseif ($code >= 100 && $code < 200) {
                    $result['status'] = 'PROCESSING';
                }
            }

            $package = isset($data['package']) ? $data['package'] : $data;

            if (isset($package['parts']) && is_array($package['parts'])) {
                $result['parts'] = $package['parts'];
            } elseif (isset($data['packageParts']['parts'])) {
                $result['parts'] = $data['packageParts']['parts'];
            }

            $result['isTruncated'] = !empty($package['isTruncated']);

            if (isset($package['permanentStorageHwmDate']) && !empty($package['permanentStorageHwmDate'])) {
                $ts = strtotime($package['permanentStorageHwmDate']);
                $result['permanentStorageHwmDate'] = ($ts !== false && $ts > 0) ? $ts : null;
            }

            if (isset($package['lastPermanentStorageDate']) && !empty($package['lastPermanentStorageDate'])) {
                $ts = strtotime($package['lastPermanentStorageDate']);
                $result['lastPermanentStorageDate'] = ($ts !== false && $ts > 0) ? $ts : null;
            }

            if (isset($data['completedDate'])) {
                $result['completedDate'] = $data['completedDate'];
            }

            $result['numberOfElements'] = isset($data['numberOfElements']) ? (int)$data['numberOfElements'] : 0;

            $statusCode = isset($data['status']['code']) ? $data['status']['code'] : 'not found';
            $partsCount = count($result['parts']);
            dol_syslog("KsefClient::getExportStatus Parsed: status.code={$statusCode}, result_status={$result['status']}, Truncated: " . ($result['isTruncated'] ? 'yes' : 'no') . ", Parts: {$partsCount}", LOG_DEBUG);

            return $result;

        } catch (Exception $e) {
            $this->error = "Get export status failed: " . $e->getMessage();
            dol_syslog("KsefClient::getExportStatus Error: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * Download a package part
     *
     * @param array $part Part info with 'url' or 'partUrl'
     * @return string|false Encrypted binary data or false on error
     */
    public function downloadPackagePart($part)
    {
        $partUrl = $part['url'] ?? $part['partUrl'] ?? null;
        if (!$partUrl) {
            $this->error = "No URL in part data";
            return false;
        }

        try {
            $isFullUrl = (strpos($partUrl, 'http') === 0);

            if ($isFullUrl) {
                $url = $partUrl;
            } else {
                $url = $this->api_url . $partUrl;
            }

            $isStorageUrl = (strpos($url, '/storage/') !== false);

            $headers = array('Accept: application/octet-stream');

            if (!$isStorageUrl) {
                if (!$this->authenticate()) {
                    $this->error = "Authentication failed";
                    return false;
                }
                $headers[] = "Authorization: Bearer {$this->session_token}";
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new Exception("Curl error: $curlError");
            }

            if ($httpCode >= 400) {
                throw new Exception("HTTP $httpCode");
            }

            dol_syslog("KsefClient::downloadPackagePart Downloaded " . strlen($response) . " bytes", LOG_DEBUG);
            return $response;

        } catch (Exception $e) {
            $this->error = "Download part failed: " . $e->getMessage();
            dol_syslog("KsefClient::downloadPackagePart Error: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * Decrypt package data using AES key
     * @param string $encryptedData Encrypted binary data
     * @param string $aesKey AES-256 key (32 bytes, raw)
     * @param string $iv IV (16 bytes, raw)
     * @return string|false Decrypted data or false on error
     */
    public function decryptPackageData($encryptedData, $aesKey, $iv)
    {
        try {
            $decrypted = openssl_decrypt(
                $encryptedData,
                'aes-256-cbc',
                $aesKey,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($decrypted === false) {
                throw new Exception("Decryption failed: " . openssl_error_string());
            }

            dol_syslog("KsefClient::decryptPackageData Decrypted " . strlen($encryptedData) . " -> " . strlen($decrypted) . " bytes", LOG_DEBUG);
            return $decrypted;

        } catch (Exception $e) {
            $this->error = "Decrypt failed: " . $e->getMessage();
            dol_syslog("KsefClient::decryptPackageData Error: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * Download and decrypt package to a temp file
     * @param array $parts Package parts from getExportStatus
     * @param string $aesKey AES decryption key
     * @param string $iv Initialization vector
     * @return string|false Path to temp file containing decrypted ZIP, or false on error
     */
    public function downloadPackageToFile($parts, $aesKey, $iv)
    {
        try {
            if (empty($parts)) {
                return false;
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'ksef_dl_');
            if ($tempFile === false) {
                throw new Exception("Failed to create temp file");
            }

            $fp = fopen($tempFile, 'wb');
            if ($fp === false) {
                throw new Exception("Failed to open temp file for writing");
            }

            foreach ($parts as $index => $part) {
                $encrypted = $this->downloadPackagePart($part);
                if ($encrypted === false) {
                    fclose($fp);
                    @unlink($tempFile);
                    throw new Exception("Failed to download part $index");
                }

                $decrypted = $this->decryptPackageData($encrypted, $aesKey, $iv);
                if ($decrypted === false) {
                    fclose($fp);
                    @unlink($tempFile);
                    throw new Exception("Failed to decrypt part $index");
                }

                fwrite($fp, $decrypted);
                unset($encrypted, $decrypted);
            }

            fclose($fp);

            $fileSize = filesize($tempFile);
            dol_syslog("KsefClient::downloadPackageToFile Saved {$fileSize} bytes to {$tempFile}", LOG_INFO);

            return $tempFile;

        } catch (Exception $e) {
            $this->error = "Download to file failed: " . $e->getMessage();
            dol_syslog("KsefClient::downloadPackageToFile Error: " . $this->error, LOG_ERR);
            return false;
        }
    }


    /**
     * Process ZIP package in batches - otherwise OOM error if not
     * @param string $zipFilePath Path to ZIP file
     * @param int $batchSize Number of invoices per batch (default 1000)
     * @param callable $callback Function to call for each batch: function($batch, $batchNum, $totalFiles)
     * @return array|false Summary ['total' => X, 'batches' => Y, 'metadata' => [...]] or false on error
     */
    public function processPackageInBatches($zipFilePath, $batchSize, $callback)
    {
        try {
            if (!file_exists($zipFilePath)) {
                throw new Exception("ZIP file not found: $zipFilePath");
            }

            $zip = new ZipArchive();
            $openResult = $zip->open($zipFilePath);

            if ($openResult !== true) {
                throw new Exception("Failed to open ZIP: error $openResult");
            }

            $totalFiles = $zip->numFiles;
            dol_syslog("KsefClient::processPackageInBatches Opening ZIP with {$totalFiles} files, batch size {$batchSize}", LOG_INFO);

            $metadata = array();
            $batch = array();
            $batchNum = 0;
            $invoiceCount = 0;

            for ($i = 0; $i < $totalFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                $content = $zip->getFromIndex($i);

                if (strcasecmp($filename, '_metadata.json') === 0) {
                    $decoded = json_decode($content, true);
                    $metadata = $decoded['invoices'] ?? $decoded ?? array();
                    continue;
                }

                if (!preg_match('/\.xml$/i', $filename)) {
                    continue;
                }

                $ksefNumber = $this->extractKsefNumberFromContent($content, $filename);
                if ($ksefNumber) {
                    if (isset($batch[$ksefNumber])) {
                        dol_syslog("KsefClient::processPackageInBatches WARNING: duplicate KSeF number {$ksefNumber} from file {$filename} - overwriting previous entry", LOG_WARNING);
                    }
                    $batch[$ksefNumber] = $content;
                    $invoiceCount++;
                } else {
                    dol_syslog("KsefClient::processPackageInBatches WARNING: could not extract KSeF number from file {$filename} - skipping", LOG_WARNING);
                }

                if (count($batch) >= $batchSize) {
                    $batchNum++;
                    dol_syslog("KsefClient::processPackageInBatches Processing batch {$batchNum} ({$invoiceCount} invoices so far)", LOG_INFO);
                    $callback($batch, $batchNum, $totalFiles);
                    $batch = array();
                }
            }

            if (!empty($batch)) {
                $batchNum++;
                dol_syslog("KsefClient::processPackageInBatches Processing final batch {$batchNum} ({$invoiceCount} invoices total)", LOG_INFO);
                $callback($batch, $batchNum, $totalFiles);
            }

            $zip->close();
            dol_syslog("KsefClient::processPackageInBatches Complete: {$invoiceCount} invoices in {$batchNum} batches", LOG_INFO);

            return array(
                'total' => $invoiceCount,
                'batches' => $batchNum,
                'metadata' => $metadata,
            );

        } catch (Exception $e) {
            $this->error = "Batch processing failed: " . $e->getMessage();
            dol_syslog("KsefClient::processPackageInBatches Error: " . $this->error, LOG_ERR);
            return false;
        }
    }


    /**
     * Extract KSeF number
     * @param string $content XML content
     * @param string $filename Original filename
     * @return string|null KSeF number
     */
    private function extractKsefNumberFromContent($content, $filename)
    {
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        if (preg_match('/^(\d{10}-\d{8}-[A-Z0-9-]+)$/i', $basename)) {
            return $basename;
        }
        return $basename ?: null;
    }
}