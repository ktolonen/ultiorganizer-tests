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
        // The SUT sets $serverConf at require_once time. If the file was already loaded
        // by an earlier integration test without a DB connection, the global is empty.
        // Refresh it explicitly so getter functions read current fixture data.
        global $serverConf;
        $serverConf = GetSimpleServerConf();
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

    public function testReadBooleanSystemFlagHandlesBoolConstant(): void
    {
        $constantName = 'UO_TEST_BOOL_TRUE_' . str_replace('.', '_', uniqid('', true));
        define($constantName, true);
        $this->assertTrue(ReadBooleanSystemFlag($constantName, false));
    }

    // --- Simple $serverConf getters ---

    public function testGetPageTitleReturnsBaselineValue(): void
    {
        $this->assertStringContainsString('Ultiorganizer', GetPageTitle());
    }

    public function testGetDefaultLocaleReturnsBaselineValue(): void
    {
        $expected = self::expectedConfig();
        $this->assertSame($expected['DefaultLocale'], GetDefaultLocale());
    }

    public function testGetDefTimeZoneReturnsBaselineValue(): void
    {
        $expected = self::expectedConfig();
        $this->assertSame($expected['DefaultTimezone'], GetDefTimeZone());
    }

    public function testIsGameRSSEnabledReturnsFalseInBaseline(): void
    {
        $expected = self::expectedConfig();
        $this->assertSame($expected['GameRSSEnabled'] === 'true', IsGameRSSEnabled());
    }

    public function testShowDefenseStatsReturnsFalseInBaseline(): void
    {
        $expected = self::expectedConfig();
        $this->assertSame($expected['ShowDefenseStats'] === 'true', ShowDefenseStats());
    }

    public function testReadOnlyServerReturnsFalseInBaseline(): void
    {
        $this->assertFalse(ReadOnlyServer());
    }

    public function testSoftMaintenanceModeReturnsFalseInBaseline(): void
    {
        $this->assertFalse(SoftMaintenanceMode());
    }

    public function testGetGoogleMapsAPIKeyReturnsBaselineValue(): void
    {
        $this->assertSame('', GetGoogleMapsAPIKey());
    }

    // --- SoftMaintenanceRequestPrefersJson ---

    public function testSoftMaintenanceRequestPrefersJsonReturnsTrueForApiScriptName(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/api/v1/something.php';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $this->assertTrue(SoftMaintenanceRequestPrefersJson());
        unset($_SERVER['SCRIPT_NAME'], $_SERVER['HTTP_ACCEPT']);
    }

    public function testSoftMaintenanceRequestPrefersJsonReturnsTrueForJsonPhpSuffix(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/ext/seasons.json.php';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $this->assertTrue(SoftMaintenanceRequestPrefersJson());
        unset($_SERVER['SCRIPT_NAME'], $_SERVER['HTTP_ACCEPT']);
    }

    public function testSoftMaintenanceRequestPrefersJsonReturnsTrueForJsonAcceptHeader(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTP_ACCEPT'] = 'application/json, text/plain, */*';
        $this->assertTrue(SoftMaintenanceRequestPrefersJson());
        unset($_SERVER['SCRIPT_NAME'], $_SERVER['HTTP_ACCEPT']);
    }

    public function testSoftMaintenanceRequestPrefersJsonReturnsFalseForHtmlRequest(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';
        $this->assertFalse(SoftMaintenanceRequestPrefersJson());
        unset($_SERVER['SCRIPT_NAME'], $_SERVER['HTTP_ACCEPT']);
    }

    // --- SoftMaintenanceText ---

    public function testSoftMaintenanceTextReturnsInputUnchangedWithoutTranslation(): void
    {
        $this->assertSame('Maintenance', SoftMaintenanceText('Maintenance'));
    }

    // --- Registration flag functions ---

    public function testIsSelfRegistrationDisabledReflectsDisableSelfRegistrationConstant(): void
    {
        // DISABLE_SELF_REGISTRATION differs per profile (true baseline, false config-overrides)
        $expected = self::expectedConfig()['DISABLE_SELF_REGISTRATION'];
        $this->assertSame($expected, IsSelfRegistrationDisabled());
    }

    public function testIsEmailDisabledReturnsFalseWhenNoEmailUndefined(): void
    {
        // NO_EMAIL is not defined in either test config
        $this->assertFalse(IsEmailDisabled());
    }

    public function testIsPublicRegistrationDisabledReflectsProfile(): void
    {
        // IsPublicRegistrationDisabled = IsSelfRegistrationDisabled || IsEmailDisabled.
        // NO_EMAIL is undefined, so it tracks DISABLE_SELF_REGISTRATION.
        $expected = self::expectedConfig()['DISABLE_SELF_REGISTRATION'];
        $this->assertSame($expected, IsPublicRegistrationDisabled());
    }

    public function testIsSelfRegistrationEnabledIsInverseOfPublicRegistrationDisabled(): void
    {
        $this->assertSame(!IsPublicRegistrationDisabled(), IsSelfRegistrationEnabled());
    }

    // --- GetServerConf ---

    public function testGetServerConfReturnsRowsWithNameAndValueKeys(): void
    {
        $rows = GetServerConf();
        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('name', $rows[0]);
        $this->assertArrayHasKey('value', $rows[0]);
    }

    // --- isRespTeamHomeTeam ---

    public function testIsRespTeamHomeTeamReturnsTrueInBaseline(): void
    {
        $this->assertTrue(isRespTeamHomeTeam());
    }

    // --- getAvailableCustomizations ---

    public function testGetAvailableCustomizationsReturnsNonEmptyList(): void
    {
        $customizations = getAvailableCustomizations();
        $this->assertIsArray($customizations);
        $this->assertNotEmpty($customizations);
        $this->assertContains('default', $customizations);
    }

    // --- IsPersistentCacheEnabled / GetPersistentCacheTtlSeconds ---

    public function testIsPersistentCacheEnabledReturnsTrueWhenSettingAbsent(): void
    {
        // Baseline fixture does not set PersistentCacheEnabled → defaults to true
        $this->assertTrue(IsPersistentCacheEnabled());
    }

    public function testGetPersistentCacheTtlSecondsReturnsPositiveInteger(): void
    {
        $ttl = GetPersistentCacheTtlSeconds();
        $this->assertIsInt($ttl);
        $this->assertGreaterThan(0, $ttl);
    }

    // --- SetServerConf INSERT path (new setting) and SetServerConfValue ---

    public function testSetServerConfInsertsNewSettingAsSuperAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();

        $settingName = 'UO_HARNESS_TEST_' . uniqid();
        try {
            SetServerConf([['name' => $settingName, 'value' => 'harness_val']]);
            $stored = DBQueryToValue("SELECT value FROM uo_setting WHERE name='$settingName'");
            $this->assertSame('harness_val', $stored);
        } finally {
            DBQuery("DELETE FROM uo_setting WHERE name='$settingName'");
            $_SESSION = [];
        }
    }

    public function testSetServerConfValueWritesSingleSetting(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();

        $settingName = 'UO_HARNESS_SCV_' . uniqid();
        try {
            SetServerConfValue($settingName, 'single_val');
            $stored = DBQueryToValue("SELECT value FROM uo_setting WHERE name='$settingName'");
            $this->assertSame('single_val', $stored);
        } finally {
            DBQuery("DELETE FROM uo_setting WHERE name='$settingName'");
            $_SESSION = [];
        }
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
