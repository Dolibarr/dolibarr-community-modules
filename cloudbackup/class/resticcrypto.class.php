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
 * \file       cloudbackup/class/resticcrypto.class.php
 * \ingroup    cloudbackup
 * \brief      Cryptography of the restic repository format, in pure PHP (openssl)
 */


/**
 * Encryption primitives of a restic repository: AES-256-CTR with a Poly1305-AES MAC, and the scrypt KDF.
 *
 * A restic key is an array with 'encrypt' (32 bytes), 'k' (16 bytes, AES key of the MAC) and 'r' (16 bytes).
 * An encrypted object is IV (16 bytes) . ciphertext . MAC (16 bytes), the MAC covering the ciphertext only.
 * See https://restic.readthedocs.io/en/stable/100_references.html#design
 */
class CloudBackupResticCrypto
{
	const IV_SIZE = 16;
	const MAC_SIZE = 16;
	const EXTENSION = 32;

	/**
	 * Tell whether the PHP runtime can handle a restic repository
	 *
	 * @return string	Empty string if OK, else the name of what is missing
	 */
	public static function checkRuntime()
	{
		if (PHP_INT_SIZE < 8) {
			return '64-bit PHP';
		}
		if (!function_exists('openssl_encrypt') || !in_array('aes-256-ctr', array_map('strtolower', openssl_get_cipher_methods()))) {
			return 'openssl (aes-256-ctr)';
		}
		return '';
	}

	/**
	 * Generate a new random master key
	 *
	 * @return array{encrypt:string,k:string,r:string}
	 */
	public static function newRandomKey()
	{
		$r = random_bytes(16);
		// Same clamping as restic does on a new key (the poly1305 r value)
		foreach (array(3, 7, 11, 15) as $i) {
			$r[$i] = chr(ord($r[$i]) & 15);
		}
		foreach (array(4, 8, 12) as $i) {
			$r[$i] = chr(ord($r[$i]) & 252);
		}
		return array('encrypt' => random_bytes(32), 'k' => random_bytes(16), 'r' => $r);
	}

	/**
	 * Encrypt a plaintext
	 *
	 * @param	array{encrypt:string,k:string,r:string}	$key		Key
	 * @param	string									$plaintext	Plaintext
	 * @return	string											IV . ciphertext . MAC
	 */
	public static function encrypt($key, $plaintext)
	{
		$iv = random_bytes(self::IV_SIZE);
		$ciphertext = (string) openssl_encrypt($plaintext, 'aes-256-ctr', $key['encrypt'], OPENSSL_RAW_DATA, $iv);
		return $iv.$ciphertext.self::mac($ciphertext, $iv, $key);
	}

	/**
	 * Decrypt a ciphertext after having checked its MAC
	 *
	 * @param	array{encrypt:string,k:string,r:string}	$key		Key
	 * @param	string									$data		IV . ciphertext . MAC
	 * @return	string|false									Plaintext, or false if the data is too short or the MAC does not match
	 */
	public static function decrypt($key, $data)
	{
		$len = strlen($data);
		if ($len < self::EXTENSION) {
			return false;
		}
		$iv = substr($data, 0, self::IV_SIZE);
		$ciphertext = (string) substr($data, self::IV_SIZE, $len - self::EXTENSION);
		$mac = substr($data, $len - self::MAC_SIZE);
		if (!hash_equals(self::mac($ciphertext, $iv, $key), $mac)) {
			return false;
		}
		if ($ciphertext === '') {
			return '';
		}
		return openssl_decrypt($ciphertext, 'aes-256-ctr', $key['encrypt'], OPENSSL_RAW_DATA, $iv);
	}

