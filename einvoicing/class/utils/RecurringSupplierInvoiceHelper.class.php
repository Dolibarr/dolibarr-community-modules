<?php
/* Copyright (C) 2026       Pierre Grasswill
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
 * \file    einvoicing/class/utils/RecurringSupplierInvoiceHelper.class.php
 * \ingroup einvoicing
 * \brief   Attach a received supplier invoice to the recurring template that announced it.
 */

dol_include_once('fourn/class/fournisseur.facture.class.php');
dol_include_once('fourn/class/fournisseur.facture-rec.class.php');

/**
 * Class RecurringSupplierInvoiceHelper
 *
 * A vendor billed on a subscription is usually recorded twice: the recurring template of Dolibarr
 * generates a draft, and the document the vendor sends is imported as another one. This attaches the
 * imported invoice to the template, so it carries the settings the template predefines (bank account,
 * project, payment) and is shown as generated from it. See issue #997.
 */
class RecurringSupplierInvoiceHelper
{
	/**
	 * Fields a template predefines that a supplier invoice carries too, as
	 * property of FactureFournisseur => property of FactureFournisseurRec.
	 */
	const INHERITABLE_FIELDS = array(
		'fk_account' => 'fk_account',
		'fk_project' => 'fk_project',
		'cond_reglement_id' => 'cond_reglement_id',
		'mode_reglement_id' => 'mode_reglement_id',
	);

