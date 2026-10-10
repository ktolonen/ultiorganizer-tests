<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Score display helpers in lib/game.functions.php: the public game list and
 * score line, the atomic score tap, and the conditional final save.
 *
 * Fixture game 700 is played (15 - 11) and game 701 is unplayed, both in pool
 * 200 of event HRN2026 (Europe/Helsinki). setUp() moves both to today in the
 * event's timezone; tearDown() restores the fixture rows.
 */
final class ScoreDisplayLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(
            ['user.functions.php', 'scoresheethistory.functions.php', 'timetable.functions.php', 'game.functions.php'],
            'pool_stack',
        );
        global $serverConf;
        if (!isset($serverConf)) {
            $serverConf = [];
        }
        $serverConf['PersistentCacheEnabled'] = 'false';
        self::restore();
        DBQuery("UPDATE uo_game SET time='" . self::today() . " 14:00:00' WHERE game_id=701");
        self::asSuperadmin();
    }

    protected function tearDown(): void
    {
        self::restore();
        LegacyApp::closeDatabaseConnection();
    }

    // --- ScoreDisplayGames / ScoreDisplayGame ---

    public function testUpcomingGameTodayIsListedAndPlayedGameIsNot(): void
    {
        $ids = self::listedIds();

        $this->assertContains(701, $ids);
        $this->assertNotContains(700, $ids);
    }

    public function testOngoingGamesComeBeforeUpcomingOnes(): void
    {
        DBQuery("UPDATE uo_game SET time='" . self::today() . " 09:00:00' WHERE game_id=701");
        DBQuery("UPDATE uo_game SET isongoing=1, time='" . self::today() . " 18:00:00' WHERE game_id=700");

        $this->assertSame([700, 701], self::listedIds());
    }

    public function testGameOnAnotherDayIsNotListed(): void
    {
        DBQuery("UPDATE uo_game SET time='" . self::day('+1 day') . " 10:00:00' WHERE game_id=701");

        $this->assertNotContains(701, self::listedIds());
    }

    public function testListedGameCarriesTeamNamesAndPlace(): void
    {
        $row = self::listed(701);

        $this->assertSame('Tampere Tempest', $row['home']);
        $this->assertSame('Helsinki Heat', $row['visitor']);
        $this->assertSame('Harness Field Complex', $row['placename']);
    }

    public function testGameWithoutTeamsShowsItsPlaceholderNames(): void
    {
        DBQuery("UPDATE uo_game SET hometeam=NULL, visitorteam=NULL, scheduling_name_home=600, scheduling_name_visitor=601 WHERE game_id=701");

        $row = self::listed(701);

        $this->assertSame('Round 1', $row['home']);
        $this->assertSame('Round 2', $row['visitor']);
    }

    /** @return array<string, array{0: string}> */
    public static function hiddenEventProvider(): array
    {
        return [
            'pool hidden from the timetable' => ["UPDATE uo_pool SET visible=0 WHERE pool_id=200"],
            'game not on the timetable' => ["UPDATE uo_game_pool SET timetable=0 WHERE game=701"],
            'event not public' => ["UPDATE uo_season SET public_event=0 WHERE season_id='HRN2026'"],
            'event in maintenance' => ["UPDATE uo_season SET maintenance_mode=1 WHERE season_id='HRN2026'"],
            'game invalid' => ["UPDATE uo_game SET valid=0 WHERE game_id=701"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hiddenEventProvider')]
    public function testHiddenGameIsNeitherListedNorShown(string $hide): void
    {
        $this->assertContains(701, self::listedIds());
        $this->assertNotNull(ScoreDisplayGame(701));

        DBQuery($hide);

        $this->assertNotContains(701, self::listedIds());
        $this->assertNull(ScoreDisplayGame(701));
    }

    public function testScoreLineOfAKnownGame(): void
    {
        $row = ScoreDisplayGame(700);

        $this->assertSame(700, (int) $row['game_id']);
        $this->assertSame(15, (int) $row['homescore']);
        $this->assertSame(11, (int) $row['visitorscore']);
        $this->assertSame('Helsinki Heat', $row['home']);
        $this->assertSame('Tampere Tempest', $row['visitor']);
    }

    public function testUnknownGameHasNoScoreLine(): void
    {
        $this->assertNull(ScoreDisplayGame(999999));
    }

    // --- GameApplyScoreTap ---

    public function testFirstTapStartsTheGame(): void
    {
        $this->assertTrue(GameApplyScoreTap(701, true, 1));

        $this->assertSame([1, 0, 1, 1], self::state());
    }

    public function testTapsAccumulatePerTeam(): void
    {
        GameApplyScoreTap(701, true, 1);
        GameApplyScoreTap(701, true, 1);
        GameApplyScoreTap(701, false, 1);

        $this->assertSame([2, 1, 1, 1], self::state());
    }

    public function testUndoingTheOnlyPointReturnsTheGameToNotStarted(): void
    {
        GameApplyScoreTap(701, true, 1);

        $this->assertTrue(GameApplyScoreTap(701, true, -1));

        $row = DBQueryToRowUncached("SELECT homescore, visitorscore, isongoing, hasstarted FROM uo_game WHERE game_id=701");
        $this->assertNull($row['homescore']);
        $this->assertNull($row['visitorscore']);
        $this->assertSame([0, 0], [(int) $row['isongoing'], (int) $row['hasstarted']]);
    }

    public function testUndoingBackToZeroKeepsTheGameOngoingWhileTheClockRuns(): void
    {
        GameApplyScoreTap(701, true, 1);
        DBQuery("UPDATE uo_game SET timer_start=" . time() . " WHERE game_id=701");

        $this->assertTrue(GameApplyScoreTap(701, true, -1));

        $this->assertSame([0, 0, 1, 1], self::state());
    }

    public function testTapCannotTakeAScoreBelowZero(): void
    {
        GameApplyScoreTap(701, true, 1);

        $this->assertFalse(GameApplyScoreTap(701, false, -1));

        $this->assertSame([1, 0, 1, 1], self::state());
    }

    public function testTapCannotRaiseAScorePastTheMaximum(): void
    {
        DBQuery("UPDATE uo_game SET homescore=" . MAX_GAME_SCORE . ", visitorscore=3, isongoing=1, hasstarted=1 WHERE game_id=701");

        $this->assertFalse(GameApplyScoreTap(701, true, 1));

        $this->assertSame([MAX_GAME_SCORE, 3, 1, 1], self::state());
    }

    public function testTapLeavesAFinalResultAlone(): void
    {
        $this->assertFalse(GameApplyScoreTap(700, true, 1));
        $this->assertFalse(GameApplyScoreTap(700, false, -1));

        $row = DBQueryToRowUncached("SELECT homescore, visitorscore, hasstarted FROM uo_game WHERE game_id=700");
        $this->assertSame([15, 11, 1], array_map('intval', array_values($row)));
    }

    public function testTapRecordsAnUpdateInTheScoresheetHistory(): void
    {
        DBQuery("DELETE FROM uo_setting WHERE name='DisableScoresheetHistory'");
        DBQuery("DELETE FROM uo_scoresheet_history WHERE game=701");

        GameApplyScoreTap(701, true, 1);

        $this->assertGreaterThan(
            0,
            (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_scoresheet_history WHERE game=701"),
        );
    }

    // --- GameSetResult with an expected score ---

    public function testSetResultSavesWhileTheStoredScoreMatches(): void
    {
        DBQuery("UPDATE uo_game SET homescore=3, visitorscore=2, isongoing=1, hasstarted=1 WHERE game_id=701");

        $this->assertTrue(GameSetResult(701, 13, 9, false, [3, 2]));

        $this->assertSame([13, 9, 0, 2], self::state());
        $this->assertSame(1, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_event_log WHERE category='game' AND id1=701 AND description LIKE 'result: %'"));
    }

    public function testSetResultIsRefusedWhenAPointLandedMeanwhile(): void
    {
        DBQuery("UPDATE uo_game SET homescore=4, visitorscore=2, isongoing=1, hasstarted=1 WHERE game_id=701");

        $this->assertFalse(GameSetResult(701, 13, 9, false, [3, 2]));

        $this->assertSame([4, 2, 1, 1], self::state());
    }

    public function testSetResultExpectingNoScoreMatchesAnUnstartedGame(): void
    {
        $this->assertTrue(GameSetResult(701, 13, 9, false, [null, null]));
        $this->assertSame([13, 9, 0, 2], self::state());
    }

    public function testSetResultExpectingNoScoreIsRefusedOnceAPointExists(): void
    {
        GameApplyScoreTap(701, true, 1);

        $this->assertFalse(GameSetResult(701, 13, 9, false, [null, null]));
        $this->assertSame([1, 0, 1, 1], self::state());
    }

    public function testRefusedSetResultWritesNoResultToTheLog(): void
    {
        DBQuery("UPDATE uo_game SET homescore=4, visitorscore=2, isongoing=1, hasstarted=1 WHERE game_id=701");
        $before = (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_event_log WHERE category='game' AND id1=701 AND description LIKE 'result: %'");

        GameSetResult(701, 13, 9, false, [3, 2]);

        $after = (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_event_log WHERE category='game' AND id1=701 AND description LIKE 'result: %'");
        $this->assertSame($before, $after);
    }

    // --- helpers ---

    /** @return array<int, array<string, mixed>> */
    private static function listed(int $gameId): array
    {
        foreach (ScoreDisplayGames() as $row) {
            if ((int) $row['game_id'] === $gameId) {
                return $row;
            }
        }
        self::fail("game $gameId is not listed");
    }

    /** @return int[] */
    private static function listedIds(): array
    {
        return array_map(static fn($row) => (int) $row['game_id'], ScoreDisplayGames());
    }

    /** @return int[] home score, away score, isongoing, hasstarted of game 701 */
    private static function state(): array
    {
        $row = DBQueryToRowUncached("SELECT homescore, visitorscore, isongoing, hasstarted FROM uo_game WHERE game_id=701");
        return array_map('intval', array_values($row));
    }

    private static function restore(): void
    {
        DBQuery("UPDATE uo_pool SET visible=1 WHERE pool_id=200");
        DBQuery("UPDATE uo_game_pool SET timetable=1 WHERE game IN (700, 701)");
        DBQuery("UPDATE uo_season SET public_event=1, maintenance_mode=0 WHERE season_id='HRN2026'");
        DBQuery("UPDATE uo_game SET valid=1, time='2026-06-01 10:00:00' WHERE game_id=700");
        DBQuery("UPDATE uo_game SET homescore=15, visitorscore=11, isongoing=0, hasstarted=1, timer_start=NULL, timer_pause_start=NULL, timer_paused_duration=0 WHERE game_id=700");
        DBQuery("UPDATE uo_game SET valid=1, hometeam=301, visitorteam=300, scheduling_name_home=NULL, scheduling_name_visitor=NULL, time='2026-06-01 14:00:00', homescore=NULL, visitorscore=NULL, isongoing=0, hasstarted=0, timer_start=NULL, timer_pause_start=NULL, timer_paused_duration=0 WHERE game_id=701");
        DBQuery("DELETE FROM uo_scoresheet_history WHERE game IN (700, 701)");
        DBQuery("DELETE FROM uo_event_log WHERE category='game' AND id1=701");
        self::flushCaches();
    }

    private static function today(): string
    {
        return self::day('now');
    }

    private static function day(string $modifier): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Helsinki')))->modify($modifier)->format('Y-m-d');
    }

    private static function asSuperadmin(): void
    {
        $_SESSION = ['uid' => 'testuser', 'userproperties' => ['userrole' => ['superadmin' => true]]];
        self::flushCaches();
    }

    private static function flushCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            if (function_exists('CacheForgetPersistent')) {
                CacheForgetPersistent($ns);
            }
        }
        foreach (['scorekeeper_grant', 'scoresheet_history_snapshot'] as $ns) {
            CacheForgetNamespace($ns);
        }
        $GLOBALS['runtime_cache'] = [];
    }
}
