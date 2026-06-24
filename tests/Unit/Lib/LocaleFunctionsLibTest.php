<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Tests for lib/locale.functions.php.
 *
 * Die-path gaps: none — all functions return values or have observable side effects.
 */
final class LocaleFunctionsLibTest extends TestCase
{
    private string $originalLcMessages;
    private array $savedEnv = [];

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('locale.functions.php', 'bootstrap_only');
        $this->originalLcMessages = (string) setlocale(LC_MESSAGES, '0');
        foreach (['LC_ALL', 'LC_MESSAGES', 'LANG', 'LANGUAGE'] as $var) {
            $val = getenv($var);
            $this->savedEnv[$var] = ($val !== false) ? $val : null;
        }
    }

    protected function tearDown(): void
    {
        setlocale(LC_MESSAGES, $this->originalLcMessages !== '' ? $this->originalLcMessages : 'C');
        foreach ($this->savedEnv as $var => $val) {
            if ($val === null) {
                putenv($var);
            } else {
                putenv("$var=$val");
            }
        }
    }

    // ===== GettextLanguageSpec =====

    public function testGettextLanguageSpecUtf8LocaleProducesAllFourSegments(): void
    {
        $spec = GettextLanguageSpec('fi_FI.utf8');
        $parts = explode(':', $spec);
        $this->assertContains('fi_FI.utf8', $parts);
        $this->assertContains('fi_FI.UTF-8', $parts);
        $this->assertContains('fi_FI', $parts);
        $this->assertContains('fi', $parts);
    }

    public function testGettextLanguageSpecUpperCaseUtf8IncludesTerritoryAndLanguage(): void
    {
        $spec = GettextLanguageSpec('en_US.UTF-8');
        $parts = explode(':', $spec);
        $this->assertContains('en_US.UTF-8', $parts);
        $this->assertContains('en_US', $parts);
        $this->assertContains('en', $parts);
    }

    public function testGettextLanguageSpecLocalWithoutEncodingProducesLanguagePair(): void
    {
        $spec = GettextLanguageSpec('fi_FI');
        $parts = explode(':', $spec);
        $this->assertContains('fi_FI', $parts);
        $this->assertContains('fi', $parts);
    }

    public function testGettextLanguageSpecSingleLanguageCodeProducesOnePart(): void
    {
        $spec = GettextLanguageSpec('fi');
        $this->assertSame('fi', $spec);
    }

    // ===== GettextLocaleVariants =====

    public function testGettextLocaleVariantsUtf8UpperCaseProducesBothCasings(): void
    {
        $variants = GettextLocaleVariants('en_US.UTF-8');
        $this->assertContains('en_US.UTF-8', $variants);
        $this->assertContains('en_US.utf8', $variants);
    }

    public function testGettextLocaleVariantsUtf8LowerCaseProducesBothCasings(): void
    {
        $variants = GettextLocaleVariants('en_US.utf8');
        $this->assertContains('en_US.utf8', $variants);
        $this->assertContains('en_US.UTF-8', $variants);
    }

    public function testGettextLocaleVariantsNoEncodingReturnsSingleEntry(): void
    {
        $variants = GettextLocaleVariants('en_US');
        $this->assertSame(['en_US'], $variants);
    }

    public function testGettextLocaleVariantsDeduplicatesWhenBothCasingsMatch(): void
    {
        $variants = GettextLocaleVariants('en_US.UTF-8');
        $this->assertCount(2, $variants);
    }

    // ===== GettextEnvironmentLocales =====

    public function testGettextEnvironmentLocalesPicksUpLcAll(): void
    {
        putenv('LC_ALL=en_US.UTF-8');
        putenv('LC_MESSAGES');
        putenv('LANG');
        $locales = GettextEnvironmentLocales();
        $this->assertContains('en_US.UTF-8', $locales);
    }

    public function testGettextEnvironmentLocalesPicksUpLang(): void
    {
        putenv('LC_ALL');
        putenv('LC_MESSAGES');
        putenv('LANG=fi_FI.UTF-8');
        $locales = GettextEnvironmentLocales();
        $this->assertContains('fi_FI.UTF-8', $locales);
    }

    public function testGettextEnvironmentLocalesReturnsEmptyWhenNoneSet(): void
    {
        putenv('LC_ALL');
        putenv('LC_MESSAGES');
        putenv('LANG');
        $locales = GettextEnvironmentLocales();
        $this->assertIsArray($locales);
        $this->assertEmpty($locales);
    }

    // ===== GettextInstalledLocales =====

    public function testGettextInstalledLocalesReturnsArray(): void
    {
        $locales = GettextInstalledLocales();
        $this->assertIsArray($locales);
    }

    public function testGettextInstalledLocalesContainsCLocale(): void
    {
        $locales = GettextInstalledLocales();
        $this->assertContains('C', $locales);
    }

    // ===== IsGettextCarrierLocale =====

    public function testIsGettextCarrierLocaleReturnsFalseForC(): void
    {
        $this->assertFalse(IsGettextCarrierLocale('C'));
    }

    public function testIsGettextCarrierLocaleReturnsFalseForPosix(): void
    {
        $this->assertFalse(IsGettextCarrierLocale('POSIX'));
    }

    public function testIsGettextCarrierLocaleReturnsFalseForCWithEncoding(): void
    {
        $this->assertFalse(IsGettextCarrierLocale('C.UTF-8'));
    }

    public function testIsGettextCarrierLocaleReturnsTrueForEnUs(): void
    {
        $this->assertTrue(IsGettextCarrierLocale('en_US.UTF-8'));
    }

    public function testIsGettextCarrierLocaleReturnsTrueForFiFi(): void
    {
        $this->assertTrue(IsGettextCarrierLocale('fi_FI'));
    }

    // ===== GettextCarrierLocaleCandidates =====

    public function testGettextCarrierLocaleCandidatesIncludesFallbackDefaults(): void
    {
        $candidates = GettextCarrierLocaleCandidates([]);
        $this->assertIsArray($candidates);
        $allAsString = implode(' ', $candidates);
        $this->assertStringContainsString('en_US', $allAsString);
    }

    public function testGettextCarrierLocaleCandidatesIncludesPassedCandidates(): void
    {
        $candidates = GettextCarrierLocaleCandidates(['de_DE.UTF-8']);
        $allAsString = implode(' ', $candidates);
        $this->assertStringContainsString('de_DE', $allAsString);
    }

    // ===== GettextCarrierLocale =====

    public function testGettextCarrierLocaleReturnsFalseOrNonCLocale(): void
    {
        $result = GettextCarrierLocale();
        $this->assertTrue($result === false || IsGettextCarrierLocale((string) $result));
    }

    public function testGettextCarrierLocaleRestoresOriginalLcMessages(): void
    {
        $before = (string) setlocale(LC_MESSAGES, '0');
        GettextCarrierLocale();
        $after = (string) setlocale(LC_MESSAGES, '0');
        $this->assertSame($before, $after);
    }

    // ===== CanServeGettextLocale =====

    public function testCanServeGettextLocaleReturnsTrueForEnglishLocale(): void
    {
        // en_ prefix guarantees true even without installed locale
        $this->assertTrue(CanServeGettextLocale('en_US.UTF-8'));
    }

    public function testCanServeGettextLocaleRestoresLcMessages(): void
    {
        $before = (string) setlocale(LC_MESSAGES, '0');
        CanServeGettextLocale('en_US.UTF-8');
        $after = (string) setlocale(LC_MESSAGES, '0');
        $this->assertSame($before, $after);
    }

    // ===== ActivateGettextLocale =====

    public function testActivateGettextLocaleReturnsTrueForEnglishLocale(): void
    {
        $result = ActivateGettextLocale('en_US.UTF-8');
        $this->assertTrue($result);
    }

    public function testActivateGettextLocaleSetsLanguageEnvVar(): void
    {
        ActivateGettextLocale('en_US.UTF-8');
        $lang = getenv('LANGUAGE');
        $this->assertIsString($lang);
        $this->assertStringContainsString('en_US', $lang);
    }

    public function testActivateGettextLocaleSetsLcMessagesEnvVar(): void
    {
        ActivateGettextLocale('en_US.UTF-8');
        $lcMessages = getenv('LC_MESSAGES');
        $this->assertNotFalse($lcMessages);
        // Either the exact locale or a carrier locale
        $this->assertNotEmpty($lcMessages);
    }
}
