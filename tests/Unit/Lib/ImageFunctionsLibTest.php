<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class ImageFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        // database_only to enable GetImage/GetThumb/ImageInfo DB queries
        LegacyApp::loadLibFileUsingProfile('image.functions.php', 'database_only');
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
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
        $this->assertIsBool(CanReadImageType(1)); // GIF
        $this->assertIsBool(CanReadImageType(2)); // JPEG
        $this->assertIsBool(CanReadImageType(3)); // PNG
    }

    // --- GetImage / GetThumb / ImageInfo (DB-backed, no fixture rows) ---

    public function testGetImageReturnsFalseForMissingId(): void
    {
        $this->assertFalse((bool) GetImage(99999));
    }

    public function testGetThumbReturnsFalseForMissingId(): void
    {
        $this->assertFalse((bool) GetThumb(99999));
    }

    public function testImageInfoReturnsFalseForMissingId(): void
    {
        $this->assertFalse((bool) ImageInfo(99999));
    }

    // --- WriteJpegImage (filesystem early-return, no GD needed) ---

    public function testWriteJpegImageReturnsFalseForNonExistentDirectory(): void
    {
        $result = WriteJpegImage(null, '/tmp/nonexistent_dir_xyz_uo/image.jpg');
        $this->assertFalse($result);
    }

    // --- ConvertToJpeg (both GD-available and GD-unavailable paths) ---

    public function testConvertToJpegReturnsFalseWhenGdNotAvailableOrFileInvalid(): void
    {
        // When GD is unavailable: returns false at CanProcessImages check.
        // When GD IS available: returns false because the file does not exist.
        $result = ConvertToJpeg('/tmp/nonexistent_uo_image_xyz.jpg', '/tmp/out_uo.jpg');
        $this->assertFalse($result);
    }

    // --- CreateThumb (both GD-available and GD-unavailable paths) ---

    public function testCreateThumbReturnsFalseWhenGdNotAvailableOrFileInvalid(): void
    {
        // When GD is unavailable: returns false at CanProcessImages check (lines 149-150).
        // When GD IS available: returns false because the file does not exist (lines 159-160).
        $result = CreateThumb('/tmp/nonexistent_uo_image_xyz.jpg', '/tmp/out_uo.jpg', 100, 100);
        $this->assertFalse($result);
    }

    public function testCreateThumbReturnsFalseForZeroDimensions(): void
    {
        if (!CanProcessImages()) {
            // When GD is unavailable the CanProcessImages check fires first (line 149-150).
            // Test the GD-unavailable early return path instead.
            $this->assertFalse(CreateThumb('/tmp/any.jpg', '/tmp/out.jpg', 0, 100));
            $this->assertFalse(CreateThumb('/tmp/any.jpg', '/tmp/out.jpg', 100, 0));
        } else {
            // GD available: zero dims are caught at lines 155-156.
            $this->assertFalse(CreateThumb('/tmp/any.jpg', '/tmp/out.jpg', 0, 100));
            $this->assertFalse(CreateThumb('/tmp/any.jpg', '/tmp/out.jpg', 100, 0));
        }
    }
}
