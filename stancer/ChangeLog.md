# CHANGELOG STANCER FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 2.0.23 -- 2026-09-05

Fix payments recorded nowhere when the customer paid from a link received by mail
Read the payment id and the currency from the payment record instead of the browser session
Refuse an API call built with an empty id instead of letting it query the whole payment list
Remember on the payment record that it only covers a deposit ("30% on order")
Fix a false "payment already recorded" that could stop the return page
Fix the missing stancer-card row that hid an already existing Stancer customer on the thirdparty tab
Mail tracking id now follows the paid object: invoice, order, proposal, member or donation
Detect enabled modules on every supported Dolibarr version
The failure mail sent to the administrator now names the Stancer payment and its status
Add tests

## 2.0.22 -- 2026-08-24

Appli dolibarr rules for free community module
-> https://wiki.dolibarr.org/index.php/Modules_-_Rules_for_community_modules

## 2.0.20 -- 2026-08-24

Fix sql requests on admin parts (repair / cleanup)
better log collectors for user support
enhance user doc

## 2.0.18 -- 2026-07-20

Fix fees on operations
Add values guards, htmlfilters, xss checks
Add repair and saferty pages for admin
Enhance logs collect system


## 2.0.14 -- 2026-06-15

Dedup for all rtpe of documents
Better changelog parser (about page)
Add more tests
Fix bad thirdparty links
Better css
Add a re-audit system for muti-payments2.

## 2.0.12 -- 2026-05-20

Change pseudo-stripe fake mode
New experimental option "group sepa mandate same day" to compress fees
better handler for payment on order and propal
unique syslog prefix to make easy to grep logs
better anti doublon mode
mass action on payment list to force refresh data from api
new audit page
fix miss attributions on group payment
fix duplicate mails sent
add a check on module setup to avoid risk of invert private / public keys

## 2.0.10 -- 2026-05-04

Add dolibarr tracking number in mails
Skip doaction if object is not linked to stancer
Fix url links into mails
Split big files into short ones
Add tests
Display debug messages json + fix all case of sepa refund
Better auto send emails
Use history to avoid multi send mails for payout info

## 2.0.8 -- 2026-04-13

Fix Dolibarr 23 compat
Unify mail send system
Add checks for Adherent payments
Normalise syslogs prefix to make it easy to grep
Remove delete rights
Enhance about box
Fix phpdoc comments
Better phpstan checks
Update langs files

## 2.0.6 -- 2026-03-31

Avoid duplicate mails in case of problem
New option to allow retry pay with stancer even if there was a previous payment problem with them
Update user doc

## 2.0.4 -- 2026-03-20

Full migration to Stancer API v2 (all endpoints)
New stancer_disputes model and DB table for dispute tracking
New stancer_api.class.php refactored for API v2 endpoints
New disputes list page (stancer_disputes_list.php) with filters and status display
Automatic invoice reopen when a dispute is lost (status: lost, accepted, out_of_time, not_contestable)
Reverse payment (negative amount) created on invoice reopen to restore correct "amount due"
Customer notification on SEPA rejection using configurable Dolibarr email template with styled layout (blue header), payment link, and PDF attachment
Admin notification email now includes: customer name, invoice ref (clickable link), amount, dispute type, status, SEPA code
Auto-creation of rejection fee invoice (configurable product, prevents SEPA re-collection loop)
ActionComm-based deduplication to prevent duplicate processing across multiple refreshes
Invoice card now shows dispute status and SEPA rejection code alongside payment status
Refunds list page improvements
Refund sync from API v2
New styled email layout (blue header, company name, responsive HTML) for all customer-facing emails
Cron task email sending improvements
Better substitution variable support in email templates
SEPA rejection settings page: fee amount, auto-apply toggle, product selection, CC email
Updated translations (FR, EN, DE, ES, IT, NL)
Removed dependency on Stancer PHP library (pure REST via getURLContent())
PHPStan improvements
Unit tests updated for API v2

## 2.0.2 -- 2026-03-18

New version - no cost, this module is not sell anymore
but maintenance is active, that is our main payment system !

## 1.2.136 -- 2026-01-19

Partial pay (deposit) on propal ... (end)

