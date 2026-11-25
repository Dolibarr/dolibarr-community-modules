<?php
/* Copyright (C) 2026		Alain Kerboull
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
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *	\file       einvoicing/manual_import.php
 *	\ingroup    einvoicing
 *	\brief      Manual import page for e-invoice files (Factur-X, CII).
 *				Allows users to upload and import downloaded e-invoices from their PDP website.
 */

$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * The main.inc.php has been included so the following variable are now defined:
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Load translation files required by the page
$langs->loadLangs(array("einvoicing@einvoicing", "bills", "companies", "products"));

// Load required classes and library
dol_include_once('/einvoicing/lib/einvoicing.lib.php');
dol_include_once('/einvoicing/class/protocols/ProtocolManager.class.php');
dol_include_once('/einvoicing/class/protocols/AbstractProtocol.class.php');
dol_include_once('/einvoicing/class/einvoicing.class.php');
dol_include_once('/einvoicing/class/document.class.php');
dol_include_once('/einvoicing/class/einvoicingsyncpending.class.php');
dol_include_once('/fourn/class/facturefournisseur.class.php');
dol_include_once('/einvoicing/class/providers/AbstractPDPProvider.class.php');
dol_include_once('/einvoicing/class/providers/PDPProviderManager.class.php');

// Security check
if (!isModEnabled('einvoicing')) {
	accessforbidden('Module einvoicing not enabled');
}
if (!$user->hasRight('einvoicing', 'read')) {
	accessforbidden();
}

// DEBUG: Afficher les données de soumission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	dol_syslog('manual_import: POST received, action=' . $action . ', FILES=' . count($_FILES), LOG_DEBUG);
	dol_syslog('manual_import: FILES[einvoice_file]=' . print_r($_FILES['einvoice_file'] ?? 'NOT SET', true), LOG_DEBUG);
}

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');


/*
 * Actions
 */

$successMessages = array();
$errorMessages = array();
$importedInvoiceRef = '';
$importedInvoiceId = 0;
$importedDocumentId = 0;
$detectedProtocol = '';
$previewData = null;

	if ($action == 'upload') {
		// DEBUG: Logger les donn es de soumission (dans les logs Dolibarr, pas dans l'interface)
		dol_syslog('manual_import: action=upload, FILES count=' . count($_FILES), LOG_DEBUG);
		if (!empty($_FILES['einvoice_file'])) {
			dol_syslog('manual_import: filename=' . $_FILES['einvoice_file']['name'] . ', error=' . $_FILES['einvoice_file']['error'], LOG_DEBUG);
		}

		if (!empty($_FILES['einvoice_file']['name']) && $_FILES['einvoice_file']['error'] == 0) {
			$uploadDir = $conf->einvoicing->dir_temp;
			if (!is_dir($uploadDir)) {
				dol_mkdir($uploadDir, einvoicingDataRoot($uploadDir));
			}

			$originalFileName = $_FILES['einvoice_file']['name'];
			$uploadFilePath = $uploadDir . '/manual_' . bin2hex(random_bytes(8)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalFileName);

			// Move uploaded file to temp directory
			if (!move_uploaded_file($_FILES['einvoice_file']['tmp_name'], $uploadFilePath)) {
				$errorMessages[] = $langs->trans("ErrorFailedToSaveUploadedFile");
			} else {
				dol_syslog('manual_import: File saved to temp, proceeding with parsing...', LOG_DEBUG);
				try {
					// Read file content
					$fileContent = file_get_contents($uploadFilePath);

					if (empty($fileContent)) {
						$errorMessages[] = $langs->trans("ErrorEmptyFile");
					} else {
						dol_syslog('manual_import: File content read, detecting protocol...', LOG_DEBUG);
						// Detect protocol from file content
						$protocolManager = new ProtocolManager($db);
						$detectedProtocolName = $protocolManager->detectProtocolFromContent($fileContent);

						if (empty($detectedProtocolName)) {
							$errorMessages[] = $langs->trans("ErrorUnrecognizedEInvoiceFormat");
						} else {
							dol_syslog('manual_import: Protocol detected: ' . $detectedProtocolName, LOG_DEBUG);
							$detectedProtocol = $detectedProtocolName;
							$protocol = $protocolManager->getProtocol($detectedProtocolName);

							if (empty($protocol)) {
								$errorMessages[] = $langs->trans("ErrorProtocolNotEnabled", $detectedProtocolName);
							} else {
								dol_syslog('manual_import: Protocol object obtained, creating invoice...', LOG_DEBUG);
								// Try to create supplier invoice from the source file
								$result = $protocol->createSupplierInvoiceFromSource($fileContent, null, 'MANUAL_' . date('YmdHis'));

							if ($result['res'] >= 0) {
								dol_syslog('manual_import: Invoice created successfully, res=' . $result['res'], LOG_DEBUG);
								// Get the created invoice reference
								if (!empty($result['res'])) {
									$invoice = new FactureFournisseur($db);
									$invoice->fetch($result['res']);
									$importedInvoiceId = $result['res'];
									$importedInvoiceRef = $invoice->ref;
								}

								// Store document metadata
								$document = new Document($db);
								$document->date_creation = dol_now();
								$document->fk_user_creat = $user->id;
								$document->flow_id = 'MANUAL_' . date('YmdHis');
								$document->tracking_idref = $importedInvoiceRef ?: $document->flow_id;
								$document->flow_type = 'SupplierInvoice';
								$document->flow_direction = 'In';
								$document->provider = 'MANUAL';
								$document->fk_element_type = 'invoice_supplier';
								$document->fk_element_id = $importedInvoiceId;
								$document->entity = $conf->entity;

								$docId = $document->create($user);
								if ($docId > 0) {
									$importedDocumentId = $docId;
								}

								$successMessages[] = $langs->trans("EInvoiceManualImportSuccess", $importedInvoiceRef ?: $langs->trans("Unknown"));
							} else {
								dol_syslog('manual_import: Invoice creation failed, res=' . $result['res'] . ', message=' . ($result['message'] ?? 'unknown'), LOG_ERR);
								$errorMessages[] = $result['message'] ?? $langs->trans("ErrorFailedToImportEInvoice");
								if (!empty($result['action'])) {
									$errorMessages[] = $result['action'];
								}
							}
						}
					}
				}
			} catch (Exception $e) {
					dol_syslog('manual_import: Exception during import: ' . $e->getMessage(), LOG_ERR);
				$errorMessages[] = $langs->trans("Error", $e->getMessage());
			} finally {
				// Clean up the uploaded file
				if (isset($uploadFilePath) && file_exists($uploadFilePath)) {
					unlink($uploadFilePath);
				}
			}
		}
	} else {
		dol_syslog('manual_import: Upload error code=' . $_FILES['einvoice_file']['error'], LOG_ERR);
		if (!empty($_FILES['einvoice_file']['error']) && $_FILES['einvoice_file']['error'] != 4) {
			$errorMessages[] = $langs->trans("ErrorFileUpload", $_FILES['einvoice_file']['error']);
		} else {
			$errorMessages[] = $langs->trans("ErrorNoFileSelected");
		}
	}
}

