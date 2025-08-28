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
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
include_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
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
    public $nbPosts = 0;
    private $helloasso_tokens = array();

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
        $this->helloasso_tokens = helloassoDoConnection();

		return 1;
	}

    /**
     * @return int          0 if OK, <> 0 if KO (this function is used also by cron so only 0 is OK)
     */

    public function helloassoSyncMembersToDolibarr($dryrun = 0) {
        $db = $this->db;
        $error = 0;
        $db->begin();
        $helloasso_date_last_fetch = "";

        $res = $this->helloassoGetMembers($helloasso_date_last_fetch);
        if ($res != 0) {
            $error++;
        }
        if (!$error) {
            $this->helloassoPostMembersToDolibarr();
        }

        if (!$error && $dryrun == 0) {
		    $db->commit();
        } else {
            $db->rollback();
            if ($error) {
                return 1;
            }
        }
        return 0;
    }

    /**
     * @param string        $helloasso_date_last_fetch      Date of last member fetch
     * 
     * @return int          1 if OK, <> 1 if KO
     */

    public function helloassoGetMembers($helloasso_date_last_fetch = "") {

        global $langs;
        $headers[] = "Authorization: ".ucfirst($this->helloasso_tokens["token_type"])." ".$this->helloasso_tokens["access_token"];
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

        return 0;
    }

    public function helloassoPostMembersToDolibarr() {
        global $user;
        $db = $this->db;
        $error = 0;
        $helloasso_members = $this->helloasso_members;
        foreach ($helloasso_members as $key => $newmember) {
            $member_type = 0;
            $member = new Adherent($db);
            $membertype = new AdherentType($db);
            $amount = $newmember->initialAmount / 100;
            $date_start_subscription = dol_stringtotime($newmember->order->meta->createdAt); 

            // Verify if member_type mapping contain HelloAsso memberId
            if (empty($this->helloasso_member_types[$newmember->tierId])) {
                $dolibarrmembertype = 0;
                $newlabel = "HELLOASSO_MEMBERTYPE_".((int) $newmember->tierId);
                $sql = "SELECT rowid as id";
                $sql .= " FROM ".MAIN_DB_PREFIX."adherent_type as at";
                $sql .= " WHERE libelle = '".$db->escape($newlabel)."'";
                $sql .= " AND statut = 1";
                $sql .= " AND entity IN (".getEntity($membertype->element).")";
                $resql = $db->query($sql);
                if ($resql) {
                    $num_rows = $db->num_rows($resql);
                    if ($num_rows > 0) {
                        $objm = $db->fetch_object($resql);
                        $dolibarrmembertype = $objm->id;
                    } else {
                        $dolibarrmembertype = $this->createHelloAssoTypeMember($newlabel);
                    }
                    $res = $this->setHelloAssoTypeMemberMapping($dolibarrmembertype, $newmember->tierId);
                    if ($res <= 0) {
                        return -1;
                    }
                } else {
                    $this->errors[] = $db->lasterror();
                    return -2;
                }

            }
            $membertype->fetch($this->helloasso_member_types[$newmember->tierId]);
            $date_end_subscription = dol_time_plus_duree($date_start_subscription, $membertype->duration_value, $membertype->duration_unit);

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
                            return -3;
                        }
                    }
                } else {
                    $memberid = $this->createHelloAssoMember($newmember, $dolibarrmembertype);
                    if ($memberid <= 0) {
                       return -4;
                    }
                    $res = $member->fetch($memberid);
                    if ($res <= 0) {
                        $this->errors = array_merge($this->errors, $member->errors);
                        return -5;
                    }
                }

                // Create new subscription
                if (!$error) {
                    $result = $member->subscription($date_start_subscription, $amount, 0, '', '', '', '', '', $date_end_subscription, $member_type);
                    if ($result <= 0) {
                        $this->error = $member->error;
                        $this->errors = array_merge($this->errors, $member->errors);
                        return -6;
                    }
                }
            } else {
                $this->errors[] = $db->lasterror();
                return -7;
            }
            $this->nbPosts++;
        }
        return 0;
    }

    public function setHelloAssoTypeMemberMapping($dolibarrmembertype, $helloassomembertype) {
        global $langs, $conf;
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
        $res = dolibarr_set_const($this->db, 'HELLOASSO_TYPE_MEMBER_MAPPING', $mappingstr, 'chaine', 0, '', $conf->entity);
        if ($res <= 0) {
            $this->error = $langs->trans("ErrorHelloAssoAddingMemberType");
            $this->errors[] = $langs->trans("ErrorHelloAssoAddingMemberType");
            return -2;
        }
        $this->helloasso_member_types = $mapping;
        return 1;
    }

    public function createHelloAssoTypeMember($label) {
        global $user;
        $db = $this->db;
        $newmembertype = new AdherentType($db);

        $headers[] = "Authorization: ".ucfirst($this->helloasso_tokens["token_type"])." ".$this->helloasso_tokens["access_token"];
        $headers[] = "Accept: application/json";
        $headers[] = "Content-Type: application/json";

        $assoslug = str_replace('_', '-', dol_string_nospecial(strtolower(dol_string_unaccent($this->organization_slug)), '-'));
        $formslug = str_replace('_', '-', dol_string_nospecial(strtolower(dol_string_unaccent($this->form_slug)), '-'));
        $urlforform = "https://".urlencode($this->helloasso_url)."/v5/organizations/".urlencode($assoslug)."/forms/Membership/".urlencode($formslug).'/public';
        dol_syslog("Send Get to url=".$urlforform.", to get HelloAsso Member type informations", LOG_DEBUG);

        $ret = getURLContent($urlforform, 'GET', "", 1, $headers);
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
        $validitytype = $json->validityType;
        $duration_value = "1";
        $duration_unit = "y";
        $subscription = 1;
        switch ($validitytype) {
            case 'Custom':
                //TODO: Custom calcul for duration
                $duration_value = "1";
                $duration_unit = "y";
                break;

            case 'Illimited':
                $duration_value = "";
                $duration_unit = "";
                $subscription = 0;
                break;
            
            default:
                $duration_value = "1";
                $duration_unit = "y";
                $subscription = 1;
                break;
        }
        $amount = $json->tiers[0]->price / 100;

        $newmembertype->amount = $amount;
        $newmembertype->subscription = $subscription;
        $newmembertype->duration_value = $duration_value;
        $newmembertype->duration_unit = $duration_unit;
        $newmembertype->label = $label;
        $newmembertype->statut = 1;
        $res = $newmembertype->create($user);
        if ($res <= 0) {
            $this->errors = array_merge($this->errors, $newmembertype->errors);
            return -2;
        }
        return $res;
    }

    public function createHelloAssoMember($newmember, $membertype) {
        global $user;
        $db = $this->db;
        $customfields = array_flip($this->customfields);
        $craetemember = new Adherent($db);

        $craetemember->firstname = $newmember->user->firstName;
        $craetemember->lastname = $newmember->user->lastName;
        $craetemember->typeid = $membertype;
        if (!empty($newmember->customFields) && !empty($this->customfields)) {
            foreach ($newmember->customFields as $key => $field) {
                if (!empty($customfields[$field->id])) {
                    $dolibarkey = $customfields[$field->id];
                    $craetemember->$dolibarkey = $field->answer;
                }
            }
        }
        // Login creation for member
        if (empty($craetemember->login)) {
            $login = strtolower($newmember->user->firstName).strtolower($newmember->user->lastName);
            $sql = "SELECT COUNT(rowid) as nbmembers";
            $sql .= " FROM ".MAIN_DB_PREFIX."adherent";
            $sql .= " WHERE entity IN (".((int) getEntity($craetemember->element)).")";
            $sql .= " AND login LIKE '".$db->escape($login)."%'";
            $resql = $db->query($sql);
            if ($resql) {
                $num_rows = $db->num_rows($resql);
                if ($num_rows > 0) {
                    $obja = $db->fetch_object($resql);
                    if ($obja->nbmembers > 0) {
                        $login = $login.((string)($obja->nbmembers + 1));
                    }
                }
            } else {
                $this->errors[] = $db->lasterror();
                return -1;
            }
            $craetemember->login = $login;
        }
        $res = $craetemember->create($user);
        if ($res <= 0) {
            $this->errors = array_merge($this->errors, $craetemember->errors);
            return -2;
        }
        $res = $craetemember->validate($user);
        if ($res <= 0) {
            $this->errors = array_merge($this->errors, $craetemember->errors);
            return -3;
        }
        return $craetemember->id;
    }
}