## 1.2.134 -- 2026-01-09

Partial pay (deposit) on propal ... (in progress)

## 1.2.133 -- 2026-01-06

Propal to Invoice

## 1.2.132 -- 2025-12-09

New back of payment link for propal
Hide payment button if "retry payment"
New refund process on credit note

## 1.2.130 -- 2025-09-09

Fix sql request on auto pay cron task thanks to Florian Koch

## 1.2.128 -- 2025-06-17

Fix undef vars
Fix some php warnings
Force type of a var

## 1.2.126

New substitution var : __STANCER_SEPA_URL__ thanks to Pierre Ardoin (LES METIERS DU BATIMENT)
Fix setup for multicompany thanks to Pierre Ardoin
Fix search amount on some race conditions (now use round,2)
Fix some php warning about undef vars
Fix on check reversements
Better search on PaymentInvoices

## 1.2.124 -- 2025-03-26

migrate from conf->global to getDolGlobalString
cleanup config from sandbox when switching to prod mode
new option for automatic payment via SEPA (invoices linked to contract or no)


## 1.2.121 -- 2025-03-11

fix auto sign mandate via uptosign

## 1.2.119 -- 2025-02-14

fix lang file

## 1.2.118 -- 2025-02-14

add new function stancerShowOnlineIBANDataForCustomer
partial payment of order and auto create paid deposit invoice

## 1.2.117 -- 2024-12-05

Massive code cleanup thanks to phpstan level 2
Option "stancer add fees on each payment" is back thanks to ops sponsorring

## 1.2.116 -- 2024-11-13

Fix: fetch call to be dolibarr 20 compatible (next)

## 1.2.114 -- 2024-11-07

Fix: update CB card number / handle expiry case

## 1.2.112

Fix : fetch call to be dolibarr 20.0 compatible

## 1.2.110

New : delete old sepa / create new sepa on stancer_thirdparty.php (backoffice)

## 1.2.109

New : add second sepa mandate even if there is one sepa on customer account
      see STANCER_PUBLIC_IBAN_PAGE_FORCE new option in setup

Fix : better update on payment status (in case of reject some days later)
New : add functions for other modules (one page basket for example)
Fix : dolibarr 20 SQL
Fix : import stancer reversement files

## 1.2.108 -- 2024-06-11

NEW: add right label on bank VIR : same label as you can read on your main bank account / pdf
NEW: add details of fees for payments done via physical device
NEW: use getRemainToPay on objects
NEW: better check for duplicates payments (if payment on order, check if linked invoice was not
     paid & reverse)
NEW: very long & old time for sync history if needed
NEW: add tons of compatibility stuff with for EasyA "Rapprochement bancaire" /
NEW: start of refunds support
NEW: import stancer csv files you can download on manage.stancer.com each month
     (stancer_import_check_reversements.php)
NEW: post accountancy fixes script, could be dangerous, no warranty on it
     (stancer_bookkeeping.php)
NEW: export monthly stancer csv files to proper table you can print
FIX: better links in mails



## 1.2.104 -- 2024-03-21

FIX: race condition on some transfert lines from Stancer to main account with only one bank line
     and not the opposite line on destination account

## 1.2.102 -- 2024-03-19

FIX: convert timestamp for some dolibarr objects
FIX: multiple php 8.2 warning messages
FIX: auto build / tests CI
FIX: convert dateo to timestamp

## 1.2.100 -- 2024-02-28

FIX: date on bank transferts
FIX: force popup for sepa manual actions
FIX: translate with translation string % (dedicace to devoc solutions)
FIX: force pay after CB refused
FIX: manual schedule make 'last run' datetime to null to force all process
FIX: double check against $thirdparty_id
FIX: countable object on SEPA


## 1.2.94 -- 2024-01-29

FIX: better search payment for a Stancer Invoice (check fees on prev month) + exclude payment not
     yet reconcilliated
FIX: switch back to translation on (StancerFees) to real translation due to FEC exports and search
     on lists
FIX: in some race conditions stancer lines could stay to "in progress" status
FIX: new test againts customer name length (4-64)


## 1.2.92

