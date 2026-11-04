---
title: "Nouveautés de la version 2.0.26"
weight: 300
description: "Factures modèles passées en prélèvement à la signature du mandat SEPA, paiements Stancer visibles dans les événements des factures, clients joignables sur leur seul mobile."
---

# Nouveautés de la version 2.0.26

Cette version fait passer vos clients au prélèvement dès qu'ils signent leur mandat SEPA, et rend le suivi des paiements plus lisible depuis les factures. Elle permet aussi d'encaisser les clients qui n'ont laissé qu'un numéro de mobile, et corrige plusieurs défauts autour des prélèvements rejetés.

## Factures modèles en prélèvement dès la signature du mandat

Quand un client signe son mandat SEPA, ses factures modèles peuvent passer automatiquement en prélèvement sur le compte bancaire Stancer, y compris celles qu'il réglait par carte. Le mandat signé devient son mandat par défaut. Le mode de paiement du tiers ne change pas : une facture ponctuelle garde le mode que vous choisissez.

Le passage se fait à la signature électronique par UptoSign, ou avec le bouton **Mandat signé** de l'onglet Stancer du tiers pour un mandat signé sur papier. L'option est désactivée par défaut. Détails : [Prélèvements SEPA](/stancer/sepa).

## Les paiements Stancer dans les événements des factures

Chaque paiement Stancer encaissé, refusé, échoué ou contesté apparaît une seule fois dans les événements de la facture, comme les prélèvements Stripe. Les autres modules peuvent s'appuyer sur l'événement de refus (`AC_PAYMENT_STANCER_KO`) pour espacer les relances ou suspendre un service. Détails : [Suivi des paiements](/stancer/suivi-paiements).

Un paiement par carte refusé déclenche désormais l'email d'échec au client et l'événement de refus, comme les autres échecs.

## Moins d'emails répétés

L'administrateur n'est plus prévenu à chaque synchronisation quotidienne d'un même paiement en échec : il reçoit un seul email par paiement.

## Clients joignables sur leur seul mobile

Un client qui n'a laissé qu'un numéro de mobile, sans adresse email, peut payer un devis, une commande ou une facture avec Stancer. Les numéros nationaux et ceux des adhérents sont reconnus. Les numéros des départements d'outre-mer sont envoyés à Stancer avec leur propre indicatif, même quand le pays du tiers est la France.

## Documents joints par d'autres modules

L'email d'envoi d'une facture joint aussi les fichiers fournis par d'autres modules, par exemple le relevé de consommation d'une facture de revendeur.

## Corrections de bugs

- Une facture payée à nouveau après un prélèvement SEPA rejeté reste classée payée, au lieu de repasser à l'état "commencée" avec un reste à payer nul.
- Le résultat de la tâche planifiée liste les factures entièrement réglées qui n'ont pas pu être classées payées.
- Le module refuse de s'activer si l'une de ses tables n'a pas pu être créée, au lieu de s'activer sans elle.

## Mise à jour

Remplacez les fichiers du module : aucune autre étape n'est nécessaire. Pour faire passer les factures modèles en prélèvement à la signature des mandats, activez l'option dans la configuration SEPA du module.
