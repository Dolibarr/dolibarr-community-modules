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
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('helloasso/lib/helloasso.lib.php');

class HelloAssoMemberUtils
{
    public $db;

    public $helloasso_url;
    public $organization_slug;
    public $form_slug;
    public $helloasso_members;
    public $helloasso_member_types = array();
    public $helloasso_date_last_fetch = "";
    
    public $error;
    public $errors = array();
    public $nbPosts = 0;
    public $output = "";
    private $helloasso_tokens = array();
    
    public $memberfields = array(
        "name",
        "email",
        "address",
        "zip",
        "town",
        "phone",
        "phone_perso",
        "phone_pro",
        "phone_mobile",
        "fax",
        "civility_id",
        "poste",
        "default_lang",
        "photo",
        "gender",
        "birth",
        "note_public",
        "note_private"
    );
    public $customfields = array();

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
            $this->helloasso_member_types = json_decode($mappingstr, true);   
		}
        $mappingcustomfieldsstr = getDolGlobalString("HELLOASSO_CUSTOM_FIELD_MAPPING");
        if (!empty($mappingcustomfieldsstr)) {
            $this->customfields = json_decode($mappingcustomfieldsstr, true);   
		}

        $this->helloasso_date_last_fetch = getDolGlobalString("HELLOASSO_DATE_LAST_MEMBER_FETCH");

        $this->helloasso_tokens = helloassoDoConnection();

		return 1;
	}

    /**
     * Sync Members form HelloAsso to Dolibarr
     * 
     * @param   int        $dryrun    0 for normal run, 1 for dry run
     * @param   string     $mode      If set to "cron" disable SetEventMessages
     * 
     * @return  int              0 if OK, <> 0 if KO (this function is used also by cron so only 0 is OK)
     */

    public function helloassoSyncMembersToDolibarr($dryrun = 0, $mode = "cron")
    {
        global $langs;

        $db = $this->db;
        $helloasso_date_last_fetch = "";
        $error = 0;
        $db->begin();


        if ($dryrun == 0) {
            $helloasso_date_last_fetch = $this->helloasso_date_last_fetch;
        }

        $res = $this->helloassoGetMembers($helloasso_date_last_fetch, $dryrun);
        if ($res < 0) {
            $error++;
        }
        if (!$error) {
            $res = $this->helloassoPostMembersToDolibarr();
            if ($res < 0) {
                $error++;
            }
        }

        if (!$error && $dryrun == 0) {
            $db->commit();
            if ($mode != "cron") {
                $mesg = $langs->transnoentities("HelloAssoMembersAddedSucessfully", $this->nbPosts);
                if ($this->nbPosts == 0) {
                    $mesg = $langs->transnoentities("HelloAssoMembersNoNewMembers");
                }
		        setEventMessages($mesg, null, 'mesgs');
            } else {
                $mesg = $this->nbPosts." Member(s) have been added successfully";
                if ($this->nbPosts == 0) {
                    $mesg = "0 Member where added, no new members on HelloAsso";
                }
                $this->output = $mesg;
            }
        } else {
            $db->rollback();
            if ($error) {
                if (!empty($this->error)) {
                    $this->errors[] = $this->error;
                }
				$errmsg = (implode(', ', $this->errors));
                if ($mode != "cron") {
		            setEventMessages($errmsg, null, 'errors');
                }
                return 1;
            }
            $mesg = $langs->transnoentities("HelloAssoMembersNothingDone", $this->nbPosts);
            if ($mode != "cron") {
		        setEventMessages($mesg, null, 'warnings');
            } else {
                $this->output = "Nothing done (Dry mode)";
            }
        }
        return 0;
    }

    /**
     * Post array $this->helloasso_members of HelloAsso Members to Dolibarr
     * 
     * @return int >0 if OK, 0 if nothing to do, <0 if KO
     */

    public function helloassoPostMembersToDolibarr()
    {
        global $user, $conf, $langs;
        $db = $this->db;
        $error = 0;
        $datelastfetch = 0;
        
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
        $helloassoformdata = json_decode($result);

        $helloasso_members = $this->helloasso_members;
        foreach ($helloasso_members as $key => $newmember) {
            $date_start_subscription = dol_stringtotime($newmember->order->meta->createdAt); 
            $member = new Adherent($db);
            $membertype = new AdherentType($db);
            $amount = $newmember->initialAmount / 100;
            $dolibarrmembertype = 0;

            // Verify if member_type mapping contain HelloAsso memberId
            if (empty($this->helloasso_member_types[$newmember->tierId])) {
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
                        $dolibarrmembertype = $this->createHelloAssoTypeMember($helloassoformdata, $newlabel);
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

            // Try to find dolibarr member linked to HelloAsso member
            $dolibarrmembertype = $this->helloasso_member_types[$newmember->tierId];
            $sql = "SELECT rowid as id";
            $sql .= " FROM ".MAIN_DB_PREFIX."adherent as a";
            $sql .= " WHERE a.firstname = '".$db->escape($newmember->user->firstName)."'";
            $sql .= " AND a.lastname = '".$db->escape($newmember->user->lastName)."'";
            $sql .= " AND statut = 1";
            $sql .= " AND entity IN (".getEntity($member->element).")";
            if (!empty($this->customfields['email'])) {
                $email = "";
                foreach ($newmember->customFields as $key => $field) {
                    if ($field->name == $this->customfields['email']) {
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
                    if ($member->typeid != $dolibarrmembertype) {
                        $member->typeid = $dolibarrmembertype;
                        $result = $member->update($user);
                        if ($result <= 0) {
                            $this->error = $member->error;
                            $this->errors = array_merge($this->errors, $member->errors);
                            return -3;
                        }
                    }
                } else {
                    // Create in Dolibarr an HelloAsso member
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
                    $date_start_subscription = dol_stringtotime($newmember->order->meta->createdAt); 
                    $date_end_subscription = dol_time_plus_duree($date_start_subscription, $membertype->duration_value, $membertype->duration_unit);
                    if ($jsonmembertype->validityType == "Custom") {
                        $date_start_subscription = dol_stringtotime($jsonmembertype->startDate); 
                        $date_end_subscription = dol_stringtotime($jsonmembertype->endDate);
                    } else {
                        $result = $member->fetch_subscriptions();
                        if ($result <= 0) {
                            $this->error = $member->error;
                            $this->errors = array_merge($this->errors, $member->errors);
                            return -6;
                        }
                        if (!empty($member->last_subscription_date_end)) {
                            $date_start_subscription = $member->last_subscription_date_end; 
                            $date_end_subscription = dol_time_plus_duree($date_start_subscription, $membertype->duration_value, $membertype->duration_unit);
                        }
                    }
                    $subscriptionid = $member->subscription($date_start_subscription, $amount, 0, '', '', '', '', '', $date_end_subscription, $dolibarrmembertype);
                    if ($subscriptionid <= 0) {
                        $this->error = $member->error;
                        $this->errors = array_merge($this->errors, $member->errors);
                        return -6;
                    }

                    // Create new bank payment
                    $bankaccountid = getDolGlobalInt('HELLOASSO_BANK_ACCOUNT_FOR_PAYMENTS');
                    $subscriptioncomplementaryaction = getDolGlobalInt('HELLOASSO_SUBSCRIPTION_COMPLEMENTARYACTIONS');
                    if (isModEnabled('bank') || isModEnabled('invoice')) {
                        switch ($subscriptioncomplementaryaction) {
                            case 1:
                                $subscriptioncomplementaryaction = "bankdirect";
                                break;
                             case 2:
                                $subscriptioncomplementaryaction = (getDolGlobalInt("HELLOASSO_FORM_CREATE_THIRDPARTY") ? "invoiceonly" : "none");
                                break;
                             case 3:
                                $subscriptioncomplementaryaction = (getDolGlobalInt("HELLOASSO_FORM_CREATE_THIRDPARTY") ? "bankviainvoice" : "none");
                                break;
                            default:
                                $subscriptioncomplementaryaction = "none";
                                break;
                        }
                        foreach ($newmember->payments as $key => $payment) {
                            $paymentmethod = "";
                            switch ($payment->paymentMeans) {
                                case 'BankTransfer':
                                    $paymentmethod = "VIR";
                                    break;

                                case 'Check':
                                    $paymentmethod = "CHQ";
                                    break;

                                case 'Cash':
                                    $paymentmethod = "LIQ";
                                    break;

                                default:
                                    $paymentmethod = "CB";
                                    break;
                            }
                            $label = $langs->transnoentitiesnoconv("HelloAssoMemberPaymentLabel", $payment->id);
                            $paymentamount = $payment->amount /100;
                            $result = $member->subscriptionComplementaryActions($subscriptionid, $subscriptioncomplementaryaction, $bankaccountid, $date_start_subscription, $payment->date, $paymentmethod, $label, $paymentamount, '');
                            if ($result <= 0) {
                                $this->error = $member->error;
                                $this->errors = array_merge($this->errors, $member->errors);
                                return -6;
                            }
                        }
                    }
                }
            } else {
                $this->errors[] = $db->lasterror();
                return -7;
            }
            $this->nbPosts++;
            $newdatelastfetch = dol_stringtotime($newmember->order->date);
            if ($newdatelastfetch == dol_get_first_hour($newdatelastfetch)) {
                // Prevent Offline payment to be imported another time
                $newdatelastfetch = dol_time_plus_duree($newdatelastfetch, 1, 's');
            }
            $datelastfetch = max($newdatelastfetch, $datelastfetch);

            if (!$error && count($helloasso_members) == $this->nbPosts) {
                $datetime = $db->idate($datelastfetch, "gmt");
                $res = dolibarr_set_const($db, 'HELLOASSO_DATE_LAST_MEMBER_FETCH', $datetime, 'chaine', 0, '', $conf->entity);
                if ($res <= 0) {
                    $error++;
                }
            }
        }
        return $this->nbPosts;
    }

    /**
     * Get Members from HelloAsso API and set $this->helloasso_members
     * 
     * @param string        $helloasso_date_last_fetch      Date of last member fetch
     * @param int           $dryrun                         if !=0 dryrun, if 0 normal run
     * 
     * @return int          >0 if OK, 0 if noting to do, <0 if KO
     */

    public function helloassoGetMembers($helloasso_date_last_fetch = "", $dryrun = 0)
    {
        global $langs;

        $maxmemberpages = getDolGlobalInt("HELLOASSO_MAX_FORM_PAGINATION_PAGES", 100);
        $pagesize = getDolGlobalInt("HELLOASSO_FORM_PAGINATION_PAGES_SIZE", 20);

        $headers[] = "Authorization: ".ucfirst($this->helloasso_tokens["token_type"])." ".$this->helloasso_tokens["access_token"];
        $headers[] = "Accept: application/json";
        $headers[] = "Content-Type: application/json";

        $assoslug = str_replace('_', '-', dol_string_nospecial(strtolower(dol_string_unaccent($this->organization_slug)), '-'));
        $formslug = str_replace('_', '-', dol_string_nospecial(strtolower(dol_string_unaccent($this->form_slug)), '-'));
        $parambase = '?pageSize='.urlencode($pagesize).'&pageIndex=1&withDetails=true';
        $paramfrom = "";
        if ($helloasso_date_last_fetch != "") {
            $helloasso_date_last_fetch_tms = dol_stringtotime($helloasso_date_last_fetch);
            $datefromtimestamp = dol_get_first_hour($helloasso_date_last_fetch_tms);
            $datefrom = dol_print_date($datefromtimestamp, '%Y-%m-%d %H:%M:%S');
            $paramfrom = "&from=".urlencode($datefrom);
        }
        $param = $parambase.$paramfrom;
        $nbpages = 0;
        $arraymembers = array();
        // Loop to have all pages
        while($nbpages < $maxmemberpages) {
            $urlformemebers = "https://".urlencode($this->helloasso_url)."/v5/organizations/".urlencode($assoslug)."/forms/Membership/".urlencode($formslug).'/items'.$param;
            dol_syslog("Send Get to url=".$urlformemebers.", to get member list, page=".$nbpages+1, LOG_DEBUG);

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
            if (empty($json->data)) {
                break;
            }
            foreach ($json->data as $key => $member) {
                if ($helloasso_date_last_fetch == "") {
                    $arraymembers[] = $member;
                    continue;
                }
                $date_pay_tms = dol_stringtotime($member->order->date);
                if ($date_pay_tms > $helloasso_date_last_fetch_tms) {
                    $arraymembers[] = $member;
                    continue;
                }
                if ($member->payments[0]->type == "Offline") {
                    $memeberdate = dol_stringtotime($member->order->date);
                    $updatedtime = dol_stringtotime($member->order->meta->updatedAt);
                    if ($memeberdate == dol_get_first_hour($updatedtime) && $updatedtime > $helloasso_date_last_fetch_tms) {
                        $arraymembers[] = $member;
                        continue;
                    }
                }
            }
            // No pagination for dryrun
            if (empty($json->pagination->continuationToken) || $dryrun) {
                break;
            }
            $param = $parambase.$paramfrom;
            $param .= "&continuationToken=".urlencode($json->pagination->continuationToken);
            $nbpages++;
        }
        $this->helloasso_members = $arraymembers;
        return count($this->helloasso_members);
    }

    /**
     * Set array of correspondance between HelloAsso and Dolibarr member type
     * 
     * @param   int   $dolibarrmembertype     Id of member type in Dolibarr
     * @param   int   $helloassomembertype    Id of member type in HelloAsso
     * 
     * @return  int   >0 if Ok, <0 if Ko
     */
    public function setHelloAssoTypeMemberMapping($dolibarrmembertype, $helloassomembertype)
    {
        global $langs, $conf;
        $mappingstr = getDolGlobalString("HELLOASSO_TYPE_MEMBER_MAPPING");
        if (empty($mappingstr)) {
            $mappingstr = "[]";
        }
        $mapping = json_decode($mappingstr, true);
        if (!empty($mapping[$helloassomembertype])) {
            $this->error = $langs->trans("ErrorHelloAssoMemberTypeAlreadySet");
            $this->errors[] = $langs->trans("ErrorHelloAssoMemberTypeAlreadySet");
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

     /**
     * Set array of correspondance between HelloAsso custom fields and Dolibarr fields
     * 
     * @param   string   $dolibarrfield          Dolibar field of member object
     * @param   string   $helloassomembertype    HelloAsso custom field name
     * 
     * @return  int   >0 if Ok, <0 if Ko
     */
    public function setHelloAssoCustomFieldMapping($dolibarrfield, $helloassofield)
    {
        global $langs, $conf;
        $mappingstr = getDolGlobalString("HELLOASSO_CUSTOM_FIELD_MAPPING");
        if (empty($mappingstr)) {
            $mappingstr = "[]";
        }
        $mapping = json_decode($mappingstr, true);
        if (!empty($mapping[$dolibarrfield])) {
            $this->error = $langs->trans("ErrorHelloAssoCustomFieldAlreadySet");
            $this->errors[] = $langs->trans("ErrorHelloAssoCustomFieldAlreadySet");
            return -1;
        }
        $mapping[$dolibarrfield] = $helloassofield;
        $mappingstr = json_encode($mapping);
        $res = dolibarr_set_const($this->db, 'HELLOASSO_CUSTOM_FIELD_MAPPING', $mappingstr, 'chaine', 0, '', $conf->entity);
        if ($res <= 0) {
            $this->error = $langs->trans("ErrorHelloAssoAddingCustomField");
            $this->errors[] = $langs->trans("ErrorHelloAssoAddingCustomField");
            return -2;
        }
        $this->customfields = $mapping;
        return 1;
    }

    /**
     * Create HelloAsso member type in Dolibarr database
     * 
     * @param   stdClass    $object     Object with HelloAsso member type data
     * @param   string      $label      Label of new member type in Dolibarr
     * 
     * @return  int   >0 if Ok, <0 if Ko
     */
    public function createHelloAssoTypeMember($object, $label)
    {
        global $user;
        require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
        $db = $this->db;
        $newmembertype = new AdherentType($db);

        $validitytype = $object->validityType;
        $duration_value = "1";
        $duration_unit = "y";
        $subscription = 1;
        switch ($validitytype) {
            case 'Custom':
                $duration_value = num_between_day(dol_stringtotime($object->startDate), dol_stringtotime($object->endDate), 1);
                $duration_unit = "d";
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
        $amount = $object->tiers[0]->price / 100;

        $newmembertype->amount = $amount;
        $newmembertype->subscription = $subscription;
        $newmembertype->duration_value = $duration_value;
        $newmembertype->duration_unit = $duration_unit;
        $newmembertype->label = $label;
        $newmembertype->statut = 1;
        $res = $newmembertype->create($user);
        if ($res <= 0) {
            $this->error = $newmembertype->error;
            $this->errors = array_merge($this->errors, $newmembertype->errors);
            return -1;
        }
        return $res;
    }

    /**
     * Create HelloAsso member in Dolibarr database
     * 
     * @param   stdClass    $newmember     Object with HelloAsso member data
     * @param   int         $membertype    Id of member type in Dolibarr to set to new Dolibarr member
     * 
     * @return  int   >0 if Ok, <0 if Ko
     */
    public function createHelloAssoMember($newmember, $membertype)
    {
        global $user;
        $db = $this->db;
        $customfields = array_flip($this->customfields);
        $createmember = new Adherent($db);

        $createmember->firstname = $newmember->user->firstName;
        $createmember->lastname = $newmember->user->lastName;
        $createmember->typeid = $membertype;
        $createmember->morphy = "phy";
        if (!empty($newmember->customFields) && !empty($this->customfields)) {
            foreach ($newmember->customFields as $key => $field) {
                if (!empty($customfields[$field->name])) {
                    $dolibarkey = $customfields[$field->name];
                    $createmember->$dolibarkey = $field->answer;
                }
            }
        }
        // Login creation for member
        if (empty($createmember->login)) {
            $login = strtolower($newmember->user->firstName).strtolower($newmember->user->lastName);
            $sql = "SELECT COUNT(rowid) as nbmembers";
            $sql .= " FROM ".MAIN_DB_PREFIX."adherent";
            $sql .= " WHERE entity IN (".((int) getEntity($createmember->element)).")";
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
            $createmember->login = $login;
        }

        if (getDolGlobalInt("HELLOASSO_FORM_CREATE_THIRDPARTY")) {
            $newthirdparty = new Societe($db);
            $newthirdparty->name = $newmember->user->firstName ." ". $newmember->user->lastName;
            $newthirdparty->client = 1;
            $newthirdparty->code_client = -1;
            $newthirdpartyid = $newthirdparty->create($user);
            if ($newthirdpartyid <= 0) {
                $this->error = $newthirdparty->error;
                $this->errors = array_merge($this->errors, $newthirdparty->errors);
                return -1;
            }
            $createmember->socid = $newthirdpartyid;
        }

        $res = $createmember->create($user);
        if ($res <= 0) {
            $this->error = $createmember->error;
            $this->errors = array_merge($this->errors, $createmember->errors);
            return -2;
        }
        $res = $createmember->validate($user);
        if ($res <= 0) {
            $this->error = $createmember->error;
            $this->errors = array_merge($this->errors, $createmember->errors);
            return -3;
        }
        return $createmember->id;
    }
}

