<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class SessionFunctionsLibTest extends TestCase
{
    /** @var array Original $_SERVER state restored after each test */
    private array $originalServer;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('session.functions.php', 'bootstrap_only');
        $this->originalServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
    }

    // --- isHttpsRequest ---

    public function testIsHttpsRequestReturnsTrueWhenHttpsIsOn(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue(isHttpsRequest());
    }

    public function testIsHttpsRequestReturnsTrueWhenHttpsIs1(): void
    {
        $_SERVER['HTTPS'] = '1';
        $this->assertTrue(isHttpsRequest());
    }

    public function testIsHttpsRequestReturnsFalseWhenHttpsIsOff(): void
    {
        $_SERVER['HTTPS'] = 'off';
        unset($_SERVER['SERVER_PORT']);
        $this->assertFalse(isHttpsRequest());
    }

    public function testIsHttpsRequestReturnsTrueWhenPortIs443(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['SERVER_PORT'] = 443;
        $this->assertTrue(isHttpsRequest());
    }

    public function testIsHttpsRequestReturnsFalseWhenPortIs80(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['SERVER_PORT'] = 80;
        $this->assertFalse(isHttpsRequest());
    }

    public function testIsHttpsRequestReturnsFalseWithNoHttpsIndicators(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['SERVER_PORT']);
        $this->assertFalse(isHttpsRequest());
    }
}
