<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class TranslationFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        if (!defined('WORD_DELIMITER')) {
            define('WORD_DELIMITER', '/([\;\,\-_\s\/\.])/');
        }
        LegacyApp::loadLibFileUsingProfile('translation.functions.php', 'bootstrap_only');
    }

    public function testTranslateUsesExactKeyMatchWhenAvailable(): void
    {
        $translated = translate('Hello', ['hello' => 'Hei']);

        $this->assertSame(['Hello' => 'Hei'], $translated);
    }

    public function testTranslateFallsBackPerTokenAndPreservesNumbers(): void
    {
        $translated = translate('Final-2026 Match', [
            'final' => 'Loppuottelu',
            'match' => 'Ottelu',
        ]);

        $this->assertSame(['Final-2026 Match' => 'Loppuottelu-2026 Ottelu'], $translated);
    }

    public function testTranslateReturnsEmptyStringForEmptyInput(): void
    {
        $this->assertSame(['' => ''], translate('', []));
        $this->assertSame(['' => ''], translate(null, []));
    }
}