FIX: better refresh of payout list (double check stancer -> dolibarr then dolibarr -> stancer)
     like it did for payments

## 1.2.90 -- 2023-12-05

NEW: option to switch from stancer to other payment provider if amount > limit defined into admin
TRY: php 7.3 as minimal prereq (stancer sdk says 7.4 ...)
FIX: massive code cleanup thanks to phpstan


## 1.2.88 -- 2023-11-28

NEW: option to consider cb payment as done as soon as early bank return authenticated process
NEW: doCheckInvoicesPaid cron task to set invoice paid if amount to pay = 0
FIX: code cleanup thanks to phpstan
FIX: order linked to paid invoices race condition
FIX: invoice paid with other payment system race condition


## 1.2.86 -- 2023-11-17

NEW: check if stancer amount is > minimal (50 cents)


## 1.2.84 -- 2023-11-15

FIX: force stancer account and cb/sepa payment mode in case of that payment
     system is used even if you specify something else on invoice / order card

DEV: better logs on payment back


## 1.2.82 -- 2023-11-08

FIX: race condition with memcached
NEW: option to send mail on order
FIX: substitute sepa_delay on mail template

## 1.2.80 -- 2023-10-25

FIX: race condition on order -> payment -> invoice (no pdf joined to mail)
FIX: mysql 5.5 compatible SQL for old installations (on nas for example)
FIX: use MAIN_MAIL_EMAIL_FROM (dolibarr core) instead of MAILING_EMAIL_FROM (mailing module)
NEW: mail "in case of problem please follow that link to make your payment"
FIX: multientity STANCER bank account
FIX: better sign position on stancer PDF mandate

## 1.2.76 -- 2023-09-26

FIX: include needed on trigger file

## 1.2.74 -- 2023-09-23

NEW: auto redirect to uptosign (option)
NEW: option admin to disable stancer CB stuff (experimental)
FIX: more check againts db update with empty values


## 1.2.72 -- 2023-09-18

NEW: auto configure payment mode on invoices if make from order
NEW: add UPTOSIGN magic keywords into mandate
FIX: fix typo into data.sql (default mail with __USER_SIGNATURE__)
FIX: send invoice to billing contact mail address
FIX: better code to send invoices when payed (use of a trigger)
FIX: add propal id as source for order id
NEW: all mail templates are embedded into that version
FIX: some links into error messages was incorrects
FIX: some error messages
NEW: option to auto-update date_lim_reglement in case of SEPA delay
FIX: get outstanding bills compute amount only for invoices concerned by Stancer CB payment mode

## 1.2.58 -- 2023-09-05

FIX: email template reference
FIX: better dolibarr-18 compatible when no other payment system is enabled
FIX: add css into module*
FIX: confirm SEPA mandate only if needed
FIX: correct for amount to pay if invoice is already partialy paid
FIX: token id on some links missing (dolibarr 17/18)
FIX: remove debug message on add cb / add sepa
FIX: handle stancer message about error on sepa
NEW: experimental "take payment" on registered cb

## 1.2.52 -- 2023-07-26

NEW: add php-iban lib from dolibarr 17+ for older versions as backport to be able to
     well convert IBAN to RIB
NEW: add option to force pay by SEPA even if there is a previous one in error
FIX: dolibarr17/18 and double def of dolJSToSetRandomPassword
FIX: add CNIL / DPO informations on SEPA

## 1.2.46 -- 2023-07-20

NEW: trigger to send mail with invoice when invoice is classified as paid (SEPA delay)
FIX: keep our uniqid even if Stancer change it (keep tracking order/invoice/...)
FIX: add euro and prices into mail reports

## 1.2.44 -- 2023-07-11

FIX: SQL request potential error on low invoice number
FIX: Update data if orderId exists and no TAG
NEW: Translate labels for filter dropdown
NEW: getObjectFromOrderID
FIX: scheduledJob (only diff with previous run)
FIX: set user if unset in case of anonymous paymentback

## 1.2.40 -- 2023-06-21

FIX: better filters tests and messages on cron tasks
NEW: new function to make tests agains Tag
NEW: change default sorting list on payment list
NEW: display a message if you don't disable/(re)enable the module
NEW: display net amount on payout