	/**
	 * Poly1305-AES of a message: the second half of the one-time key is AES-128(k, nonce)
	 *
	 * @param	string									$message	Message
	 * @param	string									$nonce		16 bytes nonce (the IV)
	 * @param	array{encrypt:string,k:string,r:string}	$key		Key
	 * @return	string											16 bytes tag
	 */
	public static function mac($message, $nonce, $key)
	{
		$s = (string) openssl_encrypt($nonce, 'aes-128-ecb', $key['k'], OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
		return self::poly1305($message, $key['r'].$s);
	}

	/**
	 * Poly1305 one-time authenticator (26-bit limbs, needs 64-bit integers)
	 *
	 * @param	string	$message	Message
	 * @param	string	$key		32 bytes key: r . s
	 * @return	string				16 bytes tag
	 */
	public static function poly1305($message, $key)
	{
		$m26 = 0x3ffffff;
		$t = array_values(unpack('V4', substr($key, 0, 16)));
		$r0 = $t[0] & 0x3ffffff;
		$r1 = (($t[0] >> 26) | ($t[1] << 6)) & 0x3ffff03;
		$r2 = (($t[1] >> 20) | ($t[2] << 12)) & 0x3ffc0ff;
		$r3 = (($t[2] >> 14) | ($t[3] << 18)) & 0x3f03fff;
		$r4 = ($t[3] >> 8) & 0x00fffff;
		$s1 = $r1 * 5;
		$s2 = $r2 * 5;
		$s3 = $r3 * 5;
		$s4 = $r4 * 5;
		$h0 = $h1 = $h2 = $h3 = $h4 = 0;

		$len = strlen($message);
		$full = $len - ($len % 16);
		$tail = substr($message, $full);
		if ($tail !== '') {
			$tail = str_pad($tail."\x01", 16, "\x00");
		}
		// Words of the full blocks are unpacked by slices of 64 KiB to bound memory
		$slice = 65536;
		for ($offset = 0; $offset < $full || ($offset == $full && $tail !== ''); $offset += $slice) {
			if ($offset < $full) {
				$words = unpack('V*', substr($message, $offset, min($slice, $full - $offset)));
				$hibit = 1 << 24;
				if ($offset + $slice >= $full && $tail !== '') {
					$lastWords = unpack('V*', $tail);
				} else {
					$lastWords = null;
				}
			} else {
				$words = array();
				$lastWords = unpack('V*', $tail);
				$hibit = 1 << 24;
			}
			$n = count($words);
			for ($i = 1; $i <= $n; $i += 4) {
				$w0 = $words[$i];
				$w1 = $words[$i + 1];
				$w2 = $words[$i + 2];
				$w3 = $words[$i + 3];
				$h0 += $w0 & $m26;
				$h1 += (($w0 >> 26) | ($w1 << 6)) & $m26;
				$h2 += (($w1 >> 20) | ($w2 << 12)) & $m26;
				$h3 += (($w2 >> 14) | ($w3 << 18)) & $m26;
				$h4 += ($w3 >> 8) | $hibit;

				$d0 = $h0 * $r0 + $h1 * $s4 + $h2 * $s3 + $h3 * $s2 + $h4 * $s1;
				$d1 = $h0 * $r1 + $h1 * $r0 + $h2 * $s4 + $h3 * $s3 + $h4 * $s2;
				$d2 = $h0 * $r2 + $h1 * $r1 + $h2 * $r0 + $h3 * $s4 + $h4 * $s3;
				$d3 = $h0 * $r3 + $h1 * $r2 + $h2 * $r1 + $h3 * $r0 + $h4 * $s4;
				$d4 = $h0 * $r4 + $h1 * $r3 + $h2 * $r2 + $h3 * $r1 + $h4 * $r0;

				$c = $d0 >> 26;
				$h0 = $d0 & $m26;
				$d1 += $c;
				$c = $d1 >> 26;
				$h1 = $d1 & $m26;
				$d2 += $c;
				$c = $d2 >> 26;
				$h2 = $d2 & $m26;
				$d3 += $c;
				$c = $d3 >> 26;
				$h3 = $d3 & $m26;
				$d4 += $c;
				$c = $d4 >> 26;
				$h4 = $d4 & $m26;
				$h0 += $c * 5;
				$c = $h0 >> 26;
				$h0 &= $m26;
				$h1 += $c;
			}
			if ($lastWords !== null) {
				// The padded last block carries its own 0x01 marker, so no high bit
				$w0 = $lastWords[1];
				$w1 = $lastWords[2];
				$w2 = $lastWords[3];
				$w3 = $lastWords[4];
				$h0 += $w0 & $m26;
				$h1 += (($w0 >> 26) | ($w1 << 6)) & $m26;
				$h2 += (($w1 >> 20) | ($w2 << 12)) & $m26;
				$h3 += (($w2 >> 14) | ($w3 << 18)) & $m26;
				$h4 += ($w3 >> 8);

				$d0 = $h0 * $r0 + $h1 * $s4 + $h2 * $s3 + $h3 * $s2 + $h4 * $s1;
				$d1 = $h0 * $r1 + $h1 * $r0 + $h2 * $s4 + $h3 * $s3 + $h4 * $s2;
				$d2 = $h0 * $r2 + $h1 * $r1 + $h2 * $r0 + $h3 * $s4 + $h4 * $s3;
				$d3 = $h0 * $r3 + $h1 * $r2 + $h2 * $r1 + $h3 * $r0 + $h4 * $s4;
				$d4 = $h0 * $r4 + $h1 * $r3 + $h2 * $r2 + $h3 * $r1 + $h4 * $r0;

				$c = $d0 >> 26;
				$h0 = $d0 & $m26;
				$d1 += $c;
				$c = $d1 >> 26;
				$h1 = $d1 & $m26;
				$d2 += $c;
				$c = $d2 >> 26;
				$h2 = $d2 & $m26;
				$d3 += $c;
				$c = $d3 >> 26;
				$h3 = $d3 & $m26;
				$d4 += $c;
				$c = $d4 >> 26;
				$h4 = $d4 & $m26;
				$h0 += $c * 5;
				$c = $h0 >> 26;
				$h0 &= $m26;
				$h1 += $c;
				break;
			}
		}

		// Full carry, then compute h + -p and select h or h - p
		$c = $h1 >> 26;
		$h1 &= $m26;
		$h2 += $c;
		$c = $h2 >> 26;
		$h2 &= $m26;
		$h3 += $c;
		$c = $h3 >> 26;
		$h3 &= $m26;
		$h4 += $c;
		$c = $h4 >> 26;
		$h4 &= $m26;
		$h0 += $c * 5;
		$c = $h0 >> 26;
		$h0 &= $m26;
		$h1 += $c;

		$g0 = $h0 + 5;
		$c = $g0 >> 26;
		$g0 &= $m26;
		$g1 = $h1 + $c;
		$c = $g1 >> 26;
		$g1 &= $m26;
		$g2 = $h2 + $c;
		$c = $g2 >> 26;
		$g2 &= $m26;
		$g3 = $h3 + $c;
		$c = $g3 >> 26;
		$g3 &= $m26;
		$g4 = $h4 + $c - (1 << 26);
		if ($g4 >= 0) {
			$h0 = $g0;
			$h1 = $g1;
			$h2 = $g2;
			$h3 = $g3;
			$h4 = $g4;
		}

		$h0 = ($h0 | ($h1 << 26)) & 0xffffffff;
		$h1 = (($h1 >> 6) | ($h2 << 20)) & 0xffffffff;
		$h2 = (($h2 >> 12) | ($h3 << 14)) & 0xffffffff;
		$h3 = (($h3 >> 18) | ($h4 << 8)) & 0xffffffff;

		$p = array_values(unpack('V4', substr($key, 16, 16)));
		$f = $h0 + $p[0];
		$h0 = $f & 0xffffffff;
		$f = $h1 + $p[1] + ($f >> 32);
		$h1 = $f & 0xffffffff;
		$f = $h2 + $p[2] + ($f >> 32);
		$h2 = $f & 0xffffffff;
		$f = $h3 + $p[3] + ($f >> 32);
		$h3 = $f & 0xffffffff;

		return pack('V4', $h0, $h1, $h2, $h3);
	}

	/**
	 * Derive the 64 bytes key of a key file with scrypt, then split it as restic does
	 *
	 * @param	string	$password	Repository password
	 * @param	string	$salt		Salt (raw)
	 * @param	int		$N			scrypt N
	 * @param	int		$r			scrypt r
	 * @param	int		$p			scrypt p
	 * @return	array{encrypt:string,k:string,r:string}
	 */
	public static function kdf($password, $salt, $N, $r, $p)
	{
		$derived = self::scrypt($password, $salt, $N, $r, $p, 64);
		return array('encrypt' => substr($derived, 0, 32), 'k' => substr($derived, 32, 16), 'r' => substr($derived, 48, 16));
	}

	/**
	 * scrypt (RFC 7914) in pure PHP. libsodium is of no help: it only takes 32 bytes salts, restic
	 * refuses anything but 64.
	 *
	 * @param	string	$password	Password
	 * @param	string	$salt		Salt
	 * @param	int		$N			CPU/memory cost
	 * @param	int		$r			Block size
	 * @param	int		$p			Parallelization
	 * @param	int		$length		Output length
	 * @return	string
	 */
	public static function scrypt($password, $salt, $N, $r, $p, $length)
	{
		$blockSize = 128 * $r;
		$b = hash_pbkdf2('sha256', $password, $salt, 1, $p * $blockSize, true);
		$out = '';
		for ($i = 0; $i < $p; $i++) {
			$out .= self::roMix(substr($b, $i * $blockSize, $blockSize), $N, $r);
		}
		return hash_pbkdf2('sha256', $password, $out, 1, $length, true);
	}

	/**
	 * scrypt ROMix on 32-bit little-endian words
	 *
	 * @param	string	$block	128 * r bytes
	 * @param	int		$N		CPU/memory cost
	 * @param	int		$r		Block size
	 * @return	string
	 */
	private static function roMix($block, $N, $r)
	{
		// V is kept as binary strings: as PHP arrays it would take 8 times the memory
		$x = array_values(unpack('V*', $block));
		$v = array();
		for ($i = 0; $i < $N; $i++) {
			$v[$i] = pack('V*', ...$x);
			$x = self::blockMix($x, $r);
		}
		$last = (2 * $r - 1) * 16;
		for ($i = 0; $i < $N; $i++) {
			$j = $x[$last] & ($N - 1);
			$x = self::blockMix(array_values(unpack('V*', pack('V*', ...$x) ^ $v[$j])), $r);
		}
		return pack('V*', ...$x);
	}

	/**
	 * scrypt BlockMix with Salsa20/8
	 *
	 * @param	int[]	$b	32 * r words
	 * @param	int		$r	Block size
	 * @return	list<int>
	 */
	private static function blockMix($b, $r)
	{
		$x = array_slice($b, (2 * $r - 1) * 16, 16);
		$even = array();
		$odd = array();
		for ($i = 0; $i < 2 * $r; $i++) {
			for ($k = 0; $k < 16; $k++) {
				$x[$k] ^= $b[$i * 16 + $k];
			}
			$x = self::salsa208($x);
			if ($i % 2 == 0) {
				array_push($even, ...$x);
			} else {
				array_push($odd, ...$x);
			}
		}
		return array_merge($even, $odd);
	}

	/**
	 * Salsa20/8 core
	 *
	 * @param	int[]	$in		16 words
	 * @return	int[]
	 */
	private static function salsa208($in)
	{
		list($x0, $x1, $x2, $x3, $x4, $x5, $x6, $x7, $x8, $x9, $x10, $x11, $x12, $x13, $x14, $x15) = $in;
		for ($i = 0; $i < 8; $i += 2) {
			$t = ($x0 + $x12) & 0xffffffff;
			$x4 ^= (($t << 7) | ($t >> 25)) & 0xffffffff;
			$t = ($x4 + $x0) & 0xffffffff;
			$x8 ^= (($t << 9) | ($t >> 23)) & 0xffffffff;
			$t = ($x8 + $x4) & 0xffffffff;
			$x12 ^= (($t << 13) | ($t >> 19)) & 0xffffffff;
			$t = ($x12 + $x8) & 0xffffffff;
			$x0 ^= (($t << 18) | ($t >> 14)) & 0xffffffff;
			$t = ($x5 + $x1) & 0xffffffff;
			$x9 ^= (($t << 7) | ($t >> 25)) & 0xffffffff;
			$t = ($x9 + $x5) & 0xffffffff;
			$x13 ^= (($t << 9) | ($t >> 23)) & 0xffffffff;
			$t = ($x13 + $x9) & 0xffffffff;
			$x1 ^= (($t << 13) | ($t >> 19)) & 0xffffffff;
			$t = ($x1 + $x13) & 0xffffffff;
			$x5 ^= (($t << 18) | ($t >> 14)) & 0xffffffff;
			$t = ($x10 + $x6) & 0xffffffff;
			$x14 ^= (($t << 7) | ($t >> 25)) & 0xffffffff;
			$t = ($x14 + $x10) & 0xffffffff;
			$x2 ^= (($t << 9) | ($t >> 23)) & 0xffffffff;
			$t = ($x2 + $x14) & 0xffffffff;
			$x6 ^= (($t << 13) | ($t >> 19)) & 0xffffffff;
			$t = ($x6 + $x2) & 0xffffffff;
			$x10 ^= (($t << 18) | ($t >> 14)) & 0xffffffff;
			$t = ($x15 + $x11) & 0xffffffff;
			$x3 ^= (($t << 7) | ($t >> 25)) & 0xffffffff;
			$t = ($x3 + $x15) & 0xffffffff;
			$x7 ^= (($t << 9) | ($t >> 23)) & 0xffffffff;
			$t = ($x7 + $x3) & 0xffffffff;
			$x11 ^= (($t << 13) | ($t >> 19)) & 0xffffffff;
			$t = ($x11 + $x7) & 0xffffffff;
			$x15 ^= (($t << 18) | ($t >> 14)) & 0xffffffff;
			$t = ($x0 + $x3) & 0xffffffff;
			$x1 ^= (($t << 7) | ($t >> 25)) & 0xffffffff;
			$t = ($x1 + $x0) & 0xffffffff;
			$x2 ^= (($t << 9) | ($t >> 23)) & 0xffffffff;
			$t = ($x2 + $x1) & 0xffffffff;
			$x3 ^= (($t << 13) | ($t >> 19)) & 0xffffffff;
			$t = ($x3 + $x2) & 0xffffffff;
			$x0 ^= (($t << 18) | ($t >> 14)) & 0xffffffff;
			$t = ($x5 + $x4) & 0xffffffff;
			$x6 ^= (($t << 7) | ($t >> 25)) & 0xffffffff;
			$t = ($x6 + $x5) & 0xffffffff;
			$x7 ^= (($t << 9) | ($t >> 23)) & 0xffffffff;
			$t = ($x7 + $x6) & 0xffffffff;
			$x4 ^= (($t << 13) | ($t >> 19)) & 0xffffffff;
			$t = ($x4 + $x7) & 0xffffffff;
			$x5 ^= (($t << 18) | ($t >> 14)) & 0xffffffff;
			$t = ($x10 + $x9) & 0xffffffff;
			$x11 ^= (($t << 7) | ($t >> 25)) & 0xffffffff;
			$t = ($x11 + $x10) & 0xffffffff;
			$x8 ^= (($t << 9) | ($t >> 23)) & 0xffffffff;
			$t = ($x8 + $x11) & 0xffffffff;
			$x9 ^= (($t << 13) | ($t >> 19)) & 0xffffffff;
			$t = ($x9 + $x8) & 0xffffffff;
			$x10 ^= (($t << 18) | ($t >> 14)) & 0xffffffff;
			$t = ($x15 + $x14) & 0xffffffff;
			$x12 ^= (($t << 7) | ($t >> 25)) & 0xffffffff;
			$t = ($x12 + $x15) & 0xffffffff;
			$x13 ^= (($t << 9) | ($t >> 23)) & 0xffffffff;
			$t = ($x13 + $x12) & 0xffffffff;
			$x14 ^= (($t << 13) | ($t >> 19)) & 0xffffffff;
			$t = ($x14 + $x13) & 0xffffffff;
			$x15 ^= (($t << 18) | ($t >> 14)) & 0xffffffff;
		}
		return array(
			($x0 + $in[0]) & 0xffffffff, ($x1 + $in[1]) & 0xffffffff, ($x2 + $in[2]) & 0xffffffff, ($x3 + $in[3]) & 0xffffffff,
			($x4 + $in[4]) & 0xffffffff, ($x5 + $in[5]) & 0xffffffff, ($x6 + $in[6]) & 0xffffffff, ($x7 + $in[7]) & 0xffffffff,
			($x8 + $in[8]) & 0xffffffff, ($x9 + $in[9]) & 0xffffffff, ($x10 + $in[10]) & 0xffffffff, ($x11 + $in[11]) & 0xffffffff,
			($x12 + $in[12]) & 0xffffffff, ($x13 + $in[13]) & 0xffffffff, ($x14 + $in[14]) & 0xffffffff, ($x15 + $in[15]) & 0xffffffff,
		);
	}
}
