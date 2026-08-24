<?php
/* Copyright (C) 2017  Laurent Destailleur <eldy@users.sourceforge.net>
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
 */

/**
 * \file        class/Stancer.class.php
 * \ingroup     stancer
 * \brief       This file is a CRUD class file for Stancer (Create/Read/Update/Delete)
 */

// Put here all includes required by your class file
require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
dol_include_once('/stancer/lib/stancer.lib.php');

/**
 * Class for Stancer
 */
class Stancer extends CommonObject
{
	public $socid;
	public $labelStatusShort;
	public $labelStatus;
	public $output;
	public $user_validation;

	/**
	 * @var string ID of module.
	 */
	public $module = 'stancer';

	/**
	 * @var string ID to identify managed object.
	 */
	public $element = 'Stancer';

	/**
	 * @var string Name of table without prefix where object is stored. This is also the key used for extrafields management.
	 */
	public $table_element = '';

	/**
	 * @var int  Does this object support multicompany module ?
	 * 0=No test on entity, 1=Test with field entity, 'field@table'=Test with link by field@table
	 */
	public $ismultientitymanaged = 0;

	/**
	 * @var int  Does object support extrafields ? 0=No, 1=Yes
	 */
	public $isextrafieldmanaged = 0;

	/**
	 * @var string String with name of icon for Stancer. Must be a 'fa-xxx' fontawesome code (or 'fa-xxx_fa_color_size') or 'Stancer@stancer' if picto is file 'img/object_Stancer.png'.
	 */
	public $picto = 'fa-file';


