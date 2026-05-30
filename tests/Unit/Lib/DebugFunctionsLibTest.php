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

    public function testDebugVarEmitsVarDumpForNonArrayWhenDebugEnabled(): void
    {
        global $DEBUG;

        $DEBUG = true;
        ob_start();
        debugVar('scalar_value');
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('DEBUG INFO:', $output);
        $this->assertStringContainsString('scalar_value', $output);
    }

    public function testDebugFuncEmitsArgsWhenDebugEnabled(): void
    {
        global $DEBUG;

        $DEBUG = true;
        ob_start();
        debugFunc('arg1', 42, ['k' => 'v']);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('DEBUG INFO:', $output);
        $this->assertStringContainsString('arg1', $output);
    }

    public function testDebugFuncStaysSilentWhenDebugDisabled(): void
    {
        global $DEBUG;

        $DEBUG = false;
        ob_start();
        debugFunc('hidden_arg');
        $output = (string) ob_get_clean();

        $this->assertSame('', $output);
    }

    public function testDebugMsgEmitsMessageWhenDebugEnabled(): void
    {
        global $DEBUG;

        $DEBUG = true;
        ob_start();
        debugMsg('hello debug');
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('DEBUG INFO:', $output);
        $this->assertStringContainsString('hello debug', $output);
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
