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
 * \file       test/phpunit/HelloAssoMemberUtilsTest.php
 * \ingroup    test
 * \brief      PHPUnit test
 * \remarks    To run this script as CLI:  phpunit filename.php
 */

global $conf,$user,$langs,$db;

require_once dirname(__FILE__).'/../../../../dolibarr/htdocs/master.inc.php';
require_once dirname(__FILE__).'/../../../../dolibarr/test/phpunit/CommonClassTest.class.php';

dol_include_once('helloasso/lib/helloasso.lib.php');
dol_include_once('helloasso/class/helloassomemberutils.class.php');
$conf->global->HELLOASSO_TYPE_MEMBER_MAPPING = "[]";
$conf->global->HELLOASSO_CUSTOM_FIELD_MAPPING = "[]";

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class HelloAssoMemberUtilsTest extends CommonClassTest
{

     /**
	 * testcreateHelloAssoTypeMember
	 *
	 * @return	integer
	 *
	 */
    function testcreateHelloAssoTypeMember()
    {
        global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

        $helloassoid = 123456;
        $label = "HELLOASSO_MEMBERTYPE_".$helloassoid;
		$localobject = new HelloAssoMemberUtils($db);

        $helloassomember = new stdClass();
        $helloassomember->validityType = "validityType";
        $priceobj = new stdClass();
        $priceobj->price = 1000;
        $helloassomember->tiers =array($priceobj);
        
        $result = $localobject->createHelloAssoTypeMember($helloassomember, $label);
		print __METHOD__." validityType=".$helloassomember->validityType." price = ".$priceobj->price." label=".$label." result=".$result."\n";
		$this->assertLessThan($result, 0);

        return $result;
    }

    /**
	 * testsetHelloAssoTypeMemberMapping
	 *
	 * @return	void
	 * @depends	testcreateHelloAssoTypeMember
	 * The depends says test is run only if previous is ok
	 */
    function testsetHelloAssoTypeMemberMapping($dolibarrid)
    {
        global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

        $helloassoid = 123456;
		$localobject = new HelloAssoMemberUtils($db);

        $testmembertypes = array($helloassoid => $dolibarrid);

        $result = $localobject->setHelloAssoTypeMemberMapping($dolibarrid, $helloassoid);
		print __METHOD__." dolibarrid=".$dolibarrid." helloassoid=".$helloassoid." result=".$result."\n";
		$this->assertLessThan($result, 0);

        $membertypes = $localobject->helloasso_member_types;
		$this->assertEquals($membertypes, $testmembertypes);

        return 1;
    }

    /**
	 * testsetHelloAssoCustomFieldMapping
	 *
	 * @return	void
	 * @depends	testsetHelloAssoTypeMemberMapping
	 * The depends says test is run only if previous is ok
	 */
    function testsetHelloAssoCustomFieldMapping()
    {
        global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

        $dolibarrfield = "email";
        $helloassofield = "Member email";
        $localobject = new HelloAssoMemberUtils($db);

        $testcustomfields = array($dolibarrfield => $helloassofield);

        $result = $localobject->setHelloAssoCustomFieldMapping($dolibarrfield, $helloassofield);
		print __METHOD__." dolibarrfield=".$dolibarrfield." helloassofield=".$helloassofield." result=".$result."\n";
		$this->assertLessThan($result, 0);

        $customfields = $localobject->customfields;
		$this->assertEquals($customfields, $testcustomfields);
        return 1;
    }

    /**
	 * testcreateHelloAssoMember
	 *
	 * @return	void
	 * @depends	testsetHelloAssoCustomFieldMapping testcreateHelloAssoTypeMember
	 * The depends says test is run only if previous is ok
	 */
    function testcreateHelloAssoMember($dolibarrid)
    {
        global $conf,$user,$langs,$db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

        $localobject = new HelloAssoMemberUtils($db);
        $newmember = new stdClass();
        $newmember->user = new stdClass();

        $newmember->user->firstName = "Test";
        $newmember->user->lastName = "Adherent";

        $customfield = new stdClass();
        $customfield->name = "Member email";
        $customfield->answer = "test.adherent@exemple.com";
        $newmember->customFields = array();
        $newmember->customFields[] = $customfield;

        $result = $localobject->createHelloAssoMember($newmember, $dolibarrid);
		print __METHOD__." result=".$result."\n";
		$this->assertLessThan($result, 0);
        return 1;
    }
}