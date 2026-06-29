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

    public function testIsHttpsRequestReturnsFalseViaBASEURLWhenNoHttpsIndicator(): void
    {
        // BASEURL = 'http://127.0.0.1' (test config) → parse_url scheme = 'http' → false.
        unset($_SERVER['HTTPS']);
        unset($_SERVER['SERVER_PORT']);
        $this->assertFalse(isHttpsRequest());
    }

    public function testIsHttpsRequestReturnsFalseWhenHttpsIsOff(): void
    {
        // HTTPS='off' → strtolower !== 'off' is false → falls through to BASEURL check.
        $_SERVER['HTTPS'] = 'off';
        unset($_SERVER['SERVER_PORT']);
        $this->assertFalse(isHttpsRequest());
        unset($_SERVER['HTTPS']);
    }

    public function testStartSecureSessionIsIdempotentWhenAlreadyActive(): void
    {
        startSecureSession();
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        // Second call hits the early-return guard.
        startSecureSession();
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    public function testDestroySessionCompletelyDoesNothingWhenNotActive(): void
    {
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
        // Should hit the early-return guard (no active session).
        destroySessionCompletely();
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
    }

    public function testStartSecureSessionStartsNamedSession(): void
    {
        startSecureSession();

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertSame('UO_SESSID', session_name());
    }

    public function testRegenerateSessionIdDoesNothingWhenNoActiveSession(): void
    {
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
        regenerateSessionId();
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
    }

    public function testRegenerateSessionIdSucceedsWhenSessionIsActive(): void
    {
        startSecureSession();
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        regenerateSessionId();
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
    }
}
