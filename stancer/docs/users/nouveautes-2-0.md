---
title: "Nouveautés de la version 2.0"
weight: 399
description: "Toutes les nouveautés et améliorations des versions 2.0 du module Stancer."
---

# Nouveautés de la version 2.0

## Version 2.0.28

Version publiée le 17 novembre 2026. Cette version fiabilise la facturation des commandes et des devis payés en ligne : un paiement donne une facture, une seule, même quand le client recharge la page ou ne revient jamais sur le site. Elle ajoute la facturation en acompte du montant exact encaissé, et les tâches planifiées signalent désormais ce qu'elles ne peuvent pas faire.

### Commandes et devis facturés une seule fois

Quand un client paie une commande ou un devis en ligne, la facture, sa validation, le règlement et l'écriture bancaire sont enregistrés d'un seul bloc. Si une étape échoue, rien n'est conservé : il ne reste plus de facture validée sans son règlement.

Recharger la page de retour, ou y revenir tard, ne facture plus une seconde fois : le module retrouve le paiement déjà enregistré. Si une facture impayée existe déjà pour le document, créée à la main par exemple, le paiement y est enregistré au lieu d'en créer une nouvelle. Détails : [Paiements par carte](/stancer/paiements-cb).

### Les paiements des clients qui ne reviennent pas sur le site

Un client qui ferme son navigateur juste après avoir payé, ou dont le retour de 3-D Secure est interrompu, laissait un paiement encaissé chez Stancer sans facture ni règlement dans Dolibarr. La tâche planifiée StancerCheckPay crée désormais la facture de la commande ou du devis et enregistre le règlement, exactement comme l'aurait fait la page de retour.

Cette tâche est installée désactivée. En mode production, la page de configuration du module affiche un avertissement tant qu'elle ne tourne pas. Détails : [Tâches automatiques](/stancer/taches-automatiques).

### Acompte du montant encaissé

L'option **Facturer en acompte le montant réellement encaissé sur une commande ou un devis**, dans l'onglet Carte bancaire de la configuration, crée une facture d'acompte du montant exact payé quand il est inférieur au total du document. La facture reprend les taux de TVA du document au prorata, au centime près, sans exiger de pourcentage d'acompte ni de conditions de règlement d'acompte. Dans tous les cas, le règlement enregistré est le montant réellement encaissé par Stancer. Détails : [Paiements par carte](/stancer/paiements-cb).

### Prélèvements désactivés : la tâche le signale

Quand le prélèvement SEPA ou le paiement par carte est désactivé dans la configuration alors que des factures attendent ce mode de paiement, la tâche de prélèvement se termine en erreur avec la raison dans sa sortie, au lieu de ne rien prélever en silence. Chaque passage indique aussi le nombre de factures examinées. L'onglet SEPA de la configuration affiche un avertissement quand le prélèvement est désactivé alors que des mandats SEPA Stancer existent.

### Corrections de bugs

- Une facture envoyée par email à sa validation part avec son numéro définitif et son PDF, jamais en brouillon : avec un délai d'envoi à 0, c'est la tâche planifiée horaire qui l'envoie.
- Sur un devis payé en acompte, le règlement enregistré est le montant encaissé, et non plus le pourcentage d'acompte recalculé sur le total du devis.
- La tâche planifiée pouvait enregistrer le paiement d'une commande qui n'avait pas encore de facture sur une autre facture : elle crée désormais la facture de la commande.

### Mise à jour

Remplacez les fichiers du module. Vérifiez ensuite, dans la liste des travaux planifiés, que la tâche StancerCheckPay est active.

## Version 2.0.26

Version publiée le 3 novembre 2026. Cette version fait passer vos clients au prélèvement dès qu'ils signent leur mandat SEPA, et rend le suivi des paiements plus lisible depuis les factures. Elle permet aussi d'encaisser les clients qui n'ont laissé qu'un numéro de mobile, et corrige plusieurs défauts autour des prélèvements rejetés.

### Factures modèles en prélèvement dès la signature du mandat

Quand un client signe son mandat SEPA, ses factures modèles peuvent passer automatiquement en prélèvement sur le compte bancaire Stancer, y compris celles qu'il réglait par carte. Le mandat signé devient son mandat par défaut. Le mode de paiement du tiers ne change pas : une facture ponctuelle garde le mode que vous choisissez.

Le passage se fait à la signature électronique par UptoSign, ou avec le bouton **Mandat signé** de l'onglet Stancer du tiers pour un mandat signé sur papier. L'option est désactivée par défaut. Détails : [Prélèvements SEPA](/stancer/sepa).

### Les paiements Stancer dans les événements des factures

Chaque paiement Stancer encaissé, refusé, échoué ou contesté apparaît une seule fois dans les événements de la facture, comme les prélèvements Stripe. Les autres modules peuvent s'appuyer sur l'événement de refus (`AC_PAYMENT_STANCER_KO`) pour espacer les relances ou suspendre un service. Détails : [Suivi des paiements](/stancer/suivi-paiements).

