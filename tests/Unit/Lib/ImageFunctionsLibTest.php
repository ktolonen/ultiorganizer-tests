<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class ImageFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('image.functions.php', 'bootstrap_only');
    }

    // --- CanProcessImages ---

    public function testCanProcessImagesReturnsBool(): void
    {
        $this->assertIsBool(CanProcessImages());
    }

    // --- CanReadImageType ---

    public function testCanReadImageTypeReturnsFalseForUnknownType(): void
    {
        $this->assertFalse(CanReadImageType(4));
        $this->assertFalse(CanReadImageType(0));
        $this->assertFalse(CanReadImageType(-1));
    }

    public function testCanReadImageTypeReturnsBoolForKnownTypes(): void
    {
        // Returns true/false depending on GD extension support in this environment
        $this->assertIsBool(CanReadImageType(1)); // GIF
        $this->assertIsBool(CanReadImageType(2)); // JPEG
        $this->assertIsBool(CanReadImageType(3)); // PNG
    }
}
