<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * A read-only event locks its games' media links.
 *
 * Adding a game media link goes through CanEditMediaTarget(), which asks
 * hasEditGameEventsRight() and so refuses once the event is read-only.
 * Removing one goes through CanRemoveMediaUrl(), which lets the link's
 * publisher remove it (and, in RemoveMediaUrl(), the game's media events)
 * without looking at the read-only flag. RemoveMediaUrl() die()s on refusal, so
 * the test pins the predicate.
 */
final class GameMediaReadonlyTest extends TestCase
{
    private const GAME = 700;

    private int $urlId = 0;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(
            ['user.functions.php', 'url.functions.php', 'game.functions.php'],
            'pool_stack',
        );
        global $serverConf;
        $serverConf['PersistentCacheEnabled'] = 'false';
        // The fixture user, holding only the responsible team's admin role for game 700.
        $_SESSION['uid'] = 'admin';
        $_SESSION['userproperties']['userrole'] = ['teamadmin' => [300 => 1]];

        $this->urlId = (int) AddMediaUrl([
            'owner' => 'game',
            'owner_id' => self::GAME,
            'type' => 'video',
            'url' => 'http://example.com/harness-readonly-video',
            'ismedialink' => 1,
            'name' => 'Harness video',
            'mediaowner' => '',
        ]);
    }

    protected function tearDown(): void
    {
        DBQuery("UPDATE uo_season SET event_readonly=0 WHERE season_id='HRN2026'");
        self::clearCaches();
        DBQuery(sprintf("DELETE FROM uo_urls WHERE url_id=%d", $this->urlId));
        unset($_SESSION['uid'], $_SESSION['userproperties']);
        LegacyApp::closeDatabaseConnection();
    }

    public function testPublisherCanRemoveTheLinkWhileTheEventIsOpen(): void
    {
        $this->assertGreaterThan(0, $this->urlId, 'the publisher could add the link');
        $this->assertTrue(CanRemoveMediaUrl(GetUrlById($this->urlId)));
    }

    public function testPublisherCannotRemoveTheLinkOnceTheEventIsReadonly(): void
    {
        $this->assertGreaterThan(0, $this->urlId);
        DBQuery("UPDATE uo_season SET event_readonly=1 WHERE season_id='HRN2026'");
        self::clearCaches();

        // Precondition: the lock refuses the same user adding a link.
        $this->assertFalse(CanEditMediaTarget('game', self::GAME));

        $this->assertFalse(CanRemoveMediaUrl(GetUrlById($this->urlId)));
    }

    private static function clearCaches(): void
    {
        ClearSeasonRuntimeCache();
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            CacheForgetPersistent($ns);
            CacheForgetNamespace($ns);
        }
    }
}
