<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class ViewGuardLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('view.guard.php', 'bootstrap_only');
    }

    // --- routedViewFromScript ---

    public function testRoutedViewFromScriptExtractsAdminPath(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/admin/edit.php';
        $this->assertSame('admin/edit', routedViewFromScript('/nonexistent'));
    }

    public function testRoutedViewFromScriptExtractsUserPath(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/user/profile.php';
        $this->assertSame('user/profile', routedViewFromScript('/nonexistent'));
    }

    public function testRoutedViewFromScriptFindsAdminSegmentAnywhere(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/some/prefix/admin/manage.php';
        $this->assertSame('admin/manage', routedViewFromScript('/nonexistent'));
    }

    public function testRoutedViewFromScriptReturnsCleanedPathWhenNoKnownRoot(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/other/path.php';
        $this->assertSame('other/path', routedViewFromScript('/nonexistent'));
    }

    public function testRoutedViewFromScriptStripsLeadingSlash(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $this->assertSame('index', routedViewFromScript('/nonexistent'));
    }

    public function testRoutedViewFromScriptHandlesNestedAdminPath(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/admin/season/edit.php';
        $this->assertSame('admin/season/edit', routedViewFromScript('/nonexistent'));
    }

    // --- requireRoutedView ---

    public function testRequireRoutedViewReturnsWhenConstantIsDefined(): void
    {
        // When UO_ROUTED_VIEW is already defined the guard is satisfied and
        // the function must return without calling header()/exit().
        if (!defined('UO_ROUTED_VIEW')) {
            define('UO_ROUTED_VIEW', true);
        }
        requireRoutedView('admin/frontpage');
        $this->assertTrue(true); // reached here — no exit occurred
    }
}
