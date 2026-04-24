<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class UrlFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('url.functions.php', 'database_only');
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    public function testGetUrlReturnsFixtureSeasonMenuLink(): void
    {
        $url = GetUrl('season', 'HRN2026', 'menulink');

        $this->assertSame('Harness Event Site', $url['name']);
        $this->assertSame('https://example.com/harness', $url['url']);
        $this->assertSame('0', (string) $url['ismedialink']);
    }

    public function testGetUrlListReturnsOnlyNonMediaLinksInStableOrder(): void
    {
        $urls = GetUrlList('season', 'HRN2026');

        $this->assertCount(2, $urls);
        $this->assertSame(['Harness Event Site', 'Harness Admin'], array_column($urls, 'name'));
        $this->assertSame(['menulink', 'menumail'], array_column($urls, 'type'));
    }
}
