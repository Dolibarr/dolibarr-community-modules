# STANCER FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

Module Dolibarr pour l'integration de la plateforme de paiement [Stancer](https://stancer.com/fr/paiement-en-ligne?mtm_campaign=Dolibarr&mtm_source=Dolibarr).

## Description

Ce module permet d'accepter des paiements par **carte bancaire** et **prelevement SEPA** directement depuis Dolibarr, en utilisant l'infrastructure de paiement Stancer.

## Fonctionnalites

### Moyens de paiement

- **Carte bancaire** (Visa, Mastercard, CB) avec support 3D Secure
- **Prelevement SEPA** avec gestion des mandats

### Gestion des transactions

- Suivi des paiements (statuts : autorise, capture, refuse, etc.)
- Gestion des remboursements
- Suivi des reversements (virements de Stancer vers votre compte bancaire)
- Synchronisation automatique avec l'API Stancer

### Integrations Dolibarr

- Factures clients
- Commandes
- Dons
- Adhesions et cotisations (associations)
- Rapprochement comptable

### Pages publiques

- Formulaire de paiement CB pour les clients
- Formulaire de saisie IBAN pour les prelevements SEPA
- Pages de retour apres paiement

### Administration

- Configuration des cles API (mode test et production)
- Parametrage CB et SEPA separement
- Templates d'emails personnalisables
- Logs des transactions
- Support multi-societe

## Pre-requis

| Logiciel | Version minimum |
|----------|-----------------|
| PHP | 7.4 |
| Dolibarr | 15.0 |

### Modules Dolibarr requis

- Prelevements (modPrelevement)
- Banques et caisses (modBanque)

## Installation

1. Telecharger le module depuis [Dolistore](https://www.dolistore.com)
2. Extraire l'archive dans le dossier `htdocs/custom/` de Dolibarr
3. Activer le module dans **Accueil > Configuration > Modules**
4. Configurer les cles API dans **Configuration > Modules > Stancer**

## Configuration

### Cles API Stancer

1. Creer un compte sur [Stancer](https://stancer.com/fr/paiement-en-ligne?mtm_campaign=Dolibarr&mtm_source=Dolibarr)
2. Recuperer vos cles API (publique et privee) depuis le dashboard Stancer
3. Renseigner les cles dans la configuration du module
4. Utiliser le mode test pour valider l'integration avant passage en production

### Parametrage des paiements

- **Carte bancaire** : activer/desactiver, seuil de montant, 3D Secure
- **SEPA** : delai de prelevement, gestion des mandats
- **Emails** : personnalisation des notifications

## Utilisation

### Paiement par carte bancaire

1. Ouvrir une facture client
2. Cliquer sur le bouton "Payer avec Stancer"
3. Le client est redirige vers la page de paiement securisee
4. Apres paiement, retour automatique et mise a jour du statut

### Paiement par SEPA

1. Enregistrer l'IBAN du client (formulaire public ou saisie manuelle)
2. Creer le mandat SEPA
3. Lancer le prelevement depuis la facture
4. Suivi du statut dans la liste des paiements

### Synchronisation

Une tache planifiee (cron) synchronise automatiquement :
- Les statuts des paiements
- Les reversements recus
- Les remboursements

## Structure du module

```
stancer/
├── admin/           # Pages d'administration
├── class/           # Classes PHP principales
├── core/            # Triggers et modules specifiques
├── langs/           # Fichiers de traduction
├── lib/             # Bibliotheque de fonctions
├── public/          # Pages publiques (paiement, IBAN)
├── sql/             # Scripts SQL d'installation
└── vendor/          # Dependances (SDK Stancer)
```

## API Stancer

Le module utilise l'API Stancer v2. Documentation : https://docs.stancer.com/api/

### Ressources utilisees

| Endpoint | Usage |
|----------|-------|
| `/customers/` | Gestion des clients |
| `/cards/` | Cartes bancaires |
| `/sepa/` | Comptes SEPA |
| `/mandates/` | Mandats de prelevement |
| `/checkout/` | Paiements |

## Support

- Documentation Stancer : https://docs.stancer.com
- Modules Dolibarr : https://www.dolistore.com

## Licence

### Code source

GPLv3 ou (a votre choix) toute version ulterieure. Voir le fichier COPYING.

### Documentation

Textes et documentation sous licence GFDL.

## Auteur

[CAP-REL](https://cap-rel.fr) et la communauté Dolibarr