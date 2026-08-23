<?php
/* Copyright (C) 2026	Joliciel	<contact@joliciel.fr>
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
 */

/**
 *	\file		dolisecure/class/dolisecurechecker.class.php
 *	\ingroup	dolisecure
 *	\brief		Class to check the installed Dolibarr version against public CVE databases
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

/**
 * Checks whether the currently installed Dolibarr version is affected by a known
 * vulnerability (CVE), using the NVD (National Vulnerability Database) public API.
 * Results are cached into llx_const so the home page indicator never triggers a
 * network call by itself; refresh is done on demand (setup page) or by the module cron job.
 */
class DoliSecureChecker
{
	/**
	 * @var string NVD REST API endpoint (CVE 2.0)
	 */
	const NVD_API_URL = 'https://services.nvd.nist.gov/rest/json/cves/2.0';

	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @var string Last error message
	 */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Return the installed Dolibarr version (X.Y.Z, without dev/alpha suffix)
	 *
	 * @return string
	 */
	public function getInstalledVersion()
	{
		return preg_replace('/[^0-9.].*$/', '', DOL_VERSION);
	}

	/**
	 * Delay (in seconds) during which a previous result is reused instead of calling the API again
	 *
	 * @return int
	 */
	public function getCacheDelay()
	{
		return getDolGlobalInt('DOLISECURE_CACHE_DELAY_HOURS', 24) * 3600;
	}

	/**
	 * Is the cached result missing or older than the configured cache delay?
	 *
	 * @return bool
	 */
	public function isCacheStale()
	{
		$lastcheck = getDolGlobalInt('DOLISECURE_LAST_CHECK_DATE', 0);
		if (empty($lastcheck)) {
			return true;
		}
		return ((dol_now() - $lastcheck) > $this->getCacheDelay());
	}

	/**
	 * Read the last check result from cache (llx_const), without calling any external API
	 *
	 * @return array{status:string,date:int,version:string,error:string,cves:array<int,array<string,mixed>>}
	 */
	public function getLastResult()
	{
		$cves = array();
		$json = getDolGlobalString('DOLISECURE_LAST_CHECK_RESULT', '');
		if ($json) {
			$decoded = json_decode($json, true);
			if (is_array($decoded)) {
				$cves = $decoded;
			}
		}

		return array(
			'status'  => getDolGlobalString('DOLISECURE_LAST_CHECK_STATUS', ''),	// '', 'ok', 'vulnerable' or 'error'
			'date'    => getDolGlobalInt('DOLISECURE_LAST_CHECK_DATE', 0),
			'version' => getDolGlobalString('DOLISECURE_LAST_CHECK_VERSION', ''),
			'error'   => getDolGlobalString('DOLISECURE_LAST_CHECK_ERROR', ''),
			'cves'    => $cves,
		);
	}

