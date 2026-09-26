<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Win/loss counts for drawn and forfeited games.
 *
 * docs/ranking.md: a forfeit is recorded at 0-0 and uo_game.forfeit awards the
 * result (1 home forfeited, 2 away forfeited, 3 both lose); games with
 * forfeit=0 count from the score, and a drawn game is neither a win nor a loss.
 * TeamStatsByPool(), which the pool standings table uses, follows that and is
 * the reference here. Two other counters do not:
 *
 * - SeriesTeamStatsPoints() (the division statistics page) counts by score only,
 *   so a 0-0 forfeit is a draw;
 * - CalcTeamStats() (archiving an event) counts any non-win of the HOME team as
 *   a loss, so a draw is a home loss but not an away loss, and a 0-0 forfeit won
 *   by the home team is a home loss.
 *
 * Fixture: game 700 is 300 (home) 15-11 301; game 701 is 301 (home) v 300, not
 * played. Both are in pool 200 of series 100.
 */
final class DrawAndForfeitStatisticsTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(
            ['user.functions.php', 'configuration.functions.php', 'standings.functions.php', 'statistical.functions.php'],
            'team_stack',
        );
        global $serverConf;
        $serverConf['PersistentCacheEnabled'] = 'false';
        $_SESSION['uid'] = 'admin';
        $_SESSION['userproperties']['userrole']['superadmin'] = true;
    }

    protected function tearDown(): void
    {
        DBQuery("UPDATE uo_game SET homescore=15, visitorscore=11, forfeit=0, isongoing=0, hasstarted=1 WHERE game_id=700");
        DBQuery("UPDATE uo_game SET homescore=NULL, visitorscore=NULL, forfeit=0, isongoing=0, hasstarted=0 WHERE game_id=701");
        DBQuery("UPDATE uo_team_stats SET season='HRN2026', series=100, goals_made=15, goals_against=11, standing=1, wins=1, losses=0, defenses_total=0 WHERE team_id=300");
        DBQuery("UPDATE uo_team_stats SET season='HRN2026', series=100, goals_made=11, goals_against=15, standing=2, wins=0, losses=1, defenses_total=0 WHERE team_id=301");
        self::clearCaches();
        unset($_SESSION['uid'], $_SESSION['userproperties']);
        LegacyApp::closeDatabaseConnection();
    }

    public function testDivisionStatisticsCountAForfeitLikeThePoolStandings(): void
    {
        // Home team 300 forfeits game 700 at 0-0.
        DBQuery("UPDATE uo_game SET homescore=0, visitorscore=0, forfeit=1 WHERE game_id=700");
        self::clearCaches();

        // Reference: the pool standings count the forfeit as 301's win.
        $this->assertEquals(1, TeamStatsByPool(200, 301)['wins']);
        $this->assertEquals(1, TeamStatsByPool(200, 300)['losses']);

        $series = SeriesTeamStatsPoints(100);
        $this->assertEquals(1, $series[301]['wins']);
        $this->assertEquals(1, $series[300]['losses']);
    }

    public function testArchivedStatisticsCountADrawAsNeitherWinNorLoss(): void
    {
        // Game 701, home team 301, ends 10-10.
        DBQuery("UPDATE uo_game SET homescore=10, visitorscore=10, isongoing=0, hasstarted=2 WHERE game_id=701");
        self::clearCaches();

        CalcTeamStats('HRN2026');

        // Away side of the draw: 300 keeps its one win and no loss.
        $this->assertSame(['1', '0'], self::archivedWinsLosses(300));
        // Home side of the draw: 301 keeps only its game-700 loss.
        $this->assertSame(['0', '1'], self::archivedWinsLosses(301));
    }

    public function testArchivedStatisticsCountAHomeForfeitWinAsAWin(): void
    {
        // The away team 301 forfeits game 700 at 0-0: home team 300 wins.
        DBQuery("UPDATE uo_game SET homescore=0, visitorscore=0, forfeit=2 WHERE game_id=700");
        self::clearCaches();

        // Reference: the pool standings agree.
        $this->assertEquals(1, TeamStatsByPool(200, 300)['wins']);

        CalcTeamStats('HRN2026');

        $this->assertSame(['1', '0'], self::archivedWinsLosses(300));
        $this->assertSame(['0', '1'], self::archivedWinsLosses(301));
    }

    /** @return array{0: string, 1: string} */
    private static function archivedWinsLosses(int $teamId): array
    {
        $row = DBQueryToRow(sprintf("SELECT wins, losses FROM uo_team_stats WHERE team_id=%d", $teamId));
        return [(string) $row['wins'], (string) $row['losses']];
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
