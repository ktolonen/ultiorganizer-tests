<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class CommonFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadCommonFunctions();
    }

    public function testSafeDivideReturnsZeroForZeroDivisor(): void
    {
        $this->assertSame(0, SafeDivide(10, 0));
    }

    public function testConvertToUtf8HandlesNullAndUtf8Input(): void
    {
        $this->assertSame('', convertToUtf8(null));
        $this->assertSame('Mäki', convertToUtf8('Mäki'));
    }

    public function testNormalizeTextInputDecodesAndTrims(): void
    {
        $this->assertSame('Mäki', normalizeTextInput('  M%C3%A4ki  '));
    }

    public function testStripFromQueryStringRemovesSingleParameter(): void
    {
        $query = StripFromQueryString('?view=games&season=HRN2026&group=all', 'season');

        $this->assertStringContainsString('view=games', $query);
        $this->assertStringNotContainsString('season=', $query);
    }

    public function testSecToMinFormatsSeconds(): void
    {
        $this->assertSame('2.05', SecToMin(125));
    }

    public function testDefTimeFormatUsesExpectedLegacyTimestampFormat(): void
    {
        $this->assertSame('1.6.2026 09:05', DefTimeFormat('2026-06-01 09:05:00'));
    }

    public function testSafeUrlAddsSchemeWhenMissing(): void
    {
        $this->assertSame('http://example.com', SafeUrl('example.com'));
        $this->assertSame('https://example.com', SafeUrl('https://example.com'));
    }

    public function testColorStringToRgbParsesHexColors(): void
    {
        $this->assertSame(['r' => 51, 'g' => 102, 'b' => 153], colorstring2rgb('#336699'));
    }

    public function testResolveViewPathFallsBackForTraversal(): void
    {
        $path = resolveViewPath('../admin/seasons', LegacyApp::sutRoot(), 'frontpage', ['index', 'install']);
        $this->assertSame(LegacyApp::sutRoot() . '/frontpage.php', $path);
    }

    public function testValidEmailRecognizesExpectedAddresses(): void
    {
        $this->assertTrue(validEmail('test@example.com'));
        $this->assertFalse(validEmail('invalid-address'));
    }
}