	/**
	 * Check the installed version against the NVD database (uses cache unless $forcerefresh is set)
	 *
	 * @param	bool	$forcerefresh	Ignore cache and call the API now
	 * @return	array{status:string,date:int,version:string,error:string,cves:array<int,array<string,mixed>>}
	 */
	public function check($forcerefresh = false)
	{
		if (!$forcerefresh && !$this->isCacheStale()) {
			return $this->getLastResult();
		}

		$version = $this->getInstalledVersion();

		// We use a broad keyword search (not a CPE virtualMatchString search): NVD's CPE dictionary for
		// Dolibarr lags behind (recently published CVEs commonly have no CPE configuration data for weeks),
		// so a CPE-only search would silently miss current vulnerabilities. Applicability is instead decided
		// per-CVE below, using CPE ranges when available and falling back to the description text otherwise.
		$url = self::NVD_API_URL.'?keywordSearch=dolibarr&resultsPerPage=2000';

		$resp = getURLContent($url, 'GET', '', 1, array('Accept: application/json'), array('https'), 0, 1, 10, 30);

		if (!empty($resp['curl_error_no']) || empty($resp['content']) || (int) $resp['http_code'] >= 400) {
			$errormsg = !empty($resp['curl_error_msg']) ? $resp['curl_error_msg'] : ('HTTP '.$resp['http_code']);
			$this->error = $errormsg;
			$this->saveResult('error', $version, array(), $errormsg);
			return $this->getLastResult();
		}

		$data = json_decode($resp['content'], true);
		if (!is_array($data)) {
			$this->error = 'Invalid JSON response from NVD';
			$this->saveResult('error', $version, array(), $this->error);
			return $this->getLastResult();
		}

		$cves = array();
		if (!empty($data['vulnerabilities']) && is_array($data['vulnerabilities'])) {
			foreach ($data['vulnerabilities'] as $vuln) {
				$cve = isset($vuln['cve']) ? $vuln['cve'] : array();
				$id = isset($cve['id']) ? $cve['id'] : '';
				if (empty($id)) {
					continue;
				}

				$description = '';
				if (!empty($cve['descriptions']) && is_array($cve['descriptions'])) {
					foreach ($cve['descriptions'] as $desc) {
						if (isset($desc['lang']) && $desc['lang'] === 'en') {
							$description = $desc['value'];
							break;
						}
					}
				}

				// Decide whether this CVE actually applies to the installed version: prefer structured
				// CPE configuration data, fall back to parsing the version range out of the description.
				$cpematch = $this->cpeConfigurationMatches($cve, $version);
				if ($cpematch !== true && !$this->descriptionIndicatesAffected($description, $version)) {
					continue;
				}

				$score = null;
				$severity = '';
				if (!empty($cve['metrics']) && is_array($cve['metrics'])) {
					foreach (array('cvssMetricV31', 'cvssMetricV30', 'cvssMetricV2') as $metrickey) {
						if (!empty($cve['metrics'][$metrickey][0]['cvssData'])) {
							$cvssdata = $cve['metrics'][$metrickey][0]['cvssData'];
							$score = isset($cvssdata['baseScore']) ? $cvssdata['baseScore'] : null;
							$severity = isset($cvssdata['baseSeverity']) ? $cvssdata['baseSeverity'] : (isset($cve['metrics'][$metrickey][0]['baseSeverity']) ? $cve['metrics'][$metrickey][0]['baseSeverity'] : '');
							break;
						}
					}
				}

				$cves[] = array(
					'id'           => $id,
					'description'  => $description,
					'score'        => $score,
					'severity'     => $severity,
					'published'    => isset($cve['published']) ? $cve['published'] : '',
					'url'          => 'https://nvd.nist.gov/vuln/detail/'.$id,
					'fixedversion' => $this->extractFixedVersion($cve, $description),
				);
			}
		}

		$status = count($cves) > 0 ? 'vulnerable' : 'ok';
		$this->saveResult($status, $version, $cves, '');

		global $conf;
		if ($status === 'vulnerable' && getDolGlobalInt('DOLISECURE_SEND_ADMIN_ALERT', 1)) {
			$this->notifyAdminIfNeeded($cves, $version);
		} elseif ($status === 'ok') {
			// Vulnerabilities are gone (fixed or version changed): forget what we notified so a future
			// re-detection triggers a fresh alert instead of staying silent forever.
			dolibarr_set_const($this->db, 'DOLISECURE_NOTIFIED_CVES', '[]', 'chaine', 0, '', $conf->entity);
			$conf->global->DOLISECURE_NOTIFIED_CVES = '[]';
		}

		return $this->getLastResult();
	}

	/**
	 * Send an alert email to the administrator(s) when new CVEs (not already notified about) are found.
	 * Keeps track of which CVE ids were already notified (in DOLISECURE_NOTIFIED_CVES) so the same set of
	 * vulnerabilities does not trigger a new email on every check while nothing has changed.
	 *
	 * @param	array<int,array<string,mixed>>	$cves		Currently detected CVEs
	 * @param	string							$version	Installed Dolibarr version
	 * @return	void
	 */
	protected function notifyAdminIfNeeded(array $cves, $version)
	{
		global $conf;

		$newids = array();
		foreach ($cves as $cve) {
			$newids[] = $cve['id'];
		}

		$notified = json_decode(getDolGlobalString('DOLISECURE_NOTIFIED_CVES', '[]'), true);
		if (!is_array($notified)) {
			$notified = array();
		}

		if (count(array_diff($newids, $notified)) === 0) {
			return; // Nothing new since the last alert
		}

		$this->sendAdminAlertMail($cves, $version);

		dolibarr_set_const($this->db, 'DOLISECURE_NOTIFIED_CVES', json_encode($newids), 'chaine', 0, '', $conf->entity);
		$conf->global->DOLISECURE_NOTIFIED_CVES = json_encode($newids);
	}

