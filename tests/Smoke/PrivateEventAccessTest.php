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
 */
final class PrivateEventAccessTest extends TestCase
{
    private const SEASON = 'HRNPRIV';
    private const SERIES = 110;
    private const TEAM = 310;
    private const PLAYER = 810;
    private const TEAM_NAME = 'Secret Squad';
    private const PLAYER_NAME = 'Hidden';

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([], 'database_with_common');
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
        self::flushQueryCaches();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        self::flushQueryCaches();
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

    private static function cleanUp(): void
    {
        DBQuery(sprintf("DELETE FROM uo_player WHERE player_id=%d", self::PLAYER));
        DBQuery(sprintf("DELETE FROM uo_team WHERE team_id=%d", self::TEAM));
        DBQuery(sprintf("DELETE FROM uo_series WHERE series_id=%d", self::SERIES));
        DBQuery(sprintf("DELETE FROM uo_season WHERE season_id='%s'", self::SEASON));
    }

    private static function flushQueryCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            if (function_exists('CacheForgetPersistent')) {
                CacheForgetPersistent($ns);
            }
            if (function_exists('CacheForgetNamespace')) {
                CacheForgetNamespace($ns);
            }
        }
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
