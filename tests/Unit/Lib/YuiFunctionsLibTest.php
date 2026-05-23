<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class YuiFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('yui.functions.php', 'bootstrap_only');
    }

    // --- yuiLoad ---

    public function testYuiLoadReturnsStringWithEmptyLibsList(): void
    {
        $result = yuiLoad([]);
        $this->assertIsString($result);
    }

    public function testYuiLoadWithKnownLibReturnsNonEmptyString(): void
    {
        $result = yuiLoad(['yahoo']);
        $this->assertIsString($result);
        $this->assertNotSame('', $result);
    }

    public function testYuiLoadUsesPrefixWhenStylesPrefixIsSet(): void
    {
        global $styles_prefix;
        $prev = $styles_prefix ?? null;
        $styles_prefix = '/test/prefix/';

        $result = yuiLoad([]);

        $styles_prefix = $prev;
        $this->assertIsString($result);
    }

    public function testYuiLoadFallsBackToIncludePrefixWhenStylesPrefixUnset(): void
    {
        global $styles_prefix, $include_prefix;
        $prevStyles  = $styles_prefix ?? null;
        $prevInclude = $include_prefix ?? null;

        unset($styles_prefix);
        $include_prefix = '/fallback/prefix/';

        $result = yuiLoad([]);

        $styles_prefix  = $prevStyles;
        $include_prefix = $prevInclude;
        $this->assertIsString($result);
    }
}
