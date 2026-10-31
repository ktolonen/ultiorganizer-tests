<?php

declare(strict_types=1);

use UltiorganizerHarness\Support\LegacyApp;
use UltiorganizerHarness\Support\ScorekeepingApiTestCase;

/**
 * The live scorekeeping writes: goals, last-goal delete, timeouts, spirit
 * stoppages, score taps, one-side rosters, metadata and finalize.
 */
final class ScorekeepingApiLiveTest extends ScorekeepingApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $paths = self::request('GET', '/api/v1/openapi')['json']['paths'] ?? [];
        if (!isset($paths['/api/v1/games/{game_id}/goals'], $paths['/api/v1/games/{game_id}/finalize'])) {
            $this->markTestSkipped('The SUT has no live scorekeeping endpoints.');
        }
    }

    /**
     * @param array<string, mixed>|object|null $body null sends no body
     * @param array<string, ?string> $extra headers that replace the defaults
     */
    private static function live(string $method, string $route, array|object|null $body = null, string $key = 'live-1', array $extra = []): array
    {
        $headers = self::auth() + ['Idempotency-Key' => $key];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        foreach ($extra as $name => $value) {
            if ($value === null) {
                unset($headers[$name]);
            } else {
                $headers[$name] = $value;
            }
        }
        return self::request($method, '/api/v1/games/' . self::GAME . '/' . $route, $headers, $body === null ? null : json_encode($body));
    }

    private static function goal(string $side, ?int $scorer, ?int $assist, ?int $time, bool $callahan = false): array
    {
        return ['side' => $side, 'scorer_id' => $scorer, 'assist_id' => $assist, 'callahan' => $callahan, 'time' => $time];
    }

    /** Home (301) has 802 and 803 on the game roster, away (300) 800 and 801. */
    private static function seedRoster(): void
    {
        DBQuery("INSERT INTO uo_played (player, game, num, accredited, captain) VALUES
            (802, 701, 7, 1, 0), (803, 701, 14, 1, 0), (800, 701, 8, 1, 0), (801, 701, 12, 1, 0)");
    }

    private static function stored(): array
    {
        return [
            'goals' => self::dbCount("SELECT COUNT(*) FROM uo_goal WHERE game=701"),
            'played' => self::dbCount("SELECT COUNT(*) FROM uo_played WHERE game=701"),
            'timeouts' => self::dbCount("SELECT COUNT(*) FROM uo_timeout WHERE game=701"),
            'spirit' => self::dbCount("SELECT COUNT(*) FROM uo_spirit_timeout WHERE game=701"),
            'history' => self::dbCount("SELECT COUNT(*) FROM uo_scoresheet_history WHERE game=701"),
            'receipts' => self::dbCount("SELECT COUNT(*) FROM uo_api_receipt WHERE game=701"),
            'game' => DBQueryToRowUncached("SELECT homescore, visitorscore, hasstarted, isongoing, official FROM uo_game WHERE game_id=701"),
        ];
    }

    /** Send a live write on its own connection and return the socket, so the case can act while the request waits. */
    private static function startLive(string $method, string $route, array $body, string $key): mixed
    {
        $parts = parse_url(getenv('UO_BASE_URL') ?: 'http://127.0.0.1');
        $socket = fsockopen($parts['host'], $parts['port'] ?? 80, $errno, $error, 10);
        self::assertNotFalse($socket, "connect: $error");
        $json = (string) json_encode($body);
        fwrite($socket, "$method /api/v1/games/" . self::GAME . "/$route HTTP/1.1\r\nHost: {$parts['host']}\r\n"
            . "Authorization: Bearer " . self::TOKEN_INSTALL . "\r\nX-Scorekeeping-Token: " . self::LINK_GAME . "\r\n"
            . "Idempotency-Key: $key\r\nContent-Type: application/json\r\n"
            . "Content-Length: " . strlen($json) . "\r\nConnection: close\r\n\r\n" . $json);
        return $socket;
    }

    /** @return array{status: int, json: mixed} */
    private static function finishLive(mixed $socket): array
    {
        $response = (string) stream_get_contents($socket);
        fclose($socket);
        [$head, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        preg_match('/^HTTP\/\S+ (\d{3})/', $head, $m);
        if (stripos($head, 'transfer-encoding: chunked') !== false) {
            $decoded = '';
            while (preg_match('/^([0-9a-f]+)\r\n/i', $body, $c) && hexdec($c[1]) > 0) {
                $decoded .= substr($body, strlen($c[0]), (int) hexdec($c[1]));
                $body = substr($body, strlen($c[0]) + (int) hexdec($c[1]) + 2);
            }
            $body = $decoded;
        }
        return ['status' => (int) ($m[1] ?? 0), 'json' => json_decode($body, true)];
    }

    private static function score(): array
    {
        $row = DBQueryToRowUncached("SELECT homescore, visitorscore, hasstarted, isongoing FROM uo_game WHERE game_id=701");
        return array_map(static fn($v) => $v === null ? null : (int) $v, $row);
    }

    public function testGoalAppendNumbersScoresAndMovesTheToken(): void
    {
        self::seedRoster();
        $before = self::entryContext()['token'];

        $first = self::live('POST', 'goals', self::goal('home', 803, 802, 60));

        $this->assertSame(201, $first['status'], $first['raw']);
        $data = $first['json']['data'];
        $this->assertSame(['num' => 1, 'side' => 'home', 'scorer_id' => 803, 'assist_id' => 802, 'callahan' => false, 'time' => 60, 'home_score' => 1, 'away_score' => 0], $data['goal']);
        $this->assertSame(['home' => 1, 'away' => 0, 'state' => 'ongoing'], $data['result']);
        $this->assertNotSame($before, $data['token'], 'a goal append moves the upload token');
        $this->assertSame('"' . $data['token'] . '"', $first['headers']['etag'] ?? null);
        $this->assertSame($data['token'], self::entryContext()['token']);

        // An equal time is accepted; the server numbers the goal and keeps the running score.
        $second = self::live('POST', 'goals', self::goal('away', 800, null, 60, true), 'live-2');
        $this->assertSame(201, $second['status'], $second['raw']);
        $this->assertSame([2, 1, 1], [$second['json']['data']['goal']['num'], $second['json']['data']['goal']['home_score'], $second['json']['data']['goal']['away_score']]);
        $this->assertSame(['homescore' => 1, 'visitorscore' => 1, 'hasstarted' => 1, 'isongoing' => 1], self::score());

        $rows = DBQueryToArrayUncached("SELECT user_id, source, scorekeeper_token, api_token FROM uo_scoresheet_history WHERE game=701 AND target='goal'");
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(['anonymous', 'api', '920', '910'], [$row['user_id'], $row['source'], $row['scorekeeper_token'], $row['api_token']]);
        }
        // An upload with the token from before the goals is stale.
        self::assertError(self::put(self::validDoc(), $before), 409, 'stale_token');
    }

    public function testUserScopedTokenAttributesHistoryToItsUser(): void
    {
        self::seedRoster();
        $goal = self::live('POST', 'goals', self::goal('home', 803, 802, 60), 'user-1', ['Authorization' => 'Bearer ' . self::TOKEN_USER]);
        $this->assertSame(201, $goal['status'], $goal['raw']);

        $rows = DBQueryToArrayUncached("SELECT user_id, scorekeeper_token, api_token FROM uo_scoresheet_history WHERE game=701 AND target='goal'");
        $this->assertSame([['user_id' => 'admin', 'scorekeeper_token' => '920', 'api_token' => '914']], $rows);
    }

    public function testGoalChecksTimesAndPlayers(): void
    {
        self::seedRoster();
        $this->assertSame(201, self::live('POST', 'goals', self::goal('home', 803, 802, 120))['status']);
        $before = self::stored();

        $cases = [
            ['backwards', self::goal('away', 800, 801, 60), 'time'],
            ['other side', self::goal('home', 800, null, 180), 'scorer_id'],
            ['callahan assist', self::goal('away', 800, 801, 180, true), 'assist_id'],
            ['same player', self::goal('away', 800, 800, 180), 'assist_id'],
        ];
        foreach ($cases as $i => [$label, $body, $path]) {
            $response = self::live('POST', 'goals', $body, "bad-$i");
            self::assertError($response, 400, 'validation_failed');
            $this->assertSame($path, $response['json']['error']['details'][0]['path'] ?? null, $label . ': ' . $response['raw']);
        }
        $unknown = self::live('POST', 'goals', self::goal('home', 803, null, 180) + ['num' => 5], 'bad-x');
        self::assertError($unknown, 400, 'invalid_body');
        $this->assertSame('num', $unknown['json']['error']['details'][0]['path'] ?? null);
        $this->assertSame($before, self::stored());
    }

    public function testHiddenGoalTimesAreSynthesizedAndSuppliedOnesRefused(): void
    {
        self::seedRoster();
        DBQuery("UPDATE uo_season SET hide_time_on_scoresheet=1 WHERE season_id='HRN2026'");

        $supplied = self::live('POST', 'goals', self::goal('home', 803, 802, 50));
        self::assertError($supplied, 400, 'validation_failed');
        $this->assertSame('time', $supplied['json']['error']['details'][0]['path'] ?? null);

        $first = self::live('POST', 'goals', self::goal('home', 803, 802, null), 'live-2');
        $second = self::live('POST', 'goals', self::goal('away', 800, 801, null), 'live-3');

        $this->assertSame(201, $first['status'], $first['raw']);
        $this->assertNull($first['json']['data']['goal']['time']);
        $this->assertSame(201, $second['status'], $second['raw']);
        $this->assertSame(['1', '2'], array_column(DBQueryToArrayUncached("SELECT time FROM uo_goal WHERE game=701 ORDER BY num"), 'time'));
    }

    public function testAFinalGameRefusesGoalsAndScoreTaps(): void
    {
        self::seedRoster();
        DBQuery("UPDATE uo_game SET homescore=3, visitorscore=2, hasstarted=2, isongoing=0 WHERE game_id=701");
        $before = self::stored();

        self::assertError(self::live('POST', 'goals', self::goal('home', 803, 802, 60)), 409, 'game_final');
        self::assertError(self::live('POST', 'score-taps', ['side' => 'home', 'delta' => 1], 'live-2'), 409, 'game_final');
        $this->assertSame($before, self::stored());
    }

    public function testGoalKeepsAHigherTappedScore(): void
    {
        self::seedRoster();
        DBQuery("UPDATE uo_game SET homescore=0, visitorscore=3, hasstarted=1, isongoing=1 WHERE game_id=701");

        $response = self::live('POST', 'goals', self::goal('home', 803, 802, 60));

        $this->assertSame(201, $response['status'], $response['raw']);
        $this->assertSame(['home' => 1, 'away' => 3, 'state' => 'ongoing'], $response['json']['data']['result']);
    }

    public function testOnlyTheLastGoalCanBeDeleted(): void
    {
        self::seedRoster();
        $this->assertSame(201, self::live('POST', 'goals', self::goal('home', 803, 802, 60), 'g1')['status']);
        $this->assertSame(201, self::live('POST', 'goals', self::goal('away', 800, 801, 120), 'g2')['status']);

        self::assertError(self::live('DELETE', 'goals/1', null, 'd1'), 409, 'goal_changed');
        self::assertError(self::live('DELETE', 'goals/3', null, 'd2'), 409, 'goal_changed');
        $this->assertSame(2, self::stored()['goals']);

        $deleted = self::live('DELETE', 'goals/2', null, 'd3');

        $this->assertSame(200, $deleted['status'], $deleted['raw']);
        $this->assertSame(['num' => 2], $deleted['json']['data']['deleted']);
        $this->assertSame(['home' => 1, 'away' => 0, 'state' => 'ongoing'], $deleted['json']['data']['result'], 'the result follows the remaining goals');
        $this->assertSame(1, self::stored()['goals']);
    }

    public function testDuplicateGoalNumberIsRetriedOnceThenAConflict(): void
    {
        self::seedRoster();
        DBQuery("CREATE TABLE skapi_flag (x INT) ENGINE=MyISAM");
        $this->injectTrigger("CREATE TRIGGER skapi_once BEFORE INSERT ON uo_goal FOR EACH ROW
            BEGIN
                IF (SELECT COUNT(*) FROM skapi_flag) = 0 THEN
                    INSERT INTO skapi_flag VALUES (1);
                    SIGNAL SQLSTATE '23000' SET MYSQL_ERRNO=1062, MESSAGE_TEXT='injected duplicate';
                END IF;
            END");

        $retried = self::live('POST', 'goals', self::goal('home', 803, 802, 60));

        $this->assertSame(201, $retried['status'], $retried['raw']);
        $this->assertSame(1, self::dbCount("SELECT COUNT(*) FROM skapi_flag"), 'the first insert failed');
        $this->assertSame(1, self::stored()['goals']);

        DBQuery("DROP TRIGGER skapi_once");
        $this->injectTrigger("CREATE TRIGGER skapi_fail BEFORE INSERT ON uo_goal FOR EACH ROW
            SIGNAL SQLSTATE '23000' SET MYSQL_ERRNO=1062, MESSAGE_TEXT='injected duplicate'");
        $before = self::stored();

        self::assertError(self::live('POST', 'goals', self::goal('away', 800, 801, 120), 'live-2'), 409, 'goal_changed');
        $this->assertSame($before, self::stored());
    }

    public function testGoalAppendWaitsForABrowserGoalInFlight(): void
    {
        self::seedRoster();
        // A browser append is in flight: its goal row is written but not committed.
        // The insert's foreign key check holds a shared lock on the game row, so
        // the API waits at its game lock and its reads start after the commit.
        $browser = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_DATABASE);
        $browser->query("START TRANSACTION");
        $browser->query("INSERT INTO uo_goal (game, num, assist, scorer, time, homescore, visitorscore, ishomegoal, iscallahan)
            VALUES (701, 1, 802, 803, 60, 1, 0, 1, 0)");
        try {
            $socket = self::startLive('POST', 'goals', self::goal('away', 800, 801, 120), 'race');
            // Commit only once the API waits for the game lock.
            $waiting = 0;
            for ($i = 0; $i < 100 && $waiting === 0; $i++) {
                usleep(100000);
                $waiting = self::dbCount("SELECT COUNT(*) FROM information_schema.PROCESSLIST
                    WHERE ID<>CONNECTION_ID() AND INFO LIKE 'SELECT game_id FROM uo_game WHERE game_id=701 FOR UPDATE'");
            }
            $this->assertSame(1, $waiting, 'the API request waits on the browser goal');
            $browser->query("COMMIT");
        } finally {
            $browser->close();
        }
        $response = self::finishLive($socket);

        $this->assertSame(201, $response['status'], (string) json_encode($response['json']));
        $this->assertSame([2, 1, 1], [
            $response['json']['data']['goal']['num'] ?? null,
            $response['json']['data']['goal']['home_score'] ?? null,
            $response['json']['data']['goal']['away_score'] ?? null,
        ], 'the API numbered its goal after the browser one');
        $this->assertSame(2, self::stored()['goals']);
    }

    public function testAFatalErrorAnswersJsonAndRollsBack(): void
    {
        self::seedRoster();
        // So many spirit stoppage rows that building the resulting upload token,
        // after the goal is written, runs out of memory: a fatal error, which
        // ends the request the way a die() in a shared helper would, with the
        // transaction open.
        DBQuery("INSERT INTO uo_spirit_timeout (game, num, time, ishome) SELECT 701, 1, seq, 1 FROM seq_1_to_1500000");
        $before = self::stored();

        $response = self::live('POST', 'goals', self::goal('home', 803, 802, 60));
        $after = self::stored();
        DBQuery("DELETE FROM uo_spirit_timeout WHERE game=701");

        self::assertError($response, 500, 'internal_error');
        $this->assertSame('application/json; charset=utf-8', $response['headers']['content-type'] ?? null);
        $this->assertStringNotContainsString('Fatal error', $response['raw']);
        $this->assertSame($before, $after, 'the goal and the receipt were rolled back');
    }

    public function testEveryLiveWriteMovesAnOpenDesktopSheetsToken(): void
    {
        LegacyApp::loadLibFilesUsingProfile(['scoresheethistory.functions.php'], 'database_with_common');
        // The players' own numbers: adding a player to the game roster also sets uo_player.num.
        $entry = static fn(int $id, int $number) => ['player_id' => $id, 'number' => $number, 'captain' => false, 'spirit_captain' => false];
        $writes = [
            'roster' => ['PUT', 'rosters/home', ['team_id' => 301, 'players' => [$entry(802, 7), $entry(803, 14)]]],
            'metadata' => ['PATCH', 'metadata', ['official' => 'Official One']],
            'goal' => ['POST', 'goals', self::goal('home', 803, 802, 60)],
            'goal delete' => ['DELETE', 'goals/1', null],
            'timeout' => ['POST', 'timeouts', ['side' => 'home', 'time' => 100]],
            'spirit stoppage' => ['POST', 'spirit-stoppages', ['side' => 'home', 'time' => 110]],
            'score tap' => ['POST', 'score-taps', ['side' => 'home', 'delta' => 1]],
            'finalize' => ['POST', 'finalize', ['home' => 1, 'away' => 0]],
        ];
        $token = ScoresheetHistoryToken(self::GAME);
        foreach ($writes as $name => [$method, $route, $body]) {
            $response = self::live($method, $route, $body, "desktop-$name");
            $this->assertLessThan(300, $response['status'], "$name: " . $response['raw']);
            $next = ScoresheetHistoryToken(self::GAME);
            $this->assertNotSame($token, $next, "$name moves the token an open desktop sheet saves against");
            $token = $next;
        }
    }

    public function testTimeoutsStopAtThePoolLimit(): void
    {
        $limit = self::entryContext()['rules']['timeouts_per_team'];
        for ($i = 1; $i <= $limit; $i++) {
            $response = self::live('POST', 'timeouts', ['side' => 'home', 'time' => 100 * $i], "t$i");
            $this->assertSame(201, $response['status'], $response['raw']);
            $this->assertSame(['side' => 'home', 'num' => $i, 'time' => 100 * $i], $response['json']['data']['timeout']);
        }

        self::assertError(self::live('POST', 'timeouts', ['side' => 'home', 'time' => 2000], 'over'), 409, 'timeout_limit');
        $this->assertSame($limit, self::stored()['timeouts']);
        $this->assertSame(201, self::live('POST', 'timeouts', ['side' => 'away', 'time' => 2000], 'away')['status'], 'the limit is per team');
    }

    public function testSpiritStoppagesStopAtFourAndNeedSpiritStoppagesOn(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->assertSame(201, self::live('POST', 'spirit-stoppages', ['side' => 'away', 'time' => 10 * $i], "s$i")['status']);
        }
        self::assertError(self::live('POST', 'spirit-stoppages', ['side' => 'away', 'time' => 50], 's5'), 409, 'spirit_stoppage_limit');

        DBQuery("UPDATE uo_season SET spiritmode=0 WHERE season_id='HRN2026'");
        self::assertError(self::live('POST', 'spirit-stoppages', ['side' => 'home', 'time' => 50], 's6'), 409, 'spirit_stoppages_disabled');
        $this->assertSame(4, self::stored()['spirit']);
    }

    public function testScoreTapsMoveTheStoredScore(): void
    {
        $plus = self::live('POST', 'score-taps', ['side' => 'home', 'delta' => 1], 'tap-1');
        $this->assertSame(200, $plus['status'], $plus['raw']);
        $this->assertSame(['home' => 1, 'away' => 0, 'state' => 'ongoing'], $plus['json']['data']['result']);

        // Back at 0-0 before the clock starts, the game returns to not started.
        $minus = self::live('POST', 'score-taps', ['side' => 'home', 'delta' => -1], 'tap-2');
        $this->assertSame(200, $minus['status'], $minus['raw']);
        $this->assertNull($minus['json']['data']['result']);

        self::assertError(self::live('POST', 'score-taps', ['side' => 'home', 'delta' => -1], 'tap-3'), 409, 'score_out_of_range');
        $bad = self::live('POST', 'score-taps', ['side' => 'home', 'delta' => 2], 'tap-4');
        self::assertError($bad, 400, 'invalid_body');
        $this->assertSame('delta', $bad['json']['error']['details'][0]['path'] ?? null);
    }

    public function testRosterReplaceAddsRenumbersAndRemoves(): void
    {
        $entry = static fn(int $id, ?int $number, bool $captain = false, bool $spirit = false) => [
            'player_id' => $id, 'number' => $number, 'captain' => $captain, 'spirit_captain' => $spirit,
        ];
        $first = self::live('PUT', 'rosters/home', ['team_id' => 301, 'players' => [$entry(802, 7, true), $entry(803, null, false, true)]]);

        $this->assertSame(200, $first['status'], $first['raw']);
        $this->assertSame(['side' => 'home', 'players' => 2], $first['json']['data']['roster']);
        $this->assertSame(
            [['802', '7', '1', '0'], ['803', null, '0', '1']],
            array_map('array_values', DBQueryToArrayUncached("SELECT player, num, captain, spirit_captain FROM uo_played WHERE game=701 ORDER BY player")),
        );

        $second = self::live('PUT', 'rosters/home', ['team_id' => 301, 'players' => [$entry(803, 14, true)]], 'live-2');

        $this->assertSame(200, $second['status'], $second['raw']);
        $this->assertSame(
            [['803', '14', '1', '0']],
            array_map('array_values', DBQueryToArrayUncached("SELECT player, num, captain, spirit_captain FROM uo_played WHERE game=701 ORDER BY player")),
        );
        $this->assertSame(200, self::live('PUT', 'rosters/away', ['team_id' => 300, 'players' => [$entry(800, 8)]], 'live-3')['status']);
        $this->assertSame(2, self::stored()['played'], 'replacing one side leaves the other alone');
    }

    public function testRosterChecksTheTeamAndThePlayers(): void
    {
        $entry = static fn(int $id) => ['player_id' => $id, 'number' => 1, 'captain' => false, 'spirit_captain' => false];

        self::assertError(self::live('PUT', 'rosters/home', ['team_id' => 300, 'players' => []]), 409, 'fixture_changed');
        $other = self::live('PUT', 'rosters/home', ['team_id' => 301, 'players' => [$entry(800)]], 'live-2');
        self::assertError($other, 400, 'validation_failed');
        $this->assertSame('players[0].player_id', $other['json']['error']['details'][0]['path'] ?? null);

        DBQuery("UPDATE uo_season SET require_accreditation=1 WHERE season_id='HRN2026'");
        DBQuery("UPDATE uo_player SET accredited=0 WHERE player_id=803");
        $unaccredited = self::live('PUT', 'rosters/home', ['team_id' => 301, 'players' => [$entry(802), $entry(803)]], 'live-3');
        self::assertError($unaccredited, 400, 'validation_failed');
        $this->assertSame('players[1].player_id', $unaccredited['json']['error']['details'][0]['path'] ?? null);
        $this->assertSame(0, self::stored()['played']);
    }

    public function testRosterRefusesARepeatedAndAnUnknownPlayer(): void
    {
        $entry = static fn(int $id) => ['player_id' => $id, 'number' => 1, 'captain' => false, 'spirit_captain' => false];

        $response = self::live('PUT', 'rosters/home', ['team_id' => 301, 'players' => [$entry(802), $entry(802), $entry(999999)]]);

        self::assertError($response, 400, 'validation_failed');
        $this->assertSame(
            [['players[1].player_id', 'The player is listed more than once.'], ['players[2].player_id', 'The player does not exist.']],
            array_map(static fn(array $d) => [$d['path'], $d['message']], $response['json']['error']['details']),
        );
        $this->assertSame(0, self::stored()['played']);
    }

    public function testRosterKeepsPlayersTheGoalsAndDefencesReferTo(): void
    {
        self::seedRoster();
        $this->assertSame(201, self::live('POST', 'goals', self::goal('home', 803, null, 60), 'g1')['status']);
        DBQuery("INSERT INTO uo_defense (game, num, author, time, iscallahan, iscaught, ishomedefense) VALUES (701, 1, 802, 30, 0, 0, 1)");
        $entry = static fn(int $id) => ['player_id' => $id, 'number' => 1, 'captain' => false, 'spirit_captain' => false];
        $before = self::stored();

        $response = self::live('PUT', 'rosters/home', ['team_id' => 301, 'players' => []], 'r1');

        self::assertError($response, 409, 'player_referenced');
        $this->assertSame([802, 803], $response['json']['error']['details']['player_ids'] ?? null);
        $this->assertSame($before, self::stored());
        // Contrast: keeping both lets the roster change otherwise.
        $this->assertSame(200, self::live('PUT', 'rosters/home', ['team_id' => 301, 'players' => [$entry(802), $entry(803)]], 'r2')['status']);
    }

    public function testMetadataChangesOnlyTheFieldsSent(): void
    {
        DBQuery("UPDATE uo_game SET official='Official One', halftime=900 WHERE game_id=701");

        $offence = self::live('PATCH', 'metadata', ['starting_offence' => 'away']);
        $this->assertSame(200, $offence['status'], $offence['raw']);
        $this->assertSame(['starting_offence' => 'away', 'halftime' => 900, 'official' => 'Official One'], self::entryContext()['metadata']);

        $this->assertSame(200, self::live('PATCH', 'metadata', ['official' => null, 'halftime' => 1200], 'live-2')['status']);
        $this->assertSame(['starting_offence' => 'away', 'halftime' => 1200, 'official' => null], self::entryContext()['metadata']);

        self::assertError(self::live('PATCH', 'metadata', new \stdClass(), 'live-3'), 400, 'invalid_body');
        $unknown = self::live('PATCH', 'metadata', ['comment' => 'x'], 'live-4');
        self::assertError($unknown, 400, 'invalid_body');
        $this->assertSame('comment', $unknown['json']['error']['details'][0]['path'] ?? null);
    }

    public function testFinalizeNeedsTheStoredScore(): void
    {
        self::assertError(self::live('POST', 'finalize', ['home' => 0, 'away' => 0], 'f0'), 409, 'score_changed');
        DBQuery("UPDATE uo_game SET homescore=2, visitorscore=1, hasstarted=1, isongoing=1, timer_start=NOW() WHERE game_id=701");

        self::assertError(self::live('POST', 'finalize', ['home' => 2, 'away' => 2], 'f1'), 409, 'score_changed');
        $this->assertSame(['homescore' => 2, 'visitorscore' => 1, 'hasstarted' => 1, 'isongoing' => 1], self::score());

        $final = self::live('POST', 'finalize', ['home' => 2, 'away' => 1], 'f2');

        $this->assertSame(200, $final['status'], $final['raw']);
        $this->assertSame(['home' => 2, 'away' => 1, 'state' => 'final'], $final['json']['data']['result']);
        $this->assertSame(['homescore' => 2, 'visitorscore' => 1, 'hasstarted' => 2, 'isongoing' => 0], self::score());
        $this->assertNull(DBQueryToValueUncached("SELECT timer_start FROM uo_game WHERE game_id=701"), 'finalizing resets the clock');
    }

    public function testFinalizeRollsBackTheStandingsWithTheResult(): void
    {
        // The pool standings and moves are recomputed inside the transaction; a
        // failure after them must leave neither the result nor the ranks behind.
        DBQuery("UPDATE uo_game SET homescore=20, visitorscore=0, hasstarted=1, isongoing=1 WHERE game_id=701");
        $rank = static fn(int $team) => (int) DBQueryToValueUncached("SELECT activerank FROM uo_team_pool WHERE team=$team AND pool=200");
        $ranks = [$rank(300), $rank(301)];
        $this->assertSame([1, 2], $ranks, 'fixture: team 300 leads before the game counts');
        $this->injectTrigger("CREATE TRIGGER skapi_fail BEFORE INSERT ON uo_api_receipt FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected failure'");

        self::assertError(self::live('POST', 'finalize', ['home' => 20, 'away' => 0]), 500, 'internal_error');

        $this->assertSame(1, self::score()['isongoing']);
        $this->assertSame($ranks, [$rank(300), $rank(301)], 'the recomputed ranks rolled back with the result');
        DBQuery("DROP TRIGGER skapi_fail");
        $this->assertSame(200, self::live('POST', 'finalize', ['home' => 20, 'away' => 0])['status']);
        $this->assertSame([1, 2], [$rank(301), $rank(300)], 'contrast: committed, the final game counts');
    }

    /** @return array<string, array{string}> */
    public static function liveWrites(): array
    {
        return array_combine(
            ['goal', 'delete', 'timeout', 'spirit stoppage', 'score tap', 'roster', 'metadata', 'finalize'],
            [['goal'], ['delete'], ['timeout'], ['spirit'], ['tap'], ['roster'], ['metadata'], ['finalize']],
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('liveWrites')]
    public function testEveryLiveWriteReplaysWithoutWritingAndRefusesAReusedKey(string $write): void
    {
        self::seedRoster();
        if ($write === 'delete') {
            $this->assertSame(201, self::live('POST', 'goals', self::goal('home', 803, 802, 60), 'setup')['status']);
        }
        if ($write === 'finalize') {
            DBQuery("UPDATE uo_game SET homescore=1, visitorscore=0, hasstarted=1, isongoing=1 WHERE game_id=701");
        }
        $roster = static fn(array $ids) => ['team_id' => 301, 'players' => array_map(
            static fn(int $id) => ['player_id' => $id, 'number' => 5, 'captain' => false, 'spirit_captain' => false],
            $ids,
        )];
        [$method, $route, $body, $otherRoute, $otherBody] = match ($write) {
            'goal' => ['POST', 'goals', self::goal('home', 803, 802, 60), 'goals', self::goal('home', 802, 803, 60)],
            'delete' => ['DELETE', 'goals/1', null, 'goals/2', null],
            'timeout' => ['POST', 'timeouts', ['side' => 'home', 'time' => 100], 'timeouts', ['side' => 'home', 'time' => 101]],
            'spirit' => ['POST', 'spirit-stoppages', ['side' => 'home', 'time' => 100], 'spirit-stoppages', ['side' => 'away', 'time' => 100]],
            'tap' => ['POST', 'score-taps', ['side' => 'home', 'delta' => 1], 'score-taps', ['side' => 'away', 'delta' => 1]],
            'roster' => ['PUT', 'rosters/home', $roster([802, 803]), 'rosters/home', $roster([802])],
            'metadata' => ['PATCH', 'metadata', ['official' => 'First'], 'metadata', ['official' => 'Second']],
            'finalize' => ['POST', 'finalize', ['home' => 1, 'away' => 0], 'finalize', ['home' => 1, 'away' => 1]],
        };

        $first = self::live($method, $route, $body, 'replay');
        $this->assertContains($first['status'], [200, 201], $first['raw']);
        $stored = self::stored();
        $replay = self::live($method, $route, $body, 'replay');

        $this->assertSame($first['status'], $replay['status'], $replay['raw']);
        $this->assertSame('true', $replay['headers']['idempotent-replay'] ?? null);
        $this->assertSame($first['json'], $replay['json']);
        $this->assertSame($stored, self::stored(), 'a replay writes nothing');

        self::assertError(self::live($method, $otherRoute, $otherBody, 'replay'), 409, 'idempotency_conflict');
        $this->assertSame($stored, self::stored());
    }

    public function testLiveWritesRefuseAForfeitAReadOnlyEventAndAnUncoveredGame(): void
    {
        self::seedRoster();
        $goal = self::goal('home', 803, 802, 60);

        DBQuery("UPDATE uo_game SET forfeit=1 WHERE game_id=701");
        self::assertError(self::live('POST', 'goals', $goal, 'a'), 409, 'forfeit');
        DBQuery("UPDATE uo_game SET forfeit=0 WHERE game_id=701");

        DBQuery("UPDATE uo_season SET event_readonly=1 WHERE season_id='HRN2026'");
        self::assertError(self::live('POST', 'goals', $goal, 'b'), 403, 'event_readonly');
        DBQuery("UPDATE uo_season SET event_readonly=0 WHERE season_id='HRN2026'");

        self::assertError(self::live('POST', 'goals', $goal, 'c', ['X-Scorekeeping-Token' => self::LINK_OTHER_GAME]), 403, 'game_not_covered');
        self::assertError(self::live('POST', 'goals', $goal, 'd', ['Authorization' => 'Bearer ' . self::TOKEN_USER, 'X-Scorekeeping-Token' => self::LINK_OTHER_GAME]), 403, 'game_not_covered');
        self::assertError(self::live('POST', 'goals', $goal, 'e', ['Idempotency-Key' => null]), 400, 'idempotency_key_required');
        $this->assertSame(0, self::stored()['goals']);
        $this->assertSame(201, self::live('POST', 'goals', $goal, 'f', ['X-Scorekeeping-Token' => self::LINK_FIELD])['status'], 'contrast: the field link may write');
    }

    public function testWrongMethodIsRefusedWithAllow(): void
    {
        $get = self::get('/api/v1/games/701/goals');
        self::assertError($get, 405, 'method_not_allowed');
        $this->assertSame('POST', $get['headers']['allow'] ?? null);

        $put = self::live('PUT', 'finalize', ['home' => 0, 'away' => 0]);
        self::assertError($put, 405, 'method_not_allowed');
        $this->assertSame('POST', $put['headers']['allow'] ?? null);

        $delete = self::live('POST', 'goals/1', null);
        $this->assertSame('DELETE', $delete['headers']['allow'] ?? null);
    }

    /** Creates a trigger, skipping the case when the database user may not. */
    private function injectTrigger(string $sql): void
    {
        try {
            DBQuery($sql);
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1227 || (int) $e->getCode() === 1142) {
                $this->markTestSkipped('The database user cannot create triggers: ' . $e->getMessage());
            }
            throw $e;
        }
    }
}
