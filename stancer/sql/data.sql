--
-- initial data
--

DELETE FROM llx_c_email_templates WHERE module='stancer' AND datec=tms;

INSERT INTO llx_c_email_templates (entity,module,type_template,lang,private,fk_user,datec,label,position,active,joinfiles,topic,content)
    VALUES (
        1,'stancer','facture_send','fr_FR', 0,NULL, NOW(),
        'Stancer Facture (SEPA)',
        1,1,1,
        'Votre facture référence __REF__',
        '__MYCOMPANY_NAME__<br />__MYCOMPANY_ADDRESS__<br />__MYCOMPANY_ZIP__ __MYCOMPANY_TOWN__<br /><br />__MYCOMPANY_TOWN__, le __DAY_TEXT__ __DAY__ __MONTH_TEXT__ __YEAR__<br /><br />Bonjour,<br />vous trouverez ci-joint la facture r&eacute;f&eacute;rence __REF__ pour un montant de __AMOUNT_EXCL_TAX_FORMATED__ HT.<br /><br />Sauf erreur le paiement de cette facture est en cours par pr&eacute;l&egrave;vement SEPA, vous n&#39;avez donc rien de particulier &agrave; faire concernant son r&egrave;glement.<br />N&#39;h&eacute;sitez-pas &agrave; revenir vers nous (par r&eacute;ponse de mail) si vous avez la moindre question ou si vous remarquez une erreur dans ce document.<br /><br />Si vous voulez changer de mode de paiement merci de nous en informer rapidement.<br /><br />Cordialement,<br />--<br />__USER_SIGNATURE__');

INSERT INTO llx_c_email_templates (entity,module,type_template,lang,private,fk_user,datec,label,position,active,joinfiles,topic,content)
    VALUES (
        1,'stancer','facture_send','fr_FR', 0,NULL, NOW(),
        'Stancer Facture (SEPA information initiale)',
        1,1,1,
        'Votre facture référence __REF__',
        "__MYCOMPANY_NAME__<br />__MYCOMPANY_ADDRESS__<br />__MYCOMPANY_ZIP__ __MYCOMPANY_TOWN__<br /><br />__MYCOMPANY_TOWN__, le __DAY_TEXT__ __DAY__ __MONTH_TEXT__ __YEAR__<br /><br />Bonjour,<br />vous trouverez ci-joint la facture r&eacute;f&eacute;rence __REF__ pour un montant de __AMOUNT_EXCL_TAX_FORMATED__ HT.<br /><br />Le mode de r&egrave;glement de cette facture est : pr&eacute;l&egrave;vement SEPA. De ce fait ce courrier constitue l&#39;information initiale concernant le montant qui sera pr&eacute;lev&eacute; sur votre compte dans __STANCER_SEPA_DELAIS__ jours conform&eacute;ment &agrave; la r&egrave;glementation.<br />N&#39;h&eacute;sitez-pas &agrave; revenir vers nous (par r&eacute;ponse de mail) si vous avez la moindre question ou si vous remarquez une erreur dans ce document.<br /><br />Si vous voulez changer de mode de paiement merci de nous en informer rapidement.<br /><br />Cordialement,<br />--<br />__USER_SIGNATURE__");

INSERT INTO llx_c_email_templates (entity,module,type_template,lang,private,fk_user,datec,label,position,active,joinfiles,topic,content)
    VALUES (
        1,'stancer','facture_send','fr_FR', 0,NULL, NOW(),
        'Stancer Facture (SEPA Succès)',
        1,1,1,
        'Votre facture référence __REF__',
        '__MYCOMPANY_NAME__<br />__MYCOMPANY_ADDRESS__<br />__MYCOMPANY_ZIP__ __MYCOMPANY_TOWN__<br /><br />__MYCOMPANY_TOWN__, le __DAY_TEXT__ __DAY__ __MONTH_TEXT__ __YEAR__<br /><br />Bonjour,<br />vous trouverez ci-joint la facture r&eacute;f&eacute;rence __REF__ pour un montant de __AMOUNT_EXCL_TAX_FORMATED__ HT sold&eacute;e.<br /><br />Le pr&eacute;l&egrave;vement SEPA est termin&eacute;, vous n&#39;avez rien de particulier &agrave; faire.<br /><br />Cordialement,<br />--<br />__USER_SIGNATURE__');


