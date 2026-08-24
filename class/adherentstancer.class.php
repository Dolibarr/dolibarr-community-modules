<?php

/**
 * adherentstancer.class.php
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
require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent.class.php';

/**
 * Class for AdherentStancer
 */
class AdherentStancer extends Adherent
{
	/**
	 * @var int Country ID
	 */
	public $country_id;

	/**
	 * @var string Country code
	 */
	public $country_code;

	/**
	 * @var string Country name
	 */
	public $country;

	/**
	 * @var string Region code
	 */
	public $region_code;

	/**
	 * @var string Region name
	 */
	public $region;

	/**
	 * @var array<string,array<string,string>> Extra languages values
	 */
	public $array_languages;

	/**
	 * Returns the full address formatted for output.
	 *
	 * @param	int		$withcountry		1=Add country, 0=No country
	 * @param	string	$sep				Separator to use between parts
	 * @param	int		$withregion			1=Add region, 0=No region
	 * @param	string	$extralangcode		Language code for translation
	 * @return	string						Full address as string
	 */
	public function getFullAddress($withcountry = 0, $sep = "\n", $withregion = 0, $extralangcode = '')
	{
		if ($withcountry && $this->country_id && (empty($this->country_code) || empty($this->country))) {
			require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
			$tmparray = getCountry($this->country_id, 'all');
			$this->country_code = $tmparray['code'];
			$this->country = $tmparray['label'];
		}

		if ($withregion && $this->state_id && (empty($this->state_code) || empty($this->state) || empty($this->region) || empty($this->region_code))) {
			require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
			// Signature changed in Dolibarr 20: $dbtouse changed from int to DoliDB|null
			if (floatval(DOL_VERSION) < 20.0) {
				// @phpstan-ignore-next-line
				$tmparray = getState($this->state_id, 'all', 0, 1);
			} else {
				// @phpstan-ignore-next-line
				$tmparray = getState($this->state_id, 'all', null, 1);
			}
			$this->state_code = $tmparray['code'];
			$this->state = $tmparray['label'];
			$this->region_code = $tmparray['region_code'];
			$this->region = $tmparray['region'];
		}

		// Signature changed in Dolibarr 20: $outputlangs changed from string to Translate|null
		if (floatval(DOL_VERSION) < 20.0) {
			// @phpstan-ignore-next-line
			return dol_format_address($this, $withcountry, $sep, '', 0, $extralangcode);
		}
		// @phpstan-ignore-next-line
		return dol_format_address($this, $withcountry, $sep, null, 0, $extralangcode);
	}

	/**
	 * Fetch values for extra languages.
	 *
	 * @return	int		Return integer <0 if KO, 0 if no values, >0 if OK
	 */
	public function fetchValuesForExtraLanguages()
	{
		if (!$this->element) {
			return 0;
		}
		if (!($this->id > 0)) {
			return 0;
		}
		if (is_array($this->array_languages)) {
			return 1;
		}

		$this->array_languages = array();

		$element = $this->element;
		if ($element == 'categorie') {
			$element = 'categories';
		}

		$sql = "SELECT rowid, property, lang, value";
		$sql .= " FROM " . $this->db->prefix() . "object_lang";
		$sql .= " WHERE type_object = '" . $this->db->escape($element) . "'";
		$sql .= " AND fk_object = " . ((int) $this->id);

		$resql = $this->db->query($sql);
		if ($resql) {
			$numrows = $this->db->num_rows($resql);
			if ($numrows) {
				while ($obj = $this->db->fetch_object($resql)) {
					$key = $obj->property;
					$value = $obj->value;
					$codelang = $obj->lang;
					$this->array_languages[$key][$codelang] = $value;
				}
			}
			$this->db->free($resql);
			return $numrows;
		} else {
			dol_print_error($this->db);
			return -1;
		}
	}

	//fausse bonne idée: dette technique en cas de modification du coeur de dolibarr sur les appels de fonctions...
	//a reflechir

	//gestion des extrafields des adherents pour Stancer
	// public $stancer_sepa_ref; 	// Référence stancer de l'objet pour le prélèvement sepa
	// 							// exemple sepa_1Yq9jrzXioNAfp3NhbwOXOo4
	// public $stancer_cb_ref; 	// Référence stancer de l'objet pour le paiement cb
	// 							// exemple card_bBtDikmyjj3gCPqsUY8tXROM
	// public $stancer_account;    //Référence stancer du compte client, exemple cust_wxOmAt7GIKDK7u5HGHtGyV8k

	// public function __construct(DoliDB $db)
	// {
	// 	$this->fields['stancer_sepa_ref'] = array('type'=>'varchar(32)', 'label'=>'Stancer sepa ref', 'enabled'=>1, 'visible'=>-2, 'position'=>172);
	// 	$this->fields['stancer_cb_ref'] = array('type'=>'varchar(32)', 'label'=>'Stancer cb ref', 'enabled'=>1, 'visible'=>-2, 'position'=>173);
	// 	$this->fields['stancer_account'] = array('type'=>'varchar(32)', 'label'=>'Stancer account', 'enabled'=>1, 'visible'=>-2, 'position'=>174);
	// 	parent::__construct($db);
	// }


	// public function fetch($rowid, $ref = '', $fk_soc = '', $ref_ext = '', $fetch_optionals = true, $fetch_subscriptions = true) {
	// 	$res = parent::fetch($rowid, $ref, $fk_soc, $ref_ext, $fetch_optionals, $fetch_subscriptions);
	// 	if($res > 0) {
	// 		$this->stancer_sepa_ref = $this->array_options['options_stancer_sepa_ref'];
	// 		$this->stancer_cb_ref = $this->array_options['options_stancer_cb_ref'];
	// 		$this->stancer_account = $this->array_options['options_stancer_account'];
	// 	}
	// 	return $res;
	// }

	// public function update($user, $notrigger, $nosyncuser, $nosyncuserpass, $nosyncthirdparty, $action) {
	// 	$this->array_options['options_stancer_sepa_ref'] = $this->stancer_sepa_ref;
	// 	$this->array_options['options_stancer_cb_ref'] = $this->stancer_cb_ref;
	// 	$this->array_options['options_stancer_account'] = $this->stancer_account;
	// 	$res = parent::update($user, $notrigger, $nosyncuser, $nosyncuserpass, $nosyncthirdparty, $action);
	// }
}
