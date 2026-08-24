---
title: "FAQ"
weight: 80
description: "Réponses aux questions fréquentes et résolution de problèmes courants."
---

# FAQ

## Où trouver mes clés API Stancer ?

Connectez-vous à votre espace Stancer sur [manage.stancer.com](https://manage.stancer.com/). Vos clés API (publique et privée) sont disponibles dans les paramètres de votre compte, en version test et production.

## Le module affiche "Erreur d'authentification API"

Ce message apparaît lorsque l'API Stancer rejette vos clés (code HTTP 401). Vérifiez :

- Que vos clés API sont correctement saisies dans la [configuration](/stancer/configuration).
- Que vous n'avez pas inversé clé publique et clé privée.
- Que le mode (test/production) correspond aux clés utilisées (clés `ptest_`/`stest_` pour le test, `pprod_`/`sprod_` pour la production).

## Les reversements n'apparaissent pas dans la liste

Les reversements ne sont pas disponibles en mode test. Passez en mode production pour voir les données de reversement réelles.

Si vous êtes en mode production et que la liste est vide, cliquez sur le bouton **Rafraîchir** pour synchroniser les données depuis l'API Stancer.

## Un paiement est enregistré mais la facture n'est pas classée en payée

Plusieurs raisons possibles :

- Le montant du paiement ne couvre pas le solde total de la facture (paiement partiel).
- L'option "Classer la facture en payée" est désactivée dans la [configuration CB](/stancer/paiements-cb).
- L'utilisateur configuré pour les actions automatisées n'a pas les permissions suffisantes sur les factures.

Vérifiez ces points, puis lancez la tâche planifiée **StancerCheckInvoicesPaid** ou classez la facture manuellement.

## Comment tester le module sans effectuer de vrais paiements ?

Utilisez le mode test :

1. Dans la [configuration générale](/stancer/configuration), désactivez le mode production.
2. Renseignez vos clés API de test (`ptest_` et `stest_`).
3. Effectuez vos tests avec les numéros de carte de test fournis par Stancer.

Lorsque vous passez en production, utilisez le bouton **Nettoyer les données de tests** pour supprimer les paiements de test.

## Le prélèvement SEPA ne se lance pas automatiquement

Vérifiez les points suivants :

- La tâche planifiée **StancerCheckTakePayments** est activée et le cron Dolibarr fonctionne.
- Le client a un mandat SEPA actif (vérifiable dans l'onglet Stancer de sa fiche tiers).
- Le délai de notification SEPA est respecté (14 jours par défaut en France).
- Si l'option "Uniquement pour les clients sous contrat" est activée, le client doit avoir un contrat actif.
- La facture a bien le mode de paiement "Prélèvement" et un RIB/IBAN associé.

## Un prélèvement SEPA a été rejeté, que se passe-t-il ?

Lorsqu'un rejet est détecté lors de la synchronisation :

1. La facture d'origine est rouverte (statut "Impayée").
2. Si la facturation automatique des frais est activée, une facture de frais de rejet est créée.
3. Un email est envoyé au client et à l'administrateur avec le motif du rejet.

Le motif du rejet (code ISO 20022) est visible dans la liste des paiements. Consultez la [page SEPA](/stancer/sepa) pour la liste des codes de rejet courants.

## Comment rembourser un client ?

1. Rendez-vous dans **Banque > Stancer > Paiements**.
2. Trouvez le paiement concerné et ouvrez sa fiche.
3. Cliquez sur **Rembourser**.
4. Saisissez le montant (total ou partiel) et confirmez.

Le remboursement est traité par l'API Stancer. La facture associée est rouverte automatiquement.

## L'API Stancer renvoie une réponse vide (JSON null)

Ce problème peut survenir en cas de surcharge de l'API Stancer ou de problème réseau temporaire. Le module journalise l'erreur dans `dolibarr.log`. Réessayez en cliquant sur le bouton **Rafraîchir** de la liste concernée.

Si le problème persiste, vérifiez la connectivité de votre serveur vers `api.stancer.com`.

## Comment voir les logs du module ?

Le module enregistre ses actions dans le fichier de log principal de Dolibarr (`dolibarr.log`). Les entrées sont préfixées par "stancer". Recherchez cette chaîne pour filtrer les logs pertinents :

```bash
grep "stancer" /path/to/dolibarr/documents/dolibarr.log
```

## Le QR code de paiement ne s'affiche pas

Le module peut générer un QR code pour les liens de paiement. Si le QR code ne s'affiche pas, vérifiez que la bibliothèque PHP `gd` est installée et activée sur votre serveur.

## Comment fonctionne la déduplication des actions ?

Le module utilise un système d'événements (ActionComm) avec des codes uniques pour éviter de traiter plusieurs fois le même événement. Par exemple, si un rejet SEPA est détecté lors de deux synchronisations successives, la facture n'est rouverte qu'une seule fois et un seul email de notification est envoyé.

Ces événements sont visibles dans l'onglet **Événements** de la facture concernée.
