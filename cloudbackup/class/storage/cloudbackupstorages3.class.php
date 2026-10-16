<?php
/* Copyright (C) 2026		Pierre Grasswill		<da.grumpf@gmail.com>
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
 * \file       cloudbackup/class/storage/cloudbackupstorages3.class.php
 * \ingroup    cloudbackup
 * \brief      Storage in a S3 compatible bucket (AWS, Scaleway, OVH, Wasabi, Backblaze B2, MinIO...)
 */

require_once __DIR__.'/cloudbackupstorage.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';


/**
 * S3 storage, signed with AWS Signature V4, over the HTTP client of Dolibarr
 */
class CloudBackupStorageS3 extends CloudBackupStorage
{
	/** Attempts of a request, 1 + 2 + 4 + 8 seconds apart */
	const ATTEMPTS = 5;

	/** @var string Scheme and host, like https://s3.fr-par.scw.cloud */
	private $endpoint;
	/** @var string Region, like fr-par */
	private $region;
	/** @var string Bucket */
	private $bucket;
	/** @var string Access key */
	private $accessKey;
	/** @var string Secret key */
	private $secretKey;
	/** @var int 1 = https://endpoint/bucket/key, 0 = https://bucket.endpoint/key */
	private $pathStyle;
	/** @var int 1 = the endpoint may be on a private network */
	private $allowLocal;

	/**
	 * Constructor
	 *
	 * @param	string	$endpoint	Endpoint URL
	 * @param	string	$region		Region
	 * @param	string	$bucket		Bucket
	 * @param	string	$accessKey	Access key
	 * @param	string	$secretKey	Secret key
	 * @param	string	$root		Key prefix
	 * @param	int		$pathStyle	1 for path style URLs
	 * @param	int		$allowLocal	1 to allow an endpoint on a private network
	 */
	public function __construct($endpoint, $region, $bucket, $accessKey, $secretKey, $root = '', $pathStyle = 1, $allowLocal = 0)
	{
		$endpoint = rtrim(trim($endpoint), '/');
		if ($endpoint !== '' && !preg_match('#^https?://#i', $endpoint)) {
			$endpoint = 'https://'.$endpoint;
		}
		$this->endpoint = $endpoint;
		$this->region = trim($region) !== '' ? trim($region) : 'us-east-1';
		$this->bucket = trim($bucket);
		$this->accessKey = trim($accessKey);
		$this->secretKey = trim($secretKey);
		$this->root = trim($root, '/');
		$this->pathStyle = $pathStyle;
		$this->allowLocal = $allowLocal;
	}

	/**
	 * Name of the PHP extension the driver needs and that is missing
	 *
	 * @return string
	 */
	public function missingExtension()
	{
		return function_exists('curl_init') ? '' : 'curl';
	}

	/**
	 * Check that the storage is reachable and writable
	 *
	 * @return bool
	 */
	public function test()
	{
		if ($this->endpoint === '' || $this->bucket === '' || $this->accessKey === '' || $this->secretKey === '') {
			$this->error = 'Endpoint, bucket, access key and secret key are required';
			return false;
		}
		return parent::test();
	}

	/**
	 * Write an object
	 *
	 * @param	string	$name	Object name
	 * @param	string	$data	Content
	 * @return	bool
	 */
	public function put($name, $data)
	{
		$res = $this->request('PUT', $this->fullName($name), array(), $data);
		return $this->isSuccess($res, 'PUT '.$name);
	}

	/**
	 * Read an object
	 *
	 * @param	string	$name	Object name
	 * @return	string|false
	 */
	public function get($name)
	{
		$res = $this->request('GET', $this->fullName($name));
		return $this->isSuccess($res, 'GET '.$name) ? $res['content'] : false;
	}

	/**
	 * Read a part of an object
	 *
	 * @param	string	$name	Object name
	 * @param	int		$offset	Offset
	 * @param	int		$length	Length
	 * @return	string|false
	 */
	public function getRange($name, $offset, $length)
	{
		$res = $this->request('GET', $this->fullName($name), array(), '', array('Range: bytes='.((int) $offset).'-'.((int) $offset + (int) $length - 1)));
		if (!$this->isSuccess($res, 'GET '.$name)) {
			return false;
		}
		$data = $res['content'];
		if ($res['http_code'] == 200 && strlen($data) > $length) {
			// A server ignoring the Range header sends the whole object
			$data = substr($data, $offset, $length);
		}
		return strlen($data) == $length ? $data : false;
	}

	/**
	 * Delete an object
	 *
	 * @param	string	$name	Object name
	 * @return	bool
	 */
	public function delete($name)
	{
		$res = $this->request('DELETE', $this->fullName($name));
		return $res['http_code'] == 404 || $this->isSuccess($res, 'DELETE '.$name);
	}

	/**
	 * List objects under a prefix
	 *
	 * @param	string	$dir	Directory
	 * @return	array<string,int>|false
	 */
	public function listFiles($dir)
	{
		$prefix = $this->fullName(trim($dir, '/')).'/';
		if ($prefix === '/') {
			$prefix = '';
		}
		$result = array();
		$token = '';
		do {
			$query = array('list-type' => '2', 'prefix' => $prefix, 'max-keys' => '1000');
			if ($token !== '') {
				$query['continuation-token'] = $token;
			}
			$res = $this->request('GET', '', $query);
			if (!$this->isSuccess($res, 'LIST '.$prefix)) {
				return false;
			}
			$xml = @simplexml_load_string($res['content']);
			if ($xml === false) {
				$this->error = 'Unreadable answer to LIST '.$prefix;
				return false;
			}
			foreach ($xml->Contents as $item) {
				$result[$this->relativeName((string) $item->Key)] = (int) $item->Size;
			}
			$token = ((string) $xml->IsTruncated === 'true') ? (string) $xml->NextContinuationToken : '';
		} while ($token !== '');
		return $result;
	}