INSERT INTO llx_c_email_templates (entity,module,type_template,lang,private,fk_user,datec,label,position,active,joinfiles,topic,content)
    VALUES (
        1,'stancer','facture_send','fr_FR', 0,NULL, NOW(),
        'Stancer Facture (ERREUR)',
        1,1,1,
        'Erreur de paiement de votre facture référence __REF__',
        "__MYCOMPANY_NAME__<br />__MYCOMPANY_ADDRESS__<br />__MYCOMPANY_ZIP__ __MYCOMPANY_TOWN__<br /><br />__MYCOMPANY_TOWN__, le __DAY_TEXT__ __DAY__ __MONTH_TEXT__ __YEAR__<br /><br />Bonjour,<br />le paiement de la facture r&eacute;f&eacute;rence __REF__ jointe &agrave; ce mail <strong>est en echec ou erreur</strong> !<br /><br />Merci de nous contacter sans attendre pour nous proposer un autre moyen de paiement.<br />N&#39;h&eacute;sitez-pas &agrave; revenir vers nous (par r&eacute;ponse de mail) si vous avez la moindre question ou si vous remarquez une erreur dans ce document.<br /><br />Si vous voulez changer de mode de paiement merci de nous en informer rapidement.<br /><br />Pour un paiement via carte bancaire et régularisation immédiate de votre situation vous pouvez <a href='__ONLINE_PAYMENT_URL__'>suivre ce lien</a>.<br /><br />Cordialement,<br />--<br />__USER_SIGNATURE__");


INSERT INTO llx_c_email_templates (entity,module,type_template,lang,private,fk_user,datec,label,position,active,joinfiles,topic,content)
    VALUES (
        1,'stancer','facture_send','fr_FR', 0,NULL, NOW(),
        'Stancer Facture (CB)',
        1,1,1,
        'Votre facture référence __REF__',
        "__MYCOMPANY_NAME__<br />__MYCOMPANY_ADDRESS__<br />__MYCOMPANY_ZIP__ __MYCOMPANY_TOWN__<br /><br />__MYCOMPANY_TOWN__, le __DAY_TEXT__ __DAY__ __MONTH_TEXT__ __YEAR__<br /><br />Bonjour,<br />vous trouverez ci-joint la facture r&eacute;f&eacute;rence __REF__ pour un montant de __AMOUNT_EXCL_TAX_FORMATED__ HT.<br /><br />Sauf erreur le paiement par carte bancaire de cette facture est en cours, vous n&#39;avez donc rien de particulier &agrave; faire concernant son r&egrave;glement.<br />N&#39;h&eacute;sitez-pas &agrave; revenir vers nous (par r&eacute;ponse de mail) si vous avez la moindre question ou si vous remarquez une erreur dans ce document.<br /><br />Si vous voulez changer de mode de paiement merci de nous en informer rapidement.<br /><br />Cordialement,<br />--<br />__USER_SIGNATURE__");


INSERT INTO llx_c_email_templates (entity,module,type_template,lang,private,fk_user,datec,label,position,active,joinfiles,topic,content)
    VALUES (
        1,'stancer','order_send','fr_FR', 0,NULL, NOW(),
        'Stancer Commande',
        1,1,1,
        'Votre commande référence __REF__',
        '__MYCOMPANY_NAME__<br />__MYCOMPANY_ADDRESS__<br />__MYCOMPANY_ZIP__ __MYCOMPANY_TOWN__<br /><br />__MYCOMPANY_TOWN__, le __DAY_TEXT__ __DAY__ __MONTH_TEXT__ __YEAR__<br /><br />Bonjour,<br />vous trouverez ci-dessous un rappel de votre commande en cours r&eacute;f&eacute;rence __REF__ pour un montant de __AMOUNT_EXCL_TAX_FORMATED__ HT.<br /><br /><br />N&#39;h&eacute;sitez-pas &agrave; revenir vers nous (par r&eacute;ponse de mail) si vous avez la moindre question ou si vous remarquez une erreur dans ce document.<br /><br />Si vous voulez changer de mode de paiement merci de nous en informer rapidement.<br /><br />Cordialement,<br />--<br />__USER_SIGNATURE__');
