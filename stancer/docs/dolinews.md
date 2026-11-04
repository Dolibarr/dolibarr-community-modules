---
title: "Stancer 2.0.26 : vos clients passent au prélèvement dès qu'ils signent leur mandat SEPA"
summary: "À la signature du mandat SEPA, les factures modèles du client passent en prélèvement, y compris celles réglées par carte. Les paiements Stancer apparaissent aussi dans les événements des factures, et un client qui n'a laissé qu'un mobile peut payer en ligne."
type: release
version: "2.0.26"
focus: feature_major
maturity: stable
compat_status: declared
locale: fr_FR
---

## Du paiement par carte au prélèvement, sans ressaisie

Le module Stancer encaisse vos clients par carte bancaire et par prélèvement SEPA directement depuis Dolibarr. Le paiement par carte d'un abonnement reste fragile : une carte arrive en fin de validité, un paiement est refusé, et les frais sont plus élevés que ceux d'un prélèvement.

Avec cette version, dès que le client signe son mandat SEPA :

- le mandat signé devient son mandat par défaut, même s'il en avait déjà un ;
- toutes ses factures modèles passent en prélèvement sur le compte bancaire Stancer, y compris celles qu'il réglait par carte, et les factures de ses contrats suivent ;
- le mode de paiement du tiers ne change pas : une facture ponctuelle garde le mode que vous choisissez.

La bascule se fait à la signature électronique par UptoSign, ou d'un clic sur le bouton **Mandat signé** pour un mandat signé sur papier. Chaque bascule est tracée dans l'agenda du tiers. L'option est désactivée par défaut.

## Un suivi plus lisible

- Chaque paiement Stancer encaissé, refusé, échoué ou contesté apparaît une seule fois dans les événements de la facture, comme les prélèvements Stripe.
- Les autres modules peuvent s'appuyer sur l'événement de refus pour espacer les relances ou suspendre un service.
- L'administrateur reçoit un seul email par paiement en échec, et non plus un à chaque synchronisation.

## Des clients joignables sur leur seul mobile

Un client qui n'a laissé qu'un numéro de mobile peut payer un devis, une commande ou une facture avec Stancer, y compris depuis un département d'outre-mer.

Plusieurs corrections accompagnent cette version, dont une facture payée à nouveau après un prélèvement rejeté, qui reste désormais classée payée.

## Essayez-le

Essayez Stancer sur un Dolibarr installé en moins de 20 secondes. Pour aller
jusqu'au paiement, saisissez vos clés de test Stancer dans la configuration du
module : [dolitest.fr/stancer](https://dolitest.fr/stancer/)

## Mise à jour

Remplacez les fichiers du module, aucune autre étape n'est nécessaire.

Documentation : [doc.cap-rel.fr/stancer](https://doc.cap-rel.fr/stancer/)
