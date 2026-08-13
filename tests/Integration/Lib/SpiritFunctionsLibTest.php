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

    // DBQueryToValue/Array/Row/RowCount persistently cache by query string; a
    // DBQuery UPDATE followed by a re-read through SpiritGameRow()/DBQueryToRow()
    // needs these flushed or the reread returns the pre-write value.
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
        // Fixture never sets these, so they carry their table defaults (0);
        // pins that the SELECT actually returns the two new columns.
        $this->assertEquals(0, $row['forfeit']);
        $this->assertEquals(0, $row['showspiritcommentstoteams']);
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
        // TeamSpiritTotal()'s default (includeIncomplete=false) query gates on
        // g.show_spirit=1, same as TeamSpiritTotalByPool (see
        // testSpiritTotalByPoolIsGatedOnShowSpirit). Fixture game 700 has
        // show_spirit=0, so a submission alone leaves the total null until the
        // game is marked visible.
        $this->submitHomeSpirit();
        $this->assertNull(TeamSpiritTotal(300)['total']);

        DBQuery("UPDATE uo_game SET show_spirit=1 WHERE game_id=700");
        // 5 categories submitted (4+3+4+4+3), each with factor=1 for mode 1003.
        $this->assertEquals(18, TeamSpiritTotal(300)['total']);
    }

    public function testCountSpiritStatsReturnsZeroBeforeSubmit(): void
    {
        $row = CountSpiritStats(300);
        $this->assertSame('0', (string) $row['games']);
    }

    public function testSpiritTotalByPoolIsGatedOnShowSpirit(): void
    {
        // TeamSpiritTotalByPool only sums games with show_spirit=1. The fixture game 700
        // has show_spirit=0, so even after a submission the pool total stays 0; flipping
        // show_spirit=1 surfaces the submitted scores. This pins the visibility gate that
        // a plain assertIsArray would silently pass regardless of.
        $this->submitHomeSpirit();

        $hidden = TeamSpiritTotalByPool(200, 300);
        $this->assertEquals(0, $hidden['spirit']);

        DBQuery("UPDATE uo_game SET show_spirit=1 WHERE game_id=700");
        $visible = TeamSpiritTotalByPool(200, 300);
        $this->assertGreaterThan(0, (int) $visible['spirit']);
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
        // No uo_spirit_score rows and no fixture game has show_spirit=1 → no scores
        // to aggregate, so the per-team loop never runs.
        $this->assertSame([], SeriesSpiritBoard(100));
    }

    public function testSeriesSpiritBoardAlt2ReturnsArrayForSeries100(): void
    {
        $this->assertSame([], SeriesSpiritBoardAlt2(100));
    }

    public function testSeriesSpiritBoardTotalAveragesReturnsArray(): void
    {
        // Unlike SeriesSpiritBoard(), this always seeds $ret with a 'total' key
        // (0.0, forced to null when zero rows match) — never a bare [].
        $this->assertSame(['total' => null], SeriesSpiritBoardTotalAverages(100));
    }

    // --- SpiritMissingGames* ---

    public function testSpiritMissingGamesByPoolReturnsArray(): void
    {
        // Game 700 is played (homescore+visitorscore>0) and isongoing=0, so it passes
        // the base filter; with no spirit_score rows submitted by either team, both
        // TeamSpiritSubmissionComplete() checks are false, so it's reported "missing".
        // Game 701 is unplayed (no scores) and is excluded by the base filter.
        $result = SpiritMissingGamesByPool(200);
        $this->assertCount(1, $result);
        $this->assertSame(700, $result[0]['game_id']);
        $this->assertSame('Helsinki Heat', $result[0]['home']);
        $this->assertSame('Tampere Tempest', $result[0]['visitor']);
        $this->assertNull($result[0]['homesotg']);
        $this->assertNull($result[0]['visitorsotg']);
    }

    public function testSpiritMissingGamesBySeriesReturnsArray(): void
    {
        // Same underlying game (700) as SpiritMissingGamesByPool, joined via series
        // instead of pool, and with an added poolname field.
        $result = SpiritMissingGamesBySeries(100);
        $this->assertCount(1, $result);
        $this->assertSame(700, $result[0]['game_id']);
        $this->assertSame('Pool A', $result[0]['poolname']);
    }

    public function testSpiritMissingGamesByPoolExcludesForfeitedGame(): void
    {
        // testSpiritMissingGamesByPoolReturnsArray above is the allow precondition:
        // game 700 is reported missing when not forfeited. The new g.forfeit=0
        // filter must drop it once forfeited.
        DBQuery("UPDATE uo_game SET forfeit=1 WHERE game_id=700");
        self::flushQueryCaches();
        try {
            $this->assertSame([], SpiritMissingGamesByPool(200));
        } finally {
            DBQuery("UPDATE uo_game SET forfeit=0 WHERE game_id=700");
            self::flushQueryCaches();
        }
    }

    public function testSpiritMissingGamesBySeriesExcludesForfeitedGame(): void
    {
        DBQuery("UPDATE uo_game SET forfeit=1 WHERE game_id=700");
        self::flushQueryCaches();
        try {
            $this->assertSame([], SpiritMissingGamesBySeries(100));
        } finally {
            DBQuery("UPDATE uo_game SET forfeit=0 WHERE game_id=700");
            self::flushQueryCaches();
        }
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
        $this->assertSame('Time TBD', SpiritkeeperGameTimeLabel(['time' => '']));
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
        $this->assertSame('?view=teamgames&season=HRN2026&team=300', $url);
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
        // The fixture season has showspiritpoints=0, so spirit is not visible → 0.
        $result = GameSpiritVisibilityValue(700);
        $this->assertSame(0, $result);
    }

    public function testGameSpiritVisibilityValueReturnsZeroWhenGameForfeited(): void
    {
        // Passing $game directly bypasses SpiritGameRow()'s DB read and
        // showspiritpointsonlyoncomplete=0 skips the GameSpiritComplete() DB
        // check, isolating the new forfeit short-circuit as a pure function.
        $game = [
            'spiritmode' => 1003,
            'showspiritpoints' => 1,
            'showspiritpointsonlyoncomplete' => 0,
            'forfeit' => 0,
        ];
        // Allow precondition: without forfeit, spirit is visible immediately.
        $this->assertSame(1, GameSpiritVisibilityValue(700, $game));

        $game['forfeit'] = 1;
        $this->assertSame(0, GameSpiritVisibilityValue(700, $game));
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
        // SpiritToolRowsBySeason() is empty (no uo_spirit_score rows), and
        // ArrayToCsv() returns '' for an empty row set.
        $this->assertSame('', SpiritToCsv('HRN2026', ','));
    }

    // --- TeamSpiritStats / TeamSpiritStats2 ---

    public function testTeamSpiritStatsReturnsArray(): void
    {
        // No spirit_score rows → tscore.total is always NULL/0, so the
        // "COALESCE(tscore.total,0)>0" clause never matches; COUNT(*) still
        // returns exactly one row with games=0.
        $result = TeamSpiritStats(300);
        $this->assertEquals(0, $result['games']);
    }

    public function testTeamSpiritStats2ReturnsArray(): void
    {
        // Default includeIncomplete=false gates on show_spirit=1; fixture game 700
        // has show_spirit=0, so games=0.
        $result = TeamSpiritStats2(300);
        $this->assertEquals(0, $result['games']);
    }

    // --- SpiritSeriesMissingPointRows ---

    public function testSpiritSeriesMissingPointRowsReturnsArray(): void
    {
        // Game 700 (hasstarted>0, isongoing=0) qualifies; game 701 (hasstarted=0)
        // doesn't. Neither team has submitted, so both get a "missing" row, sorted
        // by teamname.
        $result = SpiritSeriesMissingPointRows(100);
        $this->assertCount(2, $result);
        $this->assertSame(300, $result[0]['team_id']);
        $this->assertSame('Helsinki Heat', $result[0]['teamname']);
        $this->assertSame(301, $result[0]['giver_team_id']);
        $this->assertSame(301, $result[1]['team_id']);
        $this->assertSame('Tampere Tempest', $result[1]['teamname']);
    }

    public function testSpiritSeriesMissingPointRowsExcludesForfeitedGame(): void
    {
        // testSpiritSeriesMissingPointRowsReturnsArray above proves game 700
        // produces 2 rows when not forfeited; the new g.forfeit=0 filter must
        // drop both once the game is forfeited.
        DBQuery("UPDATE uo_game SET forfeit=1 WHERE game_id=700");
        self::flushQueryCaches();
        try {
            $this->assertSame([], SpiritSeriesMissingPointRows(100));
        } finally {
            DBQuery("UPDATE uo_game SET forfeit=0 WHERE game_id=700");
            self::flushQueryCaches();
        }
    }

    // --- SpiritSeriesScoreRows ---

    public function testSpiritSeriesScoreRowsReturnsArray(): void
    {
        // Gates on show_spirit=1; fixture game 700 has show_spirit=0 and there are
        // no uo_spirit_score rows anyway.
        $this->assertSame([], SpiritSeriesScoreRows(100));
    }

    // --- SpiritTeamPointRows ---

    public function testSpiritTeamPointRowsReturnsArray(): void
    {
        // Only game 700 qualifies (played, involves team 300); no submission yet,
        // so total is null.
        $result = SpiritTeamPointRows('HRN2026', 300);
        $this->assertCount(1, $result);
        $this->assertSame(700, $result[0]['game_id']);
        $this->assertNull($result[0]['total']);
    }

    // --- SpiritToolRowsBySeason ---

    public function testSpiritToolRowsBySeasonReturnsArray(): void
    {
        // Reads uo_spirit_score, which is empty in the fixture.
        $this->assertSame([], SpiritToolRowsBySeason('HRN2026'));
    }

    public function testSpiritToolRowsBySeasonExcludesForfeitedGame(): void
    {
        // SpiritToolRowsBySeason() briefly took an $includeForfeits parameter;
        // that was reverted in favor of always excluding forfeits (the caller,
        // SpiritToCsv(), no longer passes a second argument either).
        $this->submitHomeSpirit();

        // Allow precondition: the submitted score is included while not forfeited.
        $this->assertNotEmpty(SpiritToolRowsBySeason('HRN2026'));

        DBQuery("UPDATE uo_game SET forfeit=1 WHERE game_id=700");
        self::flushQueryCaches();
        try {
            $this->assertSame([], SpiritToolRowsBySeason('HRN2026'));
        } finally {
            DBQuery("UPDATE uo_game SET forfeit=0 WHERE game_id=700");
            self::flushQueryCaches();
        }
    }

    // --- SpiritTimeoutSummaryBySeason ---

    public function testSpiritTimeoutSummaryBySeasonReturnsArray(): void
    {
        // Bare aggregate (no GROUP BY) always returns one row; fixture has no
        // uo_spirit_timeout rows, so every count is 0.
        $result = SpiritTimeoutSummaryBySeason('HRN2026');
        $this->assertEquals(0, $result['total']);
        $this->assertEquals(0, $result['games']);
        $this->assertEquals(0, $result['home_total']);
        $this->assertEquals(0, $result['away_total']);
    }

    // --- SpiritSotgUrlsBySeason ---

    public function testSpiritSotgUrlsBySeasonReturnsArray(): void
    {
        // Both fixture teams, ordered by series name then team name; neither has
        // a sotg_token set.
        $result = SpiritSotgUrlsBySeason('HRN2026');
        $this->assertCount(2, $result);
        $this->assertSame('Helsinki Heat', $result[0]['team']);
        $this->assertNull($result[0]['token']);
        $this->assertSame('Tampere Tempest', $result[1]['team']);
    }

    // --- SpiritkeeperAccessibleTeams ---

    public function testSpiritkeeperAccessibleTeamsReturnsArray(): void
    {
        // setUp() grants seasonadmin for HRN2026, which has spiritmode=1003 → both
        // fixture teams are accessible, ordered by season/series/name.
        $teams = SpiritkeeperAccessibleTeams();
        $this->assertCount(2, $teams);
        $this->assertSame('Helsinki Heat', $teams[0]['name']);
        $this->assertSame('Tampere Tempest', $teams[1]['name']);
    }

    // --- SpiritkeeperCurrentSeasons ---

    public function testSpiritkeeperCurrentSeasonsReturnsArray(): void
    {
        // Fixture's only season (HRN2026) has iscurrent=1.
        $seasons = SpiritkeeperCurrentSeasons();
        $this->assertSame(['HRN2026'], array_keys($seasons));
    }

    // --- SpiritCategoryFactors ---

    public function testSpiritCategoryFactorsReturnsArray(): void
    {
        // Reads ALL uo_spirit_category rows (no mode filter) — base schema seeds 25
        // rows across modes 1001-1004. Mode 1003 (fixture's spiritmode) categories
        // all have factor=1; mode 1004's "ours" categories (even index) have factor=0.
        $factors = SpiritCategoryFactors();
        $this->assertCount(25, $factors);
        $this->assertEquals(1, $factors[1009]);
        $this->assertEquals(0, $factors[1016]);
    }

    // --- TeamSpiritPointsReceived / TeamSpiritPointsGiven ---

    public function testTeamSpiritPointsReceivedReturnsArray(): void
    {
        // uo_spirit_score.team_id is the team being rated; submitHomeSpirit() inserts
        // rows keyed to team 300, so "received" (ratedTeamId=teamId=300) sees them:
        // total = 4+3+4+4+3 = 18 (factor=1 for every mode-1003 category).
        $this->submitHomeSpirit();
        $result = TeamSpiritPointsReceived('HRN2026', 300);
        $this->assertCount(1, $result);
        $this->assertSame(700, $result[0]['game_id']);
        $this->assertEquals(18, $result[0]['total']);
    }

    public function testTeamSpiritPointsGivenReturnsArray(): void
    {
        // "Given" rates the opponent (301), but submitHomeSpirit() only inserted
        // rows for team 300, so team 301's points are still unset → total is null.
        $this->submitHomeSpirit();
        $result = TeamSpiritPointsGiven('HRN2026', 300);
        $this->assertCount(1, $result);
        $this->assertNull($result[0]['total']);
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
        // type=5 is COMMENT_TYPE_SPIRIT_HOME → game 700's hometeam (300).
        $this->assertSame(300, SpiritTeamIdForCommentType(700, 5));
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
        // Game 700 has started, spiritmode set, event not readonly, and the rated
        // opponent (301) hasn't completed a submission yet to trip the lock.
        $this->assertTrue(SpiritTokenCanSubmit(700, 300));
    }

    public function testSpiritTokenCanSubmitReturnsFalseWhenGameForfeited(): void
    {
        // Passing $game directly bypasses SpiritTokenGame()'s DB read, so this
        // isolates the forfeit short-circuit as a pure function of the game row.
        $game = [
            'spiritmode' => 1003,
            'event_readonly' => 0,
            'forfeit' => 0,
            'hometeam' => 300,
            'visitorteam' => 301,
            'hasstarted' => 1,
            'lockteamspiritonsubmit' => 0,
        ];
        // Allow precondition: without forfeit, this exact game row is submittable.
        $this->assertTrue(SpiritTokenCanSubmit(700, 300, $game));

        $game['forfeit'] = 1;
        $this->assertFalse(SpiritTokenCanSubmit(700, 300, $game));
    }

    public function testSpiritTokenCanViewReceivedPointsReturnsBoolForValidGame(): void
    {
        // Neither team has submitted anything yet, so team 300 has no "own
        // submission" (of the opponent) to unlock viewing received points.
        $this->assertFalse(SpiritTokenCanViewReceivedPoints(700, 300));
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
        // Reads uo_team_spirit_stats, only populated by CalcTeamSpiritStats()
        // (archival rebuild), which this test never runs.
        $this->assertSame([], TeamSpiritCategoryStats(300, 'HRN2026', 1003));
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

    public function testSpiritToCsvExcludesForfeitedGame(): void
    {
        // SpiritToCsv() calls SpiritToolRowsBySeason(), which always excludes
        // forfeits, so a forfeited game's submitted score must not appear in
        // the export even though testSpiritToCsvWithScoresContainsData above
        // proves the same submission is included when not forfeited.
        $this->submitHomeSpirit();
        DBQuery("UPDATE uo_game SET forfeit=1 WHERE game_id=700");
        self::flushQueryCaches();
        try {
            $this->assertSame('', SpiritToCsv('HRN2026', ','));
        } finally {
            DBQuery("UPDATE uo_game SET forfeit=0 WHERE game_id=700");
            self::flushQueryCaches();
        }
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

        // tokenTeamId=300 rates the opponent (301, ratedTeamId); points are valid
        // (all 5 categories, values within 0-4), so this succeeds and creates a new
        // spirit comment (ApplyCommentChange's "create" branch always returns true).
        $result = SpiritTokenSaveSubmissionWithComment(700, 300, $points, $categories, 'Good game!');
        $this->assertTrue($result);
        $this->assertSame(5, (int) DBQueryToValue(
            "SELECT COUNT(*) FROM uo_spirit_score WHERE game_id=700 AND team_id=301"
        ));
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
        // Only home team submits → game 700 is still "missing" because both teams
        // must be complete to be excluded; home's 5-category submission now gives a
        // real homesotg (4+3+4+4+3=18), while visitor's stays null (nothing submitted).
        $this->submitHomeSpirit();
        $result = SpiritMissingGamesByPool(200);
        $this->assertCount(1, $result);
        $this->assertSame(700, $result[0]['game_id']);
        $this->assertEquals(18, $result[0]['homesotg']);
        $this->assertNull($result[0]['visitorsotg']);
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
        // HRN2026 is the current season, so this matches SpiritkeeperAccessibleTeams().
        $teams = SpiritkeeperCurrentAccessibleTeams();
        $this->assertCount(2, $teams);
        $this->assertSame('Helsinki Heat', $teams[0]['name']);
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
        // Single series ('Open') containing both accessible teams.
        $groups = SpiritkeeperSeasonTeamGroups('HRN2026');
        $this->assertCount(1, $groups);
        $this->assertSame('Open', $groups[0]['seriesname']);
        $this->assertCount(2, $groups[0]['teams']);
    }

    // --- TeamSpiritCategoryHistoryAveragesByName ---

    public function testTeamSpiritCategoryHistoryAveragesByNameReturnsArray(): void
    {
        // Same uo_team_spirit_stats dependency as TeamSpiritCategoryStats — empty
        // unless CalcTeamSpiritStats() has been run.
        $this->assertSame([], TeamSpiritCategoryHistoryAveragesByName('Helsinki Heat', 'open', 1003));
    }

    // --- TeamSpiritAveragesByName ---

    public function testTeamSpiritAveragesByNameReturnsArray(): void
    {
        $this->assertSame([], TeamSpiritAveragesByName('Helsinki Heat', 'open'));
    }

    // --- SpiritSubmissionLocked ---

    public function testSpiritSubmissionLockedReturnsFalseWhenNoLockFlag(): void
    {
        // Game 700 has no lockteamspiritonsubmit set in fixture → returns false
        $result = SpiritSubmissionLocked(700, 300);
        $this->assertFalse($result);
    }

    public function testSpiritSubmissionLockedReturnsFalseWhenLockFlagSetButNoSubmission(): void
    {
        // lockteamspiritonsubmit is on uo_season (joined into SpiritGameRow via LEFT JOIN).
        // Set it to 1 → TeamSpiritSubmissionComplete called; no scores → false.
        // Covers line 874 (return TeamSpiritSubmissionComplete path).
        DBQuery("UPDATE uo_season SET lockteamspiritonsubmit=1 WHERE season_id='HRN2026'");
        try {
            $result = SpiritSubmissionLocked(700, 300);
            $this->assertFalse($result);
        } finally {
            DBQuery("UPDATE uo_season SET lockteamspiritonsubmit=0 WHERE season_id='HRN2026'");
        }
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
        // GROUP BY query over uo_spirit_timeout, which the fixture never seeds.
        $this->assertSame([], SpiritTimeoutGameRowsBySeason('HRN2026'));
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

    // --- HasFullGameSpiritEditRight / SpiritEntryTeamForUser non-admin paths ---

    public function testHasFullGameSpiritEditRightViaSeriesAdmin(): void
    {
        // Non-superadmin with seriesadmin:100 covers the tail of
        // HasFullGameSpiritEditRight (hasSpiritEditRight=false,
        // isEventReadonly=false, then the GameSeries check).
        LegacyApp::requireTopLevelLib('game.functions.php');
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        unset($_SESSION['userproperties']['userrole']['seasonadmin']);
        $_SESSION['userproperties']['userrole']['seriesadmin'][100] = 1;
        try {
            $result = HasFullGameSpiritEditRight(700);
            $this->assertTrue($result);
        } finally {
            unset($_SESSION['userproperties']['userrole']['seriesadmin']);
            $_SESSION['userproperties']['userrole']['superadmin'] = true;
            $_SESSION['userproperties']['userrole']['seasonadmin']['HRN2026'] = 1;
        }
    }

    public function testHasFullGameSpiritEditRightDeniedForGameAdmin(): void
    {
        // gameadmin[700] staffs the scoring desk and no longer carries spirit
        // rights: HasFullGameSpiritEditRight only accepts spirit tools rights
        // or seriesadmin.
        LegacyApp::requireTopLevelLib('game.functions.php');
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        unset($_SESSION['userproperties']['userrole']['seasonadmin']);
        $_SESSION['userproperties']['userrole']['gameadmin'][700] = 1;
        try {
            $result = HasFullGameSpiritEditRight(700);
            $this->assertFalse($result);
        } finally {
            unset($_SESSION['userproperties']['userrole']['gameadmin']);
            $_SESSION['userproperties']['userrole']['superadmin'] = true;
            $_SESSION['userproperties']['userrole']['seasonadmin']['HRN2026'] = 1;
        }
    }

    public function testHasFullGameSpiritEditRightDeniedForReservationGameAdmin(): void
    {
        // resgameadmin[500] owns game 700's reservation, which likewise no
        // longer grants spirit rights.
        LegacyApp::requireTopLevelLib('game.functions.php');
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        unset($_SESSION['userproperties']['userrole']['seasonadmin']);
        $_SESSION['userproperties']['userrole']['resgameadmin'][500] = 1;
        try {
            $result = HasFullGameSpiritEditRight(700);
            $this->assertFalse($result);
        } finally {
            unset($_SESSION['userproperties']['userrole']['resgameadmin']);
            $_SESSION['userproperties']['userrole']['superadmin'] = true;
            $_SESSION['userproperties']['userrole']['seasonadmin']['HRN2026'] = 1;
        }
    }

    public function testSpiritEntryTeamForUserReturnsTeamIdForTeamAdmin(): void
    {
        // teamadmin:300 (home) — HasFullGameSpiritViewRight returns false, then
        // hasEditPlayersRight(300)=true, hasEditPlayersRight(301)=false → returns 300.
        LegacyApp::requireTopLevelLib('game.functions.php');
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        unset($_SESSION['userproperties']['userrole']['seasonadmin']);
        $_SESSION['userproperties']['userrole']['teamadmin'][300] = 1;
        try {
            $result = SpiritEntryTeamForUser(700);
            $this->assertSame(300, $result);
        } finally {
            unset($_SESSION['userproperties']['userrole']['teamadmin']);
            $_SESSION['userproperties']['userrole']['superadmin'] = true;
            $_SESSION['userproperties']['userrole']['seasonadmin']['HRN2026'] = 1;
        }
    }

    public function testSpiritEntryTeamForUserReturnsBothTeamsAdmin(): void
    {
        // teamadmin:300 AND teamadmin:301 → both added → count=2 → returns 0.
        LegacyApp::requireTopLevelLib('game.functions.php');
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        unset($_SESSION['userproperties']['userrole']['seasonadmin']);
        $_SESSION['userproperties']['userrole']['teamadmin'][300] = 1;
        $_SESSION['userproperties']['userrole']['teamadmin'][301] = 1;
        try {
            $result = SpiritEntryTeamForUser(700);
            $this->assertSame(0, $result);
        } finally {
            unset($_SESSION['userproperties']['userrole']['teamadmin']);
            $_SESSION['userproperties']['userrole']['superadmin'] = true;
            $_SESSION['userproperties']['userrole']['seasonadmin']['HRN2026'] = 1;
        }
    }

    public function testCanEditSpiritSubmissionViaOpposingTeamAdmin(): void
    {
        // teamadmin:301 (visitor) allows editing spirit for home team (300) scoring for 301.
        // Covers lines 913-929: $homeTeam=300, $visitorTeam=301, teamId=300=homeTeam
        // → responsibleTeamId=301 → hasEditPlayersRight(301)=true → return true.
        LegacyApp::requireTopLevelLib('game.functions.php');
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        unset($_SESSION['userproperties']['userrole']['seasonadmin']);
        $_SESSION['userproperties']['userrole']['teamadmin'][301] = 1;
        try {
            $result = CanEditSpiritSubmission(700, 300);
            $this->assertTrue($result);
        } finally {
            unset($_SESSION['userproperties']['userrole']['teamadmin']);
            $_SESSION['userproperties']['userrole']['superadmin'] = true;
            $_SESSION['userproperties']['userrole']['seasonadmin']['HRN2026'] = 1;
        }
    }

    public function testCanEditSpiritSubmissionReturnsFalseWhenGameForfeited(): void
    {
        // Same grant as testCanEditSpiritSubmissionViaOpposingTeamAdmin (teamadmin:301
        // allows team 300 to edit) so the only variable is the forfeit flag added at
        // lib/spirit.functions.php:917-919 (checked after HasFullGameSpiritEditRight,
        // before the hasEditPlayersRight branch this permission grant exercises).
        LegacyApp::requireTopLevelLib('game.functions.php');
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        unset($_SESSION['userproperties']['userrole']['seasonadmin']);
        $_SESSION['userproperties']['userrole']['teamadmin'][301] = 1;
        try {
            // Allow precondition: proves the permission grant works before forfeit,
            // so the deny below is attributable to the forfeit flag, not the setup.
            $this->assertTrue(CanEditSpiritSubmission(700, 300));

            DBQuery("UPDATE uo_game SET forfeit=1 WHERE game_id=700");
            self::flushQueryCaches();
            $this->assertFalse(CanEditSpiritSubmission(700, 300));
        } finally {
            DBQuery("UPDATE uo_game SET forfeit=0 WHERE game_id=700");
            self::flushQueryCaches();
            unset($_SESSION['userproperties']['userrole']['teamadmin']);
            $_SESSION['userproperties']['userrole']['superadmin'] = true;
            $_SESSION['userproperties']['userrole']['seasonadmin']['HRN2026'] = 1;
        }
    }

    public function testCanViewSpiritScoresForGameWithNonAdmin(): void
    {
        // Non-admin user: hasSpiritToolsRight=false → covers line 1025-1026
        // (showspiritpoints=0 in fixture → return false).
        LegacyApp::requireTopLevelLib('game.functions.php');
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        unset($_SESSION['userproperties']['userrole']['seasonadmin']);
        try {
            $result = CanViewSpiritScoresForGame(700);
            $this->assertFalse($result);
        } finally {
            $_SESSION['userproperties']['userrole']['superadmin'] = true;
            $_SESSION['userproperties']['userrole']['seasonadmin']['HRN2026'] = 1;
        }
    }

    public function testCanViewSpiritScoresForGameWithShowSpiritEnabled(): void
    {
        // Set showspiritpoints=1 on season and show_spirit=1 on game → covers lines 1028-1032.
        LegacyApp::requireTopLevelLib('game.functions.php');
        DBQuery("UPDATE uo_season SET showspiritpoints=1 WHERE season_id='HRN2026'");
        DBQuery("UPDATE uo_game SET show_spirit=1 WHERE game_id=700");
        CacheForgetNamespace('season_info');
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        unset($_SESSION['userproperties']['userrole']['seasonadmin']);
        try {
            $result = CanViewSpiritScoresForGame(700);
            $this->assertTrue($result);
        } finally {
            DBQuery("UPDATE uo_season SET showspiritpoints=0 WHERE season_id='HRN2026'");
            DBQuery("UPDATE uo_game SET show_spirit=0 WHERE game_id=700");
            CacheForgetNamespace('season_info');
            $_SESSION['userproperties']['userrole']['superadmin'] = true;
            $_SESSION['userproperties']['userrole']['seasonadmin']['HRN2026'] = 1;
        }
    }

    public function testSpiritEntryUrlIncludesTeamParamWhenSingleTeamAdmin(): void
    {
        // teamadmin:300 only → SpiritEntryTeamForUser returns 300 → teamId > 0
        // → SpiritEntryUrl appends &team=300 (covers line 276).
        LegacyApp::requireTopLevelLib('game.functions.php');
        unset($_SESSION['userproperties']['userrole']['superadmin']);
        unset($_SESSION['userproperties']['userrole']['seasonadmin']);
        $_SESSION['userproperties']['userrole']['teamadmin'][300] = 1;
        try {
            $url = SpiritEntryUrl(700);
            $this->assertStringContainsString('&team=300', $url);
        } finally {
            unset($_SESSION['userproperties']['userrole']['teamadmin']);
            $_SESSION['userproperties']['userrole']['superadmin'] = true;
            $_SESSION['userproperties']['userrole']['seasonadmin']['HRN2026'] = 1;
        }
    }

    public function testCalcTeamSpiritStatsReturnsEarlyForNoSpiritMode(): void
    {
        // INSERT a temp season with spiritmode=0 → CalcTeamSpiritStats hits the
        // empty spiritmode early return (lines 2153-2154).
        DBQuery("INSERT INTO uo_season (season_id, name, spiritmode) VALUES ('SPIRITTEST', 'SpiritTest', 0)");
        try {
            CalcTeamSpiritStats('SPIRITTEST');
            $this->assertTrue(true);
        } finally {
            DBQuery("DELETE FROM uo_season WHERE season_id='SPIRITTEST'");
            CacheForgetNamespace('season_info');
        }
    }
}
