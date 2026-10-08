# STANCER FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## Fonctionnalités

Encaissez vos clients par carte bancaire et par prélèvement SEPA directement
depuis Dolibarr, avec la plateforme de paiement française
[Stancer](https://www.stancer.com/).

- **Paiement par carte bancaire** : lien de paiement en ligne envoyé au client
  depuis une facture, une commande ou un devis, page de paiement sécurisée
  (3-D Secure), acompte sur commande ou devis, facture classée payée une fois
  le paiement confirmé
- **Prélèvement SEPA** : page publique de saisie de l'IBAN, mandat SEPA en PDF
  créé automatiquement, signature électronique optionnelle avec UptoSign,
  prélèvement automatique des factures échues
- **Rejets SEPA** : notification du client et de l'administrateur, facture des
  frais de rejet créée automatiquement si vous le souhaitez
- **Suivi** : tableau de bord, listes des paiements, reversements,
  remboursements et contestations, onglet Stancer sur la fiche de chaque tiers
- **Synchronisation** avec Stancer, à la demande ou par tâche planifiée
- **Courriels** de confirmation, d'erreur et de relance après un paiement refusé
- **Comptabilité** : frais Stancer enregistrés sur le compte bancaire,
  reversements rapprochés, écarts comptables détectés
- **Associations** : paiement en ligne des cotisations et des dons
- **Multi-société**, mode test et mode production

Vous trouverez nos autres modules sur [Dolistore.com](https://www.dolistore.com/index.php?controller=search&orderby=position&orderway=desc&tag=&website=marketplace&search_query=cap-rel&submit_search=).

## Prérequis

- Dolibarr 15.0 ou supérieur
- PHP 7.4 ou supérieur
- Les modules Banques et caisses et Prélèvements activés
- Un compte Stancer et ses clés API

## Documentation

Documentation utilisateur : [doc.cap-rel.fr/stancer](https://doc.cap-rel.fr/stancer/)

<!--

## Installation

### Depuis le fichier ZIP et l'interface

Allez dans le menu ```Accueil - Configuration - Modules - Déployer un module externe``` et téléversez le fichier zip.

### Étapes finales

Depuis votre navigateur :

  - Connectez-vous à Dolibarr en tant que super-administrateur
  - Allez dans "Configuration" -> "Modules"
  - Vous devriez maintenant pouvoir trouver et activer le module

-->

## SAV / Aide / Support

Toute demande d'aide / support / sav doit passer par la procédure suivante :

[https://cap-rel.fr/sav-module-dolibarr/](https://cap-rel.fr/sav-module-dolibarr/)

## Licence

### Code principal

Le code est couvert par la licence GNU/GPLv3 ou toute version ultérieure.

Voir le fichier COPYING pour plus d'informations.

### Documentation

Toute la documentation est sous licence GNU/GFDL.
