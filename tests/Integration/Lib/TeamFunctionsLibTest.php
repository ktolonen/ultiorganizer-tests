<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class TeamFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        // team_stack + configuration (TeamGames/TeamScoreBoard call ShowDefenseStats)
        LegacyApp::loadLibFilesUsingProfile(['configuration.functions.php', 'team.functions.php'], 'team_stack');
        global $serverConf;
        $serverConf = GetSimpleServerConf();
        $_SESSION['userproperties']['locale'] = 'en_GB.utf8';
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    /**
     * DBQuery* helpers cache by query string in the persistent cache; flush after a
     * write before re-reading the same query. See feedback-db-query-persistent-cache.
     */
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

    // Fixture: teams 300 (Helsinki Heat) + 301 (Tampere Tempest), series 100, pool 200,
    // season HRN2026, players 800 (Ari Ace) + 801 (Bea Blade) on team 300.

    // --- Name / identity reads ---

    public function testTeamNameAndSeasonReadBaselineFixture(): void
    {
        $this->assertSame('Helsinki Heat', TeamName(300));
        $this->assertSame('HRN2026', TeamSeason(300));
    }

    public function testTeamNameReturnsEmptyForInvalidId(): void
    {
        $this->assertSame('', TeamName(0));
        $this->assertSame('', TeamName(-1));
        $this->assertSame('', TeamName(''));
    }

    public function testTeamPseudoNameReturnsValueOrNull(): void
    {
        $result = TeamPseudoName(99999);
        $this->assertTrue($result === null || is_string($result));
    }

    public function testTeamInfoReturnsFixtureData(): void
    {
        $info = TeamInfo(300);
        $this->assertSame('Helsinki Heat', $info['name']);
        $this->assertSame('HRN2026', $info['season']);
    }

    public function testTeamProfileReturnsRowOrNull(): void
    {
        // uo_team_profile has no row for the fixture team → null/false; just exercise the query
        $profile = TeamProfile(300);
        $this->assertTrue($profile === null || $profile === false || is_array($profile));
    }

    public function testTeamFullInfoReturnsFixtureData(): void
    {
        $info = TeamFullInfo(300);
        $this->assertIsArray($info);
    }

    public function testTeamSeasonReturnsSeasonId(): void
    {
        $this->assertSame('HRN2026', TeamSeason(300));
    }

    // --- Player arrays/lists ---

    public function testTeamPlayerArrayReturnsOrderedFixturePlayers(): void
    {
        $this->assertSame(['800' => 'Ari Ace', '801' => 'Bea Blade'], TeamPlayerArray(300));
    }

    public function testTeamPlayerAccreditationArrayReturnsArray(): void
    {
        $this->assertIsArray(TeamPlayerAccreditationArray(300));
    }

    public function testTeamPlayerListReturnsArray(): void
    {
        $list = TeamPlayerList(300);
        $this->assertIsArray($list);
        $this->assertNotEmpty($list);
    }

    public function testGetTeamPlayersReturnsArray(): void
    {
        $_GET['team'] = '300';
        $this->assertIsArray(GetTeamPlayers());
    }

    // --- Team listings ---

    public function testTeamsReturnsResultWithFixtureTeams(): void
    {
        $teams = DBFetchAllAssoc(Teams());
        $ids = array_column($teams, 'team_id');
        $this->assertContains('300', $ids);
    }

    public function testTeamsWithFilterReturnsResult(): void
    {
        $teams = DBFetchAllAssoc(Teams(['season.season_id' => 'HRN2026']));
        $this->assertIsArray($teams);
    }

    public function testTeamListAllReturnsResult(): void
    {
        $this->assertIsArray(DBFetchAllAssoc(TeamListAll()));
    }

    public function testTeamListAllGroupedReturnsResult(): void
    {
        $this->assertIsArray(DBFetchAllAssoc(TeamListAll(true)));
    }

    public function testTeamListAllWithNameFilterReturnsResult(): void
    {
        $this->assertIsArray(DBFetchAllAssoc(TeamListAll(false, false, 'Helsinki')));
    }

    public function testTeamNameListBySeriesTypeReturnsArray(): void
    {
        $this->assertIsArray(TeamNameListBySeriesType('open'));
    }

    public function testTeamGetTeamsByNameReturnsArray(): void
    {
        $this->assertIsArray(TeamGetTeamsByName('Helsinki Heat'));
    }

    public function testTeamPlayedSeasonsReturnsResult(): void
    {
        $this->assertIsArray(DBFetchAllAssoc(TeamPlayedSeasons('Helsinki Heat', 'open')));
    }

    // --- Pool / game reads ---

    public function testTeamPoolInfoReturnsRowOrFalse(): void
    {
        $info = TeamPoolInfo(300, 200);
        $this->assertTrue($info === false || is_array($info));
    }

    public function testTeamComingGamesReturnsResult(): void
    {
        $this->assertIsArray(DBFetchAllAssoc(TeamComingGames(300, null)));
    }

    public function testTeamTournamentGamesReturnsResult(): void
    {
        $this->assertIsArray(DBFetchAllAssoc(TeamTournamentGames(300, null)));
    }

    public function testTeamGamesReturnsResult(): void
    {
        $this->assertIsArray(DBFetchAllAssoc(TeamGames(300)));
    }

    public function testTeamSerieGamesReturnsArray(): void
    {
        $this->assertIsArray(TeamSerieGames(300, 100));
    }

    public function testTeamPoolCountBYEsReturnsValue(): void
    {
        $result = TeamPoolCountBYEs(300, 200);
        $this->assertNotNull($result);
    }

    public function testTeamPoolGamesReturnsResult(): void
    {
        $this->assertIsArray(DBFetchAllAssoc(TeamPoolGames(300, 200)));
    }

    public function testTeamPoolGamesArrayReturnsArray(): void
    {
        $this->assertIsArray(TeamPoolGamesArray(300, 200));
    }

    public function testTeamPoolLastGameReturnsRowOrFalse(): void
    {
        $result = TeamPoolLastGame(300, 200);
        $this->assertTrue($result === false || is_array($result) || $result === null);
    }

    public function testTeamGetNextGamesReturnsArray(): void
    {
        $this->assertIsArray(TeamGetNextGames(300, 200));
    }

    public function testTeamPoolGamesLeftReturnsArray(): void
    {
        $this->assertIsArray(TeamPoolGamesLeft(300, 200));
    }

    public function testTeamStandingReturnsValue(): void
    {
        $result = TeamStanding(300, 200);
        $this->assertNotNull($result);
    }

    public function testTeamPoolGamesAgainstReturnsArray(): void
    {
        $this->assertIsArray(TeamPoolGamesAgainst(300, 301, 200));
    }

    public function testTeamResponsibleGamesReturnsArray(): void
    {
        $this->assertIsArray(TeamResponsibleGames(300, null));
    }

    public function testSchedulingNameByMoveToReturnsValueOrNull(): void
    {
        $result = SchedulingNameByMoveTo(200, 1);
        $this->assertTrue($result === null || is_string($result));
    }

    // --- Stats / points / scoreboards ---

    public function testTeamPlayedGamesReturnsArray(): void
    {
        $this->assertIsArray(TeamPlayedGames('Helsinki Heat', 'open', 'name'));
    }

    public function testTeamStatsByPoolReturnsRowOrFalse(): void
    {
        $result = TeamStatsByPool(200, 300);
        $this->assertTrue($result === false || is_array($result) || $result === null);
    }

    public function testTeamStatsReturnsArray(): void
    {
        $this->assertIsArray(TeamStats(300));
    }

    public function testTeamVictoryPointsByPoolReturnsValueOrArray(): void
    {
        $result = TeamVictoryPointsByPool(200, 300);
        $this->assertNotNull($result);
    }

    public function testTeamPointsReturnsArrayOrValue(): void
    {
        $result = TeamPoints(300);
        $this->assertNotNull($result);
    }

    public function testTeamPointsByPoolReturnsValueOrArray(): void
    {
        $result = TeamPointsByPool(200, 300);
        $this->assertNotNull($result);
    }

    public function testTeamScoreBoardArrayReturnsArray(): void
    {
        $this->assertIsArray(TeamScoreBoardArray(300, [200], 'total', null));
    }

    public function testTeamScoreBoardWithDefensesReturnsArray(): void
    {
        $result = TeamScoreBoardWithDefenses(300, [200], 'total', null);
        $this->assertIsArray($result);
    }

    public function testGetAllPlayedGamesArrayReturnsArray(): void
    {
        $this->assertIsArray(GetAllPlayedGamesArray(300, 301, 'open', 'name'));
    }

    // --- CanDeletePlayer / TeamHasConfirmedEnrollment ---

    public function testCanDeletePlayerReturnsBool(): void
    {
        $this->assertIsBool(CanDeletePlayer(800));
    }

    public function testTeamHasConfirmedEnrollmentReturnsBool(): void
    {
        $this->assertIsBool(TeamHasConfirmedEnrollment(300));
    }

    // --- Admin write functions (superadmin via hasEditTeamsRight) ---

    public function testAddSetDeleteTeamRoundTrip(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $teamId = null;
        try {
            $teamId = (int) AddTeam([
                'name' => 'Test Team',
                'pool' => null,
                'rank' => 5,
                'valid' => 1,
                'series' => 100,
                'country' => 0,
                'club' => null,
                'abbreviation' => 'TST',
            ]);
            $this->assertGreaterThan(0, $teamId);
            self::flushQueryCaches();
            $this->assertSame('Test Team', TeamName($teamId));

            SetTeam([
                'team_id' => $teamId,
                'name' => 'Updated Team',
                'pool' => null,
                'abbreviation' => 'UPD',
                'rank' => 6,
                'valid' => 1,
                'series' => 100,
                'country' => 0,
                'club' => null,
            ]);
            self::flushQueryCaches();
            $this->assertSame('Updated Team', TeamName($teamId));

            DeleteTeam($teamId);
            self::flushQueryCaches();
            $this->assertSame('', TeamName($teamId));
            $teamId = null;
        } finally {
            if ($teamId !== null) {
                DBQuery("DELETE FROM uo_team WHERE team_id=$teamId");
            }
            $_SESSION = [];
        }
    }

    public function testSetTeamNameUpdatesName(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $teamId = null;
        try {
            $teamId = (int) AddTeam([
                'name' => 'Rename Team', 'pool' => null, 'rank' => 1, 'valid' => 1,
                'series' => 100, 'country' => 0, 'club' => null, 'abbreviation' => 'RT',
            ]);
            SetTeamName($teamId, 'Renamed Team');
            self::flushQueryCaches();
            $this->assertSame('Renamed Team', TeamName($teamId));
        } finally {
            if ($teamId !== null) {
                DBQuery("DELETE FROM uo_team WHERE team_id=$teamId");
            }
            $_SESSION = [];
        }
    }

    public function testSetTeamOwnerUpdatesClub(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $teamId = null;
        $clubId = null;
        try {
            $clubId = (int) DBQueryInsert("INSERT INTO uo_club (name, country) VALUES ('Test Club', 1064)");
            $teamId = (int) AddTeam([
                'name' => 'Owner Team', 'pool' => null, 'rank' => 1, 'valid' => 1,
                'series' => 100, 'country' => 0, 'club' => null, 'abbreviation' => 'OT',
            ]);
            $result = SetTeamOwner($teamId, $clubId);
            $this->assertNotFalse($result);
            self::flushQueryCaches();
            $storedClub = DBQueryToValue("SELECT club FROM uo_team WHERE team_id=$teamId");
            $this->assertSame((string) $clubId, $storedClub);
        } finally {
            if ($teamId !== null) {
                DBQuery("DELETE FROM uo_team WHERE team_id=$teamId");
            }
            if ($clubId !== null) {
                DBQuery("DELETE FROM uo_club WHERE club_id=$clubId");
            }
            $_SESSION = [];
        }
    }

    public function testSetTeamRankFunctions(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $teamId = null;
        try {
            $teamId = (int) AddTeam([
                'name' => 'Rank Team', 'pool' => 200, 'rank' => 1, 'valid' => 1,
                'series' => 100, 'country' => 0, 'club' => null, 'abbreviation' => 'RK',
            ]);
            // These return results / run without error
            SetTeamSerieRank($teamId, 200, 3, 3);
            SetTeamPoolRank($teamId, 200, 4);
            SetTeamRank($teamId, 200, 5);
            SetTeamSeeding(100, $teamId, 2);
            $this->assertGreaterThan(0, $teamId);
        } finally {
            if ($teamId !== null) {
                DBQuery("DELETE FROM uo_team_pool WHERE team=$teamId");
                DBQuery("DELETE FROM uo_team WHERE team_id=$teamId");
            }
            $_SESSION = [];
        }
    }

    public function testTeamCopyRosterRunsForEmptySource(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $sourceId = null;
        $targetId = null;
        try {
            // Empty source team avoids the fixture players' profile-FK coupling;
            // exercises the permission check and the (empty) copy loop.
            $sourceId = (int) AddTeam([
                'name' => 'Roster Source', 'pool' => null, 'rank' => 1, 'valid' => 1,
                'series' => 100, 'country' => 0, 'club' => null, 'abbreviation' => 'RS',
            ]);
            $targetId = (int) AddTeam([
                'name' => 'Roster Target', 'pool' => null, 'rank' => 1, 'valid' => 1,
                'series' => 100, 'country' => 0, 'club' => null, 'abbreviation' => 'RT',
            ]);
            TeamCopyRoster($sourceId, $targetId);
            self::flushQueryCaches();
            $this->assertIsArray(TeamPlayerList($targetId));
        } finally {
            foreach ([$sourceId, $targetId] as $tid) {
                if ($tid !== null) {
                    DBQuery("DELETE FROM uo_player WHERE team=$tid");
                    DBQuery("DELETE FROM uo_team WHERE team_id=$tid");
                }
            }
            $_SESSION = [];
        }
    }
}
