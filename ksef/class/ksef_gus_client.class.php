<?php
/* Copyright (C) 2026 InPoint Automation Sp z o.o.
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
 * \file    ksef/class/ksef_gus_client.class.php
 * \ingroup ksef
 * \brief   GUS REGON BIR1 web service client
 */

dol_include_once('/ksef/lib/ksef.lib.php');
require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';

class KsefGusClient
{
    const ENDPOINT_TEST = 'https://wyszukiwarkaregontest.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc';
    const ENDPOINT_PROD = 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc';
    const TEST_KEY      = 'abcde12345abcde12345';

    const NS_SOAP = 'http://www.w3.org/2003/05/soap-envelope';
    const NS_WSA  = 'http://www.w3.org/2005/08/addressing';
    const NS_PUBL = 'http://CIS/BIR/PUBL/2014/07';
    const NS_DATA = 'http://CIS/BIR/PUBL/2014/07/DataContract';
    const ACTION_BASE = 'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/';

    const SESSION_KEY = 'KSEF_GUS_SID';

    public $error;
    public $errors = array();

    private $db;
    private $env;
    private $endpoint;
    private $key;
    private $timeout;
    private $sid;
    private $nameStyle;

    /**
     * @brief Constructor
     * @param DoliDB $db Database handler
     * @param $env Environment (TEST or PROD)
     * @return void
     * @called_by class/actions_ksef.class.php, admin/setup_auth.php
     */
    public function __construct($db, $env = null)
    {
        global $conf;

        $this->db = $db;
        $this->env = strtoupper($env ? $env : getDolGlobalString('KSEF_GUS_ENV', 'TEST'));
        $this->endpoint = ($this->env === 'PROD') ? self::ENDPOINT_PROD : self::ENDPOINT_TEST;

        $storedKey = trim(dol_decode(getDolGlobalString('KSEF_GUS_KEY', '')));
        if (empty($storedKey) && $this->env !== 'PROD') {
            $storedKey = self::TEST_KEY;
        }
        $this->key = $storedKey;

        $this->timeout = getDolGlobalInt('KSEF_TIMEOUT', 7);
        $this->nameStyle = getDolGlobalString('KSEF_GUS_NAME_STYLE', 'full');
        $this->error = '';
        $this->sid = '';
    }

    /**
     * @brief Session key for the cached sid, qualified by environment
     * @return string Env qualified session key
     * @called_by login(), logout(), ensureSession()
     */
    private function sessionKey()
    {
        return self::SESSION_KEY . '_' . $this->env;
    }

    /**
     * @brief Looks up a Polish company in GUS by NIP
     * @param $nip NIP in any format (cleaned internally)
     * @return array|false Normalized company data, or false on error
     * @called_by class/actions_ksef.class.php
     * @calls login(), search(), fullReport(), logout()
     */
    public function lookupByNip($nip)
    {
        try {
            return $this->doLookupByNip($nip);
        } finally {
            $this->logout();
        }
    }

    /**
     * @brief Lookup body, wrapped by lookupByNip() for guaranteed logout
     * @param $nip NIP in any format (cleaned internally)
     * @return array|false Normalized company data, or false on error
     * @called_by lookupByNip()
     * @calls login(), search(), fullReport()
     */
    private function doLookupByNip($nip)
    {
        $nip = ksefCleanNIP($nip);
        if (!ksefValidateNIP($nip)) {
            $this->error = 'invalid_nip';
            return false;
        }

        if (empty($this->key)) {
            $this->error = 'no_key';
            return false;
        }

        $rows = $this->search($nip);
        if ($rows === false) {
            return false;
        }

        $row = $this->pickRow($rows);
        if ($row === null) {
            $this->error = 'not_found';
            return false;
        }

        $typ = isset($row['Typ']) ? $row['Typ'] : '';
        $silosId = isset($row['SilosID']) ? $row['SilosID'] : '';
        $regon = ksefCleanREGON(isset($row['Regon']) ? $row['Regon'] : '');
        if (empty($regon)) {
            $this->error = 'not_found';
            return false;
        }

        if ($typ === 'P') {
            $report = $this->fullReport($regon, 'BIR12OsPrawna');
            if ($report === false) {
                return false;
            }
            $result = $this->normalizeLegal($report);
        } elseif ($typ === 'F') {
            // Only CEIDG
            if ($silosId !== '1') {
                $this->error = 'unsupported_type';
                return false;
            }
            $ceidg = $this->fullReport($regon, 'BIR12OsFizycznaDzialalnoscCeidg');
            $ogolne = $this->fullReport($regon, 'BIR12OsFizycznaDaneOgolne');
            if ($ceidg === false || $ogolne === false) {
                return false;
            }
            $result = $this->normalizeSoleProp($ceidg, $ogolne);
        } else {
            $this->error = 'unsupported_type';
            return false;
        }

        return $result;
    }