Un paiement par carte refusé déclenche désormais l'email d'échec au client et l'événement de refus, comme les autres échecs.

### Moins d'emails répétés

L'administrateur n'est plus prévenu à chaque synchronisation quotidienne d'un même paiement en échec : il reçoit un seul email par paiement.

### Clients joignables sur leur seul mobile

Un client qui n'a laissé qu'un numéro de mobile, sans adresse email, peut payer un devis, une commande ou une facture avec Stancer. Les numéros nationaux et ceux des adhérents sont reconnus. Les numéros des départements d'outre-mer sont envoyés à Stancer avec leur propre indicatif, même quand le pays du tiers est la France.

### Documents joints par d'autres modules

L'email d'envoi d'une facture joint aussi les fichiers fournis par d'autres modules, par exemple le relevé de consommation d'une facture de revendeur.

### Corrections de bugs

- Une facture payée à nouveau après un prélèvement SEPA rejeté reste classée payée, au lieu de repasser à l'état "commencée" avec un reste à payer nul.
- Le résultat de la tâche planifiée liste les factures entièrement réglées qui n'ont pas pu être classées payées.
- Le module refuse de s'activer si l'une de ses tables n'a pas pu être créée, au lieu de s'activer sans elle.

### Mise à jour

Remplacez les fichiers du module : aucune autre étape n'est nécessaire. Pour faire passer les factures modèles en prélèvement à la signature des mandats, activez l'option dans la configuration SEPA du module.

## Version 2.0.24

Version publiée le 24 septembre 2026. Cette version facilite l'encaissement par carte quand un premier paiement échoue, et renforce nettement la sécurité du module : contrôles d'accès, pages publiques, journaux et cloisonnement entre sociétés. Elle corrige aussi plusieurs défauts de synchronisation et de comptabilité.

### Envoyer le lien de paiement depuis une commande ou une facture

Un bouton **Envoyer le lien de paiement** apparaît sur les commandes et les factures validées. Il envoie au client le lien de la page de paiement en ligne, à l'adresse email du tiers ou, à défaut, à celle de l'un de ses contacts. Détails : [Paiements par carte bancaire](/stancer/paiements-cb).

### Payer à nouveau après un refus

Un paiement par carte refusé ne bloque plus le client : le même lien démarre une nouvelle tentative. La page de retour lui explique désormais ce qui s'est passé, et lui confirme que rien n'a été débité quand c'est le cas.

### Paiement par carte sans 3-D Secure, document par document

Pour un client dont la carte ne parvient pas à s'authentifier, vous pouvez autoriser le paiement sans 3-D Secure sur une commande ou une facture précise. L'autorisation est enregistrée dans les événements du document. Sans 3-D Secure, une contestation frauduleuse reste à votre charge. Détails : [Paiements par carte bancaire](/stancer/paiements-cb).

### Sécurité

- Les actions qui débitent, remboursent ou enregistrent un paiement exigent le droit d'écriture du module et un jeton de sécurité.
- Le montant d'une facture, d'une commande ou d'un devis est toujours calculé par Dolibarr, jamais repris du lien de paiement.
- Une facture n'est classée payée que si ses règlements couvrent réellement son total.
- Les pages publiques de carte et d'IBAN exigent la clé de sécurité des paiements en ligne, et n'agissent jamais au nom du premier administrateur.
- Numéros de carte, cryptogrammes et clé de sécurité n'apparaissent plus dans les journaux, et le certificat de l'API Stancer est vérifié.
- Les paiements, remboursements, contestations et prélèvements de chaque société restent séparés en multi-société.

### Corrections de bugs

- Les emails de validation de facture différés partent de nouveau : la tâche planifiée échouait à chaque passage.
- Un paiement SEPA forcé depuis l'outil de réparation est enregistré comme un prélèvement, et non comme un paiement par carte.
- Les paiements de dons et d'inscriptions aux événements réglés avec Stancer sont portés sur le compte bancaire Stancer.
- Un même reversement, remboursement ou litige ne peut plus être enregistré deux fois.
- Un utilisateur ayant créé des enregistrements Stancer peut être supprimé.
- La fenêtre de réponse brute de l'API Stancer fonctionne dans le module installé depuis l'archive.
- Les messages de lancement des paiements sont traduits.

### Mise à jour

1. Remplacez les fichiers du module par ceux de la nouvelle archive.
2. Dans **Accueil > Configuration > Modules**, désactivez puis réactivez le module Stancer : cette étape applique les nouvelles contraintes de la base de données et termine la mise à jour.
3. Vérifiez que l'**utilisateur pour les actions automatisées** est bien renseigné dans la [configuration](/stancer/configuration) : les pages publiques refusent désormais d'agir sans lui ou sans l'auteur du tiers concerné.
4. Sur l'onglet Stancer d'un tiers, la vérification des mandats SEPA se lance par le bouton **Synchroniser les mandats SEPA avec Stancer**, et non plus à l'affichage.
