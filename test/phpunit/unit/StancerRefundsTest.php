<?php

namespace Stancer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Stancer_refunds class
 */
class StancerRefundsTest extends TestCase
{
    private $db;
    private $refund;

    protected function setUp(): void
    {
        global $conf;
        $conf->entity = 1;

        $this->db = $this->createMock(\DoliDB::class);

        require_once __DIR__ . '/../../../class/stancer_refunds.class.php';

        $this->refund = new \Stancer_refunds($this->db);
    }

    // =========================================================================
    // Constructor tests
    // =========================================================================

    public function testConstructorSetsProperties(): void
    {
        $this->assertEquals('stancer', $this->refund->module);
        $this->assertEquals('stancer_refunds', $this->refund->element);
        $this->assertEquals('stancer_stancer_refunds', $this->refund->table_element);
        $this->assertSame($this->db, $this->refund->db);
    }

    // =========================================================================
    // Status constants tests
    // =========================================================================

    public function testStatusConstantsAreDefined(): void
    {
        $this->assertEquals(0, \Stancer_refunds::STATUS_DRAFT);
        $this->assertEquals(1, \Stancer_refunds::STATUS_VALIDATED);
        $this->assertEquals(9, \Stancer_refunds::STATUS_CANCELED);
    }

    // =========================================================================
    // Validation tests
    // =========================================================================

    public function testValidateReturnsZeroIfAlreadyValidated(): void
    {
        global $user;
        $this->refund->status = \Stancer_refunds::STATUS_VALIDATED;

        $result = $this->refund->validate($user);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // setDraft tests
    // =========================================================================

    public function testSetDraftReturnsZeroIfAlreadyDraft(): void
    {
        global $user;
        $this->refund->status = \Stancer_refunds::STATUS_DRAFT;

        $result = $this->refund->setDraft($user);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // cancel tests
    // =========================================================================

    public function testCancelReturnsZeroIfNotValidated(): void
    {
        global $user;
        $this->refund->status = \Stancer_refunds::STATUS_DRAFT;

        $result = $this->refund->cancel($user);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // reopen tests
    // =========================================================================

    public function testReopenReturnsZeroIfAlreadyValidated(): void
    {
        global $user;
        $this->refund->status = \Stancer_refunds::STATUS_VALIDATED;

        $result = $this->refund->reopen($user);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // deleteLine tests
    // =========================================================================

    public function testDeleteLineReturnsErrorIfStatusNegative(): void
    {
        global $user;
        $this->refund->status = -1;

        $result = $this->refund->deleteLine($user, 1);

        $this->assertEquals(-2, $result);
        $this->assertEquals('ErrorDeleteLineNotAllowedByObjectStatus', $this->refund->error);
    }

    // =========================================================================
    // setErrorsFromObject tests
    // =========================================================================

    public function testSetErrorsFromObjectCopiesError(): void
    {
        $sourceObject = new \stdClass();
        $sourceObject->error = 'Test error message';
        $sourceObject->errors = [];

        $this->refund->setErrorsFromObject($sourceObject);

        $this->assertEquals('Test error message', $this->refund->error);
    }

    public function testSetErrorsFromObjectMergesErrors(): void
    {
        $sourceObject = new \stdClass();
        $sourceObject->error = '';
        $sourceObject->errors = ['Error 1', 'Error 2'];

        $this->refund->errors = ['Existing error'];
        $this->refund->setErrorsFromObject($sourceObject);

        $this->assertContains('Existing error', $this->refund->errors);
        $this->assertContains('Error 1', $this->refund->errors);
        $this->assertContains('Error 2', $this->refund->errors);
    }

    // =========================================================================
    // LibStatut tests
    // =========================================================================

    public function testLibStatutReturnsCorrectLabelForDraft(): void
    {
        $result = $this->refund->LibStatut(\Stancer_refunds::STATUS_DRAFT);
        $this->assertEquals('Draft', $result);
    }

    public function testLibStatutReturnsCorrectLabelForValidated(): void
    {
        $result = $this->refund->LibStatut(\Stancer_refunds::STATUS_VALIDATED);
        $this->assertEquals('Enabled', $result);
    }

    public function testLibStatutReturnsCorrectLabelForCanceled(): void
    {
        $result = $this->refund->LibStatut(\Stancer_refunds::STATUS_CANCELED);
        $this->assertEquals('Disabled', $result);
    }

    // =========================================================================
    // getLabelStatus / getLibStatut tests
    // =========================================================================

    public function testGetLabelStatusUsesObjectStatus(): void
    {
        $this->refund->status = \Stancer_refunds::STATUS_DRAFT;
        $result = $this->refund->getLabelStatus();
        $this->assertEquals('Draft', $result);
    }

    public function testGetLibStatutUsesObjectStatus(): void
    {
        $this->refund->status = \Stancer_refunds::STATUS_VALIDATED;
        $result = $this->refund->getLibStatut();
        $this->assertEquals('Enabled', $result);
    }

    // =========================================================================
    // initAsSpecimen() tests
    // =========================================================================

    public function testInitAsSpecimenCallsWithoutError(): void
    {
        $this->refund->initAsSpecimen();
        $this->assertTrue(true);
    }

    // =========================================================================
    // getNomUrl() tests (Cat 5 - standard Dolibarr methods)
    // =========================================================================

    public function testGetNomUrlReturnsLinkWithRef(): void
    {
        $this->refund->id = 5;
        $this->refund->ref = 'RFND-001';
        $this->refund->status = \Stancer_refunds::STATUS_VALIDATED;

        $result = $this->refund->getNomUrl();

        $this->assertStringContainsString('RFND-001', $result);
        $this->assertStringContainsString('href=', $result);
        $this->assertStringContainsString('stancer_refunds_card.php', $result);
        $this->assertStringContainsString('id=5', $result);
    }

    public function testGetNomUrlWithNoLinkReturnsSpan(): void
    {
        $this->refund->id = 5;
        $this->refund->ref = 'RFND-001';

        $result = $this->refund->getNomUrl(0, 'nolink');

        $this->assertStringContainsString('RFND-001', $result);
        $this->assertStringContainsString('<span', $result);
        $this->assertStringNotContainsString('<a ', $result);
    }

    public function testGetNomUrlWithPictoIncludesImg(): void
    {
        $this->refund->id = 5;
        $this->refund->ref = 'RFND-001';
        $this->refund->status = \Stancer_refunds::STATUS_DRAFT;

        $result = $this->refund->getNomUrl(1);

        $this->assertStringContainsString('RFND-001', $result);
        $this->assertStringContainsString('<img', $result);
    }

    // =========================================================================
    // info() tests (Cat 5 - standard Dolibarr methods)
    // =========================================================================

    public function testInfoCallsDbQuery(): void
    {
        $db = $this->createMock(\DoliDB::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('stancer_stancer_refunds'))
            ->willReturn(true);
        $db->expects($this->once())
            ->method('num_rows')
            ->willReturn(0);
        $db->expects($this->once())
            ->method('free');

        $refund = new \Stancer_refunds($db);
        $refund->info(5);
    }

    // =========================================================================
    // getLinesArray() tests (Cat 5 - standard Dolibarr methods)
    // =========================================================================

    public function testGetLinesArrayReturnsEmptyArray(): void
    {
        $this->refund->id = 1;
        $result = $this->refund->getLinesArray();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // generateDocument() tests (Cat 5 - standard Dolibarr methods)
    // =========================================================================

    public function testGenerateDocumentReturnsZeroWhenDocgenDisabled(): void
    {
        global $langs;
        $result = $this->refund->generateDocument('', $langs);

        $this->assertEquals(0, $result);
    }
}
