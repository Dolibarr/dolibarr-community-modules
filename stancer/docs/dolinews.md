---
title: "Stancer 2.0.24 : relancer un paiement par carte refusé, et un module plus sûr"
summary: "Le lien de paiement s'envoie depuis une commande ou une facture et permet de payer à nouveau après un refus. La version apporte aussi une série de correctifs de sécurité : contrôles d'accès, pages publiques, journaux et multi-société."
type: release
version: "2.0.24"
focus: security
maturity: stable
compat_status: declared
locale: fr_FR
---

## Encaisser malgré un premier refus

Le module Stancer encaisse vos clients par carte bancaire et par prélèvement SEPA directement depuis Dolibarr. Cette version s'attaque au cas qui coûte le plus cher : le paiement par carte refusé.

- Un bouton **Envoyer le lien de paiement** sur les commandes et les factures envoie au client le lien de paiement en ligne, à son adresse ou à celle de l'un de ses contacts.
- Ce lien reste valable après un refus : il démarre une nouvelle tentative, et la page de retour explique au client ce qui s'est passé.
- Pour une carte qui ne parvient pas à s'authentifier, le paiement sans 3-D Secure peut être autorisé sur un document précis, avec traçabilité de l'autorisation.

## Une mise à jour de sécurité

Une revue complète du module a conduit à durcir de nombreux points :

- droit d'écriture et jeton de sécurité exigés pour toute action qui débite, rembourse ou enregistre un paiement ;
- montant des factures, commandes et devis toujours calculé par Dolibarr, jamais repris du lien ;
- pages publiques de carte et d'IBAN protégées par la clé de sécurité des paiements en ligne ;
- aucune donnée de carte ni clé de sécurité dans les journaux, certificat de l'API Stancer vérifié ;
- paiements, remboursements et contestations cloisonnés par société.

Plusieurs corrections l'accompagnent, dont l'envoi des emails de validation de facture différés, qui ne partaient plus.

## Essayez-le

Essayez Stancer sur un Dolibarr installé en moins de 20 secondes. Pour aller
jusqu'au paiement, saisissez vos clés de test Stancer dans la configuration du
module : [dolitest.fr/stancer](https://dolitest.fr/stancer/)

## Mise à jour

Après avoir remplacé les fichiers, désactivez puis réactivez le module pour appliquer les nouvelles contraintes de la base de données.

Documentation : [doc.cap-rel.fr/stancer](https://doc.cap-rel.fr/stancer/)