	/**
	 * Constructor
	 *
	 * @param DoliDb $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Action executed by scheduler
	 * CAN BE A CRON TASK. In such a case, parameters come from the schedule job setup field 'Parameters'
	 * Use public function doScheduledJob($param1, $param2, ...) to get parameters
	 *
	 * @return	int			0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
	 */
	public function doScheduledJob()
	{
		global $conf, $langs, $action;
		// print "<p>stancer doScheduledJob, action = $action</p>";
		$lastrun = null;

		// debug
		$savlog = getDolGlobalString('SYSLOG_FILE');
		$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_doScheduledJobStancer.log';

		if (empty($conf->stancer->enabled)) {
			$this->error='Error, Stancer module not enabled';
			return -1;
		}
		if (getDolGlobalString('STANCER_IS_PROD', '0') == '0') {
			$this->error='Error, Stancer module is not in production mode';
			return -1;
		}

		$job = new Cronjob($this->db);
		$res = $job->fetch(0, 'Stancer', 'doScheduledJob');
		if ($res) {
			$lastrun = $job->datelastresult;
			if (empty($lastrun)) {
				$lastrun = $job->datelastrun - (24*3600);
			}
			if (!empty($lastrun)) {
				//lastrun -6 day (time to stancer to make job)
				$lastrun -= (6*24*3600);
			}
		}

		//lancé manuellement -> on force le lastrun a vide
		if ($action == "confirm_execute") {
			$lastrun = null;
		}

		// print "<p>doScheduledJob, lastrun = $lastrun</p>";exit;


		$error = 0;
		$this->output = '';
		$this->error = '';

		dol_syslog(__METHOD__, LOG_DEBUG);

		//prev time for that scheduled job
		$lastTime = '';

		//refresh all payments
		$res = stancerRefreshAllPayments(false, $lastrun);
		$message = "";
		if ($res->error != '') {
			$this->error = $res->error;
			$message = $res->error;
			// $error++;
		} else {
			$message = $res->message;
			$this->output = $res->message;
		}

		//refresh all payments from local dolibarr entries ? or from stancer list ?
		$res = stancerRefreshAllPaymentsFromDolibarr(false, $lastrun);
		if ($res->error != '') {
			$this->error .= $res->error;
			$message .= $res->error;
			// $error++;
		} else {
			$message .= $res->message;
			$this->output .= $res->message;
		}

		// Re-audit recently captured payments to catch late status changes (refused,
		// disputed, refunded after the polling window). Bounded by a sliding window
		// (STANCER_AUDIT_CAPTURED_WINDOW_DAYS). Skipped when disabled (0 days).
		$res = stancerAuditRecentCapturedPayments($lastrun);
		if ($res->error != '') {
			$this->error .= $res->error;
			$message .= $res->error;
		} else {
			$message .= $res->message;
			$this->output .= $res->message;
		}

		if (getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT', '') != '' && !empty($message)) {
			dol_syslog("call stancerSendMail (1)");
			$header = [
				$langs->trans('StancerMailTableHeaderStancer'),
				$langs->trans('StancerMailTableHeaderDolibarr'),
				$langs->trans('StancerMailTableHeaderAmount'),
				$langs->trans('StancerMailTableHeaderMessage'),
				$langs->trans('StancerMailTableHeaderDate'),
			];
			$messageHTML = stancerCSVtoHTML($header, $message);
			stancerSendMail(getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT', ''), $langs->trans('StancerMailSubjectPayment'), $langs->transnoentitiesnoconv('StancerMailPayment', $messageHTML));
		}

		//then all payouts
		$res = stancerRefreshAllPayouts(false, $lastrun);
		if ($res->error != '') {
			$this->error .= $res->error;
			$message = $res->error;
			// $error++;
		} else {
			$message = $res->message;
			$this->output .= $res->message;
		}
		if (getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT', '') != '' && !empty($message)) {
			dol_syslog("call stancerSendMail (2)");
			$header = [
				$langs->trans('StancerMailTableHeaderStancer'),
				$langs->trans('StancerMailTableHeaderDolibarr'),
				$langs->trans('StancerMailTableHeaderAmount'),
				$langs->trans('StancerMailTableHeaderMessage'),
				$langs->trans('StancerMailTableHeaderDate'),
			];
			$messageHTML = stancerCSVtoHTML($header, $message);
			stancerSendMail(getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT', ''), $langs->trans('StancerMailSubjectPayout'), $langs->transnoentitiesnoconv('StancerMailPayout', $messageHTML));
		}
		$conf->global->SYSLOG_FILE = $savlog;
		return $error;
	}


	 /**
	  * process invoices for payment mode
	  *
	  * @param   string $mode                            [$mode description]
	  * @param   int $idpaiement                      [$idpaiement description]
	  * @param   array $invoiceprocessed                [$invoiceprocessed description]
	  * @param   array $invoiceprocessedok              [$invoiceprocessedok description]
	  * @param   array $invoiceprocessedko              [$invoiceprocessedko description]
	  * @param   array $invoiceprocessedinfo            [$invoiceprocessedinfo description]
	  * @param   array $invoiceprocessedwaitingduedate  [$invoiceprocessedwaitingduedate description]
	  * @param   int $maxnbofinvoicetotry             [$maxnbofinvoicetotry description]
	  * @param   int|null $thirdparty_id                   [$thirdparty_id description]
	  * @param   boolean   $isautomatic                     [$isautomatic description]
	  *
	  * @return  int                                  [return description]
	  */
	public function processInvoicesForPaymentMode($mode, $idpaiement, &$invoiceprocessed, &$invoiceprocessedok, &$invoiceprocessedko, &$invoiceprocessedinfo, &$invoiceprocessedwaitingduedate, $maxnbofinvoicetotry = 0, $thirdparty_id = null, $isautomatic = false)
	{
		global $conf;
		$error = 0;
		dol_syslog("stancer processInvoicesForPaymentMode mode=$mode idpaiement=$idpaiement thirdparty_id=$thirdparty_id isautomatic=$isautomatic", LOG_DEBUG);

		$sql = 'SELECT f.rowid, f.fk_soc as socid, sr.rowid as companypaymentmodeid';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'facture as f, '.MAIN_DB_PREFIX.'societe_rib as sr';
		$sql .= ' WHERE sr.fk_soc = f.fk_soc';
		$sql .= " AND f.fk_mode_reglement = ".((int) $idpaiement);
		$sql .= " AND f.paye = 0 AND f.type = " . Facture::TYPE_STANDARD . " AND f.fk_statut = ".Facture::STATUS_VALIDATED;
		$sql .= " AND sr.type = '".$this->db->escape($mode)."'";	// This exclude payment mode of other types
		$sql .= " AND sr.stancer_account <> ''";	// Only if stancer account exists
		$sql .= " AND sr.stancer_object_ref <> ''";	// and stancer payment too
		$sql .= " AND fk_account ='" . $this->db->escape(getDolGlobalString('STANCER_BANK_ACCOUNT_FOR_PAYMENTS', '')) . "'"; //only stancer target
		if (!empty($thirdparty_id) && (int) $thirdparty_id > 0) {
			$sql .= " AND sr.fk_soc = " . ((int) $thirdparty_id);	//for take payment only for one company
		}
		// We must add a sort on sr.default_rib to get the default first, and then the last recent if no default found.
		$sql .= " ORDER BY f.datef ASC, f.rowid ASC, sr.default_rib DESC, sr.tms DESC";	// Lines may be duplicated. Never mind, we will exclude duplicated invoice later.
		// print $sql;exit;

		$resql = $this->db->query($sql);
		if ($resql) {
			$num = $this->db->num_rows($resql);
			dol_syslog("stancer processInvoicesForPaymentMode there is $num lines");

			// Buffer all rows so we can do a grouping pre-pass before the per-invoice loop.
			// Deduplicate by facid keeping the first occurrence (default mandate first thanks to ORDER BY sr.default_rib DESC).
			$rows = array();
			$rowsOrder = array();
			while (($obj = $this->db->fetch_object($resql))) {
				if (empty($obj->rowid)) {
					continue;
				}
				if (isset($rows[$obj->rowid])) {
					continue;
				}
				$rows[$obj->rowid] = $obj;
				$rowsOrder[] = $obj->rowid;
			}

			// Pre-pass: same-day SEPA grouping when enabled and we are in SEPA mode.
			// Groups invoices by (socid, datef, companypaymentmodeid). Groups of size >= 2 are
			// processed via stancerSEPAstartPayGrouped() in a single Stancer API call.
			// Solo entries (group size 1) fall through to the legacy per-invoice loop below.
			if ($mode == "ban" && getDolGlobalString('STANCER_SEPA_GROUP_SAME_DAY')) {
				dol_syslog("stancer processInvoicesForPaymentMode SEPA grouping enabled, building groups");
				$groups = array();
				foreach ($rowsOrder as $facid) {
					$row = $rows[$facid];
					$invTmp = new Facture($this->db);
					if ($invTmp->fetch($facid) <= 0) {
						continue;
					}
					$dayKey = dol_print_date($invTmp->date, '%Y-%m-%d');
					$gkey = $row->socid . '|' . $dayKey . '|' . $row->companypaymentmodeid;
					if (!isset($groups[$gkey])) {
						$groups[$gkey] = array('invoices' => array(), 'facids' => array(), 'companypaymentmodeid' => $row->companypaymentmodeid);
					}
					$groups[$gkey]['invoices'][] = $invTmp;
					$groups[$gkey]['facids'][] = $facid;
				}

				foreach ($groups as $gkey => $g) {
					if (count($g['invoices']) < 2) {
						continue; // solo, leave to legacy loop
					}

					// Anti-doublon: in automatic (cron) mode, abort the whole group as soon
					// as ONE invoice already has a live Stancer payment (any unique_id /
					// order_id / grouped_invoice_ids match returning a status in
					// AUTHORIZED/TO_CAPTURE/CAPTURE_SENT/CAPTURED). Without this, the cron
					// retries the same invoices night after night and the customer is
					// double-billed (incident, 5 invoices x 2 nights).
					if ($isautomatic) {
						$skipGroup = false;
						$blockingInv = null;
						foreach ($g['invoices'] as $invCheck) {
							if (stancerCheckIfPaymentInProgress($invCheck)) {
								$skipGroup = true;
								$blockingInv = $invCheck;
								break;
							}
						}
						if ($skipGroup) {
							dol_syslog("stancer processInvoicesForPaymentMode grouped: invoice " . ($blockingInv ? $blockingInv->ref : '?')
								. " already has a Stancer payment in progress, abort whole group=$gkey ("
								. count($g['invoices']) . " invoices)", LOG_WARNING);
							foreach ($g['facids'] as $idx => $facid) {
								$invoiceprocessed[$facid] = $g['invoices'][$idx]->ref;
								$invoiceprocessedko[$facid] = $g['invoices'][$idx]->ref;
							}
							continue;
						}
					}

					dol_syslog("stancer processInvoicesForPaymentMode grouped pay for key=$gkey, " . count($g['invoices']) . " invoices");
					$this->db->begin();
					$resG = stancerSEPAstartPayGrouped($g['invoices'], (int) $g['companypaymentmodeid'], false);
					if ($resG == 0) {
						foreach ($g['facids'] as $idx => $facid) {
							$invoiceprocessedok[$facid] = $g['invoices'][$idx]->ref;
							$invoiceprocessed[$facid] = $g['invoices'][$idx]->ref;
						}
					} elseif ($resG == 2) {
						foreach ($g['facids'] as $idx => $facid) {
							$invoiceprocessedwaitingduedate[$facid] = $g['invoices'][$idx]->ref;
							$invoiceprocessed[$facid] = $g['invoices'][$idx]->ref;
						}
					} else {
						foreach ($g['facids'] as $idx => $facid) {
							$invoiceprocessedko[$facid] = $g['invoices'][$idx]->ref;
							$invoiceprocessed[$facid] = $g['invoices'][$idx]->ref;
						}
					}
					$this->db->commit();
				}
			}

			$i = 0;
			$num = count($rowsOrder);
			//note: if there is more than one result, all payment mode are used ...
			//that the reason why sort is on default_rib first
			while ($i < $num) {
				$result = -1;
				$facid = $rowsOrder[$i];
				$obj = $rows[$facid];
				if (!empty($obj->rowid)) {
					if (! empty($invoiceprocessed[$facid])) {	// Invoice already processed
						$i++;
						continue;
					}

					dol_syslog("stancer processInvoicesForPaymentMode Loop on invoices, loop cursor no " . $i . " rowid=" . $facid);

					$this->db->begin();

					$invoice = new Facture($this->db);
					$result1 = $invoice->fetch($facid);

					$companypaymentmode = new CompanyPaymentMode($this->db);
					$result2 = $companypaymentmode->fetch($obj->companypaymentmodeid);

					if ($result1 <= 0 || $result2 <= 0) {
						$error++;
						dol_syslog('processInvoicesForPaymentMode Failed to get invoice id = '.$facid.' or companypaymentmode id ='.$obj->companypaymentmodeid, LOG_ERR);
						$this->errors[] = 'Failed to get invoice id = '.$facid.' or companypaymentmode id ='.$obj->companypaymentmodeid;
					} else {
						dol_syslog("stancer processInvoicesForPaymentMode * Process invoice id=".$invoice->id." ref=".$invoice->ref.", mode=$mode");
						dol_syslog("stancer processInvoicesForPaymentMode * result1=".json_encode($result1)." result2=".json_encode($result2));

						if ($isautomatic) {
							$onlycontracts = getDolGlobalString('STANCER_AUTO_SEPA_ONLY_FOR_CONTRACTS', "");
							if ($onlycontracts != "") {
								//is automatic -> only for invoices linked to a contract ?
								$invoice->fetchObjectLinked('', 'contrat');
								if (empty($invoice->linkedObjectsIds)) {
									dol_syslog("stancer processInvoicesForPaymentMode : automatic mode is enabled and there is no contract linked to that invoice, break");
									$invoiceprocessedko[$facid] = $invoice->ref;
									$this->db->rollback();
									$i++;
									continue;
								}
							}
						}

						//TODO check if no payment was done or in progress
						if (stancerCheckIfPaymentInProgress($invoice) && $isautomatic) {
							//erreur do not try to make payment
							dol_syslog("stancer processInvoicesForPaymentMode * Process invoice objid=".$obj->rowid."id=".$invoice->id." CheckIfPaymentInProgress return true and we are in automatic mode then add that invoice to error list");
							$invoiceprocessedko[$facid]=$invoice->ref;
						} else {
							if ($mode == "ban") {
								$result = stancerSEPAstartPay($invoice, false, $obj->companypaymentmodeid);
							}

							if ($mode == "card") {
								//TODO ?
								$result = stancerCBstartPay($invoice, false, $obj->companypaymentmodeid);
							}

							if ($result == 0) {	// No error
								dol_syslog("stancer processInvoicesForPaymentMode * Process invoice id=".$invoice->id." return success, add to ok array");
								$invoiceprocessedok[$facid]=$invoice->ref;
							} elseif ($result == 2) {
								dol_syslog("stancer processInvoicesForPaymentMode * Process invoice id=".$invoice->id." return delay due to SEPA payment mode, add to info array");
								$invoiceprocessedwaitingduedate[$facid]=$invoice->ref;
							} else {
								dol_syslog("stancer processInvoicesForPaymentMode * Process invoice id=".$invoice->id." return error, add to KO array");
								$invoiceprocessedko[$facid]=$invoice->ref;
							}
						}
					}

					$this->db->commit();
					$invoiceprocessed[$facid]=$invoice->ref;
				}

				$i++;
				if ($maxnbofinvoicetotry && $i >= $maxnbofinvoicetotry) {
					break;
				}
			}
		} else {
			$error++;
			$this->error = $this->db->lasterror();
			dol_syslog("stancer processInvoicesForPaymentMode sql error : " . $this->error);
		}

		return $error;
	}


	/**
	 * Action executed by scheduler
	 * Loop on invoice for customer with default payment mode Stancer and take payment/send email.
	 * Unsuspend if it was suspended (done by trigger BILL_CANCEL or BILL_PAYED).
	 * CAN BE A CRON TASK
	 *
	 * @param	int		$maxnbofinvoicetotry    		Max number of payment to do (0 = No max)
	 * @param	int		$noemailtocustomeriferror		1=No email sent to customer if there is a payment error (can be used when error is already reported on screen)
	 * @param	int|null		$thirdparty_id					id of thirdpart
	 * @param	bool	$isautomatic					set true if called by cron task, then do not force payment if it could be a duplicate one
	 *
	 * @return	int			                    		0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
	 */
	public function doTakePaymentStancer($maxnbofinvoicetotry = 0, $noemailtocustomeriferror = 0, $thirdparty_id = null, $isautomatic = true)
	{
		global $conf, $langs, $mysoc;
		// debug
		$savlog = getDolGlobalString('SYSLOG_FILE');
		$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_doTakePaymentStancer.log';

		$langs->load("stancer@stancer");

		include_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		include_once DOL_DOCUMENT_ROOT.'/societe/class/companypaymentmode.class.php';

		$error = 0;
		$this->output = '';
		$this->error = '';

		$invoiceprocessed = array();
		$invoiceprocessedok = array();
		$invoiceprocessedko = array();
		$invoiceprocessedinfoSEPA = array();
		$invoiceprocessedSEPAwaitingduedate = array();
		$invoiceprocessedwaitingduedate = array();

		if (empty($conf->stancer->enabled)) {
			$this->error='Error, Stancer module not enabled';
			return -1;
		}
		if (getDolGlobalString('STANCER_IS_PROD', '0') == '0') {
			$this->error='Error, Stancer module is not in production mode';
			return -1;
		}

		dol_syslog("stancer doTakePaymentStancer maxnbofinvoicetotry=".$maxnbofinvoicetotry." noemailtocustomeriferror=".$noemailtocustomeriferror, LOG_DEBUG);

		//paiements SEPA
		if (getDolGlobalString('STANCER_ENABLE_SEPA', '') != '') {
			$idpaiementpre = dol_getIdFromCode($this->db, 'PRE', 'c_paiement', 'code', 'id', 1);
			if ($idpaiementpre) {
				$this->processInvoicesForPaymentMode('ban', $idpaiementpre, $invoiceprocessed, $invoiceprocessedok, $invoiceprocessedko, $invoiceprocessedinfoSEPA, $invoiceprocessedSEPAwaitingduedate, 0, $thirdparty_id, $isautomatic);
			} else {
				dol_syslog("stancer doTakePaymentStancer there is no id for code=PRE", LOG_DEBUG);
			}
		}

		//paiements CB
		if (getDolGlobalString('STANCER_ENABLE_CB', '') != '') {
			$idpaiementcard = dol_getIdFromCode($this->db, 'CB', 'c_paiement', 'code', 'id', 1);
			if ($idpaiementcard) {
				$this->processInvoicesForPaymentMode('card', $idpaiementcard, $invoiceprocessed, $invoiceprocessedok, $invoiceprocessedko, $invoiceprocessedinfoCB, $invoiceprocessedwaitingduedate, 0, $thirdparty_id, $isautomatic);
			} else {
				dol_syslog("stancer doTakePaymentStancer there is no id for code=CB", LOG_DEBUG);
			}
		}

		$listeFactureOKLink = $listeFactureKOLink = $listeFactureMailLink = $listeFactureInfoLink = $invoiceprocessedSEPAwaitingduedateLink = "";
		$fac = new Facture($this->db);
		foreach ($invoiceprocessedok as $fid) {
			// print $fid;
			if ($fac->fetch(0, $fid)) {
				$listeFactureOKLink .= " " . $fac->getNomUrl(0, '', 0, 0, '', 1);
			}
		}
		foreach ($invoiceprocessedko as $fid) {
			// print $fid;
			if ($fac->fetch(0, $fid)) {
				$listeFactureKOLink .= " " . $fac->getNomUrl(0, '', 0, 0, '', 1);
			}
		}
		foreach ($invoiceprocessedinfoSEPA as $fid) {
			// print $fid;
			if ($fac->fetch(0, $fid)) {
				$listeFactureInfoLink .= " " . $fac->getNomUrl(0, '', 0, 0, '', 1);
			}
		}
		foreach ($invoiceprocessedSEPAwaitingduedate as $fid) {
			// print $fid;
			if ($fac->fetch(0, $fid)) {
				$invoiceprocessedSEPAwaitingduedateLink .= " " . $fac->getNomUrl(0, '', 0, 0, '', 1);
			}
		}

		$message = "";
		if (@count($invoiceprocessedok) == 1) {
			$message = $langs->transnoentitiesnoconv("StancerdoTakePaymentStancerResultMessageA", count($invoiceprocessed), $listeFactureOKLink);
		} elseif (@count($invoiceprocessedok) > 1) {
			$message = $langs->transnoentitiesnoconv("StancerdoTakePaymentStancerResultMessageAP", count($invoiceprocessedok), count($invoiceprocessed), $listeFactureOKLink);
		}
		if (!empty($message)) {
			$message .= "<br />";
		}
		if (@count($invoiceprocessedinfoSEPA) == 1) {
			$message = $langs->transnoentitiesnoconv("StancerdoTakePaymentStancerResultMessageC", $listeFactureInfoLink);
		} elseif (@count($invoiceprocessedinfoSEPA) > 1) {
			$message = $langs->transnoentitiesnoconv("StancerdoTakePaymentStancerResultMessageCP", count($invoiceprocessedinfoSEPA), $listeFactureInfoLink);
		}
		if (!empty($message)) {
			$message .= "<br />";
		}
		if (@count($invoiceprocessedSEPAwaitingduedate) == 1) {
			$message = $langs->transnoentitiesnoconv("StancerdoTakePaymentStancerResultMessageD", $invoiceprocessedSEPAwaitingduedateLink);
		} elseif (@count($invoiceprocessedSEPAwaitingduedate) > 1) {
			$message = $langs->transnoentitiesnoconv("StancerdoTakePaymentStancerResultMessageDP", count($invoiceprocessedSEPAwaitingduedate), $invoiceprocessedSEPAwaitingduedateLink);
		}
		if (!empty($message)) {
			$message .= "<br />";
		}
		if (@count($invoiceprocessedko) == 1) {
			$message = $langs->transnoentitiesnoconv("StancerdoTakePaymentStancerResultMessageB", $listeFactureKOLink);
		} elseif (@count($invoiceprocessedko) > 1) {
			$message = $langs->transnoentitiesnoconv("StancerdoTakePaymentStancerResultMessageBP", count($invoiceprocessedko), $listeFactureKOLink);
		}
		$this->output = $message;

		$conf->global->SYSLOG_FILE = $savlog;
		return $error;
	}

	//2 invoice(s) paid among 2 qualified invoice(s) with a valid Stancer default payment mode processed : FA2303-0003,FA2303-0005 (ran in mode ) (search done on SellYourSaas customers only) - 0 discarded (missing Stancer customer/card id, payment error or other reason)
	// Same sample output, French wording: 2 invoices paid out of 2 qualified ones (Stancer payment mode)


	/**
	 * Action executed by scheduler
	 * Loop on invoice for customer with amount to pay = 0 and status not payed
	 * Then change status to payed
	 * CAN BE A CRON TASK
	 *
	 * @param	int		$maxnbofinvoicetotry    		Max number of payment to do (0 = No max)
	 * @param	int		$noemailtocustomeriferror		1=No email sent to customer if there is a payment error (can be used when error is already reported on screen)
	 * @param	int		$thirdparty_id					id of thirdpart
	 * @param	bool	$isautomatic					set true if called by cron task, then do not force payment if it could be a duplicate one
	 *
	 * @return	int			                    		0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
	 */
	public function doCheckInvoicesPaid($maxnbofinvoicetotry = 0, $noemailtocustomeriferror = 0, $thirdparty_id = null, $isautomatic = true)
	{
		global $conf, $langs, $mysoc, $user;
		// debug
		$savlog = getDolGlobalString('SYSLOG_FILE');
		$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_doCheckInvoicesPaid.log';

		$langs->load("stancer@stancer");


		include_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		include_once DOL_DOCUMENT_ROOT.'/societe/class/companypaymentmode.class.php';

		$error = 0;
		$this->output = '';
		$this->error = '';

		$invoiceprocessed = array();
		$invoiceprocessedok = array();
		$invoiceprocessedko = array();

		if (empty($conf->stancer->enabled)) {
			$this->error='Error, Stancer module not enabled';
			return -1;
		}
		if (getDolGlobalString('STANCER_IS_PROD', '0') == '0') {
			$this->error='Error, Stancer module is not in production mode';
			return -1;
		}

		dol_syslog("stancer doCheckInvoicesPaid maxnbofinvoicetotry=".$maxnbofinvoicetotry." noemailtocustomeriferror=".$noemailtocustomeriferror, LOG_DEBUG);
		$message = $listeFactureOKLink = "";
		$invoiceprocessed = $invoiceprocessedok = [];

		// List of invoices flagged as unpaid
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture WHERE fk_statut='". CommonInvoice::STATUS_VALIDATED . "' AND paye = '0'";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$facture = new Facture($this->db);
				$facture->fetch($obj->rowid);
				$paid = $facture->getSommePaiement() ?? 0;
				$invoiceprocessed[] = $facture->ref;
				if ($paid == $facture->total_ttc) {
					$facture->setPaid($user);
					$listeFactureOKLink .= " " . $facture->getNomUrl(0, '', 0, 0, '', 1);
					$invoiceprocessedok[] = $facture->ref;
				}
			}
		}

		if (@count($invoiceprocessedok) == 1) {
			$message = $langs->transnoentitiesnoconv("StancerdoCheckInvoicesPaidResultMessageA", count($invoiceprocessed), $listeFactureOKLink);
		} elseif (@count($invoiceprocessedok) > 1) {
			$message = $langs->transnoentitiesnoconv("StancerdoCheckInvoicesPaidResultMessageAP", count($invoiceprocessedok), count($invoiceprocessed), $listeFactureOKLink);
		}
		$this->output = $message;

		$conf->global->SYSLOG_FILE = $savlog;
		return $error;
	}

