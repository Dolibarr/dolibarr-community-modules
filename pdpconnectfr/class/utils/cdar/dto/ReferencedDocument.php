<?php
/*require_once '../enum/StatusCode.php';
require_once '../enum/DocumentTypeCode.php';
require_once '../enum/ProcessConditionCode.php';
require_once 'TradeParty.php';*/

dol_include_once('/pdpconnectfr/class/utils/cdar/enum/StatusCode.php');
dol_include_once('/pdpconnectfr/class/utils/cdar/enum/DocumentTypeCode.php');
dol_include_once('/pdpconnectfr/class/utils/cdar/enum/ProcessConditionCode.php');
dol_include_once('/pdpconnectfr/class/utils/cdar/dto/TradeParty.php');

class ReferencedDocument
{
    public function __construct(
        public string $IssuerAssignedID,
        public StatusCode $StatusCode,
        public DocumentTypeCode $TypeCode,
        public string $FormattedIssueDateTime,
        public ProcessConditionCode $ProcessConditionCode,
        public string $ProcessCondition,
        public TradeParty $IssuerTradeParty,
        public ?string $StatusReasonCode = null,
        public ?string $StatusReason = null,
        public ?string $StatusSequenceNumeric = null,
        public ?string $StatusIncludedNoteContent = null
    ) {}
}