	/**
	 * Recurring templates of a vendor, the unusable ones included: a suspended template is still a
	 * legitimate target for a manual attachment, and saying nothing about it would look like a bug.
	 *
	 * @param	int		$socId	Vendor the templates belong to
	 * @return	array<int,array{id:int,title:string,ref_supplier:string,suspended:int,frequency:int,unit_frequency:string,total_ttc:float}>	Templates, by id, oldest first. Empty on error.
	 */
	public static function listTemplatesOfVendor(int $socId): array
	{
		global $db;

		$templates = array();
		if ($socId <= 0) {
			return $templates;
		}

		$sql = "SELECT rowid, titre, ref_supplier, suspended, frequency, unit_frequency, total_ttc";
		$sql .= " FROM " . $db->prefix() . "facture_fourn_rec";
		$sql .= " WHERE fk_soc = " . ((int) $socId);
		$sql .= " AND entity IN (" . getEntity('invoice') . ")";
		$sql .= " ORDER BY rowid ASC";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . ' ' . $db->lasterror(), LOG_ERR);
			return $templates;
		}

		while ($obj = $db->fetch_object($resql)) {
			$templates[(int) $obj->rowid] = array(
				'id' => (int) $obj->rowid,
				'title' => (string) $obj->titre,
				'ref_supplier' => (string) $obj->ref_supplier,
				'suspended' => (int) $obj->suspended,
				'frequency' => (int) $obj->frequency,
				'unit_frequency' => (string) $obj->unit_frequency,
				'total_ttc' => (float) $obj->total_ttc,
			);
		}
		$db->free($resql);

		return $templates;
	}

	/**
	 * The one template a received document may be attached to without guessing.
	 *
	 * Only an active recurring template counts, and only if it is the only one: two subscriptions with
	 * the same vendor are told apart by the reader of the document, not by this.
	 *
	 * @param	array<int,array{id:int,title:string,ref_supplier:string,suspended:int,frequency:int,unit_frequency:string,total_ttc:float}>	$templates	As listTemplatesOfVendor() returns them
	 * @return	int		Id of that template, 0 when there is none or more than one
	 */
	public static function soleActiveTemplate(array $templates): int
	{
		$candidates = array();
		foreach ($templates as $template) {
			if (empty($template['suspended']) && !empty($template['frequency'])) {
				$candidates[] = (int) $template['id'];
			}
		}

		return (count($candidates) === 1) ? $candidates[0] : 0;
	}

	/**
	 * What a template predefines and the invoice does not carry yet.
	 *
	 * The document always wins: a payment method read in the document is the one the vendor asks for,
	 * so only the fields it left empty are taken from the template.
	 *
	 * @param	FactureFournisseur		$invoice	Invoice being imported or already stored
	 * @param	FactureFournisseurRec	$template	Template to inherit from
	 * @return	array<string,int>					Property of FactureFournisseur => value to set, empty when the template adds nothing
	 * @phan-suppress PhanPluginMoreSpecificActualReturnType
	 */
	public static function fieldsToInherit(FactureFournisseur $invoice, FactureFournisseurRec $template): array
	{
		$toinherit = array();

		foreach (self::INHERITABLE_FIELDS as $invoicefield => $templatefield) {
			$templatevalue = (int) ($template->$templatefield ?? 0);
			if ($templatevalue <= 0) {
				continue;
			}
			if ((int) ($invoice->$invoicefield ?? 0) > 0) {
				continue;
			}
			$toinherit[$invoicefield] = $templatevalue;
		}

		return $toinherit;
	}

	/**
	 * Extrafield values of the template the invoice does not have.
	 *
	 * Keys unknown to a supplier invoice are dropped by insertExtraFields() itself, so no filtering on
	 * the definitions is needed here.
	 *
	 * @param	FactureFournisseur		$invoice	Invoice, its extrafields already loaded
	 * @param	FactureFournisseurRec	$template	Template to inherit from
	 * @return	array<string,mixed>					Extrafield key => value to add, 'options_' prefix included
	 */
	public static function optionsToInherit(FactureFournisseur $invoice, FactureFournisseurRec $template): array
	{
		$toinherit = array();
		if (empty($template->array_options) || !is_array($template->array_options)) {
			return $toinherit;
		}

		$existing = is_array($invoice->array_options) ? $invoice->array_options : array();
		foreach ($template->array_options as $key => $value) {
			if ($value === '' || $value === null) {
				continue;
			}
			if (isset($existing[$key]) && $existing[$key] !== '') {
				continue;
			}
			$toinherit[$key] = $value;
		}

		return $toinherit;
	}

	/**
	 * Attach an invoice already stored to a template, and give it what that template predefines.
	 *
	 * @param	FactureFournisseur		$invoice	Invoice to attach, loaded
	 * @param	FactureFournisseurRec	$template	Template to attach it to, loaded
	 * @param	User					$user		User doing it
	 * @return	array{res:int,fields:string[],message:string}	res > 0 when attached, < 0 on failure. 'fields' names what was taken from the template.
	 */
	public static function attachStoredInvoice(FactureFournisseur $invoice, FactureFournisseurRec $template, User $user): array
	{
		global $langs;

		$langs->loadLangs(array('bills', 'banks', 'projects'));

		if ($invoice->setValueFrom('fk_fac_rec_source', $template->id, '', null, 'int', '', $user) <= 0) {
			return array('res' => -1, 'fields' => array(), 'message' => $invoice->error ?: 'setValueFrom fk_fac_rec_source failed');
		}
		$invoice->fk_fac_rec_source = $template->id;

		$applied = array();
		foreach (self::fieldsToInherit($invoice, $template) as $field => $value) {
			switch ($field) {
				case 'fk_account':
					$result = $invoice->setBankAccount($value, 1, $user);
					break;
				case 'fk_project':
					$result = $invoice->setProject($value, 1);
					break;
				case 'cond_reglement_id':
					$result = $invoice->setPaymentTerms($value);
					break;
				default:
					$result = $invoice->setPaymentMethods($value);
					break;
			}
			if ($result <= 0) {
				return array('res' => -1, 'fields' => $applied, 'message' => $invoice->error ?: ('could not set ' . $field . ' from the template'));
			}
			$applied[] = $langs->trans(self::labelOfField($field));
		}

		// Extrafields are not worth failing an attachment for: the invoice is attached either way, and
		// insertExtraFields() refuses the whole row when one mandatory field of the invoice is empty.
		// It also rewrites that row entirely, so the values already stored are read back first.
		$invoice->fetch_optionals();
		$options = self::optionsToInherit($invoice, $template);
		if (!empty($options)) {
			$invoice->array_options = array_merge(is_array($invoice->array_options) ? $invoice->array_options : array(), $options);
			if ($invoice->insertExtraFields('', $user) < 0) {
				dol_syslog(__METHOD__ . ' extrafields of template ' . $template->id . ' not applied to invoice ' . $invoice->id . ': ' . $invoice->error, LOG_WARNING);
			} else {
				$applied[] = $langs->trans('ExtraFields');
			}
		}

		return array('res' => 1, 'fields' => $applied, 'message' => '');
	}

	/**
	 * Translation key naming a field taken from the template, for the message telling what changed.
	 *
	 * @param	string	$field	Property of FactureFournisseur
	 * @return	string			Translation key of the core
	 */
	public static function labelOfField(string $field): string
	{
		$labels = array(
			'fk_account' => 'BankAccount',
			'fk_project' => 'Project',
			'cond_reglement_id' => 'PaymentConditions',
			'mode_reglement_id' => 'PaymentMode',
		);

		return $labels[$field] ?? $field;
	}

	/**
	 * The draft this template generated and that the imported document makes redundant.
	 *
	 * Only a draft with no payment on it, and not one an import produced itself, so the invoice the
	 * user is being offered to drop is really the one Dolibarr wrote on its own.
	 *
	 * @param	int		$templateId			Template that generated it
	 * @param	int		$socId				Vendor, the way the invoice list is scoped
	 * @param	int		$excludeInvoiceId	Invoice being attached, never a candidate
	 * @return	int							Its id, 0 when there is none or more than one, -1 on error
	 */
	public static function findGeneratedDraft(int $templateId, int $socId, int $excludeInvoiceId = 0): int
	{
		global $db;

		if ($templateId <= 0 || $socId <= 0) {
			return 0;
		}

		$sql = "SELECT f.rowid FROM " . $db->prefix() . "facture_fourn as f";
		$sql .= " WHERE f.fk_fac_rec_source = " . ((int) $templateId);
		$sql .= " AND f.fk_soc = " . ((int) $socId);
		$sql .= " AND f.fk_statut = " . ((int) FactureFournisseur::STATUS_DRAFT);
		$sql .= " AND f.rowid <> " . ((int) $excludeInvoiceId);
		$sql .= " AND f.entity IN (" . getEntity('facture_fourn') . ")";
		$sql .= " AND NOT EXISTS (SELECT pf.fk_facturefourn FROM " . $db->prefix() . "paiementfourn_facturefourn as pf WHERE pf.fk_facturefourn = f.rowid)";
		$sql .= " AND NOT EXISTS (SELECT ext.rowid FROM " . $db->prefix() . "einvoicing_extlinks as ext WHERE ext.element_id = f.rowid AND ext.element_type = 'invoice_supplier')";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . ' ' . $db->lasterror(), LOG_ERR);
			return -1;
		}

		$found = 0;
		$nbfound = 0;
		while ($obj = $db->fetch_object($resql)) {
			$found = (int) $obj->rowid;
			$nbfound++;
		}
		$db->free($resql);

		return ($nbfound === 1) ? $found : 0;
	}

	/**
	 * Move a template past the generation the received document already stands for.
	 *
	 * Done the way the core does it when it generates an invoice itself (FactureFournisseur::create()),
	 * and only when the template still owes one: a template whose next date is ahead has generated its
	 * invoice already, and skipping again would lose a period.
	 *
	 * @param	FactureFournisseurRec	$template	Template to move forward, loaded
	 * @param	User					$user		User doing it
	 * @return	int									1 when moved, 0 when there was nothing to skip, -1 on error
	 */
	public static function skipNextGeneration(FactureFournisseurRec $template, User $user): int
	{
		$now = dol_now();

		if (empty($template->frequency) || empty($template->date_when) || $template->date_when > $now) {
			return 0;
		}

		$nextdate = $template->getNextDate();
		if (empty($nextdate)) {
			return 0;
		}

		if ($template->setValueFrom('date_last_gen', $now, '', null, 'date', '', $user) <= 0) {
			dol_syslog(__METHOD__ . ' could not set date_last_gen on template ' . $template->id . ': ' . $template->error, LOG_ERR);
			return -1;
		}
		// @phan-suppress-next-line PhanTypeMismatchArgument  the core documents a 'datetime' and passes a timestamp itself
		if ($template->setNextDate($nextdate, 1) <= 0) {
			dol_syslog(__METHOD__ . ' could not move template ' . $template->id . ' to its next date: ' . $template->error, LOG_ERR);
			return -1;
		}

		return 1;
	}
}
