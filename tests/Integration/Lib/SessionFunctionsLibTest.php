<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class SessionFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('session.functions.php', 'bootstrap_only');
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            destroySessionCompletely();
        }
        LegacyApp::resetRequestState();
    }

    public function testIsHttpsRequestDetectsHttpsServerFlagAndBaseUrl(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue(isHttpsRequest());

        unset($_SERVER['HTTPS']);
        $_SERVER['SERVER_PORT'] = 443;
        $this->assertTrue(isHttpsRequest());
    }

    public function testStartSecureSessionStartsNamedSession(): void
    {
        startSecureSession();

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertSame('UO_SESSID', session_name());
    }
}