/*
 * View
 */

$form = new Form($db);

$now = dol_now();

llxHeader("", $langs->trans("EInvoiceManualImport"), '', '', 0, 0, '', '', '', 'mod-einvoicing page-manual_import');

// Build navigation breadcrumb
print '<a href="einvoicingindex.php">' . img_picto('', 'back') . ' ' . $langs->trans("EInvoiceManagement") . '</a><br><br>';

print load_fiche_titre($langs->trans("EInvoiceManualImport"), '', 'import@einvoicing');

// Display success messages
if (!empty($successMessages)) {
	print '<div class="ok margintopbottom">';
	foreach ($successMessages as $msg) {
		print '<div>' . $msg . '</div>';
	}
	print '</div><br>';

	// Show link to the imported invoice if available
	if ($importedInvoiceId > 0) {
		$invoice = new FactureFournisseur($db);
		$invoice->fetch($importedInvoiceId);
		dol_syslog('manual_import: Displaying open invoice button for ID=' . $importedInvoiceId, LOG_DEBUG);
		print '<div class="center" style="margin-top: 15px; padding: 10px; background: #e8f5e9; border: 1px solid #4caf50; border-radius: 4px;">';
		print '<a href="/fourn/facture/card.php?facid=' . $importedInvoiceId . '" class="button button-approve" style="color: white; padding: 8px 16px; background: #4caf50; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-weight: bold;">';
		print img_picto('', 'view') . ' ' . $langs->trans("ViewInvoice");
		print '</a>';
		print '</div>';
	}
}

// Display error messages
if (!empty($errorMessages)) {
	print '<div class="error margintopbottom">';
	foreach ($errorMessages as $msg) {
		print '<div>' . $msg . '</div>';
	}
	print '</div><br>';
}

// Main import form
print '<div class="fichecenter">';
print '<div class="fichehalfleft">';

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="upload">';
if (!empty($backtopage)) {
	print '<input type="hidden" name="backtopage" value="' . dol_escape_html($backtopage) . '">';
}

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="2">' . $langs->trans("EInvoiceManualImportTitle") . '</th>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="valoright">';
print $langs->trans("EInvoiceChooseFile");
print '</td>';
print '<td class="value">';

// File upload button with styling
print '<div class="flex inline-block gapreldy">';
print '<input type="file" name="einvoice_file" id="einvoice_file" class="flat" accept=".xml,.pdf" required>';
print '<span class="opacitymedium">' . $langs->trans("EInvoiceAcceptedFormats") . '</span>';
print '</div>';

print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="valoright">';
print $langs->trans("EInvoiceSupportedProtocols");
print '</td>';
print '<td class="value">';
print '<ul>';

// List supported protocols
$protocolManager = new ProtocolManager($db);
$protocolsList = $protocolManager->getProtocolsList();
foreach ($protocolsList as $key => $proto) {
	if (!empty($proto['is_enabled'])) {
		$label = !empty($proto['protocol_label']) ? $proto['protocol_label'] : $proto['protocol_name'];
		print '<li>' . $label . ' - ' . dol_escape_htmltag($proto['description']) . '</li>';
	}
}
print '</ul>';
print '</td>';
print '</tr>';

print '</table>';

print '<div class="center">';
print '<input type="submit" class="button button-approve" value="' . $langs->trans("EInvoiceImportFile") . '">';
print '</div>';

print '</form>';

print '</div>';

// Help section
print '<div class="fichehalfright">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="2">' . $langs->trans("EInvoiceHowTo") . '</th>';
print '</tr>';

print '<tr class="oddeven">';
print '<td colspan="2">';
print '<ol>';
print '<li>' . $langs->trans("EInvoiceManualStepDownload") . '</li>';
print '<li>' . $langs->trans("EInvoiceManualStepSelect") . '</li>';
print '<li>' . $langs->trans("EInvoiceManualStepImport") . '</li>';
print '<li>' . $langs->trans("EInvoiceManualStepVerify") . '</li>';
print '</ol>';
print '</td>';
print '</tr>';

print '</table>';

print '</div>';
print '<div class="clearboth"></div>';

print '</div>';

// End of page
llxFooter();
$db->close();
