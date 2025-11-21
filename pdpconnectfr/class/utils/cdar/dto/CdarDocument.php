<?php
/*require_once 'ExchangedDocument.php';
require_once 'AcknowledgementDocument.php';*/
dol_include_once('/pdpconnectfr/class/utils/cdar/dto/ExchangedDocument.php');
dol_include_once('/pdpconnectfr/class/utils/cdar/dto/AcknowledgementDocument.php');

class CdarDocument
{
    public function __construct(
        public string $GuidelineID,
        public ExchangedDocument $ExchangedDocument,
        public AcknowledgementDocument $AcknowledgementDocument
    ) {}
}