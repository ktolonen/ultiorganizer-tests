<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class ConfigurationFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadConfigurationFunctions();
        LegacyApp::loginAsAdmin();
    }

    protected function tearDown(): void
    {
        SetServerConfValue('ShowDefenseStats', self::expectedShowDefenseStats());
        LegacyApp::closeDatabaseConnection();
    }

    public function testSetServerConfValueUpdatesAServerSetting(): void
    {
        SetServerConfValue('ShowDefenseStats', 'true');

        $this->assertSame('true', DBQueryToValue("SELECT value FROM uo_setting WHERE name='ShowDefenseStats'"));
    }

    public function testSeasonSeriesReadsBaselineFixture(): void
    {
        LegacyApp::loadSeasonFunctions();

        $series = SeasonSeries('HRN2026', true);

        $this->assertCount(1, $series);
        $this->assertSame('Open', $series[0]['name']);
    }

    private static function expectedShowDefenseStats(): string
    {
        return getenv('UO_CONFIG_PROFILE') === 'config-overrides' ? 'true' : 'false';
    }
}
