---
title: "Stancer 2.0.28 : les commandes et devis payés en ligne sont facturés une fois, et jamais oubliés"
summary: "Un paiement en ligne sur une commande ou un devis crée sa facture et son règlement d'un seul bloc : plus de doublon après un rechargement, plus de facture sans règlement après une erreur. Si le client ne revient pas sur le site, la tâche planifiée rattrape le paiement. Nouvelle option : facturer en acompte le montant exact encaissé."
type: release
version: "2.0.28"
focus: bugfix_major
maturity: stable
compat_status: declared
locale: fr_FR
---

## Un paiement, une facture

Le module Stancer encaisse vos clients par carte bancaire et par prélèvement SEPA directement depuis Dolibarr. Quand un client paie une commande ou un devis en ligne puis revient sur votre site, le module crée la facture, la valide et enregistre le règlement. Ce retour était fragile :

- recharger la page, ou y revenir tard, pouvait facturer la commande une seconde fois ;
- une erreur pendant l'enregistrement du règlement laissait une facture validée sans son règlement ;
- un client qui fermait son navigateur juste après avoir payé laissait un paiement encaissé chez Stancer, sans rien dans Dolibarr.

Avec cette version, la facture, sa validation, le règlement et l'écriture bancaire sont enregistrés d'un seul bloc : si une étape échoue, rien n'est conservé. Un paiement déjà enregistré n'est jamais repris, et une facture impayée qui existe déjà pour la commande reçoit le paiement au lieu d'en créer une nouvelle.

Le client qui ne revient pas sur le site n'est plus perdu : la tâche planifiée StancerCheckPay crée la facture et enregistre le règlement à son passage suivant. Cette tâche est installée désactivée, et la page de configuration du module vous prévient tant qu'elle ne tourne pas.

## Un acompte du montant exact encaissé

Une nouvelle option facture en acompte le montant réellement encaissé quand le client paie moins que le total d'une commande ou d'un devis. La facture d'acompte reprend les taux de TVA du document au prorata, au centime près, sans exiger de pourcentage d'acompte ni de conditions de règlement d'acompte.

## Des tâches planifiées qui ne se taisent plus

- Quand le prélèvement SEPA ou le paiement par carte est désactivé alors que des factures l'attendent, la tâche de prélèvement se termine en erreur et dit pourquoi, au lieu de ne rien prélever en silence. L'onglet SEPA de la configuration affiche aussi un avertissement.
- Une facture envoyée par email à sa validation part avec son numéro définitif et son PDF, jamais en brouillon.

## Essayez-le

Essayez Stancer sur un Dolibarr installé en moins de 20 secondes. Pour aller
jusqu'au paiement, saisissez vos clés de test Stancer dans la configuration du
module : [dolitry.fr/stancer](https://dolitry.fr/stancer/)

## Mise à jour

Remplacez les fichiers du module. Vérifiez ensuite que la tâche planifiée StancerCheckPay est active : c'est elle qui rattrape les paiements des clients qui ne reviennent pas sur le site.

Documentation : [doc.cap-rel.fr/stancer](https://doc.cap-rel.fr/stancer/)
