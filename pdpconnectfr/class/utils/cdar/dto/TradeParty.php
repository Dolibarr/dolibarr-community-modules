<?php
dol_include_once('/pdpconnectfr/class/utils/cdar/enum/SchemeID.php');
dol_include_once('/pdpconnectfr/class/utils/cdar/enum/RoleCode.php');

class TradeParty
{
    public function __construct(
        public ?string $GlobalID = null,
        public ?SchemeID $SchemeID = null,
        public ?RoleCode $RoleCode = null,
        public ?string $URIID = null,
        public ?SchemeID $URISchemeID = null
    ) {}
}