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
 * \file    einvoicing/class/utils/PayeeBankAccountHelper.class.php
 * \ingroup einvoicing
 * \brief   Bank account a received e-invoice says it must be paid to (BT-84, BT-85, BT-86).
 */

dol_include_once('einvoicing/class/protocols/ProtocolManager.class.php');
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
dol_include_once('einvoicing/class/utils/SupplierInvoiceHelper.class.php');
dol_include_once('fourn/class/fournisseur.facture.class.php');
// The core ships install/inc.php, which defines DOL_DOCUMENT_ROOT as '..', and PHPStan resolves the
// constant against it: the path it reports does not exist, the one used at runtime does.
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';  // @phpstan-ignore requireOnce.fileNotFound
require_once DOL_DOCUMENT_ROOT.'/societe/class/companybankaccount.class.php';  // @phpstan-ignore requireOnce.fileNotFound
require_once DOL_DOCUMENT_ROOT.'/core/lib/bank.lib.php';  // @phpstan-ignore requireOnce.fileNotFound

/**
 * Class PayeeBankAccountHelper
 *
 * The import never writes that account on the vendor: an IBAN read from an incoming document is
 * exactly what an invoice fraud carries. It is shown on the supplier invoice card, compared with
 * the accounts already recorded on the vendor, and only a click of the user adds it.
 */
