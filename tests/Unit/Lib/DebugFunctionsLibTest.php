<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class DebugFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('debug.functions.php', 'bootstrap_only');
    }

    public function testDebugVarEmitsHtmlCommentWhenDebugEnabled(): void
    {
        global $DEBUG;

        $DEBUG = true;
        ob_start();
        debugVar(['fixture' => 'visible']);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('DEBUG INFO:', $output);
        $this->assertStringContainsString('fixture', $output);
        $this->assertStringContainsString('visible', $output);
    }

    public function testDebugMsgStaysSilentWhenDebugDisabled(): void
    {
        global $DEBUG;

        $DEBUG = false;
        ob_start();
        debugMsg('hidden');
        $output = (string) ob_get_clean();

        $this->assertSame('', $output);
    }
}
