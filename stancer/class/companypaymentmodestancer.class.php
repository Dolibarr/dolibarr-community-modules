<?php
/**
 * companypaymentmodestancer.class.php
 *
 * Copyright (c) 2023 Eric Seigne <eric.seigne@cap-rel.fr>
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

// Put here all includes required by your class file
require_once DOL_DOCUMENT_ROOT.'/societe/class/companypaymentmode.class.php';

/**
 * Class for CompanyPaymentModeStancer
 */
class CompanyPaymentModeStancer extends CompanyPaymentMode
{

	public $stancer_object_ref; // Référence stancer de l'objet,
								// exemple card_bBtDikmyjj3gCPqsUY8tXROM pour une carte bancaire
								// ou sepa_1Yq9jrzXioNAfp3NhbwOXOo4 pour un prélèvement SEPA
	public $stancer_account;    //Référence stancer du compte client, exemple cust_wxOmAt7GIKDK7u5HGHtGyV8k

	/**
	 * Constructor: declares the two Stancer extra fields on top of the parent payment mode
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->fields['stancer_object_ref'] = array('type'=>'varchar(32)', 'label'=>'Stancer object ref', 'enabled'=>1, 'visible'=>-2, 'position'=>172);
		$this->fields['stancer_account'] = array('type'=>'varchar(32)', 'label'=>'Stancer account', 'enabled'=>1, 'visible'=>-2, 'position'=>173);
		parent::__construct($db);
	}
}
