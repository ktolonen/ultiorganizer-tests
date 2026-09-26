<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * GameAddPlayer() and players who are not on either team of the game.
 *
 * The roster pages (user/, mobile/, scorekeeper/addplayerlists.php) pass every
 * posted player id to GameAddPlayer(), which checks the caller's rights on the
 * game but not that the player belongs to one of its teams. It then also
 * writes the posted jersey number onto the player's own uo_player row. A team
 * admin can so put another event's player on their game's roster and change
 * that player's number.
 *
 * The test seeds a second event with a team and a player, and calls the
 * mutator as the admin of fixture game 700's responsible team (300).
 */
final class GameRosterForeignPlayerTest extends TestCase
{
    private const GAME = 700;
    private const OTHER_SEASON = 'HRNOTHER';
    private const OTHER_SERIES = 120;
    private const OTHER_TEAM = 320;
    private const OTHER_PLAYER = 820;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(['user.functions.php', 'game.functions.php'], 'pool_stack');
        global $serverConf;
        $serverConf['PersistentCacheEnabled'] = 'false';
        self::cleanUp();
        DBQuery(sprintf(
            "INSERT INTO uo_season (season_id, name, starttime, endtime, iscurrent, enrollopen, type,
                istournament, isinternational, isnationalteams, organizer, category, showspiritpoints,
                use_season_points, hide_time_on_scoresheet, event_readonly, api_public, public_event,
                timezone, spiritmode)
             VALUES ('%s', 'Other Cup', '2025-06-01 09:00:00', '2025-06-02 18:00:00', 0, 0, 'outdoor',
                1, 0, 0, 'Harness Org', 'test', 0, 0, 0, 0, 0, 1, 'Europe/Helsinki', 1003)",
            self::OTHER_SEASON,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_series (series_id, name, ordering, season, valid, type, color, pool_template)
             VALUES (%d, 'Other Open', 'A', '%s', 1, 'open', '336699', NULL)",
            self::OTHER_SERIES,
            self::OTHER_SEASON,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_team (team_id, name, pool, club, rank, activerank, valid, series, country, abbreviation)
             VALUES (%d, 'Other Team', NULL, NULL, 1, 1, 1, %d, NULL, 'OTH')",
            self::OTHER_TEAM,
            self::OTHER_SERIES,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_player (player_id, firstname, lastname, team, num, accredited)
             VALUES (%d, 'Olli', 'Other', %d, 21, 1)",
            self::OTHER_PLAYER,
            self::OTHER_TEAM,
        ));
        self::clearCaches();

        $_SESSION['uid'] = 'admin';
        $_SESSION['userproperties']['userrole'] = ['teamadmin' => [300 => 1]];
    }

    protected function tearDown(): void
    {
        DBQuery("UPDATE uo_player SET num=8 WHERE player_id=800");
        DBQuery("UPDATE uo_played SET num=8 WHERE player=800 AND game=700");
        self::cleanUp();
        self::clearCaches();
        unset($_SESSION['uid'], $_SESSION['userproperties']);
        LegacyApp::closeDatabaseConnection();
    }

    public function testOwnTeamsPlayerCanBeRostered(): void
    {
        // Contrast: the caller does hold roster rights on the game.
        $this->assertTrue(hasEditGamePlayersRight(self::GAME));
        $this->assertNotFalse(GameAddPlayer(self::GAME, 800, 9));
        $this->assertSame('9', self::number(800));
    }

    public function testAnotherEventsPlayerCannotBeRostered(): void
    {
        $result = GameAddPlayer(self::GAME, self::OTHER_PLAYER, 55);

        $this->assertFalse($result);
        $this->assertSame('21', self::number(self::OTHER_PLAYER), "the other event's player was renumbered");
        $this->assertSame('0', (string) DBQueryToValueUncached(sprintf(
            "SELECT COUNT(*) FROM uo_played WHERE game=%d AND player=%d",
            self::GAME,
            self::OTHER_PLAYER,
        )));
    }

    private static function number(int $playerId): string
    {
        return (string) DBQueryToValueUncached(sprintf("SELECT num FROM uo_player WHERE player_id=%d", $playerId));
    }

    private static function cleanUp(): void
    {
        DBQuery(sprintf("DELETE FROM uo_played WHERE player=%d", self::OTHER_PLAYER));
        DBQuery(sprintf("DELETE FROM uo_scoresheet_history WHERE game=%d", self::GAME));
        DBQuery(sprintf("DELETE FROM uo_player WHERE player_id=%d", self::OTHER_PLAYER));
        DBQuery(sprintf("DELETE FROM uo_team WHERE team_id=%d", self::OTHER_TEAM));
        DBQuery(sprintf("DELETE FROM uo_series WHERE series_id=%d", self::OTHER_SERIES));
        DBQuery(sprintf("DELETE FROM uo_season WHERE season_id='%s'", self::OTHER_SEASON));
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
