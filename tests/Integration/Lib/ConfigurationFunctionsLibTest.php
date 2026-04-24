<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class ConfigurationFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('configuration.functions.php', 'database_with_common');
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    public function testDefaultConfigValuesComeFromBaselineFixture(): void
    {
        $config = GetSimpleServerConf();

        $this->assertSame('Ultiorganizer Test Harness - ', $config['PageTitle']);
        $this->assertSame('en_GB.utf8', $config['DefaultLocale']);
        $this->assertSame('Europe/Helsinki', $config['DefaultTimezone']);
        $this->assertSame('false', $config['ShowDefenseStats']);
    }

    public function testReadBooleanSystemFlagHandlesUndefinedAndTruthyConstants(): void
    {
        $constantName = 'UO_TEST_BOOL_' . str_replace('.', '_', uniqid('', true));
        define($constantName, 'enabled');

        $this->assertFalse(ReadBooleanSystemFlag('UO_TEST_BOOL_MISSING_FLAG', false));
        $this->assertTrue(ReadBooleanSystemFlag($constantName, false));
    }
}
