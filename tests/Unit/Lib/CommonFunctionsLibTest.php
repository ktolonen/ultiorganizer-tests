<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class CommonFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('common.functions.php', 'bootstrap_only');
    }

    public function testNormalizeTextInputDecodesAndTrimsUtf8Input(): void
    {
        $this->assertSame('Mäki', normalizeTextInput('  M%C3%A4ki  '));
    }

    public function testResolveViewPathFallsBackForTraversalInput(): void
    {
        $path = resolveViewPath('../admin/seasons', LegacyApp::sutRoot(), 'frontpage', ['index', 'install']);

        $this->assertSame(LegacyApp::sutRoot() . '/frontpage.php', $path);
    }
}