	/**
	 * Action executed by scheduler
	 * Send payment reminder emails to customers with failed/refused/disputed/expired payments.
	 * Uses ActionComm to track which reminders have been sent and when.
	 * CAN BE A CRON TASK
	 *
	 * @return int 0 if OK, <>0 if KO
	 */
	public function doSendPaymentReminders()
	{
		global $conf, $langs, $user;

		$savlog = getDolGlobalString('SYSLOG_FILE');
		$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_doSendPaymentReminders.log';

		$langs->load("stancer@stancer");

		include_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
		include_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';
		dol_include_once('/stancer/class/stancer_payments.class.php');

		$error = 0;
		$this->output = '';
		$this->error = '';

		if (empty($conf->stancer->enabled)) {
			$this->error = 'Error, Stancer module not enabled';
			dol_syslog("stancer doSendPaymentReminders " . $this->error, LOG_ERR);
			return -1;
		}
		if (getDolGlobalString('STANCER_IS_PROD', '0') == '0') {
			$this->error = 'Error, Stancer module is not in production mode';
			dol_syslog("stancer doSendPaymentReminders " . $this->error, LOG_ERR);
			return -1;
		}
		if (!getDolGlobalString('STANCER_PAYMENT_REMINDER_ENABLED')) {
			$this->output = 'Payment reminders are not enabled';
			dol_syslog("stancer doSendPaymentReminders " . $this->output, LOG_DEBUG);
			$conf->global->SYSLOG_FILE = $savlog;
			return 0;
		}

		$mailTemplateFallback = getDolGlobalString('STANCER_PAYMENT_REMINDER_MAILTYPE', '');
		$mailTemplateSoft     = getDolGlobalString('STANCER_PAYMENT_REMINDER_MAILTYPE_SOFT', '');
		$mailTemplateHard     = getDolGlobalString('STANCER_PAYMENT_REMINDER_MAILTYPE_HARD', '');
		$mailTemplateMonthly  = getDolGlobalString('STANCER_PAYMENT_REMINDER_MAILTYPE_MONTHLY', '');
		if (empty($mailTemplateFallback) && empty($mailTemplateSoft) && empty($mailTemplateHard) && empty($mailTemplateMonthly)) {
			$this->error = 'Error, no STANCER_PAYMENT_REMINDER_MAILTYPE* template is configured';
			dol_syslog("stancer doSendPaymentReminders " . $this->error, LOG_ERR);
			return -1;
		}

		// Parse reminder schedule (default: J+7 SOFT, J+14 HARD, then monthly up to 6 months)
		$scheduleStr = getDolGlobalString('STANCER_PAYMENT_REMINDER_SCHEDULE', '7,14,44,74,104,134,164');
		$schedule = array_map('intval', array_filter(explode(',', $scheduleStr), function ($v) {
			return is_numeric(trim($v)) && intval(trim($v)) > 0;
		}));
		sort($schedule);
		if (empty($schedule)) {
			$this->error = 'Error, STANCER_PAYMENT_REMINDER_SCHEDULE is invalid: ' . $scheduleStr;
			dol_syslog("stancer doSendPaymentReminders " . $this->error, LOG_ERR);
			return -1;
		}

		dol_syslog("stancer doSendPaymentReminders schedule=" . implode(',', $schedule) . " maxReminders=" . count($schedule), LOG_DEBUG);

		$remindersSent = array();
		$remindersError = array();
		$invoicesProcessed = array();

		// Fetch failed Stancer payments from the last 6 months
		$sp = new Stancer_payments($this->db);
		$failureStatuses = array(
			Stancer_payments::STATUS_DISPUTED,
			Stancer_payments::STATUS_EXPIRED,
			Stancer_payments::STATUS_FAILED,
			Stancer_payments::STATUS_REFUSED,
		);
		$statusList = implode("','", $failureStatuses);
		// 6-month window: covers the longest reminder horizon (up to 164 days by default)
		$dateLimit = dol_print_date((dol_now() - (3600 * 24 * 180)), '%Y-%m-%d');
		$resList = $sp->fetchAll('ASC', '', 0, 0, array(
			'customsql' => "live_mode = '" . getDolGlobalString('STANCER_IS_PROD') . "' AND status IN ('" . $statusList . "') AND date_creation > '" . $dateLimit . "'"
		));

		if (!is_array($resList)) {
			dol_syslog("stancer doSendPaymentReminders no failed payments found", LOG_DEBUG);
			$this->output = 'No failed payments found';
			$conf->global->SYSLOG_FILE = $savlog;
			return 0;
		}

		dol_syslog("stancer doSendPaymentReminders found " . count($resList) . " failed payment(s)", LOG_DEBUG);

		foreach ($resList as $stancerPayment) {
			// Resolve the linked invoice
			$obj = getObjectFromTag($stancerPayment->unique_id);
			if (empty($obj)) {
				dol_syslog("stancer doSendPaymentReminders cannot resolve object from tag=" . $stancerPayment->unique_id . ", skip", LOG_DEBUG);
				continue;
			}
			if ($obj->element != 'facture') {
				dol_syslog("stancer doSendPaymentReminders object is not an invoice (" . $obj->element . "), skip", LOG_DEBUG);
				continue;
			}

			// Avoid processing the same invoice multiple times (multiple failed payments for the same invoice)
			if (in_array($obj->id, $invoicesProcessed)) {
				continue;
			}
			$invoicesProcessed[] = $obj->id;

			// Skip if invoice is already paid or not in validated status
			if ($obj->paye == 1 || $obj->status != Facture::STATUS_VALIDATED) {
				dol_syslog("stancer doSendPaymentReminders invoice " . $obj->ref . " already paid or not validated, skip", LOG_DEBUG);
				continue;
			}

			// Check if invoice remaining amount is > 0
			$remainToPay = $obj->total_ttc - ($obj->getSommePaiement() ?? 0);
			if ($remainToPay <= 0) {
				dol_syslog("stancer doSendPaymentReminders invoice " . $obj->ref . " nothing left to pay, skip", LOG_DEBUG);
				continue;
			}

			// Count existing reminders via ActionComm
			$actioncomm = new ActionComm($this->db);
			if (floatval(DOL_VERSION) < 15) {
				$existingReminders = $actioncomm->getActions($this->db, $obj->socid, $obj->id, "invoice", " AND code LIKE 'AC_BILL_RELANCE_%_SENTBYMAIL'");
			} else {
				$existingReminders = $actioncomm->getActions($obj->socid, $obj->id, "invoice", " AND code LIKE 'AC_BILL_RELANCE_%_SENTBYMAIL'");
			}
			$reminderCount = is_array($existingReminders) ? count($existingReminders) : 0;

			// All reminders already sent?
			if ($reminderCount >= count($schedule)) {
				dol_syslog("stancer doSendPaymentReminders invoice " . $obj->ref . " all " . count($schedule) . " reminders already sent, skip", LOG_DEBUG);
				continue;
			}

			// Determine when the next reminder should be sent
			$nextReminderDay = $schedule[$reminderCount];
			$failureTimestamp = $stancerPayment->date_creation;
			if (is_string($failureTimestamp)) {
				$failureTimestamp = strtotime($failureTimestamp);
			}
			if (empty($failureTimestamp)) {
				dol_syslog("stancer doSendPaymentReminders invoice " . $obj->ref . " cannot determine failure date, skip", LOG_DEBUG);
				continue;
			}
			$nextReminderTimestamp = $failureTimestamp + ($nextReminderDay * 86400);

			if (dol_now() < $nextReminderTimestamp) {
				dol_syslog("stancer doSendPaymentReminders invoice " . $obj->ref . " reminder " . ($reminderCount + 1) . " not due yet (due " . dol_print_date($nextReminderTimestamp, 'dayhour') . "), skip", LOG_DEBUG);
				continue;
			}

			// Avoid double-send: check if the last reminder was sent less than 1 hour ago
			if ($reminderCount > 0 && is_array($existingReminders)) {
				$lastReminder = end($existingReminders);
				if (is_object($lastReminder) && !empty($lastReminder->datep) && ($lastReminder->datep > (dol_now() - 3600))) {
					dol_syslog("stancer doSendPaymentReminders invoice " . $obj->ref . " last reminder sent less than 1 hour ago, skip", LOG_DEBUG);
					continue;
				}
			}

			// Send the reminder
			$nextReminderNumber = $reminderCount + 1;
			$actionCode = 'BILL_RELANCE_' . $nextReminderNumber . '_SENTBYMAIL';

			// Pick template per step: #1=SOFT, #2=HARD, #3+=MONTHLY. Fallback to global template if not set.
			if ($nextReminderNumber == 1) {
				$mailTemplate = !empty($mailTemplateSoft) ? $mailTemplateSoft : $mailTemplateFallback;
				$reminderLevel = 'SOFT';
			} elseif ($nextReminderNumber == 2) {
				$mailTemplate = !empty($mailTemplateHard) ? $mailTemplateHard : $mailTemplateFallback;
				$reminderLevel = 'HARD';
			} else {
				$mailTemplate = !empty($mailTemplateMonthly) ? $mailTemplateMonthly : $mailTemplateFallback;
				$reminderLevel = 'MONTHLY';
			}

			if (empty($mailTemplate)) {
				dol_syslog("stancer doSendPaymentReminders no template configured for level=$reminderLevel (reminder $nextReminderNumber) on invoice " . $obj->ref . ", skip", LOG_WARNING);
				continue;
			}

			dol_syslog("stancer doSendPaymentReminders sending reminder " . $nextReminderNumber . " (level=$reminderLevel, template=$mailTemplate) for invoice " . $obj->ref . " (actionCode=$actionCode)", LOG_DEBUG);

			$obj->fetch_thirdparty();
			stancerSendInvoiceMailModele(
				$mailTemplate,
				$obj,
				$actionCode,
				0  // forceMail=0: dedup via ActionComm
			);

			$customerName = is_object($obj->thirdparty) && !empty($obj->thirdparty->name) ? $obj->thirdparty->name : '-';
			$remindersSent[] = stancerBuildInvoiceLink($obj) . ' - ' . htmlspecialchars($customerName) . ' (relance ' . $nextReminderNumber . ' - ' . $reminderLevel . ')';
		}

		// Send admin summary
		if (getDolGlobalString('STANCER_PAYMENT_REMINDER_ADMIN_SUMMARY')
			&& getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT', '') != ''
			&& (!empty($remindersSent) || !empty($remindersError))
		) {
			$message = '';
			if (!empty($remindersSent)) {
				$message .= $langs->trans('StancerRemindersSentList') . " :\n<ul>";
				foreach ($remindersSent as $ref) {
					$message .= "<li>" . $ref . "</li>\n";
				}
				$message .= "</ul>\n";
			}
			if (!empty($remindersError)) {
				$message .= $langs->trans('StancerRemindersErrorList') . " :\n<ul>";
				foreach ($remindersError as $ref) {
					$message .= "<li>" . $ref . "</li>\n";
				}
				$message .= "</ul>\n";
			}
			stancerSendMail(
				getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT', ''),
				$langs->trans('StancerMailSubjectReminders'),
				$message
			);
		}

		$this->output = count($remindersSent) . ' reminder(s) sent';
		if (!empty($remindersError)) {
			$this->output .= ', ' . count($remindersError) . ' error(s)';
		}
		dol_syslog("stancer doSendPaymentReminders done: " . $this->output, LOG_DEBUG);

		$conf->global->SYSLOG_FILE = $savlog;
		return $error;
	}

