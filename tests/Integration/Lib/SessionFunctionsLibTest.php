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
        $this->assertSame(sessionCookieName(), session_name());
    }

    public function testSessionCookieNameIsDerivedWhenUnconfigured(): void
    {
        // UO_SESSION_NAME is optional. While it is undefined the name is derived
        // from the installation directory, so co-hosted installations differ
        // without anyone configuring them, and it must stay valid for
        // session_name(): letters, digits, '-' and '_' only, and not all digits.
        if (defined('UO_SESSION_NAME')) {
            $this->markTestSkipped('This case configures UO_SESSION_NAME, so no name is derived.');
        }

        $name = sessionCookieName();

        $this->assertStringStartsWith('UO_SESSID_', $name);
        $this->assertNotSame('UO_SESSID_', $name);
        $this->assertDoesNotMatchRegularExpression('/[^A-Za-z0-9_-]/', $name);
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

    public function testSessionInstanceFingerprintIsStableHexAndFollowsInstallationValues(): void
    {
        $fingerprint = sessionInstanceFingerprint();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $fingerprint);
        $this->assertSame($fingerprint, sessionInstanceFingerprint());
        $parts = [
            defined('DB_HOST') ? (string) DB_HOST : '',
            defined('DB_DATABASE') ? (string) DB_DATABASE : '',
            defined('BASEURL') ? (string) BASEURL : '',
            sessionCookieName(),
        ];
        $this->assertSame(substr(hash('sha256', implode('|', $parts)), 0, 32), $fingerprint);
    }

    public function testEnforceSessionInstanceBindingAdoptsSessionWithoutFingerprint(): void
    {
        startSecureSession();
        unset($_SESSION['__uo_instance']);
        $_SESSION['uid'] = 'keep-me';
        $idBefore = session_id();

        enforceSessionInstanceBinding();

        $this->assertSame(sessionInstanceFingerprint(), $_SESSION['__uo_instance']);
        $this->assertSame('keep-me', $_SESSION['uid'], 'adoption must not discard existing data');
        $this->assertSame($idBefore, session_id());
    }

    public function testEnforceSessionInstanceBindingKeepsSessionWithMatchingFingerprint(): void
    {
        startSecureSession();
        $_SESSION['__uo_instance'] = sessionInstanceFingerprint();
        $_SESSION['uid'] = 'keep-me';
        $idBefore = session_id();

        enforceSessionInstanceBinding();

        $this->assertSame('keep-me', $_SESSION['uid']);
        $this->assertSame($idBefore, session_id());
    }

    public function testEnforceSessionInstanceBindingStartsFreshSessionForForeignFingerprint(): void
    {
        startSecureSession();
        $_SESSION['__uo_instance'] = str_repeat('f', 32);
        $_SESSION['uid'] = 'foreign-user';
        $idBefore = session_id();

        enforceSessionInstanceBinding();

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertNotSame($idBefore, session_id());
        $this->assertSame(sessionInstanceFingerprint(), $_SESSION['__uo_instance']);
        $this->assertArrayNotHasKey('uid', $_SESSION, 'foreign session data must not leak into the new session');
    }
}
