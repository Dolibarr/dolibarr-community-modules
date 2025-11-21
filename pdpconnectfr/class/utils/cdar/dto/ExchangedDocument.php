<?php
//require_once 'TradeParty.php';
dol_include_once('/pdpconnectfr/class/utils/cdar/dto/TradeParty.php');

class ExchangedDocument
{
    public function __construct(
        public string $ID,
        public string $Name,
        public string $IssueDateTime,
        public TradeParty $SenderTradeParty,
        public TradeParty $IssuerTradeParty,
        public TradeParty $RecipientTradeParty
    ) {}
}