<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Anonymous access to a private event's pages.
 *
 * index.php runs EnforcePrivateEventAccessForView(), which asks
 * MaintenanceSeasonFromView() for the event behind the request. That helper
 * returns the event of the first id parameter it finds, checking `season`
 * before `team`, `player` and the rest, so a request for a private event's
 * team that also names a public event gets checked against the public one.
 *
 * The fixture has only a public event, so the test seeds a private one with a
 * team and a player. Assertions are locale-independent (status codes, the
 * seeded names).
 *
 * Apache's persistent query cache lives in a www-data-only directory the test
 * process cannot clear, so the test turns the cache off for its duration
 * rather than risk a season row cached before a visibility flip.
 */
final class PrivateEventAccessTest extends TestCase
{
    private const SEASON = 'HRNPRIV';
    private const SERIES = 110;
    private const TEAM = 310;
    private const PLAYER = 810;
    private const POOL = 210;
    private const PROFILE = 9810;
    private const GAME = 710;
    private const TEAM_NAME = 'Secret Squad';
    private const PLAYER_NAME = 'Hidden';

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([], 'database_with_common');
        DBQuery("UPDATE uo_setting SET value='false' WHERE name='PersistentCacheEnabled'");
        self::cleanUp();
        DBQuery(sprintf(
            "INSERT INTO uo_season (season_id, name, starttime, endtime, iscurrent, enrollopen, type,
                istournament, isinternational, isnationalteams, organizer, category, showspiritpoints,
                use_season_points, hide_time_on_scoresheet, event_readonly, api_public, public_event,
                timezone, spiritmode)
             VALUES ('%s', 'Private Cup', '2025-06-01 09:00:00', '2025-06-02 18:00:00', 0, 0, 'outdoor',
                1, 0, 0, 'Harness Org', 'test', 0, 0, 0, 0, 0, 0, 'Europe/Helsinki', 1003)",
            self::SEASON,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_series (series_id, name, ordering, season, valid, type, color, pool_template)
             VALUES (%d, 'Private Open', 'A', '%s', 1, 'open', '336699', NULL)",
            self::SERIES,
            self::SEASON,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_team (team_id, name, pool, club, rank, activerank, valid, series, country, abbreviation)
             VALUES (%d, '%s', NULL, NULL, 1, 1, 1, %d, NULL, 'SEC')",
            self::TEAM,
            self::TEAM_NAME,
            self::SERIES,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_player (player_id, firstname, lastname, team, num, accredited)
             VALUES (%d, 'Very', '%s', %d, 99, 1)",
            self::PLAYER,
            self::PLAYER_NAME,
            self::TEAM,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_pool (pool_id, name, ordering, visible, continuingpool, placementpool, teams, mvgames,
                timeoutlen, halftime, winningscore, timecap, scorecap, played, addscore, halftimescore, timeouts,
                timeoutsper, timeoutsovertime, timeoutstimecap, betweenpointslen, series, type, timeslot, color,
                forfeitscore, forfeitagainst, follower, drawsallowed, playoff_template)
             VALUES (%d, 'Private Pool', '1', 1, 0, 0, 2, 0, 70, 35, 15, NULL, NULL, 1, NULL, NULL, 2, 'half',
                1, 'soft', 90, %d, 1, NULL, '336699', 15, 0, NULL, 0, NULL)",
            self::POOL,
            self::SERIES,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_game (game_id, hometeam, visitorteam, reservation, time, valid, isongoing, hasstarted)
             VALUES (%d, %d, %d, 500, '2026-06-01 12:00:00', 1, 0, 0)",
            self::GAME,
            self::TEAM,
            self::TEAM,
        ));
        DBQuery(sprintf("INSERT INTO uo_game_pool (game, pool, timetable) VALUES (%d, %d, 1)", self::GAME, self::POOL));
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        DBQuery("UPDATE uo_setting SET value='true' WHERE name='PersistentCacheEnabled'");
        LegacyApp::closeDatabaseConnection();
    }

    public function testPrivateTeamCardRedirectsAnonymousVisitors(): void
    {
        // Precondition: the gate recognises the seeded event as private.
        [$status, $body] = self::get('/index.php?view=teamcard&team=' . self::TEAM);

        $this->assertSame(302, $status);
        $this->assertStringNotContainsString(self::TEAM_NAME, $body);
    }

    public function testPublicTeamCardStillRendersWithASeasonParameter(): void
    {
        // Contrast: the season parameter itself is harmless on a public team.
        [$status, $body] = self::get('/index.php?view=teamcard&team=300&season=HRN2026');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Helsinki Heat', $body);
    }

    public function testNamingAPublicEventDoesNotOpenAPrivateTeamCard(): void
    {
        [$status, $body] = self::get('/index.php?view=teamcard&team=' . self::TEAM . '&season=HRN2026');

        $this->assertSame(302, $status);
        $this->assertStringNotContainsString(self::TEAM_NAME, $body);
    }

    public function testNamingAPublicEventDoesNotOpenAPrivatePlayerCard(): void
    {
        [$status, $body] = self::get('/index.php?view=playercard&player=' . self::PLAYER . '&season=HRN2026');

        $this->assertSame(302, $status);
        $this->assertStringNotContainsString(self::PLAYER_NAME, $body);
    }

    public function testPrivatePoolScheduleRedirectsAnonymousVisitors(): void
    {
        // Precondition for the pools case below.
        [$status, $body] = self::get('/index.php?view=games&pools=' . self::POOL);

        $this->assertSame(302, $status);
        $this->assertStringNotContainsString(self::TEAM_NAME, $body);
    }

    public function testListingAPublicPoolFirstDoesNotOpenAPrivatePoolSchedule(): void
    {
        [$status, $body] = self::get('/index.php?view=games&pools=200,' . self::POOL);

        $this->assertStringNotContainsString(self::TEAM_NAME, $body, 'status ' . $status);
    }

    public function testAllPlayersListLeavesOutPlayersOfAPrivateEvent(): void
    {
        // allteams filters teams through CanAccessSeason(); the player list
        // should not name a player whose only roster is in a private event.
        [$status, $body] = self::get('/index.php?view=allplayers&list=all');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Ace', $body, 'the public fixture player is listed');
        $this->assertStringNotContainsString(self::PLAYER_NAME, $body);
    }

    public function testAllTimeScoreboardLeavesOutPrivateEventStatistics(): void
    {
        DBQuery(sprintf(
            "INSERT INTO uo_player_profile (profile_id, firstname, lastname) VALUES (%d, 'Very', '%s')",
            self::PROFILE,
            self::PLAYER_NAME,
        ));
        DBQuery(sprintf("UPDATE uo_player SET profile_id=%d WHERE player_id=%d", self::PROFILE, self::PLAYER));
        DBQuery(sprintf(
            "INSERT INTO uo_player_stats (player_id, profile_id, team, season, series, games, goals, passes)
             VALUES (%d, %d, %d, '%s', %d, 5, 40, 10)",
            self::PLAYER,
            self::PROFILE,
            self::TEAM,
            self::SEASON,
            self::SERIES,
        ));

        // Contrast: once the event is public, the same row is listed.
        DBQuery(sprintf("UPDATE uo_season SET public_event=1 WHERE season_id='%s'", self::SEASON));
        [$status, $body] = self::get('/index.php?view=statistics&list=playerscoresall');
        $this->assertSame(200, $status);
        $this->assertStringContainsString(self::PLAYER_NAME, $body);

        DBQuery(sprintf("UPDATE uo_season SET public_event=0 WHERE season_id='%s'", self::SEASON));
        [$status, $body] = self::get('/index.php?view=statistics&list=playerscoresall');
        $this->assertSame(200, $status);
        $this->assertStringNotContainsString(self::PLAYER_NAME, $body);
        $this->assertStringNotContainsString(self::TEAM_NAME, $body);
    }

    private static function cleanUp(): void
    {
        DBQuery(sprintf("DELETE FROM uo_game_pool WHERE game=%d", self::GAME));
        DBQuery(sprintf("DELETE FROM uo_game WHERE game_id=%d", self::GAME));
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", self::POOL));
        DBQuery(sprintf("DELETE FROM uo_player_stats WHERE player_id=%d", self::PLAYER));
        DBQuery(sprintf("DELETE FROM uo_player WHERE player_id=%d", self::PLAYER));
        DBQuery(sprintf("DELETE FROM uo_player_profile WHERE profile_id=%d", self::PROFILE));
        DBQuery(sprintf("DELETE FROM uo_team WHERE team_id=%d", self::TEAM));
        DBQuery(sprintf("DELETE FROM uo_series WHERE series_id=%d", self::SERIES));
        DBQuery(sprintf("DELETE FROM uo_season WHERE season_id='%s'", self::SEASON));
    }

    /** @return array{0: int, 1: string} */
    private static function get(string $path): array
    {
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create([
            'http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20],
        ]);
        $body = file_get_contents($baseUrl . $path, false, $context);
        preg_match('/\s(\d{3})\b/', $http_response_header[0] ?? '', $m);
        return [(int) ($m[1] ?? 0), (string) $body];
    }
}
