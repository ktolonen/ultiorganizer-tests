<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class PdfInterfacesLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('pdf.interfaces.php', 'bootstrap_only');
    }

    // --- SchedulePdf interface ---

    public function testSchedulePdfInterfaceExists(): void
    {
        $this->assertTrue(interface_exists('SchedulePdf'));
    }

    public function testSchedulePdfDefinesPrintSchedule(): void
    {
        $ref = new ReflectionClass('SchedulePdf');
        $this->assertTrue($ref->hasMethod('PrintSchedule'));
        $this->assertTrue($ref->getMethod('PrintSchedule')->isAbstract());
    }

    public function testSchedulePdfDefinesPrintOnePageSchedule(): void
    {
        $ref = new ReflectionClass('SchedulePdf');
        $this->assertTrue($ref->hasMethod('PrintOnePageSchedule'));
        $this->assertTrue($ref->getMethod('PrintOnePageSchedule')->isAbstract());
    }

    public function testSchedulePdfIsAnInterface(): void
    {
        $ref = new ReflectionClass('SchedulePdf');
        $this->assertTrue($ref->isInterface());
    }

    // --- ScoreSheetPdf interface ---

    public function testScoreSheetPdfInterfaceExists(): void
    {
        $this->assertTrue(interface_exists('ScoreSheetPdf'));
    }

    public function testScoreSheetPdfDefinesPrintScoreSheet(): void
    {
        $ref = new ReflectionClass('ScoreSheetPdf');
        $this->assertTrue($ref->hasMethod('PrintScoreSheet'));
        $this->assertTrue($ref->getMethod('PrintScoreSheet')->isAbstract());
    }

    public function testScoreSheetPdfDefinesPrintPlayerList(): void
    {
        $ref = new ReflectionClass('ScoreSheetPdf');
        $this->assertTrue($ref->hasMethod('PrintPlayerList'));
    }

    public function testScoreSheetPdfDefinesPrintRoster(): void
    {
        $ref = new ReflectionClass('ScoreSheetPdf');
        $this->assertTrue($ref->hasMethod('PrintRoster'));
    }

    public function testScoreSheetPdfIsAnInterface(): void
    {
        $ref = new ReflectionClass('ScoreSheetPdf');
        $this->assertTrue($ref->isInterface());
    }
}
