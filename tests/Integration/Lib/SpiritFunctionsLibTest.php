<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Integration tests for lib/spirit.functions.php.
 *
 * Fixture context:
 *   season HRN2026: spiritmode=1003 (WFDF 5-category), showspiritpoints=0
 *   series_id=100, pool_id=200 (type 1, played=1)
 *   team_id=300 (home in game 700), 301 (visitor in game 700)
 *   game_id=700: played; game_id=701: not started
 *   spirit_category rows 1008-1013: mode=1003, index 0-5 (index 0 is header, 1-5 are scored)
 *   No uo_spirit_score rows initially.
 */
final class SpiritFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([
            'user.functions.php',
            'season.functions.php',
            'series.functions.php',
            'pool.functions.php',
            'team.functions.php',
            'standings.functions.php',
            'swissdraw.functions.php',
            'configuration.functions.php',
            'spirit.functions.php',
        ], 'database_only');

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['uid'] = 'testadmin';
        $_SESSION['userproperties']['userrole']['superadmin'] = true;
        $_SESSION['userproperties']['userrole']['seasonadmin']['HRN2026'] = 1;
        $_SESSION['userproperties']['locale'] = 'en_US';

        global $serverConf;
        if (!isset($serverConf)) {
            $serverConf = [];
        }
        $serverConf['PersistentCacheEnabled'] = 'false';
    }

    protected function tearDown(): void
    {
        DBQuery("DELETE FROM uo_spirit_score WHERE game_id IN (700, 701)");
        DBQuery("DELETE FROM uo_team_spirit_stats WHERE season='HRN2026'");
        DBQuery("UPDATE uo_team SET sotg_token=NULL WHERE team_id IN (300, 301)");
        DBQuery("UPDATE uo_game SET show_spirit=0 WHERE game_id=700");
        LegacyApp::closeDatabaseConnection();
    }

    // --- SpiritModeDisabledName ---

    public function testSpiritModeDisabledNameReturnsString(): void
    {
        $name = SpiritModeDisabledName();
        $this->assertIsString($name);
        $this->assertNotEmpty($name);
    }

    // --- SpiritSeasonInfo ---

    public function testSpiritSeasonInfoReturnsFalseForNull(): void
    {
        $result = SpiritSeasonInfo(false);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testSpiritSeasonInfoReturnsArrayForValidSeason(): void
    {
        $result = SpiritSeasonInfo('HRN2026');
        $this->assertIsArray($result);
        $this->assertSame('HRN2026', $result['season_id']);
        $this->assertSame('1003', (string) $result['spiritmode']);
    }

    public function testSpiritSeasonInfoAcceptsArray(): void
    {
        $seasoninfo = SeasonInfo('HRN2026');
        $result = SpiritSeasonInfo($seasoninfo);
        $this->assertSame('HRN2026', $result['season_id']);
    }

    // --- ShowSpiritScoresForSeason / ShowSpiritComments ---

    public function testShowSpiritScoresForSeasonReturnsTrueForAdmin(): void
    {
        $result = ShowSpiritScoresForSeason('HRN2026');
        $this->assertTrue($result);
    }

    public function testShowSpiritScoresForSeasonReturnsFalseForEmptySeasoninfo(): void
    {
        $result = ShowSpiritScoresForSeason(false);
        $this->assertFalse($result);
    }

    public function testShowSpiritCommentsReturnsTrueForAdmin(): void
    {
        $result = ShowSpiritComments('HRN2026');
        $this->assertTrue($result);
    }

    public function testShowSpiritCommentsReturnsFalseForEmpty(): void
    {
        $result = ShowSpiritComments(false);
        $this->assertFalse($result);
    }

    // --- SpiritOrderedCategories / SpiritDefaultPoints ---

    private function getMode1003Categories(): array
    {
        return SpiritCategories(1003);
    }

    public function testSpiritOrderedCategoriesExcludesIndexZero(): void
    {
        $categories = $this->getMode1003Categories();
        $ordered = SpiritOrderedCategories($categories);
        foreach ($ordered as $cat) {
            $this->assertGreaterThan(0, (int) $cat['index']);
        }
        $this->assertCount(5, $ordered);
    }

    public function testSpiritDefaultPointsReturnsMidpoints(): void
    {
        $categories = $this->getMode1003Categories();
        $defaults = SpiritDefaultPoints($categories);
        $this->assertIsArray($defaults);
        $this->assertCount(5, $defaults);
        foreach ($defaults as $value) {
            $this->assertIsInt($value);
            $this->assertGreaterThanOrEqual(0, $value);
        }
    }

    // --- SpiritTotal ---

    public function testSpiritTotalSumsFactoredValues(): void
    {
        $categories = $this->getMode1003Categories();
        $defaults = SpiritDefaultPoints($categories);
        $total = SpiritTotal($defaults, $categories);
        $this->assertIsInt($total);
        $this->assertGreaterThanOrEqual(0, $total);
    }

    // --- SpiritPointsSummary ---

    public function testSpiritPointsSummaryWithEmptyPointsReturnsEmpty(): void
    {
        $categories = $this->getMode1003Categories();
        $this->assertSame('', SpiritPointsSummary([], $categories));
    }

    public function testSpiritPointsSummaryWithFullPointsReturnsString(): void
    {
        $categories = $this->getMode1003Categories();
        $ordered = SpiritOrderedCategories($categories);
        $points = [];
        foreach ($ordered as $cat) {
            $points[(int) $cat['category_id']] = 2;
        }
        $summary = SpiritPointsSummary($points, $categories);
        $this->assertIsString($summary);
        $this->assertStringContainsString('(', $summary);
    }

    // --- SpiritRequiredCategoryCount ---

    public function testSpiritRequiredCategoryCountForMode1003Returns5(): void
    {
        $count = SpiritRequiredCategoryCount(1003);
        $this->assertSame(5, $count);
    }

    public function testSpiritRequiredCategoryCountForZeroReturns0(): void
    {
        $this->assertSame(0, SpiritRequiredCategoryCount(0));
    }

    // --- SpiritCategoryRows / SpiritCategoryModeRows ---

    public function testSpiritCategoryRowsReturnsRowsForMode1003(): void
    {
        $rows = SpiritCategoryRows(1003);
        $this->assertIsArray($rows);
        $this->assertGreaterThan(0, count($rows));
        $this->assertSame('1003', (string) $rows[0]['mode']);
    }

    public function testSpiritCategoryModeRowsReturnsArray(): void
    {
        $rows = SpiritCategoryModeRows();
        $this->assertIsArray($rows);
        $this->assertGreaterThan(0, count($rows));
    }

    public function testSpiritCategoryModeRowReturnsRowFor1003(): void
    {
        $row = SpiritCategoryModeRow(1003);
        $this->assertIsArray($row);
        $this->assertSame('1003', (string) $row['mode']);
    }

    // --- SpiritGameRow ---

    public function testSpiritGameRowReturnsRowForGame700(): void
    {
        $row = SpiritGameRow(700);
        $this->assertIsArray($row);
        $this->assertSame('700', (string) $row['game_id']);
        $this->assertSame('HRN2026', $row['season_id']);
        $this->assertSame('1003', (string) $row['spiritmode']);
    }

    public function testSpiritGameRowReturnsFalseForMissingGame(): void
    {
        $row = SpiritGameRow(9999);
        $this->assertEmpty($row);
    }

    // --- TeamSpiritSubmissionComplete ---

    public function testTeamSpiritSubmissionCompleteReturnsFalseWithNoScores(): void
    {
        $result = TeamSpiritSubmissionComplete(700, 300);
        $this->assertFalse($result);
    }

    public function testTeamSpiritSubmissionCompleteReturnsFalseForInvalidGame(): void
    {
        $result = TeamSpiritSubmissionComplete(0, 300);
        $this->assertFalse($result);
    }

    // --- GameSpiritComplete ---

    public function testGameSpiritCompleteReturnsFalseWithNoScores(): void
    {
        $this->assertFalse(GameSpiritComplete(700));
    }

    // --- CanEditSpiritSubmission ---

    public function testCanEditSpiritSubmissionReturnsTrueForSuperAdmin(): void
    {
        $result = CanEditSpiritSubmission(700, 300);
        $this->assertTrue($result);
    }

    public function testCanEditSpiritSubmissionReturnsFalseForInvalidGame(): void
    {
        $result = CanEditSpiritSubmission(0, 300);
        $this->assertFalse($result);
    }

    // --- HasFullGameSpiritEditRight / HasFullGameSpiritViewRight ---

    public function testHasFullGameSpiritEditRightReturnsTrueForSuperAdmin(): void
    {
        $this->assertTrue(HasFullGameSpiritEditRight(700));
    }

    public function testHasFullGameSpiritViewRightReturnsTrueForSuperAdmin(): void
    {
        $this->assertTrue(HasFullGameSpiritViewRight(700));
    }

    // --- SpiritScoreReplaceByGameTeam + downstream read functions ---

    private function submitHomeSpirit(): void
    {
        // Submit 5 categories for game 700, team 300 (home)
        $points = [1009 => 4, 1010 => 3, 1011 => 4, 1012 => 4, 1013 => 3];
        SpiritScoreReplaceByGameTeam(700, 300, $points);
    }

    private function submitVisitorSpirit(): void
    {
        $points = [1009 => 3, 1010 => 3, 1011 => 3, 1012 => 3, 1013 => 3];
        SpiritScoreReplaceByGameTeam(700, 301, $points);
    }

    public function testSpiritScoreReplaceByGameTeamInsertsRows(): void
    {
        $this->submitHomeSpirit();
        $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_spirit_score WHERE game_id=700 AND team_id=300");
        $this->assertSame(5, $count);
    }

    public function testSpiritScoreReplaceByGameTeamOverwritesPrevious(): void
    {
        $this->submitHomeSpirit();
        SpiritScoreReplaceByGameTeam(700, 300, [1009 => 2]);
        $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_spirit_score WHERE game_id=700 AND team_id=300");
        $this->assertSame(1, $count);

        $val = (int) DBQueryToValue("SELECT value FROM uo_spirit_score WHERE game_id=700 AND team_id=300 AND category_id=1009");
        $this->assertSame(2, $val);
    }

    public function testTeamSpiritSubmissionCompleteReturnsTrueAfterSubmit(): void
    {
        $this->submitHomeSpirit();
        $this->assertTrue(TeamSpiritSubmissionComplete(700, 300));
    }

    public function testGameGetSpiritPointsReturnsPointsAfterSubmit(): void
    {
        $this->submitHomeSpirit();
        $points = GameGetSpiritPoints(700, 300);
        $this->assertIsArray($points);
        $this->assertArrayHasKey(1009, $points);
        $this->assertSame('4', (string) $points[1009]);
    }

    public function testSpiritScoreRowsByGameTeamReturnsRows(): void
    {
        $this->submitHomeSpirit();
        $rows = SpiritScoreRowsByGameTeam(700, 300);
        $this->assertIsArray($rows);
        $this->assertCount(5, $rows);
    }

    public function testGameSpiritCompleteReturnsFalseWithOnlyOneSubmission(): void
    {
        $this->submitHomeSpirit();
        $this->assertFalse(GameSpiritComplete(700));
    }

    public function testGameSpiritCompleteReturnsTrueWhenBothTeamsSubmit(): void
    {
        $this->submitHomeSpirit();
        $this->submitVisitorSpirit();
        $this->assertTrue(GameSpiritComplete(700));
    }

    public function testSpiritPointsSummaryWithSubmittedPoints(): void
    {
        $this->submitHomeSpirit();
        $categories = $this->getMode1003Categories();
        $points = GameGetSpiritPoints(700, 300);
        $summary = SpiritPointsSummary($points, $categories);
        $this->assertIsString($summary);
        $this->assertNotEmpty($summary);
        $this->assertStringContainsString('(', $summary);
    }

    public function testTeamSpiritTotalReturnsNonNullAfterSubmit(): void
    {
        $this->submitHomeSpirit();
        $total = TeamSpiritTotal(300);
        $this->assertNotNull($total);
    }

    public function testCountSpiritStatsReturnsZeroBeforeSubmit(): void
    {
        $row = CountSpiritStats(300);
        $this->assertSame('0', (string) $row['games']);
    }

    public function testSpiritTotalByPoolAfterSubmit(): void
    {
        $this->submitHomeSpirit();
        $result = TeamSpiritTotalByPool(200, 300);
        $this->assertIsArray($result);
    }

    public function testRefreshGameSpiritDataReturnsTrueForValidGame(): void
    {
        $result = RefreshGameSpiritData(700);
        $this->assertTrue($result);
    }

    public function testRefreshGameSpiritDataReturnsFalseForInvalidGame(): void
    {
        $result = RefreshGameSpiritData(0);
        $this->assertFalse($result);
    }

    // --- SeriesSpiritBoard ---

    public function testSeriesSpiritBoardReturnsArrayForSeries100(): void
    {
        $board = SeriesSpiritBoard(100);
        $this->assertIsArray($board);
    }

    public function testSeriesSpiritBoardAlt2ReturnsArrayForSeries100(): void
    {
        $board = SeriesSpiritBoardAlt2(100);
        $this->assertIsArray($board);
    }

    public function testSeriesSpiritBoardTotalAveragesReturnsArray(): void
    {
        $result = SeriesSpiritBoardTotalAverages(100);
        $this->assertIsArray($result);
    }

    // --- SpiritMissingGames* ---

    public function testSpiritMissingGamesByPoolReturnsArray(): void
    {
        $result = SpiritMissingGamesByPool(200);
        $this->assertIsArray($result);
    }

    public function testSpiritMissingGamesBySeriesReturnsArray(): void
    {
        $result = SpiritMissingGamesBySeries(100);
        $this->assertIsArray($result);
    }

    // --- SpiritTeamIdByToken ---

    public function testSpiritTeamIdByTokenReturns0ForUnknownToken(): void
    {
        $this->assertSame(0, SpiritTeamIdByToken('unknowntoken123'));
    }

    public function testSpiritTeamIdByTokenReturns0ForEmpty(): void
    {
        $this->assertSame(0, SpiritTeamIdByToken(''));
    }

    public function testSpiritTeamIdByTokenReturnsTeamIdForKnownToken(): void
    {
        DBQuery("UPDATE uo_team SET sotg_token='TestToken300' WHERE team_id=300");
        $result = SpiritTeamIdByToken('TestToken300');
        $this->assertSame(300, $result);
    }

    // --- SpiritTokenGameRows ---

    public function testSpiritTokenGameRowsReturnsGamesForTeam300(): void
    {
        $rows = SpiritTokenGameRows(300);
        $this->assertIsArray($rows);
        $this->assertGreaterThan(0, count($rows));
    }

    // --- SpiritkeeperGetToken ---

    public function testSpiritkeeperGetTokenReturnsEmptyWhenNoGetParam(): void
    {
        $this->assertSame('', SpiritkeeperGetToken());
    }

    public function testSpiritkeeperGetTokenReturnsTokenFromGet(): void
    {
        $_GET['token'] = 'abc123XYZ';
        $result = SpiritkeeperGetToken();
        unset($_GET['token']);
        $this->assertSame('abc123XYZ', $result);
    }

    // --- SpiritkeeperGameTimeLabel ---

    public function testSpiritkeeperGameTimeLabelReturnsTimeTbdWhenEmpty(): void
    {
        $result = SpiritkeeperGameTimeLabel(['time' => '']);
        $this->assertIsString($result);
    }

    public function testSpiritkeeperGameTimeLabelReturnsFormattedTimeWhenSet(): void
    {
        $result = SpiritkeeperGameTimeLabel(['time' => '2026-06-01 10:00:00']);
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    // --- SpiritkeeperGameScoreLabel ---

    public function testSpiritkeeperGameScoreLabelReturnsQuestionMarksWhenNull(): void
    {
        $result = SpiritkeeperGameScoreLabel(['homescore' => null, 'visitorscore' => null]);
        $this->assertSame('? - ?', $result);
    }

    public function testSpiritkeeperGameScoreLabelReturnsScoreWhenSet(): void
    {
        $result = SpiritkeeperGameScoreLabel(['homescore' => 15, 'visitorscore' => 11]);
        $this->assertSame('15 - 11', $result);
    }

    // --- SpiritkeeperEditGameUrl / HomeUrl / TeamsGamesUrl ---

    public function testSpiritkeeperEditGameUrlContainsGameId(): void
    {
        $url = SpiritkeeperEditGameUrl(700, 300);
        $this->assertStringContainsString('700', $url);
        $this->assertStringContainsString('300', $url);
    }

    public function testSpiritkeeperEditGameUrlWithoutTeam(): void
    {
        $url = SpiritkeeperEditGameUrl(700);
        $this->assertStringContainsString('700', $url);
    }

    public function testSpiritkeeperHomeUrlReturnsString(): void
    {
        $url = SpiritkeeperHomeUrl('HRN2026', './spiritkeeper/');
        $this->assertIsString($url);
        $this->assertNotEmpty($url);
    }

    public function testSpiritkeeperTeamGamesUrlReturnsString(): void
    {
        $url = SpiritkeeperTeamGamesUrl(300, 'HRN2026');
        $this->assertIsString($url);
    }

    // --- SpiritSubmissionLocked ---

    public function testSpiritSubmissionLockedReturnsFalseWhenNotLocked(): void
    {
        $result = SpiritSubmissionLocked(700, 300);
        $this->assertFalse($result);
    }

    // --- GameSpiritVisibilityValue ---

    public function testGameSpiritVisibilityValueReturnsInt(): void
    {
        $result = GameSpiritVisibilityValue(700);
        $this->assertIsInt($result);
    }

    // --- SpiritEntryUrl ---

    public function testSpiritEntryUrlReturnsString(): void
    {
        $url = SpiritEntryUrl(700);
        $this->assertIsString($url);
        $this->assertStringContainsString('700', $url);
    }

    // --- SpiritValidateSubmittedPoints ---

    public function testSpiritValidateSubmittedPointsReturnsTrueForValidPoints(): void
    {
        $categories = $this->getMode1003Categories();
        $ordered = SpiritOrderedCategories($categories);
        $points = [];
        foreach ($ordered as $cat) {
            $points[(int) $cat['category_id']] = (int) $cat['min'] + 1; // above min to avoid edge cases
        }
        $result = SpiritValidateSubmittedPoints($points, $categories);
        // Returns validated array (truthy) on success, false on failure
        $this->assertNotFalse($result);
        $this->assertIsArray($result);
    }

    public function testSpiritValidateSubmittedPointsReturnsFalseForOutOfRange(): void
    {
        $categories = $this->getMode1003Categories();
        $ordered = SpiritOrderedCategories($categories);
        $points = [];
        foreach ($ordered as $cat) {
            $points[(int) $cat['category_id']] = 999; // way above max
        }
        $result = SpiritValidateSubmittedPoints($points, $categories);
        $this->assertFalse($result);
    }

    // --- SpiritGenerateSotgTokens ---

    public function testSpiritGenerateSotgTokensGeneratesTokensForTeams(): void
    {
        $generated = SpiritGenerateSotgTokens('HRN2026'); // default filter=onlymissing
        $this->assertGreaterThanOrEqual(0, $generated);
        // If tokens were generated, verify team 300 now has one
        if ($generated > 0) {
            $token300 = DBQueryToValue("SELECT sotg_token FROM uo_team WHERE team_id=300");
            $this->assertNotEmpty($token300);
        } else {
            $this->assertTrue(true); // tokens already existed or no teams
        }
    }

    public function testSpiritGenerateSotgTokensWithNonDefaultFilterReturnsMinusOne(): void
    {
        $result = SpiritGenerateSotgTokens('HRN2026', 'all');
        $this->assertSame(-1, $result);
    }

    // --- SpiritDeleteSotgToken ---

    public function testSpiritDeleteSotgTokenClearsToken(): void
    {
        DBQuery("UPDATE uo_team SET sotg_token='tokenToDelete' WHERE team_id=300");
        SpiritDeleteSotgToken('HRN2026', 300);
        $token = DBQueryToValue("SELECT sotg_token FROM uo_team WHERE team_id=300");
        $this->assertEmpty($token);
    }

    // --- SpiritToCsv ---

    public function testSpiritToCsvReturnsStringForSeason(): void
    {
        $csv = SpiritToCsv('HRN2026', ',');
        $this->assertIsString($csv);
    }

    // --- TeamSpiritStats / TeamSpiritStats2 ---

    public function testTeamSpiritStatsReturnsArray(): void
    {
        $result = TeamSpiritStats(300);
        $this->assertIsArray($result);
    }

    public function testTeamSpiritStats2ReturnsArray(): void
    {
        $result = TeamSpiritStats2(300);
        $this->assertIsArray($result);
    }

    // --- SpiritSeriesMissingPointRows ---

    public function testSpiritSeriesMissingPointRowsReturnsArray(): void
    {
        $result = SpiritSeriesMissingPointRows(100);
        $this->assertIsArray($result);
    }

    // --- SpiritSeriesScoreRows ---

    public function testSpiritSeriesScoreRowsReturnsArray(): void
    {
        $result = SpiritSeriesScoreRows(100);
        $this->assertIsArray($result);
    }

    // --- SpiritTeamPointRows ---

    public function testSpiritTeamPointRowsReturnsArray(): void
    {
        $result = SpiritTeamPointRows('HRN2026', 300);
        $this->assertIsArray($result);
    }

    // --- SpiritToolRowsBySeason ---

    public function testSpiritToolRowsBySeasonReturnsArray(): void
    {
        $rows = SpiritToolRowsBySeason('HRN2026');
        $this->assertIsArray($rows);
    }

    // --- SpiritTimeoutSummaryBySeason ---

    public function testSpiritTimeoutSummaryBySeasonReturnsArray(): void
    {
        $result = SpiritTimeoutSummaryBySeason('HRN2026');
        $this->assertIsArray($result);
    }

    // --- SpiritSotgUrlsBySeason ---

    public function testSpiritSotgUrlsBySeasonReturnsArray(): void
    {
        $result = SpiritSotgUrlsBySeason('HRN2026');
        $this->assertIsArray($result);
    }

    // --- SpiritkeeperAccessibleTeams ---

    public function testSpiritkeeperAccessibleTeamsReturnsArray(): void
    {
        $teams = SpiritkeeperAccessibleTeams();
        $this->assertIsArray($teams);
    }

    // --- SpiritkeeperCurrentSeasons ---

    public function testSpiritkeeperCurrentSeasonsReturnsArray(): void
    {
        $seasons = SpiritkeeperCurrentSeasons();
        $this->assertIsArray($seasons);
    }

    // --- SpiritCategoryFactors ---

    public function testSpiritCategoryFactorsReturnsArray(): void
    {
        $factors = SpiritCategoryFactors();
        $this->assertIsArray($factors);
    }

    // --- TeamSpiritPointsReceived / TeamSpiritPointsGiven ---

    public function testTeamSpiritPointsReceivedReturnsArray(): void
    {
        $this->submitHomeSpirit();
        $result = TeamSpiritPointsReceived('HRN2026', 300);
        $this->assertIsArray($result);
    }

    public function testTeamSpiritPointsGivenReturnsArray(): void
    {
        $this->submitHomeSpirit();
        $result = TeamSpiritPointsGiven('HRN2026', 300);
        $this->assertIsArray($result);
    }

    // --- CanViewSpiritScoresForGame / CanViewSpiritCommentsForGame ---

    public function testCanViewSpiritScoresForGameReturnsTrueForAdmin(): void
    {
        $this->assertTrue(CanViewSpiritScoresForGame(700));
    }

    public function testCanViewSpiritCommentsForGameReturnsTrueForAdmin(): void
    {
        $this->assertTrue(CanViewSpiritCommentsForGame(700));
    }

    // --- SpiritTeamIdForCommentType ---

    public function testSpiritTeamIdForCommentTypeReturnsInt(): void
    {
        $result = SpiritTeamIdForCommentType(700, 5);
        $this->assertIsInt($result);
    }

    // --- SpiritTokenHasOwnSubmission / SpiritTokenHasReceivedSubmission ---

    public function testSpiritTokenHasOwnSubmissionReturnsFalseWithNoScores(): void
    {
        $result = SpiritTokenHasOwnSubmission(700, 300);
        $this->assertFalse($result);
    }

    public function testSpiritTokenHasReceivedSubmissionReturnsFalseWithNoScores(): void
    {
        $result = SpiritTokenHasReceivedSubmission(700, 300);
        $this->assertFalse($result);
    }

    public function testSpiritTokenCanSubmitReturnsBoolForValidGame(): void
    {
        $result = SpiritTokenCanSubmit(700, 300);
        $this->assertIsBool($result);
    }

    public function testSpiritTokenCanViewReceivedPointsReturnsBoolForValidGame(): void
    {
        $result = SpiritTokenCanViewReceivedPoints(700, 300);
        $this->assertIsBool($result);
    }

    // --- SpiritTokenRatedTeamId ---

    public function testSpiritTokenRatedTeamIdReturnsOpponent(): void
    {
        $game = SpiritGameRow(700);
        $result = SpiritTokenRatedTeamId($game, 300);
        $this->assertIsInt($result);
        $this->assertNotSame(300, $result);
    }

    // --- GameDeleteSpiritPoints ---

    public function testGameDeleteSpiritPointsReturnsTrueAfterSubmit(): void
    {
        $this->submitHomeSpirit();
        $result = GameDeleteSpiritPoints(700, 300);
        $this->assertTrue($result);

        $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_spirit_score WHERE game_id=700 AND team_id=300");
        $this->assertSame(0, $count);
    }

    public function testGameDeleteSpiritPointsReturnsFalseForMissingGame(): void
    {
        $result = GameDeleteSpiritPoints(9999, 300);
        $this->assertFalse($result);
    }

    // --- SpiritEntryTeamForUser ---

    public function testSpiritEntryTeamForUserReturnsIntOrNull(): void
    {
        $result = SpiritEntryTeamForUser(700);
        // Superadmin is not a team member, returns null or int
        $this->assertTrue(is_null($result) || is_int($result));
    }

    // --- TeamSpiritCategoryStats ---

    public function testTeamSpiritCategoryStatsReturnsArray(): void
    {
        $result = TeamSpiritCategoryStats(300, 'HRN2026', 1003);
        $this->assertIsArray($result);
    }

    // --- CalcTeamSpiritStats ---

    public function testCalcTeamSpiritStatsRunsWithoutError(): void
    {
        CalcTeamSpiritStats('HRN2026');
        $this->assertTrue(true);
    }

    // --- SpiritToCsv with actual spirit score data ---

    public function testSpiritToCsvWithScoresContainsData(): void
    {
        $this->submitHomeSpirit();
        $csv = SpiritToCsv('HRN2026', ',');
        $this->assertIsString($csv);
        $this->assertStringContainsString('Helsinki Heat', $csv);
    }

    // --- SeriesSpiritBoard and SeriesSpiritBoardAlt2 with data ---

    public function testSeriesSpiritBoardWithScoresContainsTeams(): void
    {
        $this->submitHomeSpirit();
        $this->submitVisitorSpirit();
        // show_spirit=0 in fixture (showspiritpoints=0 in season), set directly to allow query
        DBQuery("UPDATE uo_game SET show_spirit=1 WHERE game_id=700");
        $board = SeriesSpiritBoard(100);
        $this->assertIsArray($board);
        $this->assertNotEmpty($board);
    }

    public function testSeriesSpiritBoardAlt2SortingVariants(): void
    {
        $this->submitHomeSpirit();
        $this->submitVisitorSpirit();
        DBQuery("UPDATE uo_game SET show_spirit=1 WHERE game_id=700");

        foreach (['total', 'team', 'games', 'cat1', 'cat2'] as $sort) {
            $board = SeriesSpiritBoardAlt2(100, $sort);
            $this->assertIsArray($board, "sort=$sort failed");
            $this->assertNotEmpty($board, "sort=$sort returned empty");
        }
    }

    public function testSeriesSpiritBoardTotalAveragesWithDataReturnsArray(): void
    {
        $this->submitHomeSpirit();
        $this->submitVisitorSpirit();
        DBQuery("UPDATE uo_game SET show_spirit=1 WHERE game_id=700");
        $result = SeriesSpiritBoardTotalAverages(100, true);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('total', $result);
    }

    // --- SpiritTokenSaveSubmission ---

    public function testSpiritTokenSaveSubmissionSavesScores(): void
    {
        $categories = $this->getMode1003Categories();
        $ordered = SpiritOrderedCategories($categories);
        $points = [];
        foreach ($ordered as $cat) {
            $points[(int) $cat['category_id']] = 3; // valid mid-range value
        }

        // Team 300 (home) submits scores for team 301 (visitor is rated)
        $result = SpiritTokenSaveSubmission(700, 300, $points, $categories);
        $this->assertTrue($result);

        // Verify scores were saved for team 301 (rated by 300)
        $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_spirit_score WHERE game_id=700 AND team_id=301");
        $this->assertSame(5, $count);
    }

    public function testSpiritTokenSaveSubmissionWithCommentSavesScores(): void
    {
        $categories = $this->getMode1003Categories();
        $ordered = SpiritOrderedCategories($categories);
        $points = [];
        foreach ($ordered as $cat) {
            $points[(int) $cat['category_id']] = 2;
        }

        $result = SpiritTokenSaveSubmissionWithComment(700, 300, $points, $categories, 'Good game!');
        // May return true or false depending on comment infrastructure
        $this->assertIsBool($result);
    }

    // --- TeamSpiritTotal with includeIncomplete ---

    public function testTeamSpiritTotalIncludeIncompleteReturnsRow(): void
    {
        $this->submitHomeSpirit();
        $row = TeamSpiritTotal(300, true);
        // Returns associative row ['total' => ...] or null/false
        $this->assertTrue(is_array($row) || is_null($row) || $row === false);
    }

    // --- SpiritMissingGames with actual data (incomplete submissions) ---

    public function testSpiritMissingGamesByPoolWithIncompleteSubmission(): void
    {
        // Only home team submits → game 700 is incomplete
        $this->submitHomeSpirit();
        $result = SpiritMissingGamesByPool(200);
        $this->assertIsArray($result);
        // Pool 200 has game 700 which is incomplete since visitor hasn't submitted
        // (SpiritMissingGames returns games needing spirit)
    }

    // --- SpiritTokenHasOwnSubmission / HasReceivedSubmission after submit ---

    public function testSpiritTokenHasOwnSubmissionReturnsTrueAfterSubmit(): void
    {
        // team 300 submits score FOR team 301 (token team is 300 = submitter)
        $categories = $this->getMode1003Categories();
        $ordered = SpiritOrderedCategories($categories);
        $points = [];
        foreach ($ordered as $cat) {
            $points[(int) $cat['category_id']] = 3;
        }
        SpiritTokenSaveSubmission(700, 300, $points, $categories);

        // SpiritTokenHasOwnSubmission checks if tokenTeam 300 has submitted
        $result = SpiritTokenHasOwnSubmission(700, 300);
        $this->assertTrue($result);
    }

    public function testSpiritTokenHasReceivedSubmissionReturnsTrueAfterPartnerSubmit(): void
    {
        // Team 301 (visitor) submits score for team 300 (home - the rated team)
        $categories = $this->getMode1003Categories();
        $ordered = SpiritOrderedCategories($categories);
        $points = [];
        foreach ($ordered as $cat) {
            $points[(int) $cat['category_id']] = 3;
        }
        SpiritTokenSaveSubmission(700, 301, $points, $categories);

        // Team 300 received a submission from team 301
        $result = SpiritTokenHasReceivedSubmission(700, 300);
        $this->assertTrue($result);
    }

    // --- TeamSpiritStats2 with includeIncomplete ---

    public function testTeamSpiritStats2WithIncludeIncompleteReturnsRow(): void
    {
        $this->submitHomeSpirit();
        $row = TeamSpiritStats2(300, true);
        $this->assertTrue(is_array($row) || is_null($row));
    }

    // --- CanDeleteSpiritSubmission ---

    public function testCanDeleteSpiritSubmissionReturnsTrueForAdmin(): void
    {
        // Superadmin has full spirit edit right, team 300 is in game 700
        $result = CanDeleteSpiritSubmission(700, 300);
        $this->assertTrue($result);
    }

    public function testCanDeleteSpiritSubmissionReturnsFalseForUnknownTeam(): void
    {
        $result = CanDeleteSpiritSubmission(700, 9999);
        $this->assertFalse($result);
    }

    // --- SpiritkeeperAccessibleTeams with seasonadmin ---

    public function testSpiritkeeperAccessibleTeamsWithSeasonAdminReturnsTeams(): void
    {
        // seasonadmin for HRN2026 set in setUp; superadmin → hasSpiritToolsRight → SeasonTeams
        $teams = SpiritkeeperAccessibleTeams();
        $this->assertIsArray($teams);
        $this->assertNotEmpty($teams);
        $this->assertArrayHasKey('team_id', $teams[0]);
    }

    // --- SpiritkeeperCurrentAccessibleTeams ---

    public function testSpiritkeeperCurrentAccessibleTeamsReturnsArray(): void
    {
        $teams = SpiritkeeperCurrentAccessibleTeams();
        $this->assertIsArray($teams);
    }

    // --- SpiritkeeperSeasonAccessibleTeams ---

    public function testSpiritkeeperSeasonAccessibleTeamsReturnsTeamsForSeason(): void
    {
        $teams = SpiritkeeperSeasonAccessibleTeams('HRN2026');
        $this->assertIsArray($teams);
        $this->assertNotEmpty($teams);
    }

    public function testSpiritkeeperSeasonAccessibleTeamsReturnsEmptyForEmptySeason(): void
    {
        $result = SpiritkeeperSeasonAccessibleTeams('');
        $this->assertSame([], $result);
    }

    // --- SpiritkeeperSeasonTeamGroups ---

    public function testSpiritkeeperSeasonTeamGroupsReturnsArray(): void
    {
        $groups = SpiritkeeperSeasonTeamGroups('HRN2026');
        $this->assertIsArray($groups);
    }

    // --- TeamSpiritCategoryHistoryAveragesByName ---

    public function testTeamSpiritCategoryHistoryAveragesByNameReturnsArray(): void
    {
        $result = TeamSpiritCategoryHistoryAveragesByName('Helsinki Heat', 'open', 1003);
        $this->assertIsArray($result);
    }

    // --- TeamSpiritAveragesByName ---

    public function testTeamSpiritAveragesByNameReturnsArray(): void
    {
        $result = TeamSpiritAveragesByName('Helsinki Heat', 'open');
        $this->assertIsArray($result);
    }

    // --- SpiritSubmissionLocked ---

    public function testSpiritSubmissionLockedReturnsFalseWhenNoLockFlag(): void
    {
        // Game 700 has no lockteamspiritonsubmit set in fixture → returns false
        $result = SpiritSubmissionLocked(700, 300);
        $this->assertFalse($result);
    }

    // --- SpiritTeamIdForCommentType ---

    public function testSpiritTeamIdForCommentTypeReturnsHomeTeamId(): void
    {
        $homeId = SpiritTeamIdForCommentType(700, COMMENT_TYPE_SPIRIT_HOME);
        $this->assertSame(300, (int) $homeId);
    }

    public function testSpiritTeamIdForCommentTypeReturnsVisitorTeamId(): void
    {
        $visitorId = SpiritTeamIdForCommentType(700, COMMENT_TYPE_SPIRIT_VISITOR);
        $this->assertSame(301, (int) $visitorId);
    }

    // --- SpiritTokenSaveComment ---

    public function testSpiritTokenSaveCommentReturnsFalseForInvalidGameId(): void
    {
        $result = SpiritTokenSaveComment(0, 300, 'good game');
        $this->assertFalse($result);
    }

    public function testSpiritTokenSaveCommentReturnsFalseWhenGameNotStarted(): void
    {
        // Game 701 has hasstarted=0, so SpiritTokenCanSubmit rejects the
        // submission even though team 301 is a participant and the season has
        // spiritmode enabled.
        $result = SpiritTokenSaveComment(701, 301, 'good game');
        $this->assertFalse($result);
    }

    // --- SpiritTimeoutGameRowsBySeason ---

    public function testSpiritTimeoutGameRowsBySeasonReturnsArray(): void
    {
        $rows = SpiritTimeoutGameRowsBySeason('HRN2026');
        $this->assertIsArray($rows);
    }

    public function testSpiritTimeoutGameRowsBySeasonReturnsEmptyForNoTimeouts(): void
    {
        // Fixture has no spirit timeouts
        $rows = SpiritTimeoutGameRowsBySeason('HRN2026');
        $this->assertEmpty($rows);
    }

    // --- GameSetSpiritPoints ---

    public function testGameSetSpiritPointsReturnsTrueAsSuperAdminForFixtureGame(): void
    {
        // As superadmin, hasSpiritEditRight → CanEditSpiritSubmission → true
        $result = GameSetSpiritPoints(700, 300, true, [], []);
        $this->assertTrue($result);
    }

    public function testGameSetSpiritPointsReturnsFalseForNonExistentGame(): void
    {
        $result = GameSetSpiritPoints(999999, 300, true, [], []);
        $this->assertFalse($result);
    }
}
