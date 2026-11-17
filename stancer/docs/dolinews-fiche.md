---
project: stancer
name: "Stancer"
summary: "Encaissez par carte bancaire et prélèvement SEPA depuis vos factures, commandes et devis Dolibarr, avec Stancer, la plateforme de paiement française à bas coût."
locale: fr_FR
license: GPL-3.0-or-later
link_doc: https://doc.cap-rel.fr/stancer/
link_demo: https://dolitry.fr/stancer/
link_dolistore: https://www.dolistore.com/product.php?id=623&l=fr
link_support: https://cap-rel.fr/sav-module-dolibarr/
gallery: users/screenshots/tableau-de-bord.webp | Le tableau de bord Stancer avec les indicateurs de paiement du mois en cours
gallery: users/screenshots/bouton-paiement-facture.webp | Le bouton de paiement Stancer sur une facture validée
gallery: users/screenshots/configuration-generalites.webp | La configuration générale : clés API, comptes bancaires et synchronisation
gallery: users/screenshots/configuration-sepa.webp | La configuration du mandat SEPA
gallery: users/screenshots/configuration-sepa-frais-rejet.webp | Les frais de rejet SEPA, refacturés automatiquement si vous le souhaitez
gallery: users/screenshots/taches-planifiees.webp | Les tâches planifiées Stancer
---

## Présentation

Stancer est la plateforme de paiement française à bas coût. Le module la relie
à Dolibarr : vos clients paient par carte bancaire ou par prélèvement SEPA
depuis leurs factures, commandes et devis, et Dolibarr enregistre le paiement,
classe la facture et passe les écritures bancaires sans ressaisie.

Le module s'adresse aux entreprises et aux associations qui facturent dans
Dolibarr et veulent être payées en ligne. Il est gratuit et sous licence libre ;
seul le compte Stancer est à ouvrir, sur
[stancer.com](https://www.stancer.com/fr/paiement-en-ligne?mtm_campaign=Dolibarr&mtm_source=Dolibarr).

## Fonctionnalités

- **Paiement par carte** : lien de paiement envoyé par courriel depuis une
  facture, une commande ou un devis, page sécurisée 3-D Secure, carte
  enregistrée pour les paiements suivants, nouvelle tentative après un refus
- **Devis et commandes** : création automatique de la facture, paiement partiel
  et facture d'acompte
- **Prélèvement SEPA** : page publique de saisie d'IBAN, mandat généré en PDF,
  vérification de l'IBAN via SEPAMail, prélèvement automatique des factures
  échues après le délai d'information du client
- **Signature électronique du mandat** avec le module UptoSign ; une fois le
  mandat signé, les factures modèles du client passent en prélèvement
- **Rejets SEPA** : facture rouverte, client et administrateur prévenus, frais
  de rejet refacturés si vous le souhaitez
- **Suivi** : tableau de bord, paiements, reversements, remboursements et
  contestations synchronisés avec Stancer, onglet Stancer sur chaque tiers
- **Automatisation** : tâches planifiées et courriels prêts à l'emploi pour
  chaque situation
- **Comptabilité** : frais enregistrés sur le compte bancaire, reversements
  passés en virements vers votre banque principale, écarts détectés
- **Associations** : paiement en ligne des cotisations et des dons
- **Six langues** : français, anglais, allemand, espagnol, italien et
  néerlandais

## Essayer et se documenter

- Démonstration en ligne, sur un Dolibarr installé en moins de 20 secondes ;
  saisissez vos clés de test Stancer pour aller jusqu'au paiement :
  [dolitry.fr/stancer](https://dolitry.fr/stancer/)
- Documentation utilisateur : [doc.cap-rel.fr/stancer](https://doc.cap-rel.fr/stancer/)