    /**
     * @brief Logs in and stores the session identifier
     * @return bool True on success (sid available), false otherwise
     * @called_by lookupByNip()
     * @calls request()
     */
    public function login()
    {
        if (empty($this->key)) {
            $this->error = 'no_key';
            return false;
        }
        $body = '<ns:Zaloguj><ns:pKluczUzytkownika>' . $this->escapeXml($this->key) . '</ns:pKluczUzytkownika></ns:Zaloguj>';
        $sid = $this->request(self::ACTION_BASE . 'Zaloguj', $body, 'ZalogujResult', false);

        if ($sid === null) {
            return false;
        }
        $sid = trim($sid);
        if ($sid === '') {
            $this->error = 'auth_failed';
            return false;
        }

        $this->sid = $sid;
        ksefSessionWrite($this->sessionKey(), $sid);
        return true;
    }

    /**
     * @brief Searches for entities by NIP
     * @param $nip Cleaned NIP
     * @return array|false List of dane rows (assoc arrays), false on error
     * @called_by lookupByNip()
     * @calls requestWithSession(), parseDaneRows()
     */
    public function search($nip)
    {
        $body = '<ns:DaneSzukajPodmioty><ns:pParametryWyszukiwania>'
            . '<dat:Nip>' . $this->escapeXml($nip) . '</dat:Nip>'
            . '</ns:pParametryWyszukiwania></ns:DaneSzukajPodmioty>';

        $inner = $this->requestWithSession(self::ACTION_BASE . 'DaneSzukajPodmioty', $body, 'DaneSzukajPodmiotyResult');
        if ($inner === false) {
            return false;
        }

        $rows = $this->parseDaneRows($inner);
        if (empty($rows) || $this->rowIsError($rows[0])) {
            $this->error = 'not_found';
            return false;
        }

        return $rows;
    }

    /**
     * @brief Fetches a full report for a REGON
     * @param $regon 9 digit REGON
     * @param $reportName BIR12 report name
     * @return array|false Report fields (assoc), or false on error
     * @called_by lookupByNip()
     * @calls requestWithSession(), parseDaneRows()
     */
    public function fullReport($regon, $reportName)
    {
        $body = '<ns:DanePobierzPelnyRaport>'
            . '<ns:pRegon>' . $this->escapeXml($regon) . '</ns:pRegon>'
            . '<ns:pNazwaRaportu>' . $this->escapeXml($reportName) . '</ns:pNazwaRaportu>'
            . '</ns:DanePobierzPelnyRaport>';

        $inner = $this->requestWithSession(self::ACTION_BASE . 'DanePobierzPelnyRaport', $body, 'DanePobierzPelnyRaportResult');
        if ($inner === false) {
            return false;
        }

        $rows = $this->parseDaneRows($inner);
        if (empty($rows) || $this->rowIsError($rows[0])) {
            $this->error = 'not_found';
            return false;
        }

        return $rows[0];
    }

    /**
     * @brief Logs out
     * @return void
     * @called_by lookupByNip()
     * @calls request()
     */
    public function logout()
    {
        if (empty($this->sid)) {
            return;
        }
        $body = '<ns:Wyloguj><ns:pIdentyfikatorSesji>' . $this->escapeXml($this->sid) . '</ns:pIdentyfikatorSesji></ns:Wyloguj>';
        $this->request(self::ACTION_BASE . 'Wyloguj', $body, 'WylogujResult', true);
        $this->sid = '';
        ksefSessionUnset($this->sessionKey());
    }

    /**
     * @brief Performs a session call, re-logging if session expired
     * @param $actionUri WS-Addressing action URI
     * @param $bodyXml SOAP body inner XML
     * @param $resultLocalName Local name of the *Result element
     * @return string|false Inner result string, or false on error
     * @called_by search(), fullReport()
     * @calls ensureSession(), login(), request()
     */
    private function requestWithSession($actionUri, $bodyXml, $resultLocalName)
    {
        if (!$this->ensureSession()) {
            return false;
        }

        $result = $this->request($actionUri, $bodyXml, $resultLocalName, true);
        if ($result === null) {
            return false;
        }

        // Expired session returns empty
        if (trim($result) === '') {
            if (!$this->login()) {
                return false;
            }
            $result = $this->request($actionUri, $bodyXml, $resultLocalName, true);
            if ($result === null) {
                return false;
            }
            if (trim($result) === '') {
                $this->error = 'session_error';
                return false;
            }
        }

        return $result;
    }

