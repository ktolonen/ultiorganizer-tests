<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class ImageFunctionsLibTest extends TestCase
{
    /** @var string[] files to clean up */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        // database_only to enable GetImage/GetThumb/ImageInfo DB queries
        LegacyApp::loadLibFileUsingProfile('image.functions.php', 'database_only');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->tempFiles = [];
        LegacyApp::closeDatabaseConnection();
    }

    private function tmp(string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'uo_img_') . $suffix;
        $this->tempFiles[] = $path;
        return $path;
    }

    /** Create a real image file of the given type using GD; returns the path. */
    private function makeImage(string $type, int $w = 40, int $h = 30): string
    {
        $img = imagecreatetruecolor($w, $h);
        $color = imagecolorallocate($img, 120, 60, 200);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $color);
        $path = $this->tmp('.' . $type);
        match ($type) {
            'jpg' => imagejpeg($img, $path),
            'png' => imagepng($img, $path),
            'gif' => imagegif($img, $path),
        };
        return $path;
    }

    /**
     * A PNG that carries only the signature and an IHDR chunk. getimagesize() reads the
     * dimensions from it, but the pixel data is missing, so decoding fails.
     */
    private function makeHeaderOnlyPng(int $w, int $h): string
    {
        $ihdr = pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);
        $chunk = pack('N', strlen($ihdr)) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
        $path = $this->tmp('.png');
        file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk);
        return $path;
    }

    // --- CanProcessImages / CanReadImageType ---

    public function testCanProcessImagesReturnsTrueWithGd(): void
    {
        $this->assertTrue(CanProcessImages());
    }

    public function testCanReadImageTypeReturnsFalseForUnknownType(): void
    {
        $this->assertFalse(CanReadImageType(4));
        $this->assertFalse(CanReadImageType(0));
        $this->assertFalse(CanReadImageType(-1));
    }

    public function testCanReadImageTypeReturnsTrueForSupportedTypes(): void
    {
        $this->assertTrue(CanReadImageType(1)); // GIF
        $this->assertTrue(CanReadImageType(2)); // JPEG
        $this->assertTrue(CanReadImageType(3)); // PNG
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

    // --- WriteJpegImage ---

    public function testWriteJpegImageWritesFileFromGdResource(): void
    {
        $img = imagecreatetruecolor(10, 10);
        $dest = $this->tmp('.jpg');
        @unlink($dest); // start clean
        $result = WriteJpegImage($img, $dest);
        $this->assertTrue($result);
        $this->assertFileExists($dest);
        $info = getimagesize($dest);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
    }

    public function testWriteJpegImageReturnsFalseForNonWritableDirectory(): void
    {
        $img = imagecreatetruecolor(10, 10);
        $result = WriteJpegImage($img, '/tmp/uo_nonexistent_dir_xyz/out.jpg');
        $this->assertFalse($result);
    }

    // --- ConvertToJpeg (real conversion for each source type) ---

    public function testConvertToJpegFromPng(): void
    {
        $src = $this->makeImage('png');
        $dst = $this->tmp('.jpg');
        @unlink($dst);
        $this->assertTrue(ConvertToJpeg($src, $dst));
        $this->assertSame(IMAGETYPE_JPEG, getimagesize($dst)[2]);
    }

    public function testConvertToJpegFromJpeg(): void
    {
        $src = $this->makeImage('jpg');
        $dst = $this->tmp('.jpg');
        @unlink($dst);
        $this->assertTrue(ConvertToJpeg($src, $dst));
        $this->assertFileExists($dst);
    }

    public function testConvertToJpegFromGif(): void
    {
        $src = $this->makeImage('gif');
        $dst = $this->tmp('.jpg');
        @unlink($dst);
        $this->assertTrue(ConvertToJpeg($src, $dst));
        $this->assertSame(IMAGETYPE_JPEG, getimagesize($dst)[2]);
    }

    public function testConvertToJpegReturnsFalseForNonImageFile(): void
    {
        $src = $this->tmp('.jpg');
        file_put_contents($src, 'not an image');
        $this->assertFalse(ConvertToJpeg($src, $this->tmp('.jpg')));
    }

    public function testConvertToJpegReturnsFalseForMissingSource(): void
    {
        $this->assertFalse(ConvertToJpeg('/tmp/uo_missing_xyz.png', $this->tmp('.jpg')));
    }

    // --- CreateThumb (real resampling, aspect-ratio branches) ---

    public function testCreateThumbProducesScaledJpeg(): void
    {
        $src = $this->makeImage('png', 80, 40);
        $dst = $this->tmp('.jpg');
        @unlink($dst);
        $this->assertTrue(CreateThumb($src, $dst, 20, 20));
        $info = getimagesize($dst);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        // Wider-than-tall source: width is constrained by aspect ratio
        $this->assertLessThanOrEqual(20, $info[0]);
        $this->assertLessThanOrEqual(20, $info[1]);
    }

    public function testCreateThumbHandlesTallSource(): void
    {
        $src = $this->makeImage('png', 30, 90); // taller than wide
        $dst = $this->tmp('.jpg');
        @unlink($dst);
        $this->assertTrue(CreateThumb($src, $dst, 20, 20));
        $this->assertSame(IMAGETYPE_JPEG, getimagesize($dst)[2]);
    }

    public function testCreateThumbFromJpegAndGifSources(): void
    {
        foreach (['jpg', 'gif'] as $type) {
            $src = $this->makeImage($type, 60, 60);
            $dst = $this->tmp('.jpg');
            @unlink($dst);
            $this->assertTrue(CreateThumb($src, $dst, 25, 25), "type=$type");
        }
    }

    public function testCreateThumbReturnsFalseForZeroDimensions(): void
    {
        $src = $this->makeImage('png');
        $this->assertFalse(CreateThumb($src, $this->tmp('.jpg'), 0, 100));
        $this->assertFalse(CreateThumb($src, $this->tmp('.jpg'), 100, 0));
    }

    public function testCreateThumbReturnsFalseForNonImageFile(): void
    {
        $src = $this->tmp('.png');
        file_put_contents($src, 'garbage');
        $this->assertFalse(CreateThumb($src, $this->tmp('.jpg'), 20, 20));
    }

    public function testCreateThumbReturnsFalseForMissingSource(): void
    {
        $this->assertFalse(CreateThumb('/tmp/uo_missing_xyz.png', $this->tmp('.jpg'), 20, 20));
    }

    public function testConvertToJpegReturnsFalseForUnsupportedImageType(): void
    {
        // BMP (type 6) is recognised by getimagesize but not by CanReadImageType → line 121.
        $src = $this->tmp('.bmp');
        $img = imagecreatetruecolor(4, 4);
        imagebmp($img, $src);
        $this->assertFalse(ConvertToJpeg($src, $this->tmp('.jpg')));
    }

    public function testCreateThumbReturnsFalseForUnsupportedImageType(): void
    {
        // BMP (type 6): getimagesize succeeds, CanReadImageType returns false → line 168.
        $src = $this->tmp('.bmp');
        $img = imagecreatetruecolor(4, 4);
        imagebmp($img, $src);
        $this->assertFalse(CreateThumb($src, $this->tmp('.jpg'), 20, 20));
    }

    // --- RemoveImage ---
    // Non-superadmin branch calls die() — untestable in-process per docs/lib-test-deep-coverage.md.

    public function testRemoveImageAsSuperAdminOnNonExistentIdReturnsTrue(): void
    {
        LegacyApp::loginAsAdmin();
        $result = RemoveImage(999999);
        $this->assertTrue((bool) $result);
    }

    // --- CanDecodeImageSize / ImageSizeError ---

    public function testCanDecodeImageSizeAcceptsDimensionsUpToTheLimits(): void
    {
        $this->assertTrue(CanDecodeImageSize(1, 1));
        $this->assertTrue(CanDecodeImageSize('40', '30'));
        $this->assertTrue(CanDecodeImageSize(MAX_IMAGE_DIMENSION, MAX_IMAGE_PIXELS / MAX_IMAGE_DIMENSION));
    }

    public function testCanDecodeImageSizeRejectsNonPositiveDimensions(): void
    {
        $this->assertFalse(CanDecodeImageSize(0, 10));
        $this->assertFalse(CanDecodeImageSize(10, 0));
        $this->assertFalse(CanDecodeImageSize(-5, 10));
        $this->assertFalse(CanDecodeImageSize(10, -5));
    }

    public function testCanDecodeImageSizeRejectsTooLongSideEvenWhenPixelCountIsSmall(): void
    {
        $this->assertSame(MAX_IMAGE_DIMENSION + 1, 8001);
        $this->assertFalse(CanDecodeImageSize(MAX_IMAGE_DIMENSION + 1, 1));
        $this->assertFalse(CanDecodeImageSize(1, MAX_IMAGE_DIMENSION + 1));
    }

    public function testCanDecodeImageSizeRejectsTooManyPixelsWithinSideLimit(): void
    {
        $this->assertFalse(CanDecodeImageSize(MAX_IMAGE_DIMENSION, MAX_IMAGE_PIXELS / MAX_IMAGE_DIMENSION + 1));
        $this->assertFalse(CanDecodeImageSize(5000, 5000));
    }

    public function testImageSizeErrorIsEmptyForNormalImageAndNonImage(): void
    {
        $this->assertSame('', ImageSizeError($this->makeImage('png')));
        $notImage = $this->tmp('.png');
        file_put_contents($notImage, 'not an image');
        $this->assertSame('', ImageSizeError($notImage));
    }

    public function testImageSizeErrorNamesTheLimitsForOversizedImage(): void
    {
        $error = ImageSizeError($this->makeHeaderOnlyPng(9000, 10));
        $this->assertSame(
            'Image resolution is too large. The maximum is 8000 x 8000 pixels and 16 megapixels.',
            $error
        );
        // Contrast: the same helper accepts an image inside the limits.
        $this->assertSame('', ImageSizeError($this->makeHeaderOnlyPng(8000, 2000)));
    }

    public function testConvertToJpegAndCreateThumbRefuseOversizedImageBeforeDecoding(): void
    {
        $src = $this->makeHeaderOnlyPng(9000, 10);
        $dst = $this->tmp('.jpg');
        $this->assertFalse(ConvertToJpeg($src, $dst));
        $this->assertFalse(CreateThumb($src, $dst, 50, 50));
        $this->assertFileDoesNotExist($dst);
    }

    public function testConvertToJpegAndCreateThumbReturnFalseWhenPixelDataCannotBeDecoded(): void
    {
        $src = $this->makeHeaderOnlyPng(10, 10);
        $dst = $this->tmp('.jpg');
        $this->assertSame([10, 10], array_slice(getimagesize($src), 0, 2), 'precondition: header is readable');
        set_error_handler(static fn() => true);
        try {
            $this->assertFalse(ConvertToJpeg($src, $dst));
            $this->assertFalse(CreateThumb($src, $dst, 5, 5));
        } finally {
            restore_error_handler();
        }
        $this->assertFileDoesNotExist($dst);
    }

    public function testWriteJpegImageReturnsFalseAndLeavesNoTempFileWhenRenameFails(): void
    {
        $dir = sys_get_temp_dir() . '/uo_img_dir_' . uniqid();
        mkdir($dir);
        mkdir($dir . '/target.jpg');
        $img = imagecreatetruecolor(4, 4);
        try {
            $this->assertFalse(WriteJpegImage($img, $dir . '/target.jpg'));
            $this->assertSame(['target.jpg'], array_values(array_diff(scandir($dir), ['.', '..'])));
            $this->assertTrue(is_dir($dir . '/target.jpg'));
        } finally {
            rmdir($dir . '/target.jpg');
            rmdir($dir);
        }
    }
}
