<?php
/*require_once '../enum/AcknowledgementTypeCode.php';
require_once 'ReferencedDocument.php';*/
dol_include_once('/pdpconnectfr/class/utils/cdar/enum/AcknowledgementTypeCode.php');
dol_include_once('/pdpconnectfr/class/utils/cdar/dto/AcknowledgementTypeCode.php');


class AcknowledgementDocument
{
    public function __construct(
        public bool $MultipleReferencesIndicator,
        public AcknowledgementTypeCode $TypeCode,
        public string $IssueDateTime,
        public ReferencedDocument $ReferenceReferencedDocument
    ) {}
}