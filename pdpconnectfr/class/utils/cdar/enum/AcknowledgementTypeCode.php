<?php
enum AcknowledgementTypeCode: string
{
    case ACKNOWLEDGEMENT = '305';    // Acknowledgement of receipt
    case REJECTION = '304';          // Rejection
    case ACCEPTANCE = '302';         // Acceptance

    public function getLabel(): string
    {
        return match($this) {
            self::ACKNOWLEDGEMENT => 'Acknowledgement of receipt',
            self::REJECTION => 'Rejection',
            self::ACCEPTANCE => 'Acceptance',
        };
    }
}