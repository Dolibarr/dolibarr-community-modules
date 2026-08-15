<?php
/* Copyright (C) 2025-2026 InPoint Automation Sp z o.o.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    ksef/class/actions_ksef.class.php
 * \ingroup ksef
 * \brief   KSEF action hooks
 */
class ActionsKSEF
{
    public $db;
    public $error = '';
    public $errors = array();
    public $results = array();
    public $resprints;
    public $priority;


    public function __construct($db)
    {
        $this->db = $db;
        $this->priority = 50;
    }

    /**
     * @brief Adds customer exclusion info and PDF generation button
     * @param $parameters Hook parameters
     * @param $object Invoice object
     * @return int Status code
     * @called_by Dolibarr hook: formBuilddocOptions
     */
    public function formBuilddocOptions($parameters, &$object)
    {
        global $conf, $langs, $db;

        if (empty($conf->ksef) || empty($conf->ksef->enabled)) {
            return 0;
        }

        $contextArray = explode(':', $parameters['context']);
        if (!in_array('invoicecard', $contextArray)) {
            return 0;
        }

        $langs->load("ksef@ksef");
        $out = '';

        // is customer is excluded
        $is_excluded = false;
        if (!empty($conf->global->KSEF_EXCLUDED_CUSTOMERS)) {
            $excluded = array_map('trim', explode(',', $conf->global->KSEF_EXCLUDED_CUSTOMERS));
            $is_excluded = in_array($object->socid, $excluded);
        }

        if ($is_excluded) {
            $out .= '<tr class="oddeven"><td colspan="5" style="padding: 8px;"><span class="opacitymedium"><i class="fa fa-info-circle"></i> ' . $langs->trans('KSEF_CustomerExcludedInfo') . '</span></td></tr>';
            $this->resprints = $out;
            return 1;
        }

        // Display NBP rate info for foreign invoices
        dol_include_once('/ksef/lib/ksef.lib.php');
        dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
        if (class_exists('KsefNbpCurrencyRate')) {
            $this->displayNBPRateInfo($parameters, $object);
        }

        // Check submission status
        dol_include_once('/ksef/class/ksef_submission.class.php');
        $submission = new KsefSubmission($db);
        $has_submission = ($submission->fetchByInvoice($object->id) > 0);

        $has_real_ksef_number = $has_submission &&
            !empty($submission->ksef_number) &&
            strpos($submission->ksef_number, 'OFFLINE') === false &&
            strpos($submission->ksef_number, 'PENDING') === false &&
            strpos($submission->ksef_number, 'ERROR') === false;

        $is_accepted = $has_submission && $submission->status == 'ACCEPTED' && $has_real_ksef_number;
        $is_offline = $has_submission && $submission->status == 'OFFLINE' && !empty($submission->fa3_xml);
        $is_pending_submission = $has_submission && $submission->status == 'PENDING';
        $can_generate_pdf = $is_accepted || $is_offline;

        // Preview
        $is_preview = !$can_generate_pdf
            && in_array($object->statut, array(0, 1, 2))
            && !empty($object->lines)
            && !$is_pending_submission;

        $out .= '<tr class="oddeven"><td colspan="5" style="padding: 4px 8px;">';
        $out .= '<div style="display: flex; align-items: center; gap: 8px;">';
        $out .= '<span style="font-size: 0.9em;">' . $langs->trans('KSEF_GenerateKSeFPDF') . '</span>';

        if ($can_generate_pdf) {
            $out .= '<input type="button" class="button buttongen reposition nomargintop nomarginbottom" ';
            $out .= 'style="background: #a94442; border-color: #8c2e2e; color: #fff; border-radius: 0;" ';
            $out .= 'value="' . $langs->trans('Generate') . '" ';
            $out .= 'onclick="window.location.href=\'' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_generate_pdf&token=' . newToken() . '\'" />';
        } elseif ($is_preview) {
            $out .= '<input type="button" class="button buttongen reposition nomargintop nomarginbottom" ';
            $out .= 'style="background: #a94442; border-color: #8c2e2e; color: #fff; border-radius: 0;" ';
            $out .= 'value="' . $langs->trans('Generate') . '" ';
            $out .= 'onclick="window.location.href=\'' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_generate_pdf&preview=1&token=' . newToken() . '\'" />';
        } else {
            $tooltip = '';
            if (!$has_submission) {
                $tooltip = $langs->trans('KSEF_PDFRequiresSubmission');
            } elseif ($submission->status == 'PENDING') {
                $tooltip = $langs->trans('KSEF_PDFRequiresAccepted');
            } elseif (in_array($submission->status, array('FAILED', 'REJECTED', 'TIMEOUT'))) {
                $tooltip = $langs->trans('KSEF_PDFRequiresAccepted');
            } else {
                $tooltip = $langs->trans('KSEF_PDFRequiresAccepted');
            }
            $out .= '<input type="button" class="button buttongen reposition nomargintop nomarginbottom classfortooltip" ';
            $out .= 'style="background: #a94442; border-color: #8c2e2e; color: #fff; border-radius: 0; opacity: 0.5; cursor: not-allowed;" ';
            $out .= 'value="' . $langs->trans('Generate') . '" title="' . dol_escape_htmltag($tooltip) . '" disabled />';
        }

        $out .= '</div>';
        $out .= '</td></tr>';

//        if (!empty($object->array_options['options_ksef_number'])) {
//            $out .= '<tr class="oddeven"><td colspan="5" style="padding: 8px;"><span class="opacitymedium" style="color: #28a745;"><i class="fa fa-check-circle"></i> ' . $langs->trans('KSEF_IncludedInPDF') . ': <strong>' . htmlspecialchars($object->array_options['options_ksef_number']) . '</strong></span></td></tr>';
//        }

        $this->resprints .= $out;
        return 1;
    }

    /**
     * @brief warning when trying to modify a KSeF-submitted invoice
     * @param $parameters Hook parameters
     * @param $object Invoice object
     * @param $action Current action
     * @param $hookmanager Hook manager
     * @return int Status code
     * @called_by Dolibarr hook formConfirm
     */
    public function formConfirm($parameters, &$object, &$action, $hookmanager)
    {
        global $langs, $conf, $db;

        if (!in_array('invoicecard', explode(':', $parameters['context'] ?? ''))) {
            return 0;
        }

        if (empty($conf->ksef) || empty($conf->ksef->enabled)) {
            return 0;
        }

        if ($action != 'modif' && $action != 'confirm_modif') {
            return 0;
        }

        if (empty($object) || $object->element != 'facture') {
            return 0;
        }

        dol_include_once('/ksef/class/ksef_submission.class.php');
        $submission = new KsefSubmission($db);

        if ($submission->fetchByInvoice($object->id) <= 0) {
            return 0;
        }

        $langs->load("ksef@ksef");

        $warning_msg = '';
        $is_offline = !empty($submission->offline_mode);
        $has_ksef_number = !empty($submission->ksef_number) && strpos($submission->ksef_number, 'OFFLINE') === false;

        if ($has_ksef_number) {
            $warning_msg = '<div class="warning" style="margin-bottom: 15px; padding: 10px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;">';
            $warning_msg .= '<i class="fas fa-exclamation-triangle" style="color: #856404;"></i> ';
            $warning_msg .= '<strong>' . $langs->trans('KSEF_ModifyWarningTitle') . '</strong><br>';
            $warning_msg .= $langs->trans('KSEF_ModifyWarningKSeF', $submission->ksef_number);
            $warning_msg .= '</div>';
        } elseif ($is_offline) {
            $warning_msg = '<div class="warning" style="margin-bottom: 15px; padding: 10px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;">';
            $warning_msg .= '<i class="fas fa-exclamation-triangle" style="color: #856404;"></i> ';
            $warning_msg .= '<strong>' . $langs->trans('KSEF_ModifyWarningTitle') . '</strong><br>';
            $warning_msg .= $langs->trans('KSEF_ModifyWarningOffline');
            if (!empty($submission->offline_deadline)) {
                $warning_msg .= '<br><small>' . $langs->trans('KSEF_OfflineDeadline') . ': ' . dol_print_date($submission->offline_deadline, 'dayhour') . '</small>';
            }
            $warning_msg .= '</div>';
        } elseif ($submission->status == 'FAILED') {
            $warning_msg = '<div class="info" style="margin-bottom: 15px; padding: 10px; background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 4px;">';
            $warning_msg .= '<i class="fa fa-info-circle" style="color: #0c5460;"></i> ';
            $warning_msg .= $langs->trans('KSEF_ModifyInfoFailed');
            $warning_msg .= '</div>';
        }

        if (!empty($warning_msg)) {
            $this->resprints = $warning_msg;
            return 1;
        }

        return 0;
    }


    /**
     * @brief Adds "Validate and Upload" / "Upload to KSeF" buttons to invoice card
     * @param $parameters Hook parameters
     * @param $object Invoice object
     * @param $action Current action
     * @param $hookmanager Hook manager
     * @return int Status code
     * @called_by Dolibarr hook: addMoreActionsButtons
     * @calls ksefIsCustomerExcluded()
     */
    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        dol_include_once('/ksef/lib/ksef.lib.php');
        dol_include_once('/ksef/class/ksef_submission.class.php');
        global $langs, $conf, $user, $db, $form;

        // Validate & Add Payment
        if ($parameters['currentcontext'] == 'invoicesuppliercard' && !empty($object) && !empty($object->id)) {
            if (!empty($conf->ksef) && !empty($conf->ksef->enabled)) {
                $ksefNumber = $object->array_options['options_ksef_number'] ?? '';
                if (!empty($ksefNumber) && $object->statut == 0 && !empty($user->rights->fournisseur->facture->creer)) {
                    dol_include_once('/ksef/class/ksef_incoming.class.php');
                    $incoming = new KsefIncoming($db);
                    if ($incoming->fetchBySupplierInvoice($object->id) > 0) {
                        $hasFullPaymentData = ($incoming->payment_status === 'paid'
                            && !empty($incoming->payment_date)
                            && !empty($incoming->payment_method));
                        if ($hasFullPaymentData) {
                            $langs->load("ksef@ksef");
                            $methodLabel = ksefGetPaymentMethodLabel($incoming->payment_method);
                            $tooltip = dol_escape_htmltag(price($incoming->total_gross, 0, $langs, 0, -1, -1, $incoming->currency)
                                . ' - ' . dol_print_date($incoming->payment_date, 'day')
                                . ' - ' . $methodLabel);
                            print '<a class="butAction classfortooltip" style="background-color: #28a745; color: white; font-weight: bold;"'
                                . ' title="' . $tooltip . '"'
                                . ' href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_confirm_payment&token=' . newToken() . '">'
                                . $langs->trans('KSEF_ValidateAndPay') . '</a>';
                        }
                    }
                }
            }
            return 0;
        }

        if ($parameters['currentcontext'] != 'invoicecard' || empty($object) || empty($object->id) || $object->element != 'facture') {
            return 0;
        }

        if (empty($conf->ksef) || empty($conf->ksef->enabled) || empty($user->rights->facture->creer)) {
            return 0;
        }

        $langs->load("ksef@ksef");

        if (ksefIsCustomerExcluded($object->socid)) {
            return 0;
        }

        $button_color = !empty($conf->global->KSEF_BUTTON_COLOR) ? $conf->global->KSEF_BUTTON_COLOR : '#dc3545';
        $button_style = 'style="background-color: ' . $button_color . '; color: white; font-weight: bold;"';
        $retry_style = 'style="background-color: #ffc107; color: #212529; font-weight: bold;"';
        $offline_style = 'style="background-color: #28a745; color: white; font-weight: bold;"';

        $submission = new KsefSubmission($db);
//        $has_submission = ($submission->fetchByInvoice($object->id) > 0);
        $has_submission = (
            $submission->fetchByInvoice($object->id) > 0
            && !empty($submission->status)
        );

        $is_accepted = $has_submission && $submission->status == 'ACCEPTED';
        $is_pending = $has_submission && $submission->status == 'PENDING';
        $is_failed = $has_submission && in_array($submission->status, array('FAILED', 'REJECTED', 'TIMEOUT'));
        $is_offline = $has_submission && !empty($submission->offline_mode);
        $has_ksef_number = $has_submission && !empty($submission->ksef_number) &&
            strpos($submission->ksef_number, 'OFFLINE') === false &&
            strpos($submission->ksef_number, 'PENDING') === false &&
            strpos($submission->ksef_number, 'ERROR') === false;

        // Latarnia warning
        dol_include_once('/ksef/class/ksef_latarnia.class.php');
        $latarnia_cached = KsefLatarnia::getCachedStatus();
        if (in_array($latarnia_cached['status'], array('MAINTENANCE', 'FAILURE', 'TOTAL_FAILURE'))) {
            $latarnia_msg = '';
            if (!empty($latarnia_cached['messages'])) {
                $lmsg = $latarnia_cached['messages'][0];
                $lmsg_start = !empty($lmsg['start']) ? dol_print_date(strtotime($lmsg['start']), 'dayhour') : '';
                if ($latarnia_cached['status'] === 'MAINTENANCE') {
                    $latarnia_msg = $langs->trans('KSEF_MaintenanceOngoing', $lmsg_start);
                } elseif ($latarnia_cached['status'] === 'TOTAL_FAILURE') {
                    $latarnia_msg = $langs->trans('KSEF_SystemDownTotalFailure', $lmsg_start);
                } else {
                    $latarnia_msg = $langs->trans('KSEF_SystemDownFailure', $lmsg_start);
                }
            }
            print '<div class="warning" style="margin-bottom: 10px; padding: 8px 12px; border-radius: 4px;">';
            print '<i class="fas fa-signal" style="margin-right: 5px;"></i> ';
            print '<strong>' . $langs->trans('KSEF_SystemStatus') . ':</strong> ';
            print ksefGetLatarniaStatusBadge($latarnia_cached['status']);
            if (!empty($latarnia_msg)) {
                print ' - ' . $latarnia_msg;
            }
            print '<br><small>' . $langs->trans('KSEF_SystemDownWarning') . '</small>';
            print '</div>';
        } elseif ($latarnia_cached['status'] === 'UNREACHABLE') {
            print '<div class="info" style="margin-bottom: 10px; padding: 8px 12px; border-radius: 4px; background: #d1ecf1; border: 1px solid #bee5eb;">';
            print '<i class="fas fa-question-circle" style="margin-right: 5px; color: #0c5460;"></i> ';
            print $langs->trans('KSEF_LatarniaUnreachableWarning');
            print '</div>';
        }