	/**
	 * Tell whether an answer is a success, else record the error
	 *
	 * @param	array<string,mixed>	$res		Result of getURLContent()
	 * @param	string				$what		Operation, for the message
	 * @return	bool
	 */
	private function isSuccess($res, $what)
	{
		$code = (int) $res['http_code'];
		if ($code >= 200 && $code < 300) {
			return true;
		}
		$detail = '';
		if (!empty($res['curl_error_msg'])) {
			$detail = $res['curl_error_msg'];
		} elseif (!empty($res['content']) && preg_match('#<Code>(.*?)</Code>#', $res['content'], $m)) {
			$detail = $m[1];
			if (preg_match('#<Message>(.*?)</Message>#', $res['content'], $m2)) {
				$detail .= ': '.$m2[1];
			}
		}
		$this->error = $what.' failed with HTTP '.$code.($detail !== '' ? ' - '.$detail : '');
		return false;
	}

	/**
	 * Send a signed request
	 *
	 * @param	string					$method		GET, PUT or DELETE
	 * @param	string					$key		Object key, '' for the bucket
	 * @param	array<string,string>	$query		Query parameters
	 * @param	string					$body		Body
	 * @param	string[]				$extra		Additional headers, not signed
	 * @return	array<string,int|string>	Result of getURLContent()
	 */
	private function request($method, $key, $query = array(), $body = '', $extra = array())
	{
		global $conf;

		$parts = parse_url($this->endpoint);
		$host = isset($parts['host']) ? $parts['host'] : '';
		if (!$this->pathStyle) {
			$host = $this->bucket.'.'.$host;
		}
		if (!empty($parts['port'])) {
			$host .= ':'.$parts['port'];
		}
		$scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
		$path = ($this->pathStyle ? '/'.$this->bucket : '').'/'.implode('/', array_map('rawurlencode', explode('/', $key)));
		if ($key === '') {
			$path = $this->pathStyle ? '/'.$this->bucket.'/' : '/';
		}

		ksort($query);
		$canonicalQuery = array();
		foreach ($query as $k => $v) {
			$canonicalQuery[] = rawurlencode($k).'='.rawurlencode($v);
		}
		$canonicalQuery = implode('&', $canonicalQuery);

		$amzDate = gmdate('Ymd\THis\Z');
		$day = substr($amzDate, 0, 8);
		$payloadHash = hash('sha256', $body);
		$canonicalRequest = $method."\n".$path."\n".$canonicalQuery."\nhost:".$host
			."\nx-amz-content-sha256:".$payloadHash."\nx-amz-date:".$amzDate
			."\n\nhost;x-amz-content-sha256;x-amz-date\n".$payloadHash;
		$scope = $day.'/'.$this->region.'/s3/aws4_request';
		$stringToSign = "AWS4-HMAC-SHA256\n".$amzDate."\n".$scope."\n".hash('sha256', $canonicalRequest);
		$signingKey = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $this->region, hash_hmac('sha256', $day, 'AWS4'.$this->secretKey, true), true), true), true);
		$signature = hash_hmac('sha256', $stringToSign, $signingKey);

		$headers = array_merge(array(
			'Authorization: AWS4-HMAC-SHA256 Credential='.$this->accessKey.'/'.$scope.', SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature='.$signature,
			'x-amz-content-sha256: '.$payloadHash,
			'x-amz-date: '.$amzDate,
		), $extra);
		if ($method == 'PUT') {
			$headers[] = 'Content-Type: application/octet-stream';
		}

		$url = $scheme.'://'.$host.$path.($canonicalQuery !== '' ? '?'.$canonicalQuery : '');
		$methodForDolibarr = ($method == 'PUT') ? 'PUTALREADYFORMATED' : $method;

		// Before Dolibarr 22 the response timeout is only a global setting: raise it for the call
		$savedTimeout = isset($conf->global->MAIN_USE_RESPONSE_TIMEOUT) ? $conf->global->MAIN_USE_RESPONSE_TIMEOUT : null;
		$conf->global->MAIN_USE_RESPONSE_TIMEOUT = 900;
		// A network cut, a 5xx or a 429 is often transient (a mobile link, a busy provider): try again, waiting more each time
		for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
			$res = getURLContent($url, $methodForDolibarr, $body, 0, $headers, array('https', 'http'), $this->allowLocal ? 2 : 0, -1, 20, 900);
			$code = isset($res['http_code']) ? (int) $res['http_code'] : 0;
			if ($attempt == self::ATTEMPTS || ($code != 0 && $code != 408 && $code != 429 && $code < 500)) {
				break;
			}
			dol_syslog('CloudBackup S3: '.$method.' '.$key.' got HTTP '.$code.', attempt '.$attempt.' of '.self::ATTEMPTS, LOG_WARNING);
			sleep(1 << ($attempt - 1));
		}
		if ($savedTimeout === null) {
			unset($conf->global->MAIN_USE_RESPONSE_TIMEOUT);
		} else {
			$conf->global->MAIN_USE_RESPONSE_TIMEOUT = $savedTimeout;
		}
		if (!isset($res['content'])) {
			$res['content'] = '';
		}
		return $res;
	}
}
