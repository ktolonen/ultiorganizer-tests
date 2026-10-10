<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Scorekeeper Result page over HTTP: the +1/-1 taps, the final save guarded by
 * the score the page showed, the game clock controls, the request checks in
 * scorekeeper/auth.php, and the Scoresheet-behind-result redirect.
 *
 * Works through an anonymous scorekeeping link to fixture game 701, moved to
 * today because a link works only on its game day. Everything is restored
 * afterwards. Assertions avoid translated text, because config-overrides
 * renders in fi_FI.
 */
final class ScoreTapFlowTest extends TestCase
{
    private const GAME = 701;

    /** @var array<string, string> */
    private array $cookies = [];

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(['user.functions.php', 'game.functions.php'], 'pool_stack');
        self::restore();
        $token = bin2hex(random_bytes(16));
        DBQuery(sprintf("INSERT INTO uo_scorekeeper_token (token, game) VALUES ('%s', %d)", $token, self::GAME));
        DBQuery("UPDATE uo_season SET anonymous_scorekeeping=1 WHERE season_id='HRN2026'");
        DBQuery("DELETE FROM uo_setting WHERE name='DisableScoresheetHistory'");
        DBQuery("INSERT INTO uo_setting (name, value) VALUES ('DisableScoresheetHistory', 'false')");
        $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Helsinki')))->format('Y-m-d');
        DBQuery(sprintf("UPDATE uo_game SET time='%s 14:00:00' WHERE game_id=%d", $today, self::GAME));
        self::flushQueryCaches();

