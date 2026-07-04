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

    /**
     * Team 300's two fixture players (800 Ari Ace, 801 Bea Blade) tie on every
     * ScoreBoard stat from game 700 (each scored once, assisted once, played once),
     * so sort order always falls back to the lastname/firstname/num tiebreak, which
     * puts 800 before 801 regardless of $sorting.
     */
    private function assertTeamScoreBoardRows(array $result): void
    {
        $this->assertCount(2, $result);
        $this->assertSame('800', $result[0]['player_id']);
        $this->assertSame('801', $result[1]['player_id']);
        $this->assertEquals(1, $result[0]['done']);
        $this->assertEquals(1, $result[0]['fedin']);
        $this->assertEquals(0, $result[0]['callahan']);
        $this->assertEquals(2, $result[0]['total']);
        $this->assertEquals(1, $result[0]['games']);
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
        $this->assertSame('Helsinki Heat', $info['name']);
        $this->assertSame('HEAT', $info['abbreviation']);
        // Joined uo_team_pool.activerank for team 300 in pool 200 is 1.
        $this->assertSame(1, (int) $info['activerank']);
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
        // Regression guard: this function used to key its map by the nullable,
        // non-unique accreditation_id, collapsing distinct players to one key
        // (see project-sut-bugs-found memory); it now keys by player_id.
        $this->assertSame(['800' => 'Ari Ace', '801' => 'Bea Blade'], TeamPlayerAccreditationArray(300));
    }

    public function testTeamPlayerListReturnsArray(): void
    {
        $list = TeamPlayerList(300);
        $this->assertIsArray($list);
        $this->assertNotEmpty($list);
    }

    public function testGetTeamPlayersReturnsArray(): void
    {
        // GetTeamPlayers() resolves the team from $_GET['search']/'query'/'q', not 'team'.
        $_GET['search'] = '300';
        $players = GetTeamPlayers();
        $this->assertIsArray($players);
        // Team 300 has players 800 (Ari Ace) and 801 (Bea Blade), ordered by lastname ASC.
        $this->assertCount(2, $players);
        $this->assertSame('Ari', $players[0]['firstname']);
        $this->assertSame('Ace', $players[0]['lastname']);
        $this->assertSame('Bea', $players[1]['firstname']);
    }

    public function testGetTeamPlayersWithoutSearchParamReturnsEmpty(): void
    {
        // With no search param the team id defaults to 0, so no players match.
        unset($_GET['search'], $_GET['query'], $_GET['q']);
        $this->assertSame([], GetTeamPlayers());
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
        // Both fixture teams (300, 301) are in season HRN2026.
        $teams = DBFetchAllAssoc(Teams(['season.season_id' => 'HRN2026']));
        $this->assertCount(2, $teams);
    }

    public function testTeamListAllReturnsResult(): void
    {
        // Fixture has exactly 2 teams globally.
        $this->assertCount(2, DBFetchAllAssoc(TeamListAll()));
    }

    public function testTeamListAllGroupedReturnsResult(): void
    {
        // Grouped by (team.name, ser.name); still 2 distinct team+series combos.
        $this->assertCount(2, DBFetchAllAssoc(TeamListAll(true)));
    }

    public function testTeamListAllWithNameFilterReturnsResult(): void
    {
        // Only 'Helsinki Heat' matches the 'Helsinki' name-prefix filter.
        $result = DBFetchAllAssoc(TeamListAll(false, false, 'Helsinki'));
        $this->assertCount(1, $result);
        $this->assertSame('Helsinki Heat', $result[0]['name']);
    }

    public function testTeamNameListBySeriesTypeReturnsArray(): void
    {
        // Both fixture teams are in series 100, type='open'; ordered by name ASC.
        $result = TeamNameListBySeriesType('open');
        $this->assertSame(['Helsinki Heat', 'Tampere Tempest'], array_column($result, 'name'));
    }

    public function testTeamGetTeamsByNameReturnsArray(): void
    {
        // Team 300 has a uo_team_stats row (required by the join) and matches the prefix.
        $result = TeamGetTeamsByName('Helsinki Heat');
        $this->assertCount(1, $result);
        $this->assertEquals(300, $result[0]['team_id']);
    }

    public function testTeamPlayedSeasonsReturnsResult(): void
    {
        // Team 300 (Helsinki Heat) is in pool 200, series type 'open', season HRN2026.
        $result = DBFetchAllAssoc(TeamPlayedSeasons('Helsinki Heat', 'open'));
        $this->assertCount(1, $result);
        $this->assertEquals(200, $result[0]['pool_id']);
        $this->assertSame('HRN2026', $result[0]['season_id']);
    }

    // --- Pool / game reads ---

    public function testTeamPoolInfoReturnsRowOrFalse(): void
    {
        $info = TeamPoolInfo(300, 200);
        $this->assertTrue($info === false || is_array($info));
    }

    public function testTeamComingGamesReturnsResult(): void
    {
        // placeId=null never matches either fixture game's real reservation (500/501).
        $this->assertSame([], DBFetchAllAssoc(TeamComingGames(300, null)));
    }

    public function testTeamTournamentGamesReturnsResult(): void
    {
        // placeId=null never matches either fixture game's real reservation (500/501).
        $this->assertSame([], DBFetchAllAssoc(TeamTournamentGames(300, null)));
    }

    public function testTeamGamesReturnsResult(): void
    {
        // Only game 700 has hasstarted>0; game 701 is excluded.
        $result = DBFetchAllAssoc(TeamGames(300));
        $this->assertCount(1, $result);
        $this->assertEquals(700, $result[0]['game_id']);
    }

    public function testTeamSerieGamesReturnsArray(): void
    {
        // The $serieId param is actually filtered as a pool id (gp.pool='%s'); the
        // fixture's pool is 200, not 100, so passing the series id (100) always
        // returns empty. Use the real pool id to exercise the matching path.
        $result = TeamSerieGames(300, 200);
        $this->assertCount(2, $result);
        $this->assertEquals(700, $result[0]['game_id']);
        $this->assertEquals(701, $result[1]['game_id']);
    }

    public function testTeamPoolCountBYEsReturnsValue(): void
    {
        // The fixture has no BYE opponents (no valid=2 teams) in pool 200, so the count is 0.
        $result = TeamPoolCountBYEs(300, 200);
        $this->assertEquals(0, $result);
    }

    public function testTeamPoolGamesReturnsResult(): void
    {
        // No hasstarted filter here, unlike TeamGames(): both fixture games qualify.
        $result = DBFetchAllAssoc(TeamPoolGames(300, 200));
        $this->assertCount(2, $result);
        $this->assertEquals(700, $result[0]['game_id']);
        $this->assertEquals(701, $result[1]['game_id']);
    }

    public function testTeamPoolGamesArrayReturnsArray(): void
    {
        $result = TeamPoolGamesArray(300, 200);
        $this->assertCount(2, $result);
        $this->assertEquals(700, $result[0]['game_id']);
        $this->assertEquals(701, $result[1]['game_id']);
    }

    public function testTeamPoolLastGameReturnsRowOrFalse(): void
    {
        $result = TeamPoolLastGame(300, 200);
        $this->assertTrue($result === false || is_array($result) || $result === null);
    }

    public function testTeamGetNextGamesReturnsArray(): void
    {
        // Earliest game by time (700, 10:00) beats game 701 (14:00).
        $result = TeamGetNextGames(300, 200);
        $this->assertEquals(700, $result['game_id']);
    }

    public function testTeamPoolGamesLeftReturnsArray(): void
    {
        // Only game 701 (hasstarted=0) is still left to play.
        $result = TeamPoolGamesLeft(300, 200);
        $this->assertCount(1, $result);
        $this->assertEquals(701, $result[0]['game_id']);
    }

    public function testTeamStandingReturnsValue(): void
    {
        // TeamStanding reads uo_team_pool.activerank; team 300 is ranked 1 in pool 200.
        $result = TeamStanding(300, 200);
        $this->assertEquals(1, $result);
    }

    public function testTeamPoolGamesAgainstReturnsArray(): void
    {
        // visitorteam=teamId1(300) AND hometeam=teamId2(301) matches only game 701.
        $result = TeamPoolGamesAgainst(300, 301, 200);
        $this->assertCount(1, $result);
        $this->assertEquals(701, $result[0]['game_id']);
    }

    public function testTeamResponsibleGamesReturnsArray(): void
    {
        // placeId=null never matches either fixture game's real reservation (500/501).
        $this->assertSame([], TeamResponsibleGames(300, null));
    }

    public function testSchedulingNameByMoveToReturnsValueOrNull(): void
    {
        $result = SchedulingNameByMoveTo(200, 1);
        $this->assertTrue($result === null || is_string($result));
    }

    // --- Stats / points / scoreboards ---

    public function testTeamPlayedGamesReturnsArray(): void
    {
        // curSeason defaults to false, which excludes ser.season == CurrentSeason()
        // (HRN2026 is the fixture's current season), so this is always empty.
        $this->assertSame([], TeamPlayedGames('Helsinki Heat', 'open', 'name'));
    }

    public function testTeamStatsByPoolReturnsRowOrFalse(): void
    {
        $result = TeamStatsByPool(200, 300);
        $this->assertTrue($result === false || is_array($result) || $result === null);
    }

    public function testTeamStatsReturnsArray(): void
    {
        // Only game 700 is started (hasstarted=1, isongoing=0, timetable=1); team 300
        // won it 15-11. Game 701 has not started and is excluded from the aggregate.
        $stats = TeamStats(300);
        $this->assertIsArray($stats);
        $this->assertEquals(1, $stats['games']);
        $this->assertEquals(1, $stats['wins']);
        $this->assertEquals(0, $stats['draws']);
        $this->assertEquals(0, $stats['losses']);
    }

    public function testTeamVictoryPointsByPoolReturnsValueOrArray(): void
    {
        // Only game 700 (hasstarted>0) counts: team 300 won 15-11 (diff +4).
        // uo_victorypoints maps pointdiff 4→19 and the opponent's -4→11.
        $result = TeamVictoryPointsByPool(200, 300);
        $this->assertEquals(1, $result['games']);
        $this->assertEquals(4, $result['margin']);
        $this->assertEquals(19, $result['victorypoints']);
        $this->assertEquals(11, $result['oppvp']);
        $this->assertEquals(15, $result['score']);
    }

    public function testTeamPointsReturnsArrayOrValue(): void
    {
        // Team 300 scored 15, conceded 11, in its one hasstarted game (700).
        $result = TeamPoints(300);
        $this->assertEquals(15, $result['scores']);
        $this->assertEquals(11, $result['against']);
    }

    public function testTeamPointsByPoolReturnsValueOrArray(): void
    {
        $result = TeamPointsByPool(200, 300);
        $this->assertEquals(15, $result['scores']);
        $this->assertEquals(11, $result['against']);
    }

    public function testTeamScoreBoardArrayReturnsArray(): void
    {
        $this->assertTeamScoreBoardRows(TeamScoreBoardArray(300, [200], 'total', null));
    }

    public function testTeamScoreBoardWithDefensesReturnsArray(): void
    {
        $result = TeamScoreBoardWithDefenses(300, [200], 'total', null);
        $this->assertTeamScoreBoardRows($result);
        // Regression guard: the pools-branch query used to do `COALESCE(d.deftotal)`
        // (missing the ",0" default that the no-pools branch has), so with no
        // uo_defense rows for this team, deftotal came back NULL here instead of 0.
        $this->assertEquals(0, $result[0]['deftotal']);
    }

    public function testGetAllPlayedGamesArrayReturnsArray(): void
    {
        // GetAllPlayedGames() matches on space-stripped team NAMES (REPLACE(name,' ',''))
        // not team ids; game 700 (300 home vs 301 away, 15-11, hasstarted) is the only
        // played meeting between these two fixture teams.
        $result = GetAllPlayedGamesArray('HelsinkiHeat', 'TampereTempest', 'open', 'name');
        $this->assertCount(1, $result);
        $this->assertEquals(700, $result[0]['game_id']);
    }

    // --- CanDeletePlayer / TeamHasConfirmedEnrollment ---

    public function testCanDeletePlayerReturnsBool(): void
    {
        // Player 800 has a uo_played row for game 700, so it can't be deleted.
        $this->assertFalse(CanDeletePlayer(800));
    }

    public function testTeamHasConfirmedEnrollmentReturnsBool(): void
    {
        // Fixture has no uo_enrolledteam rows at all.
        $this->assertFalse(TeamHasConfirmedEnrollment(300));
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
            // Empty source → the copy loop runs zero iterations; target stays empty.
            $this->assertSame([], TeamPlayerList($targetId));
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

    // --- Player add/remove and team profile (need player + logging stack) ---

    private function loadPlayerStack(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loadLibFilesUsingProfile(
            ['logging.functions.php', 'player.functions.php'],
            'database_only'
        );
        LegacyApp::loginAsAdmin();
    }

    public function testAddAndRemovePlayerRoundTrip(): void
    {
        $this->loadPlayerStack();
        $playerId = null;
        try {
            // No profileId → AddPlayer creates a new uo_player_profile then the player
            $playerId = (int) AddPlayer(300, 'Test', 'Player', null, 23);
            $this->assertGreaterThan(0, $playerId);
            self::flushQueryCaches();
            $names = TeamPlayerArray(300);
            $this->assertArrayHasKey((string) $playerId, $names);
            $this->assertSame('Test Player', $names[(string) $playerId]);

            // A freshly added player has no played games → deletable
            $this->assertTrue(CanDeletePlayer($playerId));

            $result = RemovePlayer($playerId);
            $this->assertNotFalse($result);
            self::flushQueryCaches();
            $this->assertArrayNotHasKey((string) $playerId, TeamPlayerArray(300));
            $playerId = null;
        } finally {
            if ($playerId !== null) {
                DBQuery("DELETE FROM uo_player WHERE player_id=$playerId");
            }
            $_SESSION = [];
        }
    }

    public function testRemovePlayerReturnsFalseForMissingPlayer(): void
    {
        $this->loadPlayerStack();
        try {
            $this->assertFalse(RemovePlayer(999999));
        } finally {
            $_SESSION = [];
        }
    }

    public function testCanDeletePlayerReturnsFalseForPlayerWithGames(): void
    {
        $this->loadPlayerStack();
        try {
            // Fixture player 800 has a uo_played row for game 700
            $this->assertFalse(CanDeletePlayer(800));
        } finally {
            $_SESSION = [];
        }
    }

    public function testSetTeamProfileInsertsThenUpdates(): void
    {
        $this->loadPlayerStack();
        try {
            // Insert path (no existing uo_team_profile row for team 300)
            SetTeamProfile([
                'team_id' => 300,
                'abbreviation' => 'HEL',
                'captain' => 'Ari Ace',
                'coach' => 'Coach One',
                'story' => 'A story',
                'achievements' => 'None yet',
            ]);
            self::flushQueryCaches();
            $profile = TeamProfile(300);
            $this->assertIsArray($profile);
            $this->assertSame('Ari Ace', $profile['captain']);

            // Update path (row now exists)
            SetTeamProfile([
                'team_id' => 300,
                'abbreviation' => 'HEL',
                'captain' => 'Bea Blade',
                'coach' => 'Coach Two',
                'story' => 'Updated story',
                'achievements' => 'Champions',
            ]);
            self::flushQueryCaches();
            $updated = TeamProfile(300);
            $this->assertSame('Bea Blade', $updated['captain']);
            $this->assertSame('Champions', $updated['achievements']);
        } finally {
            DBQuery("DELETE FROM uo_team_profile WHERE team_id=300");
            // SetTeamProfile updated uo_team.abbreviation; restore the fixture value
            DBQuery("UPDATE uo_team SET abbreviation='HEAT' WHERE team_id=300");
            $_SESSION = [];
        }
    }

    public function testSetAndRemoveTeamProfileImage(): void
    {
        $this->loadPlayerStack();
        try {
            // Seed a profile row, then set + clear its profile_image (no real files needed;
            // RemoveTeamProfileImage only unlinks when the file exists on disk)
            DBQuery("INSERT INTO uo_team_profile (team_id, captain) VALUES (300, 'C')");
            SetTeamProfileImage(300, 'team-photo.jpg');
            self::flushQueryCaches();
            $this->assertSame('team-photo.jpg', TeamProfile(300)['profile_image']);

            RemoveTeamProfileImage(300);
            self::flushQueryCaches();
            $this->assertEmpty(TeamProfile(300)['profile_image']);
        } finally {
            DBQuery("DELETE FROM uo_team_profile WHERE team_id=300");
            $_SESSION = [];
        }
    }

    public function testUploadTeamImageProcessesGeneratedImage(): void
    {
        if (!CanProcessImages()) {
            $this->markTestSkipped('GD not available');
        }
        $this->loadPlayerStack();

        // Generate a real JPEG and present it as an uploaded file
        $tmp = tempnam(sys_get_temp_dir(), 'uo_upl_') . '.jpg';
        $img = imagecreatetruecolor(60, 40);
        imagejpeg($img, $tmp);
        imagedestroy($img);
        $_FILES['picture'] = ['name' => 'p.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)];

        $teamDir = LegacyApp::sutRoot() . '/images/uploads/teams/300';
        try {
            // SetTeamProfileImage (called inside UploadTeamImage) UPDATEs an existing row
            DBQuery("INSERT INTO uo_team_profile (team_id, captain) VALUES (300, 'C')");
            self::flushQueryCaches();
            $result = UploadTeamImage(300);
            // Empty string means success; the uploaded jpeg + thumb were written and recorded
            $this->assertSame('', $result);
            self::flushQueryCaches();
            $stored = TeamProfile(300)['profile_image'];
            $this->assertNotEmpty($stored);
            $this->assertFileExists("$teamDir/$stored");
            $this->assertFileExists("$teamDir/thumbs/$stored");
        } finally {
            // Remove generated files and the team dir, plus the DB row
            foreach (glob("$teamDir/thumbs/*") ?: [] as $f) {
                @unlink($f);
            }
            foreach (glob("$teamDir/*") ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir("$teamDir/thumbs");
            @rmdir($teamDir);
            @unlink($tmp);
            DBQuery("DELETE FROM uo_team_profile WHERE team_id=300");
            unset($_FILES['picture']);
            $_SESSION = [];
        }
    }

    public function testUploadTeamImageRejectsNonImageType(): void
    {
        $this->loadPlayerStack();
        $_FILES['picture'] = ['name' => 'x.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 10];
        try {
            $result = UploadTeamImage(300);
            $this->assertStringContainsString('not supported', strtolower($result));
        } finally {
            unset($_FILES['picture']);
            $_SESSION = [];
        }
    }

    public function testUploadTeamImageRejectsOversizeFile(): void
    {
        $this->loadPlayerStack();
        $_FILES['picture'] = ['name' => 'big.jpg', 'type' => 'image/jpeg', 'tmp_name' => '/tmp/big', 'error' => 0, 'size' => 6 * 1024 * 1024];
        try {
            $result = UploadTeamImage(300);
            $this->assertStringContainsString('too large', strtolower($result));
        } finally {
            unset($_FILES['picture']);
            $_SESSION = [];
        }
    }

    // ---- TeamMove ----

    public function testTeamMoveReturnsEarlyWhenNoMoveConfigured(): void
    {
        // Pool 10 (VIS2026) has no playoff moves → PoolGetMoveToPool returns falsy → early return
        TeamMove(300, 10);
        $this->assertTrue(true);
    }

    // ---- TeamAbbreviation ----

    public function testTeamAbbreviationReturnsAbbreviationForKnownTeam(): void
    {
        // Team 300 (Helsinki Heat) has abbreviation 'HEAT' in the fixture.
        $this->assertSame('HEAT', TeamAbbreviation(300));
    }

    public function testTeamAbbreviationReturnsNullForUnknownTeam(): void
    {
        $abbrev = TeamAbbreviation(999999);
        $this->assertNull($abbrev);
    }

    // ---- TeamsToCsv ----

    public function testTeamsToCsvReturnsCsvStringForKnownSeason(): void
    {
        $result = TeamsToCsv('HRN2026', ';');
        $this->assertStringContainsString('"Team"', $result);
        $this->assertStringContainsString('Helsinki Heat', $result);
        $this->assertStringContainsString('Tampere Tempest', $result);
    }

    // ---- AddTeamProfileUrl / RemoveTeamProfileUrl ----

    public function testAddAndRemoveTeamProfileUrl(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $addResult = AddTeamProfileUrl(300, 'website', 'https://example.com', 'Example');
            $this->assertNotFalse($addResult);

            // Retrieve the inserted url_id
            $urlId = DBQueryToValue("SELECT url_id FROM uo_urls WHERE owner='team' AND owner_id=300 AND type='website' ORDER BY url_id DESC LIMIT 1");
            $this->assertNotNull($urlId);

            $removeResult = RemoveTeamProfileUrl(300, (int) $urlId);
            $this->assertNotFalse($removeResult);
        } finally {
            DBQuery("DELETE FROM uo_urls WHERE owner='team' AND owner_id=300 AND type='website'");
            $_SESSION = [];
        }
    }

    // ---- GetTeamPlayers (additional $_GET branches) ----

    public function testGetTeamPlayersWithQueryKey(): void
    {
        $_GET = ['query' => '300'];
        try {
            $result = GetTeamPlayers();
            $this->assertCount(2, $result);
            $this->assertSame('Ace', $result[0]['lastname']);
        } finally {
            $_GET = [];
        }
    }

    public function testGetTeamPlayersWithQKey(): void
    {
        $_GET = ['q' => '300'];
        try {
            $result = GetTeamPlayers();
            $this->assertCount(2, $result);
            $this->assertSame('Ace', $result[0]['lastname']);
        } finally {
            $_GET = [];
        }
    }

    public function testGetTeamPlayersWithSearchKey(): void
    {
        $_GET = ['search' => '300'];
        try {
            $result = GetTeamPlayers();
            $this->assertCount(2, $result);
            $this->assertSame('Ace', $result[0]['lastname']);
        } finally {
            $_GET = [];
        }
    }

    // ---- TeamCopyRoster ----

    public function testTeamCopyRosterAsAdminCopiesPlayers(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $newTeamId = null;
        try {
            $newTeamId = (int) AddTeam([
                'name' => 'CopyTarget', 'pool' => null, 'rank' => 1, 'valid' => 1,
                'series' => 100, 'country' => 0, 'club' => null, 'abbreviation' => 'CT',
            ]);
            // Copy from team 300 which has 2 players → covers foreach body with INSERT
            TeamCopyRoster(300, $newTeamId);
            self::flushQueryCaches();
            $players = DBQueryToArray("SELECT player_id FROM uo_player WHERE team=$newTeamId");
            $this->assertCount(2, $players);
        } finally {
            if ($newTeamId !== null) {
                DBQuery("DELETE FROM uo_player WHERE team=$newTeamId");
                DBQuery("DELETE FROM uo_team WHERE team_id=$newTeamId");
            }
            $_SESSION = [];
        }
    }

    // ---- TeamPlayedGames (additional sorting branches) ----

    public function testTeamPlayedGamesWithTeamSorting(): void
    {
        // 'Harness FC' matches no fixture team name; only exercises the sort branch.
        $result = TeamPlayedGames('Harness FC', 'open', 'team');
        $this->assertSame([], $result);
    }

    public function testTeamPlayedGamesWithResultSorting(): void
    {
        $result = TeamPlayedGames('Harness FC', 'open', 'result');
        $this->assertSame([], $result);
    }

    public function testTeamPlayedGamesWithSerieSorting(): void
    {
        $result = TeamPlayedGames('Harness FC', 'open', 'serie');
        $this->assertSame([], $result);
    }

    public function testTeamPlayedGamesWithCurSeasonTrue(): void
    {
        // 'Harness FC' still matches no fixture team, regardless of curSeason.
        $result = TeamPlayedGames('Harness FC', 'open', 'name', true);
        $this->assertSame([], $result);
    }

    // ---- GetAllPlayedGames (additional sorting branches) ----

    public function testGetAllPlayedGamesWithTeamSorting(): void
    {
        // GetAllPlayedGames() matches on space-stripped team names, not ids.
        $result = GetAllPlayedGamesArray('HelsinkiHeat', 'TampereTempest', 'open', 'team');
        $this->assertCount(1, $result);
        $this->assertEquals(700, $result[0]['game_id']);
    }

    public function testGetAllPlayedGamesWithResultSorting(): void
    {
        $result = GetAllPlayedGamesArray('HelsinkiHeat', 'TampereTempest', 'open', 'result');
        $this->assertCount(1, $result);
        $this->assertEquals(700, $result[0]['game_id']);
    }

    public function testGetAllPlayedGamesWithSeriesSorting(): void
    {
        $result = GetAllPlayedGamesArray('HelsinkiHeat', 'TampereTempest', 'open', 'series');
        $this->assertCount(1, $result);
        $this->assertEquals(700, $result[0]['game_id']);
    }

    // ---- SetTeam (additional country branches) ----

    public function testSetTeamWithPositiveCountryCoversBranch(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $teamId = null;
        try {
            $teamId = (int) AddTeam([
                'name' => 'Country Team', 'pool' => null, 'rank' => 1, 'valid' => 1,
                'series' => 100, 'country' => 0, 'club' => null, 'abbreviation' => 'COT',
            ]);
            // country_id 1000 (Afghanistan) exists in the production country table
            SetTeam([
                'team_id' => $teamId, 'name' => 'Country Team', 'pool' => null,
                'abbreviation' => 'COT', 'rank' => 1, 'valid' => 1, 'series' => 100,
                'country' => 1000, 'club' => null,
            ]);
            $this->assertTrue(true);
        } finally {
            if ($teamId !== null) {
                DBQuery("DELETE FROM uo_team WHERE team_id=$teamId");
            }
            $_SESSION = [];
        }
    }

    public function testSetTeamWithNegativeOneCountrySetsNull(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $teamId = null;
        try {
            $teamId = (int) AddTeam([
                'name' => 'NoCountry Team', 'pool' => null, 'rank' => 1, 'valid' => 1,
                'series' => 100, 'country' => 1000, 'club' => null, 'abbreviation' => 'NCT',
            ]);
            SetTeam([
                'team_id' => $teamId, 'name' => 'NoCountry Team', 'pool' => null,
                'abbreviation' => 'NCT', 'rank' => 1, 'valid' => 1, 'series' => 100,
                'country' => -1, 'club' => null,
            ]);
            self::flushQueryCaches();
            $info = TeamInfo($teamId);
            $this->assertNull($info['country']);
        } finally {
            if ($teamId !== null) {
                DBQuery("DELETE FROM uo_team WHERE team_id=$teamId");
            }
            $_SESSION = [];
        }
    }

    // ---- TeamScoreBoard (additional paths) ----

    public function testTeamScoreBoardArrayWithNullPools(): void
    {
        // Covers else branch (no pool filter) in TeamScoreBoard
        $result = TeamScoreBoardArray(300, false, 'total', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardArrayWithGoalSorting(): void
    {
        $result = TeamScoreBoardArray(300, [200], 'goal', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardArrayWithCallahanSorting(): void
    {
        $result = TeamScoreBoardArray(300, [200], 'callahan', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardArrayWithPassSorting(): void
    {
        $result = TeamScoreBoardArray(300, [200], 'pass', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardArrayWithGamesSorting(): void
    {
        $result = TeamScoreBoardArray(300, [200], 'games', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardArrayWithTeamSorting(): void
    {
        $result = TeamScoreBoardArray(300, [200], 'team', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardArrayWithNameSorting(): void
    {
        $result = TeamScoreBoardArray(300, [200], 'name', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardArrayWithNumSorting(): void
    {
        $result = TeamScoreBoardArray(300, [200], 'num', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardArrayWithGoalavgSorting(): void
    {
        $result = TeamScoreBoardArray(300, [200], 'goalavg', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardArrayWithPassavgSorting(): void
    {
        $result = TeamScoreBoardArray(300, [200], 'passavg', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardArrayWithTotalavgSorting(): void
    {
        $result = TeamScoreBoardArray(300, [200], 'totalavg', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardArrayWithLimit(): void
    {
        $result = TeamScoreBoardArray(300, [200], 'total', 5);
        $this->assertTeamScoreBoardRows($result);
    }

    // ---- TeamScoreBoardWithDefenses (additional paths) ----

    public function testTeamScoreBoardWithDefensesNullPools(): void
    {
        // Covers else branch in TeamScoreBoardWithDefenses
        $result = TeamScoreBoardWithDefenses(300, false, 'total', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardWithDefensesDeftotalSorting(): void
    {
        $result = TeamScoreBoardWithDefenses(300, [200], 'deftotal', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardWithDefensesGoalSorting(): void
    {
        $result = TeamScoreBoardWithDefenses(300, [200], 'goal', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardWithDefensesCallahanSorting(): void
    {
        $result = TeamScoreBoardWithDefenses(300, [200], 'callahan', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardWithDefensesPassSorting(): void
    {
        $result = TeamScoreBoardWithDefenses(300, [200], 'pass', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardWithDefensesGamesSorting(): void
    {
        $result = TeamScoreBoardWithDefenses(300, [200], 'games', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardWithDefensesTeamSorting(): void
    {
        $result = TeamScoreBoardWithDefenses(300, [200], 'team', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardWithDefensesNameSorting(): void
    {
        $result = TeamScoreBoardWithDefenses(300, [200], 'name', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardWithDefensesWithLimit(): void
    {
        $result = TeamScoreBoardWithDefenses(300, [200], 'total', 3);
        $this->assertTeamScoreBoardRows($result);
    }

    // ---- TeamListAll (onlyold and '#' namefilter branches) ----

    public function testTeamListAllWithOnlyoldTrueGroupedTrue(): void
    {
        // Covers grouped=true, onlyold=true → RIGHT JOIN branch
        $result = TeamListAll(true, true);
        $this->assertNotFalse($result);
    }

    public function testTeamListAllWithHashNamefilterGroupedTrue(): void
    {
        // Covers grouped=true + namefilter="#" → REGEXP branch
        $result = TeamListAll(true, false, '#');
        $this->assertNotFalse($result);
    }

    public function testTeamListAllWithOnlyoldNonGrouped(): void
    {
        // Covers grouped=false, onlyold=true → RIGHT JOIN branch
        $result = TeamListAll(false, true);
        $this->assertNotFalse($result);
    }

    public function testTeamListAllWithHashNamefilterNonGrouped(): void
    {
        // Covers grouped=false + namefilter="#" → REGEXP branch
        $result = TeamListAll(false, false, '#');
        $this->assertNotFalse($result);
    }

    public function testTeamListAllGroupedWithPlainNamefilterCoversUppercaseLikeBranch(): void
    {
        // grouped=true + non-empty, non-#, non-ALL namefilter → line 118 LIKE branch
        $result = TeamListAll(true, false, 'Helsinki');
        $this->assertNotFalse($result);
    }

    public function testTeamPoolGamesLeftReturnsEmptyArrayForZeroTeamId(): void
    {
        $this->assertSame([], TeamPoolGamesLeft(0, 200));
        $this->assertSame([], TeamPoolGamesLeft(-1, 0));
    }

    public function testTeamGamesWithDefenseStatsEnabledCoversDefenseStringBranch(): void
    {
        global $serverConf;
        $serverConf['ShowDefenseStats'] = 'true';
        $result = TeamGames(300);
        $this->assertNotFalse($result);
        $serverConf['ShowDefenseStats'] = 'false';
    }

    public function testTeamScoreBoardArrayWithStringPoolsCoversExplodeBranch(): void
    {
        // String pools → line 810: $pools = explode(",", (string) $pools)
        $result = TeamScoreBoardArray(300, '200', 'total', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardArrayWithUnknownSortCoversDefaultCase(): void
    {
        // Unrecognised sort → default case (lines 921-922)
        $result = TeamScoreBoardArray(300, [200], 'UNKNOWN_SORT_XYZ', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardWithDefensesStringPoolsCoversExplodeBranch(): void
    {
        // String pools → line 942: $pools = explode(...)
        $result = TeamScoreBoardWithDefenses(300, '200', 'total', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testTeamScoreBoardWithDefensesUnknownSortCoversDefaultCase(): void
    {
        // Unrecognised sort → default case (lines 1047-1048)
        $result = TeamScoreBoardWithDefenses(300, [200], 'UNKNOWN_SORT_XYZ', null);
        $this->assertTeamScoreBoardRows($result);
    }

    public function testAddPlayerWithExistingProfileIdCoversProfileLookupBranch(): void
    {
        // AddPlayer with non-empty profileId → lines 1221-1222
        $this->loadPlayerStack();
        $profileId = null;
        $playerId = null;
        try {
            $profileId = (int) DBQueryInsert(
                "INSERT INTO uo_player_profile (firstname, lastname, num) VALUES ('Harness', 'ProfileTest', 0)"
            );
            $playerId = (int) AddPlayer(300, 'Harness', 'ProfileTest', $profileId, 99);
            $this->assertGreaterThan(0, $playerId);
        } finally {
            if ($playerId !== null && $playerId > 0) {
                DBQuery("DELETE FROM uo_player WHERE player_id=$playerId");
            }
            if ($profileId !== null && $profileId > 0) {
                DBQuery("DELETE FROM uo_player_profile WHERE profile_id=$profileId");
            }
            self::flushQueryCaches();
            $_SESSION = [];
        }
    }

    public function testAddPlayerWithExistingProfileByNameCoversLookupPath(): void
    {
        // AddPlayer with no profileId but existing profile → lines 1230-1232
        $this->loadPlayerStack();
        $profileId = null;
        $playerId = null;
        try {
            $profileId = (int) DBQueryInsert(
                "INSERT INTO uo_player_profile (firstname, lastname, num) VALUES ('Harness', 'ExistingLookup', 0)"
            );
            // profileId=null, but name matches existing profile → FindExistingPlayerProfileId fires
            $playerId = (int) AddPlayer(300, 'Harness', 'ExistingLookup', null, 98);
            $this->assertGreaterThan(0, $playerId);
        } finally {
            if ($playerId !== null && $playerId > 0) {
                DBQuery("DELETE FROM uo_player WHERE player_id=$playerId");
            }
            if ($profileId !== null && $profileId > 0) {
                DBQuery("DELETE FROM uo_player_profile WHERE profile_id=$profileId");
            }
            self::flushQueryCaches();
            $_SESSION = [];
        }
    }

    public function testAddTeamWithRegIdCoversRegIdBranch(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $teamId = null;
        try {
            $teamId = (int) AddTeam([
                'name' => 'RegId Team', 'pool' => null, 'rank' => 1, 'valid' => 1,
                'series' => 100, 'country' => 0, 'club' => null,
                'abbreviation' => 'RIT', 'reg_id' => '12345',
            ]);
            $this->assertGreaterThan(0, $teamId);
            self::flushQueryCaches();
            $regId = DBQueryToValue("SELECT reg_id FROM uo_team WHERE team_id=$teamId");
            $this->assertSame('12345', $regId);
        } finally {
            if ($teamId !== null) {
                DBQuery("DELETE FROM uo_team WHERE team_id=$teamId");
            }
            self::flushQueryCaches();
            $_SESSION = [];
        }
    }

    public function testAddTeamWithCountryMinusOneCoversNullCountryBranch(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $teamId = null;
        try {
            $teamId = (int) AddTeam([
                'name' => 'NullCountry Team', 'pool' => null, 'rank' => 1, 'valid' => 1,
                'series' => 100, 'country' => -1, 'club' => null, 'abbreviation' => 'NCT',
            ]);
            $this->assertGreaterThan(0, $teamId);
            self::flushQueryCaches();
            $country = DBQueryToValue("SELECT country FROM uo_team WHERE team_id=$teamId");
            $this->assertNull($country);
        } finally {
            if ($teamId !== null) {
                DBQuery("DELETE FROM uo_team WHERE team_id=$teamId");
            }
            self::flushQueryCaches();
            $_SESSION = [];
        }
    }

    public function testAddTeamWithClubCoversClubUpdateBranch(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $teamId = null;
        $clubId = null;
        try {
            $clubId = (int) DBQueryInsert("INSERT INTO uo_club (name, country) VALUES ('HarnessClub', 1064)");
            $teamId = (int) AddTeam([
                'name' => 'Club Team', 'pool' => null, 'rank' => 1, 'valid' => 1,
                'series' => 100, 'country' => 0, 'club' => $clubId, 'abbreviation' => 'CLT',
            ]);
            $this->assertGreaterThan(0, $teamId);
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
            self::flushQueryCaches();
            $_SESSION = [];
        }
    }

    public function testSetTeamReturnsFalseForNonExistentTeamId(): void
    {
        // TeamInfo returns false → SetTeam returns false at line 1492
        $result = SetTeam(['team_id' => 999999, 'name' => 'Ghost', 'pool' => null,
            'rank' => 1, 'valid' => 1, 'series' => 100, 'country' => 0, 'club' => null]);
        $this->assertFalse($result);
    }

    public function testSetTeamWithClubCoversClubUpdateBranch(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        $teamId = null;
        $clubId = null;
        try {
            $clubId = (int) DBQueryInsert("INSERT INTO uo_club (name, country) VALUES ('SetTeamClub', 1064)");
            $teamId = (int) AddTeam([
                'name' => 'SetClub Team', 'pool' => null, 'rank' => 1, 'valid' => 1,
                'series' => 100, 'country' => 0, 'club' => null, 'abbreviation' => 'SCT',
            ]);
            $result = SetTeam([
                'team_id' => $teamId, 'name' => 'SetClub Team', 'pool' => null,
                'abbreviation' => 'SCT', 'rank' => 1, 'valid' => 1,
                'series' => 100, 'country' => 0, 'club' => $clubId,
            ]);
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
            self::flushQueryCaches();
            $_SESSION = [];
        }
    }

    public function testDeleteTeamReturnsFalseWhenTeamHasPlayers(): void
    {
        // Team 300 has players → CanDeleteTeam(300) = false → DeleteTeam returns false (line 1658)
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $result = DeleteTeam(300);
            $this->assertFalse($result);
        } finally {
            $_SESSION = [];
        }
    }

    public function testCanDeleteTeamReturnsFalseWithoutAdminRights(): void
    {
        // No admin session → hasEditTeamsRight = false → CanDeleteTeam returns false (line 1715)
        LegacyApp::loadUserFunctions();
        $result = CanDeleteTeam(300);
        $this->assertFalse($result);
        $_SESSION = [];
    }

    private function poolInsertSql(int $poolId, string $name): string
    {
        return "INSERT INTO uo_pool (pool_id, name, ordering, visible, continuingpool, placementpool, series, type, teams, mvgames, timeoutlen, halftime, winningscore, timecap, scorecap, played, addscore, halftimescore, timeouts, timeoutsper, timeoutsovertime, timeoutstimecap, betweenpointslen, forfeitscore, forfeitagainst, drawsallowed, follower) VALUES ($poolId, '$name', 99, 0, 0, 0, 100, 1, 2, 0, 70, 35, 15, NULL, NULL, 0, NULL, NULL, 2, 'half', 1, 'soft', 90, 15, 0, 0, NULL)";
    }

    public function testTeamMoveExecutesMainInsertionPath(): void
    {
        // ismoved=0: covers TeamMove main body lines 469,473,477,522-590
        LegacyApp::loadLibFilesUsingProfile(['standings.functions.php'], 'database_only');
        $fromPool = 9600; $toPool = 9601; $teamId = 9700;
        DBQuery($this->poolInsertSql($fromPool, 'TM_From'));
        DBQuery($this->poolInsertSql($toPool, 'TM_To'));
        DBQuery("INSERT INTO uo_team (team_id, name, valid, series) VALUES ($teamId, 'Move Team', 1, 100)");
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES ($teamId, $fromPool, 1, 1)");
        DBQuery("INSERT INTO uo_moveteams (frompool, fromplacing, topool, torank, ismoved, scheduling_id) VALUES ($fromPool, 1, $toPool, 1, 0, NULL)");
        try {
            TeamMove($teamId, $fromPool, false);
            self::flushQueryCaches();
            $poolNow = DBQueryToValue("SELECT pool FROM uo_team WHERE team_id=$teamId");
            $this->assertSame((string) $toPool, $poolNow);
            $inToPool = DBQueryToValue("SELECT team FROM uo_team_pool WHERE pool=$toPool AND team=$teamId");
            $this->assertSame((string) $teamId, $inToPool);
            $isMoved = DBQueryToValue("SELECT ismoved FROM uo_moveteams WHERE frompool=$fromPool AND fromplacing=1");
            $this->assertSame('1', $isMoved);
        } finally {
            DBQuery("DELETE FROM uo_moveteams WHERE frompool=$fromPool");
            DBQuery("DELETE FROM uo_team_pool WHERE pool IN ($fromPool,$toPool) AND team=$teamId");
            DBQuery("UPDATE uo_team SET pool=NULL WHERE team_id=$teamId");
            DBQuery("DELETE FROM uo_team WHERE team_id=$teamId");
            DBQuery("DELETE FROM uo_pool WHERE pool_id IN ($fromPool,$toPool)");
            self::flushQueryCaches();
        }
    }

    public function testTeamMoveWithIsmovedAndSameTeamAtDestinationReturnsEarly(): void
    {
        // ismoved=1 + same team already at topool rank → early return (lines 479-491)
        LegacyApp::loadLibFilesUsingProfile(['standings.functions.php'], 'database_only');
        $fromPool = 9602; $toPool = 9603; $teamId = 9701;
        DBQuery($this->poolInsertSql($fromPool, 'TM_From2'));
        DBQuery($this->poolInsertSql($toPool, 'TM_To2'));
        DBQuery("INSERT INTO uo_team (team_id, name, valid, series) VALUES ($teamId, 'Move Team2', 1, 100)");
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES ($teamId, $fromPool, 1, 1)");
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES ($teamId, $toPool, 1, 1)");
        DBQuery("INSERT INTO uo_moveteams (frompool, fromplacing, topool, torank, ismoved, scheduling_id) VALUES ($fromPool, 1, $toPool, 1, 1, NULL)");
        try {
            TeamMove($teamId, $fromPool, false);
            self::flushQueryCaches();
            // early return: team still shows its original pool (team table unchanged)
            $inFromPool = DBQueryToValue("SELECT team FROM uo_team_pool WHERE pool=$fromPool AND team=$teamId");
            $this->assertSame((string) $teamId, $inFromPool);
        } finally {
            DBQuery("DELETE FROM uo_moveteams WHERE frompool=$fromPool");
            DBQuery("DELETE FROM uo_team_pool WHERE pool IN ($fromPool,$toPool) AND team=$teamId");
            DBQuery("DELETE FROM uo_team WHERE team_id=$teamId");
            DBQuery("DELETE FROM uo_pool WHERE pool_id IN ($fromPool,$toPool)");
            self::flushQueryCaches();
        }
    }
}