## 1.2.38 -- 2023-06-19

ENH: Cron do only thing on newer data since previous cron call
FIX: Better tests before pay
FIX: Max length for member subscription (remove seconds)
FIX: Handle membersubscription type of payement sources
NEW: STANCER_NB_DAYS_TO_SYNC on admin setup to choose history sync length
NEW: Add a new function to sync / refresh old data present in dolibarr stancer history
NEW: Better display OrderId / StancerID in payment list
FIX: Apply live_mode on sql requests to display / use only data on production / test environment
FIX: Better SEPA delay tests

## 1.2.30 -- 2023-06-02

FIX: Téléchargement des paiements
FIX: Logo sur les pages CB/SEPA
FIX: Use ButAction CSS
FIX: Remove duplicate keys
FIX: Private / Protected var labelStatusShort
FIX: Logo on CB / SEPA Page
FIX: Card details on payment back

## 1.2.16 -- 2023-05-25

NEW: notification par mail lors de l'ajout d'une CB, les premiers tests ne sont par contre pas concluants (3DS)
FIX: prise en compte de la saisie des "0" sur la fiche d'enregistrement CB
FIX: ajout d'un filtre complémentaire sur la gestion des paiements automatiques
FIX: amélioration de la mise en page de saisie cb / sepa (alignements)

## 1.2.14 -- 2023-05-20

FIX: correctif sur le montant des virements de compte a compte pour ne pas avoir les frais (2 mois de reculs par rapport à l'ouverture de compte
Stancer, la procédure est maintenant validée ainsi)

NEW : ajout d'alertes par mail pour les tâches planifiées, pensez à configurer l'adresse mail de notification dans la configuration du module

## 1.2.12 -- 2023-05-19

EXPERIMENTAL: Grosse evolution sur la gestion des adhérents
* ajout d'une page de config du module pour activer la gestion des associations
* ajout de 3 extrafields sur la table member pour stocker les donnée stancer
* activation des liens dans les mails qui retournent sur la page de paiement en ligne
* paiement de la cotisation depuis le formulaire d'auto-adhésion ok

## 1.2.10 -- 2023-05-17

Ajout d'un message clair dans l'encart des paiements pour indiquer qu'un paiement Stancer est en cours même s'il n'est pas encore totalement validé
Corrige le retour de paiement pour passer la facture à payée (et non "partiellement payée")
Corrige une typo sans importance sancer -> stancer (manquait le t)
Ajout d'un lien "payer les factures en attente" sur la liste des CB (comme pour Stripe)
Amélioration de la tâche planifiée qui procède aux paiements automatiques (en lien avec CB/Expérimental)
Gestion du cas du unique_id déjà utilisé (Stancer_payments)
EXPERIMENTAL: Ajout d'un qrcode pour proposer au client de payer en ligne (pas de TPE par exemple et qu'il préfère un coup de qrcode plutôt qu'un mail avec le lien de paiement) ... arrivera prochainement sur l'affichage déporté, voir projet eTicket
EXPERIMENTAL: Amélioration sur la saisie des informations de CB pour paiement récurrents

## 1.2.6 -- 2023-05-16

Correctif de bugs pour la prise en compte des paiements TPE qui n'ont pas été initiés par dolibarr (et donc sans lien possible avec les factures)
Amélioration du code (plus robuste) en cas d'exceptions provoquées par la lib stancer
Amélioration des tests pour la saisie du RIB pour le mandat SEPA

EXPERIMENTAL: Page publique de saisie des informations CB pour mettre en place le paiement automatique des factures récurrentes (abonnements par exemple)
EXPERIMENTAL: Implémentation des dons et cotisations pour les Associations

## 1.2.0 -- 2023-05-05

Configuration du délais entre date de facturation et date de prélèvement
Prise en compte des status "non payés" sur l'actualisation des paiements
Option: envoi automatique d'un mail pour les factures payées par CB
Option: envoi automatique d'un mail d'information pour les factures payées par SEPA
Option: envoi automatique d'un mail lors du lancement du prélèvement SEPA après le délais indiqué dans la configuration du module
Désactive les boutons + message tooltip


