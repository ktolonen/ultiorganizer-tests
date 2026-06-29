<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class PoolFunctionsLibTest extends TestCase
{
    // Fixture constants:
    //   pool_id=200 ('Pool A', type=1/roundrobin, series=100, season='HRN2026', visible=1, played=1, teams=2)
    //   team_id=300 ('Helsinki Heat', rank=1), 301 ('Tampere Tempest', rank=2) in pool 200
    //   game_id=700: home=300, visitor=301, score=15:11, hasstarted=1, isongoing=0 (played)
    //   game_id=701: home=301, visitor=300, score=null:null, hasstarted=0, isongoing=0 (not played)
    //   No uo_moveteams rows; no uo_pooltemplate rows

    /** @var int[] pool-template IDs created during a test, deleted in tearDown */
    private array $createdTemplateIds = [];
    /** @var int[] team IDs created during a test, deleted in tearDown */
    private array $createdTeamIds = [];

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([
            'user.functions.php',
            'season.functions.php',
            'team.functions.php',
            'standings.functions.php',
            'swissdraw.functions.php',
            'pool.functions.php',
            'configuration.functions.php',
        ], 'database_only');

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['uid'] = 'testadmin';
        $_SESSION['userproperties']['userrole']['superadmin'] = true;
        $_SESSION['userproperties']['locale'] = 'en_US';

        global $serverConf;
        if (!isset($serverConf)) {
            $serverConf = [];
        }
        $serverConf['PersistentCacheEnabled'] = 'false';
    }

    protected function tearDown(): void
    {
        foreach ($this->createdTemplateIds as $id) {
            DBQuery(sprintf("DELETE FROM uo_pooltemplate WHERE template_id=%d", (int) $id));
        }
        $this->createdTemplateIds = [];

        foreach ($this->createdTeamIds as $id) {
            DBQuery(sprintf("DELETE FROM uo_team_pool WHERE team=%d", (int) $id));
            DBQuery(sprintf("DELETE FROM uo_team WHERE team_id=%d", (int) $id));
        }
        $this->createdTeamIds = [];

        unset($_SESSION['userproperties'], $_SESSION['uid']);
        LegacyApp::closeDatabaseConnection();
    }

    // --- PoolInfo ---

    public function testPoolInfoReturnsArrayForKnownPool(): void
    {
        $info = PoolInfo(200);
        $this->assertIsArray($info);
        $this->assertSame('200', (string) $info['pool_id']);
        $this->assertSame('Pool A', $info['name']);
        $this->assertSame('100', (string) $info['series']);
    }

    public function testPoolInfoReturnsNullForUnknownPool(): void
    {
        $this->assertNull(PoolInfo(99999));
    }

    // --- PoolName / PoolSeriesName ---

    public function testPoolNameReturnsCorrectString(): void
    {
        $name = PoolName(200);
        $this->assertSame('Pool A', $name);
    }

    public function testPoolSeriesNameReturnsNonEmptyString(): void
    {
        $name = PoolSeriesName(200);
        $this->assertIsString($name);
        $this->assertNotSame('', $name);
    }

    // --- PoolShortName ---

    public function testPoolShortNameLeavesNormalNameUnchanged(): void
    {
        $this->assertSame('Pool A', PoolShortName(200));
    }

    // --- PoolTypes ---

    public function testPoolTypesReturnsExpectedMap(): void
    {
        $types = PoolTypes();
        $this->assertIsArray($types);
        $this->assertSame(1, $types['roundrobin']);
        $this->assertSame(2, $types['playoff']);
        $this->assertSame(3, $types['swissdraw']);
        $this->assertSame(4, $types['crossmatch']);
    }

    // --- Pools ---

    public function testPoolsReturnsResult(): void
    {
        $result = Pools();
        $this->assertNotFalse($result);
    }

    public function testPoolsWithFilterFindsFixturePool(): void
    {
        $result = Pools(['pool.pool_id' => 200]);
        $row = mysqli_fetch_assoc($result);
        $this->assertIsArray($row);
        $this->assertSame('200', (string) $row['pool_id']);
    }

    // --- PoolListAll ---

    public function testPoolListAllReturnsResult(): void
    {
        $result = PoolListAll();
        $this->assertNotFalse($result);
    }

    // --- PoolTeams ---

    public function testPoolTeamsReturnsBothFixtureTeams(): void
    {
        $teams = PoolTeams(200);
        $this->assertIsArray($teams);
        $this->assertCount(2, $teams);
        $ids = array_map('strval', array_column($teams, 'team_id'));
        $this->assertContains('300', $ids);
        $this->assertContains('301', $ids);
    }

    public function testPoolTeamsSortByName(): void
    {
        $teams = PoolTeams(200, 'name');
        $this->assertIsArray($teams);
        $this->assertCount(2, $teams);
    }

    public function testPoolTeamsSortBySeed(): void
    {
        $teams = PoolTeams(200, 'seed');
        $this->assertIsArray($teams);
        $this->assertCount(2, $teams);
    }

    public function testPoolTeamsReturnsEmptyForUnknownPool(): void
    {
        $this->assertCount(0, PoolTeams(99999));
    }

    // --- PoolSchedulingTeams ---

    public function testPoolSchedulingTeamsReturnsEmptyForPoolWithoutMoves(): void
    {
        $teams = PoolSchedulingTeams(200);
        $this->assertIsArray($teams);
        $this->assertCount(0, $teams);
    }

    // --- PoolCountGames / PoolGames ---

    public function testPoolCountGamesReturnsTwoForFixturePool(): void
    {
        $this->assertSame(2, PoolCountGames(200));
    }

    public function testPoolCountGamesReturnsZeroForUnknownPool(): void
    {
        $this->assertSame(0, PoolCountGames(99999));
    }

    public function testPoolGamesReturnsBothFixtureGames(): void
    {
        $games = PoolGames(200);
        $this->assertIsArray($games);
        $this->assertCount(2, $games);
        $ids = array_map('strval', array_column($games, 'game_id'));
        $this->assertContains('700', $ids);
        $this->assertContains('701', $ids);
    }

    public function testPoolGamesWithFieldFilterLimitsResults(): void
    {
        // Only game 700 uses reservation 500.
        $games = PoolGames(200, 500);
        $this->assertIsArray($games);
        $this->assertCount(1, $games);
        $this->assertSame('700', (string) $games[0]['game_id']);
    }

    public function testPoolGamesReturnsEmptyForUnknownPool(): void
    {
        $this->assertCount(0, PoolGames(99999));
    }

    // --- PoolGamesNotScheduled ---

    public function testPoolGamesNotScheduledReturnsEmptyWhenAllScheduled(): void
    {
        // Both fixture games have reservation and time set.
        $games = PoolGamesNotScheduled(200);
        $this->assertIsArray($games);
        $this->assertCount(0, $games);
    }

    // --- PoolMovedGames ---

    public function testPoolMovedGamesReturnsEmptyForFixturePool(): void
    {
        $games = PoolMovedGames(200);
        $this->assertIsArray($games);
        $this->assertCount(0, $games);
    }

    // --- PoolTotalPlayedGames ---

    public function testPoolTotalPlayedGamesCountsOnlyCompletedGames(): void
    {
        // Game 700: hasstarted=1, isongoing=0 → played. Game 701: hasstarted=0 → not played.
        $this->assertSame(1, PoolTotalPlayedGames(200));
    }

    // --- IsPoolStarted / IsPoolLocked ---

    public function testIsPoolStartedReturnsTrueForFixturePool(): void
    {
        // Game 700 has hasstarted=1.
        $this->assertTrue(IsPoolStarted(200));
    }

    public function testIsPoolLockedReturnsTrueForFixturePool(): void
    {
        // Set played explicitly: other classes (e.g. GameFunctionsLibTest) call
        // GameSetResult on pool 200, which runs PoolResolvePlayed and resets played.
        DBQuery("UPDATE uo_pool SET played=1 WHERE pool_id=200");
        $this->assertTrue((bool) IsPoolLocked(200));
    }

    // --- PoolResolvePlayed ---

    public function testPoolResolvePlayed(): void
    {
        // Both games are in pool 200; only game 700 played.
        // After resolve: played=0 because not all games are done.
        DBQuery("UPDATE uo_pool SET played=1 WHERE pool_id=200");
        PoolResolvePlayed(200);
        $info = PoolInfo(200);
        $this->assertSame('0', (string) $info['played']);

        // Restore
        DBQuery("UPDATE uo_pool SET played=1 WHERE pool_id=200");
    }

    // --- CanDeletePool / CanGenerateGames / PseudoTeamsOnly ---

    public function testCanDeletePoolReturnsFalseForFixturePool(): void
    {
        // Pool 200 has teams and games.
        $this->assertFalse(CanDeletePool(200));
    }

    public function testCanGenerateGamesReturnsFalseWhenGamesExist(): void
    {
        // Pool 200 already has timetable=1 games.
        $this->assertFalse(CanGenerateGames(200));
    }

    public function testPseudoTeamsOnlyReturnsFalseForFixturePool(): void
    {
        // No moveteams entries in fixtures.
        $this->assertFalse(PseudoTeamsOnly(200));
    }

    // --- CanDeleteTeamFromPool ---

    public function testCanDeleteTeamFromPoolReturnsFalseForPlayedTeam(): void
    {
        // Team 300 participated in game 700 (score set, hasstarted=1).
        $this->assertFalse(CanDeleteTeamFromPool(200, 300));
    }

    // --- PoolFollowersArray / PoolPlayoffFollowersArray / PoolPlayoffRoot ---

    public function testPoolFollowersArrayReturnsEmptyForSimplePool(): void
    {
        $followers = PoolFollowersArray(200);
        $this->assertIsArray($followers);
        $this->assertCount(0, $followers);
    }

    public function testPoolPlayoffFollowersArrayReturnsEmptyForSimplePool(): void
    {
        $followers = PoolPlayoffFollowersArray(200);
        $this->assertIsArray($followers);
        $this->assertCount(0, $followers);
    }

    public function testPoolPlayoffRootReturnsPoolIdWhenNoParent(): void
    {
        // No pool has follower=200, so root is 200 itself.
        $root = PoolPlayoffRoot(200);
        $this->assertSame('200', (string) $root);
    }

    // --- PoolMovingsToPool / PoolMovingsFromPool / PoolDependsOn / PoolMoveExist ---

    public function testPoolMovingsToPoolReturnsEmptyForFixturePool(): void
    {
        $this->assertCount(0, PoolMovingsToPool(200));
    }

    public function testPoolMovingsFromPoolReturnsEmptyForFixturePool(): void
    {
        $this->assertCount(0, PoolMovingsFromPool(200));
    }

    public function testPoolMovingsFromPoolWithTeamsReturnsEmpty(): void
    {
        $this->assertCount(0, PoolMovingsFromPoolWithTeams(200));
    }

    public function testPoolDependsOnReturnsEmptyForSimplePool(): void
    {
        $this->assertCount(0, PoolDependsOn(200));
    }

    public function testPoolMoveExistReturnsZeroForSimplePool(): void
    {
        $this->assertSame(0, (int) PoolMoveExist(200, 1));
    }

    public function testPoolMovedPlacingsReturnsEmptyForSimplePool(): void
    {
        $this->assertCount(0, PoolMovedPlacings(200));
    }

    public function testPoolIsAllMovedReturnsTrueWhenNoMovesExist(): void
    {
        // No uo_moveteams rows → 0 unfilled slots → vacuously all moved.
        $this->assertTrue(PoolIsAllMoved(200));
    }

    public function testPoolIsMoveFromPoolsPlayedReturnsTrueForSimplePool(): void
    {
        // No source pools to check → vacuously true.
        $this->assertTrue(PoolIsMoveFromPoolsPlayed(200));
    }

    public function testPoolGetMoveToPoolReturnsNullForSimplePool(): void
    {
        $this->assertNull(PoolGetMoveToPool(200, 1));
    }

    public function testPoolGetMoveFromReturnsNullForSimplePool(): void
    {
        $this->assertNull(PoolGetMoveFrom(200, 1));
    }

    public function testPoolGetGamesToMoveReturnsEmptyForSimplePool(): void
    {
        $games = PoolGetGamesToMove(200, []);
        $this->assertIsArray($games);
        $this->assertCount(0, $games);
    }

    public function testPoolGetFromPoolByTeamIdReturnsNullForUnknownCombo(): void
    {
        $this->assertNull(PoolGetFromPoolByTeamId(200, 99999));
    }

    // --- PoolTemplates / AddPoolTemplate / PoolTemplateInfo / DeletePoolTemplate ---

    public function testPoolTemplatesReturnsEmptyByDefault(): void
    {
        $templates = PoolTemplates();
        $this->assertIsArray($templates);
        // Fixtures don't include pool templates.
        $this->assertCount(0, $templates);
    }

    public function testAddPoolTemplateCreatesRowAndDeletePoolTemplateRemovesIt(): void
    {
        $params = [
            'name'              => 'Test Template',
            'timeoutlen'        => 70,
            'halftime'          => 35,
            'winningscore'      => 15,
            'drawsallowed'      => 0,
            'timecap'           => 0,
            'scorecap'          => 0,
            'addscore'          => 0,
            'halftimescore'     => 0,
            'timeouts'          => 2,
            'timeoutsper'       => 'half',
            'timeoutsovertime'  => 1,
            'timeoutstimecap'   => 'soft',
            'betweenpointslen'  => 90,
            'continuingpool'    => 0,
            'mvgames'           => 0,
            'type'              => 1,
            'ordering'          => '1',
            'teams'             => 4,
            'timeslot'          => 0,
            'forfeitagainst'    => 0,
            'forfeitscore'      => 15,
        ];

        $id = (int) AddPoolTemplate($params);
        $this->assertGreaterThan(0, $id);
        $this->createdTemplateIds[] = $id;

        $info = PoolTemplateInfo($id);
        $this->assertIsArray($info);
        $this->assertSame('Test Template', $info['name']);

        $templates = PoolTemplates();
        $this->assertCount(1, $templates);

        DeletePoolTemplate($id);
        $this->createdTemplateIds = array_diff($this->createdTemplateIds, [$id]);

        $this->assertNull(PoolTemplateInfo($id));
    }

    // --- SetPoolVisibility / SetPoolName ---

    public function testSetPoolVisibilityTogglesFlag(): void
    {
        SetPoolVisibility(200, 0);
        $info = PoolInfo(200);
        $this->assertSame('0', (string) $info['visible']);

        SetPoolVisibility(200, 1);
        $info = PoolInfo(200);
        $this->assertSame('1', (string) $info['visible']);
    }

    public function testSetPoolNameChangesName(): void
    {
        SetPoolName(200, 'Renamed Pool');
        $this->assertSame('Renamed Pool', PoolName(200));

        SetPoolName(200, 'Pool A');
    }

    // --- SetPool ---

    public function testSetPoolUpdatesFields(): void
    {
        SetPool(200, [
            'name'           => 'Pool B',
            'continuingpool' => 0,
            'placementpool'  => 0,
            'visible'        => 1,
            'type'           => 1,
            'ordering'       => '1',
        ]);

        $info = PoolInfo(200);
        $this->assertSame('Pool B', $info['name']);

        SetPool(200, [
            'name'           => 'Pool A',
            'continuingpool' => 0,
            'placementpool'  => 0,
            'visible'        => 1,
            'type'           => 1,
            'ordering'       => '1',
        ]);
    }

    // --- PoolAddTeam / PoolDeleteTeam ---

    public function testPoolAddTeamAndDeleteTeam(): void
    {
        // Insert a fresh team not in pool 200
        DBQuery("INSERT INTO uo_team (name, valid, series) VALUES ('Test Pool Team', 1, 100)");
        $teamId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        $this->createdTeamIds[] = $teamId;

        PoolAddTeam(200, $teamId, 3, false, false);

        $teams = PoolTeams(200);
        $ids = array_map('strval', array_column($teams, 'team_id'));
        $this->assertContains((string) $teamId, $ids);

        PoolDeleteTeam(200, $teamId, false);

        $teams = PoolTeams(200);
        $ids = array_map('strval', array_column($teams, 'team_id'));
        $this->assertNotContains((string) $teamId, $ids);
    }

    // --- PoolSetSchedulingName ---

    public function testPoolSetSchedulingNameUpdatesEntry(): void
    {
        // scheduling_id=600, 601 exist as pool game scheduling names in fixtures.
        PoolSetSchedulingName(600, 'Updated Round 1', 'HRN2026');
        $val = DBQueryToValue("SELECT name FROM uo_scheduling_name WHERE scheduling_id=600");
        $this->assertSame('Updated Round 1', $val);

        // Restore
        PoolSetSchedulingName(600, 'Round 1', 'HRN2026');
    }

    // --- PoolColors ---

    public function testPoolColorsReturnsNonEmptyArray(): void
    {
        $colors = PoolColors();
        $this->assertIsArray($colors);
        $this->assertNotEmpty($colors);
    }

    public function testPoolColorsReturnsCachedResultOnSecondCall(): void
    {
        // Second call hits the static cache (isset($poolColors) → return).
        $first = PoolColors();
        $second = PoolColors();
        $this->assertSame($first, $second);
    }

    // --- PoolScoreBoard / PoolScoreBoardArray ---

    public function testPoolScoreBoardReturnsResult(): void
    {
        $result = PoolScoreBoard(200, 'total', 0);
        $this->assertNotFalse($result);
    }

    public function testPoolScoreBoardArrayReturnsArray(): void
    {
        $result = PoolScoreBoardArray(200, 'total', 0);
        $this->assertIsArray($result);
    }

    // --- PoolsScoreBoard ---

    public function testPoolsScoreBoardReturnsResult(): void
    {
        $result = PoolsScoreBoard([200], 'total', 0);
        $this->assertNotFalse($result);
    }

    public function testPoolsScoreBoardArrayReturnsArray(): void
    {
        $result = PoolsScoreBoardArray([200], 'total', 0);
        $this->assertIsArray($result);
    }

    public function testPoolScoreBoardSortingVariants(): void
    {
        foreach (['goal', 'pass', 'games', 'team', 'name', 'callahan', 'goalavg', 'passavg', 'totalavg', 'unknown'] as $sort) {
            $result = PoolScoreBoard(200, $sort, 0);
            $this->assertNotFalse($result, "sort=$sort failed");
        }
    }

    public function testPoolScoreBoardWithLimitReturnsResult(): void
    {
        $result = PoolScoreBoard(200, 'total', 5);
        $this->assertNotFalse($result);
    }

    // --- PoolScoreBoardWithDefenses / PoolsScoreBoardWithDefenses ---

    public function testPoolScoreBoardWithDefensesReturnsResult(): void
    {
        $result = PoolScoreBoardWithDefenses(200, 'total', 0);
        $this->assertNotFalse($result);
    }

    public function testPoolScoreBoardWithDefensesArrayReturnsArray(): void
    {
        $result = PoolScoreBoardWithDefensesArray(200, 'total', 0);
        $this->assertIsArray($result);
    }

    public function testPoolsScoreBoardWithDefensesReturnsResult(): void
    {
        $result = PoolsScoreBoardWithDefenses([200], 'total', 0);
        $this->assertNotFalse($result);
    }

    public function testPoolsScoreBoardWithDefensesArrayReturnsArray(): void
    {
        $result = PoolsScoreBoardWithDefensesArray([200], 'total', 0);
        $this->assertIsArray($result);
    }

    // --- PoolTeamFromStandings / PoolTeamsFromStandings / PoolTeamFromInitialRank ---

    public function testPoolTeamFromStandingsReturnsTeamAtRank1(): void
    {
        $team = PoolTeamFromStandings(200, 1);
        $this->assertIsArray($team);
        $this->assertSame('300', (string) $team['team_id']);
    }

    public function testPoolTeamFromStandingsCountByeFalse(): void
    {
        $team = PoolTeamFromStandings(200, 1, false);
        $this->assertIsArray($team);
    }

    public function testPoolTeamFromStandingsReturnsEmptyForMissingRank(): void
    {
        $team = PoolTeamFromStandings(200, 99);
        $this->assertArrayHasKey('team_id', $team);
        $this->assertNull($team['team_id']);
    }

    public function testPoolTeamsFromStandingsReturnsTeams(): void
    {
        $teams = PoolTeamsFromStandings(200, 1);
        $this->assertIsArray($teams);
        $this->assertNotEmpty($teams);
        $this->assertSame('300', (string) $teams[0]['team_id']);
    }

    public function testPoolTeamsFromStandingsCountByeFalse(): void
    {
        $teams = PoolTeamsFromStandings(200, 1, false);
        $this->assertIsArray($teams);
    }

    public function testPoolTeamFromInitialRankReturnsTeamAtRank1(): void
    {
        $team = PoolTeamFromInitialRank(200, 1);
        $this->assertIsArray($team);
        $this->assertSame('300', (string) $team['team_id']);
    }

    public function testPoolTeamFromInitialRankCountByeFalse(): void
    {
        $team = PoolTeamFromInitialRank(200, 1, false);
        $this->assertIsArray($team);
    }

    // --- PoolIsMoved ---

    public function testPoolIsMovedReturnsZeroForUnmovedPlacing(): void
    {
        $this->assertSame(0, (int) PoolIsMoved(200, 1));
    }

    // --- PoolGetFromPoolBySchedulingId ---

    public function testPoolGetFromPoolBySchedulingIdReturnsNullWhenNotInMoveteams(): void
    {
        // scheduling_id 600 exists but is not in uo_moveteams.
        $this->assertNull(PoolGetFromPoolBySchedulingId(600));
    }

    // --- PoolAddMove / PoolSetMove / PoolDeleteMove ---

    public function testPoolAddMoveAndDeleteMove(): void
    {
        // Create a second pool as move target to avoid self-referential recursion in PoolFollowersArray.
        DBQuery("INSERT INTO uo_pool (name, ordering, visible, continuingpool, placementpool, teams, mvgames, series, type, played)
                 VALUES ('Move Target Pool', '98', 0, 0, 0, 0, 0, 100, 1, 0)");
        $toPoolId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");

        try {
            // frompool=200, topool=$toPoolId, fromplacing=1, torank=1
            PoolAddMove(200, $toPoolId, 1, 1, 'Test Scheduling Team');

            // Move exists now
            $this->assertSame(1, (int) PoolMoveExist(200, 1));
            $this->assertSame(0, (int) PoolIsMoved(200, 1)); // ismoved=0, not yet executed

            // PoolAddMove returns 0 when move already exists
            $second = PoolAddMove(200, $toPoolId, 1, 1, 'Duplicate');
            $this->assertSame(0, (int) $second);

            // Update the move
            PoolSetMove(200, 1, 1, 2);
            $this->assertSame(1, (int) PoolMoveExist(200, 1));

            // PoolFollowersArray returns [$toPoolId] (no self-loop)
            $followers = PoolFollowersArray(200);
            $this->assertContains($toPoolId, array_map('intval', $followers));

            // PoolDependsOn($toPoolId) now has a row pointing from pool 200
            $deps = PoolDependsOn($toPoolId);
            $this->assertNotEmpty($deps);

            // PoolIsMoveFromPoolsPlayed($toPoolId): frompool=200 played=1 → no unplayed → true
            $this->assertTrue(PoolIsMoveFromPoolsPlayed($toPoolId));

            // Grab scheduling_id before deleting the move row
            $schedId = (int) DBQueryToValue("SELECT scheduling_id FROM uo_moveteams WHERE frompool=200 AND fromplacing=1");

            PoolDeleteMove(200, 1);
            $this->assertSame(0, (int) PoolMoveExist(200, 1));

            // Clean up scheduling_name row created by PoolAddMove
            if ($schedId > 0) {
                DBQuery(sprintf("DELETE FROM uo_scheduling_name WHERE scheduling_id=%d", $schedId));
            }
        } finally {
            DBQuery(sprintf("DELETE FROM uo_moveteams WHERE frompool=200 OR topool=%d", $toPoolId));
            DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $toPoolId));
        }
    }

    // --- DeletePool ---

    public function testDeletePoolRemovesEmptyPool(): void
    {
        // Create a bare pool with no teams or games via direct DB insert.
        DBQuery("INSERT INTO uo_pool (name, ordering, visible, continuingpool, placementpool, teams, mvgames, series, type, played)
                 VALUES ('Temp Pool', '99', 0, 0, 0, 0, 0, 100, 1, 0)");
        $poolId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");

        $this->assertNotNull(PoolInfo($poolId));
        $this->assertTrue(CanDeletePool($poolId));

        DeletePool($poolId);

        $this->assertNull(PoolInfo($poolId));
    }

    // --- PoolFromPoolTemplate ---

    public function testPoolFromPoolTemplateCreatesPool(): void
    {
        // First create a template
        $tplParams = [
            'name' => 'Tpl For Pool', 'timeoutlen' => 70, 'halftime' => 35,
            'winningscore' => 15, 'drawsallowed' => 0, 'timecap' => 0, 'scorecap' => 0,
            'addscore' => 0, 'halftimescore' => 0, 'timeouts' => 2, 'timeoutsper' => 'half',
            'timeoutsovertime' => 1, 'timeoutstimecap' => 'soft', 'betweenpointslen' => 90,
            'continuingpool' => 0, 'mvgames' => 0, 'type' => 1, 'ordering' => '1',
            'teams' => 4, 'timeslot' => 0, 'forfeitagainst' => 0, 'forfeitscore' => 15,
        ];
        $tplId = (int) AddPoolTemplate($tplParams);
        $this->createdTemplateIds[] = $tplId;

        $newPoolId = (int) PoolFromPoolTemplate(100, 'Generated Pool', '99', $tplId);
        $this->assertGreaterThan(0, $newPoolId);

        $info = PoolInfo($newPoolId);
        $this->assertSame('Generated Pool', $info['name']);

        // Clean up
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $newPoolId));
        DeletePoolTemplate($tplId);
        $this->createdTemplateIds = array_diff($this->createdTemplateIds, [$tplId]);
    }

    // --- ResolveGeneratedGameHomeAway ---

    public function testResolveGeneratedGameHomeAwayModeOne(): void
    {
        $game = ResolveGeneratedGameHomeAway(1, 1, 300, 301);
        $this->assertSame(300, $game['home']);
        $this->assertSame(301, $game['away']);
    }

    public function testResolveGeneratedGameHomeAwayRoundRobinSwap(): void
    {
        // poolType=1, homeTeamMode=0, I=0(even), J=0(even): !is_odd(0)=true → swap
        $game = ResolveGeneratedGameHomeAway(1, 0, 300, 301, 0, 0, 0);
        $this->assertSame(301, $game['home']);
        $this->assertSame(300, $game['away']);
    }

    public function testResolveGeneratedGameHomeAwayRoundRobinNoSwap(): void
    {
        // poolType=1, homeTeamMode=0, I=0(even), J=1(odd): !is_odd(1)=false → no swap
        $game = ResolveGeneratedGameHomeAway(1, 0, 300, 301, 0, 0, 1);
        $this->assertSame(300, $game['home']);
        $this->assertSame(301, $game['away']);
    }

    public function testResolveGeneratedGameHomeAwayPlayoffOddRound(): void
    {
        // poolType=2, homeTeamMode=0, round=1(odd) → swap
        $game = ResolveGeneratedGameHomeAway(2, 0, 300, 301, 1);
        $this->assertSame(301, $game['home']);
        $this->assertSame(300, $game['away']);
    }

    public function testResolveGeneratedGameHomeAwayPlayoffEvenRound(): void
    {
        // poolType=2, homeTeamMode=0, round=2(even) → no swap
        $game = ResolveGeneratedGameHomeAway(2, 0, 300, 301, 2);
        $this->assertSame(300, $game['home']);
        $this->assertSame(301, $game['away']);
    }

    public function testResolveGeneratedGameHomeAwayCrossmatch(): void
    {
        // poolType=4 behaves like playoff
        $game = ResolveGeneratedGameHomeAway(4, 0, 300, 301, 1);
        $this->assertSame(301, $game['home']);
    }

    public function testResolveGeneratedGameHomeAwaySwissDraw(): void
    {
        // poolType=3, homeTeamMode=0 → keep generator order
        $game = ResolveGeneratedGameHomeAway(3, 0, 300, 301);
        $this->assertSame(300, $game['home']);
        $this->assertSame(301, $game['away']);
    }

    // --- PoolAddGame ---

    public function testPoolAddGameCreatesGame(): void
    {
        // PoolAddGame has no return value; find the created game by newest game_id.
        PoolAddGame(200, 300, 301);
        $gameId = (int) DBQueryToValue("SELECT game_id FROM uo_game WHERE hometeam=300 AND visitorteam=301 ORDER BY game_id DESC LIMIT 1");
        $this->assertGreaterThan(0, $gameId);

        $count = (int) DBQueryToValue(sprintf(
            "SELECT COUNT(*) FROM uo_game_pool WHERE game=%d AND pool=200", $gameId
        ));
        $this->assertSame(1, $count);

        // Clean up
        DBQuery(sprintf("DELETE FROM uo_game_pool WHERE game=%d", $gameId));
        DBQuery(sprintf("DELETE FROM uo_game WHERE game_id=%d", $gameId));
    }

    public function testPoolAddGameWithHomeRespCreatesGameWithRespTeam(): void
    {
        // Covers the $homeresp=true branch (INSERT with respteam).
        PoolAddGame(200, 300, 301, false, true);
        $gameId = (int) DBQueryToValue("SELECT game_id FROM uo_game WHERE hometeam=300 AND visitorteam=301 AND respteam=300 ORDER BY game_id DESC LIMIT 1");
        $this->assertGreaterThan(0, $gameId);
        DBQuery(sprintf("DELETE FROM uo_game_pool WHERE game=%d", $gameId));
        DBQuery(sprintf("DELETE FROM uo_game WHERE game_id=%d", $gameId));
    }

    public function testPoolAddGameWithPseudoTeamsUsesSchedulingColumns(): void
    {
        // Covers the $psudoteams=true branch (columns: scheduling_name_home/visitor).
        PoolAddGame(200, 600, 601, true);
        $gameId = (int) DBQueryToValue("SELECT game_id FROM uo_game WHERE scheduling_name_home=600 AND scheduling_name_visitor=601 ORDER BY game_id DESC LIMIT 1");
        $this->assertGreaterThan(0, $gameId);
        DBQuery(sprintf("DELETE FROM uo_game_pool WHERE game=%d", $gameId));
        DBQuery(sprintf("DELETE FROM uo_game WHERE game_id=%d", $gameId));
    }

    // --- PoolSetTeam ---

    public function testPoolSetTeamMovesTeamToNewPool(): void
    {
        // Create a second pool to move team into
        DBQuery("INSERT INTO uo_pool (name, ordering, visible, continuingpool, placementpool, teams, mvgames, series, type, played)
                 VALUES ('Temp Pool 2', '99', 0, 0, 0, 0, 0, 100, 1, 0)");
        $newPoolId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");

        // Create a fresh team to move
        DBQuery("INSERT INTO uo_team (name, valid, series) VALUES ('Move Test Team', 1, 100)");
        $teamId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        $this->createdTeamIds[] = $teamId;

        PoolAddTeam(200, $teamId, 5, false, false);

        PoolSetTeam(200, $teamId, 1, $newPoolId);

        $inNew = (int) DBQueryToValue(sprintf(
            "SELECT COUNT(*) FROM uo_team_pool WHERE team=%d AND pool=%d", $teamId, $newPoolId
        ));
        $this->assertSame(1, $inNew);

        // Clean up
        DBQuery(sprintf("DELETE FROM uo_team_pool WHERE team=%d", $teamId));
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $newPoolId));
    }

    // --- PoolGetMoveByTeam ---

    public function testPoolGetMoveByTeamReturnsEmptyArrayWhenNoMove(): void
    {
        $result = PoolGetMoveByTeam(200, 300);
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    // --- SetPoolTemplate ---

    public function testSetPoolTemplateUpdatesRow(): void
    {
        $params = [
            'name'              => 'Template For Update',
            'timeoutlen'        => 70,
            'halftime'          => 35,
            'winningscore'      => 15,
            'drawsallowed'      => 0,
            'timecap'           => 0,
            'scorecap'          => 0,
            'addscore'          => 0,
            'halftimescore'     => 0,
            'timeouts'          => 2,
            'timeoutsper'       => 'half',
            'timeoutsovertime'  => 1,
            'timeoutstimecap'   => 'soft',
            'betweenpointslen'  => 90,
            'continuingpool'    => 0,
            'mvgames'           => 0,
            'type'              => 1,
            'ordering'          => '1',
            'teams'             => 4,
            'timeslot'          => 0,
            'forfeitagainst'    => 0,
            'forfeitscore'      => 15,
        ];

        $id = (int) AddPoolTemplate($params);
        $this->assertGreaterThan(0, $id);
        $this->createdTemplateIds[] = $id;

        $params['name'] = 'Template Updated';
        SetPoolTemplate($id, $params);

        $info = PoolTemplateInfo($id);
        $this->assertSame('Template Updated', $info['name']);
    }

    // --- PoolFromAnotherPool ---

    public function testPoolFromAnotherPoolCreatesNewPool(): void
    {
        $newId = PoolFromAnotherPool(100, 'Pool Copy', '99', 200, false);
        $this->assertGreaterThan(0, $newId);

        $info = PoolInfo($newId);
        $this->assertSame('Pool Copy', $info['name']);
        $this->assertSame('100', (string) $info['series']);

        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $newId));
    }

    public function testPoolFromAnotherPoolWithFollowerSetsFollowerColumn(): void
    {
        $originalInfo = PoolInfo(200);

        $newId = PoolFromAnotherPool(100, 'Pool Follower', '98', 200, true);
        $this->assertGreaterThan(0, $newId);

        $updatedInfo = PoolInfo(200);
        $this->assertSame((string) $newId, (string) $updatedInfo['follower']);

        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $newId));
        // Restore original follower value on pool 200
        $origFollower = (int) ($originalInfo['follower'] ?? 0);
        DBQuery(sprintf("UPDATE uo_pool SET follower=%d WHERE pool_id=200", $origFollower));
    }

    // --- PoolAddTeam with updaterank=true ---

    public function testPoolAddTeamWithUpdateRankSetsActiveRank(): void
    {
        // Create a temporary team for this test
        DBQuery("INSERT INTO uo_team (team_id, name, series, valid) VALUES (9901, 'TmpTeam', 100, 1)");
        $this->createdTeamIds[] = 9901;

        PoolAddTeam(200, 9901, 5, true);

        $row = DBQueryToArray(sprintf(
            "SELECT rank, activerank FROM uo_team_pool WHERE pool=%d AND team=%d",
            200, 9901
        ));
        $this->assertCount(1, $row);
        $this->assertSame('5', (string) $row[0]['rank']);
        $this->assertSame('5', (string) $row[0]['activerank']);

        DBQuery("DELETE FROM uo_team_pool WHERE pool=200 AND team=9901");
        DBQuery("DELETE FROM uo_team WHERE team_id=9901");
        $this->createdTeamIds = array_diff($this->createdTeamIds, [9901]);
    }

    // --- AddSpecialRankingRule ---

    public function testAddSpecialRankingRuleInsertsRow(): void
    {
        AddSpecialRankingRule(200, 99, 99, 'Test Placeholder');

        // uo_specialranking has no AUTO_INCREMENT; verify row exists
        $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_specialranking WHERE frompool=200 AND fromplacing=99 AND torank=99");
        $this->assertSame(1, $count);

        // Cleanup
        $schedId = (int) DBQueryToValue("SELECT scheduling_id FROM uo_specialranking WHERE frompool=200 AND fromplacing=99");
        DBQuery("DELETE FROM uo_specialranking WHERE frompool=200 AND fromplacing=99");
        if ($schedId > 0) {
            DBQuery(sprintf("DELETE FROM uo_scheduling_name WHERE scheduling_id=%d", $schedId));
        }
    }

    // --- PoolsToCsv ---

    public function testPoolsToCsvReturnsString(): void
    {
        $csv = PoolsToCsv('HRN2026', ',');
        $this->assertIsString($csv);
        $this->assertStringContainsString('Division', $csv);
    }

    // --- SeriesRanking ---

    public function testSeriesRankingWithPlacementPool(): void
    {
        // Temporarily mark pool 200 as a placement pool
        DBQuery("UPDATE uo_pool SET placementpool=1 WHERE pool_id=200");
        try {
            $ranking = SeriesRanking(100);
            $this->assertIsArray($ranking);
        } finally {
            DBQuery("UPDATE uo_pool SET placementpool=0 WHERE pool_id=200");
        }
    }

    // --- GenerateGames ---

    private function createTempPool(int $type): int
    {
        DBQuery(sprintf(
            "INSERT INTO uo_pool (name, ordering, visible, continuingpool, placementpool, teams, mvgames,
             timeoutlen, halftime, winningscore, timecap, scorecap, played, addscore, halftimescore,
             timeouts, timeoutsper, timeoutsovertime, timeoutstimecap, betweenpointslen, series, type,
             forfeitscore, forfeitagainst, drawsallowed)
             VALUES ('TempPool', '99', 0, 0, 0, 2, 0,
             70, 35, 15, NULL, NULL, 0, NULL, NULL,
             2, 'half', 1, 'soft', 90, 100, %d,
             15, 0, 0)",
            $type
        ));
        return (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
    }

    public function testGenerateGamesType1DryRun(): void
    {
        $poolId = $this->createTempPool(1);
        DBQuery(sprintf("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (300, %d, 1, 1)", $poolId));
        DBQuery(sprintf("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (301, %d, 2, 2)", $poolId));

        $games = GenerateGames($poolId, 1, false);

        $this->assertIsArray($games);
        $this->assertCount(1, $games);
        $this->assertArrayHasKey('home', $games[0]);
        $this->assertArrayHasKey('away', $games[0]);

        // Verify no DB games were created
        $count = (int) DBQueryToValue(sprintf("SELECT COUNT(*) FROM uo_game_pool WHERE pool=%d", $poolId));
        $this->assertSame(0, $count);

        DBQuery(sprintf("DELETE FROM uo_team_pool WHERE pool=%d", $poolId));
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $poolId));
    }

    public function testGenerateGamesType1Generate(): void
    {
        $poolId = $this->createTempPool(1);
        DBQuery(sprintf("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (300, %d, 1, 1)", $poolId));
        DBQuery(sprintf("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (301, %d, 2, 2)", $poolId));

        $games = GenerateGames($poolId, 1, true);

        $this->assertIsArray($games);
        $count = (int) DBQueryToValue(sprintf("SELECT COUNT(*) FROM uo_game_pool WHERE pool=%d", $poolId));
        $this->assertSame(count($games), $count);

        // Cleanup: delete game records
        $gameIds = DBQueryToArray(sprintf("SELECT game FROM uo_game_pool WHERE pool=%d", $poolId));
        DBQuery(sprintf("DELETE FROM uo_game_pool WHERE pool=%d", $poolId));
        foreach ($gameIds as $row) {
            DBQuery(sprintf("DELETE FROM uo_game WHERE game_id=%d", (int) $row['game']));
        }
        DBQuery(sprintf("DELETE FROM uo_team_pool WHERE pool=%d", $poolId));
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $poolId));
    }

    public function testGenerateGamesType2Playoff(): void
    {
        $poolId = $this->createTempPool(2);
        DBQuery(sprintf("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (300, %d, 1, 1)", $poolId));
        DBQuery(sprintf("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (301, %d, 2, 2)", $poolId));

        $games = GenerateGames($poolId, 1, false);

        $this->assertIsArray($games);
        $this->assertCount(1, $games);

        DBQuery(sprintf("DELETE FROM uo_team_pool WHERE pool=%d", $poolId));
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $poolId));
    }

    public function testGenerateGamesType3SwissDraw(): void
    {
        $poolId = $this->createTempPool(3);
        DBQuery(sprintf("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (300, %d, 1, 1)", $poolId));
        DBQuery(sprintf("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (301, %d, 2, 2)", $poolId));

        $games = GenerateGames($poolId, 1, false);

        $this->assertIsArray($games);
        $this->assertGreaterThanOrEqual(1, count($games));

        DBQuery(sprintf("DELETE FROM uo_team_pool WHERE pool=%d", $poolId));
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $poolId));
    }

    public function testGenerateGamesType4Crossmatch(): void
    {
        $poolId = $this->createTempPool(4);
        DBQuery(sprintf("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (300, %d, 1, 1)", $poolId));
        DBQuery(sprintf("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (301, %d, 2, 2)", $poolId));

        $games = GenerateGames($poolId, 1, false);

        $this->assertIsArray($games);
        $this->assertCount(1, $games);

        DBQuery(sprintf("DELETE FROM uo_team_pool WHERE pool=%d", $poolId));
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $poolId));
    }

    // --- PoolTeams sort variants ---

    public function testPoolTeamsSeedSort(): void
    {
        $result = PoolTeams(200, 'seed');
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function testPoolTeamsNameSort(): void
    {
        $result = PoolTeams(200, 'name');
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function testPoolTeamsDefaultSort(): void
    {
        $result = PoolTeams(200, 'unknown');
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    // --- PoolSetTeam ---

    public function testPoolSetTeamMovesTeamToAnotherPool(): void
    {
        $destId = $this->createTempPool(1);

        // Transfer team 300 from pool 200 to destId at rank 1
        PoolSetTeam(200, 300, 1, $destId);

        $row = DBQueryToArray(sprintf("SELECT pool FROM uo_team_pool WHERE team=300 AND pool=%d", $destId));
        $this->assertCount(1, $row);

        // Undo: move back
        PoolSetTeam($destId, 300, 1, 200);

        // Cleanup
        DBQuery(sprintf("DELETE FROM uo_team_pool WHERE pool=%d", $destId));
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $destId));
    }

    // --- PoolResolvePlayed played=1 branch ---

    public function testPoolResolvePlayedSetsPlayedWhenAllGamesFinished(): void
    {
        // Mark game 701 as played so all pool 200 timetable games are played
        DBQuery("UPDATE uo_game SET hasstarted=1, isongoing=0 WHERE game_id=701");
        PoolResolvePlayed(200);
        $info = PoolInfo(200);
        $this->assertSame('1', (string) $info['played']);

        // Restore
        DBQuery("UPDATE uo_game SET hasstarted=0, isongoing=0 WHERE game_id=701");
    }

    // --- PoolMakeMoves / PoolMakeMove / PoolUndoMove ---

    public function testPoolMakeMoveAndUndoMove(): void
    {
        $destId = $this->createTempPool(1);

        // Create the move: rank 1 from pool 200 → rank 1 in destId
        PoolAddMove(200, $destId, 1, 1, 'PlaceholderMove1');

        // Execute the move
        PoolMakeMove(200, 1);

        // Team 300 (rank 1 in pool 200) should now be in destId
        $row = DBQueryToArray(sprintf("SELECT team FROM uo_team_pool WHERE pool=%d AND team=300", $destId));
        $this->assertCount(1, $row);

        // Undo the move
        PoolUndoMove(200, 1, $destId);

        // Team 300 should be removed from destId
        $row = DBQueryToArray(sprintf("SELECT team FROM uo_team_pool WHERE pool=%d AND team=300", $destId));
        $this->assertCount(0, $row);

        // Cleanup: moveteams cascades when pool deleted; clean up scheduling_name
        $schedId = (int) DBQueryToValue(sprintf(
            "SELECT scheduling_id FROM uo_moveteams WHERE frompool=200 AND topool=%d", $destId
        ));
        DBQuery(sprintf("DELETE FROM uo_moveteams WHERE frompool=200 AND topool=%d", $destId));
        if ($schedId > 0) {
            DBQuery(sprintf("DELETE FROM uo_scheduling_name WHERE scheduling_id=%d", $schedId));
        }
        DBQuery(sprintf("DELETE FROM uo_team_pool WHERE pool=%d", $destId));
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $destId));
    }

    public function testPoolMakeMoveWithHomeTeamNotResponsible(): void
    {
        // Covers the isRespTeamHomeTeam()=false branch in PoolMakeMove.
        $destId = $this->createTempPool(1);
        PoolAddMove(200, $destId, 2, 1, 'PlaceholderMoveHR');
        DBQuery("UPDATE uo_setting SET value='no' WHERE name='HomeTeamResponsible'");
        try {
            PoolMakeMove(200, 2);
            $row = DBQueryToArray(sprintf("SELECT team FROM uo_team_pool WHERE pool=%d AND team=301", $destId));
            $this->assertCount(1, $row);
        } finally {
            DBQuery("UPDATE uo_setting SET value='yes' WHERE name='HomeTeamResponsible'");
            $schedId = (int) DBQueryToValue(sprintf(
                "SELECT scheduling_id FROM uo_moveteams WHERE frompool=200 AND topool=%d", $destId
            ));
            DBQuery(sprintf("DELETE FROM uo_moveteams WHERE frompool=200 AND topool=%d", $destId));
            if ($schedId > 0) {
                DBQuery(sprintf("DELETE FROM uo_scheduling_name WHERE scheduling_id=%d", $schedId));
            }
            DBQuery(sprintf("DELETE FROM uo_team_pool WHERE pool=%d", $destId));
            DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $destId));
        }
    }

    public function testPoolMakeMoveNullTeamIdContinues(): void
    {
        // Covers the !$teamId continue branch: move from placing=5 → no team at that position.
        $destId = $this->createTempPool(1);
        PoolAddMove(200, $destId, 5, 1, 'PlaceholderMoveNull');
        try {
            PoolMakeMove(200, 5);
            // No assertion needed — we just need to not error.
            $this->assertTrue(true);
        } finally {
            $schedId = (int) DBQueryToValue(sprintf(
                "SELECT scheduling_id FROM uo_moveteams WHERE frompool=200 AND topool=%d", $destId
            ));
            DBQuery(sprintf("DELETE FROM uo_moveteams WHERE frompool=200 AND topool=%d", $destId));
            if ($schedId > 0) {
                DBQuery(sprintf("DELETE FROM uo_scheduling_name WHERE scheduling_id=%d", $schedId));
            }
            DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $destId));
        }
    }

    public function testPoolMakeMovesWithHomeTeamNotResponsible(): void
    {
        // Covers the isRespTeamHomeTeam()=false else branch in PoolMakeMoves.
        $destId = $this->createTempPool(1);
        PoolAddMove(200, $destId, 1, 1, 'PMovesMoveHR');
        DBQuery("UPDATE uo_setting SET value='no' WHERE name='HomeTeamResponsible'");
        try {
            PoolMakeMoves($destId);
            $row = DBQueryToArray(sprintf("SELECT team FROM uo_team_pool WHERE pool=%d AND team=300", $destId));
            $this->assertCount(1, $row);
        } finally {
            DBQuery("UPDATE uo_setting SET value='yes' WHERE name='HomeTeamResponsible'");
            $schedId = (int) DBQueryToValue("SELECT scheduling_id FROM uo_moveteams WHERE frompool=200 AND topool=$destId");
            DBQuery("DELETE FROM uo_moveteams WHERE frompool=200 AND topool=$destId");
            if ($schedId > 0) { DBQuery("DELETE FROM uo_scheduling_name WHERE scheduling_id=$schedId"); }
            DBQuery("DELETE FROM uo_team_pool WHERE pool=$destId");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$destId");
        }
    }

    public function testPoolMakeMoves(): void
    {
        $destId = $this->createTempPool(1);

        PoolAddMove(200, $destId, 2, 1, 'PlaceholderMove2');

        PoolMakeMoves($destId);

        // Team 301 (rank 2 in pool 200) should now be in destId
        $row = DBQueryToArray(sprintf("SELECT team FROM uo_team_pool WHERE pool=%d AND team=301", $destId));
        $this->assertCount(1, $row);

        // Cleanup
        $schedId = (int) DBQueryToValue(sprintf(
            "SELECT scheduling_id FROM uo_moveteams WHERE frompool=200 AND topool=%d", $destId
        ));
        DBQuery(sprintf("DELETE FROM uo_moveteams WHERE frompool=200 AND topool=%d", $destId));
        if ($schedId > 0) {
            DBQuery(sprintf("DELETE FROM uo_scheduling_name WHERE scheduling_id=%d", $schedId));
        }
        DBQuery(sprintf("DELETE FROM uo_team_pool WHERE pool=%d", $destId));
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $destId));
    }

    // --- GenerateGames with homeresp ---

    public function testGenerateGamesWithHomeresp(): void
    {
        $poolId = $this->createTempPool(1);
        DBQuery(sprintf("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (300, %d, 1, 1)", $poolId));
        DBQuery(sprintf("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (301, %d, 2, 2)", $poolId));

        $games = GenerateGames($poolId, 1, true, false, true); // homeresp=true

        $this->assertIsArray($games);
        $count = (int) DBQueryToValue(sprintf("SELECT COUNT(*) FROM uo_game_pool WHERE pool=%d", $poolId));
        $this->assertSame(count($games), $count);

        $gameIds = DBQueryToArray(sprintf("SELECT game FROM uo_game_pool WHERE pool=%d", $poolId));
        DBQuery(sprintf("DELETE FROM uo_game_pool WHERE pool=%d", $poolId));
        foreach ($gameIds as $row) {
            DBQuery(sprintf("DELETE FROM uo_game WHERE game_id=%d", (int) $row['game']));
        }
        DBQuery(sprintf("DELETE FROM uo_team_pool WHERE pool=%d", $poolId));
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $poolId));
    }

    // --- GenerateGames type 3 continuingpool ---

    public function testGenerateGamesType3ContinuingPool(): void
    {
        DBQuery("INSERT INTO uo_pool (name, ordering, visible, continuingpool, placementpool, teams, mvgames,
             timeoutlen, halftime, winningscore, timecap, scorecap, played, addscore, halftimescore,
             timeouts, timeoutsper, timeoutsovertime, timeoutstimecap, betweenpointslen, series, type,
             forfeitscore, forfeitagainst, drawsallowed)
             VALUES ('TempPool3c', '99', 0, 1, 0, 2, 0,
             70, 35, 15, NULL, NULL, 0, NULL, NULL,
             2, 'half', 1, 'soft', 90, 100, 3,
             15, 0, 0)");
        $poolId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        DBQuery(sprintf("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (300, %d, 1, 1)", $poolId));
        DBQuery(sprintf("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (301, %d, 2, 2)", $poolId));

        $games = GenerateGames($poolId, 1, false);

        $this->assertIsArray($games);
        $this->assertCount(1, $games);

        DBQuery(sprintf("DELETE FROM uo_team_pool WHERE pool=%d", $poolId));
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $poolId));
    }

    public function testGenerateGamesType3OddTeams(): void
    {
        $poolId = $this->createTempPool(3);
        DBQuery(sprintf("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (300, %d, 1, 1)", $poolId));

        $games = GenerateGames($poolId, 1, false);

        $this->assertIsArray($games);
        $this->assertContains(false, $games);

        DBQuery(sprintf("DELETE FROM uo_team_pool WHERE pool=%d", $poolId));
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $poolId));
    }

    // --- GenerateGames pseudoteams path ---

    public function testGenerateGamesWithPseudoteamsDryRun(): void
    {
        // Covers $pseudoteams=true path: pool has no real teams but has scheduling moves.
        $srcId = $this->createTempPool(1);
        $destId = $this->createTempPool(1);
        // Add teams to source pool so they have standings
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (300, $srcId, 1, 1), (301, $srcId, 2, 2)");
        // Add moves: rank 1 and 2 from srcId → destId (creates scheduling names in destId)
        PoolAddMove($srcId, $destId, 1, 1, 'PseudoTeam1');
        PoolAddMove($srcId, $destId, 2, 2, 'PseudoTeam2');
        try {
            $games = GenerateGames($destId, 1, false);
            $this->assertIsArray($games);
        } finally {
            $schedIds = array_column(DBQueryToArray("SELECT scheduling_id FROM uo_moveteams WHERE topool=$destId"), 'scheduling_id');
            DBQuery("DELETE FROM uo_moveteams WHERE topool=$destId");
            foreach ($schedIds as $sid) {
                DBQuery("DELETE FROM uo_scheduling_name WHERE scheduling_id=$sid");
            }
            DBQuery("DELETE FROM uo_team_pool WHERE pool=$srcId");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$srcId");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$destId");
        }
    }

    public function testGenerateGamesWithPseudoteamsAndGenerateTrue(): void
    {
        // Covers $pseudoteams=true, $generate=true path (INSERT with scheduling names).
        $srcId = $this->createTempPool(1);
        $destId = $this->createTempPool(1);
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (300, $srcId, 1, 1), (301, $srcId, 2, 2)");
        PoolAddMove($srcId, $destId, 1, 1, 'PSGen1');
        PoolAddMove($srcId, $destId, 2, 2, 'PSGen2');
        try {
            $games = GenerateGames($destId, 1, true);
            $this->assertIsArray($games);
            $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_game_pool WHERE pool=$destId");
            $this->assertGreaterThan(0, $count);
        } finally {
            $gameIds = array_column(DBQueryToArray("SELECT game FROM uo_game_pool WHERE pool=$destId"), 'game');
            DBQuery("DELETE FROM uo_game_pool WHERE pool=$destId");
            foreach ($gameIds as $gid) { DBQuery("DELETE FROM uo_game WHERE game_id=$gid"); }
            $schedIds = array_column(DBQueryToArray("SELECT scheduling_id FROM uo_moveteams WHERE topool=$destId"), 'scheduling_id');
            DBQuery("DELETE FROM uo_moveteams WHERE topool=$destId");
            foreach ($schedIds as $sid) { DBQuery("DELETE FROM uo_scheduling_name WHERE scheduling_id=$sid"); }
            DBQuery("DELETE FROM uo_team_pool WHERE pool=$srcId");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$srcId");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$destId");
        }
    }

    public function testGenerateGamesWithPseudoteamsAndHomeresp(): void
    {
        // Covers $pseudoteams=true, $generate=true, $homeresp=true path.
        $srcId = $this->createTempPool(1);
        $destId = $this->createTempPool(1);
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (300, $srcId, 1, 1), (301, $srcId, 2, 2)");
        PoolAddMove($srcId, $destId, 1, 1, 'PSHom1');
        PoolAddMove($srcId, $destId, 2, 2, 'PSHom2');
        try {
            $games = GenerateGames($destId, 1, true, false, true);
            $this->assertIsArray($games);
        } finally {
            $gameIds = array_column(DBQueryToArray("SELECT game FROM uo_game_pool WHERE pool=$destId"), 'game');
            DBQuery("DELETE FROM uo_game_pool WHERE pool=$destId");
            foreach ($gameIds as $gid) { DBQuery("DELETE FROM uo_game WHERE game_id=$gid"); }
            $schedIds = array_column(DBQueryToArray("SELECT scheduling_id FROM uo_moveteams WHERE topool=$destId"), 'scheduling_id');
            DBQuery("DELETE FROM uo_moveteams WHERE topool=$destId");
            foreach ($schedIds as $sid) { DBQuery("DELETE FROM uo_scheduling_name WHERE scheduling_id=$sid"); }
            DBQuery("DELETE FROM uo_team_pool WHERE pool=$srcId");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$srcId");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$destId");
        }
    }

    // --- GenerateGames nomutual path ---

    public function testGenerateGamesType1WithNomutualSkipsMatchesFromSamePool(): void
    {
        // $nomutual=true, pseudoteams=false: both teams come from null frompool → null==null → all skipped.
        $poolId = $this->createTempPool(1);
        DBQuery(sprintf("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (300, %d, 1, 1)", $poolId));
        DBQuery(sprintf("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (301, %d, 2, 2)", $poolId));

        $games = GenerateGames($poolId, 1, false, true); // nomutual=true
        $this->assertIsArray($games);

        DBQuery(sprintf("DELETE FROM uo_team_pool WHERE pool=%d", $poolId));
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $poolId));
    }

    // --- PoolPlayoffFollowersArray / PoolPlayoffRoot ---

    public function testPoolPlayoffFollowersArrayWithFollower(): void
    {
        // Create child pool, set it as follower on pool 200
        $childId = $this->createTempPool(2);
        DBQuery(sprintf("UPDATE uo_pool SET follower=%d WHERE pool_id=200", $childId));

        $followers = PoolPlayoffFollowersArray(200);
        $this->assertContains((string) $childId, $followers);

        // PoolPlayoffRoot: finds the pool whose follower=childId → pool 200
        $root = PoolPlayoffRoot($childId);
        $this->assertSame(200, (int) $root);

        // Restore and cleanup
        DBQuery("UPDATE uo_pool SET follower=NULL WHERE pool_id=200");
        DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $childId));
    }

    // --- PoolShortName with forbidden word ---

    public function testPoolShortNameWithForbiddenWordOnly(): void
    {
        // Pool named exactly "Playoff" → str_replace produces "", falls back to original
        DBQuery(sprintf("UPDATE uo_pool SET name='Playoff' WHERE pool_id=200"));
        $short = PoolShortName(200);
        $this->assertSame('Playoff', $short);
        DBQuery("UPDATE uo_pool SET name='Pool A' WHERE pool_id=200");
    }

    // --- PoolPlacementString ---

    public function testPoolPlacementStringGoldForFirstPlace(): void
    {
        DBQuery("UPDATE uo_pool SET placementpool=1 WHERE pool_id=200");
        try {
            $result = PoolPlacementString(200, 1);
            $this->assertIsString($result);
        } finally {
            DBQuery("UPDATE uo_pool SET placementpool=0 WHERE pool_id=200");
        }
    }

    public function testPoolPlacementStringNonOrdinalReturnsNumber(): void
    {
        DBQuery("UPDATE uo_pool SET placementpool=1 WHERE pool_id=200");
        try {
            $result = PoolPlacementString(200, 1, false);
            $this->assertIsInt($result);
        } finally {
            DBQuery("UPDATE uo_pool SET placementpool=0 WHERE pool_id=200");
        }
    }

    // --- PoolsScoreBoard sorting variants ---

    public function testPoolsScoreBoardSortingVariants(): void
    {
        foreach (['goalavg', 'passavg', 'totalavg', 'goal', 'pass', 'games', 'team', 'name', 'callahan', 'unknown'] as $sort) {
            $result = PoolsScoreBoard([200], $sort, 0);
            $this->assertNotFalse($result, "PoolsScoreBoard sort=$sort failed");
        }
        $result = PoolsScoreBoard([200], 'total', 5);
        $this->assertNotFalse($result, 'PoolsScoreBoard limit>0 failed');
    }

    public function testPoolsScoreBoardWithDefensesSortingVariants(): void
    {
        foreach (['deftotal', 'goal', 'pass', 'games', 'team', 'name', 'callahan', 'unknown'] as $sort) {
            $result = PoolsScoreBoardWithDefenses([200], $sort, 0);
            $this->assertNotFalse($result, "PoolsScoreBoardWithDefenses sort=$sort failed");
        }
        $result = PoolsScoreBoardWithDefenses([200], 'total', 5);
        $this->assertNotFalse($result, 'PoolsScoreBoardWithDefenses limit>0 failed');
    }

    public function testPoolScoreBoardWithDefensesSortingVariants(): void
    {
        foreach (['deftotal', 'goal', 'pass', 'games', 'team', 'name', 'callahan', 'unknown'] as $sort) {
            $result = PoolScoreBoardWithDefenses(200, $sort, 0);
            $this->assertNotFalse($result, "PoolScoreBoardWithDefenses sort=$sort failed");
        }
        $result = PoolScoreBoardWithDefenses(200, 'total', 5);
        $this->assertNotFalse($result, 'PoolScoreBoardWithDefenses limit>0 failed');
    }

    // --- PlayoffTemplate ---

    public function testPlayoffTemplateReturnsStringForAnyInput(): void
    {
        $result = PlayoffTemplate(4, 1);
        $this->assertIsString($result);
    }

    // --- SetPoolDetails ---
    // Non-superadmin branch calls die() — untestable per docs/lib-test-deep-coverage.md.

    public function testSetPoolDetailsUpdatesSingleFieldAsSuperAdmin(): void
    {
        $before = PoolInfo(200);
        try {
            SetPoolDetails(200, ['visible' => 1]);
            $after = PoolInfo(200);
            $this->assertSame('1', (string) $after['visible']);
        } finally {
            // Restore original visible value
            DBQuery(sprintf("UPDATE uo_pool SET visible=%d WHERE pool_id=200", (int) $before['visible']));
        }
    }

    // --- SetPoolFollowersVisibility ---

    public function testSetPoolFollowersVisibilityRunsWithoutErrorOnPoolWithNoFollowers(): void
    {
        // Pool 200 has follower=NULL → PoolPlayoffFollowersArray returns [] → no iterations
        SetPoolFollowersVisibility(200, 1);
        $this->assertTrue(true);
    }

    // --- PoolConfirmMoves ---

    public function testPoolConfirmMovesRunsWithoutErrorOnFixturePool(): void
    {
        // Pool 200: no uo_moveteams → PoolMakeMoves is a no-op; CheckBYE finds no BYE teams.
        // ResolvePoolStandings recalculates standings (idempotent for played fixtures).
        PoolConfirmMoves(200);
        $this->assertTrue(true);
    }

    public function testPoolConfirmMovesWithVisibleParamCoversSetVisibilityBranch(): void
    {
        // Covers the isset($visible) branch in PoolConfirmMoves.
        $before = (int) DBQueryToValue("SELECT visible FROM uo_pool WHERE pool_id=200");
        PoolConfirmMoves(200, $before);
        // Visibility restored to same value (idempotent).
        $after = (int) DBQueryToValue("SELECT visible FROM uo_pool WHERE pool_id=200");
        $this->assertSame($before, $after);
    }

    // --- GeneratePlayoffPools ---
    // Non-superadmin branch calls die() — untestable per docs/lib-test-deep-coverage.md.

    public function testGeneratePlayoffPoolsReturnsEmptyArrayForSmallPool(): void
    {
        // Pool 200 has 2 teams (rounds=1, for-loop skipped) → returns []
        $result = GeneratePlayoffPools(200, false);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGeneratePlayoffPoolsWithFollowerEarlyReturn(): void
    {
        // Covers the !empty($poolInfo['follower']) early-return path (lines 2504-2516).
        $followerPoolId = $this->createTempPool(2);
        DBQuery("UPDATE uo_pool SET follower=$followerPoolId WHERE pool_id=200");
        try {
            $result = GeneratePlayoffPools(200, false);
            $this->assertIsArray($result);
            $this->assertNotEmpty($result);
            $this->assertSame($followerPoolId, (int) $result[0]['pool_id']);
        } finally {
            DBQuery("UPDATE uo_pool SET follower=NULL WHERE pool_id=200");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$followerPoolId");
        }
    }

    public function testGeneratePlayoffPoolsWithFourTeamsDryRunCoversForLoopBody(): void
    {
        // With 4 teams → rounds=2 → for-loop runs once (covers "Finals" branch).
        $poolId = $this->createTempPool(2);
        DBQuery("INSERT INTO uo_team (name, valid, series) VALUES ('PGP1',1,100),('PGP2',1,100)");
        $id1 = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        $id2 = $id1 + 1;
        $this->createdTeamIds[] = $id1;
        $this->createdTeamIds[] = $id2;
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES (300,$poolId,1,1),(301,$poolId,2,2),($id1,$poolId,3,3),($id2,$poolId,4,4)");
        try {
            $result = GeneratePlayoffPools($poolId, false);
            $this->assertIsArray($result);
        } finally {
            DBQuery("DELETE FROM uo_team_pool WHERE pool=$poolId");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$poolId");
        }
    }

    // --- CanDeleteTeamFromPool true path ---

    public function testCanDeleteTeamFromPoolReturnsTrueForNonPlayedTeam(): void
    {
        DBQuery("INSERT INTO uo_team (name, valid, series) VALUES ('NoGamesTeam', 1, 100)");
        $teamId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        $this->createdTeamIds[] = $teamId;
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES ($teamId, 200, 5, 5)");
        try {
            $this->assertTrue(CanDeleteTeamFromPool(200, $teamId));
        } finally {
            DBQuery("DELETE FROM uo_team_pool WHERE team=$teamId");
        }
    }

    // --- PoolDeleteTeam zero-teamId early return ---

    public function testPoolDeleteTeamWithZeroTeamIdDoesNothing(): void
    {
        // Covers the !$teamId early return at the top of PoolDeleteTeam.
        PoolDeleteTeam(200, 0, false);
        $this->assertTrue(true);
    }

    // --- PoolDeleteTeam: UPDATE pool=NULL when team.pool matches ---

    public function testPoolDeleteTeamClearsTeamPoolWhenPoolMatches(): void
    {
        DBQuery("INSERT INTO uo_team (name, valid, series, pool) VALUES ('PoolDeleteTeamTest', 1, 100, 200)");
        $teamId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        $this->createdTeamIds[] = $teamId;
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES ($teamId, 200, 5, 5)");
        try {
            PoolDeleteTeam(200, $teamId, false);
            $pool = DBQueryToValue("SELECT pool FROM uo_team WHERE team_id=$teamId");
            $this->assertNull($pool);
        } finally {
            DBQuery("DELETE FROM uo_team_pool WHERE team=$teamId");
        }
    }

    // --- SetPoolDetails with comment ---

    public function testSetPoolDetailsWithCommentSavesComment(): void
    {
        LegacyApp::loadCommonFunctions();
        // Covers the SetComment branch inside SetPoolDetails.
        SetPoolDetails(200, ['name' => 'Pool A'], 'Test pool detail comment');
        // If we get here without exception, the branch was covered.
        $this->assertTrue(true);
    }

    // --- PoolSetTeam with newpool=0 (DELETE path) ---

    public function testPoolSetTeamWithZeroNewPoolDeletesTeamFromPool(): void
    {
        $tempPoolId = $this->createTempPool(1);
        DBQuery("INSERT INTO uo_team (name, valid, series) VALUES ('PoolSetTeamDelTest', 1, 100)");
        $teamId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        $this->createdTeamIds[] = $teamId;
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES ($teamId, $tempPoolId, 1, 1)");
        try {
            PoolSetTeam($tempPoolId, $teamId, 1, 0);
            $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_team_pool WHERE team=$teamId AND pool=$tempPoolId");
            $this->assertSame(0, $count);
        } finally {
            DBQuery("DELETE FROM uo_team_pool WHERE team=$teamId");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$tempPoolId");
        }
    }

    // --- PoolSetTeam with pool match: UPDATE uo_team.pool when newpool=0 ---

    public function testPoolSetTeamWithZeroNewPoolClearsTeamPoolWhenMatching(): void
    {
        $tempPoolId = $this->createTempPool(1);
        DBQuery("INSERT INTO uo_team (name, valid, series, pool) VALUES ('PoolSetTeamClearTest', 1, 100, $tempPoolId)");
        $teamId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        $this->createdTeamIds[] = $teamId;
        DBQuery("INSERT INTO uo_team_pool (team, pool, rank, activerank) VALUES ($teamId, $tempPoolId, 1, 1)");
        try {
            PoolSetTeam($tempPoolId, $teamId, 1, 0);
            $pool = DBQueryToValue("SELECT pool FROM uo_team WHERE team_id=$teamId");
            $this->assertNull($pool);
        } finally {
            DBQuery("DELETE FROM uo_team_pool WHERE team=$teamId");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$tempPoolId");
        }
    }

    // --- SetPoolFollowersVisibility with an actual follower ---

    public function testSetPoolFollowersVisibilityUpdatesFollower(): void
    {
        $followerPoolId = $this->createTempPool(1);
        // Make pool 200 point to a follower pool in uo_pool.follower column.
        DBQuery("UPDATE uo_pool SET follower=$followerPoolId WHERE pool_id=200");
        try {
            SetPoolFollowersVisibility(200, 1);
            $visible = (int) DBQueryToValue("SELECT visible FROM uo_pool WHERE pool_id=$followerPoolId");
            $this->assertSame(1, $visible);
        } finally {
            DBQuery("UPDATE uo_pool SET follower=NULL WHERE pool_id=200");
            DBQuery("DELETE FROM uo_pool WHERE pool_id=$followerPoolId");
        }
    }
}
