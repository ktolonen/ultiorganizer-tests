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
        $expected = self::expectedConfig();

        $this->assertSame($expected['PageTitle'], $config['PageTitle']);
        $this->assertSame($expected['DefaultLocale'], $config['DefaultLocale']);
        $this->assertSame($expected['DefaultTimezone'], $config['DefaultTimezone']);
        $this->assertSame($expected['ShowDefenseStats'], $config['ShowDefenseStats']);
        $this->assertSame($expected['GameRSSEnabled'], $config['GameRSSEnabled']);
        $this->assertSame($expected['AdminEmail'], $config['AdminEmail']);
        $this->assertSame($expected['DisableVisitorLogging'], $config['DisableVisitorLogging']);
    }

    public function testGeneratedConfigConstantsMatchSelectedProfile(): void
    {
        $expected = self::expectedConfig();

        $this->assertSame($expected['CUSTOMIZATIONS'], CUSTOMIZATIONS);
        $this->assertSame($expected['ENABLE_ADMIN_DB_ACCESS'], ENABLE_ADMIN_DB_ACCESS);
        $this->assertSame($expected['DISABLE_SELF_REGISTRATION'], DISABLE_SELF_REGISTRATION);
        $this->assertSame($expected['ALLOW_INSTALL'], ALLOW_INSTALL);
        $this->assertSame($expected['ANONYMOUS_RESULT_INPUT'], ANONYMOUS_RESULT_INPUT);
        $this->assertSame($expected['API_RATE_LIMIT'], API_RATE_LIMIT);
        $this->assertSame($expected['API_RATE_WINDOW'], API_RATE_WINDOW);
    }

    public function testReadBooleanSystemFlagHandlesUndefinedAndTruthyConstants(): void
    {
        $constantName = 'UO_TEST_BOOL_' . str_replace('.', '_', uniqid('', true));
        define($constantName, 'enabled');

        $this->assertFalse(ReadBooleanSystemFlag('UO_TEST_BOOL_MISSING_FLAG', false));
        $this->assertTrue(ReadBooleanSystemFlag($constantName, false));
    }

    private static function expectedConfig(): array
    {
        if (getenv('UO_CONFIG_PROFILE') === 'config-overrides') {
            return [
                'CUSTOMIZATIONS' => 'default',
                'ENABLE_ADMIN_DB_ACCESS' => 'enabled',
                'DISABLE_SELF_REGISTRATION' => false,
                'ALLOW_INSTALL' => true,
                'ANONYMOUS_RESULT_INPUT' => true,
                'API_RATE_LIMIT' => 7,
                'API_RATE_WINDOW' => 11,
                'PageTitle' => 'Ultiorganizer Override Harness - ',
                'DefaultLocale' => 'fi_FI.utf8',
                'DefaultTimezone' => 'Europe/Helsinki',
                'ShowDefenseStats' => 'true',
                'GameRSSEnabled' => 'true',
                'AdminEmail' => 'override-admin@example.com',
                'DisableVisitorLogging' => 'false',
            ];
        }

        return [
            'CUSTOMIZATIONS' => getenv('UO_CUSTOMIZATION') ?: 'default',
            'ENABLE_ADMIN_DB_ACCESS' => 'disabled',
            'DISABLE_SELF_REGISTRATION' => true,
            'ALLOW_INSTALL' => true,
            'ANONYMOUS_RESULT_INPUT' => false,
            'API_RATE_LIMIT' => 120,
            'API_RATE_WINDOW' => 60,
            'PageTitle' => 'Ultiorganizer Test Harness - ',
            'DefaultLocale' => 'en_GB.utf8',
            'DefaultTimezone' => 'Europe/Helsinki',
            'ShowDefenseStats' => 'false',
            'GameRSSEnabled' => 'false',
            'AdminEmail' => 'admin@example.com',
            'DisableVisitorLogging' => 'true',
        ];
    }
}
