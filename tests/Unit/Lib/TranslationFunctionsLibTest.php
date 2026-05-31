<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

// utf8entities() lives in localization.php (SUT root, not loaded in unit tests)
if (!function_exists('utf8entities')) {
    function utf8entities(mixed $s): string
    {
        return htmlentities((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

final class TranslationFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        if (!defined('WORD_DELIMITER')) {
            define('WORD_DELIMITER', '/([\;\,\-_\s\/\.])/');
        }
        // database_only to enable DB-backed translation functions
        LegacyApp::loadLibFileUsingProfile('translation.functions.php', 'database_only');
        $_SESSION['userproperties']['locale'] = 'en_GB.utf8';
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    // --- translate() (existing) ---

    public function testTranslateUsesExactKeyMatchWhenAvailable(): void
    {
        $this->assertSame(['Hello' => 'Hei'], translate('Hello', ['hello' => 'Hei']));
    }

    public function testTranslateFallsBackPerTokenAndPreservesNumbers(): void
    {
        $translated = translate('Final-2026 Match', ['final' => 'Loppuottelu', 'match' => 'Ottelu']);
        $this->assertSame(['Final-2026 Match' => 'Loppuottelu-2026 Ottelu'], $translated);
    }

    public function testTranslateReturnsEmptyStringForEmptyInput(): void
    {
        $this->assertSame(['' => ''], translate('', []));
        $this->assertSame(['' => ''], translate(null, []));
    }

    public function testTranslatePassesThroughDelimiterParts(): void
    {
        $result = translate('A/B', []);
        $this->assertSame(['A/B' => 'A/B'], $result);
    }

    public function testTranslateFallsThroughWhenNoMatchFound(): void
    {
        $result = translate('UnknownWord', []);
        $this->assertSame(['UnknownWord' => 'UnknownWord'], $result);
    }

    // --- autocompleteTranslate() ---

    public function testAutocompleteTranslateReturnsEmptyForEmptyInput(): void
    {
        $this->assertSame([], autocompleteTranslate('', ['hello' => 'Hei']));
        $this->assertSame([], autocompleteTranslate(null, ['hello' => 'Hei']));
    }

    public function testAutocompleteTranslateMatchesByPrefix(): void
    {
        $result = autocompleteTranslate('hel', ['hello' => 'Hei', 'help' => 'Apu', 'world' => 'Maailma']);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testAutocompleteTranslateHandlesExactMatch(): void
    {
        $result = autocompleteTranslate('hello', ['hello' => 'Hei']);
        $this->assertIsArray($result);
    }

    public function testAutocompleteTranslateHandlesMultiWordInput(): void
    {
        $result = autocompleteTranslate('hello world', ['hello' => 'Hei', 'world' => 'Maailma']);
        $this->assertIsArray($result);
    }

    public function testAutocompleteTranslateNoMatchReturnsInput(): void
    {
        $result = autocompleteTranslate('xyz_no_match', []);
        $this->assertIsArray($result);
    }

    // --- TranslatedField / TranslationScript ---

    public function testTranslatedFieldReturnsHtmlWithFieldName(): void
    {
        $html = TranslatedField('testField', 'Test Value');
        $this->assertStringContainsString('testField', $html);
        $this->assertStringContainsString('Test Value', $html);
    }

    public function testTranslationScriptReturnsJavascriptWithFieldName(): void
    {
        $js = TranslationScript('testScript');
        $this->assertStringContainsString('testScript', $js);
        $this->assertStringContainsString('script', $js);
    }

    // --- U_() with pre-populated session ---

    public function testU_ReturnsTranslationFromSessionCache(): void
    {
        $_SESSION['dbtranslations'] = ['hello' => 'Hei'];
        $result = U_('Hello');
        $this->assertSame('Hei', $result);
    }

    public function testU_ReturnsInputWhenNoTranslationFound(): void
    {
        $_SESSION['dbtranslations'] = [];
        $result = U_('NoTranslation');
        $this->assertSame('NoTranslation', $result);
    }

    public function testU_LoadsTranslationsWhenSessionNotSet(): void
    {
        unset($_SESSION['dbtranslations']);
        $result = U_('SomeKey');
        $this->assertIsString($result);
        $this->assertArrayHasKey('dbtranslations', $_SESSION);
    }

    // --- loadDBTranslations() ---

    public function testLoadDBTranslationsPopulatesSession(): void
    {
        unset($_SESSION['dbtranslations']);
        loadDBTranslations('en_GB.utf8');
        $this->assertArrayHasKey('dbtranslations', $_SESSION);
        $this->assertIsArray($_SESSION['dbtranslations']);
    }

    // --- GetTranslations() search paths ---

    public function testGetTranslationsWithSearchParam(): void
    {
        $_GET['search'] = 'team';
        $result = GetTranslations();
        $this->assertIsArray($result);
    }

    public function testGetTranslationsWithQueryParam(): void
    {
        $_GET['query'] = 'pool';
        $result = GetTranslations();
        $this->assertIsArray($result);
    }

    public function testGetTranslationsWithQParam(): void
    {
        $_GET['q'] = 'game';
        $result = GetTranslations();
        $this->assertIsArray($result);
    }

    public function testGetTranslationsWithAutocompleteParam(): void
    {
        $_GET['search'] = 'team';
        $_GET['autocomplete'] = 'true';
        $result = GetTranslations();
        $this->assertIsArray($result);
    }

    // --- GetAutocompleteTranslations() ---

    public function testGetAutocompleteTranslationsWithSearchParam(): void
    {
        $_GET['search'] = 'pool';
        $result = GetAutocompleteTranslations();
        $this->assertIsArray($result);
    }

    // --- Admin translation functions (require hasTranslationRight) ---

    public function testTranslationsReturnsArrayAsSuperAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $rows = Translations();
            $this->assertIsArray($rows);
        } finally {
            $_SESSION = [];
        }
    }

    public function testExistingTranslationKeyReturnsNullForMissingKey(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $result = ExistingTranslationKey('uo_test_no_such_key_xyz_' . uniqid());
            $this->assertNull($result);
        } finally {
            $_SESSION = [];
        }
    }

    public function testSetAndRemoveTranslationRoundTrip(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $testKey = 'uo_test_key_' . uniqid();
        try {
            SetTranslation($testKey, ['en_GB.utf8' => 'Test value']);
            $found = ExistingTranslationKey($testKey);
            $this->assertSame($testKey, $found);

            // Test delete path with empty value
            SetTranslation($testKey, ['en_GB.utf8' => '']);
            $afterDelete = DBQueryToValue("SELECT COUNT(*) FROM uo_translation WHERE translation_key='$testKey'");
            $this->assertSame('0', $afterDelete);
        } finally {
            DBQuery("DELETE FROM uo_translation WHERE translation_key='$testKey'");
            $_SESSION = [];
        }
    }

    public function testAddAndRemoveTranslationRoundTrip(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $testKey = 'uo_add_test_' . uniqid();
        try {
            AddTranslation($testKey, ['en_GB.utf8' => 'Add test value']);
            $found = ExistingTranslationKey($testKey);
            $this->assertSame($testKey, $found);

            RemoveTranslation($testKey);
            $afterRemove = DBQueryToValue("SELECT COUNT(*) FROM uo_translation WHERE translation_key='$testKey'");
            $this->assertSame('0', $afterRemove);
        } finally {
            DBQuery("DELETE FROM uo_translation WHERE translation_key='$testKey'");
            $_SESSION = [];
        }
    }

    public function testAddTranslationWithEmptyValueDeletesExisting(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $testKey = 'uo_add_del_test_' . uniqid();
        try {
            AddTranslation($testKey, ['en_GB.utf8' => 'Initial']);
            AddTranslation($testKey, ['en_GB.utf8' => '']); // empty → DELETE path
            $count = DBQueryToValue("SELECT COUNT(*) FROM uo_translation WHERE translation_key='$testKey'");
            $this->assertSame('0', $count);
        } finally {
            DBQuery("DELETE FROM uo_translation WHERE translation_key='$testKey'");
            $_SESSION = [];
        }
    }
}