	/**
	 * Action executed by scheduler.
	 * Send the validation email for invoices whose scheduled delay has elapsed,
	 * provided no manual sending happened in the meantime.
	 * CAN BE A CRON TASK
	 *
	 * @return int 0 if OK, <0 if KO
	 */
	public function doSendPendingValidationMails()
	{
		global $conf, $langs, $user;

		$savlog = getDolGlobalString('SYSLOG_FILE');
		$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_doSendPendingValidationMails.log';

		$langs->load("stancer@stancer");

		include_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
		include_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';

		$error = 0;
		$this->output = '';
		$this->error = '';

		if (empty($conf->stancer->enabled)) {
			$this->error = 'Error, Stancer module not enabled';
			dol_syslog("stancer doSendPendingValidationMails " . $this->error, LOG_ERR);
			$conf->global->SYSLOG_FILE = $savlog;
			return -1;
		}

		if (getDolGlobalString('STANCER_AUTO_MAIL_ALL_INVOICES_VALIDATE', '') == '') {
			$this->output = 'STANCER_AUTO_MAIL_ALL_INVOICES_VALIDATE not enabled, nothing to do';
			dol_syslog("stancer doSendPendingValidationMails " . $this->output, LOG_DEBUG);
			$conf->global->SYSLOG_FILE = $savlog;
			return 0;
		}

		$mailtype = getDolGlobalString('STANCER_AUTO_MAIL_ALL_INVOICES_VALIDATE_MAILTYPE', '');
		if (empty($mailtype)) {
			$this->error = 'Error, STANCER_AUTO_MAIL_ALL_INVOICES_VALIDATE_MAILTYPE is not configured';
			dol_syslog("stancer doSendPendingValidationMails " . $this->error, LOG_ERR);
			$conf->global->SYSLOG_FILE = $savlog;
			return -1;
		}

		// Find pending validation mails whose due date has elapsed
		$now = dol_now();
		$sql = "SELECT a.id, a.fk_element, a.socid, a.datep, a.datec";
		$sql .= " FROM " . MAIN_DB_PREFIX . "actioncomm AS a";
		$sql .= " WHERE a.code = 'AC_BILL_VALIDATE_PENDING'";
		$sql .= " AND a.percentage < 100";
		$sql .= " AND a.elementtype = 'invoice'";
		$sql .= " AND a.datep <= '" . $this->db->idate($now) . "'";
		$sql .= " ORDER BY a.datep ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'SQL error: ' . $this->db->lasterror();
			dol_syslog("stancer doSendPendingValidationMails " . $this->error, LOG_ERR);
			$conf->global->SYSLOG_FILE = $savlog;
			return -1;
		}

