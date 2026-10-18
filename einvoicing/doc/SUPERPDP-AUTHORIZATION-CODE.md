# SUPER PDP — connexion en mode « Authorization Code » (délégation / marque grise)

Runbook opérateur pour connecter Dolibarr à SUPER PDP via le flow OAuth 2.1 **Authorization Code**
(délégation d'autorisation), et pour enrôler de nouveaux clients (marque grise).

À utiliser quand l'utilisateur final **délègue** l'accès à son compte SUPER PDP (tunnel KYC/KYB),
au lieu de coller des identifiants (mode *Client Credentials*).

---

## 1. Côté SUPER PDP (une fois par application)

1. Créer/ouvrir un compte sur https://superpdp.tech (sandbox pour les tests).
2. Créer une **application OAuth** dans l'interface SUPER PDP → récupérer **`client_id`** et **`client_secret`**.
3. Enregistrer la **Redirect URI** **À L'IDENTIQUE** de celle affichée dans le module :
   `https://VOTRE-DOLIBARR/custom/einvoicing/admin/setup.php` en connexion directe,
   `https://PROXY/custom/einvoicing/public/proxy_oauthcallback.php` sur l'instance proxy (§4, URL
   affichée dans l'écran du proxy) (toute différence — http/https, slash final, sous-chemin — fait échouer la connexion).

## 2. Côté Dolibarr (module einvoicing)

Configuration → eInvoicing :
- **Plateforme (EINVOICING_PDP)** = `SUPERPDP`
- Le mode Authorization Code n'a plus de champ dans l'écran : en connexion directe, poser à la main la
  constante `EINVOICING_SUPERPDP_GRANT_TYPE` = `authorization_code` (Configuration → Divers). Le chemin
  recommandé pour la délégation est le mode « via partenaire » (§4).
- **Client ID / Client Secret** = ceux de l'application SUPER PDP (le secret est stocké chiffré)
- Options d'embarquement (facultatives), saisies sur l'instance **proxy** uniquement
  (`EINVOICING_SUPERPDPVIAPARTNER_SEND_AND_RECEIVE`, `_ONLY_FUTURE`, `_DIRECTORY_ENTRY_IDENTIFIER`) ;
  en connexion directe, ce sont les constantes `EINVOICING_SUPERPDP_SEND_AND_RECEIVE` / `_ONLY_FUTURE` /
  `_DIRECTORY_ENTRY_IDENTIFIER`, à poser à la main :
  - **send_and_receive** : `any` (l'utilisateur choisit) / `send` (émission seule) / `receive` (force la réception)
  - **only_future** : Oui = uniquement la date officielle 01/09/2026 ; Non = phase pilote autorisée
  - **directory_entry_identifier** : adresse de facturation électronique à créer (optionnel, fr_siren)
- `EINVOICING_LIVE` vide = **sandbox** (le scheme entreprise envoyé est `sandbox`) ; =1 = prod (`fr_siren`/`be_numero_entreprise`).

## 3. Se connecter

1. Cliquer **« Connecter à SuperPDP »** → redirection vers `https://api.superpdp.tech/oauth2/authorize`
   (pré-rempli avec le SIREN de votre société et l'email de l'utilisateur).
2. Dérouler le **tunnel d'inscription / consentement** SUPER PDP (KYC/KYB).
3. Retour automatique sur `…/admin/setup.php?code=…&state=…` (connexion directe ; via le proxy, le
   client reçoit directement `…/admin/setup.php?accesstoken=…&refresh_token=…&expires_in=…`) → le module **vérifie le `state`** (anti-CSRF),
   **échange le `code`** contre un `access_token` + `refresh_token`, et les stocke.
4. Vérifier le bandeau « Token generated successfully » et le bouton **Healthcheck**.

## 4. Enrôler un nouveau client (marque grise)

La marque grise passe par un **proxy OAuth** : l'instance opérateur (`EINVOICING_SUPERPDP_VIAPARTNER` =
`proxy`) détient le `client_id` / `client_secret` (`EINVOICING_SUPERPDPVIAPARTNER_CLIENT_ID[_PROD]`,
`_CLIENT_SECRET[_PROD]`) et ne livre les jetons qu'aux domaines de
`EINVOICING_SUPERPDPVIAPARTNER_ONLY_DOMAIN`. Chaque instance cliente pose `EINVOICING_SUPERPDP_VIAPARTNER`
(nom du partenaire) et `EINVOICING_SUPERPDP_VIAPARTNER_OAUTH_URL` (URL du proxy), puis choisit l'entrée
« SuperPDP via partenaire » ; `EINVOICING_SUPERPDP_VIAPARTNER_ONLY` masque les autres plateformes. Le
client ne détient jamais le secret.

Le flow ci-dessus, déroulé par le proxy, **EST** le processus d'enrôlement : pour chaque tiers, l'application OAuth de l'opérateur
lance le tunnel `authorize` avec le **numéro d'entreprise du client** pré-rempli (paramètres
`superpdp_company_number` + `superpdp_company_number_scheme` = `sandbox` en test, `fr_siren` en prod).
Le client consent dans le tunnel → son compte SUPER PDP est créé/relié, et l'`access_token` obtenu
permet d'agir pour lui.

