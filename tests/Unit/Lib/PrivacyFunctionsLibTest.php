<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class PrivacyFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('privacy.functions.php', 'bootstrap_only');
    }

    // --- PrivacySlug ---

    public function testPrivacySlugPassesThroughAlreadyCleanValue(): void
    {
        $this->assertSame('hello-world', PrivacySlug('hello-world'));
    }

    public function testPrivacySlugReplacesSpacesAndSpecialChars(): void
    {
        $this->assertSame('Hello-World', PrivacySlug('Hello World!'));
    }

    public function testPrivacySlugPreservesDotsUnderscoresAndDashes(): void
    {
        $this->assertSame('John_Smith.99', PrivacySlug('John_Smith.99'));
    }

    public function testPrivacySlugReplacesAtSignWithDash(): void
    {
        $this->assertSame('user-email.com', PrivacySlug('user@email.com'));
    }

    public function testPrivacySlugReturnsSubjectForEmptyString(): void
    {
        $this->assertSame('subject', PrivacySlug(''));
    }

    public function testPrivacySlugReturnsSubjectWhenOnlyDashes(): void
    {
        // After trimming leading/trailing dashes, nothing remains
        $this->assertSame('subject', PrivacySlug('---'));
    }

    public function testPrivacySlugTrimsLeadingAndTrailingDashes(): void
    {
        $this->assertSame('abc', PrivacySlug('!abc!'));
    }

    public function testPrivacySlugCollapsesContinuousSpecialCharsToOneDash(): void
    {
        // Multiple non-allowed chars become a single dash
        $this->assertSame('a-b', PrivacySlug('a   b'));
    }
}