    /**
     * @brief Ensures a session identifier is available
     * @return bool True if a sid is available
     * @called_by requestWithSession()
     * @calls login()
     */
    private function ensureSession()
    {
        if (!empty($this->sid)) {
            return true;
        }
        $cached = ksefSessionRead($this->sessionKey(), '');
        if (!empty($cached)) {
            $this->sid = $cached;
            return true;
        }
        return $this->login();
    }

    /**
     * @brief Sends one SOAP request and return result
     * @param $actionUri WS-Addressing action URI
     * @param $bodyXml SOAP body inner XML
     * @param $resultLocalName Local name of the *Result element to extract
     * @param $withSid Whether to send the sid HTTP header
     * @return string|null Result element text, or null on transport error
     * @called_by login(), logout(), requestWithSession()
     * @calls buildEnvelope(), extractResult()
     */
    private function request($actionUri, $bodyXml, $resultLocalName, $withSid)
    {
        $envelope = $this->buildEnvelope($actionUri, $bodyXml);

        $headers = array('Content-Type: application/soap+xml;charset=utf-8;action="' . $actionUri . '"');
        if ($withSid && !empty($this->sid)) {
            $headers[] = 'sid: ' . str_replace(array("\r", "\n"), '', $this->sid);
        }

        $response = getURLContent($this->endpoint, 'POSTALREADYFORMATED', $envelope, 1, $headers, array('https'), 0, 1, 0, $this->timeout);

        if (!empty($response['curl_error_no']) || empty($response['http_code'])) {
            $this->error = 'transport_error';
            dol_syslog('KsefGusClient::request: transport error - ' . (isset($response['curl_error_msg']) ? $response['curl_error_msg'] : ''), LOG_ERR);
            return null;
        }
        if ($response['http_code'] >= 400) {
            $this->error = 'http_error';
            dol_syslog('KsefGusClient::request: HTTP ' . $response['http_code'] . ' for ' . $actionUri, LOG_ERR);
            return null;
        }

        $result = $this->extractResult($response['content'], $resultLocalName);
        if ($result === null) {
            $this->error = 'parse_error';
            dol_syslog('KsefGusClient::request: could not extract ' . $resultLocalName, LOG_ERR);
            return null;
        }

        return $result;
    }

