<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class LocaleFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('locale.functions.php', 'bootstrap_only');
    }

    public function testAddFirstMeaningfulAssertion(): void
    {
        $this->markTestIncomplete('TODO: add the first focused assertion for lib/locale.functions.php.');
    }
}