        $this->get('/scorekeeper/index.php?view=login');
        [$status] = $this->get('/scorekeeper/index.php?t=' . $token);
        $this->assertStringContainsString(' 302 ', $status);
    }

    protected function tearDown(): void
    {
        self::restore();
        LegacyApp::closeDatabaseConnection();
    }

    // --- score taps ---

    public function testTapsSaveAtOnceAndRedirectBackToTheResultPage(): void
    {
        [$status, , $headers] = $this->tap('homeplus');

        $this->assertStringContainsString(' 302 ', $status);
        $this->assertSame('?view=addresult&game=' . self::GAME, self::header($headers, 'Location'));
        $this->assertSame([1, 0, 1, 1], self::state());

        $this->tap('awayplus');
        $this->tap('homeplus');
        $this->tap('awayminus');
        $this->assertSame([2, 0, 1, 1], self::state());
    }

    public function testTapCannotTakeAScoreBelowZero(): void
    {
        $this->tap('homeplus');
        $this->tap('awayminus');

        $this->assertSame([1, 0, 1, 1], self::state());
    }

    public function testUndoingTheOnlyPointReturnsTheGameToNotStarted(): void
    {
        $this->tap('homeplus');
        $this->tap('homeminus');

        $this->assertSame([null, null, 0, 0], self::state());
    }

    public function testResultPageCarriesTheShownScoreAndNoTapButtonsOnceFinal(): void
    {
        $this->tap('homeplus');
        [, $body] = $this->get('/scorekeeper/index.php?view=addresult&game=' . self::GAME);
        $this->assertStringContainsString("name='shownhome' value='1'", $body);
        $this->assertStringContainsString("name='shownaway' value='0'", $body);
        $this->assertStringContainsString("name='homeplus'", $body);

        $this->post('/scorekeeper/index.php?view=addresult&game=' . self::GAME, ['home' => '13', 'away' => '9', 'shownhome' => '1', 'shownaway' => '0', 'save' => '1']);
        [, $body] = $this->get('/scorekeeper/index.php?view=addresult&game=' . self::GAME);
        $this->assertStringNotContainsString("name='homeplus'", $body);
        $this->assertStringNotContainsString("name='startgame'", $body);
    }

    public function testTapLeavesAFinalResultAlone(): void
    {
        $this->post('/scorekeeper/index.php?view=addresult&game=' . self::GAME, ['home' => '13', 'away' => '9', 'save' => '1']);

        $this->tap('homeplus');
        $this->tap('awayminus');

        $this->assertSame([13, 9, 0, 2], self::state());
    }

    // --- final save guarded by the shown score ---

    public function testFinalSaveWithTheShownScoreSaves(): void
    {
        $this->tap('homeplus');

        $this->post('/scorekeeper/index.php?view=addresult&game=' . self::GAME, ['home' => '13', 'away' => '9', 'shownhome' => '1', 'shownaway' => '0', 'save' => '1']);

        $this->assertSame([13, 9, 0, 2], self::state());
    }

    public function testFinalSaveFromAStalePageKeepsTheNewerPoint(): void
    {
        $this->tap('homeplus');
        $this->tap('homeplus');

        [, $body] = $this->post('/scorekeeper/index.php?view=addresult&game=' . self::GAME, ['home' => '13', 'away' => '9', 'shownhome' => '1', 'shownaway' => '0', 'save' => '1']);

        $this->assertSame([2, 0, 1, 1], self::state());
        $this->assertStringContainsString("class='warning'", $body);
        $this->assertSame(0, self::resultLogCount());
    }

    public function testFinalSaveFromAnUnstartedPageIsRefusedOncePointsExist(): void
    {
        $this->tap('homeplus');

        $this->post('/scorekeeper/index.php?view=addresult&game=' . self::GAME, ['home' => '13', 'away' => '9', 'shownhome' => '', 'shownaway' => '', 'save' => '1']);

        $this->assertSame([1, 0, 1, 1], self::state());
    }

    public function testFinalSaveWithoutAShownScoreStillSaves(): void
    {
        $this->post('/scorekeeper/index.php?view=addresult&game=' . self::GAME, ['home' => '13', 'away' => '9', 'save' => '1']);

        $this->assertSame([13, 9, 0, 2], self::state());
    }

    public function testOutOfRangeFinalScoreIsNotSaved(): void
    {
        $this->post('/scorekeeper/index.php?view=addresult&game=' . self::GAME, ['home' => '5000', 'away' => '9', 'save' => '1']);

        $this->assertSame([null, null, 0, 0], self::state());
    }

    // --- game clock controls ---

    public function testStartPauseResumeAndSetTheClock(): void
    {
        $this->clock('startgame');
        $row = self::timer();
        $this->assertNotNull($row['timer_start']);
        $this->assertNull($row['timer_pause_start']);
        $this->assertSame([1, 1], [(int) $row['isongoing'], (int) $row['hasstarted']]);

        $this->clock('pausegame');
        $this->assertNotNull(self::timer()['timer_pause_start']);

        $pause = (int) self::timer()['timer_pause_start'];
        $this->clock('setgameclock', ['settimemm' => '12', 'settimess' => '30']);
        $row = self::timer();
        $this->assertSame($pause - (int) $row['timer_paused_duration'] - 750, (int) $row['timer_start']);

        $this->clock('resumegame');
        $this->assertNull(self::timer()['timer_pause_start']);
    }

    public function testSetClockIsIgnoredWhileTheClockRuns(): void
    {
        $this->clock('startgame');
        $before = (int) self::timer()['timer_start'];

        $this->clock('setgameclock', ['settimemm' => '12', 'settimess' => '30']);

        $this->assertSame($before, (int) self::timer()['timer_start']);
    }

    public function testResetClockOnlyWhileTheScoreIsZeroZero(): void
    {
        $this->clock('startgame');
        $this->tap('homeplus');
        $this->clock('resetgameclock');
        $this->assertNotNull(self::timer()['timer_start']);

        $this->tap('homeminus');
        $this->clock('resetgameclock');
        $row = self::timer();
        $this->assertNull($row['timer_start']);
        $this->assertSame([0, 0], [(int) $row['isongoing'], (int) $row['hasstarted']]);
    }

    public function testClockControlsLeaveAFinalGameAlone(): void
    {
        $this->post('/scorekeeper/index.php?view=addresult&game=' . self::GAME, ['home' => '13', 'away' => '9', 'save' => '1']);

        foreach (['startgame', 'pausegame', 'resumegame', 'setgameclock', 'resetgameclock'] as $control) {
            [$status, , $headers] = $this->clock($control);
            $this->assertStringContainsString(' 302 ', $status, $control);
            $this->assertSame('?view=addresult&game=' . self::GAME, self::header($headers, 'Location'), $control);
        }

        $row = self::timer();
        $this->assertNull($row['timer_start']);
        $this->assertSame([13, 9, 0, 2], self::state());
    }

    public function testClockControlsAreIgnoredWhenTheEventHidesGameTime(): void
    {
        DBQuery("UPDATE uo_season SET hide_time_on_scoresheet=1 WHERE season_id='HRN2026'");
        self::flushQueryCaches();

        $this->clock('startgame');

        $this->assertNull(self::timer()['timer_start']);
    }

    // --- request checks in scorekeeper/auth.php ---

    public function testRequestNamingTwoGamesIsRefused(): void
    {
        [$status, , $headers] = $this->post('/scorekeeper/index.php?view=addresult&game=700', ['game' => (string) self::GAME, 'homeplus' => '1']);

        $this->assertStringContainsString(' 302 ', $status);
        $this->assertStringContainsString('view=login', (string) self::header($headers, 'Location'));
        $this->assertSame([null, null, 0, 0], self::state());
    }

    public function testTeamNotPlayingTheGameIsRefused(): void
    {
        [$status, , $headers] = $this->post('/scorekeeper/index.php?view=addresult&game=' . self::GAME, ['team' => '9999', 'homeplus' => '1']);

        $this->assertStringContainsString(' 302 ', $status);
        $this->assertStringContainsString('view=login', (string) self::header($headers, 'Location'));
        $this->assertSame([null, null, 0, 0], self::state());
    }

    public function testTeamPlayingTheGameIsAccepted(): void
    {
        [$status] = $this->get('/scorekeeper/index.php?view=addplayerlists&game=' . self::GAME . '&team=300');

        $this->assertStringContainsString(' 200 ', $status);
    }

    public function testPostWithoutAGameIdDoesNotFallBackToTheSessionGame(): void
    {
        $this->get('/scorekeeper/index.php?view=addresult&game=' . self::GAME);

        $this->post('/scorekeeper/index.php?view=addresult', ['homeplus' => '1']);

        $this->assertSame([null, null, 0, 0], self::state());
    }

    // --- Scoresheet behind the Result page ---

    public function testEndGameSendsASheetBehindTheResultToTheResultPage(): void
    {
        DBQuery(sprintf(
            "INSERT INTO uo_goal (game, num, assist, scorer, time, homescore, visitorscore, ishomegoal, iscallahan)
                VALUES (%d, 1, 802, 803, 120, 1, 0, 1, 0)",
            self::GAME,
        ));
        DBQuery(sprintf("UPDATE uo_game SET homescore=3, visitorscore=0, isongoing=1, hasstarted=1 WHERE game_id=%d", self::GAME));
        self::flushQueryCaches();

        [$status, , $headers] = $this->get('/scorekeeper/index.php?view=endgame&game=' . self::GAME);

        $this->assertStringContainsString(' 302 ', $status);
        $this->assertSame('?view=addresult&game=' . self::GAME, self::header($headers, 'Location'));
    }

    public function testEndGameStaysWhenTheSheetMatchesTheResult(): void
    {
        DBQuery(sprintf(
            "INSERT INTO uo_goal (game, num, assist, scorer, time, homescore, visitorscore, ishomegoal, iscallahan)
                VALUES (%d, 1, 802, 803, 120, 1, 0, 1, 0)",
            self::GAME,
        ));
        DBQuery(sprintf("UPDATE uo_game SET homescore=1, visitorscore=0, isongoing=1, hasstarted=1 WHERE game_id=%d", self::GAME));
        self::flushQueryCaches();

        [$status] = $this->get('/scorekeeper/index.php?view=endgame&game=' . self::GAME);

        $this->assertStringContainsString(' 200 ', $status);
    }

    public function testEndGameStaysWithoutGoalRows(): void
    {
        DBQuery(sprintf("UPDATE uo_game SET homescore=3, visitorscore=2, isongoing=1, hasstarted=1 WHERE game_id=%d", self::GAME));
        self::flushQueryCaches();

        [$status] = $this->get('/scorekeeper/index.php?view=endgame&game=' . self::GAME);

        $this->assertStringContainsString(' 200 ', $status);
    }

    // --- helpers ---

    /** @return array{0: string, 1: string, 2: array<int, string>} */
    private function tap(string $button): array
    {
        $result = $this->post('/scorekeeper/index.php?view=addresult&game=' . self::GAME, [$button => '1']);
        self::flushQueryCaches();
        return $result;
    }

    /** @return array{0: string, 1: string, 2: array<int, string>} */
    private function clock(string $control, array $extra = []): array
    {
        $result = $this->post('/scorekeeper/index.php?view=addresult&game=' . self::GAME, [$control => '1'] + $extra);
        self::flushQueryCaches();
        return $result;
    }

    /** @return array{0: ?int, 1: ?int, 2: int, 3: int} */
    private static function state(): array
    {
        self::flushQueryCaches();
        $row = DBQueryToRow(sprintf("SELECT homescore, visitorscore, isongoing, hasstarted FROM uo_game WHERE game_id=%d", self::GAME));
        return [
            $row['homescore'] === null ? null : (int) $row['homescore'],
            $row['visitorscore'] === null ? null : (int) $row['visitorscore'],
            (int) $row['isongoing'],
            (int) $row['hasstarted'],
        ];
    }

    /** @return array<string, mixed> */
    private static function timer(): array
    {
        self::flushQueryCaches();
        return DBQueryToRow(sprintf(
            "SELECT timer_start, timer_pause_start, timer_paused_duration, isongoing, hasstarted FROM uo_game WHERE game_id=%d",
            self::GAME,
        ));
    }

    private static function resultLogCount(): int
    {
        return (int) DBQueryToValue(sprintf(
            "SELECT COUNT(*) FROM uo_event_log WHERE category='game' AND id1=%d AND description LIKE 'result: %%'",
            self::GAME,
        ));
    }

    private static function restore(): void
    {
        DBQuery("DELETE FROM uo_scorekeeper_token");
        DBQuery("DELETE FROM uo_scorekeeper_grant");
        DBQuery("UPDATE uo_season SET anonymous_scorekeeping=0, hide_time_on_scoresheet=0 WHERE season_id='HRN2026'");
        DBQuery(sprintf("DELETE FROM uo_scoresheet_history WHERE game=%d", self::GAME));
        DBQuery(sprintf("DELETE FROM uo_goal WHERE game=%d", self::GAME));
        DBQuery(sprintf("DELETE FROM uo_event_log WHERE category='game' AND id1=%d", self::GAME));
        DBQuery(sprintf(
            "UPDATE uo_game SET homescore=NULL, visitorscore=NULL, isongoing=0, hasstarted=0, time='2026-06-01 14:00:00',
                timer_start=NULL, timer_pause_start=NULL, timer_paused_duration=0 WHERE game_id=%d",
            self::GAME,
        ));
        self::flushQueryCaches();
    }

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

    /** @param array<int, string> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }
        return null;
    }

    /** @return array{0: string, 1: string, 2: array<int, string>} */
    private function get(string $path): array
    {
        return $this->request($path, 'GET', []);
    }

    /** @return array{0: string, 1: string, 2: array<int, string>} */
    private function post(string $path, array $fields): array
    {
        return $this->request($path, 'POST', ['Content-Type: application/x-www-form-urlencoded'], http_build_query($fields));
    }

    /** @return array{0: string, 1: string, 2: array<int, string>} */
    private function request(string $path, string $method, array $headers, string $content = ''): array
    {
        if ($this->cookies !== []) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(
                static fn($name, $value) => $name . '=' . $value,
                array_keys($this->cookies),
                $this->cookies,
            ));
        }
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $content,
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 20,
            ],
        ]);
        $body = file_get_contents($baseUrl . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $this->assertIsString($body, 'request failed: ' . $path);
        $this->assertDoesNotMatchRegularExpression('/(Fatal error|Warning|Notice|Deprecated|Parse error)<\/b>:/', $body);
        foreach ($responseHeaders as $header) {
            if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $m)) {
                $this->cookies[$m[1]] = $m[2];
            }
        }
        return [$responseHeaders[0] ?? '', $body, $responseHeaders];
    }
}
