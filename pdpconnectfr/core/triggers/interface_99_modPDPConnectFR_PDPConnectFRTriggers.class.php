<?php
/* Copyright (C) 2023		Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2026		SuperAdmin					<daoud.mouhamed@gmail.com>
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
 * \file    core/triggers/interface_99_modPDPConnectFR_PDPConnectFRTriggers.class.php
 * \ingroup pdpconnectfr
 * \brief   Triggers for PDPConnectFR module
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';


/**
 *  Class of triggers for PDPConnectFR module
 */
class InterfacePDPConnectFRTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = "pdpconnectfr";
		$this->description = "PDPConnectFR triggers.";
		$this->version = self::VERSIONS['dev'];
		$this->picto = 'pdpconnectfr@pdpconnectfr';
	}

	/**
	 * PDPConnectFR trigger run function
	 *
	 * @param string 		$action 	Event action code
	 * @param CommonObject 	$object 	Object
	 * @param User 			$user 		Object user
	 * @param Translate 	$langs 		Object langs
	 * @param Conf 			$conf 		Object conf
	 * @return int              		Return integer <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		global $db;

		if (!isModEnabled('pdpconnectfr')) {
			return 0;
		}

		if ($action == 'THIRDPARTY_MODIFY') {
			// If we modify the country of a thirdparty, we update status of invoice
			// FR->other: status must be modified from "To generate" into "To ignore"
			// Other->FR: status must be modified from "To ignore" into "To generate"
			// TODO
		}

		if ($action == 'BILL_CREATE') {
			//When invoice is created
		}

		if ($action == 'BILL_VALIDATE') {
			$pdpConnectFr = new PdpConnectFr($db);

			$statustouse = $pdpConnectFr::STATUS_IGNORE;

			// Test if invoice need to be managed by EInvoice
			$needEinvoice = $pdpConnectFr->needEInvoiceManagement($object);
			if ($needEinvoice) {
				$statustouse = $pdpConnectFr::STATUS_NOT_GENERATED;
			}

			$newobject = dol_clone($object, 2);
			$newobject->ref = $object->newref;

			$result = $pdpConnectFr->setEInvoiceStatus($newobject, $statustouse, '');
			if ($result < 0) {
				$this->errors = array_merge($this->errors, $pdpConnectFr->errors);
				return -1;
			}
		}

		if ($action == 'BILL_UNVALIDATE') {
			$pdpConnectFr = new PdpConnectFr($db);
			$result = $pdpConnectFr->fetchLastknownInvoiceStatus($object->id);

			// If einvoice has been transmitted, we must check that we don't try to modify some fields
			if (is_array($result) && !in_array($result['code'], array($pdpConnectFr::STATUS_UNKNOWN, $pdpConnectFr::STATUS_IGNORE, $pdpConnectFr::STATUS_NOT_GENERATED, $pdpConnectFr::STATUS_GENERATED))) {
				$this->errors[] = 'You try to modify the status of an invoice that is locked once the invoice has been transmitted to the Access Point';
				return -3;
			}
		}

		if ($action == 'BILL_MODIFY') {
			$pdpConnectFr = new PdpConnectFr($db);
			$result = $pdpConnectFr->fetchLastknownInvoiceStatus($object->id);

			// If einvoice has been transmitted, we must check that we don't try to modify some fields
			if (is_array($result) && !in_array($result['code'], array($pdpConnectFr::STATUS_UNKNOWN, $pdpConnectFr::STATUS_IGNORE, $pdpConnectFr::STATUS_NOT_GENERATED, $pdpConnectFr::STATUS_GENERATED))) {
				// Fields that are locked after transmission.
				$lockedFields = array(
					'ref',
					'date',
					'date_lim_reglement',
					'multicurrency_code',
					'total_ht',
					'total_tva',
					'total_ttc',
					'fk_soc',
					'cond_reglement_id',
					'mode_reglement_id'
				);

				// Check if the invoice is transmitted to PDPConnectFR.
				$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."pdpconnectfr_extlinks WHERE element_id = ".((int) $object->id)." AND element_type = '" . $object->element . "'";
				$resql = $db->query($sql);
				if ($resql && $db->num_rows($resql) > 0) {
					// If invoice is transmitted, check if any locked field is modified.;
					foreach ($lockedFields as $field) {
						if ($object->$field != $object->oldcopy->$field) {
							$this->errors[] = 'You try to modify a property that is locked once the invoice has been transmitted to the Access Point';
							return -2;
						}
					}
					return 1; // Return >0 if OK.
				}
			}
		}

		return 0;
	}
}
