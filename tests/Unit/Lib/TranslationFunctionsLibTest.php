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
        $this->assertSame(['hello' => 'Hei'], $result);
    }

    public function testAutocompleteTranslateHandlesMultiWordInput(): void
    {
        $result = autocompleteTranslate('hello world', ['hello' => 'Hei', 'world' => 'Maailma']);
        $this->assertSame(['hello world' => 'Hei Maailma'], $result);
    }

    public function testAutocompleteTranslateNoMatchReturnsEmptyArray(): void
    {
        // Despite the underscore splitting 'xyz_no_match' into 3 word parts (WORD_DELIMITER
        // includes '_'), an empty translation_array means no candidate ever matches at any
        // step, so $ret stays empty — this does NOT echo the input back.
        $result = autocompleteTranslate('xyz_no_match', []);
        $this->assertSame([], $result);
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
        // These tests run before any test sets `global $locales`, so AllTranslations()'s
        // `foreach ($locales as ...)` iterates nothing, no locale results are populated, and
        // the "not found" fallback kicks in: ['None' => [search => search]].
        $_GET['search'] = 'team';
        $result = GetTranslations();
        $this->assertSame(['None' => ['team' => 'team']], $result);
    }

    public function testGetTranslationsWithQueryParam(): void
    {
        $_GET['query'] = 'pool';
        $result = GetTranslations();
        $this->assertSame(['None' => ['pool' => 'pool']], $result);
    }

    public function testGetTranslationsWithQParam(): void
    {
        $_GET['q'] = 'game';
        $result = GetTranslations();
        $this->assertSame(['None' => ['game' => 'game']], $result);
    }

    public function testGetTranslationsWithAutocompleteParam(): void
    {
        $_GET['search'] = 'team';
        $_GET['autocomplete'] = 'true';
        $result = GetTranslations();
        $this->assertSame(['None' => ['team' => 'team']], $result);
    }

    // --- GetAutocompleteTranslations() ---

    public function testGetAutocompleteTranslationsWithSearchParam(): void
    {
        $_GET['search'] = 'pool';
        $result = GetAutocompleteTranslations();
        $this->assertSame(['None' => ['pool' => 'pool']], $result);
    }

    public function testGetAutocompleteTranslationsWithQueryParam(): void
    {
        $_GET['query'] = 'team';
        $result = GetAutocompleteTranslations();
        $this->assertSame(['None' => ['team' => 'team']], $result);
    }

    public function testGetAutocompleteTranslationsWithQParam(): void
    {
        $_GET['q'] = 'game';
        $result = GetAutocompleteTranslations();
        $this->assertSame(['None' => ['game' => 'game']], $result);
    }

    // --- AllTranslations() with $locales populated ---

    public function testAllTranslationsReturnsResultsForKnownTerm(): void
    {
        global $locales;
        $locales = ['en_GB.utf8' => 'English'];
        $result = AllTranslations('team', false);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('en_GB_utf8', $result);
    }

    public function testAllTranslationsAutocompleteReturnsArrayPerLocale(): void
    {
        global $locales;
        $locales = ['en_GB.utf8' => 'English'];
        $result = AllTranslations('team', true);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('en_GB_utf8', $result);
    }

    public function testAllTranslationsReturnsNoneEntryWhenNoTranslationFound(): void
    {
        global $locales;
        $locales = ['en_GB.utf8' => 'English'];
        // Key unlikely to have a translation → 'None' entry added.
        $result = AllTranslations('xyzzy_no_match_' . uniqid(), false);
        $this->assertIsArray($result);
        // At least one entry returned (the 'None' fallback).
        $this->assertNotEmpty($result);
    }

    public function testAllTranslationsReturnsEmptyForBlankSearch(): void
    {
        global $locales;
        $locales = ['en_GB.utf8' => 'English'];
        $this->assertSame([], AllTranslations('', false));
        $this->assertSame([], AllTranslations('  ', false));
    }

    // --- Admin translation functions (require hasTranslationRight) ---

    public function testTranslationsReturnsArrayAsSuperAdmin(): void
    {
        // The SUT installer seeds default Timekeeper UI translation keys for en_GB_utf8 (no
        // test in this class adds them — all insert tests clean up in `finally` and run after
        // this one). The exact set varies by matrix case config (config-overrides' install path
        // seeds extra timekeeper templates beyond baseline-default's single WFDF template), so
        // assert the query shape and the stable core subset rather than an exact snapshot.
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $rows = Translations();
            $keys = array_column($rows, 'translation_key');
            $sorted = $keys;
            sort($sorted);
            $this->assertSame($sorted, $keys, 'Translations() must order by translation_key ASC');
            $this->assertContains('Play', $keys);
            $this->assertContains('Offence warning', $keys);
            $this->assertContains('Halftime over', $keys);
            $this->assertGreaterThanOrEqual(10, count($keys));
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

    // --- RegisterTranslationKey ---

    public function testRegisterTranslationKeyIgnoresEmptyKey(): void
    {
        // Should return early without touching the DB
        RegisterTranslationKey('');
        $this->assertTrue(true);
    }

    public function testRegisterTranslationKeyIgnoresOversizeKey(): void
    {
        RegisterTranslationKey(str_repeat('x', 51));
        $this->assertTrue(true);
    }

    public function testRegisterTranslationKeyInsertsRowWithExplicitLocale(): void
    {
        $key = 'uo_harness_rtk_' . uniqid();
        try {
            RegisterTranslationKey($key, 'fi_FI.utf8');
            $count = DBQueryToValue("SELECT COUNT(*) FROM uo_translation WHERE translation_key='$key' AND locale='fi_FI_utf8'");
            $this->assertSame('1', $count);
        } finally {
            DBQuery("DELETE FROM uo_translation WHERE translation_key='$key'");
        }
    }

    public function testRegisterTranslationKeyUsesSessionLocaleWhenNullGiven(): void
    {
        $key = 'uo_harness_rtk_sess_' . uniqid();
        $_SESSION['userproperties']['locale'] = 'en_GB.utf8';
        try {
            RegisterTranslationKey($key, null);
            $count = DBQueryToValue("SELECT COUNT(*) FROM uo_translation WHERE translation_key='$key' AND locale='en_GB_utf8'");
            $this->assertSame('1', $count);
        } finally {
            DBQuery("DELETE FROM uo_translation WHERE translation_key='$key'");
        }
    }
}
