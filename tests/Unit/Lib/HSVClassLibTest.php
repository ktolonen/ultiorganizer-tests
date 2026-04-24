<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class HSVClassLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('HSVClass.php', 'bootstrap_only');
    }

    public function testConvertRgbToHsvAndBackPreservesPrimaryColor(): void
    {
        $hsv = convertRGBtoHSV(['r' => 255, 'g' => 0, 'b' => 0]);
        $rgb = convertHSVtoRGB($hsv);

        $this->assertSame(0.0, (float) $hsv['h']);
        $this->assertSame(1.0, (float) $hsv['s']);
        $this->assertSame(1.0, (float) $hsv['v']);
        $this->assertSame(255.0, (float) $rgb['r']);
        $this->assertSame(0.0, (float) $rgb['g']);
        $this->assertSame(0.0, (float) $rgb['b']);
    }

    public function testColorObjectWrapsBrightnessAndReturnsUppercaseRgbString(): void
    {
        $color = new HSVClass(240, 1.0, 0.9);
        $color->changeBrightness(0.3);

        $hsv = $color->getHSV();

        $this->assertSame(240, $hsv['h']);
        $this->assertSame(1.0, $hsv['s']);
        $this->assertEqualsWithDelta(0.2, $hsv['v'], 0.000001);
        $this->assertSame('000032', $color->getRGBString());
    }
}
