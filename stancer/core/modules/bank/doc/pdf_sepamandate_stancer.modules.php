<?php
/* Copyright (C) 2016 Laurent Destailleur <eldy@users.sourceforge.net>
 * Copyright (C) 2020 Josep Lluís Amador  <joseplluis@lliuretic.cat>
 * Copyright (C) 2023 Eric Seigne <eric.seigne@cap-rel.fr>
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
 * or see https://www.gnu.org/
 */

/**
 *	\file       core/modules/bank/doc/pdf_sepamandate_stancer.modules.php
 *	\ingroup    project
 *	\brief      File of class to generate document with template sepamandate
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/bank/modules_bank.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/companybankaccount.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

require_once DOL_DOCUMENT_ROOT.'/core/modules/bank/doc/pdf_sepamandate.modules.php';


/**
 *	Class to generate SEPA mandate
 */
class pdf_sepamandate_stancer extends pdf_sepamandate
{
	public $result;
	public $posxref;
	public $posxlabel;
	public $posxworkload;
	public $posxprogress;
	public $posxdatestart;
	public $posxdateend;
	public $name;
	public $description;
	public $type;
	public $option_logo;



		/**
	 * @var int page_largeur
	 */
	public $page_largeur;

	/**
	 * @var int page_hauteur
	 */
	public $page_hauteur;

	/**
	 * @var array format
	 */
	public $format;

	/**
	 * @var int marge_gauche
	 */
	public $marge_gauche;

	/**
	 * @var int marge_droite
	 */
	public $marge_droite;

	/**
	 * @var int marge_haute
	 */
	public $marge_haute;

	/**
	 * @var int marge_basse
	 */
	public $marge_basse;

	/**
	 * Issuer
	 * @var Societe
	 */
	public $emetteur;

	/**
	 * Dolibarr version of the loaded document
	 * @var string
	 */
	public $version = 'dolibarr';