## 1.0.20 -- 2023-04-29 [urgent]

Bordure autour de la signature du mandat SEPA
Bug SQL : l'ajout des champs "stancer" dans la table llx_societe_rib n'était plus dans le fichier d'installation depuis la 1.0 !!!!

## 1.0.18 -- 2023-04-24

Creation des liens de la facture avec les paiements

## 1.0.16 -- 2023-04-17

Ajout d'une clé de configuration pour affecter un compte client générique en cas d'absence d'information
(paiement TPE dissocié de dolibarr par exemple)

Prise en compte du cas particulier de dolibarr + multientity sur les retours de paiements pour lesquels
l'entité n'est pas "passée"

Cache les vieilles opérations brouillon pour ne garder dans les listes que
les opérations intéressantes

## 1.0.12 -- 2023-04-12

Modification du php min version (7.4) lié à la lib Stancer (https://stancer.com/documentation/fr/api/?lang=php)
Correctif d'affichage des totaux des frais (cents -> euro)

## 1.0.10 -- 2023-03-23

Prise en compte d'une re-demande de paiement après un echec (réutilisation impossible de la transaction en cours)

## 1.0.8 -- 2023-03-15

Ameliorations diverses (retours d'erreurs, recuperation de codes d'erreurs stancer)
Evite d'afficher le bouton de paiement Stancer si le compte client ne peut pas être créé (no mail / no phone)
Amélioration du message sur la page de paiement s'il manque tel/mail du client
Amélioration des libellés (paiement CB + intitulés des prélèvements sur extraits de comptes)
Amélioration sécurité (db->escape systématique)

## 1.0.0 -- 2023-03-12

Premiere release publique (il faut bien que ça arrive un jour)

## 0.9.4

- Corrige le bug de création du compte SEPA

## 0.9.3

- Amélioration sur la partie SEPA : lorsqu'un client communique ses coordonnées IBAN vous recevrez un
  courrier électronique vous invitant à continuer la procédure (sinon comment savoir qu'un client à
  complété le formulaire...)
- Gestion interne des paiements partiels d'une facture (à valider avec le core de dolibarr donc pas
  proposé dans la version publique du module)
- Amélioration du modèle de mandat PDF auto généré
- Amélioration de la page de saisie de l'IBAN qui doit passer sur tout type de téléphone + ordinateur

## 0.9.2

Corrige le champ de limite (string) -> boite déroulante
Corrige le listing des encaissements / virements lié à la modification précédente

## 0.9.0

Tâche planifiée qui se charge de vérifier une fois par jour (nuit) où en sont les paiements SEPA
Nouvelle clé de configuration pour choisir le nombre d'entrées à télécharger lors de la synchronisation
avec Stancer
Ajout d'une option de choix pour l'ajout automatique des frais sur la banque

## 0.8.9

Corrige un bug Dolibarr16+ pour les codes IBAN (merci @Mauresque)
Amélioration de l'affichage la liste des "payouts" (transferts) de Stancer vers le compte principal
Nouvelle option dans la configuration du module : choix du compte bancaire de destination des transferts
Option du module pour "Ajouter une écriture sur la banque (Compte Stancer) pour chaque ligne de frais"


## 0.8.5

Prise en charge des commandes avec paiemen total une commande payée -> une facture payée
  (Note: pour les acomptes, voir le forum & bugs en cours sur le coeur de dolibarr)
Corrige un bug pour éviter des duplicata de SEPA & paiements


## 0.8.0

Ajout des frais sur la page de listing des paiements Stancer
Amélioration de la page de retour après paiement CB :
  mise à jour de la facture pour ajouter les informations de paiement et compte
  bancaire de destination, ajout des évènements

## 0.7.0

Warning for beta testers, internal uuid is switched to standard dolibarr tag
Add full functionnal public page for SEPA input by customers + onlign sign via uptosign if module exists

## 0.6.5

Add a public page to self enter SEPA informations by customers
Fix newpayment page
Fix stancer bank account auto-create
Fix empty customer code
Fix double stancer button on dolibarr > 16
Fix phone number customer required
First public beta version

## 0.0.0

Start dev time - 2023-01-22
