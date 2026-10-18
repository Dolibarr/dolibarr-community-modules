---
title: "Associations"
weight: 65
description: "Paiement des cotisations et dons en ligne pour les associations via Stancer."
---

# Associations

Le module Stancer prend en charge le paiement en ligne des cotisations d'adhésion et des dons pour les associations utilisant Dolibarr. La configuration se fait dans l'onglet **Associations** de la page d'administration.

![Onglet Associations de la configuration Stancer avec l'option d'activation](screenshots/configuration-associations.webp)

## Activation

Activez l'option **Activer Stancer pour les associations** pour rendre le paiement en ligne disponible sur les fiches d'adhérent et de don.

> **Prérequis :** les modules **Adhérents** et/ou **Dons** doivent être activés dans Dolibarr.

## Cotisations d'adhésion

Lorsque la fonctionnalité est activée, un bouton de paiement Stancer apparaît sur la fiche d'un adhérent. Le workflow est le suivant :

1. L'adhérent reçoit un lien de paiement (par email ou via la page publique).
2. Il paie sa cotisation en ligne par carte bancaire.
3. Le paiement est enregistré dans Dolibarr.
4. La cotisation est validée automatiquement.

Le module lie le paiement au compte client Stancer de l'adhérent. Si l'adhérent n'a pas encore de compte Stancer, il est créé automatiquement.

## Dons

Le même mécanisme fonctionne pour les dons :

1. Un lien de paiement est disponible sur la fiche du don.
2. Le donateur paie en ligne.
3. Le don est enregistré et marqué comme payé.

## Page publique

Les liens de paiement pour les cotisations et les dons utilisent les mêmes pages publiques que les paiements classiques. Le client n'a pas besoin de se connecter à Dolibarr pour effectuer son paiement.
