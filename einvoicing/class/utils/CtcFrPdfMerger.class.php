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
 * The parent class embeds the XML into the PDF and writes the Factur-X XMP block. To do so it needs
 * three parameters — the attachment filename, the XMP conformance level and the XMP version — which
 * it looks up by matching the guideline URN found in the XML against ZugferdProfiles::PROFILEDEF.
 * That table has no entry for `urn:cen.eu:en16931:2017#conformant#urn.cpro.gouv.fr:1p0:extended-ctc-fr`,
 * so a CTC-FR document makes it throw ZugferdUnknownProfileException and no Factur-X file is produced.
 *
 * EXTENDED-CTC-FR is a conformant extension built on top of the Factur-X EXTENDED profile, and the
 * Factur-X XMP `ConformanceLevel` has no CTC-FR value of its own, so the EXTENDED parameters are the
 * ones to use: attachment `factur-x.xml`, conformance level `EXTENDED`, version `1.0`.
 *
 * The lookup is only bypassed when the parent fails, so every profile the library does know keeps its
 * own parameters, and the day horstoeko/zugferd learns about CTC-FR this class becomes a no-op.
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