	/**
	 *	Constructor
	 *
	 *  @param		DoliDB		$db      Database handler
	 */
	public function __construct($db)
	{
		global $conf,$langs,$mysoc;

		// Translations
		$langs->loadLangs(array("main", "bank", "withdrawals", "companies"));

		$this->db = $db;
		$this->name = "sepamandate_stancer";
		$this->description = $langs->transnoentitiesnoconv("SepaMandateForStancer");

		// Page size for A4 format
		$this->type = 'pdf';
		$formatarray=pdf_getFormat();
		$this->page_largeur = $formatarray['width'];
		$this->page_hauteur = $formatarray['height'];
		$this->format = array($this->page_largeur,$this->page_hauteur);
		// Margins are used in arithmetic below and stored in int properties: read them as int.
		$this->marge_gauche=getDolGlobalInt('MAIN_PDF_MARGIN_LEFT', 10);
		$this->marge_droite=getDolGlobalInt('MAIN_PDF_MARGIN_RIGHT', 10);
		$this->marge_haute =getDolGlobalInt('MAIN_PDF_MARGIN_TOP', 10);
		$this->marge_basse =getDolGlobalInt('MAIN_PDF_MARGIN_BOTTOM', 10);

		$this->option_logo = 1; // Display logo FAC_PDF_LOGO


		// Retrieves transmitter
		$this->emetteur=$mysoc;
		if (! $this->emetteur->country_code) {
			$this->emetteur->country_code = substr($langs->defaultlang, -2); // By default if not defined
		}

		// Define column position
		$this->posxref=$this->marge_gauche+1;
		$this->posxlabel=$this->marge_gauche+25;
		$this->posxworkload=$this->marge_gauche+100;
		$this->posxprogress=$this->marge_gauche+130;
		$this->posxdatestart=$this->marge_gauche+150;
		$this->posxdateend=$this->marge_gauche+170;
	}


	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *  Function to create pdf of company bank account sepa mandate
	 *
	 *	@param	CompanyBankAccount	$object   		    Object bank account to generate document for
	 *	@param	    Translate	$outputlangs	    Lang output object
	 *  @param		string		$srctemplatepath	Full path of source filename for generator using a template file
	 *  @param		int			$hidedetails		Do not show line details (not used for this template)
	 *  @param		int			$hidedesc			Do not show desc (not used for this template)
	 *  @param		int			$hideref			Do not show ref (not used for this template)
	 *  @param      null|array  $moreparams         More parameters
	 *	@return	    int         				    1 if OK, <=0 if KO
	 *
	 * Dolibarr 20 widened the parent parameter from CompanyBankAccount to Account, and the
	 * analysers read that recent signature. Keeping CompanyBankAccount here is the accurate
	 * contract: the body reads $rum and $frstrecur, declared on CompanyBankAccount and on
	 * CompanyPaymentMode, but on no version of Account.
	 * @phan-suppress PhanParamSignatureMismatch
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0, $moreparams = null)
	{
        // phpcs:enable
		global $conf, $hookmanager, $langs, $user, $mysoc;
		// print json_encode($object);
		// exit;
		// dol_syslog("stancer sepa pdf object " . json_encode($object));
		// dol_syslog("stancer sepa pdf outputlangs " . json_encode($outputlangs));
		// dol_syslog("stancer sepa pdf srctemplatepath " . json_encode($srctemplatepath));
		// dol_syslog("stancer sepa pdf moreparams " . json_encode($moreparams));

		if (! is_object($outputlangs)) {
			$outputlangs=$langs;
		}
		// Standard Dolibarr fallback above: from here $outputlangs is always a Translate.
		'@phan-var-force Translate $outputlangs';
		// For backward compatibility with FPDF, force output charset to ISO, because FPDF expect text to be encoded in ISO
		if (getDolGlobalString('MAIN_USE_FPDF', '') != '') {
			$outputlangs->charset_output='ISO-8859-1';
		}

		// Load translation files required by the page
		$outputlangs->loadLangs(array("main", "dict", "withdrawals", "companies", "projects", "bills","stancer@stancer"));

		if (! empty($conf->bank->dir_output)) {
			//$nblines = count($object->lines);  // This is set later with array of tasks

			// Definition of $dir and $file
			if ($object->specimen) {
				if (! empty($moreparams['force_dir_output'])) {
					$dir = $moreparams['force_dir_output'];
				} else {
					$dir = $conf->bank->dir_output;
				}
				$file = $dir . "/SPECIMEN.pdf";
			} else {
				$objectref = "";
				if (!empty($object->socid)) {
					$objectref = $object->socid;
					// @phan-suppress-next-line PhanDeprecatedProperty  CompanyPaymentMode, also passed to this template, declares fk_soc and no socid
				} elseif (!empty($object->fk_soc)) {
					// @phan-suppress-next-line PhanDeprecatedProperty  CompanyPaymentMode, also passed to this template, declares fk_soc and no socid
					$objectref = $object->fk_soc;
				}
				if (!empty($object->id)) {
					$objectref .= "-" . $object->id;
				}
				if (! empty($moreparams['force_dir_output'])) {
					$dir = $moreparams['force_dir_output'];
				} else {
					$dir = $conf->bank->dir_output . "/" . $objectref;
				}
				$file = $dir."/".$langs->transnoentitiesnoconv("SepaMandateShort").' '.$objectref."-".dol_sanitizeFileName($object->rum).".pdf";
			}

			if (! file_exists($dir)) {
				if (dol_mkdir($dir) < 0) {
					$this->error=$langs->transnoentities("ErrorCanNotCreateDir", $dir);
					return 0;
				}
			}

			if (file_exists($dir)) {
				// Add pdfgeneration hook
				if (! is_object($hookmanager)) {
					include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
					$hookmanager=new HookManager($this->db);
				}
				$hookmanager->initHooks(array('pdfgeneration'));
				$parameters=array('file'=>$file,'object'=>$object,'outputlangs'=>$outputlangs);
				global $action;
				$reshook=$hookmanager->executeHooks('beforePDFCreation', $parameters, $object, $action);    // Note that $action and $object may have been modified by some hooks

				// @phpstan-ignore-next-line Parameter #1 $format of function pdf_getInstance expects string, array given.
				$pdf=pdf_getInstance($this->format);
				$default_font_size = pdf_getPDFFontSize($outputlangs);	// Must be after pdf_getInstance
				$heightforinfotot = 60;	// Height reserved to output the info and total part
				$heightforfreetext= getDolGlobalInt('MAIN_PDF_FREETEXT_HEIGHT', 5);	// Height reserved to output the free text on last page
				$heightforfooter = $this->marge_basse + (!getDolGlobalString('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS') ? 12 : 22); // Height reserved to output the footer (value include bottom margin)
				if (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS', '') != '') {
					$heightforfooter+= 6;
				}
				// TCPDF only tests the truthiness of $auto, false is strictly equivalent to the historical 0.
				$pdf->SetAutoPageBreak(false, 0);

				if (class_exists('TCPDF')) {
					$pdf->setPrintHeader(false);
					$pdf->setPrintFooter(false);
				}
				$pdf->SetFont(pdf_getPDFFont($outputlangs));

				$pdf->Open();
				$pagenb=0;
				$pdf->SetDrawColor(128, 128, 128);

				$pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
				$pdf->SetSubject($outputlangs->transnoentities("SepaMandate"));
				$pdf->SetCreator("Dolibarr ".DOL_VERSION);
				$pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
				$pdf->SetKeyWords($outputlangs->convToOutputCharset($object->ref)." ".$outputlangs->transnoentities("SepaMandate"));
				if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION', '') != '') {
					$pdf->SetCompression(false);
				}

				$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);   // Left, Top, Right

				// New page
				$pdf->AddPage();
				// TCPDF keeps this flag as is (its empty_string() test only rejects null and ''),
				// then forwards it to SetAutoPageBreak(), which only tests its truthiness:
				// false is strictly equivalent to the historical 0.
				$pdf->setPageOrientation('', false, $heightforfooter + $heightforfreetext);

				$pagenb++;
				$this->_pagehead($pdf, $object, 1, $outputlangs);
				$pdf->SetFont('', '', $default_font_size - 1);
				$pdf->MultiCell(0, 3, '');		// Set interline to 3
				$pdf->SetTextColor(0, 0, 0);

				$tab_top = 50;
				$tab_height = 180;
				$tab_top_newpage = 40;
				$tab_height_newpage = 210;

				// Show notes
				if (! empty($object->note_public)) {
					$pdf->SetFont('', '', $default_font_size - 1);
					$pdf->writeHTMLCell(190, 3, $this->posxref-1, $tab_top-2, dol_htmlentitiesbr((string) $object->note_public), 0, 1);
					$nexY = $pdf->GetY();
					$height_note=$nexY-($tab_top-2);

					// Rect takes a length in 3rd parameter
					$pdf->SetDrawColor(192, 192, 192);
					$pdf->Rect($this->marge_gauche, $tab_top-3, $this->page_largeur-$this->marge_gauche-$this->marge_droite, $height_note+1);

					$tab_height -= $height_note;
					$tab_top = $nexY+6;
				} else {
					$height_note=0;
				}

				$iniY = $tab_top + 7;
				$curY = $tab_top + 7;
				$nexY = $tab_top + 7;

				$posY = $curY;

				$pdf->SetFont('', '', $default_font_size);

				$pdf->line($this->marge_gauche, $posY, $this->page_largeur - $this->marge_droite, $posY);
				$posY+=2;

				$pdf->SetXY($this->marge_gauche, $posY);
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $outputlangs->transnoentitiesnoconv("RUMLong").' ('.$outputlangs->transnoentitiesnoconv("RUM").') : '.$object->rum, 0, 'L');

				$posY=$pdf->GetY();
				$posY+=2;
				$pdf->SetXY($this->marge_gauche, $posY);
				$ics='';
				if (getDolGlobalString('PRELEVEMENT_ICS', '') != '') {
					$ics=getDolGlobalString('PRELEVEMENT_ICS');
				}
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $outputlangs->transnoentitiesnoconv("CreditorIdentifier").' ('.$outputlangs->transnoentitiesnoconv("ICS").') : '.$ics, 0, 'L');

				$posY=$pdf->GetY();
				$posY+=1;
				$pdf->SetXY($this->marge_gauche, $posY);
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $outputlangs->transnoentitiesnoconv("CreditorName").' : '.$mysoc->name, 0, 'L');

				$posY=$pdf->GetY();
				$posY+=1;
				$pdf->SetXY($this->marge_gauche, $posY);
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $outputlangs->transnoentitiesnoconv("Address").' : ', 0, 'L');
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $mysoc->getFullAddress(1), 0, 'L');

				$posY=$pdf->GetY();
				$posY+=3;

				$pdf->line($this->marge_gauche, $posY, $this->page_largeur - $this->marge_droite, $posY);

				$pdf->SetFont('', '', $default_font_size - 1);

				$posY+=8;
				$pdf->SetXY($this->marge_gauche, $posY);
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 8, $outputlangs->transnoentitiesnoconv("SEPALegalTextStancer", $mysoc->name, $mysoc->name) . "\n", 0, 'J');

				$posY=$pdf->GetY();
				$posY+=4;
				$pdf->SetXY($this->marge_gauche, $posY);
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 8, $outputlangs->transnoentitiesnoconv("SEPALegalTextStancerDPO", getDolGlobalString('STANCER_EMAIL_DPO')) . "\n", 0, 'J');

				// Your data form
				$posY=$pdf->GetY();
				$posY+=8;
				$pdf->line($this->marge_gauche, $posY, $this->page_largeur - $this->marge_droite, $posY);
				$posY+=2;

				$pdf->SetFont('', '', $default_font_size);

				$pdf->SetXY($this->marge_gauche, $posY);
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $outputlangs->transnoentitiesnoconv("SEPAFillForm"), 0, 'C');

				$thirdparty=new Societe($this->db);
				if ($object->socid > 0) {
					$thirdparty->fetch($object->socid);
					// @phan-suppress-next-line PhanDeprecatedProperty  CompanyPaymentMode, also passed to this template, declares fk_soc and no socid
				} elseif ($object->fk_soc > 0) {
					// @phan-suppress-next-line PhanDeprecatedProperty  CompanyPaymentMode, also passed to this template, declares fk_soc and no socid
					$thirdparty->fetch($object->fk_soc);
				}

				// Name of the account holder, printed between parentheses next to the customer
				// name. Dolibarr 20 and above fill $owner_name on Account and CompanyBankAccount,
				// while CompanyPaymentMode, also passed to this template by public/sepa-iban.php,
				// only ever fills $proprio, from Dolibarr 15 to 21. Both fields are read through
				// empty() so a property missing on the running version stays silent on PHP 8.
				// @phan-suppress-next-line PhanDeprecatedProperty  $proprio is the only holder field before Dolibarr 20, and the only one filled on CompanyPaymentMode
				$accountOwner = !empty($object->owner_name) ? $object->owner_name : (!empty($object->proprio) ? $object->proprio : '');

				$sepaname = '______________________________________________';
				if ($thirdparty->id > 0) {
					if ($accountOwner === '') {
						// Ordinary case, not a degraded path: the holder field is optional on the
						// core screen Thirdparty > Payment modes, which also builds this mandate
						// because the model is registered as a bankaccount document model.
						dol_syslog("stancer sepa mandate pdf : no account holder name on bank account id=".(empty($object->id) ? 'unknown' : $object->id).", only the thirdparty name is printed on the mandate", LOG_DEBUG);
					}
					$sepaname = $thirdparty->name.($accountOwner !== '' ? ' ('.$accountOwner.')' : '');
				}
				$posY=$pdf->GetY();
				$posY+=3;
				$pdf->SetXY($this->marge_gauche, $posY);
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $outputlangs->transnoentitiesnoconv("SEPAFormYourName").' * : ', 0, 'L');
				$pdf->SetXY(80, $posY);
				// This cell starts at x=80, so its width has to stop at the right margin. Reusing
				// the width of the label column (page 210 - 10 - 10 = 190 on A4) would make TCPDF
				// set a right margin of 210 - 80 - 190 = -60, and a long "name (holder)" value
				// would run past the page edge instead of wrapping. The core template keeps the
				// label width here, so wrapping has never worked there either.
				$pdf->MultiCell($this->page_largeur - $this->marge_droite - 80, 3, $sepaname, 0, 'L');

				$address = '______________________________________________';
				if ($thirdparty->id > 0) {
					$address = $thirdparty->getFullAddress(1);
				}
				$posY=$pdf->GetY();
				$posY+=1;
				$pdf->SetXY($this->marge_gauche, $posY);
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $outputlangs->transnoentitiesnoconv("Address").' : ', 0, 'L');
				$pdf->SetXY(80, $posY);
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $address, 0, 'L');
				if (preg_match('/_____/', $address)) {
					$posY+=6;
					$pdf->SetXY(80, $posY);
					$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $address, 0, 'L');
				}

				$ban = '__________________________________________________';
				if (! empty($object->iban)) {
					$ban = $object->iban;
				}
				$posY=$pdf->GetY();
				$posY+=1;
				$pdf->SetXY($this->marge_gauche, $posY);
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $outputlangs->transnoentitiesnoconv("SEPAFormYourBAN").' * : ', 0, 'L');
				$pdf->SetXY(80, $posY);
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $ban, 0, 'L');

				$bic = '__________________________________________________';
				if (! empty($object->bic)) {
					$bic = $object->bic;
				}
				$posY=$pdf->GetY();
				$posY+=1;
				$pdf->SetXY($this->marge_gauche, $posY);
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $outputlangs->transnoentitiesnoconv("SEPAFormYourBIC").' * : ', 0, 'L');
				$pdf->SetXY(80, $posY);
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $bic, 0, 'L');


				$posY=$pdf->GetY();
				$posY+=1;
				$pdf->SetXY($this->marge_gauche, $posY);
				$txt = $outputlangs->transnoentitiesnoconv("SEPAFrstOrRecur").' * : ';
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $txt, 0, 'L');
				$pdf->Rect(80, $posY, 5, 5);
				$pdf->SetXY(80, $posY);
				if ($object->frstrecur == 'RECUR') {
					$pdf->MultiCell(5, 3, 'X', 0, 'L');
				}
				$pdf->SetXY(86, $posY);
				$txt = $langs->transnoentitiesnoconv("ModeRECUR").'  '.$langs->transnoentitiesnoconv("or");
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $txt, 0, 'L');
				$posY+=6;
				$pdf->Rect(80, $posY, 5, 5);
				$pdf->SetXY(80, $posY);
				if ($object->frstrecur == 'FRST') {
					$pdf->MultiCell(5, 3, 'X', 0, 'L');
				}
				$pdf->SetXY(86, $posY);
				$txt = $langs->transnoentitiesnoconv("ModeFRST");
				$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $txt, 0, 'L');
				if (empty($object->frstrecur)) {
					$posY+=6;
					$pdf->SetXY(80, $posY);
					$txt = '('.$langs->transnoentitiesnoconv("PleaseCheckOne").')';
					$pdf->MultiCell($this->page_largeur - $this->marge_gauche - $this->marge_droite, 3, $txt, 0, 'L');
				}

				$posY=$pdf->GetY();
				$posY+=3;
				$pdf->line($this->marge_gauche, $posY, $this->page_largeur - $this->marge_droite, $posY);
				$posY+=3;


				// Show square
				if ($pagenb == 1) {
					// @phpstan-ignore-next-line Parameter #3 $tab_height of method pdf_sepamandate::_tableau() expects string, (float|int) given.
					$this->_tableau($pdf, $tab_top, $this->page_hauteur - $tab_top - $heightforinfotot - $heightforfreetext - $heightforfooter, 0, $outputlangs, 0, 0);
					$bottomlasttab=$this->page_hauteur - $heightforinfotot - $heightforfreetext - $heightforfooter + 1;
				} else {
					// @phpstan-ignore-next-line Parameter #3 $tab_height of method pdf_sepamandate::_tableau() expects string, (float|int) given.
					$this->_tableau($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforinfotot - $heightforfreetext - $heightforfooter, 0, $outputlangs, 1, 0);
					$bottomlasttab=$this->page_hauteur - $heightforinfotot - $heightforfreetext - $heightforfooter + 1;
				}

				/*var_dump($tab_top);
				var_dump($heightforinfotot);
				var_dump($heightforfreetext);
				var_dump($heightforfooter);
				var_dump($bottomlasttab);*/

				// Affiche zone infos
				$posy=$this->_tableau_info($pdf, $object, $bottomlasttab, $outputlangs);

				/*
				 * Footer of the page
				 */
				// $this->_pagefoot($pdf, $object, $outputlangs, 1);
				// if (method_exists($pdf, 'AliasNbPages')) {
				//     $pdf->AliasNbPages();
				// }

				$pdf->Close();

				$pdf->Output($file, 'F');

				// Add pdfgeneration hook
				if (! is_object($hookmanager)) {
					include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
					$hookmanager=new HookManager($this->db);
				}
				$hookmanager->initHooks(array('pdfgeneration'));
				$parameters=array('file'=>$file,'object'=>$object,'outputlangs'=>$outputlangs);
				global $action;
				$reshook=$hookmanager->executeHooks('afterPDFCreation', $parameters, $this, $action);    // Note that $action and $object may have been modified by some hooks

				if ($reshook < 0) {
					$this->error = $hookmanager->error;
					$this->errors = $hookmanager->errors;
				}

				if (getDolGlobalString('MAIN_UMASK', '') != '') {
					@chmod($file, octdec($conf->global->MAIN_UMASK));
				}

				$this->result = array('fullpath'=>$file);

				return 1; // No error
			} else {
				$this->error=$langs->transnoentities("ErrorCanNotCreateDir", $dir);
				return 0;
			}
		}

		$this->error = $langs->transnoentities("ErrorConstantNotDefined", "DELIVERY_OUTPUTDIR");
		return 0;
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps,PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 *   Show miscellaneous information (payment mode, payment term, ...)
	 *
	 *   @param		TCPDF				$pdf     		Object PDF
	 *   @param		CompanyBankAccount	$object			Object to show
	 *   @param		float		$posy			Y
	 *   @param		Translate	$outputlangs	Langs object
	 *   @return	float
	 */
	protected function _tableau_info(&$pdf, $object, $posy, $outputlangs)
	{
		// phpcs:enable
		global $conf, $mysoc;

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		$diffsizetitle= getDolGlobalString('PDF_DIFFSIZE_TITLE', '1');

		$posy+=$this->_signature_area($pdf, $object, $posy, $outputlangs);

		// $pdf->SetFillColor(255, 255, 255);
		// $pdf->SetXY($this->marge_gauche, $posy+2);
		// $pdf->MultiCell($largcol, $tab_hl, $outputlangs->transnoentitiesnoconv("PleaseCheckIfBankNeedMandateStancer"), 0, 'L', 1);

		return $posy;
	}


	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *	Show area for the customer to sign
	 *
	 *	@param	TCPDF				$pdf           	Object PDF
	 *	@param  CompanyBankAccount	$object         Object invoice
	 *	@param	float				$posy			Position depart
	 *	@param	Translate			$outputlangs	Objet langs
	 *	@return float								Position pour suite
	 */
	protected function _signature_area(&$pdf, $object, $posy, $outputlangs)
	{
		global $conf, $mysoc;
		// phpcs:enable
		$default_font_size = pdf_getPDFFontSize($outputlangs);
		$tab_top = $posy + 8;
		$tab_hl = 4;

		$posx = $this->marge_gauche;
		$pdf->SetXY($posx, $tab_top);

		$pdf->SetFont('', '', $default_font_size - 2);

		//uptosign
		// $pdf->MultiCell(100, 3, $outputlangs->transnoentitiesnoconv("DateOfSignature") . ': '. dol_print_date(dol_now()), 0, 'L', 0);

		//pas nécessaire on passe en signature électronique
		// $pdf->SetXY($this->marge_gauche, $posy+8);
		// $pdf->SetFont('', '', $default_font_size);
		// $pdf->MultiCell(100, 3, $outputlangs->transnoentitiesnoconv("PleaseReturnMandateStancer", (empty($conf->global->STANCER_MAIN_EMAIL) ? $mysoc->email : getDolGlobalString('STANCER_MAIN_EMAIL'))), 0, 'L', 0);
		// $pdf->SetXY($this->marge_gauche, $pdf->GetY()+2);
		// $pdf->SetFont('', '', $default_font_size - $diffsizetitle);
		// $pdf->MultiCell(100, 6, $mysoc->name, 0, 'L', 0);
		// $pdf->MultiCell(100, 6, $outputlangs->convToOutputCharset($mysoc->getFullAddress(1)), 0, 'L', 0);
		// $posy=$pdf->GetY()+2;


		$posx = 120;
		$largcol = ($this->page_largeur - $this->marge_droite - $posx);

		$pdf->SetFillColor(255, 255, 255);
		$pdf->SetXY($posx, $tab_top);
		$pdf->MultiCell($largcol, $tab_hl, $outputlangs->transnoentitiesnoconv("StancerSignature"), 0, 'L');

		//uptosign sign here
		$pdf->SetXY($posx, $tab_top + 4);
		$pdf->SetTextColor(255, 255, 255);
		$pdf->MultiCell($largcol, $tab_hl, "UPTOSIGN_SIGN_TO_HERE", 0, 'L');

		$pdf->SetXY($posx, $tab_top + $tab_hl);
		$pdf->MultiCell($largcol, $tab_hl * 6, '', 0, 'R');

		//uptosign sign here
		$pdf->SetXY($posx-80, $tab_top + 4);
		$pdf->MultiCell($largcol, $tab_hl, "UPTOSIGN_STAMP_SIGN_HERE", 0, 'L');
		$pdf->SetTextColor(0, 0, 0);

		//TODO add UPTOSIGN_STAMP_SIGN_HERE and UPTOSIGN_SIGN_TO_HERE

		return ($tab_hl * 6);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 *  Show top header of page.
	 *
	 *  @param	TCPDF				$pdf     		Object PDF
	 *  @param  CompanyBankAccount	$object     	Object to show
	 *  @param  int	    	$showaddress    0=no, 1=yes
	 *  @param  Translate	$outputlangs	Object lang for output
	 *  @return	float|int					Top shift value, always 0 here, ignored by the caller
	 */
	public function _pagehead(&$pdf, $object, $showaddress, $outputlangs)
	{
		// phpcs:enable
		global $langs,$conf,$mysoc;

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFont('', 'B', $default_font_size + 3);

		$posx=$this->page_largeur-$this->marge_droite-100;
		$posy=$this->marge_haute;

		$pdf->SetXY($this->marge_gauche, $posy);

		// Logo
		$logo=$conf->mycompany->dir_output.'/logos/'.$mysoc->logo;
		//$paramlogo='ONLINE_PAYMENT_LOGO_dolicloud';
		//if (! empty($conf->global->$paramlogo)) $logo=$conf->mycompany->dir_output.'/logos/thumbs/'.$conf->global->$paramlogo;
		if ($mysoc->logo) {
			if (is_readable($logo)) {
				$height=pdf_getHeightForLogo($logo);
				$pdf->Image($logo, $this->marge_gauche, $posy, 0, $height);	// width=0 (auto)
			} else {
				$pdf->SetTextColor(200, 0, 0);
				$pdf->SetFont('', 'B', $default_font_size - 2);
				$pdf->MultiCell(100, 3, $langs->transnoentities("ErrorLogoFileNotFound", $logo), 0, 'L');
				$pdf->MultiCell(100, 3, $langs->transnoentities("ErrorGoToModuleSetup"), 0, 'L');
			}
		} else {
			$pdf->MultiCell(100, 4, $outputlangs->transnoentities($this->emetteur->name), 0, 'L');
		}

		$pdf->SetFont('', 'B', $default_font_size + 3);
		$pdf->SetXY($posx, $posy);
		$pdf->SetTextColor(0, 0, 60);
		$pdf->MultiCell(100, 4, $outputlangs->transnoentities("SepaMandate"), '', 'R');
		$pdf->SetFont('', '', $default_font_size + 2);

		$posy+=6;
		$pdf->SetXY($posx, $posy);
		$pdf->SetTextColor(0, 0, 60);
		$daterum = '__________________';
		if (! empty($object->date_rum)) {
			// @phpstan-ignore-next-line Parameter #3 $tzoutput of function dol_print_date expects string, false given.
			$daterum = dol_print_date($object->date_rum, 'day', false, $outputlangs, true);
		} else {
			// @phpstan-ignore-next-line Parameter #3 $tzoutput of function dol_print_date expects string, false given.
			$daterum = dol_print_date($object->datec, 'day', false, $outputlangs, true); // For old record, the date_rum was not saved.
		}

		$pdf->MultiCell(100, 4, $outputlangs->transnoentities("Date")." : " . $daterum, '', 'R');
		/*$posy+=6;
		$pdf->SetXY($posx,$posy);
		$pdf->MultiCell(100, 4, $outputlangs->transnoentities("DateEnd")." : " . dol_print_date($object->date_end,'day',false,$outputlangs,true), '', 'R');
		*/

		$pdf->SetTextColor(0, 0, 60);

		// Add list of linked objects
		/* Removed: A project can have more than thousands linked objects (orders, invoices, proposals, etc....
		$object->fetchObjectLinked();

		foreach($object->linkedObjects as $objecttype => $objects)
		{
			var_dump($objects);exit;
			if ($objecttype == 'commande')
			{
				$outputlangs->load('orders');
				$num=count($objects);
				for ($i=0;$i<$num;$i++)
				{
					$posy+=4;
					$pdf->SetXY($posx,$posy);
					$pdf->SetFont('','', $default_font_size - 1);
					$text=$objects[$i]->ref;
					if ($objects[$i]->ref_client) $text.=' ('.$objects[$i]->ref_client.')';
					$pdf->MultiCell(100, 4, $outputlangs->transnoentities("RefOrder")." : ".$outputlangs->transnoentities($text), '', 'R');
				}
			}
		}
		*/

		// Dolibarr 20 gave this method a return value, a top shift the caller may add to its
		// own top position, and the core template returns 0 there; up to Dolibarr 19 it was
		// documented void with no return at all. This header shifts nothing and the call site
		// in write_file() ignores the value, so return the same neutral 0 as the core.
		return 0;
	}


	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
}
