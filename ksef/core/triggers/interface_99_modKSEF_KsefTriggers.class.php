<?php
/* Copyright (C) 2025 InPoint Automation Sp z o.o.
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
 * \file    core/triggers/interface_99_modKSEF_KsefTriggers.class.php
 * \ingroup ksef
 * \brief   event triggers
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

class InterfaceKsefTriggers extends DolibarrTriggers
{
    public function __construct($db)
    {
        $this->db = $db;
        $this->name = preg_replace('/^Interface/i', '', get_class($this));
        $this->family = "financial";
        $this->description = "KSEF database event triggers";
        $this->version = '1.0.0';
        $this->picto = 'ksef@ksef';
    }

    /**
     * @brief Find root non-TYPE_REPLACEMENT invoice in correction chain
     * @param Facture $invoice Starting invoice
     * @param int $maxDepth Safety limit
     * @return Facture|null Root invoice, or null on fetch failure
     * @called_by runTrigger()
     */
    private function findRootInvoice(Facture $invoice, $maxDepth = 20)
    {
        $current = $invoice;
        $depth = 0;
        while ($current->type == Facture::TYPE_REPLACEMENT && $depth < $maxDepth) {
            if (empty($current->fk_facture_source)) {
                break;
            }
            $parent = new Facture($this->db);
            if ($parent->fetch((int) $current->fk_facture_source) <= 0) {
                dol_syslog("KsefTriggers::findRootInvoice - Cannot fetch parent " . $current->fk_facture_source, LOG_ERR);
                return null;
            }
            $current = $parent;
            $depth++;
        }
        return $current;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getDesc()
    {
        return $this->description;
    }

    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        if (empty($conf->ksef) || empty($conf->ksef->enabled)) {
            return 0;
        }

        switch ($action) {

            case 'BILL_CREATE':
                $object->fetch_optionals();
                if (empty($object->array_options['options_ksef_sale_date'])) {
                    $sale_date = null;
                    $saleDateSource = getDolGlobalString('KSEF_FA3_SALE_DATE_SOURCE', 'delivery_date');

                    if ($saleDateSource === 'delivery_date') {
                        // shipment -> order delivery -> order date -> invoice date
                        $object->fetchObjectLinked(null, '', null, '', 'OR', 1, 'sourcetype', 1);

                        // linked shipments
                        foreach (array('expedition', 'shipping') as $shipment_key) {
                            if (!empty($object->linkedObjects[$shipment_key])) {
                                foreach ($object->linkedObjects[$shipment_key] as $shipment) {
                                    if (!empty($shipment->date_delivery)) {
                                        $sale_date = $shipment->date_delivery;
                                        break 2;
                                    }
                                    if (!empty($shipment->date_expedition)) {
                                        $sale_date = $shipment->date_expedition;
                                        break 2;
                                    }
                                }
                            }
                        }

                        // linked sales orders delivery date
                        if (empty($sale_date) && !empty($object->linkedObjects['commande'])) {
                            foreach ($object->linkedObjects['commande'] as $order) {
                                if (!empty($order->delivery_date)) {
                                    $sale_date = $order->delivery_date;
                                    break;
                                }
                                if (!empty($order->date_livraison)) {
                                    $sale_date = $order->date_livraison;
                                    break;
                                }
                            }
                            // linked sales order date
                            if (empty($sale_date)) {
                                foreach ($object->linkedObjects['commande'] as $order) {
                                    if (!empty($order->date_commande)) {
                                        $sale_date = $order->date_commande;
                                        break;
                                    }
                                    if (!empty($order->date)) {
                                        $sale_date = $order->date;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                    // saleDateSource === 'invoice_date'

                    if (empty($sale_date)) {
                        $sale_date = $object->date;
                    }

                    $object->array_options['options_ksef_sale_date'] = $sale_date;
                    $object->insertExtraFields();
                }

                // Pre-fill default correction reason and type
                $is_kor = ($object->type == Facture::TYPE_CREDIT_NOTE
                    || $object->type == Facture::TYPE_REPLACEMENT);
                if ($is_kor) {
                    $changed = false;

                    $default_reason = getDolGlobalString('KSEF_DEFAULT_CORRECTION_REASON', '');
                    $needs_reason = !empty($default_reason) && (
                        empty($object->array_options['options_ksef_correction_reason'])
                        || $object->type == Facture::TYPE_REPLACEMENT
                    );
                    if ($needs_reason) {
                        $object->array_options['options_ksef_correction_reason'] = $default_reason;
                        $changed = true;
                    }

                    $default_type = getDolGlobalString('KSEF_DEFAULT_CORRECTION_TYPE', '');
                    $needs_type = !empty($default_type) && (
                        empty($object->array_options['options_ksef_correction_type'])
                        || $object->type == Facture::TYPE_REPLACEMENT
                    );
                    if ($needs_type) {
                        $object->array_options['options_ksef_correction_type'] = $default_type;
                        $changed = true;
                    }

                    if ($changed) {
                        $object->insertExtraFields();
                    }
                }

                if ($object->type == Facture::TYPE_REPLACEMENT && !empty($object->fk_facture_source)) {
                    $sql_disc = "SELECT rc.rowid"
                        . " FROM " . MAIN_DB_PREFIX . "societe_remise_except as rc"
                        . " WHERE rc.fk_facture = " . (int) $object->fk_facture_source;
                    $res_disc = $this->db->query($sql_disc);
                    if ($res_disc) {
                        $disc_ids = array();
                        while ($obj_disc = $this->db->fetch_object($res_disc)) {
                            $disc_ids[] = (int) $obj_disc->rowid;
                        }
                        $this->db->free($res_disc);
                        if (!empty($disc_ids)) {
                            $object->array_options['options_ksef_correction_original_discount_ids'] = implode(',', $disc_ids);
                            $object->insertExtraFields();
                            dol_syslog("KsefTriggers: BILL_CREATE - Captured " . count($disc_ids) . " discount IDs from original " . $object->fk_facture_source, LOG_INFO);
                        }
                    }
                }
                return 0;

            case 'BILL_VALIDATE':
                $object->fetch_optionals();
                if (!empty($object->array_options['options_ksef_number'])) {
                    dol_syslog("KsefTriggers: Invoice " . $object->ref . " validated with KSEF number: " . $object->array_options['options_ksef_number'], LOG_INFO);
                }

                // Discount-based correction for TYPE_REPLACEMENT
                if ($object->type == Facture::TYPE_REPLACEMENT
                    && !empty($object->fk_facture_source)) {

                    $original_id = (int) $object->fk_facture_source;
                    $replacement_id = (int) $object->id;

                    // etch original invoice
                    $originalInvoice = new Facture($this->db);
                    if ($originalInvoice->fetch($original_id) <= 0) {
                        $this->error = "Cannot fetch original invoice $original_id";
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    $originalInvoice->fetch_optionals();

                    // Store correction metadata
                    $object->array_options['options_ksef_correction_original_ht'] = $originalInvoice->total_ht;
                    $object->array_options['options_ksef_correction_original_tva'] = $originalInvoice->total_tva;
                    $object->array_options['options_ksef_correction_original_ttc'] = $originalInvoice->total_ttc;
                    if ($object->insertExtraFields() < 0) {
                        $this->error = "Failed to store correction metadata: " . $object->error;
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }

                    // Clean up parent
                    $orig_discount_id = $originalInvoice->array_options['options_ksef_correction_discount_id'] ?? null;
                    if (!empty($orig_discount_id)) {
                        dol_include_once('/core/class/discount.class.php');
                        $orphanedDiscount = new DiscountAbsolute($this->db);
                        if ($orphanedDiscount->fetch((int) $orig_discount_id) > 0) {
                            if (!empty($orphanedDiscount->fk_facture)) {
                                $orphanedDiscount->unlink_invoice(1);
                            }
                            if ($orphanedDiscount->delete($user) < 0) {
                                $this->error = "Failed to delete orphaned discount: " . $orphanedDiscount->error;
                                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                return -1;
                            }
                            dol_syslog("KsefTriggers: BILL_VALIDATE - Deleted orphaned chain discount ID $orig_discount_id", LOG_INFO);
                        }
                    }

                    // Re-link any user-created discounts to replacement
                    dol_include_once('/core/class/discount.class.php');
                    $stored_ids_str = $object->array_options['options_ksef_correction_original_discount_ids'] ?? '';
                    if (!empty($stored_ids_str)) {
                        $stored_ids = array_map('intval', explode(',', $stored_ids_str));
                        foreach ($stored_ids as $disc_id) {
                            if ($disc_id <= 0) {
                                continue;
                            }
                            $relink_disc = new DiscountAbsolute($this->db);
                            if ($relink_disc->fetch($disc_id) > 0 && empty($relink_disc->fk_facture)) {
                                $relink_disc->link_to_invoice(0, $replacement_id);
                                dol_syslog("KsefTriggers: BILL_VALIDATE - Re-linked discount ID $disc_id to replacement $replacement_id", LOG_INFO);
                            }
                        }
                    }

                    // Calculate settled amount
                    dol_include_once('/ksef/lib/ksef.lib.php');
                    $chain = ksefBuildCorrectionChain($originalInvoice, $this->db);
                    $settled = 0;
                    $settled_mc = 0;
                    foreach ($chain as $chainInv) {
                        $settled += $chainInv->getSommePaiement(0);
                        $settled_mc += $chainInv->getSommePaiement(1);
                    }

                    dol_syslog("KsefTriggers: BILL_VALIDATE - Chain settled=$settled (from " . count($chain) . " invoices), replacement=$replacement_id", LOG_INFO);

                    // Create correction discount if settled > 0
                    if ($settled > 0 || $settled_mc > 0) {
                        $langs->load("ksef@ksef");

                        // Find root
                        $rootInvoice = $this->findRootInvoice($originalInvoice);
                        if ($rootInvoice === null) {
                            $this->error = "Cannot find root invoice for correction chain from invoice $original_id";
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }

                        // Create correction discount for full settled amount
                        $discount = new DiscountAbsolute($this->db);
                        $discount->socid = $object->socid;
                        $discount->discount_type = 0; // customer
                        $discount->amount_ttc = $settled;
                        if ($rootInvoice->total_ttc != 0) {
                            $discount->amount_ht = $settled * ($rootInvoice->total_ht / $rootInvoice->total_ttc);
                        } else {
                            $discount->amount_ht = $settled;
                        }
                        $discount->amount_tva = $settled - $discount->amount_ht;
                        $discount->tva_tx = 0;
                        $discount->fk_facture_source = $rootInvoice->id;
                        $originalRef = $originalInvoice->ref;
                        $discount->description = $langs->trans('KSEF_CorrectionDiscountDescription', $originalRef);

                        // Multicurrency fields
                        if (!empty($rootInvoice->multicurrency_code)
                            && $rootInvoice->multicurrency_code != $conf->currency
                            && $settled_mc > 0) {
                            $discount->multicurrency_amount_ttc = $settled_mc;
                            if ($rootInvoice->multicurrency_total_ttc != 0) {
                                $discount->multicurrency_amount_ht = $settled_mc * ($rootInvoice->multicurrency_total_ht / $rootInvoice->multicurrency_total_ttc);
                            } else {
                                $discount->multicurrency_amount_ht = $settled_mc;
                            }
                            $discount->multicurrency_amount_tva = $settled_mc - $discount->multicurrency_amount_ht;
                            $discount->multicurrency_code = $rootInvoice->multicurrency_code;
                            $discount->multicurrency_tx = $rootInvoice->multicurrency_tx;
                        }

                        $discount_id = $discount->create($user);
                        if ($discount_id <= 0) {
                            $this->error = "Failed to create correction discount: " . $discount->error;
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }

                        // Link discount to replacement
                        $result = $discount->link_to_invoice(0, $replacement_id);
                        if ($result < 0) {
                            $this->error = "Failed to link discount to replacement: " . $discount->error;
                            dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                            return -1;
                        }

                        // Store discount ID and settled amount for chain recovery
                        $object->array_options['options_ksef_correction_discount_id'] = $discount_id;
                        $object->array_options['options_ksef_correction_settled_amount'] = $settled;
                        $object->array_options['options_ksef_correction_settled_amount_mc'] = $settled_mc;
                        $object->insertExtraFields();

                        dol_syslog("KsefTriggers: BILL_VALIDATE - Created discount ID $discount_id (amount=$settled) linked to replacement $replacement_id", LOG_INFO);

                        // Auto-classify as paid if settled matches exactly; excess left open for refund
                        $correction_ttc = (float) $object->total_ttc;
                        if ($settled >= $correction_ttc && ($settled - $correction_ttc) < 0.01) {
                            $sql_paid = "UPDATE " . MAIN_DB_PREFIX . "facture"
                                . " SET fk_statut = " . Facture::STATUS_CLOSED
                                . ", paye = 1"
                                . ", fk_user_closing = " . ((int) $user->id)
                                . ", date_closing = '" . $this->db->idate(dol_now()) . "'"
                                . " WHERE rowid = " . $replacement_id;
                            $this->db->query($sql_paid);
                            dol_syslog("KsefTriggers: BILL_VALIDATE - Auto-classified correction $replacement_id as paid (settled=$settled matches total=$correction_ttc)", LOG_INFO);
                        } elseif ($settled > $correction_ttc) {
                            dol_syslog("KsefTriggers: BILL_VALIDATE - Excess payment on chain: settled=$settled > total=$correction_ttc, leaving $replacement_id open for refund", LOG_INFO);
                        }
                    }

                    // Restore original to STATUS_CLOSED
                    $sql_restore = "UPDATE " . MAIN_DB_PREFIX . "facture"
                        . " SET fk_statut = " . Facture::STATUS_CLOSED
                        . ", close_code = NULL"
                        . ", paye = 1"
                        . " WHERE rowid = " . $original_id;
                    if (!$this->db->query($sql_restore)) {
                        $this->error = "Failed to restore original invoice status: " . $this->db->lasterror();
                        dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                        return -1;
                    }
                    dol_syslog("KsefTriggers: BILL_VALIDATE - Restored original $original_id to STATUS_CLOSED", LOG_INFO);
                }
                return 0;

            case 'BILL_MODIFY':
                if (!empty($object->array_options['options_ksef_number'])) {
                    dol_syslog("KsefTriggers: WARNING - Invoice " . $object->ref . " with KSEF number " . $object->array_options['options_ksef_number'] . " was modified", LOG_WARNING);
                }
                return 0;

            case 'LINEBILL_INSERT':
                // UU_ID for new lines
                if (!empty($object->id) && empty($object->array_options['options_ksef_uu_id'])) {
                    $uuid = sprintf(
                        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000,
                        mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                    );
                    $object->array_options['options_ksef_uu_id'] = $uuid;
                    $object->insertExtraFields();
                }
                return 0;

            case 'EXTRAFIELDS_UPDATE':
                // Regenerate PDF when KSEF number is added
                if ($object->element == 'facture' && !empty($object->array_options['options_ksef_number'])) {
                    static $pdf_regenerated = array();
                    $cache_key = $object->id . '_' . $object->array_options['options_ksef_number'];

                    if (!isset($pdf_regenerated[$cache_key])) {
                        dol_syslog("KsefTriggers: KSEF number added to invoice " . $object->ref . ", regenerating PDF", LOG_INFO);

                        try {
                            $result = $object->generateDocument($object->model_pdf, $langs, 0, 0, 0);

                            if ($result > 0) {
                                dol_syslog("KsefTriggers: PDF successfully regenerated for invoice " . $object->ref, LOG_INFO);
                            } else {
                                dol_syslog("KsefTriggers: PDF regeneration failed for invoice " . $object->ref . ": " . $object->error, LOG_ERR);
                            }

                            $pdf_regenerated[$cache_key] = true;

                        } catch (Exception $e) {
                            dol_syslog("KsefTriggers: Exception during PDF regeneration: " . $e->getMessage(), LOG_ERR);
                        }
                    }
                }
                return 0;

            case 'BILL_DELETE':
                if (!empty($object->array_options['options_ksef_number'])) {
                    dol_syslog("KsefTriggers: CRITICAL - Invoice " . $object->ref . " with KSEF number " . $object->array_options['options_ksef_number'] . " was deleted", LOG_ALERT);
                }
                // Fall through to shared undo logic
            case 'BILL_UNVALIDATE':
                // Undo discount-based correction on delete/draft
                if ($object->type == Facture::TYPE_CREDIT_NOTE) {
                    require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';
                    $sql_orphan = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe_remise_except"
                        . " WHERE fk_facture_source = " . ((int) $object->id)
                        . " AND fk_facture IS NULL AND fk_facture_line IS NULL"
                        . " AND entity IN (" . getEntity('invoice') . ")";
                    $res_orphan = $this->db->query($sql_orphan);
                    if ($res_orphan) {
                        $orphan_ids = array();
                        while ($obj_o = $this->db->fetch_object($res_orphan)) {
                            $orphan_ids[] = (int) $obj_o->rowid;
                        }
                        $this->db->free($res_orphan);
                        foreach ($orphan_ids as $orphan_id) {
                            $disc = new DiscountAbsolute($this->db);
                            if ($disc->fetch($orphan_id) > 0 && $disc->delete($user) < 0) {
                                $this->error = "Failed to revoke orphaned credit-note discount " . $orphan_id . ": " . $disc->error;
                                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                return -1;
                            }
                        }
                        if (!empty($orphan_ids)) {
                            dol_syslog("KsefTriggers: $action - revoked " . count($orphan_ids)
                                . " unused converted discount(s) for credit note " . $object->id, LOG_INFO);
                        }
                    }
                }

                if ($object->type == Facture::TYPE_REPLACEMENT && !empty($object->fk_facture_source)) {
                    $replacement_id = (int) $object->id;
                    $original_id = (int) $object->fk_facture_source;
                    $object->fetch_optionals();

                    // block if corrected by another
                    $sql_chain = "SELECT rowid, ref FROM " . MAIN_DB_PREFIX . "facture"
                        . " WHERE fk_facture_source = " . $replacement_id
                        . " AND type = " . Facture::TYPE_REPLACEMENT
                        . " AND fk_statut > 0";
                    $res_chain = $this->db->query($sql_chain);
                    if ($res_chain && $this->db->num_rows($res_chain) > 0) {
                        $obj_chain = $this->db->fetch_object($res_chain);
                        $langs->load("ksef@ksef");
                        $this->error = $langs->trans('KSEF_CorrectionChainBlocked', $obj_chain->ref);
                        dol_syslog("KsefTriggers: $action blocked - invoice $replacement_id is corrected by " . $obj_chain->ref, LOG_WARNING);
                        $this->db->free($res_chain);
                        return -1;
                    }
                    if ($res_chain) {
                        $this->db->free($res_chain);
                    }

                    // delete correction discount
                    $discount_id = $object->array_options['options_ksef_correction_discount_id'] ?? null;
                    if (!empty($discount_id)) {
                        dol_include_once('/core/class/discount.class.php');
                        $discount = new DiscountAbsolute($this->db);
                        if ($discount->fetch((int) $discount_id) > 0) {
                            // Unlink from invoice first
                            if (!empty($discount->fk_facture)) {
                                $discount->unlink_invoice(1);
                            }
                            if ($discount->delete($user) < 0) {
                                $this->error = "Failed to delete correction discount: " . $discount->error;
                                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                return -1;
                            }
                            dol_syslog("KsefTriggers: $action - Deleted discount ID $discount_id", LOG_INFO);
                        }
                    }

                    // re-link to original
                    dol_include_once('/core/class/discount.class.php');
                    $stored_ids_str = $object->array_options['options_ksef_correction_original_discount_ids'] ?? '';
                    if (!empty($stored_ids_str)) {
                        $stored_ids = array_map('intval', explode(',', $stored_ids_str));
                        foreach ($stored_ids as $disc_id) {
                            if ($disc_id <= 0) {
                                continue;
                            }
                            $relink_disc = new DiscountAbsolute($this->db);
                            if ($relink_disc->fetch($disc_id) > 0) {
                                if ($relink_disc->fk_facture == $replacement_id) {
                                    // Unlink from replacement
                                    $relink_disc->unlink_invoice(1);
                                    // Re-link to original
                                    $relink_disc->link_to_invoice(0, $original_id);
                                    dol_syslog("KsefTriggers: $action - Re-linked discount ID $disc_id back to original $original_id", LOG_INFO);
                                }
                            }
                        }
                    }

                    $originalInvoice = new Facture($this->db);
                    if ($originalInvoice->fetch($original_id) > 0) {
                        $originalInvoice->fetch_optionals();
                        $is_chain = ($originalInvoice->type == Facture::TYPE_REPLACEMENT
                            && !empty($originalInvoice->array_options['options_ksef_correction_original_ht']));

                        if ($is_chain) {
                            // Calculate settled across chain, excluding removed invoice
                            dol_include_once('/ksef/lib/ksef.lib.php');
                            $chain = ksefBuildCorrectionChain($originalInvoice, $this->db);
                            $settled = 0;
                            $settled_mc = 0;
                            foreach ($chain as $chainInv) {
                                if ($chainInv->id == $replacement_id) {
                                    continue; // exclude the invoice being deleted/unvalidated
                                }
                                $settled += $chainInv->getSommePaiement(0);
                                $settled_mc += $chainInv->getSommePaiement(1);
                            }

                            dol_syslog("KsefTriggers: $action - Chain settled=$settled (from " . count($chain) . " invoices, excluding $replacement_id)", LOG_INFO);

                            if ($settled > 0 || $settled_mc > 0) {
                                dol_include_once('/core/class/discount.class.php');
                                $langs->load("ksef@ksef");

                                // Find root non-TYPE_REPLACEMENT invoice
                                $grandparent_id = (int) $originalInvoice->fk_facture_source;
                                $grandparent = new Facture($this->db);
                                $rootInvoice = null;
                                if ($grandparent_id > 0 && $grandparent->fetch($grandparent_id) > 0) {
                                    $rootInvoice = $this->findRootInvoice($grandparent);
                                }
                                if ($rootInvoice === null) {
                                    $rootInvoice = $originalInvoice; // fallback
                                }

                                // Re-create correction discount
                                $newDiscount = new DiscountAbsolute($this->db);
                                $newDiscount->fk_soc = $rootInvoice->socid;
                                $newDiscount->discount_type = 0;
                                $newDiscount->amount_ttc = $settled;
                                if ($rootInvoice->total_ttc != 0) {
                                    $newDiscount->amount_ht = $settled * ($rootInvoice->total_ht / $rootInvoice->total_ttc);
                                } else {
                                    $newDiscount->amount_ht = $settled;
                                }
                                $newDiscount->amount_tva = $settled - $newDiscount->amount_ht;
                                $newDiscount->tva_tx = 0;
                                $newDiscount->fk_facture_source = $rootInvoice->id;
                                $newDiscount->description = $langs->trans('KSEF_CorrectionDiscountDescription', $originalInvoice->ref);

                                // Multicurrency
                                if (!empty($rootInvoice->multicurrency_code)
                                    && $rootInvoice->multicurrency_code != $conf->currency
                                    && $settled_mc > 0) {
                                    $newDiscount->multicurrency_amount_ttc = $settled_mc;
                                    if ($rootInvoice->multicurrency_total_ttc != 0) {
                                        $newDiscount->multicurrency_amount_ht = $settled_mc * ($rootInvoice->multicurrency_total_ht / $rootInvoice->multicurrency_total_ttc);
                                    } else {
                                        $newDiscount->multicurrency_amount_ht = $settled_mc;
                                    }
                                    $newDiscount->multicurrency_amount_tva = $settled_mc - $newDiscount->multicurrency_amount_ht;
                                    $newDiscount->multicurrency_code = $rootInvoice->multicurrency_code;
                                    $newDiscount->multicurrency_tx = $rootInvoice->multicurrency_tx;
                                }

                                $new_discount_id = $newDiscount->create($user);
                                if ($new_discount_id > 0) {
                                    $newDiscount->link_to_invoice(0, $original_id);
                                    $originalInvoice->array_options['options_ksef_correction_discount_id'] = $new_discount_id;
                                    $originalInvoice->array_options['options_ksef_correction_settled_amount'] = $settled;
                                    $originalInvoice->array_options['options_ksef_correction_settled_amount_mc'] = $settled_mc;
                                    $originalInvoice->insertExtraFields();
                                    dol_syslog("KsefTriggers: $action - Re-created discount ID $new_discount_id (amount=$settled) for chain parent $original_id", LOG_INFO);
                                }
                            }

                            // Restore chain parent status; exact match = closed, excess = validated
                            if ($settled > 0 && abs($settled - (float) $originalInvoice->total_ttc) < 0.01) {
                                // Exact match - classify as paid
                                $sql_chain_restore = "UPDATE " . MAIN_DB_PREFIX . "facture"
                                    . " SET fk_statut = " . Facture::STATUS_CLOSED
                                    . ", paye = 1"
                                    . ", close_code = NULL"
                                    . " WHERE rowid = " . $original_id;
                            } else {
                                // Excess, shortfall, or no settled - reopen as validated
                                $sql_chain_restore = "UPDATE " . MAIN_DB_PREFIX . "facture"
                                    . " SET fk_statut = " . Facture::STATUS_VALIDATED
                                    . ", paye = 0"
                                    . ", close_code = NULL"
                                    . " WHERE rowid = " . $original_id;
                            }
                            if (!$this->db->query($sql_chain_restore)) {
                                dol_syslog("KsefTriggers: $action - WARNING: Failed to restore chain parent status: " . $this->db->lasterror(), LOG_WARNING);
                            }
                            dol_syslog("KsefTriggers: $action - Restored chain parent $original_id status (settled=$settled, total=" . $originalInvoice->total_ttc . ")", LOG_INFO);
                        } else {
                            $orig_paid = $originalInvoice->getSommePaiement(0);
                            if ($orig_paid > 0 && $orig_paid >= $originalInvoice->total_ttc) {
                                // Fully paid
                                $restore_status = Facture::STATUS_CLOSED;
                                $restore_paye = 1;
                            } else {
                                // Unpaid/partial
                                $restore_status = Facture::STATUS_VALIDATED;
                                $restore_paye = 0;
                            }
                            $sql_restore = "UPDATE " . MAIN_DB_PREFIX . "facture"
                                . " SET fk_statut = " . $restore_status
                                . ", close_code = NULL"
                                . ", paye = " . $restore_paye
                                . " WHERE rowid = " . $original_id;
                            if (!$this->db->query($sql_restore)) {
                                $this->error = "Failed to restore original invoice: " . $this->db->lasterror();
                                dol_syslog("KsefTriggers: " . $this->error, LOG_ERR);
                                return -1;
                            }
                            dol_syslog("KsefTriggers: $action - Restored original $original_id to status=$restore_status paye=$restore_paye", LOG_INFO);
                        }
                    }

                    $object->array_options['options_ksef_correction_original_ht'] = null;
                    $object->array_options['options_ksef_correction_original_tva'] = null;
                    $object->array_options['options_ksef_correction_original_ttc'] = null;
                    $object->array_options['options_ksef_correction_discount_id'] = null;
                    $object->array_options['options_ksef_correction_settled_amount'] = null;
                    $object->array_options['options_ksef_correction_settled_amount_mc'] = null;

                    if ($action == 'BILL_UNVALIDATE') {
                        $sql_disc = "SELECT rc.rowid"
                            . " FROM " . MAIN_DB_PREFIX . "societe_remise_except as rc"
                            . " WHERE rc.fk_facture = " . $original_id;
                        $res_disc = $this->db->query($sql_disc);
                        if ($res_disc) {
                            $disc_ids = array();
                            while ($obj_disc = $this->db->fetch_object($res_disc)) {
                                $disc_ids[] = (int) $obj_disc->rowid;
                            }
                            $this->db->free($res_disc);
                            $object->array_options['options_ksef_correction_original_discount_ids'] =
                                !empty($disc_ids) ? implode(',', $disc_ids) : null;
                        }
                    } else {
                        $object->array_options['options_ksef_correction_original_discount_ids'] = null;
                    }

                    $object->insertExtraFields();
                }
                return 0;

            default:
                return 0;
        }
    }
}