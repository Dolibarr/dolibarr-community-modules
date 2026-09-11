<?php
/* Copyright (C) 2026		Pierre Grasswill			<da.grumpf@gmail.com>
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
 * \file    einvoicing/class/utils/CtcFrPdfMerger.class.php
 * \ingroup einvoicing
 * \brief   Attach a CII XML to a PDF even when its guideline URN is unknown to horstoeko/zugferd
 */

use horstoeko\zugferd\ZugferdDocumentPdfMerger;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\exception\ZugferdUnknownProfileException;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * ZugferdDocumentPdfMerger that tolerates the EXTENDED-CTC-FR guideline.
 *
 * ZugferdProfiles::PROFILEDEF has no entry for the CTC-FR guideline URN, so the parent throws
 * ZugferdUnknownProfileException and embeds nothing. CTC-FR is a conformant extension of the
 * Factur-X EXTENDED profile, so the EXTENDED parameters apply. The lookup is only bypassed when the
 * parent fails, so every profile the library knows keeps its own parameters.
 */
class CtcFrPdfMerger extends ZugferdDocumentPdfMerger
{
	/**
	 * Attachment filename of the embedded XML.
	 *
	 * @return string	Attachment filename
	 */
	protected function getXmlAttachmentFilename(): string
	{
		try {
			return parent::getXmlAttachmentFilename();
		} catch (ZugferdUnknownProfileException $e) {
			return self::extendedProfileParameter('attachmentfilename', 'factur-x.xml');
		}
	}

	/**
	 * XMP conformance level (fx:ConformanceLevel) of the embedded XML.
	 *
	 * @return string	Conformance level written into the XMP block
	 */
	protected function getXmlAttachmentXmpName(): string
	{
		try {
			return parent::getXmlAttachmentXmpName();
		} catch (ZugferdUnknownProfileException $e) {
			return self::extendedProfileParameter('xmpname', 'EXTENDED');
		}
	}

	/**
	 * XMP version (fx:Version) of the embedded XML.
	 *
	 * @return string	Factur-X version written into the XMP block
	 */
	protected function getXmlAttachmentXmpVersion(): string
	{
		try {
			return parent::getXmlAttachmentXmpVersion();
		} catch (ZugferdUnknownProfileException $e) {
			return self::extendedProfileParameter('xmpversion', '1.0');
		}
	}

	/**
	 * Read a parameter of the library's EXTENDED profile definition, so we follow it rather than
	 * hardcode values that could drift.
	 *
	 * @param	string	$parameterName	Key of ZugferdProfiles::PROFILEDEF[PROFILE_EXTENDED]
	 * @param	string	$default		Value to use if the library ever drops that key
	 * @return	string					The parameter value
	 */
	private static function extendedProfileParameter(string $parameterName, string $default): string
	{
		$definition = ZugferdProfiles::PROFILEDEF[ZugferdProfiles::PROFILE_EXTENDED] ?? array();

		return isset($definition[$parameterName]) ? (string) $definition[$parameterName] : $default;
	}
}