        static $spinner_added = false;
        if (!$spinner_added) {
            print '<style>
            @keyframes ksef-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
            #ksef-processing-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 9999; justify-content: center; align-items: center; }
            #ksef-processing-overlay.active { display: flex; }
            .ksef-processing-box { background: white; padding: 30px 40px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3); text-align: center; min-width: 300px; }
            .ksef-spinner-large { display: inline-block; width: 50px; height: 50px; border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; animation: ksef-spin 1s linear infinite; margin: 0 auto 20px; }
            .ksef-processing-text { font-size: 16px; font-weight: bold; color: #333; margin-bottom: 10px; }
            .ksef-processing-subtext { font-size: 14px; color: #666; }
        </style>';

            print '<script>
            function ksefShowSpinner(event, button, formId) {
                event.preventDefault();
                var overlay = document.getElementById("ksef-processing-overlay");
                if (!overlay) {
                    overlay = document.createElement("div");
                    overlay.id = "ksef-processing-overlay";
                    overlay.innerHTML = \'<div class="ksef-processing-box"><div class="ksef-spinner-large"></div><div class="ksef-processing-text">\' + button.getAttribute("data-processing-text") + \'</div><div class="ksef-processing-subtext">' . dol_escape_js($langs->trans("PleaseWait")) . '</div></div>\';
                    document.body.appendChild(overlay);
                }
                var textEl = overlay.querySelector(".ksef-processing-text");
                if (textEl && button.getAttribute("data-processing-text")) textEl.textContent = button.getAttribute("data-processing-text");
                overlay.classList.add("active");
                if (formId) {
                    setTimeout(function() { document.getElementById(formId).submit(); }, 100);
                } else {
                    setTimeout(function() { window.location.href = button.href; }, 100);
                }
                return false;
            }
        </script>';
            $spinner_added = true;
        }

        if ($object->statut == 0 && !empty($object->lines) && !getDolGlobalString('KSEF_DISABLE_VALIDATE_AND_UPLOAD')) {
            $validate_action = getDolGlobalInt('KSEF_CONFIRM_BEFORE_UPLOAD', 1) ? 'ksef_validate_and_preview' : 'ksef_validate_and_submit';
            print '<a class="butAction" ' . $button_style . ' href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=' . $validate_action . '&token=' . newToken() . '" data-processing-text="' . $langs->trans('KSEF_ValidatingAndSubmitting') . '..." onclick="return ksefShowSpinner(event, this);">' . $langs->trans('KSEF_ValidateAndUpload') . '</a>';
            return 0;
        }

        if (in_array($object->statut, array(1, 2))) {
            if ($is_accepted && $has_ksef_number) {
                // invoice already submitted to KSeF
                $modifyTooltip = dol_escape_htmltag($langs->trans('KSEF_CannotModifySubmitted'));
                print '<script type="text/javascript">
                jQuery(document).ready(function() {
                    jQuery("a.butAction").filter(function() {
                        return jQuery(this).attr("href") && jQuery(this).attr("href").indexOf("action=modif") !== -1;
                    }).each(function() {
                        jQuery(this).replaceWith(\'<span class="butActionRefused classfortooltip" title="' . $modifyTooltip . '">\' + jQuery(this).text() + \'</span>\');
                    });
                });
                </script>';

                // "Create correction" button
                if ($object->type == Facture::TYPE_STANDARD || $object->type == Facture::TYPE_REPLACEMENT) {
                    $objectidnext = $object->getIdReplacingInvoice('validated');
                    if (empty($objectidnext)) {
                        print '<a class="butAction" href="' . DOL_URL_ROOT . '/compta/facture/card.php?action=create&socid=' . $object->socid . '&type=1&fac_replacement=' . $object->id . '">'
                            . $langs->trans('KSEF_CreateCorrection') . '</a>';
                    }
                }

                return 0;
            }

            if ($is_pending) {
                print '<span class="butActionRefused classfortooltip" title="' . $langs->trans('KSEF_SubmissionInProgress') . '">' . $langs->trans('KSEF_UploadToKSEF') . ' <i class="fa fa-spinner fa-spin"></i></span>';
                return 0;
            }

            $is_offline_status = $has_submission && $submission->status == 'OFFLINE';

            if ($is_offline_status) {
                $deadline_passed = !empty($submission->offline_deadline) && ksefIsDeadlinePassed($submission->offline_deadline);
                if ($deadline_passed) {
                    print '<span class="butActionRefused classfortooltip" style="background-color: #dc3545; color: white;" title="' . $langs->trans('KSEF_OfflineDeadlinePassed') . '">';
                    print '<i class="fas fa-exclamation-triangle"></i> ' . $langs->trans('KSEF_DeadlinePassed') . '</span>';
                } else {
                    print '<a class="butAction" ' . $button_style . ' href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_retry_online&token=' . newToken() . '" data-processing-text="' . $langs->trans('KSEF_SubmittingToKSEF') . '..." onclick="return ksefShowSpinner(event, this);">';
                    print '<i class="fas fa-cloud-upload-alt"></i> ' . $langs->trans('KSEF_SubmitOnline') . '</a>';
                    if (!empty($submission->offline_deadline)) {
                        $hours_remaining = ($submission->offline_deadline - dol_now()) / 3600;
                        if ($hours_remaining < 8) {
                            print ' <span class="badge badge-warning" style="margin-left: 5px;"><i class="fas fa-clock"></i> ' . $langs->trans('KSEF_HoursRemaining', round($hours_remaining)) . '</span>';
                        }
                    }
                }
                return 0;
            }

            $offline_cert_check = ksefIsOfflineCertificateConfigured();

            if ($is_failed) {
                if (!empty($submission->error_message)) {
                    $error_display = dol_escape_htmltag(dol_trunc($submission->error_message, 80));
                    if (!empty($submission->error_code)) {
                        dol_include_once('/ksef/class/ksef_client.class.php');
                        $client = new KsefClient($db);
                        $error_desc = $client->getErrorDescription($submission->error_code);
                        $error_display = "Error {$submission->error_code}: " . dol_escape_htmltag($error_desc);
                    }
                }

                print '<a class="butAction" ' . $retry_style . ' href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_retry&token=' . newToken() . '" data-processing-text="' . $langs->trans('KSEF_RetryingSubmission') . '..." onclick="return ksefShowSpinner(event, this);" title="' . ($error_display ?? '') . '"><i class="fas fa-sync-alt"></i> ' . $langs->trans('KSEF_RetrySubmission') . '</a>';

                if ($offline_cert_check['configured']) {
                    print '<a class="butAction" ' . $offline_style . ' href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_create_offline&token=' . newToken() . '" data-processing-text="' . $langs->trans('KSEF_CreatingOfflineInvoice') . '..." onclick="return ksefShowSpinner(event, this);"><i class="fas fa-file-alt"></i> ' . $langs->trans('KSEF_CreateOfflineInvoice') . '</a>';
                } else {
                    print '<span class="butActionRefused classfortooltip" title="' . $langs->trans('KSEF_OfflineCertificateRequired') . '"><i class="fas fa-file-alt"></i> ' . $langs->trans('KSEF_CreateOfflineInvoice') . '</span>';
                }
                return 0;
            }

            if (!$has_submission) {
                // Modify button for correction invoices not yet submitted to KSeF
                if ($object->type == Facture::TYPE_REPLACEMENT && $object->statut == Facture::STATUS_VALIDATED) {
                    $usercancreate = $user->hasRight('facture', 'creer');
                    $usercanunvalidate = (!getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && !empty($usercancreate))
                        || (getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && $user->hasRight('facture', 'invoice_advance', 'unvalidate'));
                    $objectidnext = $object->getIdReplacingInvoice('validated');

                    if (empty($objectidnext)) {
                        $modifyUrl = $_SERVER['PHP_SELF'] . '?facid=' . $object->id . '&action=modif&token=' . newToken();
                        $params = array('attr' => array('class' => 'classfortooltip', 'title' => ''));
                        if ($usercanunvalidate) {
                            unset($params['attr']['title']);
                            print dolGetButtonAction($langs->trans('Modify'), '', 'default', $modifyUrl, '', true, $params);
                        } else {
                            $params['attr']['title'] = $langs->trans('NotEnoughPermissions');
                            print dolGetButtonAction($langs->trans('Modify'), '', 'default', $modifyUrl, '', false, $params);
                        }
                    }
                }

                $upload_action = getDolGlobalInt('KSEF_CONFIRM_BEFORE_UPLOAD', 1) ? 'ksef_pre_submit' : 'ksef_submit';
                print '<a class="butAction" ' . $button_style . ' href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=' . $upload_action . '&token=' . newToken() . '" data-processing-text="' . $langs->trans('KSEF_SubmittingToKSEF') . '..." onclick="return ksefShowSpinner(event, this);">' . $langs->trans('KSEF_UploadToKSEF') . '</a>';
            }

            return 0;
        }

        return 0;
    }

    /**
     * @brief Handles KSeF submission actions (submit, validate_and_submit, download_xml, download_upo)
     * @param $parameters Hook parameters
     * @param $object Invoice object
     * @param $action Current action
     * @param $hookmanager Hook manager
     * @return int Status code
     * @called_by Dolibarr hook: doActions
     * @calls KSEF::submitInvoice(), ksefUpdateInvoiceExtrafields(), handleSubmissionError()
     */
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        dol_include_once('/ksef/class/ksef_service.class.php');
        dol_include_once('/ksef/class/ksef_submission.class.php');
        dol_include_once('/ksef/lib/ksef.lib.php');

        global $conf, $user, $langs, $db;

        $currentcontext = $parameters['currentcontext'] ?? '';

        // GUS lookup
        if ($currentcontext === 'thirdpartycard' && $action === 'ksef_gus_lookup') {
            $this->handleGusLookup();
        }

        // Handle per-invoice override
        if ($currentcontext === 'invoicenote' && $action === 'ksef_set_note_override') {
            if (is_object($object) && !empty($object->id) && $object->element === 'facture') {
                $val = GETPOST('ksef_override_value', 'alpha');
                if (!in_array($val, array('', 'simple_stopka', 'simple_dodatkowy', 'keyvalue_dodatkowy', 'disabled'), true)) {
                    $val = '';
                }
                if (!isset($object->array_options) || empty($object->array_options)) {
                    if (method_exists($object, 'fetch_optionals')) $object->fetch_optionals();
                }
                $object->array_options['options_ksef_dodatkowy_opis_mode'] = $val;
                $res = $object->insertExtraFields();
                if ($res >= 0) {
                    setEventMessages($langs->trans('KSEF_DODATKOWY_OPIS_OVERRIDE_SAVED'), null, 'mesgs');
                } else {
                    setEventMessages($object->error ?: 'Error saving override', $object->errors, 'errors');
                }
                $action = '';
            }
        }

        // Save correction reason/type
        if ($currentcontext === 'invoicecard' && $action === 'ksef_save_correction_details') {
            if (is_object($object) && !empty($object->id) && $object->element === 'facture'
                && ($object->type == Facture::TYPE_CREDIT_NOTE || $object->type == Facture::TYPE_REPLACEMENT)) {
                $langs->load("ksef@ksef");

                // Block saving if invoice is already accepted or offline
                dol_include_once('/ksef/class/ksef_submission.class.php');
                $saveSubmission = new KsefSubmission($db);
                $saveHasSub = ($saveSubmission->fetchByInvoice($object->id) > 0);
                $saveIsAccepted = $saveHasSub && $saveSubmission->status == KsefSubmission::STATUS_ACCEPTED
                    && !empty($saveSubmission->ksef_number)
                    && strpos($saveSubmission->ksef_number, 'OFFLINE') === false
                    && strpos($saveSubmission->ksef_number, 'PENDING') === false
                    && strpos($saveSubmission->ksef_number, 'ERROR') === false;
                $saveIsOffline = $saveHasSub && $saveSubmission->status == KsefSubmission::STATUS_OFFLINE
                    && !empty($saveSubmission->fa3_xml);
                if ($saveIsAccepted || $saveIsOffline) {
                    setEventMessages($langs->trans('KSEF_CorrectionDetailsLocked'), null, 'warnings');
                    $action = '';
                    return 0;
                }

                if (!isset($object->array_options) || empty($object->array_options)) {
                    if (method_exists($object, 'fetch_optionals')) $object->fetch_optionals();
                }

                $preset = GETPOST('ksef_correction_reason_preset', 'alphanohtml');
                if ($preset === 'custom') {
                    $reason = trim(GETPOST('ksef_correction_reason_custom', 'alphanohtml'));
                } else {
                    $reason = trim($preset);
                }
                $corrType = GETPOST('ksef_correction_type', 'int');
                if (!in_array($corrType, array(1, 2, 3), false)) {
                    $corrType = '';
                }

                $object->array_options['options_ksef_correction_reason'] = $reason;
                $object->array_options['options_ksef_correction_type'] = $corrType;
                $res = $object->insertExtraFields();
                if ($res >= 0) {
                    setEventMessages($langs->trans('KSEF_CorrectionDetailsSaved'), null, 'mesgs');
                } else {
                    setEventMessages($object->error ?: 'Error saving correction details', $object->errors, 'errors');
                }
                $action = '';
            }
        }

        // Save (Podmiot3
        if ($currentcontext === 'invoicecard' && $action === 'ksef_save_podmiot3') {
            if (is_object($object) && !empty($object->id) && $object->element === 'facture') {
                $langs->load("ksef@ksef");

                dol_include_once('/ksef/class/ksef_submission.class.php');
                $p3SaveSubmission = new KsefSubmission($db);
                $p3SaveHasSub = ($p3SaveSubmission->fetchByInvoice($object->id) > 0);
                $p3SaveIsAccepted = $p3SaveHasSub && $p3SaveSubmission->status == KsefSubmission::STATUS_ACCEPTED
                    && !empty($p3SaveSubmission->ksef_number)
                    && strpos($p3SaveSubmission->ksef_number, 'OFFLINE') === false
                    && strpos($p3SaveSubmission->ksef_number, 'PENDING') === false
                    && strpos($p3SaveSubmission->ksef_number, 'ERROR') === false;
                $p3SaveIsOffline = $p3SaveHasSub && $p3SaveSubmission->status == KsefSubmission::STATUS_OFFLINE
                    && !empty($p3SaveSubmission->fa3_xml);
                if ($p3SaveIsAccepted || $p3SaveIsOffline) {
                    setEventMessages($langs->trans('KSEF_Podmiot3_Locked'), null, 'warnings');
                    $action = '';
                    return 0;
                }

                if (!isset($object->array_options) || empty($object->array_options)) {
                    if (method_exists($object, 'fetch_optionals')) $object->fetch_optionals();
                }

                require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

                $allowedRoles = array('6', '2', '3', '4', '5', '7', '8', '9', '10', '11', '1');
                $ids = GETPOST('ksef_podmiot3_party', 'array');
                $roles = GETPOST('ksef_podmiot3_role', 'array');
                $udzials = GETPOST('ksef_podmiot3_udzial', 'array');
                $list = array();
                $factorNoBank = array();
                foreach ($ids as $i => $rid) {
                    $rid = (int) $rid;
                    if ($rid <= 0) continue;
                    $role = isset($roles[$i]) ? (string) $roles[$i] : '';
                    if (!in_array($role, $allowedRoles, true)) {
                        $role = getDolGlobalString('KSEF_PODMIOT3_ROLE', '6');
                    }
                    $entry = array('id' => $rid, 'role' => $role);
                    // Udzial
                    if ($role === '4') {
                        $u = isset($udzials[$i]) ? (float) str_replace(',', '.', (string) $udzials[$i]) : 0;
                        if ($u > 0 && $u <= 100) {
                            $entry['udzial'] = rtrim(rtrim(number_format($u, 2, '.', ''), '0'), '.');
                        }
                    }
                    // Factor
                    if ($role === '1') {
                        $sqlRib = "SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "societe_rib"
                            . " WHERE fk_soc = " . ((int) $rid) . " AND type = 'ban'";
                        $resRib = $db->query($sqlRib);
                        $nbRib = ($resRib && ($o = $db->fetch_object($resRib))) ? (int) $o->nb : 0;
                        if ($nbRib === 0) {
                            $soc = new Societe($db);
                            $factorNoBank[] = ($soc->fetch($rid) > 0 && !empty($soc->name)) ? $soc->name : ('#' . $rid);
                        }
                    }
                    $list[] = $entry;
                    if (count($list) >= 20) break;
                }
                $object->array_options['options_ksef_podmiot3'] = $list ? json_encode($list) : '';
                $res = $object->insertExtraFields();
                if ($res >= 0) {
                    setEventMessages($langs->trans('KSEF_Podmiot3_Saved'), null, 'mesgs');
                    if (!empty($factorNoBank)) {
                        setEventMessages($langs->trans('KSEF_Podmiot3_FactorNoBank', implode(', ', $factorNoBank)), null, 'warnings');
                    }
                } else {
                    setEventMessages($object->error ?: 'Error saving Podmiot3', $object->errors, 'errors');
                }
                $action = '';
            }
        }

        // Block reopen
        if ($currentcontext === 'invoicecard' && $action === 'reopen' && !empty($object->id)) {
            $objectidnext = $object->getIdReplacingInvoice('validated');
            if ($objectidnext > 0) {
                $langs->load("ksef@ksef");
                $replacement = new Facture($db);
                $replacement->fetch($objectidnext);
                setEventMessages($langs->trans('KSEF_CorrectionChainBlocked', $replacement->ref), null, 'errors');
                $action = '';
                return 1;
            }
        }

        // Default payment settings
        if ($currentcontext === 'invoicecard' && $action === 'create') {
            if (!empty($conf->global->KSEF_DEFAULT_PAYMENT_TERM_ID) && !GETPOSTISSET('cond_reglement_id')) {
                $_POST['cond_reglement_id'] = $conf->global->KSEF_DEFAULT_PAYMENT_TERM_ID;
            }
            if (!empty($conf->global->KSEF_DEFAULT_PAYMENT_METHOD_ID) && !GETPOSTISSET('mode_reglement_id')) {
                $_POST['mode_reglement_id'] = $conf->global->KSEF_DEFAULT_PAYMENT_METHOD_ID;
            }
            if (!empty($conf->global->KSEF_DEFAULT_BANK_ACCOUNT_ID) && !GETPOSTISSET('fk_account')) {
                $_POST['fk_account'] = $conf->global->KSEF_DEFAULT_BANK_ACCOUNT_ID;
            }
        }

        if ($currentcontext == 'thirdpartycard') {
            if (empty($conf->ksef) || empty($conf->ksef->enabled)) {
                return 0;
            }

            if (($action == 'update' || $action == 'add') && is_object($object) && !empty($object->id)) {
                require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

                $ksef_exclude = GETPOST('ksef_exclude', 'int');
                $current_excluded = $conf->global->KSEF_EXCLUDED_CUSTOMERS ?? '';
                $excluded_array = array_filter(array_map('trim', explode(',', $current_excluded)));

                if ($ksef_exclude) {
                    if (!in_array($object->id, $excluded_array)) {
                        $excluded_array[] = $object->id;
                        dolibarr_set_const($db, 'KSEF_EXCLUDED_CUSTOMERS', implode(',', $excluded_array), 'chaine', 0, '', $conf->entity);
                    }
                } else {
                    $excluded_array = array_diff($excluded_array, array($object->id));
                    dolibarr_set_const($db, 'KSEF_EXCLUDED_CUSTOMERS', implode(',', $excluded_array), 'chaine', 0, '', $conf->entity);
                }
            }

            return 0;
        }


        // Handle supplier invoice actions
        if ($currentcontext == 'invoicesuppliercard' && $action == 'ksef_confirm_payment') {
            if (empty($user->rights->fournisseur->facture->creer)) {
                setEventMessages('Permission denied', null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $langs->load("ksef@ksef");
            dol_include_once('/ksef/class/ksef_incoming.class.php');

            $incoming = new KsefIncoming($db);
            if ($incoming->fetchBySupplierInvoice($object->id) <= 0
                || $incoming->payment_status !== 'paid'
                || empty($incoming->payment_date)
                || empty($incoming->payment_method)
            ) {
                setEventMessages($langs->trans('KSEF_PaymentConfirmError'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            // Validate if still draft
            if ($object->statut == 0) {
                $valResult = $object->validate($user);
                if ($valResult <= 0) {
                    setEventMessages($object->error, $object->errors, 'errors');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }
            }

            // Create payment
            require_once DOL_DOCUMENT_ROOT . '/fourn/class/paiementfourn.class.php';
            // Re-fetch invoice to get validated totals
            $object->fetch($object->id);
            $paiement = new PaiementFourn($db);
            $paiement->datepaye = $incoming->payment_date ? $incoming->payment_date : dol_now();
            $paiement->amounts = array($object->id => $object->total_ttc);
            if (!empty($object->multicurrency_total_ttc) && $object->multicurrency_code !== $conf->currency) {
                $paiement->multicurrency_amounts = array($object->id => $object->multicurrency_total_ttc);
                $paiement->multicurrency_code = array($object->id => $object->multicurrency_code);
                $paiement->multicurrency_tx = array($object->id => $object->multicurrency_tx);
            }
            $paiement->paiementid = $incoming->mapKsefPaymentMethod($incoming->payment_method);
            $paiement->num_payment = 'KSeF';

            $paiement_id = $paiement->create($user, 1);
            if ($paiement_id > 0) {
                setEventMessages($langs->trans('KSEF_PaymentConfirmed'), null, 'mesgs');
            } else {
                setEventMessages($langs->trans('KSEF_PaymentConfirmError') . ': ' . $paiement->error, null, 'errors');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if (!in_array('invoicecard', array($parameters['currentcontext']))) {
            return 0;
        }

        // change replacement to correction
        $langs->load("ksef@ksef");
        $langs->tab_translate['InvoiceReplacement'] = $langs->trans('KSEF_CorrectionInvoice');
        $langs->tab_translate['InvoiceReplacementShort'] = $langs->trans('KSEF_CorrectionInvoice');
        $langs->tab_translate['InvoiceReplacementAsk'] = $langs->trans('KSEF_CorrectionInvoiceAsk');
        $langs->tab_translate['InvoiceReplacementDesc'] = $langs->trans('KSEF_CorrectionInvoiceDesc');
        $langs->tab_translate['ReplaceInvoice'] = $langs->trans('KSEF_CorrectionInvoiceFor') . ' %s';
        $langs->tab_translate['ReplacedByInvoice'] = $langs->trans('KSEF_ReplacedByCorrectionInvoice') . ' %s';

        if (($action == 'download_xml' || $action == 'download_upo') && GETPOST('id', 'int')) {
            $id = GETPOST('id', 'int');
            $submission = new KsefSubmission($db);

            if ($submission->fetch($id) > 0) {
                if ($action == 'download_xml' && !empty($submission->fa3_xml)) {
                    $filename = 'FA3_' . ($submission->ksef_number ?: 'invoice_' . $submission->fk_facture) . '.xml';
                    header('Content-Type: application/xml; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $filename . '"');
                    echo $submission->fa3_xml;
                    exit;
                } elseif ($action == 'download_upo' && !empty($submission->upo_xml)) {
                    header('Content-Type: application/xml; charset=utf-8');
                    header('Content-Disposition: attachment; filename="UPO_' . $submission->ksef_number . '.xml"');
                    echo $submission->upo_xml;
                    exit;
                }
            }
            if ($action == 'download_upo') {
                setEventMessages($langs->trans("KSEF_UPONotAvailable"), null, 'warnings');
            }
        }

        // Serve preview PDF inline (for iframe embedding)
        if ($action == 'ksef_serve_preview_pdf' && !empty($object->id)) {
            $ref = dol_sanitizeFileName($object->ref);
            $dir = $conf->invoice->multidir_output[$object->entity ?? $conf->entity] . '/' . $ref;
            $template = GETPOST('template', 'alphanohtml');

            if ($template && $template != 'ksef') {
                // Dolibarr template: filename is {ref}.pdf
                $filepath = $dir . '/' . $ref . '.pdf';
            } else {
                // KSeF template: filename is {ref}_ksef.pdf
                $filepath = $dir . '/' . $ref . '_ksef.pdf';
            }

            if (file_exists($filepath)) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="' . basename($filepath) . '"');
                header('Content-Length: ' . filesize($filepath));
                header('Cache-Control: private, max-age=60');
                readfile($filepath);
                exit;
            }

            header('HTTP/1.0 404 Not Found');
            exit;
        }

        // Download final KSeF PDF (attachment for Save to PC)
        if ($action == 'ksef_download_final_pdf' && !empty($object->id)) {
            $ref = dol_sanitizeFileName($object->ref);
            $dir = $conf->invoice->multidir_output[$object->entity ?? $conf->entity] . '/' . $ref;
            $filepath = $dir . '/' . $ref . '_ksef.pdf';

            if (file_exists($filepath)) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $ref . '_ksef.pdf"');
                header('Content-Length: ' . filesize($filepath));
                readfile($filepath);
                exit;
            }

            header('HTTP/1.0 404 Not Found');
            exit;
        }

        // KSeF-style PDF
        if ($action == 'ksef_generate_pdf' && !empty($object->id)) {
            $langs->load("ksef@ksef");

            // Preview
            if (GETPOST('preview', 'int') && in_array($object->statut, array(0, 1, 2))) {
                dol_include_once('/ksef/class/fa3_builder.class.php');

                $builder = new FA3Builder($db);
                $xml = $builder->buildFromInvoice($object->id);
                if ($xml === false) {
                    setEventMessages($langs->trans('KSEF_PreviewXMLBuildError') . ': ' . dol_escape_htmltag($builder->error), null, 'errors');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }

                if (!$builder->validate($xml)) {
                    setEventMessages($langs->trans('KSEF_PreviewXMLValidationWarnings') . ': ' . dol_escape_htmltag(implode(', ', $builder->errors)), null, 'warnings');
                }

                $mockSubmission = new stdClass();
                $mockSubmission->rowid = 0;
                $mockSubmission->ksef_number = 'PODGLĄD';
                $mockSubmission->fa3_xml = $xml;
                $mockSubmission->environment = getDolGlobalString('KSEF_ENVIRONMENT', 'DEMO');
                $mockSubmission->offline_mode = false;
                $mockSubmission->offline_deadline = null;
                $mockSubmission->invoice_hash = $builder->getLastXmlHash();

                $pdfData = $this->createPdfDataFromSubmission($mockSubmission, $object);

                dol_include_once('/ksef/class/ksef_invoice_pdf.class.php');
                $pdfGen = new KsefInvoicePdf($db);
                $content = $pdfGen->generate($pdfData, '', true);

                if ($content === false) {
                    setEventMessages($langs->trans('KSEF_PDFGenerationError') . ': ' . dol_escape_htmltag($pdfGen->error), null, 'errors');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }

                $filename = dol_sanitizeFileName($object->ref) . '_ksef.pdf';
                $dir = $conf->invoice->multidir_output[$object->entity ?? $conf->entity] . '/' . dol_sanitizeFileName($object->ref);
                dol_mkdir($dir);
                file_put_contents($dir . '/' . $filename, $content);

                setEventMessages($langs->trans('KSEF_PreviewPDFGenerated', $filename), null, 'mesgs');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            // Standard path
            $submission = new KsefSubmission($db);
            if ($submission->fetchByInvoice($object->id) <= 0) {
                setEventMessages($langs->trans('KSEF_PDFRequiresSubmission'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $has_real_ksef_number = !empty($submission->ksef_number) &&
                strpos($submission->ksef_number, 'OFFLINE') === false &&
                strpos($submission->ksef_number, 'PENDING') === false &&
                strpos($submission->ksef_number, 'ERROR') === false;

            $is_accepted = ($submission->status == 'ACCEPTED' && $has_real_ksef_number);
            $is_offline = ($submission->status == 'OFFLINE' && !empty($submission->fa3_xml));

            if (!$is_accepted && !$is_offline) {
                setEventMessages($langs->trans('KSEF_PDFRequiresAccepted'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $errorMsg = '';
            $result = ksefAutoGeneratePdf($db, $submission, $object->id, $errorMsg);
            if ($result) {
                $filename = dol_sanitizeFileName($object->ref) . '_ksef.pdf';
                setEventMessages($langs->trans('KSEF_PDFGeneratedSuccess', $filename), null, 'mesgs');
            } else {
                $userMsg = $langs->trans('KSEF_PDFGenerationError');
                if (!empty($errorMsg)) {
                    $userMsg .= ': ' . dol_escape_htmltag($errorMsg);
                }
                setEventMessages($userMsg, null, 'errors');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        // Pre-submit confirmation: generate preview PDF then show dialog
        if ($action == 'ksef_pre_submit' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");

            if (!in_array($object->statut, array(1, 2))) {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $confirm_template = getDolGlobalString('KSEF_CONFIRM_PDF_TEMPLATE', 'ksef');

            if ($confirm_template == 'ksef') {
                dol_include_once('/ksef/class/fa3_builder.class.php');
                $builder = new FA3Builder($db);
                $xml = $builder->buildFromInvoice($object->id);
                if ($xml === false) {
                    setEventMessages($langs->trans('KSEF_PreviewXMLBuildError') . ': ' . dol_escape_htmltag($builder->error), null, 'errors');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }

                $xml_warnings = array();
                if (!$builder->validate($xml)) {
                    $xml_warnings = $builder->errors;
                    setEventMessages($langs->trans('KSEF_PreviewXMLValidationWarnings') . ': ' . dol_escape_htmltag(implode(', ', $builder->errors)), null, 'warnings');
                }

                $mockSubmission = new stdClass();
                $mockSubmission->rowid = 0;
                $mockSubmission->ksef_number = 'PODGLĄD';
                $mockSubmission->fa3_xml = $xml;
                $mockSubmission->environment = getDolGlobalString('KSEF_ENVIRONMENT', 'DEMO');
                $mockSubmission->offline_mode = false;
                $mockSubmission->offline_deadline = null;
                $mockSubmission->invoice_hash = $builder->getLastXmlHash();

                $pdfData = $this->createPdfDataFromSubmission($mockSubmission, $object);

                dol_include_once('/ksef/class/ksef_invoice_pdf.class.php');
                $pdfGen = new KsefInvoicePdf($db);
                $content = $pdfGen->generate($pdfData, '', true);

                if ($content === false) {
                    setEventMessages($langs->trans('KSEF_PDFGenerationError') . ': ' . dol_escape_htmltag($pdfGen->error), null, 'errors');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }

                $filename = dol_sanitizeFileName($object->ref) . '_ksef.pdf';
                $dir = $conf->invoice->multidir_output[$object->entity ?? $conf->entity] . '/' . dol_sanitizeFileName($object->ref);
                dol_mkdir($dir);
                file_put_contents($dir . '/' . $filename, $content);

                $_SESSION['ksef_confirm_preview'] = array(
                    'invoice_id' => $object->id,
                    'template' => 'ksef',
                    'xml_warnings' => $xml_warnings,
                );
            } else {
                // Dolibarr PDF template
                $gen_result = $object->generateDocument($confirm_template, $langs);
                if ($gen_result < 0) {
                    setEventMessages($langs->trans('KSEF_PDFGenerationError') . ': ' . dol_escape_htmltag(implode(', ', $object->errors)), null, 'errors');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }

                $_SESSION['ksef_confirm_preview'] = array(
                    'invoice_id' => $object->id,
                    'template' => $confirm_template,
                    'xml_warnings' => array(),
                );
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_confirm_upload&token=' . newToken());
            exit;
        }

        // Validate then show preview confirmation
        if ($action == 'ksef_validate_and_preview' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");

            if (ksefIsCustomerExcluded($object->socid)) {
                setEventMessages($langs->trans('KSEF_CustomerExcluded'), null, 'warnings');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            // Backdating triggers offline flow directly (has its own dialog)
            $backdate_info = ksefDetectBackdating($object->date);
            if ($backdate_info['is_backdated']) {
                if ($object->validate($user) > 0) {
                    $_SESSION['ksef_offline_confirm'] = array(
                        'invoice_id' => $object->id,
                        'days_behind' => $backdate_info['days_behind'],
                        'deadline' => $backdate_info['deadline'],
                        'reason' => 'backdated',
                        'message' => $backdate_info['reason'],
                        'from_validate' => true
                    );
                    setEventMessages($langs->trans('InvoiceValidated'), null, 'mesgs');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_confirm_offline&token=' . newToken());
                    exit;
                } else {
                    setEventMessages($object->error, $object->errors, 'errors');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }
            }

            // Check NBP rate for foreign invoices
            dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
            $nbpChecker = new KsefNbpCurrencyRate($db);
            if ($nbpChecker->invoiceNeedsNBPRate($object) && !$nbpChecker->invoiceHasNBPRate($object)) {
                setEventMessages($langs->trans('KSEF_NBPRateRequired'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            if ($object->validate($user) > 0) {
                setEventMessages($langs->trans('InvoiceValidated'), null, 'mesgs');

                $confirm_template = getDolGlobalString('KSEF_CONFIRM_PDF_TEMPLATE', 'ksef');
                $xml_warnings = array();

                if ($confirm_template == 'ksef') {
                    // Generate KSeF preview PDF
                    dol_include_once('/ksef/class/fa3_builder.class.php');
                    $builder = new FA3Builder($db);
                    $xml = $builder->buildFromInvoice($object->id);
                    if ($xml === false) {
                        setEventMessages($langs->trans('KSEF_PreviewXMLBuildError') . ': ' . dol_escape_htmltag($builder->error), null, 'errors');
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                        exit;
                    }

                    if (!$builder->validate($xml)) {
                        $xml_warnings = $builder->errors;
                        setEventMessages($langs->trans('KSEF_PreviewXMLValidationWarnings') . ': ' . dol_escape_htmltag(implode(', ', $builder->errors)), null, 'warnings');
                    }

                    $mockSubmission = new stdClass();
                    $mockSubmission->rowid = 0;
                    $mockSubmission->ksef_number = 'PODGLĄD';
                    $mockSubmission->fa3_xml = $xml;
                    $mockSubmission->environment = getDolGlobalString('KSEF_ENVIRONMENT', 'DEMO');
                    $mockSubmission->offline_mode = false;
                    $mockSubmission->offline_deadline = null;
                    $mockSubmission->invoice_hash = $builder->getLastXmlHash();

                    $pdfData = $this->createPdfDataFromSubmission($mockSubmission, $object);

                    dol_include_once('/ksef/class/ksef_invoice_pdf.class.php');
                    $pdfGen = new KsefInvoicePdf($db);
                    $pdfContent = $pdfGen->generate($pdfData, '', true);

                    if ($pdfContent === false) {
                        setEventMessages($langs->trans('KSEF_PDFGenerationError') . ': ' . dol_escape_htmltag($pdfGen->error), null, 'errors');
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                        exit;
                    }

                    $filename = dol_sanitizeFileName($object->ref) . '_ksef.pdf';
                    $dir = $conf->invoice->multidir_output[$object->entity ?? $conf->entity] . '/' . dol_sanitizeFileName($object->ref);
                    dol_mkdir($dir);
                    file_put_contents($dir . '/' . $filename, $pdfContent);
                } else {
                    // Dolibarr PDF template
                    $gen_result = $object->generateDocument($confirm_template, $langs);
                    if ($gen_result < 0) {
                        setEventMessages($langs->trans('KSEF_PDFGenerationError') . ': ' . dol_escape_htmltag(implode(', ', $object->errors)), null, 'errors');
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                        exit;
                    }
                }

                $_SESSION['ksef_confirm_preview'] = array(
                    'invoice_id' => $object->id,
                    'template' => $confirm_template,
                    'xml_warnings' => $xml_warnings,
                );

                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_confirm_upload&from_validate=1&token=' . newToken());
                exit;
            } else {
                setEventMessages($object->error, $object->errors, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }
        }

        // Confirmation upload dialog rendering
        if ($action == 'ksef_confirm_upload' && !empty($object->id)) {
            $langs->load("ksef@ksef");

            // Guard: invoice must be validated or closed
            if (!in_array($object->statut, array(1, 2))) {
                unset($_SESSION['ksef_confirm_preview']);
                setEventMessages($langs->trans('KSEF_InvalidStatusForUpload'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $from_validate = GETPOST('from_validate', 'int');

            $title = $from_validate
                ? $langs->trans('KSEF_ConfirmUploadValidatedTitle')
                : $langs->trans('KSEF_ConfirmUploadTitle');

            $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'DEMO');
            $env_badge = ksefGetEnvironmentBadge($environment);

            // Session preview data
            $confirm_data = isset($_SESSION['ksef_confirm_preview']) ? $_SESSION['ksef_confirm_preview'] : array();
            $xml_warnings = !empty($confirm_data['xml_warnings']) ? $confirm_data['xml_warnings'] : array();
            $confirm_template = !empty($confirm_data['template']) ? $confirm_data['template'] : 'ksef';

            $pdf_url = $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_serve_preview_pdf&template=' . urlencode($confirm_template) . '&token=' . newToken();

            $submit_url = $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_submit&token=' . newToken();
            $cancel_url = $_SERVER['PHP_SELF'] . '?id=' . $object->id;

            // Button colors
            $button_color = !empty($conf->global->KSEF_BUTTON_COLOR) ? $conf->global->KSEF_BUTTON_COLOR : '#dc3545';

            // Latarnia status
            dol_include_once('/ksef/class/ksef_latarnia.class.php');
            $latarnia_cached = KsefLatarnia::getCachedStatus();
            $latarnia_status = $latarnia_cached['status'];
            $latarnia_is_issue = in_array($latarnia_status, array('MAINTENANCE', 'FAILURE', 'TOTAL_FAILURE', 'UNREACHABLE'));

            print '<div id="ksef-confirm-upload-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; display: flex; justify-content: center; align-items: center;">';
            print '<div style="background: white; padding: 25px; border-radius: 12px; width: 80vw; max-width: 1000px; max-height: 90vh; box-shadow: 0 8px 32px rgba(0,0,0,0.3); display: flex; flex-direction: column;">';

            // Header row: title left, environment right
            print '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">';
            print '<h3 style="margin: 0; color: #333; font-size: 20px;">' . $title . '</h3>';
            print '<div style="text-align: right;">' . $langs->trans('KSEF_ENVIRONMENT') . ': ' . $env_badge . '</div>';
            print '</div>';

            // Subtitle
            print '<p style="margin: 0 0 5px 0; color: #555;">' . $langs->trans('KSEF_ConfirmUploadText', '<strong>' . dol_escape_htmltag($object->ref) . '</strong>') . '</p>';
            print '<p style="margin: 0 0 15px 0; color: #888; font-size: 13px;"><i class="fas fa-info-circle"></i> ' . $langs->trans('KSEF_ConfirmUploadNote') . '</p>';

            // Warnings section
            if ($latarnia_is_issue || !empty($xml_warnings)) {
                print '<div style="margin-bottom: 15px;">';

                if ($latarnia_is_issue) {
                    $latarnia_badge = ksefGetLatarniaStatusBadge($latarnia_status);
                    print '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px 14px; border-radius: 6px; margin-bottom: 8px;">';
                    print '<i class="fas fa-exclamation-triangle" style="color: #856404;"></i> ';
                    print $langs->trans('KSEF_ConfirmLatarniaWarning') . ' ' . $latarnia_badge;
                    print '</div>';
                }

                if (!empty($xml_warnings)) {
                    print '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px 14px; border-radius: 6px;">';
                    print '<i class="fas fa-exclamation-triangle" style="color: #856404;"></i> ';
                    print $langs->trans('KSEF_ConfirmXMLWarnings') . ': ' . dol_escape_htmltag(implode(', ', $xml_warnings));
                    print '</div>';
                }

                print '</div>';
            }

            // PDF iframe
            print '<div id="ksef-confirm-pdf-wrap" style="flex: 1; min-height: 0; margin-bottom: 15px;">';
            print '<iframe id="ksef-confirm-pdf" src="' . $pdf_url . '" style="width: 100%; height: 70vh; border: 1px solid #ddd; border-radius: 6px;" title="KSeF PDF Preview"></iframe>';
            print '</div>';

            // Buttons
            print '<div style="display: flex; gap: 12px; justify-content: flex-end;">';
            print '<a class="butAction" href="' . $cancel_url . '" onclick="var m=document.getElementById(\'ksef-confirm-upload-modal\'); if(m) m.remove(); return true;" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">' . $langs->trans('KSEF_ConfirmUploadCancel') . '</a>';
            print '<a class="butAction" href="' . $submit_url . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px; background-color: ' . $button_color . '; color: white; font-weight: bold;" onclick="var m=document.getElementById(\'ksef-confirm-upload-modal\'); if(m) m.remove(); return ksefShowSpinner(event, this);" data-processing-text="' . $langs->trans('KSEF_SubmittingToKSEF') . '...">';
            print '<i class="fa fa-paper-plane"></i> ' . $langs->trans('KSEF_ConfirmUploadSubmit') . '</a>';
            print '</div>';

            print '</div>';
            print '</div>';

            return 0;
        }

        // Post-upload PDF view dialog
        if (GETPOST('ksef_post_upload', 'int') && !empty($object->id)) {
            $langs->load("ksef@ksef");

            $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'DEMO');
            $env_badge = ksefGetEnvironmentBadge($environment);

            // Fetch submission to get KSeF number
            dol_include_once('/ksef/class/ksef_submission.class.php');
            $submission = new KsefSubmission($db);
            $ksef_number = '';
            if ($submission->fetchByInvoice($object->id) > 0 && $submission->status == 'ACCEPTED') {
                $ksef_number = $submission->ksef_number;
            } else {
                // No accepted submission - nothing to show, redirect to invoice
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $pdf_url = $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_serve_preview_pdf&template=ksef&token=' . newToken();
            $download_url = $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_download_final_pdf&token=' . newToken();
            $close_url = $_SERVER['PHP_SELF'] . '?id=' . $object->id;

            print '<div id="ksef-post-upload-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; display: flex; justify-content: center; align-items: center;">';
            print '<div style="background: white; padding: 25px; border-radius: 12px; width: 80vw; max-width: 1000px; max-height: 90vh; box-shadow: 0 8px 32px rgba(0,0,0,0.3); display: flex; flex-direction: column;">';

            // Header row: title left, environment right
            print '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">';
            print '<h3 style="margin: 0; color: #333; font-size: 20px;"><i class="fas fa-check-circle" style="color: #28a745;"></i> ' . $langs->trans('KSEF_PostUploadTitle') . '</h3>';
            print '<div style="text-align: right;">' . $langs->trans('KSEF_ENVIRONMENT') . ': ' . $env_badge . '</div>';
            print '</div>';

            // Info text
            print '<p style="margin: 0 0 5px 0; color: #555;">' . $langs->trans('KSEF_PostUploadText', '<strong>' . dol_escape_htmltag($object->ref) . '</strong>') . '</p>';
            if (!empty($ksef_number)) {
                print '<p style="margin: 0 0 15px 0; color: #555;">' . $langs->trans('KSEF_PostUploadKsefNumber') . ': <strong>' . dol_escape_htmltag($ksef_number) . '</strong></p>';
            }

            // PDF iframe
            print '<div style="flex: 1; min-height: 0; margin-bottom: 15px;">';
            print '<iframe id="ksef-post-upload-pdf" src="' . $pdf_url . '" style="width: 100%; height: 70vh; border: 1px solid #ddd; border-radius: 6px;" title="KSeF PDF"></iframe>';
            print '</div>';

            // Buttons: Close, Save to PC, Print
            print '<div style="display: flex; gap: 12px; justify-content: flex-end;">';
            print '<a class="butAction" href="' . $close_url . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">' . $langs->trans('KSEF_PostUploadClose') . '</a>';
            print '<a class="butAction" href="' . $download_url . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">';
            print '<i class="fas fa-download"></i> ' . $langs->trans('KSEF_PostUploadSave') . '</a>';
            print '<a class="butAction" href="#" onclick="try { document.getElementById(\'ksef-post-upload-pdf\').contentWindow.print(); } catch(e) { window.open(\'' . $pdf_url . '\', \'_blank\'); } return false;" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">';
            print '<i class="fas fa-print"></i> ' . $langs->trans('KSEF_PostUploadPrint') . '</a>';
            print '</div>';

            print '</div>';
            print '</div>';

            return 0;
        }

        if ($action == 'ksef_submit' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");

            // Guard: invoice must be validated or closed
            if (!in_array($object->statut, array(1, 2))) {
                setEventMessages($langs->trans('KSEF_InvalidStatusForUpload'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            // Clean up confirmation preview session data
            unset($_SESSION['ksef_confirm_preview']);

            if (ksefIsCustomerExcluded($object->socid)) {
                setEventMessages($langs->trans('KSEF_CustomerExcluded'), null, 'warnings');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            // Check NBP rate for foreig invoices
            dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
            $nbpChecker = new KsefNbpCurrencyRate($db);
            if ($nbpChecker->invoiceNeedsNBPRate($object) && !$nbpChecker->invoiceHasNBPRate($object)) {
                setEventMessages($langs->trans('KSEF_NBPRateRequired'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            // Warn if original has no KSeF number
            $is_kor = ($object->type == Facture::TYPE_CREDIT_NOTE || $object->type == Facture::TYPE_REPLACEMENT)
                && !empty($object->fk_facture_source);
            if ($is_kor) {
                $origForCheck = new Facture($db);
                if ($origForCheck->fetch($object->fk_facture_source) > 0) {
                    $origForCheck->fetch_optionals();
                    $orig_ksef_num = $origForCheck->array_options['options_ksef_number'] ?? '';
                    if (empty($orig_ksef_num)) {
                        setEventMessages($langs->trans('KSEF_CorrectionOriginalNoKsef'), null, 'warnings');
                    }
                }
            }

            $ksef_submission = new KsefSubmission($db);
            if ($ksef_submission->fetch(0, $object->id) > 0) {
                if ($ksef_submission->status == 'ACCEPTED') {
                    setEventMessages($langs->trans('KSEF_AlreadySubmitted'), null, 'warnings');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }
                if ($ksef_submission->status == 'PENDING') {
                    setEventMessages($langs->trans('KSEF_SubmissionInProgress'), null, 'warnings');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }
            }

            $backdate_info = ksefDetectBackdating($object->date);
            if ($backdate_info['is_backdated']) {
                $_SESSION['ksef_offline_confirm'] = array(
                    'invoice_id' => $object->id,
                    'days_behind' => $backdate_info['days_behind'],
                    'deadline' => $backdate_info['deadline'],
                    'reason' => 'backdated',
                    'message' => $backdate_info['reason']
                );
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_confirm_offline&token=' . newToken());
                exit;
            }

            // Release session lock to prevent blocking
            session_write_close();

            try {
                $ksef = new KsefService($db);
                $result = $ksef->submitInvoice($object->id, $user, 'SYNC');
            } catch (Exception $e) {
                $result = array('status' => 'EXCEPTION', 'error' => $e->getMessage());
                dol_syslog("KSEF submission exception: " . $e->getMessage(), LOG_ERR);
            }

            // Re-open session
            session_start();

            if ($result && $result['status'] == 'ACCEPTED') {
                setEventMessages($langs->trans('KSEF_SubmissionSuccess') . ' - ' . $result['ksef_number'], null, 'mesgs');
                ksefUpdateInvoiceExtrafields($this->db, $object->id, $result['ksef_number'], 'ACCEPTED', dol_now(), true);

                if (getDolGlobalInt('KSEF_SHOW_PDF_AFTER_UPLOAD', 1)) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&ksef_post_upload=1');
                    exit;
                }
            } elseif ($result && $result['status'] == 'FAILED') {
                $error_msg = $langs->trans('KSEF_SubmissionFailed');
                if (!empty($result['error'])) {
                    $error_msg .= ': ' . $result['error'];
                }
                setEventMessages($error_msg, null, 'errors');
            } elseif ($result && $result['status'] == 'EXCEPTION') {
                setEventMessages($langs->trans('KSEF_SubmissionFailed') . ': ' . $result['error'], null, 'errors');
            } else {
                $this->handleSubmissionError($result, $ksef->error, $langs);
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_retry' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");

            // Release session lock to prevent blocking
            session_write_close();

            try {
                $ksef = new KsefService($db);
                $result = $ksef->retrySubmission($object->id, $user);
            } catch (Exception $e) {
                $result = array('status' => 'EXCEPTION', 'error' => $e->getMessage());
            }

            // Re-open session
            session_start();

            if ($result && $result['status'] == 'ACCEPTED') {
                setEventMessages($langs->trans('KSEF_SubmissionSuccess') . ' - ' . $result['ksef_number'], null, 'mesgs');
                ksefUpdateInvoiceExtrafields($this->db, $object->id, $result['ksef_number'], 'ACCEPTED', dol_now(), true);
            } elseif ($result && $result['status'] == 'NEEDS_OFFLINE_CONFIRMATION') {
                $_SESSION['ksef_offline_confirm'] = array(
                    'invoice_id' => $object->id,
                    'days_behind' => $result['backdate_info']['days_behind'] ?? 0,
                    'deadline' => $result['deadline'],
                    'reason' => 'backdated_retry'
                );
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_confirm_offline&token=' . newToken());
                exit;
            } elseif ($result && $result['status'] == 'FAILED') {
                $error_msg = $langs->trans('KSEF_RetryFailed');
                if (!empty($result['error'])) {
                    $error_msg .= ': ' . $result['error'];
                }
                setEventMessages($error_msg, null, 'errors');
            } elseif ($result && $result['status'] == 'PENDING') {
                setEventMessages($langs->trans('KSEF_SubmissionInProgress'), null, 'warnings');
            } elseif ($result && $result['status'] == 'EXCEPTION') {
                setEventMessages('KSEF Error: ' . $result['error'], null, 'errors');
            } else {
                setEventMessages($langs->trans('KSEF_RetryFailed') . ': ' . ($result['error'] ?? $ksef->error ?? 'Unknown'), null, 'errors');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_create_offline' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");

            $offline_cert_check = ksefIsOfflineCertificateConfigured();
            if (!$offline_cert_check['configured']) {
                $_SESSION['ksef_needs_offline_cert'] = array(
                    'invoice_id' => $object->id,
                    'missing' => $offline_cert_check['missing']
                );
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_show_cert_required&token=' . newToken());
                exit;
            }

            dol_include_once('/ksef/class/ksef_submission.class.php');
            $submission = new KsefSubmission($db);
            if ($submission->fetchByInvoice($object->id) > 0) {
                if ($submission->status == 'ACCEPTED') {
                    setEventMessages($langs->trans('KSEF_AlreadySubmitted'), null, 'warnings');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }
            }

            // Release session lock to prevent blocking
            session_write_close();

            try {
                $ksef = new KsefService($db);
                $result = $ksef->submitInvoiceOffline($object->id, $user, 'failed_submission');
            } catch (Exception $e) {
                $result = array('status' => 'EXCEPTION', 'error' => $e->getMessage());
            }

            // Re-open session
            session_start();

            if ($result && $result['status'] == 'OFFLINE') {
                $msg = $langs->trans('KSEF_OfflineInvoiceCreated');
                if (!empty($result['ksef_number'])) {
                    $msg .= ': ' . $result['ksef_number'];
                }
                if (!empty($result['offline_deadline'])) {
                    $msg .= '<br><small>' . $langs->trans('KSEF_OfflineDeadline') . ': ' . dol_print_date($result['offline_deadline'], 'dayhour') . '</small>';
                }
                setEventMessages($msg, null, 'mesgs');
            } else {
                $error_msg = ($result && $result['status'] == 'EXCEPTION') ? 'KSEF Error: ' . $result['error'] : $langs->trans('KSEF_OfflineCreationFailed');
                if (!empty($result['error']) && $result['status'] != 'EXCEPTION') {
                    $error_msg .= ': ' . $result['error'];
                }
                setEventMessages($error_msg, null, 'errors');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_show_failure_dialog' && !empty($_SESSION['ksef_failed_confirm'])) {
            $fail_data = $_SESSION['ksef_failed_confirm'];
            if ($fail_data['invoice_id'] == $object->id) {
                $langs->load("ksef@ksef");

                dol_include_once('/ksef/class/ksef_client.class.php');
                $ksefClient = new KsefClient($db);

                $is_connection_error = !empty($fail_data['is_connection_error']);

                $error_desc = '';
                if (!empty($fail_data['error_code'])) {
                    $error_desc = $ksefClient->getErrorDescription($fail_data['error_code']);
                }

                $offline_deadline = ksefCalculateOfflineDeadline($object->date);

                print '<div id="ksef-failure-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; display: flex; justify-content: center; align-items: center;">';
                print '<div style="background: white; padding: 30px; border-radius: 12px; max-width: 550px; box-shadow: 0 8px 32px rgba(0,0,0,0.3); margin: 20px;">';

                print '<div style="text-align: center; margin-bottom: 20px;">';
                if ($is_connection_error) {
                    print '<div style="width: 60px; height: 60px; background: #ffc10720; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 15px;">';
                    print '<i class="fa fa-wifi" style="font-size: 28px; color: #ffc107;"></i>';
                    print '</div>';
                    print '<h3 style="margin: 0; color: #333; font-size: 20px;">' . $langs->trans('KSEF_ConnectionFailed') . '</h3>';
                } else {
                    print '<div style="width: 60px; height: 60px; background: #dc354520; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 15px;">';
                    print '<i class="fa fa-exclamation-circle" style="font-size: 28px; color: #dc3545;"></i>';
                    print '</div>';
                    print '<h3 style="margin: 0; color: #333; font-size: 20px;">' . $langs->trans('KSEF_SubmissionFailed') . '</h3>';
                }
                print '</div>';

                if ($is_connection_error) {
                    print '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 8px; margin-bottom: 20px;">';
                    print '<p style="margin: 0 0 10px 0; color: #856404;"><i class="fa fa-info-circle"></i> ' . $langs->trans('KSEF_ConnectionErrorExplanation') . '</p>';
                    print '<p style="margin: 0; font-size: 12px; color: #666;">' . dol_escape_htmltag($fail_data['error']) . '</p>';
                    print '</div>';
                } else {
                    print '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 8px; margin-bottom: 20px;">';
                    if (!empty($fail_data['error_code'])) {
                        print '<p style="margin: 0 0 5px 0; font-weight: bold; color: #721c24;">Error ' . $fail_data['error_code'] . '</p>';
                        if (!empty($error_desc)) {
                            print '<p style="margin: 0 0 10px 0; color: #721c24;">' . dol_escape_htmltag($error_desc) . '</p>';
                        }
                    }
                    print '<p style="margin: 0; font-size: 13px; color: #856404;">' . dol_escape_htmltag($fail_data['error']) . '</p>';
                    print '</div>';
                }

                print '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 8px; margin-bottom: 20px;">';
                print '<p style="margin: 0 0 10px 0; font-weight: bold; color: #155724;"><i class="fas fa-file-alt"></i> ' . $langs->trans('KSEF_OfflineOptionAvailable') . '</p>';
                print '<p style="margin: 0 0 10px 0; font-size: 13px; color: #155724;">' . $langs->trans('KSEF_OfflineOptionExplanation') . '</p>';
                print '<p style="margin: 0; font-size: 12px; color: #155724;"><strong>' . $langs->trans('KSEF_OfflineDeadline') . ':</strong> ' . dol_print_date($offline_deadline, 'dayhour') . '</p>';
                print '</div>';

                print '<div style="display: flex; gap: 12px; justify-content: flex-end; flex-wrap: wrap;">';
                print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_dismiss_failure&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">' . $langs->trans('Close') . '</a>';
                print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_retry&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px; background: #ffc107; color: #212529;">';
                print '<i class="fas fa-sync-alt"></i> ' . $langs->trans('KSEF_TryAgain') . '</a>';
                print '<a class="button button-save" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_submit_offline_confirmed&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px; background: #28a745; color: white;">';
                print '<i class="fas fa-file-alt"></i> ' . $langs->trans('KSEF_CreateOfflineInvoice') . '</a>';

                print '</div>';

                print '</div>';
                print '</div>';
            }
            return 0;
        }

        if ($action == 'ksef_show_cert_required' && !empty($_SESSION['ksef_needs_offline_cert'])) {
            $cert_data = $_SESSION['ksef_needs_offline_cert'];
            if ($cert_data['invoice_id'] == $object->id) {
                $langs->load("ksef@ksef");

                print '<div id="ksef-cert-required-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; display: flex; justify-content: center; align-items: center;">';
                print '<div style="background: white; padding: 30px; border-radius: 12px; max-width: 500px; box-shadow: 0 8px 32px rgba(0,0,0,0.3); margin: 20px;">';
                print '<div style="text-align: center; margin-bottom: 20px;">';
                print '<div style="width: 60px; height: 60px; background: #ffc10720; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 15px;">';
                print '<i class="fa fa-certificate" style="font-size: 28px; color: #ffc107;"></i>';
                print '</div>';
                print '<h3 style="margin: 0; color: #333; font-size: 20px;">' . $langs->trans('KSEF_OfflineCertificateRequired') . '</h3>';
                print '</div>';
                print '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 8px; margin-bottom: 20px;">';
                print '<p style="margin: 0 0 10px 0; color: #856404;"><i class="fa fa-info-circle"></i> ' . $langs->trans('KSEF_OfflineCertificateRequiredExplanation') . '</p>';
                print '<p style="margin: 0; font-size: 13px; color: #856404;">' . $langs->trans('KSEF_OfflineCertificateMissingItems') . ':</p>';
                print '<ul style="margin: 5px 0 0 0; padding-left: 20px; color: #856404;">';
                foreach ($cert_data['missing'] as $item) {
                    $item_label = $langs->trans('KSEF_Missing_' . $item);
                    print '<li>' . $item_label . '</li>';
                }
                print '</ul>';
                print '</div>';
                print '<div style="display: flex; gap: 12px; justify-content: flex-end;">';
                print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_dismiss_cert_dialog&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">' . $langs->trans('Close') . '</a>';
                print '<a class="button button-save" href="' . dol_buildpath('/ksef/admin/setup.php', 1) . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px; background: #007bff; color: white;">';
                print '<i class="fa fa-cog"></i> ' . $langs->trans('KSEF_GoToSetup') . '</a>';
                print '</div>';
                print '</div>';
                print '</div>';

                unset($_SESSION['ksef_needs_offline_cert']);
            }
            return 0;
        }

        if ($action == 'ksef_dismiss_cert_dialog') {
            unset($_SESSION['ksef_needs_offline_cert']);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_dismiss_failure') {
            unset($_SESSION['ksef_failed_confirm']);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_confirm_offline_after_fail' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");

            dol_include_once('/ksef/class/ksef_submission.class.php');
            $submission = new KsefSubmission($db);
            $submission->fetchByInvoice($object->id);
            $offline_cert_check = ksefIsOfflineCertificateConfigured();
            $_SESSION['ksef_failed_confirm'] = array(
                'invoice_id' => $object->id,
                'error' => $submission->error_message ?? 'Previous submission failed',
                'error_code' => $submission->error_code ?? null,
                'submission_id' => $submission->rowid ?? null
            );

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_show_failure_dialog&token=' . newToken());
            exit;
        }

        if ($action == 'ksef_retry_online' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");

            dol_include_once('/ksef/class/ksef_submission.class.php');
            $existing = new KsefSubmission($db);

            if ($existing->fetchByInvoice($object->id) <= 0) {
                setEventMessages($langs->trans('KSEF_NoSubmissionFound'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $is_valid_offline = ($existing->status == 'OFFLINE') ||
                ($existing->status == 'ACCEPTED' && !empty($existing->offline_mode) &&
                    strpos($existing->ksef_number, 'OFFLINE') !== false);

            if (!$is_valid_offline) {
                setEventMessages($langs->trans('KSEF_InvalidStatusForUpload'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            if (!empty($existing->offline_deadline) && ksefIsDeadlinePassed($existing->offline_deadline)) {
                setEventMessages($langs->trans('KSEF_OfflineDeadlinePassed'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            // Release session lock to prevent blocking
            session_write_close();
            ksefSessionUnset('ksef_failed_confirm');

            try {
                $ksef = new KsefService($db);

                if (!empty($existing->fa3_xml)) {
                    $result = $ksef->retryOfflineWithStoredXML($existing, $user);
                } else {
                    $result = $ksef->submitInvoice($object->id, $user, 'SYNC');
                }
            } catch (Exception $e) {
                $result = array('status' => 'EXCEPTION', 'error' => $e->getMessage());
            }

            // Re-open session
            session_start();

            if ($result && $result['status'] == 'ACCEPTED' &&
                strpos($result['ksef_number'], 'OFFLINE') === false) {
                setEventMessages($langs->trans('KSEF_OnlineSubmissionSuccess') . ' - ' . $result['ksef_number'], null, 'mesgs');
                ksefUpdateInvoiceExtrafields($this->db, $object->id, $result['ksef_number'], 'ACCEPTED', dol_now(), true);
            } elseif ($result && ($result['status'] == 'FAILED' || $result['status'] == 'EXCEPTION')) {
                setEventMessages($langs->trans('KSEF_OnlineRetryFailed') . ': ' . ($result['error'] ?? ''), null, 'errors');
            } else {
                setEventMessages($langs->trans('KSEF_SubmissionStatus') . ': ' . ($result['status'] ?? 'Unknown'), null, 'mesgs');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_confirm_offline' && !empty($_SESSION['ksef_offline_confirm'])) {
            $confirm_data = $_SESSION['ksef_offline_confirm'];
            if ($confirm_data['invoice_id'] == $object->id) {
                $langs->load("ksef@ksef");

                $is_backdated = ($confirm_data['reason'] == 'backdated');
                $is_connection_error = ($confirm_data['reason'] == 'connection_error');

                if ($is_backdated) {
                    $title = $langs->trans('KSEF_OfflineBackdatedTitle');
                    $icon_color = '#ffc107';
                    $message = $langs->trans('KSEF_OfflineBackdatedMessage', $confirm_data['days_behind']);
                } else {
                    $title = $langs->trans('KSEF_OfflineConnectionTitle');
                    $icon_color = '#dc3545';
                    $message = $langs->trans('KSEF_OfflineConnectionMessage');
                }

                print '<div id="ksef-offline-confirm-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 9999; display: flex; justify-content: center; align-items: center;">';
                print '<div style="background: white; padding: 30px; border-radius: 12px; max-width: 520px; box-shadow: 0 8px 32px rgba(0,0,0,0.3); margin: 20px;">';
                print '<div style="text-align: center; margin-bottom: 20px;">';
                print '<div style="width: 60px; height: 60px; background: ' . $icon_color . '20; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 15px;">';
                print '<i class="fas fa-exclamation-triangle" style="font-size: 28px; color: ' . $icon_color . ';"></i>';
                print '</div>';
                print '<h3 style="margin: 0; color: #333; font-size: 20px;">' . $title . '</h3>';
                print '</div>';
                print '<div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">';
                print '<p style="margin: 0 0 10px 0; color: #555;">' . $message . '</p>';
                if ($is_connection_error && !empty($confirm_data['message'])) {
                    print '<p style="margin: 0; font-size: 12px; color: #888;"><strong>Error:</strong> ' . dol_escape_htmltag($confirm_data['message']) . '</p>';
                }
                print '</div>';
                print '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 8px; margin-bottom: 20px;">';
                print '<p style="margin: 0; font-size: 14px;"><strong><i class="fas fa-clock"></i> ' . $langs->trans('KSEF_OfflineDeadline') . ':</strong><br>';
                print dol_print_date($confirm_data['deadline'], 'dayhour') . '</p>';
                print '</div>';
                print '<p style="color: #666; font-size: 13px; margin-bottom: 25px;">';
                print '<i class="fa fa-info-circle"></i> ' . $langs->trans('KSEF_OfflineExplanation');
                print '</p>';
                print '<div style="display: flex; gap: 12px; justify-content: flex-end;">';
                print '<a class="button" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_cancel_offline&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px;">' . $langs->trans('Cancel') . '</a>';
                print '<a class="button button-save" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_submit_offline_confirmed&token=' . newToken() . '" style="padding: 10px 20px; text-decoration: none; border-radius: 6px; background: #28a745; color: white;">';
                print '<i class="fa fa-paper-plane"></i> ' . $langs->trans('KSEF_ConfirmOfflineSubmit') . '</a>';
                print '</div>';

                print '</div>';
                print '</div>';
            }
            return 0;
        }

        if ($action == 'ksef_cancel_offline') {
            unset($_SESSION['ksef_offline_confirm']);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_submit_offline_confirmed' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");

            $offline_reason = '';

            if (!empty($_SESSION['ksef_offline_confirm'])) {
                $confirm_data = $_SESSION['ksef_offline_confirm'];
                $offline_reason = $confirm_data['reason'] ?? 'user_confirmed';
                unset($_SESSION['ksef_offline_confirm']);
            }
            if (!empty($_SESSION['ksef_failed_confirm'])) {
                $fail_data = $_SESSION['ksef_failed_confirm'];
                $offline_reason = 'failed_submission';
                unset($_SESSION['ksef_failed_confirm']);
            }

            // Release session lock to prevent blocking
            session_write_close();

            try {
                $ksef = new KsefService($db);
                $result = $ksef->submitInvoiceOffline($object->id, $user, $offline_reason);
            } catch (Exception $e) {
                $result = array('status' => 'EXCEPTION', 'error' => $e->getMessage());
            }

            // Re-open session
            session_start();

            if ($result && $result['status'] == 'OFFLINE') {
                $msg = $langs->trans('KSEF_OfflineInvoiceCreated');
                if (!empty($result['ksef_number'])) {
                    $msg .= ' - ' . $result['ksef_number'];
                }
                $msg .= ' <span class="badge badge-warning">' . $langs->trans('KSEF_OfflineMode') . '</span>';
                if (!empty($result['offline_deadline'])) {
                    $msg .= '<br><small>' . $langs->trans('KSEF_OfflineDeadline') . ': ' . dol_print_date($result['offline_deadline'], 'dayhour') . '</small>';
                }
                setEventMessages($msg, null, 'mesgs');
            } else {
                $error_msg = ($result && $result['status'] == 'EXCEPTION') ? 'KSEF Error: ' . $result['error'] : $langs->trans('KSEF_OfflineCreationFailed');
                if (!empty($result['error']) && $result['status'] != 'EXCEPTION') {
                    $error_msg .= ': ' . $result['error'];
                }
                setEventMessages($error_msg, null, 'errors');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_technical_correction' && !empty($user->rights->facture->creer)) {
            $submission_id = GETPOST('submission_id', 'int');
            $langs->load("ksef@ksef");

            // Release session lock to prevent blocking
            session_write_close();

            try {
                $ksef = new KsefService($db);
                $result = $ksef->submitTechnicalCorrection($object->id, $submission_id, $user);
            } catch (Exception $e) {
                $result = array('status' => 'ERROR', 'error' => $e->getMessage());
            }

            // Re-open session
            session_start();

            if ($result && $result['status'] != 'ERROR') {
                setEventMessages($langs->trans('KSEF_TechnicalCorrectionSubmitted'), null, 'mesgs');
            } else {
                setEventMessages($langs->trans('KSEF_TechnicalCorrectionFailed') . ': ' . ($result['error'] ?? (isset($ksef) ? $ksef->error : '') ?? ''), null, 'errors');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        if ($action == 'ksef_validate_and_submit' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");

            if (ksefIsCustomerExcluded($object->socid)) {
                setEventMessages($langs->trans('KSEF_CustomerExcluded'), null, 'warnings');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $backdate_info = ksefDetectBackdating($object->date);
            if ($backdate_info['is_backdated']) {
                if ($object->validate($user) > 0) {
                    $_SESSION['ksef_offline_confirm'] = array(
                        'invoice_id' => $object->id,
                        'days_behind' => $backdate_info['days_behind'],
                        'deadline' => $backdate_info['deadline'],
                        'reason' => 'backdated',
                        'message' => $backdate_info['reason'],
                        'from_validate' => true
                    );
                    setEventMessages($langs->trans('InvoiceValidated'), null, 'mesgs');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_confirm_offline&token=' . newToken());
                    exit;
                } else {
                    setEventMessages($object->error, $object->errors, 'errors');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                    exit;
                }
            }

            // Check NBP rate for foreign invoices
            dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
            $nbpChecker = new KsefNbpCurrencyRate($db);
            if ($nbpChecker->invoiceNeedsNBPRate($object) && !$nbpChecker->invoiceHasNBPRate($object)) {
                setEventMessages($langs->trans('KSEF_NBPRateRequired'), null, 'errors');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            if ($object->validate($user) > 0) {

                // Release session lock to prevent blocking
                session_write_close();

                try {
                    $ksef = new KsefService($db);
                    $submit_result = $ksef->submitInvoice($object->id, $user, 'SYNC');
                } catch (Exception $e) {
                    $submit_result = array('status' => 'EXCEPTION', 'error' => $e->getMessage());
                    dol_syslog("KSEF submission exception: " . $e->getMessage(), LOG_ERR);
                }

                // Re-open session
                session_start();

                if ($submit_result && $submit_result['status'] == 'NEEDS_CONFIRMATION') {
                    $backdate_info = $submit_result['backdate_info'];
                    $_SESSION['ksef_offline_confirm'] = array(
                        'invoice_id' => $object->id,
                        'days_behind' => $backdate_info['days_behind'] ?? 0,
                        'deadline' => $submit_result['deadline'],
                        'reason' => $backdate_info['reason'] ?? 'connection_error',
                        'from_validate' => true
                    );
                    setEventMessages($langs->trans('InvoiceValidated'), null, 'mesgs');
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_confirm_offline&token=' . newToken());
                    exit;
                }

                if ($submit_result && $submit_result['status'] == 'ACCEPTED') {
                    $msg = $langs->trans('InvoiceValidated') . ' - ' . $langs->trans('KSEF_SubmissionSuccess') . ' - ' . $submit_result['ksef_number'];
                    if (!empty($submit_result['offline_mode'])) {
                        $msg .= ' (' . $langs->trans('KSEF_OfflineMode') . ')';
                    }
                    setEventMessages($msg, null, 'mesgs');
                    ksefUpdateInvoiceExtrafields($this->db, $object->id, $submit_result['ksef_number'], 'ACCEPTED', dol_now(), true);

                    if (getDolGlobalInt('KSEF_SHOW_PDF_AFTER_UPLOAD', 1)) {
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&ksef_post_upload=1');
                        exit;
                    }

                } elseif ($submit_result && $submit_result['status'] == 'FAILED') {
                    setEventMessages($langs->trans('InvoiceValidated'), null, 'mesgs');

                    $_SESSION['ksef_failed_confirm'] = array(
                        'invoice_id' => $object->id,
                        'error' => $submit_result['error'] ?? 'Unknown error',
                        'error_code' => $submit_result['error_code'] ?? null,
                        'submission_id' => $submit_result['submission_id'] ?? null
                    );
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_show_failure_dialog&token=' . newToken());
                    exit;

                } elseif ($submit_result && $submit_result['status'] == 'EXCEPTION') {
                    setEventMessages($langs->trans('InvoiceValidated'), null, 'mesgs');

                    $_SESSION['ksef_failed_confirm'] = array(
                        'invoice_id' => $object->id,
                        'error' => $submit_result['error'],
                        'error_code' => null,
                        'submission_id' => null,
                    );
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_show_failure_dialog&token=' . newToken());
                    exit;

                } else {
                    $error_msg = $langs->trans('InvoiceValidated') . ' - ' . $langs->trans('KSEF_SubmissionFailed');
                    if (!empty($submit_result['error'])) {
                        $error_msg .= ': ' . $submit_result['error'];
                    }
                    setEventMessages($error_msg, null, 'warnings');
                }

            } else {
                setEventMessages($object->error, $object->errors, 'errors');
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        // NBP rate
        if ($action == 'ksef_fetch_nbp_rate' && !empty($user->rights->facture->creer)) {
            $langs->load("ksef@ksef");
            dol_include_once('/ksef/lib/ksef.lib.php');
            dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');

            $nbp = new KsefNbpCurrencyRate($db);

            if (!$nbp->invoiceNeedsNBPRate($object)) {
                setEventMessages($langs->trans('KSEF_NBPRateNotNeeded'), null, 'warnings');
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                exit;
            }

            $result = $nbp->fetchAndStoreForInvoice($object, $user);

            if ($result !== false) {
                setEventMessages(
                    $langs->trans('KSEF_NBPRateFetched', $result['rate'], $result['date']),
                    null,
                    'mesgs'
                );
            } else {
                setEventMessages(
                    $langs->trans('KSEF_NBPRateFetchError', $nbp->error),
                    null,
                    'errors'
                );
            }

            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        }

        return 0;
    }


    /**
     * @brief Handles submission error display
     * @param $result Submission result array
     * @param $ksef_error KsefService error message
     * @param $langs Language object
     * @called_by doActions()
     */
    private function handleSubmissionError($result, $ksef_error, $langs)
    {
        $error_msg = isset($result['status']) && $result['status'] == 'REJECTED' ? $langs->trans('KSEF_SubmissionRejected') : $langs->trans('KSEF_SubmissionFailed');

        if (!empty($result['error'])) {
            $error_msg .= ': ' . $result['error'];
        } elseif (!empty($ksef_error)) {
            $error_msg .= ': ' . $ksef_error;
        }
        setEventMessages($error_msg, null, 'errors');
    }

    /**
     * @brief DodatkowyOpis modes for a given invoice
     * @param object $object Invoice
     * @return array Indexed by: noteMode, efCount, effectiveNoteMode, effectiveEfCount, noteConfigured, efConfigured, dodOverride, featureActive
     */
    private function ksefComputeDodatkowyOpisContext($object)
    {
        dol_include_once('/ksef/class/fa3_builder.class.php');
        $noteMode = getDolGlobalString('KSEF_DODATKOWY_OPIS_NOTE_MODE', 'simple');
        $efConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_EXTRAFIELDS', '');
        $efCount = !empty($efConf) ? count(array_filter(array_map('trim', explode(',', $efConf)))) : 0;
        // Count all extrafield types
        $detConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_DET_EXTRAFIELDS', '');
        if (!empty($detConf)) $efCount += count(array_filter(array_map('trim', explode(',', $detConf))));
        $prodConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_PRODUCT_EXTRAFIELDS', '');
        if (!empty($prodConf)) $efCount += count(array_filter(array_map('trim', explode(',', $prodConf))));
        $socConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS', '');
        if (!empty($socConf)) $efCount += count(array_filter(array_map('trim', explode(',', $socConf))));
        $projConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS', '');
        if (!empty($projConf)) $efCount += count(array_filter(array_map('trim', explode(',', $projConf))));
        $dodOverride = FA3Builder::getDodatkowyOpisOverride($object);

        $effectiveNoteMode = $noteMode;
        $effectiveEfCount = $efCount;
        $effectiveNoteConfigured = ($noteMode !== 'disabled');
        $effectiveEfConfigured = ($efCount > 0);

        if ($dodOverride === 'disabled') {
            $effectiveNoteMode = 'disabled';
            $effectiveEfCount = 0;
            $effectiveNoteConfigured = false;
            $effectiveEfConfigured = false;
        } elseif (in_array($dodOverride, array('simple_stopka', 'simple_dodatkowy', 'keyvalue_dodatkowy'))) {
            $parsed = FA3Builder::parseCombinedNoteMode($dodOverride);
            $effectiveNoteMode = $parsed ? $parsed['mode'] : $noteMode;
            $effectiveNoteConfigured = true;
        }

        return array(
            'noteMode' => $noteMode,
            'efCount' => $efCount,
            'effectiveNoteMode' => $effectiveNoteMode,
            'effectiveEfCount' => $effectiveEfCount,
            'noteConfigured' => $effectiveNoteConfigured,
            'efConfigured' => $effectiveEfConfigured,
            'dodOverride' => $dodOverride,
            'featureActive' => ($noteMode !== 'disabled') || ($efCount > 0) || in_array($dodOverride, array('simple_stopka', 'simple_dodatkowy', 'keyvalue_dodatkowy', 'disabled')),
        );
    }

    /**
     * @brief Returns HTML for the notes preview
     * @param object $object Invoice
     * @param bool $collapsible Wrap in <details> for toggle UX
     * @return string HTML
     */
    private function ksefRenderDodatkowyOpisPreviewInner($object, $collapsible = true)
    {
        global $conf, $db, $langs;
        dol_include_once('/ksef/class/fa3_builder.class.php');

        $ctx = $this->ksefComputeDodatkowyOpisContext($object);
        $dodEntries = FA3Builder::parseDodatkowyOpisPreview($object, $conf, $db);

        $noteEntries = array();
        $efEntries = array();
        foreach ($dodEntries as $entry) {
            if (isset($entry['source']) && strpos($entry['source'], 'extrafield') === 0) {
                $efEntries[] = $entry;
            } else {
                $noteEntries[] = $entry;
            }
        }

        $overrideBadge = '';
        if (!empty($ctx['dodOverride'])) {
            $overrideLabel = '';
            if ($ctx['dodOverride'] === 'simple_stopka') $overrideLabel = $langs->transnoentities('KSEF_DodatkowyOpisMode_SimpleStopka');
            elseif ($ctx['dodOverride'] === 'simple_dodatkowy') $overrideLabel = $langs->transnoentities('KSEF_DodatkowyOpisMode_SimpleDodatkowy');
            elseif ($ctx['dodOverride'] === 'keyvalue_dodatkowy') $overrideLabel = $langs->transnoentities('KSEF_DodatkowyOpisMode_KeyValueDodatkowy');
            elseif ($ctx['dodOverride'] === 'disabled') $overrideLabel = $langs->transnoentities('KSEF_DodatkowyOpisMode_Disabled');
            if ($overrideLabel !== '') {
                $overrideBadge = ' <span class="badge badge-warning" style="margin-left:6px;">' . dol_escape_htmltag(sprintf($langs->transnoentities('KSEF_DODATKOWY_OPIS_PREVIEW_OVERRIDDEN'), $overrideLabel)) . '</span>';
            }
        }

        if ($collapsible) {
            $html = '<details' . (!empty($dodEntries) ? ' open' : '') . '>';
            $html .= '<summary style="cursor:pointer;">' . $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW_ENTRIES") . $overrideBadge . '</summary>';
        } else {
            $html = '<div>';
            if ($overrideBadge !== '') {
                $html .= '<div style="margin-bottom:4px;">' . $overrideBadge . '</div>';
            }
        }

        // Check for NrWiersza column
        $hasNrWiersza = false;
        foreach ($dodEntries as $entry) {
            if (!empty($entry['nr_wiersza'])) {
                $hasNrWiersza = true;
                break;
            }
        }

        $sections = array(
            array('title' => $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW_FROM_NOTE"), 'entries' => $noteEntries, 'configured' => $ctx['noteConfigured']),
            array('title' => $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW_FROM_EXTRAFIELDS"), 'entries' => $efEntries, 'configured' => $ctx['efConfigured']),
        );

        foreach ($sections as $sec) {
            if (!$sec['configured'] && empty($sec['entries'])) continue;
            $html .= '<h5 style="margin:8px 0 4px 0; font-size:12px; text-transform:uppercase; color:#666;">' . $sec['title'] . '</h5>';
            if (empty($sec['entries'])) {
                $html .= '<div class="opacitymedium" style="margin-bottom:4px;">' . $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW_EMPTY_SECTION") . '</div>';
                continue;
            }
            $html .= '<table class="noborder" style="margin-bottom:4px; width:100%; table-layout:fixed;">';
            $html .= '<tr class="liste_titre">';
            if ($hasNrWiersza) {
                $html .= '<td style="width:60px;">' . $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW_NRWIERSZA") . '</td>';
            }
            $html .= '<td style="width:30%;">' . $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW_KEY") . '</td><td>' . $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW_VALUE") . '</td></tr>';
            foreach ($sec['entries'] as $entry) {
                $dispKey = dol_escape_htmltag($entry['key']);
                $dispVal = dol_escape_htmltag($entry['value']);
                $html .= '<tr class="oddeven">';
                if ($hasNrWiersza) {
                    $html .= '<td style="text-align:center; vertical-align:top;">' . (!empty($entry['nr_wiersza']) ? (int) $entry['nr_wiersza'] : '') . '</td>';
                }
                $html .= '<td style="word-break:break-word; vertical-align:top;">' . $dispKey . '</td><td style="word-break:break-word; white-space:pre-wrap;">' . $dispVal . '</td></tr>';
            }
            $html .= '</table>';
        }

        if (!$ctx['noteConfigured'] && !$ctx['efConfigured']) {
            $html .= '<div class="opacitymedium">' . $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW_EMPTY_SECTION") . '</div>';
        }

        $html .= $collapsible ? '</details>' : '</div>';
        return $html;
    }

    /**
     * @brief Returns the script block for the live helper
     * @param object $object Invoice
     * @return string HTML
     */
    private function ksefRenderDodatkowyOpisHelperScript($object)
    {
        global $langs;
        $ctx = $this->ksefComputeDodatkowyOpisContext($object);

        $hintHtml = '';
        if ($ctx['effectiveNoteMode'] === 'simple') {
            $hintHtml = $langs->transnoentities("KSEF_DODATKOWY_OPIS_HINT_SIMPLE");
        } elseif ($ctx['effectiveNoteMode'] === 'keyvalue') {
            $hintHtml = $langs->transnoentities("KSEF_DODATKOWY_OPIS_HINT_KEYVALUE");
        }
        if ($ctx['effectiveEfCount'] > 0) {
            $hintHtml .= '<br>' . sprintf($langs->transnoentities("KSEF_DODATKOWY_OPIS_HINT_EXTRAFIELDS"), $ctx['effectiveEfCount']);
        }

        $charsLabel = addslashes($langs->transnoentities("KSEF_DODATKOWY_OPIS_CHARS_LABEL"));
        $noEntriesLabel = addslashes($langs->transnoentities("KSEF_DODATKOWY_OPIS_NOTE_NO_ENTRIES"));
        $truncatedLabel = addslashes($langs->transnoentities("KSEF_DODATKOWY_OPIS_NOTE_TRUNCATED"));

        return '<script type="text/javascript">
jQuery(document).ready(function() {
    var noteMode = ' . json_encode($ctx['effectiveNoteMode']) . ';
    if (noteMode === "disabled") return;

    function findTextarea() {
        return jQuery("textarea[name=\'note_public\']").first();
    }
    function insertHelper() {
        var ta = findTextarea();
        if (!ta.length || jQuery("#ksef_note_helper").length) return false;
        var ckeWrapper = ta.parent().find(".cke_chrome, .cke").first();
        var anchor = ckeWrapper.length ? ckeWrapper : ta;
        anchor.after(
            \'<div id="ksef_note_helper" style="font-size:11px; color:#666; margin-top:4px; padding:6px 8px; background:#f8f9fa; border-left:3px solid #6c757d;">\' +
            \'<div style="margin-bottom:4px;">' . addslashes($hintHtml) . '</div>\' +
            \'<div id="ksef_note_counter"></div>\' +
            \'</div>\'
        );
        return true;
    }
    function getText() {
        if (typeof CKEDITOR !== "undefined" && CKEDITOR.instances && CKEDITOR.instances.note_public) {
            return CKEDITOR.instances.note_public.getData() || "";
        }
        var ta = findTextarea();
        return ta.length ? (ta.val() || "") : "";
    }
    function escapeHtml(s) {
        return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    }
    function parseEntries(stripped) {
        var entries = [];
        if (noteMode === "simple") {
            var totalLen = stripped.length;
            if (totalLen === 0) return entries;
            if (totalLen <= 256) {
                entries.push({key: "Uwagi", value: stripped, len: totalLen});
            } else {
                var chunkNum = 0;
                for (var offset = 0; offset < totalLen; offset += 256) {
                    chunkNum++;
                    var end = Math.min(offset + 256, totalLen);
                    var label = chunkNum === 1 ? "Uwagi" : "Uwagi " + chunkNum;
                    entries.push({key: label, value: stripped.substring(offset, end), len: end - offset});
                }
            }
        } else if (noteMode === "keyvalue") {
            stripped.split("\\n").forEach(function(line) {
                line = line.trim();
                if (!line) return;
                var idx = line.indexOf(":");
                if (idx <= 0) return;
                var key = line.substring(0, idx).trim();
                var value = line.substring(idx + 1).trim();
                if (!key || !value) return;
                entries.push({key: key, value: value, len: value.length});
            });
        }
        return entries;
    }
    function updateCounter() {
        var el = jQuery("#ksef_note_counter");
        if (!el.length) return;
        var text = getText();
        var stripped = text.replace(/<br\s*\/?>/gi, "\\n").replace(/<\\/(p|div)>/gi, "\\n").replace(/<[^>]+>/g, "").trim();
        var entries = parseEntries(stripped);
        if (entries.length === 0) {
            el.html(\'<span class="opacitymedium">' . $noEntriesLabel . '</span>\');
            return;
        }
        var rowStyle = "display:flex; align-items:baseline; gap:8px; margin-bottom:2px;";
        var valStyle = "flex:1; min-width:0; font-style:italic; color:#333; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;";
        var badgeStyle = "flex:none; padding:1px 6px; border-radius:3px; font-family:monospace; font-size:11px; background:#e9ecef; color:#495057;";
        var badgeWarnStyle = "flex:none; padding:1px 6px; border-radius:3px; font-family:monospace; font-size:11px; background:#fff3cd; color:#856404;";
        var html = entries.map(function(e) {
            var truncated = e.len > 256;
            var displayLen = truncated ? 256 : e.len;
            var badge = truncated ? badgeWarnStyle : badgeStyle;
            var suffix = truncated ? \' <em>(' . $truncatedLabel . ')</em>\' : "";
            return \'<div style="\' + rowStyle + \'">\' +
                \'<b>\' + escapeHtml(e.key) + \'</b>\' +
                \'<span style="\' + valStyle + \'">"\' + escapeHtml(e.value) + \'"</span>\' +
                \'<span style="\' + badge + \'">\' + displayLen + \'/256</span>\' +
                suffix +
                \'</div>\';
        }).join("");
        el.html(html);
    }

    if (!insertHelper()) {
        var retry = setInterval(function() {
            if (insertHelper()) { clearInterval(retry); updateCounter(); }
        }, 500);
        setTimeout(function() { clearInterval(retry); }, 10000);
    } else {
        updateCounter();
    }
    var counterInterval = setInterval(updateCounter, 500);
    jQuery(window).on("beforeunload", function() { clearInterval(counterInterval); });
    jQuery(document).on("input change", "textarea[name=\'note_public\']", updateCounter);
});
</script>';
    }

    /**
     * @brief Renders the GUS lookup button
     * @param $parameters Hook parameters
     * @param $object Third party object
     * @param $action Current action
     * @param $hookmanager Hook manager
     * @return int Status code (0 so the standard field table still renders)
     * @called_by Dolibarr hook: tabContentCreateThirdparty
     * @calls printGusLookupWidget()
     */
    public function tabContentCreateThirdparty($parameters, &$object, &$action, $hookmanager)
    {
        $this->printGusLookupWidget($object);
        return 0;
    }

    /**
     * @brief Renders the GUS lookup button
     * @param $parameters Hook parameters
     * @param $object Third party object
     * @param $action Current action
     * @param $hookmanager Hook manager
     * @return int Status code (0 so the standard field table still renders)
     * @called_by Dolibarr hook: tabContentEditThirdparty
     * @calls printGusLookupWidget()
     */
    public function tabContentEditThirdparty($parameters, &$object, &$action, $hookmanager)
    {
        $this->printGusLookupWidget($object);
        return 0;
    }

    /**
     * @brief Prints the GUS lookup button
     * @param $object Third party object (may be empty on create)
     * @return void
     * @called_by tabContentCreateThirdparty(), tabContentEditThirdparty()
     * @calls ksefTranslationOverridesReady(), ksefGusDomFieldId()
     */
    private function printGusLookupWidget(&$object)
    {
        global $langs, $db, $conf;

        if (empty($conf->ksef) || empty($conf->ksef->enabled) || empty(getDolGlobalString('KSEF_GUS_ENABLED'))) {
            return;
        }

        // PL or unchosen country only
        $countryCode = (is_object($object) && !empty($object->country_code)) ? $object->country_code : '';
        if ($countryCode !== '' && $countryCode !== 'PL') {
            return;
        }

        dol_include_once('/ksef/lib/ksef.lib.php');
        $langs->loadLangs(array('ksef@ksef'));

        $nipSelector = ksefGusDomFieldId('NIP');
        if (empty($nipSelector)) {
            return;
        }

        $ready = ksefTranslationOverridesReady($db);
        $setupUrl = dol_buildpath('/ksef/admin/setup.php', 1);

        print '<span id="ksef_gus_widget" style="margin-left: 8px; white-space: nowrap;">';
        if ($ready) {
            print '<button type="button" id="ksef_gus_fetch" class="button smallpaddingimp">'
                . '<span class="fa fa-download"></span> ' . $langs->trans('KSEF_GusFetch') . '</button>';
            print ' <span id="ksef_gus_status" class="opacitymedium"></span>';
        } else {
            print '<button type="button" class="button smallpaddingimp" disabled title="' . dol_escape_htmltag($langs->trans('KSEF_GusSetupRequired')) . '">'
                . '<span class="fa fa-download"></span> ' . $langs->trans('KSEF_GusFetch') . '</button>';
            print ' <a href="' . $setupUrl . '">' . $langs->trans('KSEF_GusRunSetup') . '</a>';
        }
        print '</span>';
        if ($ready) {
            print '<div id="ksef_gus_conflicts" style="display: none;"></div>';
        }

        if (!$ready) {
            print '<script type="text/javascript">jQuery(function($) { var $n = $("' . dol_escape_js($nipSelector) . '"); if ($n.length) { $n.after($("#ksef_gus_widget")); } });</script>';
            return;
        }

        $cfg = array(
            'nipSelector' => $nipSelector,
        );
        $msg = array(
            'fetching'     => $langs->trans('KSEF_GusFetching'),
            'filled'       => $langs->trans('KSEF_GusFilled'),
            'noChanges'    => $langs->trans('KSEF_GusNoChanges'),
            'conflictTitle' => $langs->trans('KSEF_GusConflictTitle'),
            'apply'        => $langs->trans('KSEF_GusApply'),
            'dismiss'      => $langs->trans('KSEF_GusDismiss'),
            'selectAll'    => $langs->trans('KSEF_GusSelectAll'),
            'invalidNip'   => $langs->trans('KSEF_GusErrInvalidNip'),
            'nipCancelled' => $langs->trans('KSEF_GusNipCancelled'),
            'inactive'     => $langs->trans('KSEF_GusInactiveWarn'),
        );
        $errMap = array(
            'forbidden'        => $langs->trans('KSEF_GusErrForbidden'),
            'disabled'         => $langs->trans('KSEF_GusErrDisabled'),
            'setup_required'   => $langs->trans('KSEF_GusErrSetupRequired'),
            'invalid_nip'      => $langs->trans('KSEF_GusErrInvalidNip'),
            'no_key'           => $langs->trans('KSEF_GusErrNoKey'),
            'rate_limited'     => $langs->trans('KSEF_GusErrRateLimited'),
            'not_found'        => $langs->trans('KSEF_GusErrNotFound'),
            'unsupported_type' => $langs->trans('KSEF_GusErrUnsupportedType'),
            'session_error'    => $langs->trans('KSEF_GusErrSession'),
            'transport_error'  => $langs->trans('KSEF_GusErrTransport'),
            'http_error'       => $langs->trans('KSEF_GusErrTransport'),
            'parse_error'      => $langs->trans('KSEF_GusErrTransport'),
            'error'            => $langs->trans('KSEF_GusError'),
        );

        $flags = JSON_HEX_APOS | JSON_HEX_TAG;
        $cfgJson = json_encode($cfg, $flags);
        $msgJson = json_encode($msg, $flags);
        $errJson = json_encode($errMap, $flags);

        print '<script type="text/javascript">';
        print 'jQuery(function($) {';
        print 'var cfg = ' . $cfgJson . ';';
        print 'var MSG = ' . $msgJson . ';';
        print 'var ERR = ' . $errJson . ';';
        print 'var $btn = $("#ksef_gus_fetch");';
        print 'if (!$btn.length) return;';
        // Relocate widget to NIP field
        print 'var $nipField = $(cfg.nipSelector);';
        print 'if ($nipField.length) {';
        print '$nipField.after($("#ksef_gus_widget"));';
        print 'var $cr = $("<tr class=\'ksef-gus-conflict-row\'><td colspan=\'4\'></td></tr>");';
        print '$cr.children("td").append($("#ksef_gus_conflicts"));';
        print '$nipField.closest("tr").after($cr);';
        print '}';
        // card.php rolls token, use new
        print 'function token() { return $("meta[name=\'anti-csrf-newtoken\']").attr("content") || ""; }';
        print 'function setStatus(t) { $("#ksef_gus_status").text(t || ""); }';
        print 'function highlight($el) { $el.css("background-color", "#e6ffe6"); }';
        print 'function esc(s) { return $("<div>").text(s == null ? "" : s).html(); }';
        print 'function renderConflicts(list) {';
        print 'var h = "<table class=\'noborder centpercent\'>";';
        print 'h += "<tr class=\'liste_titre\'><td colspan=\'3\'>" + esc(MSG.conflictTitle) + "</td></tr>";';
        print '$.each(list, function(i, f) {';
        print 'h += "<tr class=\'oddeven\'><td class=\'center\' style=\'width:24px;\'><input type=\'checkbox\' class=\'ksef-gus-cf\' data-idx=\'" + i + "\'></td>";';
        print 'h += "<td><strong>" + esc(f.label) + "</strong></td>";';
        print 'h += "<td><span class=\'opacitymedium\'>" + esc($.trim($(f.selector).val() || "")) + "</span> &rarr; " + esc(f.value) + "</td></tr>";';
        print '});';
        print 'h += "<tr class=\'oddeven\'><td colspan=\'3\'><label><input type=\'checkbox\' id=\'ksef_gus_all\'> " + esc(MSG.selectAll) + "</label> ";';
        print 'h += "<button type=\'button\' class=\'button smallpaddingimp\' id=\'ksef_gus_apply\'>" + esc(MSG.apply) + "</button> ";';
        print 'h += "<button type=\'button\' class=\'button smallpaddingimp button-cancel\' id=\'ksef_gus_dismiss\'>" + esc(MSG.dismiss) + "</button></td></tr>";';
        print 'h += "</table>";';
        print 'var $box = $("#ksef_gus_conflicts"); $box.html(h).show(); $box.data("conflicts", list);';
        print '}';
        print '$btn.on("click", function() {';
        print 'var nip = ($(cfg.nipSelector).val() || "").replace(/[^0-9]/g, "");';
        print '$("#ksef_gus_conflicts").hide().empty();';
        print 'if (nip.length < 10) { setStatus(""); alert(MSG.invalidNip); return; }';
        print 'setStatus(MSG.fetching);';
        print '$.ajax({ type: "POST", url: window.location.pathname, dataType: "json", data: { action: "ksef_gus_lookup", nip: nip, token: token() } }).done(function(resp) {';
        print 'if (!resp || !resp.ok) { setStatus(""); var code = (resp && resp.error) ? resp.error : "error"; alert((ERR[code] || ERR.error).replace("%s", nip)); return; }';
        print 'var filled = 0, conflicts = [];';
        print '$.each(resp.fields, function(i, f) {';
        print 'var $el = $(f.selector); if (!$el.length) return;';
        print 'var cur = $.trim($el.val() || "");';
        print 'if (cur === "") { $el.val(f.value); highlight($el); filled++; }';
        print 'else if (cur !== $.trim(f.value)) { conflicts.push(f); }';
        print '});';
        print 'var notes = [];';
        print 'if (resp.meta && resp.meta.status_nip) { notes.push(MSG.nipCancelled.replace("%s", resp.meta.status_nip)); }';
        print 'if (resp.meta && resp.meta.active === false) { notes.push(MSG.inactive); }';
        print 'var summary = (!filled && !conflicts.length) ? MSG.noChanges : MSG.filled.replace("%s", filled);';
        print 'setStatus(summary + (notes.length ? " " + notes.join(" ") : ""));';
        print 'if (conflicts.length) { renderConflicts(conflicts); }';
        print '}).fail(function() { setStatus(""); alert(ERR.transport_error); });';
        print '});';
        print '$("#ksef_gus_conflicts").on("change", "#ksef_gus_all", function() { $(".ksef-gus-cf").prop("checked", $(this).prop("checked")); });';
        print '$("#ksef_gus_conflicts").on("click", "#ksef_gus_apply", function() {';
        print 'var list = $("#ksef_gus_conflicts").data("conflicts") || []; var n = 0;';
        print '$(".ksef-gus-cf:checked").each(function() { var f = list[$(this).data("idx")]; if (f) { var $el = $(f.selector); if ($el.length) { $el.val(f.value); highlight($el); n++; } } });';
        print '$("#ksef_gus_conflicts").hide().empty(); setStatus(MSG.filled.replace("%s", n));';
        print '});';
        print '$("#ksef_gus_conflicts").on("click", "#ksef_gus_dismiss", function() { $("#ksef_gus_conflicts").hide().empty(); });';
        print '});';
        print '</script>';
    }

    /**
     * @brief Handles the GUS lookup background request
     * @return void This method always exits after sending the response
     * @called_by doActions()
     * @calls ksefTranslationOverridesReady(), KsefGusClient::lookupByNip(), ksefGusDomFieldId()
     */
    private function handleGusLookup()
    {
        global $db, $user, $langs;

        dol_include_once('/ksef/class/ksef_gus_client.class.php');
        $langs->loadLangs(array('ksef@ksef', 'companies'));

        if (!$user->hasRight('societe', 'creer')) {
            $this->gusJsonError('forbidden');
        }
        if (empty(getDolGlobalString('KSEF_GUS_ENABLED'))) {
            $this->gusJsonError('disabled');
        }
        if (!ksefTranslationOverridesReady($db)) {
            $this->gusJsonError('setup_required');
        }

        $nip = ksefCleanNIP(GETPOST('nip', 'alphanohtml', 2));
        if (empty($nip)) {
            $this->gusJsonError('invalid_nip');
        }

        $now = dol_now();
        $gusEnv = getDolGlobalString('KSEF_GUS_ENV', 'TEST');
        $cacheKey = $gusEnv . ':' . getDolGlobalString('KSEF_GUS_NAME_STYLE', 'full') . ':' . $nip;

        // Cache before rate limit
        $gusCache = ksefSessionRead('KSEF_GUS_CACHE', array());
        if (!is_array($gusCache)) {
            $gusCache = array();
        }

        if (isset($gusCache[$cacheKey]) && $gusCache[$cacheKey]['ts'] > $now - 3600) {
            $data = $gusCache[$cacheKey]['data'];
        } else {
            // Self imposed rate limit
            $gusCalls = ksefSessionRead('KSEF_GUS_CALLS', array());
            if (!is_array($gusCalls)) {
                $gusCalls = array();
            }
            $recent = array();
            foreach ($gusCalls as $ts) {
                if ($ts > $now - 60) {
                    $recent[] = $ts;
                }
            }
            if (!empty($recent) && ($now - max($recent)) < 2) {
                $this->gusJsonError('rate_limited');
            }
            if (count($recent) >= 20) {
                $this->gusJsonError('rate_limited');
            }
            $recent[] = $now;
            ksefSessionWrite('KSEF_GUS_CALLS', $recent);

            $client = new KsefGusClient($db, $gusEnv);
            $data = $client->lookupByNip($nip);
            if ($data === false) {
                $this->gusJsonError($client->error ? $client->error : 'error');
            }

            $gusCache[$cacheKey] = array('ts' => $now, 'data' => $data);
            if (count($gusCache) > 50) {
                $gusCache = array_slice($gusCache, -50, null, true);
            }
            ksefSessionWrite('KSEF_GUS_CACHE', $gusCache);
        }

        $targets = array();
        $this->gusAddTarget($targets, '#name', $data['name'], $langs->trans('Name'));
        $this->gusAddTarget($targets, '#name_alias_input', $data['name_short'], $langs->trans('AliasNames'));
        $this->gusAddTarget($targets, '#address', $data['address'], $langs->trans('Address'));
        $this->gusAddTarget($targets, '#zipcode', $data['zip'], $langs->trans('Zip'));
        $this->gusAddTarget($targets, '#town', $data['town'], $langs->trans('Town'));
        $this->gusAddTarget($targets, '#phone', $data['phone'], $langs->trans('Phone'));
        $this->gusAddTarget($targets, '#email', $data['email'], $langs->trans('Email'));
        $this->gusAddTarget($targets, '#url', $data['url'], $langs->trans('Web'));

        $nipSelector = ksefGusDomFieldId('NIP');
        if ($nipSelector === '#intra_vat') {
            $this->gusAddTarget($targets, '#intra_vat', 'PL' . $data['nip'], 'VAT/NIP');
        } else {
            $this->gusAddTarget($targets, $nipSelector, $data['nip'], 'NIP');
            $this->gusAddTarget($targets, '#intra_vat', 'PL' . $data['nip'], 'VAT');
        }
        $this->gusAddTarget($targets, ksefGusDomFieldId('REGON'), $data['regon'], 'REGON');
        $this->gusAddTarget($targets, ksefGusDomFieldId('KRS'), $data['krs'], 'KRS');

        top_httphead('application/json');
        echo json_encode(array(
            'ok' => 1,
            'fields' => $targets,
            'meta' => array(
                'typ' => $data['typ'],
                'status_nip' => $data['status_nip'],
                'active' => $data['active'],
            ),
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * @brief Appends a fillable target
     * @param array $list Target list (by reference)
     * @param $selector jQuery selector or null
     * @param $value Field value
     * @param $label Human label for the reconcile panel
     * @return void
     * @called_by handleGusLookup()
     */
    private function gusAddTarget(&$list, $selector, $value, $label)
    {
        if (empty($selector) || $value === '' || $value === null) {
            return;
        }
        // Skip duplicate selectors
        foreach ($list as $existing) {
            if ($existing['selector'] === $selector) {
                return;
            }
        }
        $list[] = array('selector' => $selector, 'value' => $value, 'label' => $label);
    }

    /**
     * @brief Outputs a JSON error and exits
     * @param $code Machine readable error code
     * @return void This method always exits
     * @called_by handleGusLookup()
     */
    private function gusJsonError($code)
    {
        top_httphead('application/json');
        echo json_encode(array('ok' => 0, 'error' => $code));
        exit;
    }

    /**
     * @brief Adds KSeF status display to invoice form
     * @param $parameters Hook parameters
     * @param $object Invoice object
     * @param $action Current action
     * @param $hookmanager Hook manager
     * @return int Status code
     * @called_by Dolibarr hook: formObjectOptions
     * @calls KsefSubmission::fetchByInvoice()
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $langs, $db, $conf, $form;
        dol_include_once('/ksef/lib/ksef.lib.php');

        $currentcontext = $parameters['currentcontext'] ?? '';
        if ($currentcontext == 'invoicecard') {
            if (empty($object) || empty($object->id)) {
                if (GETPOST('action', 'aZ09') != 'create') {
                    return 0;
                }
            }

            dol_include_once('/ksef/class/ksef_submission.class.php');
            dol_include_once('/ksef/lib/ksef.lib.php');
            dol_include_once('/ksef/class/ksef_client.class.php');

            print '<style>tr:has(.facture_extras_ksef_number), tr:has(.facture_extras_ksef_status), tr:has(.facture_extras_ksef_submission_date) { display: none !important; }</style>';
            print '<style>tr:has(.facture_extras_ksef_correction_original_ht), tr:has(.facture_extras_ksef_correction_original_tva), tr:has(.facture_extras_ksef_correction_original_ttc), tr:has(.facture_extras_ksef_correction_discount_id), tr:has(.facture_extras_ksef_correction_original_discount_ids) { display: none !important; }</style>';
            print '<style>tr:has(.facture_extras_ksef_correction_reason), tr:has(.facture_extras_ksef_correction_type) { display: none !important; }</style>';
            print '<style>tr:has(.facture_extras_ksef_podmiot3), tr:has(.facture_extras_ksef_podmiot3_role) { display: none !important; }</style>';

            // Hide DodatkowyOpis mode override row unless feature is configured
            if (!empty($object) && !empty($object->id)) {
                $_dodActiveCheck_noteMode = getDolGlobalString('KSEF_DODATKOWY_OPIS_NOTE_MODE', 'simple');
                $_dodActiveCheck_efConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_EXTRAFIELDS', '');
                if ((!isset($object->array_options) || empty($object->array_options)) && method_exists($object, 'fetch_optionals')) {
                    $object->fetch_optionals();
                }
                $_dodActiveCheck_override = isset($object->array_options['options_ksef_dodatkowy_opis_mode']) ? trim((string) $object->array_options['options_ksef_dodatkowy_opis_mode']) : '';
                if ($_dodActiveCheck_noteMode === 'disabled' && empty($_dodActiveCheck_efConf) && $_dodActiveCheck_override === '') {
                    print '<style>tr:has(.facture_extras_ksef_dodatkowy_opis_mode) { display: none !important; }</style>';
                }
            }

            // Relocate ksef_sale_date below the invoice date field
            print '<script type="text/javascript">
jQuery(document).ready(function() {
    var saleDateRow = jQuery("tr").has(".facture_extras_ksef_sale_date");
    if (!saleDateRow.length) return;
    // Try edit button first (draft invoices)
    var dateRow = jQuery("a[href*=\'action=editinvoicedate\']").closest("table.nobordernopadding").closest("tr");
    // Fallback: find by label text (validated invoices where edit button is gone)
    if (!dateRow.length) {
        var label = ' . json_encode($langs->transnoentities("DateInvoice")) . ';
        dateRow = jQuery("table.border.tableforfield > tbody > tr").filter(function() {
            return jQuery(this).children("td").first().text().trim() === label;
        }).first();
    }
    if (dateRow.length) {
        saleDateRow.detach().insertAfter(dateRow).show();
    }
});
</script>';

            // Chain-based correction display
            $isInChain = false;
            if (!empty($object) && !empty($object->id)) {
                if ($object->type == Facture::TYPE_REPLACEMENT && !empty($object->fk_facture_source)) {
                    $isInChain = true;
                } else {
                    // Check for TYPE_REPLACEMENT child
                    $sql_has_replacement = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture"
                        . " WHERE fk_facture_source = " . ((int) $object->id)
                        . " AND type = " . Facture::TYPE_REPLACEMENT
                        . " AND fk_statut > 0 LIMIT 1";
                    $res_has_replacement = $db->query($sql_has_replacement);
                    if ($res_has_replacement && $db->num_rows($res_has_replacement) > 0) {
                        $isInChain = true;
                    }
                }
            }
            if ($isInChain) {
                $langs->load("ksef@ksef");
                dol_include_once('/ksef/lib/ksef.lib.php');
                $chain = ksefBuildCorrectionChain($object, $db);

                if (count($chain) > 1) {
                    // Sum payments across all invoices in chain
                    $total_paid_on_chain = 0;
                    foreach ($chain as $chainInv) {
                        $total_paid_on_chain += $chainInv->getSommePaiement(0);
                    }

                    // Find the last element
                    $latestInChain = $chain[count($chain) - 1];
                    $currentAmount = (float) $latestInChain->total_ttc;
                    $remaining = $currentAmount - $total_paid_on_chain;
                    $isViewingLatest = ($object->id == $latestInChain->id);

                    // Build chain table HTML with context-aware header
                    $chainNoteKey = $isViewingLatest ? 'KSEF_CorrectionChainCurrentNote' : 'KSEF_CorrectionChainCorrectedNote';
                    $chainHtml = '<table class="border tableforfield centpercent">';
                    $chainHtml .= '<tr class="liste_titre"><td colspan="6"><span class="fas fa-info-circle" style="margin-right: 6px;"></span>'
                        . $langs->trans($chainNoteKey) . '</td></tr>';
                    $chainHtml .= '<tr><td class="titlefieldmiddle"></td>';
                    $chainHtml .= '<td class="right"><span class="opacitymedium">' . $langs->trans('KSEF_CorrectionChainExclTax') . '</span></td>';
                    $chainHtml .= '<td class="right"><span class="opacitymedium">' . $langs->trans('KSEF_CorrectionChainTax') . '</span></td>';
                    $chainHtml .= '<td class="right"><span class="opacitymedium">' . $langs->trans('KSEF_CorrectionChainInclTax') . '</span></td>';
                    $chainHtml .= '<td class="right"><span class="opacitymedium">' . $langs->trans('KSEF_CorrectionChainDiff') . '</span></td>';
                    $chainHtml .= '<td class="right"><span class="opacitymedium">' . $langs->trans('KSEF_CorrectionChainStatus') . '</span></td>';
                    $chainHtml .= '</tr>';

                    $prevTtc = null;
                    foreach ($chain as $idx => $chainInv) {
                        $isCurrent = ($chainInv->id == $object->id);
                        $bold = $isCurrent ? 'font-weight: bold;' : '';

                        // Invoice link
                        $invLink = '<a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . $chainInv->id . '">'
                            . dol_escape_htmltag($chainInv->ref) . '</a>';

                        // Amounts
                        $ht = price($chainInv->total_ht, 0, $langs, 1, -1, -1, $conf->currency);
                        $tva = price($chainInv->total_tva, 0, $langs, 1, -1, -1, $conf->currency);
                        $ttc = price($chainInv->total_ttc, 0, $langs, 1, -1, -1, $conf->currency);

                        // Diff column - first row shows its own TTC as the baseline
                        if ($prevTtc === null) {
                            $diffHtml = price($chainInv->total_ttc, 0, $langs, 1, -1, -1, $conf->currency);
                        } else {
                            $diff = (float) $chainInv->total_ttc - $prevTtc;
                            if (abs($diff) < 0.01) {
                                $diffColor = '#666';
                            } elseif ($diff < 0) {
                                $diffColor = '#dc3545';
                            } else {
                                $diffColor = '#28a745';
                            }
                            $diffPrefix = ($diff > 0.01) ? '+' : '';
                            $diffHtml = '<span style="color:' . $diffColor . ';">' . $diffPrefix . price($diff, 0, $langs, 1, -1, -1, $conf->currency) . '</span>';
                        }
                        $prevTtc = (float) $chainInv->total_ttc;

                        // Status badges (actual payments, not paye flag)
                        $badges = '';
                        $invPaid = $chainInv->getSommePaiement(0);
                        if ($invPaid > 0 && $invPaid >= (float) $chainInv->total_ttc) {
                            $badges .= '<span class="badge badge-status6 badge-status">' . $langs->trans('BillStatusPaid') . '</span> ';
                        } elseif ($invPaid > 0) {
                            $badges .= '<span class="badge badge-status7 badge-status">' . $langs->trans('BillStatusStarted') . '</span> ';
                        } elseif ($chainInv->statut == Facture::STATUS_ABANDONED) {
                            $badges .= '<span class="badge badge-status5 badge-status">' . $langs->trans('BillStatusCanceled') . '</span> ';
                        } elseif ($chainInv->statut == Facture::STATUS_DRAFT) {
                            $badges .= '<span class="badge badge-status0 badge-status">' . $langs->trans('BillStatusDraft') . '</span> ';
                        }
                        // Chain-specific badges
                        $hasReplacement = ($idx < count($chain) - 1); // Not the last in chain = has been corrected
                        if ($hasReplacement) {
                            $badges .= '<span class="badge badge-status1 badge-status">' . $langs->trans('KSEF_CorrectionChainCorrected') . '</span> ';
                        } elseif ($idx == count($chain) - 1) {
                            $badges .= '<span class="badge badge-status4 badge-status">' . $langs->trans('KSEF_CorrectionChainCurrent') . '</span>';
                        }

                        $chainHtml .= '<tr style="' . $bold . '">';
                        $chainHtml .= '<td class="nowraponall">' . $invLink . '</td>';
                        $chainHtml .= '<td class="nowraponall right">' . $ht . '</td>';
                        $chainHtml .= '<td class="nowraponall right">' . $tva . '</td>';
                        $chainHtml .= '<td class="nowraponall right">' . $ttc . '</td>';
                        $chainHtml .= '<td class="nowraponall right">' . $diffHtml . '</td>';
                        $chainHtml .= '<td class="nowraponall right">' . $badges . '</td>';
                        $chainHtml .= '</tr>';
                    }

                    // Chain payment summary rows
                    $totalPaidFormatted = price($total_paid_on_chain, 0, $langs, 1, -1, -1, $conf->currency);
                    $currentAmountFormatted = price($currentAmount, 0, $langs, 1, -1, -1, $conf->currency);

                    $langs->load("bills");
                    if (abs($remaining) < 0.01) {
                        $remainLabel = $langs->trans('KSEF_CorrectionChainSettled');
                        $remainFormatted = price(0, 0, $langs, 1, -1, -1, $conf->currency);
                        $remainColor = '#28a745';
                    } elseif ($remaining < 0) {
                        $remainLabel = $langs->trans('RemainderToPayBack');
                        $remainFormatted = price(abs($remaining), 0, $langs, 1, -1, -1, $conf->currency);
                        $remainColor = '#e65100';
                    } else {
                        $remainLabel = $langs->trans('RemainderToPay');
                        $remainFormatted = price($remaining, 0, $langs, 1, -1, -1, $conf->currency);
                        $remainColor = '#dc3545';
                    }

                    $chainHtml .= '</table>';

                    $chainHtmlJs = dol_escape_js($chainHtml);

                    // Chain summary labels/values to inject into the Dolibarr amount table
                    $totalPaidLabelJs = dol_escape_js($langs->trans('KSEF_CorrectionChainTotalPaid'));
                    $totalPaidValueJs = dol_escape_js($totalPaidFormatted);
                    $currentAmountLabelJs = dol_escape_js($langs->trans('KSEF_CorrectionChainCurrentAmount'));
                    $currentAmountValueJs = dol_escape_js($currentAmountFormatted);
                    $remainLabelJs = dol_escape_js($remainLabel);
                    $remainValueJs = dol_escape_js($remainFormatted);

                    print '<script type="text/javascript">
jQuery(document).ready(function() {
    var amountTable = jQuery(".fichehalfright table.border.tableforfield.centpercent").first();
    if (amountTable.length) {
        amountTable.after(\'' . $chainHtmlJs . '\');

        // Add chain summary as extra columns to the 3 amount rows
        var amountRows = amountTable.find("tr").has("td.amountcard");
        if (amountRows.length >= 3) {
            amountRows.eq(0).append(\'<td class="opacitymedium right" style="padding-left: 12px;">' . $totalPaidLabelJs . '</td><td class="nowraponall right">' . $totalPaidValueJs . '</td>\');
            amountRows.eq(1).append(\'<td class="opacitymedium right" style="padding-left: 12px;">' . $currentAmountLabelJs . '</td><td class="nowraponall right">' . $currentAmountValueJs . '</td>\');
            amountRows.eq(2).append(\'<td class="opacitymedium right" style="padding-left: 12px;">' . $remainLabelJs . '</td><td class="nowraponall right" style="font-weight: bold; color: ' . $remainColor . ';">' . $remainValueJs . '</td>\');
            // Extend currency row colspan to match
            var currencyRow = amountTable.find("tr").first();
            var currencyTd = currencyRow.find("td").last();
            currencyTd.attr("colspan", 3);
        }
    }
});
</script>';

                    // Override the "Remaining" row on all invoices in the chain
                    if (!$isViewingLatest) {
                        // Corrected invoices: show "Superseded"
                        $overrideLabel = dol_escape_js($langs->trans('KSEF_CorrectionChainSuperseded'));
                        $overrideCssClass = 'amountpaymentneutral';
                        $overrideValue = '<span class="opacitymedium small">' . dol_escape_js($langs->trans('KSEF_CorrectionChainSuperseded')) . '</span>';
                    } else {
                        // Chain-calculated remaining
                        $overrideLabel = dol_escape_js($remainLabel);
                        if (abs($remaining) < 0.01) {
                            $overrideCssClass = 'amountpaymentcomplete';
                        } elseif ($remaining < 0) {
                            $overrideCssClass = 'amountremaintopayback';
                        } else {
                            $overrideCssClass = 'amountremaintopay';
                        }
                        $overrideValue = dol_escape_js($remainFormatted);
                    }
                    $overrideLabelJs = $overrideLabel;

                    print '<script type="text/javascript">
jQuery(document).ready(function() {
    var remainCell = jQuery("td.amountremaintopay, td.amountpaymentneutral, td.amountpaymentcomplete, td.amountremaintopayback");
    if (!remainCell.length) return;
    var row = remainCell.closest("tr");
    row.find("span.opacitymedium").text(\'' . $overrideLabelJs . '\');
    remainCell.replaceWith(\'<td class="right ' . $overrideCssClass . '">' . $overrideValue . '</td>\');
});
</script>';
                }
            }

            // Correction line item hint for draft invoices
            if (!empty($object) && !empty($object->id)
                && ($object->type == Facture::TYPE_REPLACEMENT || $object->type == Facture::TYPE_CREDIT_NOTE)
                && $object->statut == Facture::STATUS_DRAFT
                && !empty($object->fk_facture_source)) {
                $langs->load("ksef@ksef");
                $hintKey = ($object->type == Facture::TYPE_REPLACEMENT)
                    ? 'KSEF_CorrectionDraftHint'
                    : 'KSEF_CreditNoteDraftHint';
                $hintText = dol_escape_js($langs->trans($hintKey));
                print '<script type="text/javascript">
jQuery(document).ready(function() {
    var lineTable = jQuery("#tablelines, table.liste");
    if (lineTable.length) {
        lineTable.first().before(\'<div class="info" style="margin-bottom: 8px;"><span class="fas fa-info-circle" style="margin-right: 6px;"></span>' . $hintText . '</div>\');
    }
});
</script>';
            }

            // Hide Reopen on corrected originals
            if (!empty($object) && !empty($object->id)
                && $object->statut == Facture::STATUS_CLOSED
                && $object->getIdReplacingInvoice('validated') > 0) {
                print '<script type="text/javascript">
jQuery(document).ready(function() {
    jQuery("a.butAction[href*=\'action=reopen\']").each(function() {
        jQuery(this).replaceWith(\'<span class="butActionRefused classfortooltip" title="' . dol_escape_js($langs->trans('KSEF_CorrectionChainBlocked', '')) . '">\' + jQuery(this).text() + \'</span>\');
    });
});
</script>';
            }

            // Correction reason/type picker
            if (!empty($object) && !empty($object->id)
                && ($object->type == Facture::TYPE_CREDIT_NOTE || $object->type == Facture::TYPE_REPLACEMENT)) {
                $langs->load("ksef@ksef");
                if (!isset($object->array_options) || empty($object->array_options)) {
                    $object->fetch_optionals();
                }

                // Read-only check for accepted/offline invoices
                dol_include_once('/ksef/class/ksef_submission.class.php');
                $corrSubmission = new KsefSubmission($db);
                $corrHasSubmission = ($corrSubmission->fetchByInvoice($object->id) > 0);
                $corrIsAccepted = $corrHasSubmission && $corrSubmission->status == KsefSubmission::STATUS_ACCEPTED
                    && !empty($corrSubmission->ksef_number)
                    && strpos($corrSubmission->ksef_number, 'OFFLINE') === false
                    && strpos($corrSubmission->ksef_number, 'PENDING') === false
                    && strpos($corrSubmission->ksef_number, 'ERROR') === false;
                $corrIsOffline = $corrHasSubmission && $corrSubmission->status == KsefSubmission::STATUS_OFFLINE
                    && !empty($corrSubmission->fa3_xml);
                $corrReadonly = $corrIsAccepted || $corrIsOffline;

                $corrReasonPresets = array(
                    'Zwrot towaru'          => 'KSEF_CorrectionReasonPreset_return',
                    'Błędna ilość'          => 'KSEF_CorrectionReasonPreset_qty',  // @phan-suppress-current-line PhanPluginDuplicateArrayKey
                    'Błędna cena'           => 'KSEF_CorrectionReasonPreset_price',
                    'Błędna stawka VAT'     => 'KSEF_CorrectionReasonPreset_vat',
                    'Rabat potransakcyjny'  => 'KSEF_CorrectionReasonPreset_discount',
                    'Skonto'                => 'KSEF_CorrectionReasonPreset_skonto',
                    'Zwrot zaliczki'        => 'KSEF_CorrectionReasonPreset_advance',
                    'Błędne dane nabywcy'   => 'KSEF_CorrectionReasonPreset_buyer',
                );

                $currentReason = trim((string) ($object->array_options['options_ksef_correction_reason'] ?? ''));
                $currentType = trim((string) ($object->array_options['options_ksef_correction_type'] ?? ''));

                // Check if reason matches preset or custom
                $isPreset = !empty($currentReason) && array_key_exists($currentReason, $corrReasonPresets);
                $isCustom = !empty($currentReason) && !$isPreset;

                // Correction Details row
                $detailsLabel = $form->textwithpicto(
                    $langs->trans("KSEF_CorrectionDetails"),
                    $langs->trans("KSEF_CorrectionReasonAlwaysPolishHelp")
                );
                print '<tr><td class="titlefieldcreate">' . $detailsLabel . '</td><td colspan="3">';

                if ($corrReadonly) {
                    // Display as read-only text when accepted or offline
                    print '<div style="margin-bottom: 6px;">';
                    print $langs->trans("KSEF_CorrectionReason") . ': ';
                    if ($isPreset) {
                        print '<strong>' . $langs->trans($corrReasonPresets[$currentReason]) . '</strong>';
                    } elseif ($isCustom) {
                        print '<strong>' . dol_escape_htmltag($currentReason) . '</strong>';
                    } else {
                        print '<span class="opacitymedium">' . $langs->trans("KSEF_NotSet") . '</span>';
                    }
                    print '</div>';
                    print '<div>';
                    print $langs->trans("KSEF_CorrectionType") . ': ';
                    if (!empty($currentType) && in_array($currentType, array('1', '2', '3'))) {
                        print '<strong>' . $langs->trans("KSEF_CorrectionType" . $currentType) . '</strong>';
                    } else {
                        print '<span class="opacitymedium">' . $langs->trans("KSEF_NotSet") . '</span>';
                    }
                    print '</div>';
                } else {
                    // Editable form
                    print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?facid=' . $object->id . '">';
                    print '<input type="hidden" name="token" value="' . newToken() . '">';
                    print '<input type="hidden" name="action" value="ksef_save_correction_details">';

                    // Line 1: Reason
                    print '<div style="margin-bottom: 6px;">';
                    print $langs->trans("KSEF_CorrectionReason") . ': ';
                    print '<select name="ksef_correction_reason_preset" id="ksef_correction_reason_preset" style="min-width: 220px;">';
                    print '<option value=""></option>';
                    foreach ($corrReasonPresets as $plText => $langKey) {
                        $selected = ($isPreset && $currentReason === $plText) ? ' selected' : '';
                        print '<option value="' . dol_escape_htmltag($plText) . '"' . $selected . '>' . $langs->trans($langKey) . '</option>';
                    }
                    $otherSelected = $isCustom ? ' selected' : '';
                    print '<option value="custom"' . $otherSelected . '>' . $langs->trans("KSEF_CorrectionReasonPreset_other") . '</option>';
                    print '</select>';

                    // Custom Other
                    $customDisplay = $isCustom ? 'inline-block' : 'none';
                    $customValue = $isCustom ? dol_escape_htmltag($currentReason) : '';
                    print ' <input type="text" name="ksef_correction_reason_custom" id="ksef_correction_reason_custom"';
                    print ' value="' . $customValue . '" maxlength="256" style="display: ' . $customDisplay . '; width: 300px;"';
                    print ' placeholder="' . dol_escape_htmltag($langs->trans("KSEF_CorrectionReasonCustomPlaceholder")) . '">';
                    print '</div>';

                    // Line 2: Type + Save
                    print '<div>';
                    print $langs->trans("KSEF_CorrectionType") . ': ';
                    print '<select name="ksef_correction_type" style="min-width: 200px;">';
                    print '<option value=""></option>';
                    for ($t = 1; $t <= 3; $t++) {
                        $tSelected = ($currentType === (string) $t) ? ' selected' : '';
                        print '<option value="' . $t . '"' . $tSelected . '>' . $langs->trans("KSEF_CorrectionType" . $t) . '</option>';
                    }
                    print '</select>';
                    print ' <input type="submit" class="button smallpaddingimp" value="' . $langs->trans("Save") . '">';
                    print '</div>';

                    print '</form>';

                    // JS: toggle custom text field visibility
                    print '<script type="text/javascript">
jQuery(document).ready(function() {
    jQuery("#ksef_correction_reason_preset").on("change", function() {
        if (jQuery(this).val() === "custom") {
            jQuery("#ksef_correction_reason_custom").show().focus();
        } else {
            jQuery("#ksef_correction_reason_custom").hide().val("");
        }
    });
});
</script>';
                }
                print '</td></tr>';
            }

            // pdmiot3 picker
            if (getDolGlobalString('KSEF_PODMIOT3_SOURCE', 'disabled') === 'enabled'
                && !empty($object) && !empty($object->id) && $object->element === 'facture') {
                $langs->load("ksef@ksef");
                if (!isset($object->array_options) || empty($object->array_options)) {
                    $object->fetch_optionals();
                }

                dol_include_once('/ksef/class/ksef_submission.class.php');
                $p3Submission = new KsefSubmission($db);
                $p3HasSub = ($p3Submission->fetchByInvoice($object->id) > 0);
                $p3IsAccepted = $p3HasSub && $p3Submission->status == KsefSubmission::STATUS_ACCEPTED
                    && !empty($p3Submission->ksef_number)
                    && strpos($p3Submission->ksef_number, 'OFFLINE') === false
                    && strpos($p3Submission->ksef_number, 'PENDING') === false
                    && strpos($p3Submission->ksef_number, 'ERROR') === false;
                $p3IsOffline = $p3HasSub && $p3Submission->status == KsefSubmission::STATUS_OFFLINE
                    && !empty($p3Submission->fa3_xml);
                $p3Readonly = $p3IsAccepted || $p3IsOffline;

                $p3Roles = array('6', '2', '3', '4', '5', '7', '8', '9', '10', '11', '1');
                $p3DefaultRole = getDolGlobalString('KSEF_PODMIOT3_ROLE', '6');

                $p3Raw = $object->array_options['options_ksef_podmiot3'] ?? '';
                $p3List = array();
                $p3Decoded = (is_string($p3Raw) && $p3Raw !== '') ? json_decode($p3Raw, true) : null;
                if (is_array($p3Decoded)) {
                    foreach ($p3Decoded as $e) {
                        $eid = (int) ($e['id'] ?? 0);
                        if ($eid > 0) $p3List[] = array('id' => $eid, 'role' => (string) ($e['role'] ?? ''), 'udzial' => trim((string) ($e['udzial'] ?? '')));
                    }
                } elseif ((int) $p3Raw > 0) {
                    $p3List[] = array('id' => (int) $p3Raw, 'role' => trim((string) ($object->array_options['options_ksef_podmiot3_role'] ?? '')), 'udzial' => '');
                }

                require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
                $p3Existing = array();
                foreach ($p3List as $e) {
                    $rl = $e['role'] !== '' ? $e['role'] : $p3DefaultRole;
                    $nm = '';
                    $soc = new Societe($db);
                    if ($soc->fetch($e['id']) > 0) $nm = $soc->name;
                    $uTxt = ($rl === '4' && !empty($e['udzial'])) ? rtrim(rtrim($e['udzial'], '0'), '.') . '%' : '';
                    $p3Existing[] = array('id' => $e['id'], 'role' => $rl, 'udzial' => ($rl === '4' ? (string) $e['udzial'] : ''), 'name' => $nm, 'label' => $langs->trans('KSEF_Podmiot3_Role' . $rl) . ($uTxt !== '' ? ' (' . $uTxt . ')' : ''));
                }

                $p3Label = $form->textwithpicto($langs->trans('KSEF_Podmiot3_Details'), $langs->trans('KSEF_Podmiot3_Details_Help'));
                print '<tr><td class="titlefieldcreate">' . $p3Label . '</td><td colspan="3">';
                if ($p3Readonly) {
                    if (empty($p3Existing)) {
                        print '<span class="opacitymedium">' . $langs->trans('KSEF_NotSet') . '</span>';
                    } else {
                        print '<ul style="margin:0;padding-left:18px;">';
                        foreach ($p3Existing as $r) {
                            $nmTxt = $r['name'] !== '' ? dol_escape_htmltag($r['name']) : $langs->trans('KSEF_NotSet');
                            print '<li><strong>' . $nmTxt . '</strong> &mdash; ' . dol_escape_htmltag($r['label']) . '</li>';
                        }
                        print '</ul>';
                    }
                } else {
                    print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?facid=' . $object->id . '" id="ksef_p3_form">';
                    print '<input type="hidden" name="token" value="' . newToken() . '">';
                    print '<input type="hidden" name="action" value="ksef_save_podmiot3">';

                    print '<table id="ksef_p3_list" class="centpercent" style="margin-bottom:6px;"></table>';

                    // Mini-form
                    print '<table class="nobordernopadding"><tbody>';
                    print '<tr><td class="nowrap">' . $langs->trans('KSEF_Podmiot3_Party') . ': </td><td>';
                    print $form->select_company(0, 'ksef_podmiot3_pick', '', '&nbsp;', 0, 0, array(), 0, 'minwidth300');
                    print '</td></tr>';
                    print '<tr><td class="nowrap">' . $langs->trans('KSEF_Podmiot3_Role') . ': </td><td>';
                    print '<select id="ksef_podmiot3_role_pick">';
                    foreach ($p3Roles as $r) {
                        print '<option value="' . $r . '"' . ($r === $p3DefaultRole ? ' selected' : '') . '>' . $langs->trans('KSEF_Podmiot3_Role' . $r) . '</option>';
                    }
                    print '</select>';
                    print '</td></tr>';
                    // Udzial
                    print '<tr id="ksef_p3_udzial_row" style="display:none;"><td class="nowrap">' . $langs->trans('KSEF_Podmiot3_Udzial') . ': </td><td>';
                    print '<input type="text" id="ksef_podmiot3_udzial_pick" size="6" maxlength="6" placeholder="0-100"> %';
                    print ' <span class="opacitymedium">' . $langs->trans('KSEF_Podmiot3_Udzial_Help') . '</span>';
                    print '</td></tr>';
                    print '<tr><td></td><td>';
                    print '<button type="button" class="button smallpaddingimp" id="ksef_p3_add">' . $langs->trans('KSEF_Podmiot3_SaveAddAnother') . '</button>';
                    print ' <button type="button" class="button smallpaddingimp" id="ksef_p3_save">' . $langs->trans('Save') . '</button>';
                    print '</td></tr>';
                    print '</tbody></table>';
                    print '</form>';

                    $p3ExistingJson = json_encode(array_values($p3Existing));
                    $p3MaxMsg = dol_escape_js($langs->trans('KSEF_Podmiot3_Max'));
                    $p3RemoveTitle = dol_escape_js($langs->trans('KSEF_Podmiot3_Remove'));
                    print '<script>
                    (function() {
                        var MAX = 20;
                        var existing = ' . $p3ExistingJson . ';
                        function $list() { return document.getElementById("ksef_p3_list"); }
                        function addRow(id, role, name, label, udzial) {
                            var tb = $list();
                            if (!tb || tb.rows.length >= MAX) { if (tb) alert("' . $p3MaxMsg . '"); return false; }
                            var tr = tb.insertRow(-1);
                            var c1 = tr.insertCell(-1);
                            var nameSpan = document.createElement("strong"); nameSpan.textContent = name || "";
                            c1.appendChild(nameSpan);
                            c1.appendChild(document.createTextNode(" - " + (label || "")));
                            var h1 = document.createElement("input"); h1.type="hidden"; h1.name="ksef_podmiot3_party[]"; h1.value=id; c1.appendChild(h1);
                            var h2 = document.createElement("input"); h2.type="hidden"; h2.name="ksef_podmiot3_role[]"; h2.value=role; c1.appendChild(h2);
                            var h3 = document.createElement("input"); h3.type="hidden"; h3.name="ksef_podmiot3_udzial[]"; h3.value=(role === "4" ? (udzial || "") : ""); c1.appendChild(h3);
                            var c2 = tr.insertCell(-1); c2.style.textAlign="right"; c2.style.width="40px";
                            var rm = document.createElement("a"); rm.href="#"; rm.title="' . $p3RemoveTitle . '"; rm.innerHTML="&times;"; rm.style.color="#a00"; rm.style.fontWeight="bold";
                            rm.onclick = function(ev){ ev.preventDefault(); tr.parentNode.removeChild(tr); };
                            c2.appendChild(rm);
                            return true;
                        }
                        function toggleUdzial() {
                            var role = document.getElementById("ksef_podmiot3_role_pick").value;
                            document.getElementById("ksef_p3_udzial_row").style.display = (role === "4") ? "" : "none";
                        }
                        // true if a row was added
                        function commitPick($) {
                            var $pick = $("#ksef_podmiot3_pick");
                            var id = parseInt($pick.val(), 10);
                            if (!id || id <= 0) return false;
                            var name = $.trim($pick.find("option:selected").text());
                            var $role = $("#ksef_podmiot3_role_pick");
                            var role = $role.val();
                            var label = $.trim($role.find("option:selected").text());
                            var udzial = (role === "4") ? $.trim($("#ksef_podmiot3_udzial_pick").val()) : "";
                            if (udzial !== "") { label = label + " (" + udzial + "%)"; }
                            if (addRow(id, role, name, label, udzial)) {
                                $pick.val("").trigger("change");
                                $("#ksef_podmiot3_udzial_pick").val("");
                                return true;
                            }
                            return false;
                        }
                        jQuery(function($) {
                            existing.forEach(function(e){ addRow(e.id, e.role, e.name, e.label, e.udzial); });
                            $("#ksef_podmiot3_role_pick").on("change", toggleUdzial);
                            toggleUdzial();
                            $("#ksef_p3_add").on("click", function() {
                                commitPick($);
                            });
                            $("#ksef_p3_save").on("click", function() {
                                commitPick($);
                                document.getElementById("ksef_p3_form").submit();
                            });
                        });
                    })();
                    </script>';
                }
                print '</td></tr>';
            }

            $submission = new KsefSubmission($db);
            $result = (!empty($object) && !empty($object->id)) ? $submission->fetchByInvoice($object->id) : 0;

            if ($result > 0) {
                $langs->load("ksef@ksef");

                print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_Status") . '</td><td colspan="3">';
                print ksefGetStatusBadge($submission->status);

                if (!empty($submission->error_code) && in_array($submission->status, array('REJECTED', 'FAILED'))) {
                    $ksefClient = new KsefClient($db);
                    $errorDesc = $ksefClient->getErrorDescription($submission->error_code);
                    print ' <span class="fas fa-exclamation-triangle classfortooltip" style="color: #d9534f; cursor: help;" title="' . dol_escape_htmltag("Error {$submission->error_code}: {$errorDesc}") . '"></span>';
                }

                if (!empty($submission->ksef_number)) {
                    $is_online_ksef_number = (strpos($submission->ksef_number, 'OFFLINE') === false &&
                        strpos($submission->ksef_number, 'PENDING') === false &&
                        strpos($submission->ksef_number, 'ERROR') === false);

                    if ($is_online_ksef_number) {
                        $verifyUrl = ksefGetVerificationURL($submission->ksef_number, $submission->invoice_hash ?? null, $submission->environment);
                        print ' <a href="' . $verifyUrl . '" target="_blank" style="text-decoration: none;"><span class="badge badge-info">' . $submission->ksef_number . '</span></a>';
                    } else {
                        print ' <span class="badge badge-secondary" title="' . $langs->trans('KSEF_OfflineNumberNotYetSubmitted') . '">' . $submission->ksef_number . '</span>';
                    }
                }

                if (!empty($submission->date_submission)) {
                    print '<br><small style="color: #666;">' . $langs->trans("KSEF_SubmittedOn") . ': ' . dol_print_date($submission->date_submission, 'dayhour') . '</small>';
                }

                if ($submission->status == 'OFFLINE' && !empty($submission->offline_deadline)) {
                    print '<br><small style="color: #856404;"><i class="fas fa-clock"></i> ' . $langs->trans('KSEF_OfflineDeadline') . ': ' . ksefFormatDeadline($submission->offline_deadline) . '</small>';
                }

                print '</td></tr>';

                // Lock date fields once KSeF number is assigned
                if (!empty($submission->ksef_number)) {
                    print '<style>a[href*="attribute=ksef_sale_date"], a[href*="attribute=ksef_kurs_data"] { display: none !important; }</style>';
                }
            }

            // DodatkowyOpis preview panel
            $ctx = (!empty($object) && !empty($object->id)) ? $this->ksefComputeDodatkowyOpisContext($object) : array('featureActive' => false);
            if ($ctx['featureActive']) {
                // Preview panel
                print '<tr id="ksef_dodatkowy_preview_row"><td class="titlefieldcreate">' . $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW") . '</td><td colspan="3">';
                print $this->ksefRenderDodatkowyOpisPreviewInner($object);
                print '</td></tr>';
                print '<script type="text/javascript">
jQuery(document).ready(function() {
    var modeRow = jQuery("tr").has(".facture_extras_ksef_dodatkowy_opis_mode");
    if (!modeRow.length) return;
    var previewRow = jQuery("#ksef_dodatkowy_preview_row");
    if (previewRow.length) {
        modeRow.detach().insertBefore(previewRow).show();
    }
});
</script>';

                print $this->ksefRenderDodatkowyOpisHelperScript($object);
            }

            if (GETPOST('action', 'aZ09') == 'create') {
                $langs->load("ksef@ksef");
                $hintCreditNote = dol_escape_js($langs->trans('KSEF_HintCreditNote'));
                $hintReplacement = dol_escape_js($langs->trans('KSEF_HintReplacement'));
                print '<script type="text/javascript">
jQuery(document).ready(function() {
    var hintStyle = "display: block; color: #666; font-size: 0.85em; font-style: italic; margin: 2px 0 4px 24px;";
    var creditRadio = jQuery("#radio_creditnote");
    if (creditRadio.length) {
        creditRadio.closest(".listofinvoicetype").find(".classfortooltip").first()
            .after("<div style=\"" + hintStyle + "\">' . $hintCreditNote . '</div>");
    }
    var replaceRadio = jQuery("#radio_replacement");
    if (replaceRadio.length) {
        replaceRadio.closest(".listofinvoicetype").find(".classfortooltip").first()
            .after("<div style=\"" + hintStyle + "\">' . $hintReplacement . '</div>");
    }
});
</script>';

                $socid = GETPOST('socid', 'int');
                if ($socid > 0) {
                    $paid_invoices = array();
                    $sql_paid = "SELECT f.rowid, f.ref, f.total_ttc, f.date_valid";
                    $sql_paid .= " FROM " . MAIN_DB_PREFIX . "facture as f";
                    $sql_paid .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture as ff ON f.rowid = ff.fk_facture_source AND ff.fk_statut > 0";
                    $sql_paid .= " WHERE f.fk_soc = " . (int) $socid;
                    $sql_paid .= " AND f.fk_statut = " . Facture::STATUS_CLOSED;
                    $sql_paid .= " AND f.paye = 1";
                    $sql_paid .= " AND f.entity IN (" . getEntity('invoice') . ")";
                    $sql_paid .= " AND ff.rowid IS NULL"; // not already replaced
                    $sql_paid .= " ORDER BY f.ref";

                    $ksef_replacements = array();
                    $sql_kor = "SELECT f.rowid, f.ref, f.total_ttc, f.date_valid";
                    $sql_kor .= " FROM " . MAIN_DB_PREFIX . "facture as f";
                    $sql_kor .= " INNER JOIN " . MAIN_DB_PREFIX . "facture_extrafields as fe ON fe.fk_object = f.rowid";
                    $sql_kor .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture as ff ON f.rowid = ff.fk_facture_source AND ff.fk_statut > 0";
                    $sql_kor .= " WHERE f.fk_soc = " . (int) $socid;
                    $sql_kor .= " AND f.type = " . Facture::TYPE_REPLACEMENT;
                    $sql_kor .= " AND f.fk_statut IN (" . Facture::STATUS_VALIDATED . ", " . Facture::STATUS_CLOSED . ")";
                    $sql_kor .= " AND f.entity IN (" . getEntity('invoice') . ")";
                    $sql_kor .= " AND fe.ksef_number IS NOT NULL AND fe.ksef_number != ''";
                    $sql_kor .= " AND ff.rowid IS NULL"; // not already replaced by another
                    $sql_kor .= " ORDER BY f.ref";
                    $resql_kor = $db->query($sql_kor);
                    if ($resql_kor) {
                        while ($obj_kor = $db->fetch_object($resql_kor)) {
                            $ksef_replacements[$obj_kor->rowid] = array(
                                'id' => $obj_kor->rowid,
                                'ref' => $obj_kor->ref,
                                'label' => $obj_kor->ref . ' (' . $langs->trans('KSEF_KorektaOfKorekta') . ')',
                            );
                        }
                        $db->free($resql_kor);
                    }

                    $resql_paid = $db->query($sql_paid);
                    if ($resql_paid) {
                        while ($obj_paid = $db->fetch_object($resql_paid)) {
                            $paid_invoices[] = array(
                                'id' => $obj_paid->rowid,
                                'ref' => $obj_paid->ref,
                                'label' => $obj_paid->ref . ' (' . $langs->trans('Paid') . ')',
                            );
                        }
                        $db->free($resql_paid);
                    }

                    foreach ($ksef_replacements as $kor_id => $kor_inv) {
                        $already_in = false;
                        foreach ($paid_invoices as $pi) {
                            if ($pi['id'] == $kor_id) { $already_in = true; break; }
                        }
                        if (!$already_in) {
                            $paid_invoices[] = $kor_inv;
                        }
                    }

                    if (!empty($paid_invoices)) {
                        $js_invoices = json_encode($paid_invoices);
                        print '<script type="text/javascript">
jQuery(document).ready(function() {
    var paidInvoices = ' . $js_invoices . ';
    if (!paidInvoices.length) return;

    var sel = jQuery("#fac_replacement");
    if (!sel.length) sel = jQuery("select[name=\'fac_replacement\']");
    if (!sel.length) {
        console.warn("KSeF: Could not find replacement invoice dropdown (#fac_replacement)");
        return;
    }

    if (paidInvoices.length > 0) {
        sel.find("option[value=\'-1\']").each(function() {
            var txt = jQuery(this).text().trim();
            if (txt !== "" && txt !== "&nbsp;") {
                jQuery(this).remove();
            }
        });
    }

    for (var i = 0; i < paidInvoices.length; i++) {
        var inv = paidInvoices[i];
        // Skip if already in the list
        if (sel.find("option[value=\'" + inv.id + "\']").length > 0) continue;
        sel.append(jQuery("<option>", { value: inv.id, text: inv.label, "data-ksef-paid": "1" }));
    }

    if (!sel.find("option[value=\'-1\']").length) {
        sel.prepend(jQuery("<option>", { value: "-1", text: " " }));
    }

    if (paidInvoices.length > 0) {
        sel.prop("disabled", false);
        jQuery("#radio_replacement").prop("disabled", false);
    }

    try {
        if (sel.data("select2")) {
            sel.select2("destroy");
        }
        sel.select2({
            dir: "ltr",
            width: "resolve",
            minimumInputLength: 0,
            language: (typeof select2arrayoflanguage === "undefined") ? "en" : select2arrayoflanguage,
            theme: "default",
            containerCssClass: ":all:",
            selectionCssClass: ":all:",
            dropdownCssClass: "ui-dialog"
        });
    } catch(e) {
        console.warn("KSeF: Select2 refresh failed, dropdown may not be searchable: " + e.message);
    }

    var urlParams = new URLSearchParams(window.location.search);
    var preselect = urlParams.get("fac_replacement");
    if (preselect && sel.find("option[value=\'" + preselect + "\']").length > 0) {
        sel.val(preselect).trigger("change");
        jQuery("#radio_replacement").prop("checked", true).trigger("change");
    }
});
</script>';
                    }
                }
            }
        }

        if ($currentcontext == 'invoicesuppliercard') {
            if (empty($object) || empty($object->id)) {
                return 0;
            }

            $langs->load("ksef@ksef");

            // Hide auto-managed extrafields from default display
            print '<style>tr:has(.invoice_supplier_extras_ksef_number), tr:has(.invoice_supplier_extras_ksef_status), tr:has(.invoice_supplier_extras_ksef_submission_date), tr:has(.invoice_supplier_extras_ksef_kurs_data) { display: none !important; }</style>';

            // Relocate ksef_sale_date below the invoice date field
            print '<script type="text/javascript">
jQuery(document).ready(function() {
    var saleDateRow = jQuery("tr").has(".invoice_supplier_extras_ksef_sale_date");
    if (!saleDateRow.length) return;
    var dateRow = jQuery("a[href*=\'action=editinvoicedate\']").closest("table.nobordernopadding").closest("tr");
    if (!dateRow.length) {
        var label = ' . json_encode($langs->transnoentities("DateInvoice")) . ';
        dateRow = jQuery("table.border.tableforfield > tbody > tr, table.border.centpercent.tableforfield > tbody > tr").filter(function() {
            return jQuery(this).children("td").first().text().trim() === label;
        }).first();
    }
    if (dateRow.length) {
        saleDateRow.detach().insertAfter(dateRow).show();
    }
});
</script>';

            // Display corrected invoice info for correction invoices (both TYPE_STANDARD and TYPE_CREDIT_NOTE)
            // Rendered first so JS can reposition these rows near the top of the info table
            dol_include_once('/ksef/class/ksef_incoming.class.php');
            $incomingCorr = new KsefIncoming($db);
            if ($incomingCorr->fetchBySupplierInvoice($object->id) > 0 && KsefIncoming::isCorrectionType($incomingCorr->invoice_type)) {
                $correctionData = $incomingCorr->getCorrectionData();
                $resolvedCorrections = $incomingCorr->resolveCorrectedInvoices();
                $correctedCount = count($resolvedCorrections);

                // Replace mode detection
                $creditNoteInvoice = null;
                $linkedInvoice = null;
                $isReplaceMode = false;

                if ($incomingCorr->fk_credit_note > 0) {
                    $creditNoteInvoice = new FactureFournisseur($db);
                    if ($creditNoteInvoice->fetch($incomingCorr->fk_credit_note) <= 0) {
                        $creditNoteInvoice = null;
                    }
                }
                if ($incomingCorr->fk_facture_fourn > 0 && $incomingCorr->fk_facture_fourn != $object->id) {
                    $linkedInvoice = new FactureFournisseur($db);
                    if ($linkedInvoice->fetch($incomingCorr->fk_facture_fourn) <= 0) {
                        $linkedInvoice = null;
                    }
                }
                $isReplaceMode = ($creditNoteInvoice !== null && ($linkedInvoice !== null || $incomingCorr->fk_facture_fourn == $object->id));

                // Import mode row for replace mode
                if ($isReplaceMode) {
                    print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_ImportMode") . '</td>';
                    print '<td colspan="3"><span class="badgeneutral">' . $langs->trans("KSEF_UpwardCorrectionReplace") . '</span></td></tr>';
                }

                // Single corrected invoice: rich display
                if ($correctedCount == 1) {
                    $rc = $resolvedCorrections[0];
                    $isSelfReference = (!empty($rc['ksef_number']) && $rc['ksef_number'] === $incomingCorr->ksef_number);
                    print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_CorrectsInvoice") . '</td><td colspan="3">';

                    // KSeF number
                    print $langs->trans("KSEF_CorrectsKsefNr") . ': ';
                    if ($rc['incoming']) {
                        print $rc['incoming']->getNomUrl(1);
                    } elseif (!empty($rc['ksef_number'])) {
                        print dol_escape_htmltag($rc['ksef_number']);
                    } else {
                        print '<span class="opacitymedium">-</span>';
                    }
                    if ($isSelfReference) {
                        print '<br><span class="warning"><span class="fas fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_CorrectedKsefNumberSameAsOwn") . '</span>';
                    }

                    // Vendor invoice reference
                    if (!empty($rc['invoice_number'])) {
                        print '<br>' . $langs->trans("KSEF_VendorRef") . ': ';
                        print dol_escape_htmltag($rc['invoice_number']);
                    }

                    // Dolibarr supplier invoice
                    if ($rc['supplier_invoice']) {
                        print '<br>' . $langs->trans("KSEF_ImportedAs") . ': ';
                        print $rc['supplier_invoice']->getNomUrl(1) . ' ' . $rc['supplier_invoice']->getLibStatut(5);
                    }

                    print '</td></tr>';
                } elseif ($correctedCount > 1) {
                    // Multiple corrected invoices: summary row + table rendered via JS repositioning
                    print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_CorrectedInvoices") . '</td><td colspan="3">';
                    print $langs->trans("KSEF_CorrectsXInvoices", $correctedCount);
                    print '</td></tr>';
                }

                // Correction reason
                if (!empty($correctionData['reason'])) {
                    print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_CorrectionReason") . '</td>';
                    print '<td colspan="3">' . dol_escape_htmltag($correctionData['reason']) . '</td></tr>';
                }

                // Replace mode: show credit note and replacement invoice links
                if ($isReplaceMode) {
                    if ($creditNoteInvoice !== null) {
                        print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_ReplaceCreditNote") . '</td>';
                        print '<td colspan="3">' . $creditNoteInvoice->getNomUrl(1) . ' ' . $creditNoteInvoice->getLibStatut(5) . '</td></tr>';
                    }
                    if ($linkedInvoice !== null) {
                        print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_ReplaceNewInvoice") . '</td>';
                        print '<td colspan="3">' . $linkedInvoice->getNomUrl(1) . ' ' . $linkedInvoice->getLibStatut(5) . '</td></tr>';
                    }
                }
            }

            // Display KSeF status (formatted, replaces raw extrafields)
            $ksefNumber = $object->array_options['options_ksef_number'] ?? '';
            $ksefStatus = $object->array_options['options_ksef_status'] ?? '';
            $ksefSubmissionDate = $object->array_options['options_ksef_submission_date'] ?? '';

            dol_include_once('/ksef/lib/ksef.lib.php');

            print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_Status") . '</td><td colspan="3">';

            if (!empty($ksefNumber)) {
                if (!empty($ksefStatus)) {
                    print ksefGetStatusBadge($ksefStatus);
                    print ' ';
                }

                $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'DEMO');
                $is_online_ksef_number = (strpos($ksefNumber, 'OFFLINE') === false &&
                    strpos($ksefNumber, 'PENDING') === false &&
                    strpos($ksefNumber, 'ERROR') === false);

                if ($is_online_ksef_number) {
                    $verifyUrl = '';
                    if ($incomingCorr->id > 0 && !empty($incomingCorr->fa3_xml)) {
                        $verifyUrl = ksefGetVerificationUrlFromXml($ksefNumber, $incomingCorr->fa3_xml, $environment);
                    }

                    if (!empty($verifyUrl)) {
                        print '<a href="' . $verifyUrl . '" target="_blank" style="text-decoration: none;"><span class="badge badge-info">' . dol_escape_htmltag($ksefNumber) . '</span></a>';
                    } else {
                        print '<span class="badge badge-info">' . dol_escape_htmltag($ksefNumber) . '</span>';
                    }
                } else {
                    print '<span class="badge badge-secondary">' . dol_escape_htmltag($ksefNumber) . '</span>';
                }

                if (!empty($ksefSubmissionDate)) {
                    print '<br><small style="color: #666;">' . $langs->trans("KSEF_SubmittedOn") . ': ' . dol_print_date($ksefSubmissionDate, 'dayhour') . '</small>';
                }

                // Lock exchange rate date
                print '<style>a[href*="attribute=ksef_kurs_data"] { display: none !important; }</style>';
            } else {
                print '<span class="badge badge-status0 badge-status">' . $langs->trans("KSEF_NotSubmitted") . '</span>';
            }

            print '</td></tr>';

            // Display KSeF payment info
            if (!empty($ksefNumber) && $incomingCorr->id > 0 && !empty($incomingCorr->payment_status) && $incomingCorr->payment_status !== 'unpaid') {
                $notSpecified = '<span class="opacitymedium">' . $langs->trans("KSEF_NotSpecifiedInXml") . '</span>';

                // Status
                print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_PaymentStatus") . '</td><td colspan="3">';
                if ($incomingCorr->payment_status === 'paid') {
                    print '<span class="badge badge-status4 badge-status">' . $langs->trans("KSEF_SellerMarkedPaid") . '</span>';
                } elseif ($incomingCorr->payment_status === 'paid_installments') {
                    print '<span class="badge badge-status4 badge-status">' . $langs->trans("KSEF_SellerMarkedPaidInstallments") . '</span>';
                } elseif ($incomingCorr->payment_status === 'partial') {
                    print '<span class="badge badge-status1 badge-status">' . $langs->trans("KSEF_SellerMarkedPartial") . '</span>';
                }
                print '</td></tr>';

                // date
                print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_PaymentDate") . '</td><td colspan="3">';
                print $incomingCorr->payment_date ? dol_print_date($incomingCorr->payment_date, 'day') : $notSpecified;
                print '</td></tr>';

                // method - only show if Dolibarr's own payment method field is empty (otherwise it's duplicate)
                if (empty($object->mode_reglement_id)) {
                    print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_PaymentMethod") . '</td><td colspan="3">';
                    $methodLabel = ksefGetPaymentMethodLabel($incomingCorr->payment_method);
                    print $methodLabel ? dol_escape_htmltag($methodLabel) : $notSpecified;
                    print '</td></tr>';
                }

                // Amount
                print '<tr><td class="titlefieldcreate">' . $langs->trans("KSEF_PaymentAmount") . '</td><td colspan="3">';
                print price($incomingCorr->total_gross, 0, $langs, 0, -1, -1, $incomingCorr->currency);
                print '</td></tr>';
            }

            // Reposition correction rows near the top of the info table (after supplier/date rows)
            print '<script type="text/javascript">
jQuery(document).ready(function() {
    var corrRows = jQuery("tr").has("td:contains(\'' . dol_escape_js($langs->trans("KSEF_CorrectsInvoice")) . '\'), td:contains(\'' . dol_escape_js($langs->trans("KSEF_CorrectedInvoices")) . '\'), td:contains(\'' . dol_escape_js($langs->trans("KSEF_ImportMode")) . '\'), td:contains(\'' . dol_escape_js($langs->trans("KSEF_CorrectionReason")) . '\'), td:contains(\'' . dol_escape_js($langs->trans("KSEF_ReplaceCreditNote")) . '\'), td:contains(\'' . dol_escape_js($langs->trans("KSEF_ReplaceNewInvoice")) . '\')");
    if (!corrRows.length) return;
    // Find the invoice type row to insert after
    var anchorRow = jQuery("table.border.tableforfield > tbody > tr, table.border.centpercent.tableforfield > tbody > tr").filter(function() {
        var label = jQuery(this).children("td").first().text().trim();
        return label === ' . json_encode($langs->transnoentities("Type")) . ';
    }).first();
    if (anchorRow.length) {
        corrRows.detach().insertAfter(anchorRow);
    }
});
</script>';
        }

        if ($currentcontext == 'thirdpartycard') {
            if (!is_object($object) || empty($object->id)) {
                return 0;
            }

            $langs->load("ksef@ksef");

            print '<tr class="oddeven">';
            print '<td>' . $langs->trans("KSEF_ExcludeFromKSEF") . '</td>';
            print '<td colspan="3">';

            // Check if this is a customer (client = 1 customer, 3 customer+supplier). 0 means not client and 2 is prospect.
            $client_type = isset($object->client) ? (int)$object->client : 0;
            $is_customer = in_array($client_type, array(1, 3));

            if (!$is_customer) {
                print '<span class="opacitymedium"><i class="fa fa-info-circle"></i> ' . $langs->trans("KSEF_NotApplicableNotCustomer") . '</span>';
            } else {
                $is_excluded = ksefIsCustomerExcluded($object->id);

                if ($action == 'edit' || $action == 'create') {
                    print '<input type="checkbox" name="ksef_exclude" id="ksef_exclude" value="1"' . ($is_excluded ? ' checked' : '') . '>';
                    print ' <label for="ksef_exclude">' . $langs->trans("KSEF_ExcludeFromKSEFHelp") . '</label>';
                } else {
                    if ($is_excluded) {
                        print '<span class="badge badge-warning">' . $langs->trans("Yes") . '</span>';
                        print ' <span class="opacitymedium">(' . $langs->trans("KSEF_CustomerExcludedInfo") . ')</span>';
                    } else {
                        print '<span class="badge badge-status4">' . $langs->trans("No") . '</span>';
                    }
                }
            }

            print '</td></tr>';
        }

        return 0;
    }

    /**
     * @brief Renders the notes preview
     * @return int Status code
     * @called_by Dolibarr hook: printCommonFooter
     */
    public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
    {
        global $langs, $db, $conf;

        $currentcontext = $parameters['currentcontext'] ?? '';
        if ($currentcontext !== 'invoicenote') return 0;

        $id = GETPOSTINT('id');
        if (empty($id)) {
            $ref = GETPOST('ref', 'alpha');
            if (empty($ref)) return 0;
        }
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        $invoice = new Facture($db);
        $fetchRes = !empty($id) ? $invoice->fetch($id) : $invoice->fetch(0, $ref);
        if ($fetchRes <= 0 || empty($invoice->id)) return 0;
        $invoice->fetch_optionals();

        dol_include_once('/ksef/class/fa3_builder.class.php');
        $langs->load('ksef@ksef');

        $ctx = $this->ksefComputeDodatkowyOpisContext($invoice);
        if (!$ctx['featureActive']) return 0;

        $currentOverride = $ctx['dodOverride'];

        // Build the notes panel
        print '<div id="ksef_notes_panel" style="margin:12px 0; padding:12px; border:1px solid #e0e0e0; border-radius:4px; background:#fafbfc;">';
        print '<h4 style="margin:0 0 8px 0;">' . $langs->trans("KSEF_DODATKOWY_OPIS_SECTION") . '</h4>';

        // Override dropdown
        print '<form method="POST" action="' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '?id=' . (int) $invoice->id . '" style="margin-bottom:10px;">';
        print '<input type="hidden" name="token" value="' . newToken() . '">';
        print '<input type="hidden" name="action" value="ksef_set_note_override">';
        print '<input type="hidden" name="id" value="' . (int) $invoice->id . '">';
        print '<label for="ksef_note_override_sel" style="margin-right:6px;">' . $langs->trans("KSEF_ExtraFieldDodatkowyOpisMode") . ':</label>';
        print '<select name="ksef_override_value" id="ksef_note_override_sel" class="flat">';
        $modeOptions = array(
            '' => 'KSEF_DodatkowyOpisMode_Default',
            'simple' => 'KSEF_DodatkowyOpisMode_Simple',
            'keyvalue' => 'KSEF_DodatkowyOpisMode_KeyValue',
            'disabled' => 'KSEF_DodatkowyOpisMode_Disabled',
        );
        foreach ($modeOptions as $val => $labelKey) {
            $sel = ($currentOverride === $val) ? ' selected' : '';
            print '<option value="' . dol_escape_htmltag($val) . '"' . $sel . '>' . dol_escape_htmltag($langs->trans($labelKey)) . '</option>';
        }
        print '</select> ';
        print '<button type="submit" class="button button-small">' . $langs->trans("Save") . '</button>';
        print '</form>';

        // Preview pane
        print '<div style="margin-top:8px;"><strong>' . $langs->trans("KSEF_DODATKOWY_OPIS_PREVIEW") . '</strong></div>';
        print $this->ksefRenderDodatkowyOpisPreviewInner($invoice, false);
        print '</div>';
        print '<script type="text/javascript">
jQuery(document).ready(function() {
    var panel = jQuery("#ksef_notes_panel");
    if (!panel.length) return;
    var anchor = jQuery("#dragDropAreaTabBar .tagtable").first();
    if (!anchor.length) anchor = jQuery(".fiche .tagtable").first();
    if (!anchor.length) anchor = jQuery("textarea[name=\'note_public\']").first().closest(".tagtable");
    if (!anchor.length) anchor = jQuery(".tagtable").first();
    if (anchor.length) {
        panel.detach().insertAfter(anchor);
    }
});
</script>';

        print $this->ksefRenderDodatkowyOpisHelperScript($invoice);

        return 0;
    }

    /**
     * @brief Adds submission count badge to KSeF tab
     * @return int Status code
     * @called_by Dolibarr hook: completeTabsHead
     */
    public function completeTabsHead($parameters, &$object, &$action, $hookmanager)
    {
        global $db;

        if ($object->element != 'facture' || empty($parameters['head']) || !is_array($parameters['head'])) return 0;

        foreach ($parameters['head'] as $key => $tab) {
            if (isset($tab[2]) && $tab[2] == 'ksef') {
                $sql = "SELECT COUNT(*) as count FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int)$object->id;
                $resql = $db->query($sql);
                if ($resql) {
                    $obj = $db->fetch_object($resql);
                    if ($obj->count > 0) {
                        $parameters['head'][$key][1] = 'KSeF <span class="badge marginleftonlyshort">' . $obj->count . '</span>';
                    }
                    $db->free($resql);
                }
                break;
            }
        }
        return 0;
    }

    /**
     * @brief Handles mass actions for invoice list
     * @param $parameters Hook parameters
     * @param $object Invoice object
     * @param $action Current action
     * @param $hookmanager Hook manager
     * @return int Status code
     * @called_by Dolibarr hook: doMassActions
     */
    public function doMassActions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $db;

        if (!in_array($parameters['currentcontext'], array('invoicelist'))) return 0;

        if ($parameters['massaction'] == "ksefZip") {
            $obj = new Facture($db);
            $destdir = stripslashes($parameters['diroutputmassaction']);

            if (!is_dir($destdir)) mkdir($destdir, 0700, true);

            $zipname = $destdir . '/ksef-invoices-' . date('Ymd-His') . '.zip';
            $zip = new ZipArchive();

            if ($zip->open($zipname, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                foreach ($parameters['toselect'] as $objectid) {
                    if ($obj->fetch($objectid) > 0) {
                        $sanitizedRef = dol_sanitizeFileName($obj->ref);
                        $pdffile = $conf->facture->dir_output . '/' . $sanitizedRef . "/" . $sanitizedRef . ".pdf";
                        if (file_exists($pdffile)) $zip->addFile($pdffile, $sanitizedRef . ".pdf");

                        $xmlfile = $conf->facture->dir_output . '/' . $sanitizedRef . "/" . $sanitizedRef . "_fa3.xml";
                        if (file_exists($xmlfile)) $zip->addFile($xmlfile, $sanitizedRef . "_fa3.xml");

                        $submission = new KsefSubmission($db);
                        if ($submission->fetchByInvoice($objectid) > 0 && !empty($submission->upo_xml)) {
                            $zip->addFromString($sanitizedRef . "_upo.xml", $submission->upo_xml);
                        }
                    }
                }
                $zip->close();

                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename=' . basename($zipname));
                header('Content-Length: ' . filesize($zipname));
                readfile($zipname);
                unlink($zipname);
                exit;
            } else {
                $this->errors[] = 'Failed to create ZIP archive';
                return -1;
            }
        }
        return 0;
    }

    /**
     * @brief Adds KSeF options to mass actions
     * @param $parameters Hook parameters
     * @param $object Invoice object
     * @param $action Current action
     * @param $hookmanager Hook manager
     * @return int Status code
     * @called_by Dolibarr hook: addMoreMassActions
     */
    public function addMoreMassActions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $langs;

        if (empty($conf->ksef) || empty($conf->ksef->enabled)) return 0;
        if (!in_array($parameters['currentcontext'], array('invoicelist'))) return 0;

        $langs->load("ksef@ksef");
        $this->resprints = '<option value="ksefZip">' . $langs->trans("KSEF_DownloadKSEFZip") . '</option>';

        return 0;
    }

    /**
     * @brief Adds QR code to invoice PDF
     * @param $parameters Hook parameters
     * @param $object Invoice object
     * @param $action Current action
     * @param $hookmanager Hook manager
     * @return int Status code
     * @called_by Dolibarr hook: beforePDFCreation
     */
    public function beforePDFCreation($parameters, &$object, &$action, $hookmanager)
    {
        if (empty($object) || $object->element != 'facture') return 0;
        if ($object->type != Facture::TYPE_REPLACEMENT) return 0;

        // Override translation key for PDF templates
        $outputlangs = $parameters['outputlangs'] ?? null;
        if (!empty($outputlangs) && is_object($outputlangs)) {
            $outputlangs->load("ksef@ksef");
            $corrLabel = $outputlangs->transnoentities('KSEF_CorrectionInvoice');
            if (!empty($corrLabel) && $corrLabel != 'KSEF_CorrectionInvoice') {
                $outputlangs->tab_translate['InvoiceReplacement'] = $corrLabel;
                $outputlangs->tab_translate['InvoiceReplacementShort'] = $corrLabel;
            }
        }

        return 0;
    }

    /**
     * @called_by Dolibarr hook: afterPDFTotalTable
     * @calls KsefQR::addQRToPDF()
     */
    public function afterPDFTotalTable($parameters, $object, $action, $hookmanager)
    {
        global $conf, $ksefqralreadyadded;

        if (!empty($ksefqralreadyadded)) return 0;
        if (empty($conf->ksef) || empty($conf->ksef->enabled)) return 0;

        $object = $parameters['object'] ?? $object;

        if (empty($object) || $object->element != 'facture') return 0;
        if (empty($conf->global->KSEF_ADD_QR)) return 0;

        if (!isset($parameters['pdf']) || !is_object($parameters['pdf']) || !method_exists($parameters['pdf'], 'write2DBarcode')) {
            return 0;
        }

        dol_include_once('/ksef/class/ksef_qr.class.php');

        try {
            $qrGenerator = new KsefQR($this->db);
            $result = $qrGenerator->addQRToPDF($parameters['pdf'], $object->id);

            if ($result) {
                $ksefqralreadyadded = true;
                dol_syslog('KSeF: QR code added to invoice ' . $object->ref, LOG_INFO);
            }
        } catch (Exception $e) {
            dol_syslog('KSeF: Exception during QR generation - ' . $e->getMessage(), LOG_ERR);
        }

        return 0;
    }

    /**
     * @brief Creates a data object from KsefSubmission for PDF generation
     * @param KsefSubmission $submission The submission object
     * @param Facture $invoice The invoice object
     * @return object Object for PDF generator
     * @called_by doActions() for ksef_generate_pdf action
     * @calls FA3Parser::parse()
     */
    public function createPdfDataFromSubmission($submission, $invoice)
    {
        $data = new stdClass();
        $data->rowid = $submission->rowid;
        $data->ksef_number = $submission->ksef_number;
        $data->fa3_xml = $submission->fa3_xml;
        $data->environment = $submission->environment;
        $data->invoice_number = $invoice->ref;
        $data->invoice_type = 'VAT';
        $data->invoice_date = $invoice->date;
        $data->total_gross = $invoice->total_ttc;
        $data->total_net = $invoice->total_ht;
        $data->total_vat = $invoice->total_tva;
        $data->currency = $invoice->multicurrency_code ?: 'PLN';
        $data->offline_mode = $submission->offline_mode;
        $data->offline_deadline = $submission->offline_deadline;
        $data->invoice_hash = $submission->invoice_hash;
        $data->seller_nip = null;
        $data->seller_name = null;
        $data->seller_address = null;
        $data->seller_country = 'PL';
        $data->buyer_nip = null;
        $data->buyer_name = null;
        $data->sale_date = null;
        $data->payment_method = null;

        if (!empty($submission->fa3_xml)) {
            dol_include_once('/ksef/class/fa3_parser.class.php');

            $parser = new FA3Parser($this->db);
            $parsed = $parser->parse($submission->fa3_xml);

            if ($parsed) {
                if (!empty($parsed['invoice']['number'])) {
                    $data->invoice_number = $parsed['invoice']['number'];
                }
                if (!empty($parsed['invoice']['type'])) {
                    $data->invoice_type = $parsed['invoice']['type'];
                }
                if (!empty($parsed['invoice']['date'])) {
                    $data->invoice_date = $parsed['invoice']['date'];
                }
                if (!empty($parsed['invoice']['sale_date'])) {
                    $data->sale_date = $parsed['invoice']['sale_date'];
                }
                if (!empty($parsed['invoice']['currency'])) {
                    $data->currency = $parsed['invoice']['currency'];
                }
                if (($parsed['invoice']['total_gross'] ?? 0) != 0) {
                    $data->total_gross = $parsed['invoice']['total_gross'];
                }
                if (($parsed['invoice']['total_net'] ?? 0) != 0) {
                    $data->total_net = $parsed['invoice']['total_net'];
                }
                if (($parsed['invoice']['total_vat'] ?? 0) != 0) {
                    $data->total_vat = $parsed['invoice']['total_vat'];
                }

                $data->seller_nip = $parsed['seller']['nip'] ?? null;
                $data->seller_name = $parsed['seller']['name'] ?? null;
                $data->seller_country = $parsed['seller']['country'] ?? 'PL';
                $data->seller_address = $parsed['seller']['address'] ?? null;

                $data->buyer_nip = $parsed['buyer']['nip'] ?? null;
                $data->buyer_name = $parsed['buyer']['name'] ?? null;
            } else {
                dol_syslog("ActionsKSEF::createPdfDataFromSubmission FA3Parser error: " . $parser->error, LOG_WARNING);
            }
        }

        return $data;
    }

    /**
     * @brief Displays NBP exchange rate info and button
     * @param array $parameters Hook parameters
     * @param Facture $object Invoice object
     * @return int Status code
     * @called_by formBuilddocOptions hook
     */
    public function displayNBPRateInfo($parameters, &$object)
    {
        global $conf, $langs, $db, $user;

        if (empty($conf->ksef) || empty($conf->ksef->enabled)) {
            return 0;
        }

        $contextArray = explode(':', $parameters['context']);
        if (!in_array('invoicecard', $contextArray)) {
            return 0;
        }

        // must have multicurrency enabled
        if (empty($conf->multicurrency) || empty($conf->multicurrency->enabled)) {
            return 0;
        }

        $langs->load("ksef@ksef");
        dol_include_once('/ksef/lib/ksef.lib.php');
        dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');

        $nbp = new KsefNbpCurrencyRate($db);

        if (!$nbp->invoiceNeedsNBPRate($object)) {
            return 0;
        }

        $invoiceCurrency = $nbp->getInvoiceCurrency($object);
        $rateInfo = $nbp->getFormattedRateInfo($object);
        $canEdit = !empty($user->rights->facture->creer);
        $isDraft = ($object->statut == Facture::STATUS_DRAFT);

        $nbpContent = '';

        if (!empty($rateInfo['date'])) {
            $nbpContent .= '<span style="color: #28a745;"><i class="fa fa-check-circle"></i> ';
            $nbpContent .= htmlspecialchars($rateInfo['date']);
            $nbpContent .= '</span>';
            if ($canEdit && $isDraft) {
                $nbpContent .= ' <a class="button buttongen reposition" style="padding: 2px 8px; font-size: 0.85em; margin-left: 10px;" ';
                $nbpContent .= 'href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_fetch_nbp_rate&token=' . newToken() . '" ';
                $nbpContent .= 'title="' . dol_escape_htmltag($langs->trans('KSEF_RefreshNBPRateTooltip')) . '">';
                $nbpContent .= '<i class="fas fa-sync-alt"></i> ' . $langs->trans('KSEF_RefreshNBPRate') . '</a>';
            }
        } else {
            $nbpContent .= '<span style="color: #dc3545;"><i class="fas fa-exclamation-triangle"></i> ';
            $nbpContent .= $langs->trans('KSEF_NBPRateMissing');
            $nbpContent .= '</span>';

            if ($canEdit) {
                $nbpContent .= ' <a class="button buttongen reposition" style="padding: 2px 8px; font-size: 0.85em; margin-left: 10px; background: #28a745; border-color: #28a745; color: white;" ';
                $nbpContent .= 'href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=ksef_fetch_nbp_rate&token=' . newToken() . '" ';
                $nbpContent .= 'title="' . dol_escape_htmltag($langs->trans('KSEF_FetchNBPRateTooltip')) . '">';
                $nbpContent .= '<i class="fa fa-download"></i> ' . $langs->trans('KSEF_FetchNBPRate') . '</a>';
            }
        }

        $disabledTooltip = $langs->trans('KSEF_MulticurrencyRefreshDisabled');

        $out = '<script type="text/javascript">
jQuery(document).ready(function() {
    var nbpCell = jQuery("td[id^=\'facture_extras_ksef_kurs_data_\']");
    if (nbpCell.length > 0) {
        var newContent = ' . json_encode($nbpContent) . ';
        var hiddenInput = nbpCell.find("input[type=\'hidden\']");
        if (hiddenInput.length > 0) {
            nbpCell.html("").append(hiddenInput).append(newContent);
        } else {
            nbpCell.html(newContent);
        }
    }

    // Disable the multicurrency module rate refresh button on ksef invoices
    var multicurrencyRefreshLink = jQuery("a[href*=\'action=actualizemulticurrencyrate\']");
    if (multicurrencyRefreshLink.length > 0) {
        multicurrencyRefreshLink.removeAttr("href");
        multicurrencyRefreshLink.css({
            "opacity": "0.5",
            "cursor": "not-allowed",
            "pointer-events": "none"
        });
        multicurrencyRefreshLink.attr("title", ' . json_encode($disabledTooltip) . ');
        multicurrencyRefreshLink.find("span.fas").attr("title", ' . json_encode($disabledTooltip) . ');
        multicurrencyRefreshLink.addClass("classfortooltip");
    }
});
</script>';

        $this->resprints .= $out;
        return 0;
    }

    /**
     * @brief Override status display for KSeF-corrected invoices
     * @param array $parameters Hook parameters
     * @param CommonInvoice $object
     * @param string $action
     * @param HookManager $hookmanager
     * @return int 0 = no override, 1 = override
     * @called_by Dolibarr hook: LibStatut
     */
    public function LibStatut($parameters, &$object, &$action, $hookmanager)
    {
        global $langs, $db, $conf;

        if (empty($conf->ksef) || empty($conf->ksef->enabled)) {
            return 0;
        }

        // Customer invoices only
        if (!is_object($object) || $object->element !== 'facture') {
            return 0;
        }

        if ($parameters['status'] != Facture::STATUS_ABANDONED) {
            return 0;
        }
        if (empty($object->close_code) || $object->close_code != 'replaced') {
            return 0;
        }

        // Find replacing invoice
        $replacingId = $object->getIdReplacingInvoice('validated');
        if ($replacingId <= 0) {
            return 0;
        }

        $langs->load("ksef@ksef");

        $mode = $parameters['mode'];
        $labelShort = $langs->trans('KSEF_StatusCorrected');
        $labelLong = $langs->trans('KSEF_StatusCorrectedLong');
        $statusType = 'status4'; // info badge, distinct from status5

        $this->resprints = dolGetStatus($labelLong, $labelShort, '', $statusType, $mode);
        return 1;
    }

    // Accounting hooks
    /**
     * @brief Returns delta amounts for TYPE_REPLACEMENT (replacement - original)
     * @param Facture $invoice Must have extrafields
     * @return array|null delta_ht/tva/ttc + original_ht/tva/ttc, or null
     * @called_by processedJournalData(), facdao(), addVatLine()
     */
    private function getCorrectionDelta($invoice)
    {
        if ($invoice->type != Facture::TYPE_REPLACEMENT) {
            return null;
        }
        if (!isset($invoice->array_options['options_ksef_correction_original_ht'])) {
            $invoice->fetch_optionals();
        }
        $orig_ht = $invoice->array_options['options_ksef_correction_original_ht'] ?? null;
        $orig_tva = $invoice->array_options['options_ksef_correction_original_tva'] ?? null;
        $orig_ttc = $invoice->array_options['options_ksef_correction_original_ttc'] ?? null;
        if ($orig_ht === null || $orig_ht === '') {
            return null;
        }
        return array(
            'delta_ht' => $invoice->total_ht - (float) $orig_ht,
            'delta_tva' => $invoice->total_tva - (float) $orig_tva,
            'delta_ttc' => $invoice->total_ttc - (float) $orig_ttc,
            'original_ht' => (float) $orig_ht,
            'original_tva' => (float) $orig_tva,
            'original_ttc' => (float) $orig_ttc,
        );
    }

    /**
     * @brief Fetch original invoice line-level breakdowns
     * @param Facture $replacement Invoice with fk_facture_source
     * @return array|null by_rate and by_account breakdowns, or null
     * @called_by processedJournalData()
     */
    private function getOriginalLineBreakdown($replacement)
    {
        if (empty($replacement->fk_facture_source)) {
            return null;
        }
        $original = new Facture($this->db);
        if ($original->fetch($replacement->fk_facture_source) <= 0) {
            return null;
        }
        $original->fetch_thirdparty();

        // Per-rate breakdown
        $by_rate = array();
        foreach ($original->lines as $line) {
            $rate_key = (string) $line->tva_tx;
            if (!empty($line->vat_src_code)) {
                $rate_key .= ' (' . $line->vat_src_code . ')';
            }
            if (!isset($by_rate[$rate_key])) {
                $by_rate[$rate_key] = array('ht' => 0, 'tva' => 0, 'ttc' => 0);
            }
            $by_rate[$rate_key]['ht'] += $line->total_ht;
            $by_rate[$rate_key]['tva'] += $line->total_tva;
            $by_rate[$rate_key]['ttc'] += $line->total_ttc;
        }

        // Per-account breakdown
        global $mysoc;
        $by_account = array();
        $by_vat_account = array();
        $vat_account_rates = array();
        foreach ($original->lines as $line) {
            // Product/service account
            $compta_prod = '';
            if (!empty($line->fk_code_ventilation) && $line->fk_code_ventilation > 0) {
                $sql = "SELECT account_number FROM " . MAIN_DB_PREFIX . "accounting_account"
                    . " WHERE rowid = " . (int) $line->fk_code_ventilation;
                $res = $this->db->query($sql);
                if ($res) {
                    $obj = $this->db->fetch_object($res);
                    if ($obj) {
                        $compta_prod = $obj->account_number;
                    }
                    $this->db->free($res);
                }
            }
            if (empty($compta_prod)) {
                // Fallback to defaults
                if ($line->product_type == 0) {
                    $compta_prod = getDolGlobalString('ACCOUNTING_PRODUCT_SOLD_ACCOUNT', 'NotDefined');
                } else {
                    $compta_prod = getDolGlobalString('ACCOUNTING_SERVICE_SOLD_ACCOUNT', 'NotDefined');
                }
            }
            if (!isset($by_account[$compta_prod])) {
                $by_account[$compta_prod] = 0;
            }
            $by_account[$compta_prod] += $line->total_ht;

            // VAT account
            $vatrate_string = $line->tva_tx;
            if (!empty($line->vat_src_code)) {
                $vatrate_string .= ' (' . $line->vat_src_code . ')';
            }
            $vatdata = getTaxesFromId($vatrate_string, null, $mysoc, 0);
            $compta_tva = '';
            if (is_array($vatdata) && !empty($vatdata['accountancy_code_sell'])) {
                $compta_tva = $vatdata['accountancy_code_sell'];
            }
            if (!empty($compta_tva)) {
                if (!isset($by_vat_account[$compta_tva])) {
                    $by_vat_account[$compta_tva] = 0;
                }
                $by_vat_account[$compta_tva] += $line->total_tva;
                // Map VAT account to rate string
                $vat_account_rates[$compta_tva][$vatrate_string] = $vatrate_string;
            }
        }

        // Customer TTC account
        $compta_soc = '';
        if (!empty($original->thirdparty)) {
            $compta_soc = $original->thirdparty->code_compta_client;
        }
        if (empty($compta_soc)) {
            $compta_soc = getDolGlobalString('ACCOUNTING_ACCOUNT_CUSTOMER', 'NotDefined');
        }
        $by_ttc_account = array($compta_soc => $original->total_ttc);

        return array(
            'by_rate' => $by_rate,
            'by_account' => $by_account,
            'by_vat_account' => $by_vat_account,
            'vat_account_rates' => $vat_account_rates,
            'by_ttc_account' => $by_ttc_account,
            'original' => $original,
        );
    }

    /**
     * @brief Adjust sells journal amounts for correction deltas
     * @param array $parameters Hook parameters
     * @param CommonObject $object
     * @param string $action
     * @param HookManager $hookmanager
     * @called_by Dolibarr hook: processedJournalData
     */
    public function processedJournalData($parameters, &$object, &$action, $hookmanager)
    {
        if (!in_array('sellsjournal', explode(':', $parameters['context'] ?? ''))) {
            return 0;
        }

        $tabfac = &$parameters['tabfac'];
        $tabht = &$parameters['tabht'];
        $tabtva = &$parameters['tabtva'];
        $tabttc = &$parameters['tabttc'];
        $def_tva = &$parameters['def_tva'];

        foreach ($tabfac as $invoice_id => $facinfo) {
            if (($facinfo['type'] ?? -1) != Facture::TYPE_REPLACEMENT) {
                continue;
            }

            $invoice = new Facture($this->db);
            if ($invoice->fetch($invoice_id) <= 0) {
                continue;
            }
            $delta = $this->getCorrectionDelta($invoice);
            if ($delta === null) {
                continue;
            }

            // Get original
            $breakdown = $this->getOriginalLineBreakdown($invoice);
            if ($breakdown === null) {
                continue;
            }

            // Subtract original
            if (isset($tabht[$invoice_id])) {
                foreach ($breakdown['by_account'] as $account => $orig_amount) {
                    if (isset($tabht[$invoice_id][$account])) {
                        $tabht[$invoice_id][$account] -= $orig_amount;
                    }
                    // Account missing in replacement - add negative entry
                    else {
                        $tabht[$invoice_id][$account] = -$orig_amount;
                    }
                }
            }

            if (isset($tabtva[$invoice_id])) {
                foreach ($breakdown['by_vat_account'] as $account => $orig_amount) {
                    if (isset($tabtva[$invoice_id][$account])) {
                        $tabtva[$invoice_id][$account] -= $orig_amount;
                    } else {
                        $tabtva[$invoice_id][$account] = -$orig_amount;
                        // Populate def_tva for new VAT accounts
                        if (!empty($breakdown['vat_account_rates'][$account]) && !isset($def_tva[$invoice_id][$account])) {
                            $def_tva[$invoice_id][$account] = $breakdown['vat_account_rates'][$account];
                        }
                    }
                }
            }

            if (isset($tabttc[$invoice_id])) {
                foreach ($breakdown['by_ttc_account'] as $account => $orig_amount) {
                    if (isset($tabttc[$invoice_id][$account])) {
                        $tabttc[$invoice_id][$account] -= $orig_amount;
                    } else {
                        $tabttc[$invoice_id][$account] = -$orig_amount;
                    }
                }
            }
        }

        return 0;
    }

    /**
     * @brief Adjust customer balance for correction deltas
     * @param array $parameters Hook parameters
     * @param CommonObject $object
     * @param string $action
     * @param HookManager $hookmanager
     * @called_by Dolibarr hook: facdao
     */
    public function facdao($parameters, &$object, &$action, $hookmanager)
    {
        if (!in_array('recapcomptacard', explode(':', $parameters['context'] ?? ''))) {
            return 0;
        }

        $fac = $parameters['fac'];
        $delta = $this->getCorrectionDelta($fac);
        if ($delta === null) {
            return 0;
        }

        $parameters['values']['amount'] = $delta['delta_ttc'];
        return 0;
    }

    /**
     * @brief Adjust VAT report amounts for correction deltas
     * @param array $parameters Hook parameters
     * @param CommonObject $object
     * @param string $action
     * @param HookManager $hookmanager
     * @called_by Dolibarr hook: addVatLine
     */
    public function addVatLine($parameters, &$object, &$action, $hookmanager)
    {
        if (!in_array('externalbalance', explode(':', $parameters['context'] ?? ''))) {
            return 0;
        }

        $modetax = $parameters['mode'] ?? 0;
        if (!empty($modetax)) {
            return 0;
        }

        $cache = array();

        // Load delta + original breakdown
        $loadInvoice = function ($inv_id) use (&$cache) {
            if (isset($cache[$inv_id])) {
                return $cache[$inv_id];
            }
            $inv = new Facture($this->db);
            if ($inv->fetch($inv_id) <= 0) {
                $cache[$inv_id] = null;
                return null;
            }
            $delta = $this->getCorrectionDelta($inv);
            if ($delta === null) {
                $cache[$inv_id] = null;
                return null;
            }
            $breakdown = $this->getOriginalLineBreakdown($inv);
            if ($breakdown === null) {
                $cache[$inv_id] = null;
                return null;
            }
            $cache[$inv_id] = array(
                'delta' => $delta,
                'by_rate' => $breakdown['by_rate'],
            );
            return $cache[$inv_id];
        };

        $found = array();

        if (is_array($object[0])) {
            foreach ($object[0] as $rate => $rate_data) {
                if (!isset($rate_data['detail']) || !is_array($rate_data['detail'])) continue;
                foreach ($rate_data['detail'] as $detail) {
                    $inv_id = $detail['id'] ?? 0;
                    if ($inv_id <= 0 || isset($found[$inv_id])) continue;
                    $found[$inv_id] = ($loadInvoice($inv_id) !== null);
                }
            }
        }
        if (is_array($object[2])) {
            foreach ($object[2] as $rate => $rate_data) {
                if (!isset($rate_data['coll']['detail']) || !is_array($rate_data['coll']['detail'])) continue;
                foreach ($rate_data['coll']['detail'] as $detail) {
                    $inv_id = $detail['id'] ?? 0;
                    if ($inv_id <= 0 || isset($found[$inv_id])) continue;
                    $found[$inv_id] = ($loadInvoice($inv_id) !== null);
                }
            }
        }

        $adjusted_coll = array();
        $adjusted_both = array();

        foreach ($found as $inv_id => $is_replacement) {
            if (!$is_replacement) continue;
            $info = $cache[$inv_id];

            foreach ($info['by_rate'] as $orig_rate => $amounts) {
                $adj_key = $inv_id . ':' . $orig_rate;
                if (isset($adjusted_coll[$adj_key])) continue;
                $adjusted_coll[$adj_key] = true;

                if (isset($object[0][$orig_rate])) {
                    // Adjust rate totals
                    $object[0][$orig_rate]['totalht'] -= $amounts['ht'];
                    $object[0][$orig_rate]['vat'] -= $amounts['tva'];

                    // Adjust detail entry if replacement at same rate
                    if (isset($object[0][$orig_rate]['detail']) && is_array($object[0][$orig_rate]['detail'])) {
                        foreach ($object[0][$orig_rate]['detail'] as &$d) {
                            if (($d['id'] ?? 0) == $inv_id) {
                                if (isset($d['totalht'])) $d['totalht'] -= $amounts['ht'];
                                if (isset($d['vat'])) $d['vat'] -= $amounts['tva'];
                                break;
                            }
                        }
                        unset($d);
                    }
                }
            }

            foreach ($info['by_rate'] as $orig_rate => $amounts) {
                $adj_key = $inv_id . ':' . $orig_rate;
                if (isset($adjusted_both[$adj_key])) continue;
                $adjusted_both[$adj_key] = true;

                if (isset($object[2][$orig_rate]['coll'])) {
                    $object[2][$orig_rate]['coll']['totalht'] -= $amounts['ht'];
                    $object[2][$orig_rate]['coll']['vat'] -= $amounts['tva'];

                    if (isset($object[2][$orig_rate]['coll']['detail']) && is_array($object[2][$orig_rate]['coll']['detail'])) {
                        foreach ($object[2][$orig_rate]['coll']['detail'] as &$d) {
                            if (($d['id'] ?? 0) == $inv_id) {
                                if (isset($d['totalht'])) $d['totalht'] -= $amounts['ht'];
                                if (isset($d['vat'])) $d['vat'] -= $amounts['tva'];
                                break;
                            }
                        }
                        unset($d);
                    }
                }
            }
        }

        return 0;
    }

    /**
     * @brief Adjust P&L totals for correction deltas
     * @param array $parameters Hook parameters
     * @param CommonObject $object
     * @param string $action
     * @param HookManager $hookmanager
     * @called_by Dolibarr hook: addReportInfo
     */
    public function addReportInfo($parameters, &$object, &$action, $hookmanager)
    {
        if (!in_array('externalbalance', explode(':', $parameters['context'] ?? ''))) {
            return 0;
        }

        $mode = $parameters['mode'] ?? '';
        if ($mode != 'CREANCES-DETTES') {
            return 0;
        }

        if (empty($object[0]) || !is_array($object[0])) {
            return 0;
        }
        $months = array_keys($object[0]);
        $date_min = min($months) . '-01';
        $date_max = date('Y-m-t', strtotime(max($months) . '-01'));

        $sql = "SELECT f.datef, fe.ksef_correction_original_ht, fe.ksef_correction_original_ttc";
        $sql .= " FROM " . MAIN_DB_PREFIX . "facture as f";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "facture_extrafields as fe ON fe.fk_object = f.rowid";
        $sql .= " WHERE f.type = " . Facture::TYPE_REPLACEMENT;
        $sql .= " AND f.fk_statut > 0";
        $sql .= " AND fe.ksef_correction_original_ht IS NOT NULL";
        $sql .= " AND fe.ksef_correction_original_ht != ''";
        $sql .= " AND f.datef >= '" . $this->db->escape($date_min) . "'";
        $sql .= " AND f.datef <= '" . $this->db->escape($date_max) . "'";
        $sql .= " AND f.entity IN (" . getEntity('invoice') . ")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return 0;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $month_key = dol_print_date($this->db->jdate($obj->datef), '%Y-%m');
            $orig_ht = (float) $obj->ksef_correction_original_ht;
            $orig_ttc = (float) $obj->ksef_correction_original_ttc;

            // Subtract original amounts from month totals
            if (isset($object[0][$month_key])) {
                $object[0][$month_key] -= $orig_ht;
            }
            if (isset($object[1][$month_key])) {
                $object[1][$month_key] -= $orig_ttc;
            }
        }
        $this->db->free($resql);

        return 0;
    }

    /**
     * @brief Adjust grand totals and per-customer rows for correction deltas
     * @param array $parameters Hook parameters
     * @param CommonObject $object
     * @param string $action
     * @param HookManager $hookmanager
     * @called_by Dolibarr hook: addBalanceLine
     */
    public function addBalanceLine($parameters, &$object, &$action, $hookmanager)
    {
        global $langs;

        if (!in_array('customersupplierreportlist', explode(':', $parameters['context'] ?? ''))) {
            return 0;
        }

        $mode = $parameters['mode'] ?? '';
        if ($mode != 'CREANCES-DETTES') {
            return 0;
        }

        $date_start = $parameters['date_start'] ?? null;
        $date_end = $parameters['date_end'] ?? null;

        $sql = "SELECT f.fk_soc,";
        $sql .= " SUM(fe.ksef_correction_original_ht) as orig_ht,";
        $sql .= " SUM(fe.ksef_correction_original_ttc) as orig_ttc";
        $sql .= " FROM " . MAIN_DB_PREFIX . "facture as f";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "facture_extrafields as fe ON fe.fk_object = f.rowid";
        $sql .= " WHERE f.type = " . Facture::TYPE_REPLACEMENT;
        $sql .= " AND f.fk_statut > 0";
        $sql .= " AND fe.ksef_correction_original_ht IS NOT NULL";
        $sql .= " AND fe.ksef_correction_original_ht != ''";
        $sql .= " AND f.entity IN (" . getEntity('invoice') . ")";
        if ($date_start) {
            $sql .= " AND f.datef >= '" . $this->db->idate($date_start) . "'";
        }
        if ($date_end) {
            $sql .= " AND f.datef <= '" . $this->db->idate($date_end) . "'";
        }
        $sql .= " GROUP BY f.fk_soc";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return 0;
        }

        $total_orig_ht = 0;
        $total_orig_ttc = 0;
        $per_customer = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $adj_ht = (float) $obj->orig_ht;
            $adj_ttc = (float) $obj->orig_ttc;
            $total_orig_ht += $adj_ht;
            $total_orig_ttc += $adj_ttc;
            $per_customer[(int) $obj->fk_soc] = array('ht' => $adj_ht, 'ttc' => $adj_ttc);
        }
        $this->db->free($resql);

        // Adjust grand totals
        if ($total_orig_ht != 0 || $total_orig_ttc != 0) {
            $object[0] -= $total_orig_ht;
            $object[1] -= $total_orig_ttc;
        }

        if (!empty($per_customer)) {
            $js_adjustments = array();
            foreach ($per_customer as $socid => $adj) {
                $js_adjustments[] = '{socid:' . $socid . ',ht:' . $adj['ht'] . ',ttc:' . $adj['ttc'] . '}';
            }
            $this->resprints = '<script type="text/javascript">
jQuery(document).ready(function() {
    var adjs = [' . implode(',', $js_adjustments) . '];
    adjs.forEach(function(a) {
        jQuery("a[href*=\'socid=" + a.socid + "\']").each(function() {
            var row = jQuery(this).closest("tr");
            if (!row.length) return;
            var spans = row.find("span.amount");
            if (spans.length >= 2) {
                // HT + TTC spans
                var htSpan = spans.eq(0);
                var ttcSpan = spans.eq(1);
                adjustAmountSpan(htSpan, a.ht);
                adjustAmountSpan(ttcSpan, a.ttc);
            } else if (spans.length === 1) {
                // TTC only span
                adjustAmountSpan(spans.eq(0), a.ttc);
            }
        });
    });
    function adjustAmountSpan(span, subtract) {
        var text = span.text().trim();
        // Parse Dolibarr-formatted number
        var cleaned = text.replace(/\s/g, "");
        var val;
        // Detect decimal format
        var lastComma = cleaned.lastIndexOf(",");
        var lastDot = cleaned.lastIndexOf(".");
        if (lastComma > lastDot) {
            // European format (comma decimal)
            val = parseFloat(cleaned.replace(/\./g, "").replace(",", "."));
        } else {
            // US format (dot decimal)
            val = parseFloat(cleaned.replace(/,/g, ""));
        }
        if (isNaN(val)) return;
        var newVal = val - subtract;
        // Re-format preserving original separator
        var decSep = (lastComma > lastDot) ? "," : ".";
        var thouSep = (decSep === ",") ? "." : ",";
        // Determine decimal places
        var decPos = (decSep === ",") ? lastComma : lastDot;
        var decimals = (decPos >= 0) ? (cleaned.length - decPos - 1) : 2;
        var formatted = newVal.toFixed(decimals);
        if (decSep === ",") {
            formatted = formatted.replace(".", ",");
        }
        // Add thousand separators
        var parts = formatted.split(decSep);
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thouSep);
        span.text(parts.join(decSep));
    }
});
</script>';
        }

        return 0;
    }

}