	/**
	 * Return the list of email addresses to alert: DOLISECURE_ALERT_EMAIL if set (comma-separated override),
	 * otherwise every active user flagged as administrator.
	 *
	 * @return string[]
	 */
	protected function getAdminEmails()
	{
		$override = getDolGlobalString('DOLISECURE_ALERT_EMAIL');
		if ($override) {
			return array_values(array_filter(array_map('trim', explode(',', $override))));
		}

		$emails = array();
		$sql = "SELECT email FROM ".$this->db->prefix()."user WHERE admin = 1 AND statut = 1 AND email IS NOT NULL AND email <> ''";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$emails[] = $obj->email;
			}
		}
		return $emails;
	}

	/**
	 * Build and send the alert email listing the currently detected CVEs
	 *
	 * @param	array<int,array<string,mixed>>	$cves		Currently detected CVEs
	 * @param	string							$version	Installed Dolibarr version
	 * @return	void
	 */
	protected function sendAdminAlertMail(array $cves, $version)
	{
		global $langs;

		$emails = $this->getAdminEmails();
		if (empty($emails)) {
			return;
		}

		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';

		$langs->load('dolisecure@dolisecure');

		$subject = $langs->transnoentities('DoliSecureAlertMailSubject', $version, count($cves));

		$body = $langs->transnoentities('DoliSecureAlertMailIntro', $version)."\n\n";
		foreach ($cves as $cve) {
			$scoretext = trim($cve['severity'].' '.($cve['score'] !== null ? '('.$cve['score'].')' : ''));
			$body .= '- '.$cve['id'].($scoretext ? ' ['.$scoretext.']' : '')."\n  ".$cve['url']."\n";

			// For high-severity CVEs, tell the admin which version to upgrade to when we actually know it
			// (an exclusive upper bound: "before/prior to X" or CPE versionEndExcluding). We never guess it.
			if (in_array(strtoupper((string) $cve['severity']), array('HIGH', 'CRITICAL'), true)) {
				if (!empty($cve['fixedversion'])) {
					$body .= '  '.$langs->transnoentities('DoliSecureFixedInVersion', $cve['fixedversion'])."\n";
				} else {
					$body .= '  '.$langs->transnoentities('DoliSecureFixedVersionUnknown')."\n";
				}
			}
		}
		$body .= "\n".$langs->transnoentities('DoliSecureAlertMailOutro');

		$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM', getDolGlobalString('MAIN_INFO_SOCIETE_MAIL', 'noreply@localhost'));

		foreach ($emails as $email) {
			$mail = new CMailFile($subject, $email, $from, $body, array(), array(), array(), '', '', 0, 0);
			if ($mail->error) {
				$this->error = $mail->error;
				continue;
			}
			$mail->sendfile();
		}
	}

	/**
	 * Check the CPE configuration data of a CVE (when NVD has already analyzed it) against the installed version
	 *
	 * @param	array<string,mixed>	$cve				Decoded 'cve' object from the NVD API
	 * @param	string					$installedVersion	Installed Dolibarr version (X.Y.Z)
	 * @return	?bool										true=affected, false=has data but does not match, null=no usable data
	 */
	protected function cpeConfigurationMatches(array $cve, $installedVersion)
	{
		if (empty($cve['configurations']) || !is_array($cve['configurations'])) {
			return null;
		}

		$founddolibarrcpe = false;

		foreach ($cve['configurations'] as $config) {
			foreach ((isset($config['nodes']) && is_array($config['nodes'])) ? $config['nodes'] : array() as $node) {
				foreach ((isset($node['cpeMatch']) && is_array($node['cpeMatch'])) ? $node['cpeMatch'] : array() as $match) {
					$criteria = isset($match['criteria']) ? $match['criteria'] : '';
					// NVD escapes the '/' inside the CPE product name with a literal backslash (CPE 2.3 formatted
					// string escaping, not JSON syntax): the decoded value is really "dolibarr_erp\/crm".
					if (!preg_match('#^cpe:2\.3:a:dolibarr:(dolibarr|dolibarr_erp\\\\?/crm):#i', $criteria)) {
						continue; // Not a Dolibarr product entry
					}
					if (isset($match['vulnerable']) && !$match['vulnerable']) {
						continue;
					}

					$founddolibarrcpe = true;

					$parts = explode(':', $criteria);
					$cpeversion = isset($parts[5]) ? $parts[5] : '*';

					$start = isset($match['versionStartIncluding']) ? array($match['versionStartIncluding'], '>=') : (isset($match['versionStartExcluding']) ? array($match['versionStartExcluding'], '>') : null);
					$end = isset($match['versionEndIncluding']) ? array($match['versionEndIncluding'], '<=') : (isset($match['versionEndExcluding']) ? array($match['versionEndExcluding'], '<') : null);

					if ($start === null && $end === null) {
						// No range: the CPE string itself pins an exact version (or '*'/'-' meaning "any"/"n/a")
						if ($cpeversion !== '*' && $cpeversion !== '-') {
							if (version_compare($installedVersion, $cpeversion, '==')) {
								return true;
							}
						}
						continue;
					}

					$match_ok = true;
					if ($start !== null) {
						$match_ok = $match_ok && version_compare($installedVersion, $start[0], $start[1]);
					}
					if ($end !== null) {
						$match_ok = $match_ok && version_compare($installedVersion, $end[0], $end[1]);
					}
					if ($match_ok) {
						return true;
					}
				}
			}
		}

		return $founddolibarrcpe ? false : null;
	}

	/**
	 * Fallback used when a CVE has no (or inconclusive) CPE configuration data yet: parses common version-range
	 * phrasings out of the English description (e.g. "up to X.Y.Z", "prior to X.Y.Z", "X.Y.Z and prior",
	 * "vA.B.C through vX.Y.Z"). Recently published CVEs are frequently in this situation for weeks before NVD
	 * assigns CPE data, so relying on CPE alone would under-report real, current vulnerabilities.
	 *
	 * @param	string	$description		English CVE description
	 * @param	string	$installedVersion	Installed Dolibarr version (X.Y.Z)
	 * @return	bool
	 */
	protected function descriptionIndicatesAffected($description, $installedVersion)
	{
		if (empty($description) || stripos($description, 'dolibarr') === false) {
			return false;
		}

		$vernum = '([0-9]+(?:\.[0-9]+){1,3})';

		// Explicit range "vA.B.C through vX.Y.Z" (inclusive-inclusive)
		if (preg_match_all('/v\.?\s*'.$vernum.'\s+through\s+v\.?\s*'.$vernum.'/i', $description, $m, PREG_SET_ORDER)) {
			foreach ($m as $mm) {
				if (version_compare($installedVersion, $mm[1], '>=') && version_compare($installedVersion, $mm[2], '<=')) {
					return true;
				}
			}
		}

		// Upper bound inclusive: "up to X", "through X"
		if (preg_match_all('/\b(?:up to|through)\s+v?\.?\s*'.$vernum.'/i', $description, $m)) {
			foreach ($m[1] as $ver) {
				if (version_compare($installedVersion, $ver, '<=')) {
					return true;
				}
			}
		}

		// Upper bound inclusive: "X and prior/earlier/below"
		if (preg_match_all('/\b'.$vernum.'\s+and\s+(?:prior|earlier|below)\b/i', $description, $m)) {
			foreach ($m[1] as $ver) {
				if (version_compare($installedVersion, $ver, '<=')) {
					return true;
				}
			}
		}

		// Upper bound exclusive: "prior to X", "before X"
		if (preg_match_all('/\b(?:prior to|before)\s+v?\.?\s*'.$vernum.'/i', $description, $m)) {
			foreach ($m[1] as $ver) {
				if (version_compare($installedVersion, $ver, '<')) {
					return true;
				}
			}
		}

		// Explicit slash-separated list of versions, e.g. "23.0.0/23.0.1/23.0.2"
		if (preg_match('/\b([0-9]+\.[0-9]+\.[0-9]+(?:\/[0-9]+\.[0-9]+\.[0-9]+)+)\b/', $description, $m)) {
			foreach (explode('/', $m[1]) as $ver) {
				if (version_compare($installedVersion, $ver, '==')) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Try to determine the first Dolibarr version that fixes a CVE. Only ever returns an EXCLUSIVE upper
	 * bound (a version strictly above it is required): "versionEndExcluding" in CPE data, or "prior to X" /
	 * "before X" in the description, both literally mean "X is the first safe version". An INCLUSIVE bound
	 * ("up to X", "X and prior", "versionEndIncluding") only tells us X is still vulnerable, not which later
	 * version actually contains the fix, so those intentionally return null rather than guessing a number.
	 *
	 * @param	array<string,mixed>	$cve			Decoded 'cve' object from the NVD API
	 * @param	string					$description	English CVE description
	 * @return	?string									Fixed version (X.Y.Z), or null if unknown
	 */
	protected function extractFixedVersion(array $cve, $description)
	{
		if (!empty($cve['configurations']) && is_array($cve['configurations'])) {
			foreach ($cve['configurations'] as $config) {
				foreach ((isset($config['nodes']) && is_array($config['nodes'])) ? $config['nodes'] : array() as $node) {
					foreach ((isset($node['cpeMatch']) && is_array($node['cpeMatch'])) ? $node['cpeMatch'] : array() as $match) {
						$criteria = isset($match['criteria']) ? $match['criteria'] : '';
						if (!preg_match('#^cpe:2\.3:a:dolibarr:(dolibarr|dolibarr_erp\\\\?/crm):#i', $criteria)) {
							continue;
						}
						if (!empty($match['versionEndExcluding'])) {
							return $match['versionEndExcluding'];
						}
					}
				}
			}
		}

		if (!empty($description)) {
			$vernum = '([0-9]+(?:\.[0-9]+){1,3})';
			if (preg_match('/\b(?:prior to|before)\s+v?\.?\s*'.$vernum.'/i', $description, $m)) {
				return $m[1];
			}
		}

		return null;
	}

	/**
	 * Rank a CVSS base severity string for comparison (higher = more dangerous)
	 *
	 * @param	string	$severity
	 * @return	int
	 */
	protected static function severityRank($severity)
	{
		switch (strtoupper((string) $severity)) {
			case 'CRITICAL':
				return 4;
			case 'HIGH':
				return 3;
			case 'MEDIUM':
				return 2;
			case 'LOW':
				return 1;
			default:
				return 0;
		}
	}

	/**
	 * Return the most dangerous severity found among a list of CVEs (CRITICAL > HIGH > MEDIUM > LOW > unknown)
	 *
	 * @param	array<int,array<string,mixed>>	$cves
	 * @return	string	'CRITICAL', 'HIGH', 'MEDIUM', 'LOW', or '' if none has a known severity
	 */
	public static function getMaxSeverity(array $cves)
	{
		$max = '';
		$maxrank = -1;
		foreach ($cves as $cve) {
			$severity = isset($cve['severity']) ? $cve['severity'] : '';
			$rank = self::severityRank($severity);
			if ($rank > $maxrank) {
				$maxrank = $rank;
				$max = strtoupper((string) $severity);
			}
		}
		return $max;
	}

	/**
	 * Background/border colors representing a severity level, from mild (LOW) to critical (CRITICAL).
	 * Reuses Dolibarr's own semantic colors (info/warning/error) so the shading matches the rest of the UI,
	 * plus a distinct darker red for CRITICAL since Dolibarr has no built-in class beyond "error".
	 *
	 * @param	string	$severity
	 * @return	array{bg:string,border:string}
	 */
	public static function severityColors($severity)
	{
		switch (strtoupper((string) $severity)) {
			case 'CRITICAL':
				return array('bg' => '#f5c2c7', 'border' => '#dc3545');
			case 'HIGH':
				return array('bg' => '#EFCFCF', 'border' => '#f28787'); // Dolibarr "error" colors
			case 'MEDIUM':
				return array('bg' => '#fcf8e3', 'border' => '#f2cf87'); // Dolibarr "warning" colors
			case 'LOW':
				return array('bg' => '#eff8fc', 'border' => '#87cfd2'); // Dolibarr "info" colors
			default:
				return array('bg' => '#f8f8f8', 'border' => '#aaa');    // Dolibarr "neutral" colors
		}
	}

	/**
	 * Persist a check result into llx_const so it can be read again without calling the API
	 *
	 * @param	string					$status		'ok', 'vulnerable' or 'error'
	 * @param	string					$version	Installed version that was checked
	 * @param	array<int,mixed>		$cves		List of found CVEs
	 * @param	string					$errormsg	Error message if status is 'error'
	 * @return	void
	 */
	protected function saveResult($status, $version, array $cves, $errormsg)
	{
		global $conf;

		$now = dol_now();

		dolibarr_set_const($this->db, 'DOLISECURE_LAST_CHECK_STATUS', $status, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'DOLISECURE_LAST_CHECK_DATE', $now, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'DOLISECURE_LAST_CHECK_VERSION', $version, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'DOLISECURE_LAST_CHECK_ERROR', $errormsg, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($this->db, 'DOLISECURE_LAST_CHECK_RESULT', json_encode($cves), 'chaine', 0, '', $conf->entity);

		// Keep the running request's global conf in sync so a manual "check now" reflects immediately
		$conf->global->DOLISECURE_LAST_CHECK_STATUS = $status;
		$conf->global->DOLISECURE_LAST_CHECK_DATE = $now;
		$conf->global->DOLISECURE_LAST_CHECK_VERSION = $version;
		$conf->global->DOLISECURE_LAST_CHECK_ERROR = $errormsg;
		$conf->global->DOLISECURE_LAST_CHECK_RESULT = json_encode($cves);
	}

	/**
	 * Entry point used by the module cron job to refresh the cache in background
	 *
	 * @param	int<0,1>	$forcerefresh	Force refresh ignoring the cache delay
	 * @return	int<-1,1>					1 if OK, -1 if KO
	 */
	public function doScheduledJob($forcerefresh = 0)
	{
		$result = $this->check((bool) $forcerefresh);
		if ($result['status'] === 'error') {
			$this->error = $result['error'];
			return -1;
		}
		return 1;
	}
}
