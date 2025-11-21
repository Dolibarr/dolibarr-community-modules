<?php

enum DocumentTypeCode: string
{
    case INVOICE = '380';           // Commercial invoice
    case CREDIT_NOTE = '381';       // Credit note
    case CORRECTIVE_INVOICE = '384'; // Corrective invoice
    case DEBIT_NOTE = '383';        // Debit note
    case PREPAYMENT_INVOICE = '386'; // Prepayment invoice

    public function getLabel(): string
    {
        return match($this) {
            self::INVOICE => 'Commercial invoice',
            self::CREDIT_NOTE => 'Credit note',
            self::CORRECTIVE_INVOICE => 'Corrective invoice',
            self::DEBIT_NOTE => 'Debit note',
            self::PREPAYMENT_INVOICE => 'Prepayment invoice',
        };
    }
}