    /**
     * @brief Build envelope
     * @param $actionUri WS-Addressing action URI
     * @param $bodyXml SOAP body inner XML
     * @return string Full envelope
     * @called_by request()
     */
    private function buildEnvelope($actionUri, $bodyXml)
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:soap="' . self::NS_SOAP . '" xmlns:ns="' . self::NS_PUBL . '" xmlns:dat="' . self::NS_DATA . '">'
            . '<soap:Header xmlns:wsa="' . self::NS_WSA . '">'
            . '<wsa:To>' . $this->endpoint . '</wsa:To>'
            . '<wsa:Action>' . $actionUri . '</wsa:Action>'
            . '</soap:Header>'
            . '<soap:Body>' . $bodyXml . '</soap:Body>'
            . '</soap:Envelope>';
    }

    /**
     * @brief Extracts the text
     * @param $soapXml Raw SOAP response body
     * @param $resultLocalName Local name of the element to read
     * @return string|null Element text, or null if absent
     * @called_by request()
     */
    private function extractResult($soapXml, $resultLocalName)
    {
        if (!is_string($soapXml) || $soapXml === '') {
            return null;
        }

        // Strip wrapper
        if (preg_match('/<(?:\w+:)?Envelope\b.*?<\/(?:\w+:)?Envelope>/s', $soapXml, $m)) {
            $soapXml = $m[0];
        }

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($soapXml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return null;
        }

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//*[local-name()="' . $resultLocalName . '"]');
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        return $nodes->item(0)->textContent;
    }

    /**
     * @brief Parses GUS report XML into rows
     * @param $innerXml Namespace-less <root><dane>... XML string
     * @return array List of assoc arrays
     * @called_by search(), fullReport()
     */
    private function parseDaneRows($innerXml)
    {
        $innerXml = preg_replace('/^\xEF\xBB\xBF/', '', trim($innerXml));
        if ($innerXml === '') {
            return array();
        }

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($innerXml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return array();
        }

        $rows = array();
        foreach ($doc->getElementsByTagName('dane') as $dane) {
            $fields = array();
            foreach ($dane->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE) {
                    $fields[$child->nodeName] = $this->sanitize($child->textContent);
                }
            }
            $rows[] = $fields;
        }

        return $rows;
    }

    /**
     * @brief Chooses the best dane row
     * @param $rows List of dane rows
     * @return array|null Selected row, or null if none usable
     * @called_by lookupByNip()
     */
    private function pickRow($rows)
    {
        if (empty($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (isset($row['SilosID']) && $row['SilosID'] === '1') {
                return $row;
            }
        }
        return $rows[0];
    }

    /**
     * @brief Tells whether a parsed row is error
     * @param $row Dane row
     * @return bool True if the row carries an ErrorCode
     * @called_by search(), fullReport()
     */
    private function rowIsError($row)
    {
        return isset($row['ErrorCode']) && $row['ErrorCode'] !== '';
    }

    /**
     * @brief Normalizes a legal person report
     * @param $r Report fields
     * @return array Normalized company data
     * @called_by lookupByNip()
     * @calls composeAddress(), isActive()
     */
    private function normalizeLegal($r)
    {
        $data = $this->normalizeCommon($r, 'praw_', 'P');

        $registerName = $this->val($r, 'praw_rodzajRejestruEwidencji_Nazwa');
        if (stripos($registerName, 'KRS') !== false || stripos($registerName, 'Krajowego Rejestru') !== false) {
            $data['krs'] = $this->val($r, 'praw_numerWRejestrzeEwidencji');
        }

        return $data;
    }

    /**
     * @brief Normalizes a sole proprietor report pair
     * @param $c CEIDG report fields (name, address, dates)
     * @param $o General data report fields (nip, status)
     * @return array Normalized company data
     * @called_by lookupByNip()
     * @calls normalizeCommon()
     */
    private function normalizeSoleProp($c, $o)
    {
        $data = $this->normalizeCommon($c, 'fiz_', 'F');

        // NIP and status come from the general data report, phone is not provided
        $data['nip'] = $this->val($o, 'fiz_nip');
        $data['status_nip'] = $this->val($o, 'fiz_statusNip');
        $data['phone'] = '';

        return $data;
    }

    /**
     * @brief Normalizes the fields shared by both report types
     * @param $r Report fields
     * @param $prefix Field prefix (praw_ or fiz_)
     * @param $typ Entity type (P or F)
     * @return array Normalized company data
     * @called_by normalizeLegal(), normalizeSoleProp()
     * @calls val(), formatZip(), composeAddress(), isActive()
     */
    private function normalizeCommon($r, $prefix, $typ)
    {
        return array(
            'name'       => $this->shortenOrgType($this->val($r, $prefix . 'nazwa')),
            'name_short' => $this->val($r, $prefix . 'nazwaSkrocona'),
            'nip'        => $this->val($r, $prefix . 'nip'),
            'regon'      => $this->val($r, $prefix . 'regon9'),
            'krs'        => '',
            'zip'        => $this->formatZip($this->val($r, $prefix . 'adSiedzKodPocztowy')),
            'town'       => $this->val($r, $prefix . 'adSiedzMiejscowosc_Nazwa'),
            'street'     => $this->val($r, $prefix . 'adSiedzUlica_Nazwa'),
            'building'   => $this->val($r, $prefix . 'adSiedzNumerNieruchomosci'),
            'apt'        => $this->val($r, $prefix . 'adSiedzNumerLokalu'),
            'address'    => $this->composeAddress($r, $prefix),
            'country'    => $this->val($r, $prefix . 'adSiedzKraj_Nazwa'),
            'phone'      => $this->val($r, $prefix . 'numerTelefonu'),
            'email'      => $this->val($r, $prefix . 'adresEmail'),
            'url'        => $this->val($r, $prefix . 'adresStronyinternetowej'),
            'status_nip' => $this->val($r, $prefix . 'statusNip'),
            'active'     => $this->isActive($r, $prefix),
            'typ'        => $typ,
        );
    }

    /**
     * @brief Replaces org types with acronyms, per KSEF_GUS_NAME_STYLE
     * @param $name Company name as returned by GUS (uppercase)
     * @return string Name
     * @called_by normalizeCommon()
     */
    private function shortenOrgType($name)
    {
        if (($this->nameStyle !== 'upper' && $this->nameStyle !== 'mixed') || $name === '') {
            return $name;
        }

        $map = array(
            'SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ' => 'Sp. z o.o.',
            'SPÓŁKA Z O. O.'                          => 'Sp. z o.o.',
            'SPÓŁKA Z O.O.'                           => 'Sp. z o.o.',
            'SP. Z O. O.'                             => 'Sp. z o.o.',
            'SP. Z O.O.'                              => 'Sp. z o.o.',
            'SP.Z O.O.'                               => 'Sp. z o.o.',
            'SPÓŁKA KOMANDYTOWO-AKCYJNA'              => 'S.K.A.',
            'SPÓŁKA KOMANDYTOWA'                      => 'Sp.k.',
            'SP. K.'                                  => 'Sp.k.',
            'SP.K.'                                   => 'Sp.k.',
            'PROSTA SPÓŁKA AKCYJNA'                   => 'P.S.A.',
            'SPÓŁKA AKCYJNA'                          => 'S.A.',
            'SPÓŁKA JAWNA'                            => 'Sp.j.',
            'SP. J.'                                  => 'Sp.j.',
            'SP.J.'                                   => 'Sp.j.',
            'SPÓŁKA PARTNERSKA'                       => 'Sp.p.',
            'SP. P.'                                  => 'Sp.p.',
            'SP.P.'                                   => 'Sp.p.',
            'SPÓŁKA CYWILNA'                          => 's.c.',
        );

        if ($this->nameStyle === 'upper') {
            $map = array_map(function ($v) {
                return mb_strtoupper($v, 'UTF-8');
            }, $map);
        }

        $name = str_replace(array_keys($map), array_values($map), $name);
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * @brief Builds a single-line street address from GUS address
     * @param $r Report fields
     * @param $prefix Field prefix (praw_ or fiz_)
     * @return string Composed address (street building/apt)
     * @called_by normalizeLegal(), normalizeSoleProp()
     */
    private function composeAddress($r, $prefix)
    {
        $street = $this->val($r, $prefix . 'adSiedzUlica_Nazwa');
        $building = $this->val($r, $prefix . 'adSiedzNumerNieruchomosci');
        $apt = $this->val($r, $prefix . 'adSiedzNumerLokalu');

        $number = $building;
        if ($apt !== '') {
            $number = ($building !== '') ? $building . '/' . $apt : $apt;
        }

        return trim($street . ' ' . $number);
    }

    /**
     * @brief Whether an entity is active
     * @param $r Report fields
     * @param $prefix Field prefix (praw_ or fiz_)
     * @return bool True if not suspended, closed or removed
     * @called_by normalizeLegal(), normalizeSoleProp()
     */
    private function isActive($r, $prefix)
    {
        $blockers = array(
            $prefix . 'dataZawieszeniaDzialalnosci',
            $prefix . 'dataZakonczeniaDzialalnosci',
            $prefix . 'dataSkresleniaZRegon',
            $prefix . 'dataSkresleniaDzialalnosciZRegon',
            $prefix . 'dataOrzeczeniaOUpadlosci',
            $prefix . 'dataZakonczeniaPostepowaniaUpadlosciowego',
        );
        foreach ($blockers as $field) {
            if ($this->val($r, $field) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @brief Reads a trimmed field value
     * @param $r Report fields
     * @param $key Field name
     * @return string Value or empty string
     * @called_by normalizeLegal(), normalizeSoleProp(), composeAddress(), isActive()
     */
    private function val($r, $key)
    {
        return isset($r[$key]) ? trim($r[$key]) : '';
    }

    /**
     * @brief Strips control characters
     * @param $value Raw string (GUS warns values may carry HTML/SQL payloads)
     * @return string Sanitized, trimmed value
     * @called_by parseDaneRows()
     */
    private function sanitize($value)
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
        return trim($value);
    }

    /**
     * @brief Formats postal code
     * @param $zip Raw postal code (GUS full reports omit the dash)
     * @return string Formatted code, or the input unchanged if not 5 digits
     * @called_by normalizeLegal(), normalizeSoleProp()
     */
    private function formatZip($zip)
    {
        if (preg_match('/^\d{5}$/', $zip)) {
            return substr($zip, 0, 2) . '-' . substr($zip, 2);
        }
        return $zip;
    }

    /**
     * @brief Escapes a value
     * @param $value Raw value
     * @return string XML safe value (dol_escape_xml is a no-op, so escape here)
     * @called_by login(), search(), fullReport(), logout()
     */
    private function escapeXml($value)
    {
        return ksefXmlEscape($value);
    }
}