		$sentCount = 0;
		$cancelledCount = 0;
		$skippedCount = 0;

		while ($row = $this->db->fetch_object($resql)) {
			$pending = new ActionComm($this->db);
			$resFetchPending = $pending->fetch($row->id);
			if ($resFetchPending <= 0) {
				dol_syslog("stancer doSendPendingValidationMails cannot fetch pending actioncomm id=" . $row->id, LOG_ERR);
				continue;
			}

			$facture = new Facture($this->db);
			$resFetchInv = $facture->fetch($row->fk_element);
			if ($resFetchInv <= 0) {
				dol_syslog("stancer doSendPendingValidationMails invoice id=" . $row->fk_element . " not found, mark pending as cancelled", LOG_WARNING);
				$pending->percentage = 100;
				$pending->note_private = (string) ($pending->note_private ?? '') . "\n" . dol_print_date($now, '%Y-%m-%d %H:%M:%S') . " - invoice not found";
				$pending->update($user);
				$cancelledCount++;
				continue;
			}

			// Skip if invoice is no longer in validated state (draft, abandoned)
			if ($facture->status != Facture::STATUS_VALIDATED && $facture->status != Facture::STATUS_CLOSED) {
				dol_syslog("stancer doSendPendingValidationMails invoice " . $facture->ref . " not validated anymore (status=" . $facture->status . "), cancel pending", LOG_DEBUG);
				$pending->percentage = 100;
				$pending->note_private = (string) ($pending->note_private ?? '') . "\n" . dol_print_date($now, '%Y-%m-%d %H:%M:%S') . " - invoice no longer validated (status=" . $facture->status . ")";
				$pending->update($user);
				$cancelledCount++;
				continue;
			}

			// Detect manual send: any AC_BILL_SENTBYMAIL or AC_BILL_VALIDATE_SENTBYMAIL created after the pending was scheduled
			$pendingCreatedAt = is_numeric($pending->datec) ? $pending->datec : strtotime((string) $pending->datec);
			$sqlManual = "SELECT id, code, datec FROM " . MAIN_DB_PREFIX . "actioncomm";
			$sqlManual .= " WHERE elementtype = 'invoice'";
			$sqlManual .= " AND fk_element = " . ((int) $facture->id);
			$sqlManual .= " AND code IN ('AC_BILL_SENTBYMAIL', 'AC_BILL_VALIDATE_SENTBYMAIL', 'AC_BILL_VALIDATE_AUTOSENT')";
			$sqlManual .= " AND datec >= '" . $this->db->idate($pendingCreatedAt) . "'";
			$sqlManual .= " LIMIT 1";
			$resManual = $this->db->query($sqlManual);
			$manualSent = false;
			if ($resManual) {
				$manualRow = $this->db->fetch_object($resManual);
				if ($manualRow) {
					$manualSent = true;
				}
			}

			if ($manualSent) {
				dol_syslog("stancer doSendPendingValidationMails invoice " . $facture->ref . " manually sent in the meantime, cancel pending", LOG_INFO);
				$pending->percentage = 100;
				$pending->note_private = (string) ($pending->note_private ?? '') . "\n" . dol_print_date($now, '%Y-%m-%d %H:%M:%S') . " - " . $langs->trans('StancerBillValidateManuallySent');
				$pending->update($user);
				$skippedCount++;
				continue;
			}

			// Send the auto mail
			dol_syslog("stancer doSendPendingValidationMails sending auto-validation mail for invoice " . $facture->ref . " (modele=$mailtype)", LOG_INFO);
			$facture->fetch_thirdparty();
			$mailRes = stancerSendInvoiceMailModele($mailtype, $facture, 'BILL_VALIDATE_AUTOSENT');
			if ($mailRes > 0) {
				$pending->percentage = 100;
				$pending->note_private = (string) ($pending->note_private ?? '') . "\n" . dol_print_date($now, '%Y-%m-%d %H:%M:%S') . " - auto-sent";
				$pending->update($user);
				$sentCount++;
			} else {
				dol_syslog("stancer doSendPendingValidationMails failed to send mail for invoice " . $facture->ref . ": result=$mailRes", LOG_ERR);
				$error++;
			}
		}
		$this->db->free($resql);