> Multi-clients : une instance/entité Dolibarr connectée = un compte délégué. Pour gérer plusieurs clients
> distincts, chacun se connecte depuis sa propre instance/entité. Les jetons sont stockés chiffrés
> (`dolEncrypt`), dans `llx_oauth_token` (Dolibarr ≥ 23) ou dans des constantes (< 23), par `service` +
> `entity`. En multisociété avec `EINVOICING_MULTICOMPANY_USE_MASTER_SETUP`, les entités esclaves
> utilisent le jeton de l'entité maître.

## 5. Cycle de vie des jetons

- **access_token** : courte durée (**30 min**). Renouvelé **automatiquement** via le **refresh_token**
  (grant `refresh_token`, **rotation** à chaque usage) — **sans ouvrir de nouvelle session** côté SUPER PDP.
- **refresh_token** : longue durée (**1 an**, repoussé à chaque usage). N'expire en pratique que si le
  compte reste inutilisé 1 an.
- Renouvellement déclenché 60 s avant expiration. En mode « via partenaire », il passe par le proxy
  (`action=refresh`) ; s'il échoue, il faut se reconnecter. En connexion directe, un refresh refusé
  retombe sur une ré-authentification client_credentials.
- Révocation possible (RFC 7009) : `https://api.superpdp.tech/oauth2/revoke`.

## 6. Dépannage

| Symptôme | Cause probable | Action |
|---|---|---|
| « le paramètre de sécurité state ne correspond pas » | session expirée / lien rejoué | relancer « Connecter à SuperPDP » |
| Erreur `redirect_uri` / `invalid redirect` | Redirect URI non identique côté SUPER PDP | recopier exactement l'URL affichée dans le module |
| `invalid_grant` / code expiré | le `code` n'a pas été échangé à temps | relancer la connexion |
| HTTP 400 `CREATE_ERROR` à l'envoi | SIREN vendeur ≠ entreprise de la session | aligner `$mysoc->idprof1` avec le compte délégué |
| Beaucoup de « Sessions » côté SUPER PDP | renouvellement par re-auth (mode client_credentials) | passer en mode « via partenaire », ou poser `EINVOICING_SUPERPDP_GRANT_TYPE` = `authorization_code` |

## 7. Référence

- Doc SUPER PDP : https://www.superpdp.tech/documentation/4#authorization-code
- Exemple officiel (Go) : https://github.com/superpdp/examples/blob/main/erp.go
- Endpoints : authorize `https://api.superpdp.tech/oauth2/authorize` · token `https://api.superpdp.tech/oauth2/token`
