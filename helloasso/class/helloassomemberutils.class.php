<?php
/* Copyright (C) 2024      Lucas Marcouiller    <lmarcouiller@dolicloud.com>
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
 * \file    helloasso/lib/helloasso_member.lib.php
 * \ingroup helloasso
 * \brief   Library files with members functions for HelloAsso
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent_type.class.php';
dol_include_once('helloasso/lib/helloasso.lib.php');

class HelloAssoMemberUtils
{
    public $db;

    public $helloasso_url;
    public $organization_slug;
    public $form_slug;
    public $helloasso_members;
    public $helloasso_member_types = array();

    public $customfields = array("email" => "5760");

    public $error;
    public $errors = array();

    /**
	 *  Constructor
	 *
	 *  @param	DoliDb		$db      Database handler
	 */
	public function __construct($db)
	{
        global $langs;
		$this->db = $db;
		$langs->load("helloasso@helloasso");

        if (getDolGlobalInt("HELLOASSO_LIVE")) {
            $this->organization_slug = getDolGlobalString("HELLOASSO_CLIENT_ORGANISATION");
            $this->form_slug = getDolGlobalString("HELLOASSO_FORM_MEMBERSHIP_SLUG");
            $this->helloasso_url = "api.helloasso.com";
        } else {
            $this->organization_slug = getDolGlobalString("HELLOASSO_TEST_CLIENT_ORGANISATION");
            $this->form_slug = getDolGlobalString("HELLOASSO_TEST_FORM_MEMBERSHIP_SLUG");
            $this->helloasso_url = "api.helloasso-sandbox.com";
        }

        $mappingstr = getDolGlobalString("HELLOASSO_TYPE_MEMBER_MAPPING");
        if (!empty($mappingstr)) {
            $this->helloasso_member_types = json_decode($mappingstr,true);   
		}

		return 1;
	}

    /**
     * @return int          0 if OK, <> 0 if KO (this function is used also by cron so only 0 is OK)
     */

    public function helloassoSyncMembersToDolibarr() {
        
        $helloasso_date_last_fetch = "";

        $helloasso_tokens = helloassoDoConnection();

        $res = $this->helloassoGetMembers($helloasso_tokens, $helloasso_date_last_fetch);
        if ($res != 1) {
            return $res;
        }
        $this->helloassoPostMembersToDolibarr();

        return 0;
    }

    /**
     * @param array         $helloasso_tokens               Tokens to connect to HelloAsso API
     * @param string        $helloasso_date_last_fetch      Date of last member fetch
     * 
     * @return int          1 if OK, <> 1 if KO
     */

    public function helloassoGetMembers($helloasso_tokens, $helloasso_date_last_fetch = "") {

        global $langs;
        $headers[] = "Authorization: ".ucfirst($helloasso_tokens["token_type"])." ".$helloasso_tokens["access_token"];
        $headers[] = "Accept: application/json";
        $headers[] = "Content-Type: application/json";

        $assoslug = str_replace('_', '-', dol_string_nospecial(strtolower(dol_string_unaccent($this->organization_slug)), '-'));
        $formslug = str_replace('_', '-', dol_string_nospecial(strtolower(dol_string_unaccent($this->form_slug)), '-'));
        $param = '?pageSize=100&pageIndex=1&withDetails=true';
        if ($helloasso_date_last_fetch) {
            $param .= "&from=".$helloasso_date_last_fetch;
        }
        $urlformemebers = "https://".urlencode($this->helloasso_url)."/v5/organizations/".urlencode($assoslug)."/forms/Membership/".urlencode($formslug).'/items'.$param;
        dol_syslog("Send Get to url=".$urlformemebers.", to get member list", LOG_DEBUG);

        $ret = getURLContent($urlformemebers, 'GET', "", 1, $headers);
        if ($ret["http_code"] != 200) {
            $arrayofmessage = array();
            if (!empty($ret2['content'])) {
                $arrayofmessage = json_decode($ret['content'], true);
            }
            if (!empty($arrayofmessage['message'])) {
                $this->error = $arrayofmessage['message'];
                $this->errors[] = $this->error;
            } else {
                if (!empty($arrayofmessage['errors']) && is_array($arrayofmessage['errors'])) {
                    foreach($arrayofmessage['errors'] as $tmpkey => $tmpmessage) {
                        if (!empty($tmpmessage['message'])) {
                            $this->error = $langs->trans("Error").' - '.$tmpmessage['message'];
                            $this->errors[] = $this->error;
                        } else {
                            $this->error = $langs->trans("UnkownError").' - HTTP code = '.$ret["http_code"];
                            $this->errors[] = $this->error;
                        }
                    }
                } else {
                    $this->error = $langs->trans("UnkownError").' - HTTP code = '.$ret["http_code"];
                    $this->errors[] = $this->error;
                }
            }
            return -1;
        }
        $result = $ret["content"];
        $json = json_decode($result);
        $this->helloasso_members = $json->data;

        return 1;
    }

    public function helloassoPostMembersToDolibarr() {
        global $user;
        $db = $this->db;
        $error = 0;
        $helloasso_members = $this->helloasso_members;
        foreach ($helloasso_members as $key => $newmember) {
            $member_type = 0;
            $member = new Adherent($db);
            $amount = $newmember->initialAmount / 100;
            $date_start_subscription = ""; 
            $date_end_subscription = "";

            // Verify if member_type mapping
            if (empty($this->helloasso_member_types[$newmember->tierId])) {
                $dolibarrmembertype = 0;
                $newref = "HELLOASSO_MEMBERTYPE_".((int) $newmember->tierId);
                $sql = "SELECT rowid as id";
                $sql .= " FROM ".MAIN_DB_PREFIX."adherent_type as at";
                $sql .= " WHERE ref = '".$db->escape($newref)."'";
                $sql .= " AND amount = ".((float) $amount);
                $sql .= " AND statut = 1";
                $resql = $db->query($sql);
                if ($resql) {
                    $num_rows = $db->num_rows($resql);
                    if ($num_rows >= 0) {
                        $objm = $db->fetch_object($resql);
                        $dolibarrmembertype = $objm->id;
                    } else {
                        //TODO: Make new Membertype
                        $newmembertype = new AdherentType($db);

                    }
                    $res = $this->setHelloAssoTypeMemberMapping($dolibarrmembertype, $newmember->tierId);
                    if ($res <= 0) {
                        return -1;
                    }
                } else {
                    $this->errors[] = $db->lasterror();
                    return -1;
                }

            }

            // Try to find dolibarr member linked to HelloAsso member
            $member_type = $this->helloasso_member_types[$newmember->tierId];
            $sql = "SELECT rowid as id";
            $sql .= " FROM ".MAIN_DB_PREFIX."adherent as a";
            $sql .= " WHERE a.firstname = '".$db->escape($newmember->user->firstName)."'";
            $sql .= " AND a.lastname = '".$db->escape($newmember->user->lastName)."'";
            $sql .= " AND statut = 1";
            $sql .= " AND entity IN (".getEntity($member->element).")";
            if (!empty($this->customfields['email'])) {
                $email = "";
                foreach ($newmember->customFields as $key => $field) {
                    if ($field->id == $this->customfields['email']) {
                        $email = $field->answer;
                        break;
                    }
                }
                $sql .= " AND a.email = '".$db->escape($email)."'";
            }
            $resql = $db->query($sql);
		    if ($resql) {
                $num_rows = $db->num_rows($resql);
                if ($num_rows == 1) {
                    $obj = $db->fetch_object($resql);
                    $member->fetch($obj->id);
                    if ($member->typeid != $member_type) {
                        $member->typeid = $member_type;
                        $result = $member->update($user);
                        if ($result <= 0) {
                            $this->error = $member->error;
                            $this->errors = array_merge($this->errors, $member->errors);
                            return -2;
                        }
                    }
                } else {
                    //TODO: Create new member
                }

                // Create new subscription
                if (!$error) {
                    $result = $member->subscription($date_start_subscription, $amount, 0, '', '', '', '', '', $date_end_subscription, $member_type);
                    if ($result <= 0) {
                        $this->error = $member->error;
                        $this->errors = array_merge($this->errors, $member->errors);
                        return -3;
                    }
                }
            } else {
                $this->errors[] = $db->lasterror();
                return -4;
            }
        }
        return 1;
    }

    public function setHelloAssoTypeMemberMapping($dolibarrmembertype, $helloassomembertype) {
        $mappingstr = getDolGlobalString("HELLOASSO_TYPE_MEMBER_MAPPING");
        if (empty($mappingstr)) {
            $mappingstr = "[]";
        }
        $mapping = json_decode($mappingstr,true);
        if (!empty($mapping[$helloassomembertype])) {
            $this->error = $langs->trans("ErrorHelloAssoMemberTypeAlreadyUsed");
            $this->errors[] = $langs->trans("ErrorHelloAssoMemberTypeAlreadyUsed");
            return -1;
        }
        $mapping[$helloassomembertype] = $dolibarrmembertype;
        $mappingstr = json_encode($mapping);
        $res = dolibarr_set_const($db, 'HELLOASSO_TYPE_MEMBER_MAPPING', $mappingstr, 'chaine', 0, '', $conf->entity);
        if ($res <= 0) {
            $this->error = $langs->trans("ErrorHelloAssoAddingMemberType");
            $this->errors[] = $langs->trans("ErrorHelloAssoAddingMemberType");
            return -2;
        }
        return 1;
    }
}

