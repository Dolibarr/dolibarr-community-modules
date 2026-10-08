---
title: "Nouveautés de la version 2.0.24"
weight: 300
description: "Lien de paiement envoyé depuis une commande ou une facture, carte sans 3-D Secure, nouvel essai après un refus, et une série de durcissements de sécurité."
---

# Nouveautés de la version 2.0.24

Cette version facilite l'encaissement par carte quand un premier paiement échoue, et renforce nettement la sécurité du module : contrôles d'accès, pages publiques, journaux et cloisonnement entre sociétés. Elle corrige aussi plusieurs défauts de synchronisation et de comptabilité.

## Envoyer le lien de paiement depuis une commande ou une facture

Un bouton **Envoyer le lien de paiement** apparaît sur les commandes et les factures validées. Il envoie au client le lien de la page de paiement en ligne, à l'adresse email du tiers ou, à défaut, à celle de l'un de ses contacts. Détails : [Paiements par carte bancaire](/stancer/paiements-cb).

## Payer à nouveau après un refus

Un paiement par carte refusé ne bloque plus le client : le même lien démarre une nouvelle tentative. La page de retour lui explique désormais ce qui s'est passé, et lui confirme que rien n'a été débité quand c'est le cas.

## Paiement par carte sans 3-D Secure, document par document

Pour un client dont la carte ne parvient pas à s'authentifier, vous pouvez autoriser le paiement sans 3-D Secure sur une commande ou une facture précise. L'autorisation est enregistrée dans les événements du document. Sans 3-D Secure, une contestation frauduleuse reste à votre charge. Détails : [Paiements par carte bancaire](/stancer/paiements-cb).

## Sécurité

- Les actions qui débitent, remboursent ou enregistrent un paiement exigent le droit d'écriture du module et un jeton de sécurité.
- Le montant d'une facture, d'une commande ou d'un devis est toujours calculé par Dolibarr, jamais repris du lien de paiement.
- Une facture n'est classée payée que si ses règlements couvrent réellement son total.
- Les pages publiques de carte et d'IBAN exigent la clé de sécurité des paiements en ligne, et n'agissent jamais au nom du premier administrateur.
- Numéros de carte, cryptogrammes et clé de sécurité n'apparaissent plus dans les journaux, et le certificat de l'API Stancer est vérifié.
- Les paiements, remboursements, contestations et prélèvements de chaque société restent séparés en multi-société.

## Corrections de bugs

- Les emails de validation de facture différés partent de nouveau : la tâche planifiée échouait à chaque passage.
- Un paiement SEPA forcé depuis l'outil de réparation est enregistré comme un prélèvement, et non comme un paiement par carte.
- Les paiements de dons et d'inscriptions aux événements réglés avec Stancer sont portés sur le compte bancaire Stancer.
- Un même reversement, remboursement ou litige ne peut plus être enregistré deux fois.
- Un utilisateur ayant créé des enregistrements Stancer peut être supprimé.
- La fenêtre de réponse brute de l'API Stancer fonctionne dans le module installé depuis l'archive.
- Les messages de lancement des paiements sont traduits.

## Mise à jour

1. Remplacez les fichiers du module par ceux de la nouvelle archive.
2. Dans **Accueil > Configuration > Modules**, désactivez puis réactivez le module Stancer : cette étape applique les nouvelles contraintes de la base de données et termine la mise à jour.
3. Vérifiez que l'**utilisateur pour les actions automatisées** est bien renseigné dans la [configuration](/stancer/configuration) : les pages publiques refusent désormais d'agir sans lui ou sans l'auteur du tiers concerné.
4. Sur l'onglet Stancer d'un tiers, la vérification des mandats SEPA se lance par le bouton **Synchroniser les mandats SEPA avec Stancer**, et non plus à l'affichage.
