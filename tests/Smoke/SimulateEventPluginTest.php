<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * plugins/simulate_event.php builds a complete test event (divisions, teams,
 * players, round-robin pools, a schedule) and deletes the events it built.
 *
 * Page text of the plugin is not translated, so the assertions read it
 * directly. The form carries a one-time token kept in the session: a second
 * POST with the same token must not create another event. Deleting is limited
 * to ids of the form SIM123 and goes through DeleteSeasonWithData(), so a real
 * event id must be refused and left alone.
 *
 * The tests generate one small event (1 division, 6 teams of 4 players, 2 pools
 * of 3 teams, no playoffs): each pool plays 3 round-robin games, 6 in all.
 */
final class SimulateEventPluginTest extends TestCase
{
    private const FORM = [
        'name' => 'Harness simulation',
        'divisions' => '1',
        'teamsMin' => '6',
        'teamsMax' => '6',
        'playersMin' => '4',
        'playersMax' => '4',
        'poolsMin' => '2',
        'poolsMax' => '2',
        'days' => '2',
        'fields' => '2',
        'seed' => '7',
    ];

    private const TEMPLATE = 9900;

    private string $cookie = '';

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(['season.functions.php', 'statistical.functions.php'], 'database_with_common');
        self::cleanUp();
        // The fixture has no pool template; the plugin builds every pool from the first one.
        DBQuery(sprintf(
            "INSERT INTO uo_pooltemplate (template_id, name, ordering, continuingpool, teams, mvgames, timeoutlen,
                halftime, winningscore, timeouts, timeoutsper, timeoutsovertime, timeoutstimecap, betweenpointslen,
                type, timeslot, forfeitscore, forfeitagainst, drawsallowed)
             VALUES (%d, 'Harness template', 'A', 0, 3, 0, 70, 35, 15, 2, 'half', 1, 'soft', 90, 1, 70, 15, 0, 0)",
            self::TEMPLATE,
        ));
        $this->cookie = self::login();
    }

    protected function tearDown(): void
    {
        self::cleanUp();
        LegacyApp::closeDatabaseConnection();
    }

    public function testGeneratesTheRequestedEventAndSchedulesEveryGame(): void
    {
        [$status, $body] = $this->generate(self::FORM);

        $this->assertStringContainsString(' 200 ', $status);
        $this->assertSame(1, preg_match('/Event <a [^>]*>(SIM\d{3})<\/a> created\./', $body, $m), 'no created message');
        $season = $m[1];
        $this->assertSame('Harness simulation', self::scalar("SELECT name FROM uo_season WHERE season_id='$season'"));
        $this->assertSame('1', self::scalar("SELECT COUNT(*) FROM uo_series WHERE season='$season'"));
        $this->assertSame('6', self::scalar(
            "SELECT COUNT(*) FROM uo_team t JOIN uo_series s ON s.series_id=t.series WHERE s.season='$season'",
        ));
        $this->assertSame('24', self::scalar(
            "SELECT COUNT(*) FROM uo_player p JOIN uo_team t ON t.team_id=p.team
             JOIN uo_series s ON s.series_id=t.series WHERE s.season='$season'",
        ));
        $this->assertSame('2', self::scalar(
            "SELECT COUNT(*) FROM uo_pool p JOIN uo_series s ON s.series_id=p.series WHERE s.season='$season'",
        ));
        $this->assertSame('6', self::scalar(
            "SELECT COUNT(*) FROM uo_game_pool gp JOIN uo_pool p ON p.pool_id=gp.pool
             JOIN uo_series s ON s.series_id=p.series WHERE s.season='$season' AND gp.timetable=1",
        ));
        // Every generated game got a reservation, and the page says so.
        $this->assertSame('6', self::scalar(
            "SELECT COUNT(*) FROM uo_game g JOIN uo_game_pool gp ON gp.game=g.game_id
             JOIN uo_pool p ON p.pool_id=gp.pool JOIN uo_series s ON s.series_id=p.series
             WHERE s.season='$season' AND g.reservation IS NOT NULL",
        ));
        $this->assertStringContainsString('Games: 6, scheduled: 6.', $body);
        $this->assertStringContainsString('<td>Open</td><td>6</td><td>2</td><td>0</td><td>24</td>', $body);
        $this->assertStringNotContainsString("class='warning'", $body);
    }

    public function testWithoutAPoolTemplateNothingIsCreated(): void
    {
        DBQuery(sprintf("DELETE FROM uo_pooltemplate WHERE template_id=%d", self::TEMPLATE));

        [, $body] = $this->generate(self::FORM);

        $this->assertStringContainsString('Create a pool template first.', $body);
        $this->assertStringNotContainsString('created.', $body);
        $this->assertSame('0', self::scalar("SELECT COUNT(*) FROM uo_season WHERE season_id LIKE 'SIM___'"));
    }

    public function testNoTeamPlaysTwiceInTheSameTimeSlot(): void
    {
        [, $body] = $this->generate(self::FORM);
        $this->assertSame(1, preg_match('/Event <a [^>]*>(SIM\d{3})<\/a> created\./', $body, $m));

        // Two pools of three teams: each team plays the other two once.
        $perTeam = DBQueryToArrayUncached(
            "SELECT t.team_id, COUNT(*) AS games FROM uo_team t
             JOIN uo_series s ON s.series_id=t.series
             JOIN uo_game g ON t.team_id IN (g.hometeam, g.visitorteam)
             WHERE s.season='{$m[1]}' GROUP BY t.team_id",
        );
        $this->assertCount(6, $perTeam);
        foreach ($perTeam as $row) {
            $this->assertSame('2', (string) $row['games'], 'team ' . $row['team_id']);
        }
        $clashes = self::scalar(
            "SELECT COUNT(*) FROM (
                SELECT t.team_id, g.time FROM uo_team t
                JOIN uo_series s ON s.series_id=t.series
                JOIN uo_game g ON t.team_id IN (g.hometeam, g.visitorteam)
                WHERE s.season='{$m[1]}' GROUP BY t.team_id, g.time HAVING COUNT(*) > 1
             ) c",
        );
        $this->assertSame('0', $clashes);
    }

    public function testSameSeedGeneratesTheSameEvent(): void
    {
        [, $first] = $this->generate(self::FORM + ['seed' => '7']);
        [, $second] = $this->generate(self::FORM + ['seed' => '7']);

        $this->assertSame(1, preg_match('/<table class=\'admintable\'>.*?<\/table>/s', $first, $a));
        $this->assertSame(1, preg_match('/<table class=\'admintable\'>.*?<\/table>/s', $second, $b));
        $this->assertSame($a[0], $b[0]);
        $this->assertSame('2', self::scalar("SELECT COUNT(*) FROM uo_season WHERE season_id LIKE 'SIM___'"));
    }

    public function testRepeatedSubmitWithTheSameTokenCreatesNothingMore(): void
    {
        $token = $this->formToken();
        $first = $this->post(self::FORM + ['simulate' => '1', 'token' => $token]);
        $replay = $this->post(self::FORM + ['simulate' => '1', 'token' => $token]);

        $this->assertStringContainsString('created.', $first[1]);
        $this->assertStringContainsString('This form was already submitted.', $replay[1]);
        $this->assertStringNotContainsString('created.', $replay[1]);
        $this->assertSame('1', self::scalar("SELECT COUNT(*) FROM uo_season WHERE season_id LIKE 'SIM___'"));
    }

    public function testWrongTokenCreatesNothing(): void
    {
        $this->formToken();
        [, $body] = $this->post(self::FORM + ['simulate' => '1', 'token' => 'not-the-token']);

        $this->assertStringContainsString('This form was already submitted.', $body);
        $this->assertSame('0', self::scalar("SELECT COUNT(*) FROM uo_season WHERE season_id LIKE 'SIM___'"));
    }

    public function testInvalidRangeAndOutOfBoundsValuesAreReportedWithoutCreatingAnEvent(): void
    {
        [, $body] = $this->generate(['divisions' => '99', 'teamsMin' => '9', 'teamsMax' => '4'] + self::FORM);

        $this->assertStringContainsString('Divisions was adjusted to 10, the highest or lowest allowed value.', $body);
        $this->assertStringContainsString('The minimum cannot be larger than the maximum.', $body);
        $this->assertStringNotContainsString('created.', $body);
        $this->assertSame('0', self::scalar("SELECT COUNT(*) FROM uo_season WHERE season_id LIKE 'SIM___'"));
    }

    public function testDeleteRemovesASimulatedEventAndOnlyThat(): void
    {
        [, $body] = $this->generate(self::FORM);
        $this->assertSame(1, preg_match('/Event <a [^>]*>(SIM\d{3})<\/a> created\./', $body, $m));
        $season = $m[1];

        [, $deleted] = $this->post(['delete' => '1', 'deleteSeason' => $season]);

        $this->assertStringContainsString("Event $season deleted.", $deleted);
        $this->assertSame('0', self::scalar("SELECT COUNT(*) FROM uo_season WHERE season_id='$season'"));
        $this->assertSame('0', self::scalar(
            "SELECT COUNT(*) FROM uo_team t JOIN uo_series s ON s.series_id=t.series WHERE s.season='$season'",
        ));
        $this->assertSame('1', self::scalar("SELECT COUNT(*) FROM uo_season WHERE season_id='HRN2026'"));
    }

    public function testDeleteRefusesAnEventThatIsNotSimulated(): void
    {
        [, $body] = $this->post(['delete' => '1', 'deleteSeason' => 'HRN2026']);

        $this->assertStringContainsString('Event could not be deleted.', $body);
        $this->assertSame('1', self::scalar("SELECT COUNT(*) FROM uo_season WHERE season_id='HRN2026'"));
        $this->assertSame('2', self::scalar("SELECT COUNT(*) FROM uo_game WHERE game_id IN (700, 701)"));
    }

    public function testDeleteOfAnUnknownSimulatedIdReportsFailure(): void
    {
        [, $body] = $this->post(['delete' => '1', 'deleteSeason' => 'SIM999']);

        $this->assertStringContainsString('Event could not be deleted.', $body);
        $this->assertStringNotContainsString('Event SIM999 deleted.', $body);
    }

    /**
     * Fetches the form for its token and posts the generate request.
     *
     * @param array<string, string> $form
     * @return array{0: string, 1: string}
     */
    private function generate(array $form): array
    {
        return $this->post($form + ['simulate' => '1', 'token' => $this->formToken()]);
    }

    private function formToken(): string
    {
        [, $body] = self::request('/index.php?view=plugins/simulate_event', 'GET', ['Cookie: ' . $this->cookie]);
        self::assertSame(1, preg_match("/name='token' value='([0-9a-f]{32})'/", $body, $m), 'form has no token');
        return $m[1];
    }

    /**
     * @param array<string, string> $fields
     * @return array{0: string, 1: string}
     */
    private function post(array $fields): array
    {
        [$status, $body] = self::request(
            '/index.php?view=plugins/simulate_event',
            'POST',
            ['Cookie: ' . $this->cookie, 'Content-Type: application/x-www-form-urlencoded'],
            http_build_query($fields),
        );
        return [$status, $body];
    }

    private static function scalar(string $sql): string
    {
        return (string) DBQueryToValueUncached($sql);
    }

    private static function cleanUp(): void
    {
        DBQuery(sprintf("DELETE FROM uo_pooltemplate WHERE template_id=%d", self::TEMPLATE));
        LegacyApp::loginAsAdmin();
        SetUserSessionData('admin');
        foreach (DBQueryToArrayUncached("SELECT season_id FROM uo_season WHERE season_id REGEXP '^SIM[0-9]{3}$'") as $row) {
            DeleteSeasonWithData($row['season_id']);
        }
    }

    private static function login(): string
    {
        [$status, , $headers] = self::request(
            '/index.php?view=frontpage',
            'POST',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['myusername' => 'admin', 'mypassword' => 'harness-admin']),
        );
        $cookies = [];
        foreach ($headers as $header) {
            if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $m)) {
                $cookies[$m[1]] = $m[1] . '=' . $m[2];
            }
        }
        self::assertNotEmpty($cookies, 'login set no cookie: ' . $status);
        return implode('; ', $cookies);
    }

    /** @return array{0: string, 1: string, 2: array<int, string>} */
    private static function request(string $path, string $method, array $headers, string $content = ''): array
    {
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $content,
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 120,
            ],
        ]);
        $body = file_get_contents($baseUrl . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        return [$responseHeaders[0] ?? '', (string) $body, $responseHeaders];
    }
}
