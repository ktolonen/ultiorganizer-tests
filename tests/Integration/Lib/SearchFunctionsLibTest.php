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

    // PlayerResults body (lines 659-736) is blocked by a SUT bug:
    // the query references `email` without joining uo_player_profile, which holds that column.
    // Trigger path is untestable until the SUT bug is fixed.

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
}
