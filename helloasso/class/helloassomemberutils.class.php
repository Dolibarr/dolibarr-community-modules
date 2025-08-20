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
    public $helloasso_members_array;

    public $customfield_array;

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

		return 1;
	}

    /**
     * @return int          0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
     */

    public function helloassoSyncMembersToDolibarr() {
        
        $helloasso_date_last_fetch = "";

        $helloasso_tokens = helloassoDoConnection();

        $res = $this->helloassoGetMembers($helloasso_tokens, $helloasso_date_last_fetch);
        if ($res != 0) {
            return $res;
        }


        return 0;
    }

    /**
     * @param array         $helloasso_tokens               Tokens to connect to HelloAsso API
     * @param string        $helloasso_date_last_fetch      Date of last member fetch
     * 
     * @return int          0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
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
            return 1;
        }
        $result = $ret["content"];
        $json = json_decode($result);
        $this->helloasso_members_array = $json->data;

        return 0;
    }

    public function helloassoPostMembersToDolibarr($helloasso_members_array) {
        $helloasso_members_array = $this->helloasso_members_array;

        foreach ($helloasso_members_array as $key => $newmember) {
            $member = new Adherent($this->db);
        }
        return 0;
    }
}