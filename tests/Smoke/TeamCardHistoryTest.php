<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * The history table on teamcard.php.
 *
 * The table lists the archived statistics of every team with the same name and
 * division type, across events, with a spirit average per row. The other
 * history tables apply the event rules (playercard filters rows through
 * CanAccessSeason(); spirit points show only where ShowSpiritScoresForSeason()
 * allows), this one applies neither. The test seeds a private event with a
 * same-named team, and an archived spirit average for the fixture event, which
 * does not publish spirit points. Asserted strings are seeded data and numbers,
 * so the test is locale-independent.
 */
final class TeamCardHistoryTest extends TestCase
{
    private const PRIVATE_SEASON = 'HRNPRIV';
    private const PRIVATE_SEASON_NAME = 'Private Cup';
    private const PRIVATE_SERIES = 110;
    private const PRIVATE_TEAM = 310;
    private const SPIRIT_AVERAGE = '3.37';

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([], 'database_with_common');
        self::cleanUp();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        DBQuery("UPDATE uo_season SET showspiritpoints=0 WHERE season_id='HRN2026'");
        self::flushQueryCaches();
        LegacyApp::closeDatabaseConnection();
    }

    public function testHistoryLeavesOutAPrivateEventOfASameNamedTeam(): void
    {
        self::seedPrivateEvent();

        // Contrast: once that event is public, its row is listed.
        DBQuery(sprintf("UPDATE uo_season SET public_event=1 WHERE season_id='%s'", self::PRIVATE_SEASON));
        $this->assertStringContainsString(self::PRIVATE_SEASON_NAME, self::teamCard());

        DBQuery(sprintf("UPDATE uo_season SET public_event=0 WHERE season_id='%s'", self::PRIVATE_SEASON));
        $body = self::teamCard();
        $this->assertStringContainsString('Harness Invitational 2026', $body, 'the public row is listed');
        $this->assertStringNotContainsString(self::PRIVATE_SEASON_NAME, $body);
    }

    public function testHistoryLeavesOutSpiritAveragesTheEventDoesNotPublish(): void
    {
        DBQuery(sprintf(
            "INSERT INTO uo_team_spirit_stats (team_id, season, series, category_id, games, average)
             VALUES (300, 'HRN2026', 100, 1009, 1, %s)",
            self::SPIRIT_AVERAGE,
        ));

        // Contrast: once the event publishes spirit points, the average is shown.
        DBQuery("UPDATE uo_season SET showspiritpoints=1 WHERE season_id='HRN2026'");
        $this->assertStringContainsString(self::SPIRIT_AVERAGE, self::teamCard());

        DBQuery("UPDATE uo_season SET showspiritpoints=0 WHERE season_id='HRN2026'");
        $this->assertStringNotContainsString(self::SPIRIT_AVERAGE, self::teamCard());
    }

    private static function seedPrivateEvent(): void
    {
        DBQuery(sprintf(
            "INSERT INTO uo_season (season_id, name, starttime, endtime, iscurrent, enrollopen, type,
                istournament, isinternational, isnationalteams, organizer, category, showspiritpoints,
                use_season_points, hide_time_on_scoresheet, event_readonly, api_public, public_event,
                timezone, spiritmode)
             VALUES ('%s', '%s', '2025-06-01 09:00:00', '2025-06-02 18:00:00', 0, 0, 'outdoor',
                1, 0, 0, 'Harness Org', 'test', 0, 0, 0, 0, 0, 0, 'Europe/Helsinki', 1003)",
            self::PRIVATE_SEASON,
            self::PRIVATE_SEASON_NAME,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_series (series_id, name, ordering, season, valid, type, color, pool_template)
             VALUES (%d, 'Private Open', 'A', '%s', 1, 'open', '336699', NULL)",
            self::PRIVATE_SERIES,
            self::PRIVATE_SEASON,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_team (team_id, name, pool, club, rank, activerank, valid, series, country, abbreviation)
             VALUES (%d, 'Helsinki Heat', NULL, NULL, 1, 1, 1, %d, NULL, 'HEAT')",
            self::PRIVATE_TEAM,
            self::PRIVATE_SERIES,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_team_stats (team_id, season, series, goals_made, goals_against, standing, wins, losses, defenses_total)
             VALUES (%d, '%s', %d, 30, 20, 1, 3, 0, 0)",
            self::PRIVATE_TEAM,
            self::PRIVATE_SEASON,
            self::PRIVATE_SERIES,
        ));
    }

    private static function cleanUp(): void
    {
        DBQuery("DELETE FROM uo_team_spirit_stats WHERE team_id=300");
        DBQuery(sprintf("DELETE FROM uo_team_stats WHERE team_id=%d", self::PRIVATE_TEAM));
        DBQuery(sprintf("DELETE FROM uo_team WHERE team_id=%d", self::PRIVATE_TEAM));
        DBQuery(sprintf("DELETE FROM uo_series WHERE series_id=%d", self::PRIVATE_SERIES));
        DBQuery(sprintf("DELETE FROM uo_season WHERE season_id='%s'", self::PRIVATE_SEASON));
    }

    private static function teamCard(): string
    {
        // Rows written by this process must not be masked by Apache's cache.
        self::flushQueryCaches();
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);
        $body = file_get_contents($baseUrl . '/index.php?view=teamcard&team=300', false, $context);
        self::assertStringContainsString(' 200 ', $http_response_header[0] ?? '');
        return (string) $body;
    }

    private static function flushQueryCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            CacheForgetPersistent($ns);
            CacheForgetNamespace($ns);
        }
    }
}
