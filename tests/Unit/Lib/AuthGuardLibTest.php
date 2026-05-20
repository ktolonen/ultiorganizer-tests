<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Tests for auth.guard.php.
 *
 * The guard file runs procedural code at include-time: it starts a session,
 * sets $_SESSION['uid'] = 'anonymous' if unset, then redirects + exits when
 * isLoggedIn() is false. Because exit() terminates the process, the redirect
 * path cannot be exercised in a PHPUnit test. These tests cover the pass-
 * through path (non-anonymous uid) and the isLoggedIn() contract.
 */
final class AuthGuardLibTest extends TestCase
{
    protected static bool $guardLoaded = false;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
    }

    private function ensureGuardLoaded(): void
    {
        if (self::$guardLoaded) {
            return;
        }
        // isLoggedIn() only reads $_SESSION — no DB needed.
        // Load its dependencies first so they are defined before the guard runs.
        LegacyApp::loadLibFileUsingProfile('session.functions.php', 'bootstrap_only');
        LegacyApp::loadLibFileUsingProfile('user.functions.php', 'bootstrap_only');
        if (session_status() !== PHP_SESSION_ACTIVE) {
            startSecureSession();
        }
        // Set a non-anonymous uid so the guard does not redirect on load.
        $_SESSION['uid'] = 'admin';
        LegacyApp::loadLibFileUsingProfile('auth.guard.php', 'bootstrap_only');
        self::$guardLoaded = true;
    }

    public function testGuardPassesThroughWhenSessionHasNonAnonymousUid(): void
    {
        $this->ensureGuardLoaded();
        // If we reached here the guard did not call exit().
        $this->assertTrue(true);
    }

    public function testIsLoggedInReturnsTrueForNonAnonymousUid(): void
    {
        $this->ensureGuardLoaded();
        $_SESSION['uid'] = 'someuser';
        $this->assertTrue(isLoggedIn());
    }

    public function testIsLoggedInReturnsFalseForAnonymousUid(): void
    {
        $this->ensureGuardLoaded();
        $_SESSION['uid'] = 'anonymous';
        $this->assertFalse(isLoggedIn());
    }

    public function testIsLoggedInReturnsFalseWhenUidNotSet(): void
    {
        $this->ensureGuardLoaded();
        unset($_SESSION['uid']);
        $this->assertFalse(isLoggedIn());
    }
}
