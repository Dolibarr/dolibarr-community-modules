# SUPER PDP — connexion en mode « Authorization Code » (délégation / marque grise)

Runbook opérateur pour connecter Dolibarr à SUPER PDP via le flow OAuth 2.1 **Authorization Code**
(délégation d'autorisation), et pour enrôler de nouveaux clients (marque grise).

À utiliser quand l'utilisateur final **délègue** l'accès à son compte SUPER PDP (tunnel KYC/KYB),
au lieu de coller des identifiants (mode *Client Credentials*).

---

## 1. Côté SUPER PDP (une fois par application)

1. Créer/ouvrir un compte sur https://superpdp.tech (sandbox pour les tests).
2. Créer une **application OAuth** dans l'interface SUPER PDP → récupérer **`client_id`** et **`client_secret`**.
3. Enregistrer la **Redirect URI** **À L'IDENTIQUE** de celle affichée dans le module, p. ex. :
   `https://VOTRE-DOLIBARR/custom/einvoicing/admin/setup.php`
   (toute différence — http/https, slash final, sous-chemin — fait échouer la connexion).

## 2. Côté Dolibarr (module einvoicing)

Configuration → eInvoicing :
- **Plateforme (EINVOICING_PDP)** = `SUPERPDP`
- **Type d'authentification OAuth** = `Authorization Code (délégation / tiers)`
- **Client ID / Client Secret** = ceux de l'application SUPER PDP
- Options d'embarquement (facultatives) :
  - **send_and_receive** : `any` (l'utilisateur choisit) / `send` (émission seule) / `receive` (force la réception)
  - **only_future** : Oui = uniquement la date officielle 01/09/2026 ; Non = phase pilote autorisée
  - **directory_entry_identifier** : adresse de facturation électronique à créer (optionnel, fr_siren)
- `EINVOICING_LIVE` vide = **sandbox** (le scheme entreprise envoyé est `sandbox`) ; =1 = prod (`fr_siren`/`be_numero_entreprise`).

## 3. Se connecter

1. Cliquer **« Connecter à SuperPDP »** → redirection vers `https://api.superpdp.tech/oauth2/authorize`
   (pré-rempli avec le SIREN de votre société et l'email de l'utilisateur).
2. Dérouler le **tunnel d'inscription / consentement** SUPER PDP (KYC/KYB).
3. Retour automatique sur `…/admin/setup.php?code=…&state=…` → le module **vérifie le `state`** (anti-CSRF),
   **échange le `code`** contre un `access_token` + `refresh_token`, et les stocke.
4. Vérifier le bandeau « Token generated successfully » et le bouton **Healthcheck**.

## 4. Enrôler un nouveau client (marque grise)

Le flow ci-dessus **EST** le processus d'enrôlement : pour chaque tiers, l'application OAuth de l'opérateur
lance le tunnel `authorize` avec le **numéro d'entreprise du client** pré-rempli (paramètres
`superpdp_company_number` + `superpdp_company_number_scheme` = `sandbox` en test, `fr_siren` en prod).
Le client consent dans le tunnel → son compte SUPER PDP est créé/relié, et l'`access_token` obtenu
permet d'agir pour lui.

> Multi-clients : une instance/entité Dolibarr connectée = un compte délégué. Pour gérer plusieurs clients
> distincts, chacun se connecte depuis sa propre instance/entité (le stockage du token est par
> `service` + `entity`).

## 5. Cycle de vie des jetons

- **access_token** : courte durée (**30 min**). Renouvelé **automatiquement** via le **refresh_token**
  (grant `refresh_token`, **rotation** à chaque usage) — **sans ouvrir de nouvelle session** côté SUPER PDP.
- **refresh_token** : longue durée (**1 an**, repoussé à chaque usage). N'expire en pratique que si le
  compte reste inutilisé 1 an.
- Révocation possible (RFC 7009) : `https://api.superpdp.tech/oauth2/revoke`.

## 6. Dépannage

| Symptôme | Cause probable | Action |
|---|---|---|
| « le paramètre de sécurité state ne correspond pas » | session expirée / lien rejoué | relancer « Connecter à SuperPDP » |
| Erreur `redirect_uri` / `invalid redirect` | Redirect URI non identique côté SUPER PDP | recopier exactement l'URL affichée dans le module |
| `invalid_grant` / code expiré | le `code` n'a pas été échangé à temps | relancer la connexion |
| HTTP 400 `CREATE_ERROR` à l'envoi | SIREN vendeur ≠ entreprise de la session | aligner `$mysoc->idprof1` avec le compte délégué |
| Beaucoup de « Sessions » côté SUPER PDP | renouvellement par re-auth (mode client_credentials) | passer en Authorization Code (refresh sans nouvelle session) |

## 7. Référence

- Doc SUPER PDP : https://www.superpdp.tech/documentation/4#authorization-code
- Exemple officiel (Go) : https://github.com/superpdp/examples/blob/main/erp.go
- Endpoints : authorize `https://api.superpdp.tech/oauth2/authorize` · token `https://api.superpdp.tech/oauth2/token`
