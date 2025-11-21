<?php
enum SchemeID: string
{
    case SIREN_0225 = '0225'; // SchemeID for PDP
    case SIREN_0002 = '0002'; // siren

    public function getLabel(): string
    {
        return match($this) {
            self::SIREN_0225, self::SIREN_0002 => 'SIREN'
        };
    }
}