		$this->output = "auto-sent=$sentCount, manually-handled=$skippedCount, cancelled=$cancelledCount, errors=$error";
		dol_syslog("stancer doSendPendingValidationMails done: " . $this->output, LOG_INFO);

		$conf->global->SYSLOG_FILE = $savlog;
		return ($error > 0 ? -1 : 0);
	}


	/**
	 * Record an agenda event linked to a Dolibarr object
	 *
	 * @param	object	$object		Dolibarr object the event is attached to
	 * @param	string	$code		Event code, STANCER_PAY_CB, STANCER_PAY_SEPA, etc.
	 * @param	string	$title		Event label
	 * @param	string	$message	Event content
	 * @return	int					< 0 = KO, 1 = OK
	 */
	public function createEvent($object, $code, $title, $message)
	{
		global $user, $conf, $langs;
		include_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';
		$langs->loadLangs(array("stancer@stancer"));

		$now = dol_now();
		$date = dol_print_date(dol_now(), "%d/%m/%Y %H:%M:%S");
		$evt = new ActionComm($this->db);

		dol_syslog("stancer: Creation d'un evenement", LOG_DEBUG);
		$evt->type_code   = 'AC_OTH_AUTO'; //
		$evt->code        = 'AC_'.strtoupper($code);
		$evt->label = $object->ref . ": " . $title;
		$evt->datep = $now;
		$evt->datef = $now;
		$evt->percentage = -1;
		$evt->socid = $object->socid;
		$evt->contact_id    = 0;
		$evt->authorid    = $user->id; // User saving action
		$evt->userownerid = $user->id;
		$evt->note_private = $date . ": " . trim($langs->trans($message));
		$evt->fk_element = $object->id;
		$evt->elementtype = $object->element;

		$evt->ref_ext = $object->ref;
		$evt->userassigned = $user->id;
		$evt->fulldayevent = 0;
		$evt->transparency = 1;
		$evt->socpeopleassigned = array();
		$evt->userassigned = array();

		$evt->fk_element  = $object->id;
		$evt->elementtype = $object->element;

		$evt->create($user, 1);

		if (!empty($evt->error)) {
			dol_syslog("stancer:  error on create event " . json_encode($evt->errors), LOG_DEBUG);
			setEventMessages($evt->error, $evt->errors, 'errors');
			return -1;
		} else {
			if (!empty($evt)) {
				setEventMessage($langs->trans('StancerEventAdded') . " : " . $title);
				return 1;
			}
		}

		return 0;
	}
}

/**
 * Minimal stand-in for the Stripe module descriptor
 *
 * Some Dolibarr core templates expect a $stripe object to be available when an
 * online payment button is shown. This class provides the few properties they
 * read, so the Stancer payment button can reuse those templates.
 */
class StancerFakeStripe extends CommonObject
{
	public $enabled = true;
	public $module = 'stripe';
	public $element = 'Stripe';
}
