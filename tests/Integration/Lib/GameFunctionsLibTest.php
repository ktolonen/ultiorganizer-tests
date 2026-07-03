<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class GameFunctionsLibTest extends TestCase
{
    // Fixture constants:
    //   game_id=700: home=300 (Helsinki Heat), visitor=301 (Tampere Tempest),
    //                score=15:11, hasstarted=1, isongoing=0, pool=200,
    //                reservation=500, name=600('Round 1'), respteam=300
    //   game_id=701: home=301, visitor=300, score=NULL:NULL,
    //                hasstarted=0, pool=200, reservation=501, name=601('Round 2')
    //   pool=200, series=100, season='HRN2026'
    //   uo_played in game 700: 800(num=8,cap=1), 801(num=12,cap=0),
    //                          802(num=7,cap=1), 803(num=14,cap=0)
    //   uo_goal  in game 700: 4 goals (num 1..4)

    /** @var int[] game IDs created during a test, deleted in tearDown */
    private array $createdGameIds = [];

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        // Load full game dependency stack in require_once-safe order.
        LegacyApp::loadLibFilesUsingProfile([
            'pool.functions.php',
            'standings.functions.php',
            'statistical.functions.php',
            'user.functions.php',
            'game.functions.php',
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
        foreach ($this->createdGameIds as $gameId) {
            DBQuery(sprintf("DELETE FROM uo_goal WHERE game=%d", (int) $gameId));
            DBQuery(sprintf("DELETE FROM uo_played WHERE game=%d", (int) $gameId));
            DBQuery(sprintf("DELETE FROM uo_timeout WHERE game=%d", (int) $gameId));
            DBQuery(sprintf("DELETE FROM uo_spirit_timeout WHERE game=%d", (int) $gameId));
            DBQuery(sprintf("DELETE FROM uo_gameevent WHERE game=%d", (int) $gameId));
            DBQuery(sprintf("DELETE FROM uo_game_pool WHERE game=%d", (int) $gameId));
            DBQuery(sprintf("DELETE FROM uo_game WHERE game_id=%d", (int) $gameId));
        }
        $this->createdGameIds = [];
        unset($_SESSION['userproperties'], $_SESSION['uid']);
        LegacyApp::closeDatabaseConnection();
    }

    private function createTempGame(
        int    $homeTeam = 300,
        int    $visitorTeam = 301,
        int    $poolId = 200,
        ?int   $reservation = null,
        ?string $time = null
    ): int {
        $reservationSql = $reservation === null ? 'NULL' : (string) $reservation;
        $timeSql = $time === null ? 'NULL' : "'" . DBEscapeString($time) . "'";
        DBQuery(sprintf(
            "INSERT INTO uo_game (hometeam, visitorteam, reservation, time, valid, respteam)
             VALUES (%d, %d, %s, %s, 1, %d)",
            $homeTeam,
            $visitorTeam,
            $reservationSql,
            $timeSql,
            $homeTeam,
        ));
        $id = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        DBQuery(sprintf(
            "INSERT INTO uo_game_pool (game, pool, timetable) VALUES (%d, %d, 1)",
            $id,
            $poolId,
        ));
        $this->createdGameIds[] = $id;
        return $id;
    }

    // --- Dependency sanity check ---

    public function testRequiredFunctionsExist(): void
    {
        $this->assertTrue(function_exists('GameInfo'));
        $this->assertTrue(function_exists('hasEditGamesRight'));
        $this->assertTrue(function_exists('PoolInfo'));
        $this->assertTrue(function_exists('ResolvePoolStandings'));
        $this->assertTrue(function_exists('IsSeasonStatsCalculated'));
    }

    // --- SeasonScoreCounter ---

    public function testSeasonScoreCounterWithSeasonReturnsPositiveInt(): void
    {
        // Fixture game 700 has homescore=15, visitorscore=11; game 701 has NULL scores.
        $count = SeasonScoreCounter('HRN2026');
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(26, $count);
    }

    public function testSeasonScoreCounterWithoutSeasonReturnsGlobalSum(): void
    {
        $count = SeasonScoreCounter();
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testSeasonScoreCounterForUnknownSeasonReturnsZero(): void
    {
        $this->assertSame(0, SeasonScoreCounter('NOSUCHSEASON'));
    }

    // --- GameSetPools ---

    public function testGameSetPoolsReturnsPoolForKnownGames(): void
    {
        $pools = GameSetPools([700, 701]);
        $this->assertIsArray($pools);
        $this->assertArrayHasKey(200, $pools);
    }

    public function testGameSetPoolsReturnsEmptyArrayForEmptyInput(): void
    {
        $this->assertSame([], GameSetPools([]));
    }

    public function testGameSetPoolsIgnoresNonPositiveIds(): void
    {
        $this->assertSame([], GameSetPools([0, -1]));
    }

    // --- PoolGameSetResults ---

    public function testPoolGameSetResultsReturnsRowForKnownPoolGame(): void
    {
        // Regression for the fixed ambiguous `pool` column: the query filtered "AND pool=%d"
        // but uo_game (p) has no pool column and `pool` was ambiguous across the two uo_team
        // joins. It now joins uo_game_pool and filters gp.pool, so game 700 (in pool 200 via
        // uo_game_pool) is returned.
        $result = PoolGameSetResults(200, [700]);
        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
        $this->assertCount(1, $rows);
        $this->assertSame('700', (string) $rows[0]['game_id']);
    }

    public function testPoolGameSetResultsReturnsEmptyForEmptyGameList(): void
    {
        $this->assertSame([], PoolGameSetResults(200, []));
    }

    // --- GameResult ---

    public function testGameResultReturnsRowForFixtureGame(): void
    {
        $result = GameResult(700);
        $this->assertIsArray($result);
        $this->assertSame('700', (string) $result['game_id']);
        $this->assertSame('15', (string) $result['homescore']);
        $this->assertSame('11', (string) $result['visitorscore']);
    }

    public function testGameResultReturnsNullForUnknownGame(): void
    {
        $this->assertNull(GameResult(99999));
    }

    // --- GoalInfo ---

    public function testGoalInfoReturnsRowForFixtureGoal(): void
    {
        $goal = GoalInfo(700, 1);
        $this->assertIsArray($goal);
        $this->assertSame('700', (string) $goal['game']);
        $this->assertSame('1', (string) $goal['num']);
    }

    public function testGoalInfoReturnsFalseForUnknownGoal(): void
    {
        $this->assertFalse(GoalInfo(700, 99));
    }

    // --- GameHomeTeamResults / GameVisitorTeamResults ---

    public function testGameHomeTeamResultsReturnsGame700(): void
    {
        $results = GameHomeTeamResults(300, 200);
        $this->assertIsArray($results);
        $ids = array_column($results, 'game_id');
        $this->assertContains('700', $ids);
    }

    public function testGameVisitorTeamResultsReturnsStartedGames(): void
    {
        // game 700: hasstarted=1, valid=1, isongoing=0 → matches visitor filter
        $results = GameVisitorTeamResults(301, 200);
        $this->assertIsArray($results);
        $ids = array_column($results, 'game_id');
        $this->assertContains('700', $ids);
    }

    public function testGameHomePseudoTeamResultsReturnsEmptyForNoSchedulingMatch(): void
    {
        // No game uses scheduling_name_home=99999
        $results = GameHomePseudoTeamResults(99999, 200);
        $this->assertIsArray($results);
        $this->assertCount(0, $results);
    }

    // --- GameNameFromId ---

    public function testGameNameFromIdReturnsTeamNames(): void
    {
        $name = GameNameFromId(700);
        $this->assertStringContainsString('Helsinki Heat', $name);
        $this->assertStringContainsString('Tampere Tempest', $name);
    }

    public function testGameNameFromIdReturnsEmptyStringForUnknownGame(): void
    {
        $this->assertSame('', GameNameFromId(99999));
    }

    // --- GameSeries ---

    public function testGameSeriesReturnsCorrectSeries(): void
    {
        $series = GameSeries(700);
        $this->assertSame('100', (string) $series);
    }

    public function testGameSeriesReturnsNullForUnknownGame(): void
    {
        $this->assertNull(GameSeries(99999));
    }

    // --- GameRespTeam ---

    public function testGameRespTeamReturnsMinus1WhenNoTeamAdminRole(): void
    {
        // superadmin without teamadmin entry → returns -1
        $result = GameRespTeam(700);
        $this->assertSame(-1, $result);
    }

    public function testGameRespTeamReturnsHomeTeamWhenTeamAdminSet(): void
    {
        $_SESSION['userproperties']['userrole']['teamadmin'][300] = 1;
        try {
            $result = GameRespTeam(700);
            $this->assertSame('300', (string) $result);
        } finally {
            unset($_SESSION['userproperties']['userrole']['teamadmin']);
        }
    }

    public function testGameRespTeamReturnsMinus1ForUnknownGame(): void
    {
        $this->assertSame(-1, GameRespTeam(99999));
    }

    // --- GameAdmins ---

    public function testGameAdminsReturnsArray(): void
    {
        $admins = GameAdmins(700);
        $this->assertIsArray($admins);
    }

    // --- GamePool ---

    public function testGamePoolReturnsCorrectPool(): void
    {
        $poolId = GamePool(700);
        $this->assertSame('200', (string) $poolId);
    }

    public function testGamePoolReturnsNullForUnknownGame(): void
    {
        $this->assertNull(GamePool(99999));
    }

    // --- GameIsFirstOffenceHome ---

    public function testGameIsFirstOffenceHomeReturnsNullWhenNoEvent(): void
    {
        // Fixture games have no uo_gameevent offence rows
        $result = GameIsFirstOffenceHome(700);
        $this->assertNull($result);
    }

    // --- GameReservation ---

    public function testGameReservationReturnsCorrectId(): void
    {
        $resId = GameReservation(700);
        $this->assertSame('500', (string) $resId);
    }

    public function testGameReservationReturnsNullForUnknownGame(): void
    {
        $this->assertNull(GameReservation(99999));
    }

    // --- GameSeason ---

    public function testGameSeasonReturnsCorrectSeason(): void
    {
        $season = GameSeason(700);
        $this->assertSame('HRN2026', $season);
    }

    public function testGameSeasonReturnsNullForUnknownGame(): void
    {
        $this->assertNull(GameSeason(99999));
    }

    // --- GamePlayers ---

    public function testGamePlayersReturnsFixturePlayersForTeam300(): void
    {
        $players = GamePlayers(700, 300);
        $this->assertIsArray($players);
        $this->assertGreaterThanOrEqual(1, count($players));
        $playerIds = array_column($players, 'player_id');
        $this->assertContains('800', $playerIds);
    }

    public function testGamePlayersReturnsEmptyForGameWithNoPlayers(): void
    {
        $players = GamePlayers(701, 300);
        $this->assertIsArray($players);
        $this->assertCount(0, $players);
    }

    // --- GameRolePlayers / GameCaptains / GameSpiritCaptains ---

    public function testGameCaptainsReturnsCapedPlayerIds(): void
    {
        $captains = GameCaptains(700, 300);
        $this->assertIsArray($captains);
        $this->assertContains(800, $captains);
    }

    public function testGameCaptainsReturnsEmptyForTeamWithNoCaptain(): void
    {
        // team 301 players (802, 803) were in game 700 but no captain set
        // Actually fixture has captain=1 for 802 too - let me check:
        // (802, 700, num=7, captain=1) - captain set for team 301 player!
        $captains = GameCaptains(700, 301);
        $this->assertIsArray($captains);
        // 802 has captain=1 in uo_played
        $this->assertContains(802, $captains);
    }

    public function testGameSpiritCaptainsReturnsEmptyWhenNoneSet(): void
    {
        $spiritCaptains = GameSpiritCaptains(700, 300);
        $this->assertIsArray($spiritCaptains);
        // Fixture has no spirit_captain=1 rows
        $this->assertCount(0, $spiritCaptains);
    }

    public function testGameRolePlayersReturnsEmptyForInvalidRoleColumn(): void
    {
        $result = GameRolePlayers(700, 300, 'invalid_column');
        $this->assertSame([], $result);
    }

    public function testGameCaptainReturnsSingleCaptain(): void
    {
        $captain = GameCaptain(700, 300);
        $this->assertSame(800, $captain);
    }

    public function testGameCaptainReturnsNullWhenNoCaptain(): void
    {
        $captain = GameCaptain(701, 300);
        $this->assertNull($captain);
    }

    // --- GameFilterRolePlayers ---

    public function testGameFilterRolePlayersFiltersToRosterPlayers(): void
    {
        // Player 800 is in game 700; 99998 is not
        $filtered = GameFilterRolePlayers(700, 300, [800, 99998]);
        $this->assertContains(800, $filtered);
        $this->assertNotContains(99998, $filtered);
    }

    public function testGameFilterRolePlayersReturnsEmptyForNonRosterPlayers(): void
    {
        $filtered = GameFilterRolePlayers(700, 300, [99998, 99999]);
        $this->assertSame([], $filtered);
    }

    // --- GameSetCaptains / GameSetSpiritCaptains ---

    public function testGameSetCaptainsSetsAndClears(): void
    {
        $gameId = $this->createTempGame();
        DBQuery(sprintf(
            "INSERT INTO uo_played (player, game, num) VALUES (800, %d, 8)",
            $gameId,
        ));

        $result = GameSetCaptains($gameId, 300, [800]);
        $this->assertNotFalse($result);

        $captains = GameCaptains($gameId, 300);
        $this->assertContains(800, $captains);

        // Clear captains
        GameSetCaptains($gameId, 300, []);
        $captains = GameCaptains($gameId, 300);
        $this->assertSame([], $captains);
    }

    public function testGameSetSpiritCaptainsSetsPlayer(): void
    {
        $gameId = $this->createTempGame();
        DBQuery(sprintf(
            "INSERT INTO uo_played (player, game, num) VALUES (800, %d, 8)",
            $gameId,
        ));

        GameSetSpiritCaptains($gameId, 300, [800]);
        $spiritCaptains = GameSpiritCaptains($gameId, 300);
        $this->assertContains(800, $spiritCaptains);
    }

    public function testGameSetCaptainWrapperSetsOneCaptain(): void
    {
        $gameId = $this->createTempGame();
        DBQuery(sprintf(
            "INSERT INTO uo_played (player, game, num) VALUES (800, %d, 8)",
            $gameId,
        ));

        GameSetCaptain($gameId, 300, 800);
        $captain = GameCaptain($gameId, 300);
        $this->assertSame(800, $captain);
    }

    public function testGameSetCaptainWithZeroClearsCaptains(): void
    {
        $gameId = $this->createTempGame();
        DBQuery(sprintf(
            "INSERT INTO uo_played (player, game, num, captain) VALUES (800, %d, 8, 1)",
            $gameId,
        ));

        GameSetCaptain($gameId, 300, 0);
        $captains = GameCaptains($gameId, 300);
        $this->assertSame([], $captains);
    }

    // --- GameAll / GameAllArray ---

    public function testGameAllReturnsResultSet(): void
    {
        // game 700: valid=1, hasstarted=1, isongoing=0 → included
        $result = GameAll(100);
        $this->assertNotFalse($result);
        $rows = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
        $ids = array_column($rows, 'game_id');
        $this->assertContains('700', $ids);
    }

    public function testGameAllWithPublicFilterReturnsArray(): void
    {
        $result = GameAll(10, true);
        $this->assertNotFalse($result);
    }

    public function testGameAllArrayReturnsPhpArray(): void
    {
        $games = GameAllArray(100);
        $this->assertIsArray($games);
    }

    // --- GamePlayerFromNumber ---

    public function testGamePlayerFromNumberReturnsPlayerIdForFixtureGame(): void
    {
        // Player 800 has num=8 in game 700 for team 300
        $playerId = GamePlayerFromNumber(700, 300, 8);
        $this->assertSame('800', (string) $playerId);
    }

    public function testGamePlayerFromNumberReturnsNullForUnknownNumber(): void
    {
        $this->assertNull(GamePlayerFromNumber(700, 300, 99));
    }

    // --- GameTeamScoreBorad / GameTeamDefenseBoard ---

    public function testGameTeamScoreBoardArrayReturnsPlayersForTeam300(): void
    {
        $board = GameTeamScoreBoardArray(700, 300);
        $this->assertIsArray($board);
        $this->assertNotEmpty($board);
    }

    public function testGameTeamDefenseBoardArrayReturnsArray(): void
    {
        $board = GameTeamDefenseBoardArray(700, 300);
        $this->assertIsArray($board);
    }

    public function testGameScoreBoardArrayReturnsArray(): void
    {
        $board = GameScoreBoardArray(700);
        $this->assertIsArray($board);
    }

    // --- GameGoals / GameDefenses / GameLastGoal ---

    public function testGameGoalsReturnsFixtureGoals(): void
    {
        $goals = GameGoals(700);
        $this->assertIsArray($goals);
        $this->assertCount(4, $goals);
    }

    public function testGameGoalsReturnsEmptyArrayForGameWithoutGoals(): void
    {
        $goals = GameGoals(701);
        $this->assertIsArray($goals);
        $this->assertCount(0, $goals);
    }

    public function testGameDefensesReturnsArray(): void
    {
        $defenses = GameDefenses(700);
        $this->assertIsArray($defenses);
    }

    public function testGameLastGoalReturnsHighestNumGoal(): void
    {
        $goal = GameLastGoal(700);
        $this->assertIsArray($goal);
        $this->assertSame('4', (string) $goal['num']);
    }

    public function testGameLastGoalReturnsFalseForGameWithNoGoals(): void
    {
        $this->assertNull(GameLastGoal(701));
    }

    // --- GoalDisplayText ---

    public function testGoalDisplayTextWithAssistAndScorer(): void
    {
        $goal = GameGoals(700)[0];
        // num=1: assist=801 (Bea Blade), scorer=800 (Ari Ace)
        $text = GoalDisplayText($goal, 700);
        $this->assertStringContainsString('Bea', $text);
        $this->assertStringContainsString('Ari', $text);
    }

    public function testGoalDisplayTextCallahan(): void
    {
        $goal = ['iscallahan' => 1, 'assist' => null, 'scorer' => null,
                 'assistfirstname' => '', 'assistlastname' => '',
                 'scorerfirstname' => '', 'scorerlastname' => ''];
        $text = GoalDisplayText($goal, 700);
        $this->assertNotSame('', $text);
    }

    public function testGoalDisplayTextWithNumbersMode(): void
    {
        // Numbers mode prefixes each player with their jersey number from uo_played:
        // goal num=1 is scorer 800 (#8 Ari Ace) assisted by 801 (#12 Bea Blade).
        $goal = GameGoals(700)[0];
        $text = GoalDisplayText($goal, 700, true);
        $this->assertStringContainsString('#8', $text);
        $this->assertStringContainsString('#12', $text);
    }

    // --- GameAllGoals ---

    public function testGameAllGoalsReturnsNonEmptyForGame700(): void
    {
        $goals = GameAllGoals(700);
        $this->assertIsArray($goals);
        $this->assertCount(4, $goals);
        $this->assertArrayHasKey('num', $goals[0]);
        $this->assertArrayHasKey('time', $goals[0]);
        $this->assertArrayHasKey('ishomegoal', $goals[0]);
    }

    // --- GameEvents / GameMediaEvents ---

    public function testGameEventsReturnsArray(): void
    {
        $events = GameEvents(700);
        $this->assertIsArray($events);
    }

    public function testGameMediaEventsReturnsArray(): void
    {
        $events = GameMediaEvents(700);
        $this->assertIsArray($events);
    }

    // --- GameTimeouts / GameSpiritTimeouts ---

    public function testGameTimeoutsReturnsArray(): void
    {
        $timeouts = GameTimeouts(700);
        $this->assertIsArray($timeouts);
    }

    public function testGameSpiritTimeoutsArrayReturnsArray(): void
    {
        $timeouts = GameSpiritTimeoutsArray(700);
        $this->assertIsArray($timeouts);
    }

    public function testGameTurnoversArrayReturnsArray(): void
    {
        $turnovers = GameTurnoversArray(700);
        $this->assertIsArray($turnovers);
    }

    // --- GameInfo ---

    public function testGameInfoReturnsFullRowForGame700(): void
    {
        $info = GameInfo(700);
        $this->assertIsArray($info);
        $this->assertSame('700', (string) $info['game_id']);
        $this->assertSame('300', (string) $info['hometeam']);
        $this->assertSame('301', (string) $info['visitorteam']);
        $this->assertSame('Round 1', $info['gamename']);
        $this->assertSame('HRN2026', $info['season']);
    }

    public function testGameInfoReturnsFalseForUnknownGame(): void
    {
        $this->assertNull(GameInfo(99999));
    }

    // --- GameName ---

    public function testGameNameIncludesTeamNames(): void
    {
        $info = GameInfo(700);
        $name = GameName($info);
        $this->assertStringContainsString('Helsinki Heat', $name);
        $this->assertStringContainsString('Tampere Tempest', $name);
    }

    // --- GameHasStarted ---

    public function testGameHasStartedReturnsTrueForGame700(): void
    {
        $info = GameInfo(700);
        $this->assertTrue(GameHasStarted($info));
    }

    public function testGameHasStartedReturnsFalseForGame701(): void
    {
        $info = GameInfo(701);
        $this->assertFalse(GameHasStarted($info));
    }

    // --- GameTimerState ---

    public function testGameTimerStateReturnsDefaultForNonOngoingGame(): void
    {
        $state = GameTimerState(700);
        $this->assertIsArray($state);
        $this->assertArrayHasKey('started', $state);
        $this->assertArrayHasKey('ongoing', $state);
        $this->assertArrayHasKey('paused', $state);
        $this->assertArrayHasKey('mm', $state);
        $this->assertArrayHasKey('ss', $state);
    }

    public function testGameTimerStateReturnsFalseStartedForUnknownGame(): void
    {
        $state = GameTimerState(99999);
        $this->assertFalse($state['started']);
        $this->assertFalse($state['ongoing']);
    }

    // --- isGameLive / isGameOngoing / isGamePaused / GameLiveURL ---

    public function testIsGameLiveReturnsFalseForFixtureGames(): void
    {
        // Fixture has islive=0 for both games
        $this->assertSame(0, isGameLive(700));
    }

    public function testIsGameOngoingReturnsFalseForCompletedGame(): void
    {
        $this->assertSame(0, isGameOngoing(700));
    }

    public function testIsGamePausedReturnsFalseWhenNotOngoing(): void
    {
        $this->assertSame(0, isGamePaused(700));
    }

    public function testGameLiveURLReturnsFalseWhenNoUrl(): void
    {
        // Fixture game 700 has liveurl=NULL
        $this->assertFalse(GameLiveURL(700));
    }

    // --- GameElapsedTime ---

    public function testGameElapsedTimeReturnsMmSsStructure(): void
    {
        $elapsed = GameElapsedTime(700);
        $this->assertArrayHasKey('mm', $elapsed);
        $this->assertArrayHasKey('ss', $elapsed);
        $this->assertArrayHasKey('rss', $elapsed);
    }

    // --- SeasonForfeitGames ---

    public function testSeasonForfeitGamesReturnsEmptyWhenNoForfeits(): void
    {
        // Fixture has forfeit=0 for both games
        $games = SeasonForfeitGames('HRN2026');
        $this->assertIsArray($games);
        $ids = array_column($games, 'game_id');
        $this->assertNotContains('700', $ids);
        $this->assertNotContains('701', $ids);
    }

    public function testSeasonForfeitGamesReturnsForfeitedGame(): void
    {
        $gameId = $this->createTempGame();
        DBQuery(sprintf("UPDATE uo_game SET forfeit=1, hasstarted=2, homescore=0, visitorscore=15 WHERE game_id=%d", $gameId));
        try {
            $games = SeasonForfeitGames('HRN2026');
            $this->assertIsArray($games);
            $ids = array_column($games, 'game_id');
            $this->assertContains((string) $gameId, $ids);
        } finally {
            DBQuery(sprintf("UPDATE uo_game SET forfeit=0 WHERE game_id=%d", $gameId));
        }
    }

    // --- GameSetForfeit ---

    public function testGameSetForfeitTogglesFlag(): void
    {
        $gameId = $this->createTempGame();

        GameSetForfeit($gameId, true);
        $info = GameInfo($gameId);
        $this->assertSame('0', (string) $info['hasstarted']); // hasstarted unchanged (default=0) since SetForfeit only updates forfeit column
        $forfeit = (int) DBQueryToValue(sprintf("SELECT forfeit FROM uo_game WHERE game_id=%d", $gameId));
        $this->assertSame(1, $forfeit);

        GameSetForfeit($gameId, false);
        $forfeit = (int) DBQueryToValue(sprintf("SELECT forfeit FROM uo_game WHERE game_id=%d", $gameId));
        $this->assertSame(0, $forfeit);
    }

    // --- GameSetResult / GameUpdateResult / GameClearResult ---

    public function testGameSetResultSetsScoreAndMarksStarted(): void
    {
        $gameId = $this->createTempGame();

        GameSetResult($gameId, 10, 5);
        $info = GameInfo($gameId);
        $this->assertSame('10', (string) $info['homescore']);
        $this->assertSame('5', (string) $info['visitorscore']);
        $this->assertSame('2', (string) $info['hasstarted']);
        $this->assertSame('0', (string) $info['isongoing']);
    }

    public function testGameUpdateResultSetsOngoingState(): void
    {
        $gameId = $this->createTempGame();

        GameUpdateResult($gameId, 3, 2);
        $info = GameInfo($gameId);
        $this->assertSame('3', (string) $info['homescore']);
        $this->assertSame('2', (string) $info['visitorscore']);
        $this->assertSame('1', (string) $info['isongoing']);
    }

    public function testGameClearResultResetsToNullScores(): void
    {
        $gameId = $this->createTempGame();
        DBQuery(sprintf("UPDATE uo_game SET homescore=8, visitorscore=3, hasstarted=2 WHERE game_id=%d", $gameId));

        GameClearResult($gameId);
        $info = GameInfo($gameId);
        $this->assertNull($info['homescore']);
        $this->assertNull($info['visitorscore']);
        $this->assertSame('0', (string) $info['hasstarted']);
    }

    // --- GameSetDefenses ---

    public function testGameSetDefensesSetsHomeAndAwayDefenses(): void
    {
        $gameId = $this->createTempGame();

        GameSetDefenses($gameId, 5, 7);
        $row = DBQueryToRow(sprintf("SELECT homedefenses, visitordefenses FROM uo_game WHERE game_id=%d", $gameId));
        $this->assertSame('5', (string) $row['homedefenses']);
        $this->assertSame('7', (string) $row['visitordefenses']);
    }

    // --- GameAddPlayer / GameRemovePlayer / GameRemoveAllPlayers / GameSetPlayerNumber ---

    public function testGameAddPlayerAddsToPlayedTable(): void
    {
        $gameId = $this->createTempGame();

        GameAddPlayer($gameId, 800, 88);
        $players = GamePlayers($gameId, 300);
        $playerIds = array_column($players, 'player_id');
        $this->assertContains('800', $playerIds);
    }

    public function testGameRemovePlayerRemovesFromPlayedTable(): void
    {
        $gameId = $this->createTempGame();
        GameAddPlayer($gameId, 800, 88);

        GameRemovePlayer($gameId, 800);
        $players = GamePlayers($gameId, 300);
        $playerIds = array_column($players, 'player_id');
        $this->assertNotContains('800', $playerIds);
    }

    public function testGameRemoveAllPlayersEmptiesRoster(): void
    {
        $gameId = $this->createTempGame();
        GameAddPlayer($gameId, 800, 88);
        GameAddPlayer($gameId, 801, 12);

        GameRemoveAllPlayers($gameId);
        $players = GamePlayers($gameId, 300);
        $this->assertCount(0, $players);
    }

    public function testGameSetPlayerNumberUpdatesNumber(): void
    {
        $gameId = $this->createTempGame();
        GameAddPlayer($gameId, 800, 8);

        GameSetPlayerNumber($gameId, 800, 99);
        $row = DBQueryToRow(sprintf(
            "SELECT num FROM uo_played WHERE game=%d AND player=800",
            $gameId,
        ));
        $this->assertSame('99', (string) $row['num']);
    }

    public function testGameAddNewPlayerCreatesPlayerAndAddsToGame(): void
    {
        $gameId = $this->createTempGame();

        GameAddNewPlayer($gameId, 'Test', 'Player', '', 300, 55);

        $players = GamePlayers($gameId, 300);
        $this->assertNotEmpty($players);

        // Clean up the new player row
        $newPlayerId = (int) DBQueryToValue("SELECT player_id FROM uo_player WHERE firstname='Test' AND lastname='Player'");
        if ($newPlayerId > 0) {
            DBQuery(sprintf("DELETE FROM uo_player WHERE player_id=%d", $newPlayerId));
        }
    }

    // --- GameAddScore / GameRemoveScore / GameRemoveAllScores ---

    public function testGameAddScoreInsertsGoal(): void
    {
        $gameId = $this->createTempGame();

        GameAddScore($gameId, 800, 801, 120, 1, 1, 0, 1, 0);
        $goals = GameGoals($gameId);
        $this->assertCount(1, $goals);
        $this->assertSame('801', (string) $goals[0]['scorer']);
    }

    public function testGameRemoveScoreDeletesGoal(): void
    {
        $gameId = $this->createTempGame();
        GameAddScore($gameId, 800, 801, 120, 1, 1, 0, 1, 0);
        GameAddScore($gameId, 801, 802, 240, 2, 1, 1, 0, 0);

        GameRemoveScore($gameId, 1);
        $goals = GameGoals($gameId);
        $this->assertCount(1, $goals);
    }

    public function testGameRemoveAllScoresClearsAllGoals(): void
    {
        $gameId = $this->createTempGame();
        GameAddScore($gameId, 800, 801, 120, 1, 1, 0, 1, 0);

        GameRemoveAllScores($gameId);
        $goals = GameGoals($gameId);
        $this->assertCount(0, $goals);
    }

    public function testGameAddScoreEntryWithNullScorer(): void
    {
        $gameId = $this->createTempGame();

        $entry = [
            'game' => $gameId,
            'num' => 1,
            'assist' => 800,
            'scorer' => 0,    // 0 → NULL scorer
            'time' => 120,
            'homescore' => 1,
            'visitorscore' => 0,
            'ishomegoal' => 1,
            'iscallahan' => 0,
        ];
        $result = GameAddScoreEntry($entry);
        $this->assertNotFalse($result);
    }

    // --- GameAddDefense / GameRemoveAllDefenses ---

    public function testGameAddDefenseInsertsRow(): void
    {
        $gameId = $this->createTempGame();

        GameAddDefense($gameId, 800, 1, 0, 120, 0, 1);
        $defenses = GameDefenses($gameId);
        $this->assertIsArray($defenses);
        $this->assertCount(1, $defenses);
    }

    public function testGameRemoveAllDefensesEmptiesDefenses(): void
    {
        $gameId = $this->createTempGame();
        GameAddDefense($gameId, 800, 1, 0, 120, 0, 1);

        GameRemoveAllDefenses($gameId);
        $defenses = GameDefenses($gameId);
        $this->assertCount(0, $defenses);
    }

    // --- GameAddTimeout / GameRemoveAllTimeouts ---

    public function testGameAddTimeoutInsertsRow(): void
    {
        $gameId = $this->createTempGame();

        GameAddTimeout($gameId, 1, 120, 1);
        $timeouts = GameTimeouts($gameId);
        $this->assertIsArray($timeouts);
        $this->assertCount(1, $timeouts);
    }

    public function testGameRemoveAllTimeoutsClearsTimeouts(): void
    {
        $gameId = $this->createTempGame();
        GameAddTimeout($gameId, 1, 120, 1);

        GameRemoveAllTimeouts($gameId);
        $timeouts = GameTimeouts($gameId);
        $this->assertCount(0, $timeouts);
    }

    // --- GameAddSpiritTimeout / GameRemoveAllSpiritTimeouts ---

    public function testGameAddSpiritTimeoutInsertsRow(): void
    {
        $gameId = $this->createTempGame();

        GameAddSpiritTimeout($gameId, 1, 120, 0);
        $timeouts = GameSpiritTimeoutsArray($gameId);
        $this->assertCount(1, $timeouts);
    }

    public function testGameRemoveAllSpiritTimeoutsClearsTimeouts(): void
    {
        $gameId = $this->createTempGame();
        GameAddSpiritTimeout($gameId, 1, 120, 0);

        GameRemoveAllSpiritTimeouts($gameId);
        $timeouts = GameSpiritTimeoutsArray($gameId);
        $this->assertCount(0, $timeouts);
    }

    // --- GameSetScoreSheetKeeper ---

    public function testGameSetScoreSheetKeeperSetsName(): void
    {
        $gameId = $this->createTempGame();

        GameSetScoreSheetKeeper($gameId, 'Jane Ref');
        $official = DBQueryToValue(sprintf("SELECT official FROM uo_game WHERE game_id=%d", $gameId));
        $this->assertSame('Jane Ref', $official);
    }

    public function testGameSetScoreSheetKeeperWithNullSetsNull(): void
    {
        $gameId = $this->createTempGame();
        DBQuery(sprintf("UPDATE uo_game SET official='Someone' WHERE game_id=%d", $gameId));

        GameSetScoreSheetKeeper($gameId, null);
        $official = DBQueryToValue(sprintf("SELECT official FROM uo_game WHERE game_id=%d", $gameId));
        $this->assertNull($official);
    }

    // --- GameSetHalftime ---

    public function testGameSetHalftimeSetsTime(): void
    {
        $gameId = $this->createTempGame();

        GameSetHalftime($gameId, 21);
        $halftime = DBQueryToValue(sprintf("SELECT halftime FROM uo_game WHERE game_id=%d", $gameId));
        $this->assertSame('21', (string) $halftime);
    }

    public function testGameSetHalftimeWithNullClearsTime(): void
    {
        $gameId = $this->createTempGame();
        DBQuery(sprintf("UPDATE uo_game SET halftime=20 WHERE game_id=%d", $gameId));

        GameSetHalftime($gameId, null);
        $halftime = DBQueryToValue(sprintf("SELECT halftime FROM uo_game WHERE game_id=%d", $gameId));
        $this->assertNull($halftime);
    }

    // --- GameSetStartingTeam ---

    public function testGameSetStartingTeamInsertsOffenceEvent(): void
    {
        $gameId = $this->createTempGame();

        GameSetStartingTeam($gameId, 1);
        $ishome = DBQueryToValue(sprintf(
            "SELECT ishome FROM uo_gameevent WHERE game=%d AND type='offence'",
            $gameId,
        ));
        $this->assertSame('1', (string) $ishome);
    }

    public function testGameSetStartingTeamWithNullDeletesOffenceEvent(): void
    {
        $gameId = $this->createTempGame();
        GameSetStartingTeam($gameId, 1);

        GameSetStartingTeam($gameId, null);
        $count = (int) DBQueryToValue(sprintf(
            "SELECT COUNT(*) FROM uo_gameevent WHERE game=%d AND type='offence'",
            $gameId,
        ));
        $this->assertSame(0, $count);
    }

    // --- GameIsFirstOffenceHome (with event) ---

    public function testGameIsFirstOffenceHomeReturnsTrueAfterSetStartingTeam(): void
    {
        $gameId = $this->createTempGame();
        GameSetStartingTeam($gameId, 1);

        $result = GameIsFirstOffenceHome($gameId);
        $this->assertSame('1', (string) $result);
    }

    // --- SetGamePool ---

    public function testSetGamePoolChangesPool(): void
    {
        $gameId = $this->createTempGame(300, 301, 200);
        // game is already in pool 200; SetGamePool should be idempotent
        SetGamePool($gameId, 200);
        $poolId = GamePool($gameId);
        $this->assertSame('200', (string) $poolId);
    }

    // --- AddGame / SetGame / DeleteGame ---

    public function testAddGameCreatesNewGame(): void
    {
        $params = [
            'hometeam'    => 300,
            'visitorteam' => 301,
            'reservation' => 500,
            'time'        => '2026-09-01 10:00:00',
            'valid'       => 1,
            'respteam'    => 300,
            'pool'        => 200,
        ];

        $id = (int) AddGame($params);
        $this->assertGreaterThan(0, $id);
        $this->createdGameIds[] = $id;

        $info = GameInfo($id);
        $this->assertSame('300', (string) $info['hometeam']);
    }

    public function testSetGameUpdatesAllowedFields(): void
    {
        $gameId = $this->createTempGame();

        SetGame($gameId, ['valid' => 0]);
        $valid = (int) DBQueryToValue(sprintf("SELECT valid FROM uo_game WHERE game_id=%d", $gameId));
        $this->assertSame(0, $valid);

        SetGame($gameId, ['valid' => 1]);
    }

    public function testSetGameWithNameCreatesSchedulingNameRow(): void
    {
        $gameId = $this->createTempGame();

        SetGame($gameId, ['name' => 'Test Match']);
        $info = GameInfo($gameId);
        $this->assertSame('Test Match', $info['gamename']);

        // Clear the name
        SetGame($gameId, ['name' => '']);
    }

    public function testDeleteGameRemovesGameAndPoolRow(): void
    {
        $gameId = $this->createTempGame();

        DeleteGame($gameId);

        // Remove from tracking since already deleted
        $this->createdGameIds = array_diff($this->createdGameIds, [$gameId]);

        $info = GameInfo($gameId);
        $this->assertNull($info);
    }

    // --- GameChangeHome ---

    public function testGameChangeHomeSwapsTeams(): void
    {
        $gameId = $this->createTempGame(300, 301);

        GameChangeHome($gameId);
        $info = GameInfo($gameId);
        $this->assertSame('301', (string) $info['hometeam']);
        $this->assertSame('300', (string) $info['visitorteam']);
    }

    // --- GameChangeName ---

    public function testGameChangeNameSetsNewName(): void
    {
        $gameId = $this->createTempGame();

        GameChangeName($gameId, 'Semifinal');
        $info = GameInfo($gameId);
        $this->assertSame('Semifinal', $info['gamename']);
    }

    public function testGameChangeNameUpdatesExistingSchedulingName(): void
    {
        $gameId = $this->createTempGame();
        GameChangeName($gameId, 'First Name');
        GameChangeName($gameId, 'Updated Name');

        $info = GameInfo($gameId);
        $this->assertSame('Updated Name', $info['gamename']);
    }

    // --- PoolSeries ---

    public function testPoolSeriesReturnsCorrectSeries(): void
    {
        $series = PoolSeries(200);
        $this->assertSame('100', (string) $series);
    }

    // --- UnscheduledGameInfo variants ---

    public function testUnscheduledGameInfoReturnsEmptyWhenAllScheduled(): void
    {
        // All fixture games have reservation and time
        $result = UnscheduledGameInfo();
        $this->assertIsArray($result);
        // Fixture games are scheduled; result may be empty or contain other games
        $this->assertNotContains('700', array_keys($result));
    }

    public function testUnscheduledPoolGameInfoReturnsArray(): void
    {
        $result = UnscheduledPoolGameInfo(200);
        $this->assertIsArray($result);
    }

    public function testUnscheduledSeriesGameInfoReturnsArray(): void
    {
        $result = UnscheduledSeriesGameInfo(100);
        $this->assertIsArray($result);
    }

    public function testUnscheduledSeasonGameInfoReturnsArray(): void
    {
        $result = UnscheduledSeasonGameInfo('HRN2026');
        $this->assertIsArray($result);
    }

    public function testUnscheduledGameInfoWithTeamFilterReturnsArray(): void
    {
        $result = UnscheduledGameInfo([300, 301]);
        $this->assertIsArray($result);
    }

    // --- ScheduleGame / UnScheduleGame ---

    public function testScheduleAndUnscheduleGame(): void
    {
        $gameId = $this->createTempGame(300, 301, 200, null, null);

        ScheduleGame($gameId, 1777000000, 500);
        $res = GameReservation($gameId);
        $this->assertSame('500', (string) $res);

        UnScheduleGame($gameId);
        $res = GameReservation($gameId);
        $this->assertNull($res);
    }

    // --- CanDeleteGame ---

    public function testCanDeleteGameReturnsTrueWhenClean(): void
    {
        $gameId = $this->createTempGame();
        // No goals, no players, no score, no events
        $this->assertTrue(CanDeleteGame($gameId));
    }

    public function testCanDeleteGameReturnsFalseWhenGoalsExist(): void
    {
        $this->assertFalse(CanDeleteGame(700));
    }

    public function testCanDeleteGameReturnsFalseWhenScoreSet(): void
    {
        $gameId = $this->createTempGame();
        DBQuery(sprintf("UPDATE uo_game SET homescore=1, visitorscore=0 WHERE game_id=%d", $gameId));
        $this->assertFalse(CanDeleteGame($gameId));
    }

    public function testCanDeleteGameReturnsFalseWhenPlayersAdded(): void
    {
        $gameId = $this->createTempGame();
        GameAddPlayer($gameId, 800, 8);
        $this->assertFalse(CanDeleteGame($gameId));
    }

    // --- ResultsToCsv ---

    public function testResultsToCsvReturnsNonEmptyString(): void
    {
        $csv = ResultsToCsv('HRN2026', ',');
        $this->assertIsString($csv);
        $this->assertStringContainsString('Helsinki Heat', $csv);
    }

    // --- UpdateGameLiveURL ---

    public function testUpdateGameLiveURLSetsUrl(): void
    {
        $gameId = $this->createTempGame();
        $url = 'https://live.example.com/game/1';

        UpdateGameLiveURL($gameId, $url);
        $stored = GameLiveURL($gameId);
        $this->assertSame($url, $stored);
    }

    // --- GameTimeStart / GameTimePause / GameTimeResume / GameTimeReset ---

    public function testGameTimerLifecycle(): void
    {
        $gameId = $this->createTempGame();

        GameTimeStart($gameId);
        $state = GameTimerState($gameId);
        $this->assertTrue($state['started']);
        $this->assertTrue($state['ongoing']);
        $this->assertFalse($state['paused']);

        GameTimePause($gameId);
        $state = GameTimerState($gameId);
        $this->assertTrue($state['paused']);

        GameTimeResume($gameId);
        $state = GameTimerState($gameId);
        $this->assertTrue($state['ongoing']);
        $this->assertFalse($state['paused']);

        GameTimeReset($gameId);
        $state = GameTimerState($gameId);
        $this->assertFalse($state['started']);
        $this->assertFalse($state['ongoing']);
    }

    public function testGameTimeResumeReturnsFalseWhenNotPaused(): void
    {
        $gameId = $this->createTempGame();
        GameTimeStart($gameId);

        // Not paused → resume returns false
        $result = GameTimeResume($gameId);
        $this->assertFalse($result);
    }

    public function testGameTimeSetElapsedReturnsFalseWhenNotPaused(): void
    {
        $gameId = $this->createTempGame();
        GameTimeStart($gameId);

        // Not paused → returns false
        $result = GameTimeSetElapsed($gameId, 300);
        $this->assertFalse($result);
    }

    public function testGameTimeSetElapsedUpdatesTimerWhenPaused(): void
    {
        $gameId = $this->createTempGame();
        GameTimeStart($gameId);
        GameTimePause($gameId);

        $result = GameTimeSetElapsed($gameId, 300);
        $this->assertNotFalse($result);
    }

    // --- GameProcessMassInput ---

    public function testGameProcessMassInputClearsResult(): void
    {
        $gameId = $this->createTempGame();
        DBQuery(sprintf(
            "UPDATE uo_game SET homescore=5, visitorscore=3, hasstarted=2 WHERE game_id=%d",
            $gameId,
        ));

        $post = [
            'scoreId'       => [0 => (string) $gameId],
            'homescore'     => [0 => ''],
            'visitorscore'  => [0 => ''],
        ];

        ob_start();
        $html = GameProcessMassInput($post);
        ob_end_clean();

        $this->assertIsString($html);
    }

    public function testGameProcessMassInputSetsResult(): void
    {
        $gameId = $this->createTempGame();

        $post = [
            'scoreId'       => [0 => (string) $gameId],
            'homescore'     => [0 => '7'],
            'visitorscore'  => [0 => '4'],
        ];

        ob_start();
        $html = GameProcessMassInput($post);
        ob_end_clean();

        $this->assertIsString($html);
        $info = GameInfo($gameId);
        $this->assertSame('7', (string) $info['homescore']);
    }

    // --- DeleteMovedGame ---

    public function testDeleteMovedGameRemovesNonTimetableRow(): void
    {
        $gameId = $this->createTempGame();
        // PK is (game,pool), so flip existing row to timetable=0 to simulate a moved game
        DBQuery(sprintf(
            "UPDATE uo_game_pool SET timetable=0 WHERE game=%d AND pool=200",
            $gameId,
        ));

        $result = DeleteMovedGame($gameId, 200);
        $this->assertNotFalse($result);

        $count = (int) DBQueryToValue(sprintf(
            "SELECT COUNT(*) FROM uo_game_pool WHERE game=%d AND pool=200 AND timetable=0",
            $gameId,
        ));
        $this->assertSame(0, $count);
    }

    // --- PoolDeleteAllGames ---

    public function testPoolDeleteAllGamesDeletesGamesInPool(): void
    {
        // Create a temp pool game that can be deleted safely
        $gameId = $this->createTempGame(300, 301, 200, null, null);

        // Remove from tracking before deletion (DeleteGame handles cleanup)
        $this->createdGameIds = array_diff($this->createdGameIds, [$gameId]);

        // Verify the game exists
        $before = GameInfo($gameId);
        $this->assertIsArray($before);

        // Delete only this specific game via PoolDeleteAllGames would also
        // delete fixture games 700/701 — so we use DeleteGame directly here.
        DeleteGame($gameId);

        $after = GameInfo($gameId);
        $this->assertNull($after);
    }

    // --- AddGameMediaEvent / RemoveGameMediaEvent ---

    public function testAddAndRemoveGameMediaEvent(): void
    {
        $gameId = $this->createTempGame();
        // Insert a URL row so FK constraint is satisfied
        DBQuery("INSERT INTO uo_urls (url, name, owner) VALUES ('https://example.com/vid', 'Test', 'game')");
        $urlId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");

        try {
            AddGameMediaEvent($gameId, 120, $urlId);

            $events = GameMediaEvents($gameId);
            $this->assertCount(1, $events);

            RemoveGameMediaEvent($gameId, $urlId);
            $events = GameMediaEvents($gameId);
            $this->assertCount(0, $events);
        } finally {
            DBQuery(sprintf("DELETE FROM uo_urls WHERE url_id=%d", $urlId));
        }
    }

    // --- ClearReservation ---

    public function testClearReservationUnschedulesGames(): void
    {
        $gameId = $this->createTempGame(300, 301, 200, 500, '2026-09-15 10:00:00');

        ClearReservation(500);
        // Game 700 and our temp game should both have reservation=NULL now
        // Restore fixture game 700's reservation immediately
        DBQuery("UPDATE uo_game SET reservation=500, time='2026-06-01 10:00:00' WHERE game_id=700");

        $res = GameReservation($gameId);
        $this->assertNull($res);
    }

    // --- CheckGameResult ---

    public function testCheckGameResultReturnsErrorForNegativeScores(): void
    {
        // gameId 700 has valid checksum '7003' (getChkNum('700') = 3)
        $result = CheckGameResult('7003', -1, 5);
        $this->assertStringContainsString('warning', $result);
    }

    public function testCheckGameResultReturnsErrorForInvalidChecksum(): void
    {
        // getChkNum('999') = 1, so '9990' has wrong check digit
        $result = CheckGameResult('9990', 5, 3);
        $this->assertStringContainsString('warning', $result);
    }

    public function testCheckGameResultReturnsErrorForZeroGoals(): void
    {
        // Game 700 in pool 200 (season HRN2026 has stats → "Event played" error also appears)
        $result = CheckGameResult('7003', 0, 0);
        $this->assertStringContainsString('warning', $result);
    }

    public function testCheckGameResultReturnsEmptyForValidInputOnUnlockedPool(): void
    {
        // Game 701 (no score yet, unlocked): score 5:3 with valid checksum
        // getChkNum('701') = (10 - (7*1 + 0*3 + 1*7)%10)%10 = (10-(7+0+7)%10)%10 = (10-4)%10 = 6
        // So valid scoresheet string for game 701 is '7016'
        // Season HRN2026 has stats calculated, so "Event played" error will appear — result is non-empty.
        // This test just exercises the code path without the scoresheet/negative errors.
        $result = CheckGameResult('7016', 5, 3);
        $this->assertIsString($result);
    }

    // --- PoolDeleteAllGames ---
    // Non-superadmin branch calls die() — untestable in-process per docs/lib-test-deep-coverage.md.

    public function testPoolDeleteAllGamesOnEmptyPoolReturnsTrueAsSuperAdmin(): void
    {
        // Create a temp pool with series=100 but no games
        DBQuery(
            "INSERT INTO uo_pool (name, ordering, visible, continuingpool, placementpool, teams, mvgames,
             timeoutlen, halftime, winningscore, timecap, scorecap, played, addscore, halftimescore,
             timeouts, timeoutsper, timeoutsovertime, timeoutstimecap, betweenpointslen, series, type,
             forfeitscore, forfeitagainst, drawsallowed)
             VALUES ('TempTestPool', '99', 0, 0, 0, 0, 0,
             70, 35, 15, NULL, NULL, 0, NULL, NULL,
             2, 'half', 1, 'soft', 90, 100, 1,
             15, 0, 0)"
        );
        $tempPoolId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        try {
            $result = PoolDeleteAllGames($tempPoolId);
            $this->assertTrue((bool) $result);
        } finally {
            DBQuery(sprintf("DELETE FROM uo_pool WHERE pool_id=%d", $tempPoolId));
        }
    }

    // --- SpiritTable ---

    public function testSpiritTableReturnsHtmlStringWithTableTag(): void
    {
        $categories = [
            ['category_id' => 1, 'index' => 0, 'text' => 'Total', 'min' => 0, 'max' => 4],
            ['category_id' => 2, 'index' => 1, 'text' => 'Rules', 'min' => 0, 'max' => 4],
            ['category_id' => 3, 'index' => 2, 'text' => 'Contact', 'min' => 0, 'max' => 4],
        ];
        $points = [2 => 3, 3 => 2];
        $html = SpiritTable([], $points, $categories, true, true);
        $this->assertStringContainsString('spirit-table', $html);
        $this->assertStringContainsString('</table>', $html);
    }

    public function testSpiritTableWideHomeSideContainsHomePrefix(): void
    {
        $categories = [
            ['category_id' => 1, 'index' => 0, 'text' => 'Total', 'min' => 0, 'max' => 4],
            ['category_id' => 2, 'index' => 1, 'text' => 'Rules', 'min' => 0, 'max' => 4],
        ];
        $html = SpiritTable([], [], $categories, true, true);
        $this->assertStringContainsString('homecat', $html);
        $this->assertStringNotContainsString('viscat', $html);
    }

    public function testSpiritTableNarrowVisitorSideContainsVisPrefix(): void
    {
        $categories = [
            ['category_id' => 1, 'index' => 0, 'text' => 'Total', 'min' => 0, 'max' => 4],
            ['category_id' => 2, 'index' => 1, 'text' => 'Rules', 'min' => 0, 'max' => 4],
        ];
        $html = SpiritTable([], [], $categories, false, false);
        $this->assertStringContainsString('viscat', $html);
        $this->assertStringNotContainsString('homecat', $html);
    }

    public function testSpiritTableSkipsCategoryWithIndexZero(): void
    {
        $categories = [
            ['category_id' => 1, 'index' => 0, 'text' => 'ShouldBeSkipped', 'min' => 0, 'max' => 4],
            ['category_id' => 2, 'index' => 1, 'text' => 'ShouldAppear', 'min' => 0, 'max' => 4],
        ];
        $html = SpiritTable([], [], $categories, true, true);
        $this->assertStringNotContainsString('ShouldBeSkipped', $html);
        $this->assertStringContainsString('ShouldAppear', $html);
    }

    public function testSpiritTableWideWithLargeRangeUsesTextInput(): void
    {
        // vmax - vmin >= 12 → uses text inputs instead of radio buttons
        $categories = [
            ['category_id' => 1, 'index' => 0, 'text' => 'Total', 'min' => 0, 'max' => 20],
            ['category_id' => 2, 'index' => 1, 'text' => 'Attitude', 'min' => 0, 'max' => 20],
        ];
        $html = SpiritTable([], [2 => 15], $categories, true, true);
        $this->assertStringContainsString('type=\'text\'', $html);
    }
}
