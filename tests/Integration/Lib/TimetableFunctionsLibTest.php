<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

// U_() translates strings — identity shim for test context.
if (!function_exists('U_')) {
    function U_(mixed $name): mixed
    {
        return $name;
    }
}
// utf8entities() lives in localization.php (SUT root, never loaded in integration tests).
if (!function_exists('utf8entities')) {
    function utf8entities(mixed $s): string
    {
        return htmlentities((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

final class TimetableFunctionsLibTest extends TestCase
{
    // Fixture: season='HRN2026', series=100, pool=200, teams 300+301,
    // reservations 500+501, location 400, games 700+701.
    // game 700: hometeam=300, visitorteam=301, homescore=15, visitorscore=11,
    //           reservation=500, hasstarted=1, islive=0.
    // game 701: hometeam=301, visitorteam=300, no scores, reservation=501, hasstarted=0.

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(['timetable.functions.php'], 'database_only');

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['uid'] = 'testuser';
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
        DBQuery("DELETE FROM uo_movingtime WHERE season='HRN2026'");
        unset($_SESSION['userproperties'], $_SESSION['uid']);
        LegacyApp::closeDatabaseConnection();
    }

    // --- CollectGameIdsFromResult ---

    public function testCollectGameIdsFromResultReturnsEmptyForFalseInput(): void
    {
        $this->assertSame([], CollectGameIdsFromResult(false));
    }

    public function testCollectGameIdsFromResultReturnsEmptyForEmptyArray(): void
    {
        $this->assertSame([], CollectGameIdsFromResult([]));
    }

    public function testCollectGameIdsFromResultReturnsGameIds(): void
    {
        $games = [
            ['game_id' => 700],
            ['game_id' => 701],
            ['no_game_id' => 999],
        ];
        $this->assertSame([700, 701], CollectGameIdsFromResult($games));
    }

    // --- TimetablePoolIdListSql ---

    public function testTimetablePoolIdListSqlReturnsNullForEmptyString(): void
    {
        $this->assertSame('NULL', TimetablePoolIdListSql(''));
    }

    public function testTimetablePoolIdListSqlReturnsSingleId(): void
    {
        $this->assertSame('200', TimetablePoolIdListSql('200'));
    }

    public function testTimetablePoolIdListSqlReturnsCommaSeparatedIds(): void
    {
        $result = TimetablePoolIdListSql('200,201');
        $this->assertStringContainsString('200', $result);
        $this->assertStringContainsString('201', $result);
    }

    public function testTimetablePoolIdListSqlFiltersNonPositiveIds(): void
    {
        $this->assertSame('NULL', TimetablePoolIdListSql('0,-1'));
    }

    public function testTimetablePoolIdListSqlDeduplicatesIds(): void
    {
        $this->assertSame('200', TimetablePoolIdListSql('200,200'));
    }

    // --- TimetableMediaIconType ---

    public function testTimetableMediaIconTypeReturnsLiveForEmpty(): void
    {
        $this->assertSame('live', TimetableMediaIconType(''));
    }

    public function testTimetableMediaIconTypeReturnsTypeForValidInput(): void
    {
        $this->assertSame('youtube', TimetableMediaIconType('youtube'));
    }

    public function testTimetableMediaIconTypePreservesHyphensAndUnderscores(): void
    {
        $this->assertSame('live-stream', TimetableMediaIconType('live-stream'));
        $this->assertSame('live_stream', TimetableMediaIconType('live_stream'));
    }

    public function testTimetableMediaIconTypeStripsSpecialChars(): void
    {
        $this->assertSame('type123', TimetableMediaIconType('type<>123'));
    }

    // --- TimetableGames ---

    public function testTimetableGamesReturnsAllGamesForSeasonAllFilter(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'time');
        $this->assertIsArray($games);
        $ids = array_column($games, 'game_id');
        $this->assertContains('700', $ids);
        $this->assertContains('701', $ids);
    }

    public function testTimetableGamesReturnsPastGamesForSeasonFilter(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'past', 'time');
        $this->assertIsArray($games);
        $ids = array_column($games, 'game_id');
        $this->assertContains('700', $ids);
    }

    public function testTimetableGamesReturnsComingGamesForSeasonFilter(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'coming', 'time');
        $this->assertIsArray($games);
        $ids = array_column($games, 'game_id');
        $this->assertContains('701', $ids);
    }

    public function testTimetableGamesReturnsGamesForPoolFilter(): void
    {
        $games = TimetableGames(200, 'pool', 'all', 'time');
        $this->assertIsArray($games);
        $this->assertNotEmpty($games);
        $ids = array_column($games, 'game_id');
        $this->assertContains('700', $ids);
    }

    public function testTimetableGamesReturnsGamesForSeriesFilter(): void
    {
        $games = TimetableGames(100, 'series', 'all', 'time');
        $this->assertIsArray($games);
        $this->assertNotEmpty($games);
    }

    public function testTimetableGamesReturnsGameForTeamFilter(): void
    {
        $games = TimetableGames(300, 'team', 'all', 'time');
        $this->assertIsArray($games);
        $this->assertNotEmpty($games);
    }

    public function testTimetableGamesReturnsGameForGameFilter(): void
    {
        $games = TimetableGames(700, 'game', 'all', 'time');
        $this->assertIsArray($games);
        $this->assertCount(1, $games);
        $this->assertSame('700', $games[0]['game_id']);
    }

    public function testTimetableGamesReturnsGamesForPoolgroupFilter(): void
    {
        $games = TimetableGames('200,201', 'poolgroup', 'all', 'time');
        $this->assertIsArray($games);
        $this->assertNotEmpty($games);
    }

    public function testTimetableGamesReturnsEmptyForUnknownSeason(): void
    {
        $games = TimetableGames('NOSUCHSEASON', 'season', 'all', 'time');
        $this->assertIsArray($games);
        $this->assertEmpty($games);
    }

    public function testTimetableGamesWithPlayedTimeFilter(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'played', 'time');
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithOngoingTimeFilter(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'ongoing', 'time');
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithComingNotTodayFilter(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'comingNotToday', 'time');
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithPastNotTodayFilter(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'pastNotToday', 'time');
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithTodayFilter(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'today', 'time');
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithTomorrowFilter(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'tomorrow', 'time');
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithYesterdayFilter(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'yesterday', 'time');
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithComingTodayAndTomorrowFilter(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'comingTodayAndTomorrow', 'time');
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithSpecificDateFilter(): void
    {
        $games = TimetableGames('HRN2026', 'season', '2026-06-01', 'time');
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithTournamentsOrder(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'tournaments');
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithSeriesOrder(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'series');
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithPlacesOrder(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'places');
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithTournamentsDescOrder(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'tournamentsdesc');
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithPlacesDescOrder(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'placesdesc');
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithOnepageOrder(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'onepage');
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithTimeDescOrder(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'timedesc');
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithCrossmatchOrder(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'crossmatch');
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithGroupfilter(): void
    {
        // groupfilter != "all" appends AND pr.reservationgroup clause.
        $groups = TimetableGrouping('HRN2026', 'season', 'all');
        $group = !empty($groups) ? $groups[0]['reservationgroup'] : 'Harness Invitational 2026';
        $games = TimetableGames('HRN2026', 'season', 'all', 'time', $group);
        $this->assertIsArray($games);
    }

    public function testTimetableGamesWithOnlyPublicFlag(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'time', '', true);
        $this->assertIsArray($games);
    }

    // --- View functions ---

    public function testTournamentViewReturnsHtmlForAllGames(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'time');
        $html = TournamentView($games);
        $this->assertIsString($html);
        $this->assertStringContainsString('<table', $html);
    }

    public function testTournamentViewReturnsStringForNoGames(): void
    {
        $html = TournamentView([]);
        $this->assertIsString($html);
        $this->assertStringNotContainsString('Helsinki Heat', $html);
    }

    public function testTournamentViewWithGroupingFalse(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'time');
        $html = TournamentView($games, false);
        $this->assertIsString($html);
    }

    public function testSeriesViewReturnsHtmlForAllGames(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'time');
        $html = SeriesView($games);
        $this->assertIsString($html);
        $this->assertStringContainsString('<table', $html);
    }

    public function testSeriesViewWithDateAndTime(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'time');
        $html = SeriesView($games, true, true);
        $this->assertIsString($html);
    }

    public function testPlaceViewReturnsHtmlForAllGames(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'time');
        $html = PlaceView($games);
        $this->assertIsString($html);
        $this->assertStringContainsString('<table', $html);
    }

    public function testTimeViewReturnsHtmlForAllGames(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'time');
        $html = TimeView($games);
        $this->assertIsString($html);
    }

    public function testExtTournamentViewReturnsHtmlForAllGames(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'time');
        $html = ExtTournamentView($games);
        $this->assertIsString($html);
        $this->assertStringContainsString('<table', $html);
    }

    public function testExtGameViewReturnsHtmlForAllGames(): void
    {
        $games = TimetableGames('HRN2026', 'season', 'all', 'time');
        $html = ExtGameView($games);
        $this->assertIsString($html);
        $this->assertStringContainsString('<table', $html);
    }

    // --- Header functions ---

    public function testPlaceHeadersReturnsHtml(): void
    {
        $game = TimetableGames(700, 'game', 'all', 'time')[0];
        $html = PlaceHeaders($game, true);
        $this->assertStringContainsString('<tr>', $html);
        $this->assertStringContainsString('reservation=500', $html);
    }

    public function testPoolHeadersReturnsHtml(): void
    {
        $game = TimetableGames(700, 'game', 'all', 'time')[0];
        $html = PoolHeaders($game);
        $this->assertStringContainsString('<tr', $html);
        $this->assertStringContainsString('Pool A', $html);
    }

    public function testSeriesAndPoolHeadersReturnsHtml(): void
    {
        $game = TimetableGames(700, 'game', 'all', 'time')[0];
        $html = SeriesAndPoolHeaders($game);
        $this->assertStringContainsString('<tr', $html);
        $this->assertStringContainsString('Open', $html);
    }

    // --- GameRow ---

    public function testGameRowReturnsHtmlForStartedGame(): void
    {
        // game 700: hasstarted=1, homescore=15, visitorscore=11
        $game = TimetableGames(700, 'game', 'all', 'time')[0];
        $html = GameRow($game);
        $this->assertStringContainsString('<tr', $html);
        $this->assertStringContainsString('15', $html);
        $this->assertStringContainsString('11', $html);
    }

    public function testGameRowReturnsHtmlForUnstartedGame(): void
    {
        // game 701: hasstarted=0, no scores
        $game = TimetableGames(701, 'game', 'all', 'time')[0];
        $html = GameRow($game);
        $this->assertStringContainsString('<tr', $html);
        $this->assertStringContainsString('?', $html);
    }

    public function testGameRowWithDateAndSeriesAndPool(): void
    {
        $game = TimetableGames(700, 'game', 'all', 'time')[0];
        $html = GameRow($game, true, true, true, true, true, true, true);
        $this->assertStringContainsString('Open', $html);
        $this->assertStringContainsString('Pool A', $html);
    }

    // --- PrintTimeZone ---

    public function testPrintTimeZoneReturnsTimezoneTagForEmptyInput(): void
    {
        $html = PrintTimeZone('');
        $this->assertStringContainsString("class='timezone'", $html);
    }

    public function testPrintTimeZoneReturnsHtmlForTimezone(): void
    {
        $html = PrintTimeZone('Europe/Helsinki');
        $this->assertStringContainsString('Europe/Helsinki', $html);
    }

    // --- TimetableGrouping ---

    public function testTimetableGroupingReturnsArrayForSeason(): void
    {
        $result = TimetableGrouping('HRN2026', 'season', 'all');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testTimetableGroupingReturnsArrayForPool(): void
    {
        $result = TimetableGrouping(200, 'pool', 'past');
        $this->assertIsArray($result);
    }

    public function testTimetableGroupingForSeriesFilter(): void
    {
        $result = TimetableGrouping(100, 'series', 'all');
        $this->assertIsArray($result);
    }

    public function testTimetableGroupingForPoolgroupFilter(): void
    {
        $result = TimetableGrouping('200,201', 'poolgroup', 'all');
        $this->assertIsArray($result);
    }

    public function testTimetableGroupingForTeamFilter(): void
    {
        $result = TimetableGrouping(300, 'team', 'all');
        $this->assertIsArray($result);
    }

    public function testTimetableGroupingWithComingTimeFilter(): void
    {
        $result = TimetableGrouping('HRN2026', 'season', 'coming');
        $this->assertIsArray($result);
    }

    public function testTimetableGroupingWithPlayedTimeFilter(): void
    {
        $result = TimetableGrouping('HRN2026', 'season', 'played');
        $this->assertIsArray($result);
    }

    public function testTimetableGroupingWithOngoingTimeFilter(): void
    {
        $result = TimetableGrouping('HRN2026', 'season', 'ongoing');
        $this->assertIsArray($result);
    }

    public function testTimetableGroupingWithComingNotTodayFilter(): void
    {
        $result = TimetableGrouping('HRN2026', 'season', 'comingNotToday');
        $this->assertIsArray($result);
    }

    public function testTimetableGroupingWithPastNotTodayFilter(): void
    {
        $result = TimetableGrouping('HRN2026', 'season', 'pastNotToday');
        $this->assertIsArray($result);
    }

    public function testTimetableGroupingWithTodayFilter(): void
    {
        $result = TimetableGrouping('HRN2026', 'season', 'today');
        $this->assertIsArray($result);
    }

    public function testTimetableGroupingWithTomorrowFilter(): void
    {
        $result = TimetableGrouping('HRN2026', 'season', 'tomorrow');
        $this->assertIsArray($result);
    }

    public function testTimetableGroupingWithYesterdayFilter(): void
    {
        $result = TimetableGrouping('HRN2026', 'season', 'yesterday');
        $this->assertIsArray($result);
    }

    public function testTimetableGroupingWithSpecificDateFilter(): void
    {
        // Default case: timefilter as a date string
        $result = TimetableGrouping('HRN2026', 'season', '2026-06-01');
        $this->assertIsArray($result);
    }

    public function testTimetableGroupingWithOnlyPublicFlag(): void
    {
        $result = TimetableGrouping('HRN2026', 'season', 'all', true);
        $this->assertIsArray($result);
    }

    // --- TimetableFields and TimetableTimeslots ---

    public function testTimetableFieldsReturnsCountForFixtureData(): void
    {
        $count = TimetableFields('Harness Invitational 2026', 'HRN2026');
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testTimetableTimeslotsReturnsArrayForFixtureData(): void
    {
        $slots = TimetableTimeslots('Harness Invitational 2026', 'HRN2026');
        $this->assertIsArray($slots);
        $this->assertNotEmpty($slots);
    }

    // --- Conflict detection ---

    public function testTimetableIntraPoolConflictsReturnsArray(): void
    {
        $result = TimetableIntraPoolConflicts('HRN2026');
        $this->assertIsArray($result);
    }

    public function testTimetableInterPoolConflictsReturnsArray(): void
    {
        $result = TimetableInterPoolConflicts('HRN2026');
        $this->assertIsArray($result);
    }

    // --- TimeTableMoveTimes / TimeTableMoveTime ---

    public function testTimeTableMoveTimesReturnsEmptyArrayWhenNoRows(): void
    {
        $result = TimeTableMoveTimes('HRN2026');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testTimeTableMoveTimeReturnsZeroForMissingEntry(): void
    {
        $this->assertSame(0, TimeTableMoveTime([], 400, '1', 400, '2'));
    }

    public function testTimeTableMoveTimeReturnsConvertedMinutesForEntry(): void
    {
        $movetimes = [400 => ['1' => [400 => ['2' => 5]]]];
        $this->assertSame(300, TimeTableMoveTime($movetimes, 400, '1', 400, '2'));
    }

    public function testTimeTableMoveTimeReturnsZeroForEmptyTime(): void
    {
        $movetimes = [400 => ['1' => [400 => ['2' => '']]]];
        $this->assertSame(0, TimeTableMoveTime($movetimes, 400, '1', 400, '2'));
    }

    // --- TimeTableSetMoveTimes ---

    public function testTimeTableSetMoveTimesInsertsRowsForSuperAdmin(): void
    {
        $times = [
            ['location' => 400, 'field' => '1', 0 => 5],
        ];
        TimeTableSetMoveTimes('HRN2026', $times);
        $result = TimeTableMoveTimes('HRN2026');
        $this->assertNotEmpty($result);
        $this->assertSame(300, TimeTableMoveTime($result, 400, '1', 400, '1'));
    }

    // --- IsGamesScheduled ---

    public function testIsGamesScheduledReturnsTrueWhenGamesExist(): void
    {
        $this->assertTrue(IsGamesScheduled('HRN2026', 'season', 'all'));
    }

    public function testIsGamesScheduledReturnsFalseForUnknownSeason(): void
    {
        $this->assertFalse(IsGamesScheduled('NOSUCHSEASON', 'season', 'all'));
    }

    // --- NextGameDay / PrevGameDay ---

    public function testNextGameDayReturnsGamesWhenComingGameExists(): void
    {
        // game 701 is "coming" (hasstarted=0), so NextGameDay should find it
        $result = NextGameDay('HRN2026', 'season', 'time');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testPrevGameDayReturnsGamesWhenPastGameExists(): void
    {
        // game 700 is "past" (hasstarted=1), so PrevGameDay should find it
        $result = PrevGameDay('HRN2026', 'season', 'time');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    // --- TimetableToCsv ---

    public function testTimetableToCsvReturnsStringWithFixtureData(): void
    {
        $csv = TimetableToCsv('HRN2026', ',');
        $this->assertIsString($csv);
        $this->assertStringContainsString('Helsinki Heat', $csv);
    }
}
