<?php

enum ProcessConditionCode: string
{
    case DEPOSITED = '200';              // Déposée - Invoice deposited and validated by issuing PDP
    case ISSUED = '201';                 // Émise par la plateforme - Transmitted to recipient's platform
    case RECEIVED = '202';               // Reçue par la plateforme - Received by recipient's platform
    case AVAILABLE = '203';              // Mise à disposition - Made available to recipient
    case TAKEN_OVER = '204';             // Prise en charge - Acknowledged by recipient
    case APPROVED = '205';               // Approuvée - Fully approved by buyer
    case PARTIALLY_APPROVED = '206';     // Approuvée partiellement - Partially approved
    case DISPUTED = '207';               // En litige - Dispute blocking payment
    case SUSPENDED = '208';              // Suspendue - Temporarily suspended, awaiting documents
    case COMPLETED = '209';              // Complétée - Additional documents provided by supplier
    case REFUSED = '210';                // Refusée - Definitively refused by buyer
    case PAYMENT_TRANSMITTED = '211';    // Paiement transmis - Payment sent by buyer
    case PAID = '212';                   // Encaissée - Fully paid (payment received by supplier)
    case REJECTED = '213';               // Rejetée - Rejected for technical/functional error

    public function getLabel(): string
    {
        return match($this) {
            self::DEPOSITED => 'Déposée',
            self::ISSUED => 'Émise par la plateforme',
            self::RECEIVED => 'Reçue par la plateforme',
            self::AVAILABLE => 'Mise à disposition',
            self::TAKEN_OVER => 'Prise en charge',
            self::APPROVED => 'Approuvée',
            self::PARTIALLY_APPROVED => 'Approuvée partiellement',
            self::DISPUTED => 'En litige',
            self::SUSPENDED => 'Suspendue',
            self::COMPLETED => 'Complétée',
            self::REFUSED => 'Refusée',
            self::PAYMENT_TRANSMITTED => 'Paiement transmis',
            self::PAID => 'Encaissée',
            self::REJECTED => 'Rejetée',
        };
    }
}