class PayeeBankAccountHelper
{
	/**
	 * Remove everything that is not a letter or a digit and upper case the rest, so two writings
	 * of the same IBAN compare equal.
	 *
	 * @param  string|null	$iban	IBAN as written in a document or in the database
	 * @return string				Comparable form, '' when there is nothing to compare
	 */
	public static function normalizeIban($iban)
	{
		return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $iban));
	}

	/**
	 * Bank accounts announced by the e-invoice a supplier invoice was imported from.
	 *
	 * @param  DoliDB				$db			Database handler
	 * @param  FactureFournisseur	$invoice	Supplier invoice
	 * @return array<int,array{iban:string,bic:string,accountName:string}>	Accounts, in document order
	 */
	public static function announcedAccounts($db, FactureFournisseur $invoice)
	{
		if (empty($invoice->id)) {
			return array();
		}

		$xml = self::receivedXml($db, $invoice);
		if ($xml === '') {
			return array();
		}

		$protocolManager = new ProtocolManager($db);
		$protocolName = $protocolManager->detectProtocolFromContent($xml);
		if (empty($protocolName)) {
			return array();
		}

		$protocol = $protocolManager->getProtocol($protocolName);
		// Only the CII family reads a payee account, and a provider may answer with another protocol
		if (!($protocol instanceof CIIProtocol)) {
			return array();
		}

		return $protocol->parsePayeeBankAccounts($xml);
	}

	/**
	 * XML of the document received for a supplier invoice, read from the flow it was imported from,
	 * then from the file attached to the invoice when the flow stored none (document too big to be
	 * kept in the column, record cleaned up...).
	 *
	 * @param  DoliDB				$db			Database handler
	 * @param  FactureFournisseur	$invoice	Supplier invoice
	 * @return string							XML content, '' when there is none
	 */
	protected static function receivedXml($db, FactureFournisseur $invoice)
	{
		$xml = '';
		try {
			// Without the fetch fallback: displaying a card must not call the access point
			$xml = (string) SupplierInvoiceHelper::getXmlData((int) $invoice->id);
		} catch (Exception $e) {
			// No flow for that invoice, or several: it has no document to read
			$xml = '';
		}

		if ($xml === '') {
			dol_include_once('einvoicing/class/einvoicing.class.php');
			$einvoicing = new EInvoicing($db);
			$path = $einvoicing->getSupplierEInvoiceXmlFilePath($invoice);
			if ($path !== '') {
				$xml = (string) file_get_contents($path);
			}
		}

		return $xml;
	}

	/**
	 * Bank accounts already recorded on a thirdparty, indexed by comparable IBAN.
	 *
	 * @param  DoliDB	$db		Database handler
	 * @param  int		$socid	Id of the thirdparty
	 * @return array<string,CompanyBankAccount>	Accounts of the thirdparty carrying an IBAN
	 */
	public static function thirdpartyAccounts($db, $socid)
	{
		$accounts = array();
		if ((int) $socid <= 0) {
			return $accounts;
		}

		$thirdparty = new Societe($db);
		if ($thirdparty->fetch((int) $socid) <= 0) {
			return $accounts;
		}

		// get_all_rib() fetches each account through its class, so the IBAN is already decrypted
		// (the column holds it encrypted since Dolibarr 22)
		$ribs = $thirdparty->get_all_rib();
		if (!is_array($ribs)) {
			return $accounts;
		}

		foreach ($ribs as $rib) {
			$key = self::normalizeIban($rib->iban);
			if ($key !== '') {
				$accounts[$key] = $rib;
			}
		}

		return $accounts;
	}

	/**
	 * Check the format of an announced IBAN through the core, so a malformed one is flagged before
	 * anyone pays it.
	 *
	 * @param  DoliDB		$db		Database handler
	 * @param  string		$iban	IBAN as written in the document
	 * @return bool					True when the core validates it
	 */
	public static function ibanLooksValid($db, $iban)
	{
		// checkIbanForAccount() only takes an object up to Dolibarr 19, where the IBAN alone is enough after
		$account = new CompanyBankAccount($db);
		$account->iban = (string) $iban;

		return (bool) checkIbanForAccount($account);
	}

	/**
	 * Tell whether an account announced by a document is already recorded on the vendor.
	 *
	 * @param  array{iban:string,bic:string,accountName:string}	$account	Announced account
	 * @param  array<string,CompanyBankAccount>					$known		Accounts of the vendor, as thirdpartyAccounts() returns them
	 * @return bool
	 */
	public static function isKnownAccount(array $account, array $known)
	{
		$key = self::normalizeIban($account['iban'] ?? '');

		return ($key !== '' && isset($known[$key]));
	}

	/**
	 * Right to add a payment account on a thirdparty, read the way societe/paymentmodes.php computes
	 * it: a right of its own since Dolibarr 22, the write right on thirdparties before, unless
	 * advanced permissions are on.
	 *
	 * @param  User	$user	User asking for it
	 * @return bool
	 */
	public static function userCanAddAccount(User $user)
	{
		if (version_compare(DOL_VERSION, '22.0.0', '>=')) {
			return (bool) $user->hasRight('societe', 'thirdparty_paymentinformation', 'write');
		}

		if (getDolGlobalString('MAIN_USE_ADVANCED_PERMS')) {
			return (bool) $user->hasRight('societe', 'thirdparty_paymentinformation_advance', 'write');
		}

		return (bool) $user->hasRight('societe', 'creer');
	}

	/**
	 * Whether the card of a received invoice may offer to record the announced account on the vendor:
	 * the option is opt-in, and the user still needs the right to write payment information.
	 *
	 * @param  User	$user	User asking for it
	 * @return bool
	 */
	public static function addAccountOffered(User $user)
	{
		return (getDolGlobalInt('EINVOICING_THIRDPARTIES_ADD_PAYEE_BANK_ACCOUNT') > 0 && self::userCanAddAccount($user));
	}

	/**
	 * A label no account of that thirdparty carries yet: the core indexes the label unique per
	 * thirdparty, so a document imported twice under the same reference must not be refused.
	 *
	 * @param  DoliDB	$db		Database handler
	 * @param  int		$socid	Id of the thirdparty
	 * @param  string	$base	Label wanted
	 * @return string			The label to store
	 */
	protected static function availableLabel($db, $socid, $base)
	{
		$taken = array();

		$thirdparty = new Societe($db);
		if ($thirdparty->fetch((int) $socid) > 0) {
			$ribs = $thirdparty->get_all_rib();
			if (is_array($ribs)) {
				foreach ($ribs as $rib) {
					$taken[(string) $rib->label] = 1;
				}
			}
		}

		$label = $base;
		for ($i = 2; isset($taken[$label]) && $i <= 50; $i++) {
			$label = $base . ' (' . $i . ')';
		}

		return $label;
	}

	/**
	 * Record on the vendor an account announced by a received document.
	 *
	 * An account already recorded is never modified nor replaced, and the new one is not made the
	 * default: only the core promotes it, when the vendor has no default account at all.
	 *
	 * @param  DoliDB				$db			Database handler
	 * @param  User					$user		User doing it
	 * @param  int					$socid		Id of the vendor
	 * @param  array{iban:string,bic:string,accountName:string}	$account	Announced account
	 * @param  string				$sourceRef	Reference of the document that announced it, kept in the label
	 * @param  string				$error		Error message when the account could not be recorded
	 * @return int								Id of the created account, 0 if the IBAN is already recorded, <0 if KO
	 */
	public static function addAccountToThirdparty($db, User $user, $socid, array $account, $sourceRef, &$error = '')
	{
		global $langs;

		$error = '';

		$iban = trim((string) ($account['iban'] ?? ''));
		if (self::normalizeIban($iban) === '' || (int) $socid <= 0) {
			$error = 'BadValueForParameter';
			return -1;
		}

		// Read again rather than trust what the page displayed: the account may have been recorded
		// since, by another user or from another invoice of the same vendor
		$known = self::thirdpartyAccounts($db, $socid);
		if (isset($known[self::normalizeIban($iban)])) {
			return 0;
		}

		// The label is stored, so it has to be readable whatever loaded the file that holds the key
		$langs->load('einvoicing@einvoicing');
		// The core indexes the label unique per thirdparty, and a document may announce two accounts:
		// the end of the IBAN tells them apart and says which account the record holds
		$label = $langs->transnoentities('EInvoicePayeeAccountLabel', $sourceRef !== '' ? $sourceRef : '-');
		// The column is 180 characters long since Dolibarr 22, 200 before
		$label = dol_trunc($label, 160, 'right', 'UTF-8', 1) . ' ' . substr(self::normalizeIban($iban), -4);

		$rib = new CompanyBankAccount($db);
		$rib->socid = (int) $socid;
		$rib->label = self::availableLabel($db, (int) $socid, $label);
		$rib->iban = $iban;
		$rib->bic = str_replace(' ', '', (string) ($account['bic'] ?? ''));
		// The proprio column is written from owner_name since Dolibarr 21, from proprio before
		if (version_compare(DOL_VERSION, '21.0.0', '>=')) {
			$rib->owner_name = (string) ($account['accountName'] ?? '');
		} else {
			// @phan-suppress-next-line PhanDeprecatedProperty
			$rib->proprio = (string) ($account['accountName'] ?? '');
		}
		$rib->status = Account::STATUS_OPEN;
		$rib->datec = dol_now();
		$rib->default_rib = 0;

		$db->begin();

		// Same sequence as societe/paymentmodes.php: create() inserts the record, update() writes the fields
		$res = $rib->create($user);
		if ($res > 0) {
			$res = $rib->update($user);
		}

		if ($res <= 0) {
			$error = implode(', ', array_filter(array_merge(array((string) $rib->error), $rib->errors)));
			dol_syslog('PayeeBankAccountHelper::addAccountToThirdparty Failed to record the announced account on thirdparty '.((int) $socid).': '.$error, LOG_ERR, 0, '_einvoicing');
			$db->rollback();
			return -1;
		}

		dol_syslog('PayeeBankAccountHelper::addAccountToThirdparty Account announced by document '.$sourceRef.' recorded on thirdparty '.((int) $socid).' (id '.((int) $rib->id).')', LOG_WARNING, 0, '_einvoicing');
		$db->commit();

		return (int) $rib->id;
	}
}
