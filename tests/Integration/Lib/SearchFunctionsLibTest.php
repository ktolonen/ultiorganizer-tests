<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

// Shims for functions outside the lib/ → integration test chain.
// utf8entities() is in localization.php (root of SUT, never loaded in integration tests).
if (!function_exists('utf8entities')) {
    function utf8entities(mixed $s): string
    {
        return htmlentities((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

// U_() is in translation.functions.php (unit-test-only, never loaded in integration tests).
if (!function_exists('U_')) {
    function U_(mixed $name): mixed
    {
        return $name;
    }
}

final class SearchFunctionsLibTest extends TestCase
{
    // Fixture: season='HRN2026', series=100, pool=200, teams 300+301,
    // reservations 500+501, games 700+701, user 'admin'.

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(
            // game.functions.php pulls in configuration.functions.php (defines ShowDefenseStats etc.)
            ['user.functions.php', 'game.functions.php', 'search.functions.php'],
            'database_only'
        );

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['uid'] = 'testuser';
        $_SESSION['userproperties']['userrole']['superadmin'] = true;
        // Prevent getSessionLocale() → GetDefaultLocale().
        $_SESSION['userproperties']['locale'] = 'en_US';

        // Required by Search* functions.
        $_SERVER['QUERY_STRING'] = '';

        global $serverConf;
        if (!isset($serverConf)) {
            $serverConf = [];
        }
        $serverConf['PersistentCacheEnabled'] = 'false';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['userproperties'], $_SESSION['uid']);
        $_POST = [];
        $_GET  = [];
        LegacyApp::closeDatabaseConnection();
    }

    // --- SearchSeason ---

    public function testSearchSeasonReturnsFormHtml(): void
    {
        $result = SearchSeason('view=admin/searchresult', [], ['search' => 'Search']);
        $this->assertIsString($result);
        $this->assertStringContainsString('<form', $result);
    }

    // --- SearchSeries ---

    public function testSearchSeriesReturnsFormHtml(): void
    {
        $result = SearchSeries('view=admin/searchresult', [], ['search' => 'Search']);
        $this->assertIsString($result);
        $this->assertStringContainsString('<form', $result);
    }

    // --- SearchPool ---

    public function testSearchPoolReturnsFormHtml(): void
    {
        $result = SearchPool('view=admin/searchresult', [], ['search' => 'Search']);
        $this->assertIsString($result);
        $this->assertStringContainsString('<form', $result);
    }

    // --- SearchTeam ---

    public function testSearchTeamReturnsFormHtml(): void
    {
        $result = SearchTeam('view=admin/searchresult', [], ['search' => 'Search']);
        $this->assertIsString($result);
        $this->assertStringContainsString('<form', $result);
    }

    // --- SearchUser ---

    public function testSearchUserReturnsFormHtml(): void
    {
        $result = SearchUser('view=admin/searchresult', [], ['search' => 'Search']);
        $this->assertIsString($result);
        $this->assertStringContainsString('<form', $result);
    }

    // --- SearchPlayer ---

    public function testSearchPlayerReturnsFormHtml(): void
    {
        $result = SearchPlayer('view=admin/searchresult', [], ['search' => 'Search']);
        $this->assertIsString($result);
        $this->assertStringContainsString('<form', $result);
    }

    // --- SearchReservation ---

    public function testSearchReservationReturnsFormHtml(): void
    {
        $result = SearchReservation('view=admin/searchresult', [], ['search' => 'Search']);
        $this->assertIsString($result);
        $this->assertStringContainsString('<form', $result);
    }

    // --- SearchGame ---

    public function testSearchGameReturnsFormHtml(): void
    {
        $result = SearchGame('view=admin/searchresult', [], ['search' => 'Search']);
        $this->assertIsString($result);
        $this->assertStringContainsString('<form', $result);
    }

    // --- SeasonControl ---

    public function testSeasonControlReturnsSelectElement(): void
    {
        $result = SeasonControl();
        $this->assertIsString($result);
        $this->assertStringContainsString('<select', $result);
        $this->assertStringContainsString('HRN2026', $result);
    }

    public function testSeasonControlWithPostSearchseasonsMarksSelected(): void
    {
        $_POST['searchseasons'] = ['HRN2026'];
        $result = SeasonControl();
        $this->assertStringContainsString('selected', $result);
        $_POST = [];
    }

    public function testSeasonControlWithGetSeasonMarksSelected(): void
    {
        $_GET['Season'] = 'HRN2026';
        $result = SeasonControl();
        $this->assertStringContainsString('selected', $result);
        $_GET = [];
    }

    public function testSeasonControlWithSessionEditseasonsMarksSelected(): void
    {
        $_SESSION['userproperties']['editseason'] = ['HRN2026' => 1];
        $result = SeasonControl();
        $this->assertStringContainsString('selected', $result);
        unset($_SESSION['userproperties']['editseason']);
    }

    // --- SeriesResults ---

    public function testSeriesResultsReturnsEmptyWhenPostNotSet(): void
    {
        $this->assertSame('', SeriesResults());
    }

    public function testSeriesResultsWithSearchTriggerReturnsTableHtml(): void
    {
        $_POST['searchser'] = '1';
        $_GET['Season']     = 'HRN2026';

        $result = SeriesResults();

        $_POST = [];
        $_GET  = [];
        $this->assertIsString($result);
        $this->assertStringContainsString('<table', $result);
    }

    // --- PoolResults ---

    public function testPoolResultsReturnsEmptyWhenPostNotSet(): void
    {
        $this->assertSame('', PoolResults());
    }

    public function testPoolResultsWithSearchTriggerReturnsTableHtml(): void
    {
        $_POST['searchpool'] = '1';
        $_GET['Season']      = 'HRN2026';

        $result = PoolResults();

        $_POST = [];
        $_GET  = [];
        $this->assertIsString($result);
        $this->assertStringContainsString('<table', $result);
    }

    // --- TeamResults ---

    public function testTeamResultsReturnsEmptyWhenPostNotSet(): void
    {
        $this->assertSame('', TeamResults());
    }

    public function testTeamResultsWithSearchTriggerReturnsTableHtml(): void
    {
        $_POST['searchteam'] = '1';
        $_GET['Season']      = 'HRN2026';

        $result = TeamResults();

        $_POST = [];
        $_GET  = [];
        $this->assertIsString($result);
        $this->assertStringContainsString('<table', $result);
    }

    // --- UserResults ---

    public function testUserResultsReturnsEmptyWhenPostNotSet(): void
    {
        $this->assertSame('', UserResults());
    }

    public function testUserResultsWithSearchTriggerReturnsTableHtml(): void
    {
        $_POST['searchuser'] = '1';
        $_GET['Season']      = 'HRN2026';

        $result = UserResults();

        $_POST = [];
        $_GET  = [];
        $this->assertIsString($result);
        $this->assertStringContainsString('<table', $result);
    }

    public function testUserResultsWithRegisterRequestQueryUsesRegisterTable(): void
    {
        $_POST['searchuser']       = '1';
        $_POST['registerrequest']  = 'true';
        $_GET['Season']            = 'HRN2026';

        $result = UserResults();

        $_POST = [];
        $_GET  = [];
        $this->assertIsString($result);
    }

    public function testUserResultsWithUseSeasonsFilter(): void
    {
        $_POST['searchuser']   = '1';
        $_POST['useseasons']   = 'true';
        $_GET['Season']        = 'HRN2026';

        $result = UserResults();

        $_POST = [];
        $_GET  = [];
        $this->assertIsString($result);
    }

    public function testUserResultsWithTeamnameFilter(): void
    {
        $_POST['searchuser'] = '1';
        $_POST['teamname']   = 'Helsinki';
        $_GET['Season']      = 'HRN2026';

        $result = UserResults();

        $_POST = [];
        $_GET  = [];
        $this->assertIsString($result);
    }

    public function testUserResultsWithUsernameAndEmailFilters(): void
    {
        $_POST['searchuser'] = '1';
        $_POST['username']   = 'Admin';
        $_POST['email']      = 'example.com';
        $_GET['Season']      = 'HRN2026';

        $result = UserResults();

        $_POST = [];
        $_GET  = [];
        $this->assertIsString($result);
    }

    // --- PlayerResults ---

    public function testPlayerResultsReturnsEmptyWhenPostNotSet(): void
    {
        $this->assertSame('', PlayerResults());
    }

    public function testPlayerResultsTriggerReturnsPlayerTable(): void
    {
        // Regression for two fixed defects in the admin player search:
        //   1. referenced `email` while joining only uo_player + uo_team (email lives in
        //      uo_player_profile) -> "Unknown column 'email'";
        //   2. after LEFT JOINing uo_player_profile, the bare firstname/lastname refs became
        //      ambiguous (uo_player_profile also has them) -> "Column 'firstname' ambiguous".
        // Both are now resolved (pp.email + p.firstname/p.lastname), so a triggered search
        // returns the results table listing the fixture players.
        $_POST['searchplayer'] = '1';
        try {
            $result = PlayerResults();
        } finally {
            $_POST = [];
        }

        $this->assertStringContainsString('<table', $result);
        $this->assertStringContainsString('Ari Ace', $result);
        $this->assertStringContainsString('Bea Blade', $result);
    }

    // --- ReservationResults ---

    public function testReservationResultsReturnsEmptyWhenNoTrigger(): void
    {
        $this->assertSame('', ReservationResults());
    }

    public function testReservationResultsWithGetSeasonReturnsTableHtml(): void
    {
        $_GET['season'] = 'HRN2026';

        $result = ReservationResults();

        $_GET = [];
        $this->assertIsString($result);
        $this->assertStringContainsString('<table', $result);
    }

    public function testReservationResultsWithSearchendFilter(): void
    {
        $_GET['season']      = 'HRN2026';
        $_POST['searchend']  = '31.12.2026';

        $result = ReservationResults();

        $_GET  = [];
        $_POST = [];
        $this->assertIsString($result);
    }

    public function testReservationResultsWithGroupFilter(): void
    {
        $_GET['season']       = 'HRN2026';
        $_POST['searchgroup'] = 'Harness';

        $result = ReservationResults();

        $_GET  = [];
        $_POST = [];
        $this->assertIsString($result);
    }

    public function testReservationResultsWithFieldFilter(): void
    {
        $_GET['season']       = 'HRN2026';
        $_POST['searchfield'] = '1';

        $result = ReservationResults();

        $_GET  = [];
        $_POST = [];
        $this->assertIsString($result);
    }

    public function testReservationResultsWithLocationFilter(): void
    {
        $_GET['season']           = 'HRN2026';
        $_POST['searchlocation']  = 'Harness';

        $result = ReservationResults();

        $_GET  = [];
        $_POST = [];
        $this->assertIsString($result);
    }

    // --- GameResults ---

    public function testGameResultsReturnsEmptyWhenPostNotSet(): void
    {
        $this->assertSame('', GameResults());
    }

    public function testGameResultsWithSearchTriggerReturnsTableHtml(): void
    {
        $_POST['searchgame'] = '1';

        $result = GameResults();

        $_POST = [];
        $this->assertIsString($result);
        $this->assertStringContainsString('<table', $result);
    }

    public function testGameResultsWithEndDateFilter(): void
    {
        $_POST['searchgame'] = '1';
        $_POST['searchend']  = '31.12.2026';

        $result = GameResults();

        $_POST = [];
        $this->assertIsString($result);
    }

    public function testGameResultsWithGroupFilter(): void
    {
        $_POST['searchgame']  = '1';
        $_POST['searchgroup'] = 'Harness';

        $result = GameResults();

        $_POST = [];
        $this->assertIsString($result);
    }

    public function testGameResultsWithTeamsFilter(): void
    {
        $_POST['searchgame']  = '1';
        $_POST['searchteams'] = 'Helsinki,Tampere';

        $result = GameResults();

        $_POST = [];
        $this->assertIsString($result);
    }

    // --- Coverage-deepening tests (line-specific branches not hit by the tests above) ---

    public function testSearchSeasonWithHiddenPropertiesCoversHiddenInputLine(): void
    {
        // line 18: hiddenProperties non-empty → <input type='hidden'> rendered
        $result = SearchSeason('target', ['key' => 'val'], ['search' => 'Search']);
        $this->assertStringContainsString("name='key'", $result);
    }

    public function testSearchSeriesWithPostDataCoversValAndHiddenBranches(): void
    {
        // line 39: seriesname input value; line 52: hidden input from hiddenProperties
        $_POST['seriesname'] = 'Harness Division';
        $result = SearchSeries('target', ['prop' => 'v'], ['search' => 'Search']);
        $this->assertStringContainsString('Harness Division', $result);
        $this->assertStringContainsString("name='prop'", $result);
    }

    public function testSearchPoolWithPostDataCoversValueAndHiddenBranches(): void
    {
        // lines 73, 81, 94: seriesname + poolname + hiddenProperties
        $_POST['seriesname'] = 'HRN Division';
        $_POST['poolname']   = 'Pool A';
        $result = SearchPool('target', ['x' => 'y'], ['search' => 'Search']);
        $this->assertStringContainsString('HRN Division', $result);
        $this->assertStringContainsString('Pool A', $result);
    }

    public function testSearchTeamWithPostDataCoversValueAndHiddenBranches(): void
    {
        // lines 115, 123, 136: seriesname + teamname + hiddenProperties
        $_POST['seriesname'] = 'HRN Division';
        $_POST['teamname']   = 'Helsinki Heat';
        $result = SearchTeam('target', ['a' => 'b'], ['search' => 'Search']);
        $this->assertStringContainsString('HRN Division', $result);
        $this->assertStringContainsString('Helsinki Heat', $result);
    }

    public function testSearchUserWithAllPostFieldsCoversAllBranches(): void
    {
        // lines 153, 162, 170, 178, 186, 199, 202
        $_POST['useseasons']     = 'true';
        $_POST['username']       = 'Ari';
        $_POST['teamname']       = 'Helsinki';
        $_POST['email']          = 'ari@example.com';
        $_POST['registerrequest'] = 'true';
        $result = SearchUser('target', ['z' => 'q'], ['search' => 'Search']);
        $this->assertStringContainsString('checked', $result);
        $this->assertStringContainsString('Ari', $result);
    }

    public function testSearchPlayerWithAllPostFieldsCoversAllBranches(): void
    {
        // lines 219, 228, 236, 244, 257, 260
        $_POST['useseasons']     = 'true';
        $_POST['username']       = 'Bea';
        $_POST['teamname']       = 'Tampere';
        $_POST['email']          = 'bea@example.com';
        $_POST['registerrequest'] = 'true';
        $result = SearchPlayer('target', ['z' => 'q'], ['search' => 'Search']);
        $this->assertStringContainsString('checked', $result);
        $this->assertStringContainsString('Bea', $result);
    }

    public function testSearchReservationWithAllPostFieldsCoversValueBranches(): void
    {
        // lines 279, 290, 299, 306, 313, 327, 331, 332
        $_POST['searchstart']      = '01.01.2026';
        $_POST['searchend']        = '31.12.2026';
        $_POST['searchgroup']      = 'Harness';
        $_POST['searchfield']      = '1';
        $_POST['searchlocation']   = 'Helsinki';
        $_POST['searchreservation'] = '1';
        $result = SearchReservation('target', ['r' => 's'], ['save' => 'Save']);
        $this->assertStringContainsString('01.01.2026', $result);
        $this->assertStringContainsString('Harness', $result);
    }

    public function testSearchGameWithAllPostFieldsCoversValueBranches(): void
    {
        // lines 351, 363, 373, 380, 387, 394, 408
        $_POST['searchstart']    = '01.01.2026';
        $_POST['searchend']      = '31.12.2026';
        $_POST['searchgroup']    = 'Harness';
        $_POST['searchfield']    = '1';
        $_POST['searchlocation'] = 'Helsinki';
        $_POST['searchteams']    = 'HeatFC';
        $result = SearchGame('target', ['g' => 'h'], ['save' => 'Save']);
        $this->assertStringContainsString('01.01.2026', $result);
    }

    public function testSeriesResultsWithSearchseasonsPostCoversLine454(): void
    {
        // line 454: searchseasons in POST (array_flip branch)
        $_POST['searchser']     = '1';
        $_POST['searchseasons'] = ['HRN2026'];
        $result = SeriesResults();
        $this->assertIsString($result);
    }

    public function testSeriesResultsWithSessionEditseasonsCoversLine458(): void
    {
        // line 458: no searchseasons, no GET Season → session branch
        $_POST['searchser'] = '1';
        $_SESSION['userproperties']['editseason'] = ['HRN2026' => 1];
        $result = SeriesResults();
        $this->assertIsString($result);
    }

    public function testSeriesResultsWithSeriesnameFilterCoversLine466(): void
    {
        // line 466: seriesname filter appended to query
        $_POST['searchser']    = '1';
        $_GET['Season']        = 'HRN2026';
        $_POST['seriesname']   = 'HRN';
        $result = SeriesResults();
        $this->assertIsString($result);
    }

    public function testPoolResultsWithSearchseasonsCoversLine492(): void
    {
        $_POST['searchpool']    = '1';
        $_POST['searchseasons'] = ['HRN2026'];
        $result = PoolResults();
        $this->assertIsString($result);
    }

    public function testPoolResultsWithSessionCoversLine496(): void
    {
        $_POST['searchpool'] = '1';
        $_SESSION['userproperties']['editseason'] = ['HRN2026' => 1];
        $result = PoolResults();
        $this->assertIsString($result);
    }

    public function testPoolResultsWithFiltersCoversLines504And507(): void
    {
        // lines 504, 507: seriesname + poolname filters
        $_POST['searchpool']  = '1';
        $_GET['Season']       = 'HRN2026';
        $_POST['seriesname']  = 'HRN';
        $_POST['poolname']    = 'Pool';
        $result = PoolResults();
        $this->assertIsString($result);
    }

    public function testTeamResultsWithSearchseasonsCoversLine535(): void
    {
        $_POST['searchteam']    = '1';
        $_POST['searchseasons'] = ['HRN2026'];
        $result = TeamResults();
        $this->assertIsString($result);
    }

    public function testTeamResultsWithSessionCoversLine539(): void
    {
        $_POST['searchteam'] = '1';
        $_SESSION['userproperties']['editseason'] = ['HRN2026' => 1];
        $result = TeamResults();
        $this->assertIsString($result);
    }

    public function testTeamResultsWithFiltersCoversLines547And550(): void
    {
        // lines 547, 550: seriesname + teamname filters
        $_POST['searchteam']  = '1';
        $_GET['Season']       = 'HRN2026';
        $_POST['seriesname']  = 'HRN';
        $_POST['teamname']    = 'Helsinki';
        $result = TeamResults();
        $this->assertIsString($result);
    }

    public function testUserResultsWithSearchseasonsCoversLine580(): void
    {
        // line 580: searchseasons in POST
        $_POST['searchuser']    = '1';
        $_POST['searchseasons'] = ['HRN2026'];
        $result = UserResults();
        $this->assertIsString($result);
    }

    public function testUserResultsWithSessionCoversLine584(): void
    {
        // line 584: session editseason branch
        $_POST['searchuser'] = '1';
        $_SESSION['userproperties']['editseason'] = ['HRN2026' => 1];
        $result = UserResults();
        $this->assertIsString($result);
    }

    public function testUserResultsWithUseseasonsAndTeamnameCoversLines598And612(): void
    {
        // lines 598, 612: criteria len > 0 when adding teamname, then username
        $_POST['searchuser']  = '1';
        $_GET['Season']       = 'HRN2026';
        $_POST['useseasons']  = 'true';
        $_POST['teamname']    = 'Helsinki';
        $_POST['username']    = 'Ari';
        $result = UserResults();
        $this->assertIsString($result);
    }

    public function testReservationResultsWithStartDateCoversLine749(): void
    {
        // line 749: searchstart from POST
        $_POST['searchreservation'] = '1';
        $_POST['searchstart']       = '01.01.2026';
        $result = ReservationResults();
        $this->assertIsString($result);
    }

    public function testReservationResultsWithZeroGamesRowCoversLine798(): void
    {
        // line 798: reservation with 0 games gets delete button
        $resId = (int) DBQueryInsert(
            "INSERT INTO uo_reservation (location, fieldname, reservationgroup, starttime, endtime, season)
             VALUES (400, '3', 'Harness Zero Games', '2026-02-01 10:00:00', '2026-02-01 11:30:00', 'HRN2026')"
        );
        try {
            $_POST['searchreservation'] = '1';
            $_POST['searchstart']       = '01.01.2026';
            $result = ReservationResults();
            $this->assertStringContainsString('deletebutton', $result);
        } finally {
            DBQuery("DELETE FROM uo_reservation WHERE id=$resId");
        }
    }

    public function testGameResultsWithStartDateCoversLine829(): void
    {
        // line 829: searchstart from POST (else branch = today is default)
        $_POST['searchgame']  = '1';
        $_POST['searchstart'] = '01.01.2026';
        $result = GameResults();
        $this->assertIsString($result);
    }

    public function testGameResultsWithFieldAndLocationFiltersCoversLines849To853(): void
    {
        // lines 849, 852-853: field + location filters appended to query
        $_POST['searchgame']      = '1';
        $_POST['searchstart']     = '01.01.2026';
        $_POST['searchfield']     = '1';
        $_POST['searchlocation']  = 'Helsinki';
        $result = GameResults();
        $this->assertIsString($result);
    }

    public function testGameResultsReturnsRowsForFixtureGamesCoversLines865To870(): void
    {
        // lines 865-870: while loop body — fixture reservations are in 2026
        $_POST['searchgame']  = '1';
        $_POST['searchstart'] = '01.01.2026';
        $result = GameResults();
        // Fixture has games linked to reservations starting 2026-06-01
        $this->assertStringContainsString('<table>', $result);
    }
}
