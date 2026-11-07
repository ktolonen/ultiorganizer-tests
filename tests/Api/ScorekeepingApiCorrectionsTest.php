<?php

declare(strict_types=1);

use UltiorganizerHarness\Support\ScorekeepingApiTestCase;

/**
 * The scorekeeping writes that correct or steer a game in progress: the game
 * clock, deleting a team's last timeout or spirit stoppage, correcting a
 * goal's players, the game note, and the entry context's If-None-Match.
 */
final class ScorekeepingApiCorrectionsTest extends ScorekeepingApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $paths = self::request('GET', '/api/v1/openapi')['json']['paths'] ?? [];
        if (!isset($paths['/api/v1/games/{game_id}/clock'], $paths['/api/v1/games/{game_id}/note'])) {
            $this->markTestSkipped('The SUT has no clock or note endpoints.');
        }
        DBQuery("DELETE FROM uo_event_log WHERE category='game' AND source='comments' AND id1='701'");
    }

    protected function tearDown(): void
    {
        DBQuery("DELETE FROM uo_event_log WHERE category='game' AND source='comments' AND id1='701'");
        parent::tearDown();
    }

    /**
     * @param array<string, mixed>|null $body null sends no body
     * @param array<string, ?string> $extra headers that replace the defaults
     */
    private static function write(string $method, string $route, ?array $body, string $key, array $extra = []): array
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

    private static function clock(string $action, string $key, ?int $elapsed = null): array
    {
        $body = ['action' => $action];
        if ($elapsed !== null) {
            $body['elapsed'] = $elapsed;
        }
        return self::write('POST', 'clock', $body, $key);
    }

    private static function userAuth(): array
    {
        return ['Authorization' => 'Bearer ' . self::TOKEN_USER];
    }

    /** Home (301) has 802 and 803 on the game roster, away (300) 800 and 801. */
    private static function seedGoals(): void
    {
        DBQuery("INSERT INTO uo_played (player, game, num, accredited, captain) VALUES
            (802, 701, 7, 1, 0), (803, 701, 14, 1, 0), (800, 701, 8, 1, 0), (801, 701, 12, 1, 0)");
        DBQuery("INSERT INTO uo_goal (game, num, assist, scorer, time, homescore, visitorscore, ishomegoal, iscallahan) VALUES
            (701, 1, 802, 803, 60, 1, 0, 1, 0), (701, 2, 801, 800, 120, 1, 1, 0, 0)");
        DBQuery("UPDATE uo_game SET homescore=1, visitorscore=1, hasstarted=1, isongoing=1 WHERE game_id=701");
    }

    public function testClockRunsThroughStartPauseSetResumeAndReset(): void
    {
        $this->assertSame(['state' => 'not_started', 'elapsed' => 0], self::entryContext()['clock']);
        $tokenBefore = self::entryContext()['token'];

        $start = self::clock('start', 'c1');
        $this->assertSame(200, $start['status'], $start['raw']);
        $this->assertSame('running', $start['json']['data']['clock']['state']);
        $this->assertSame(['hasstarted' => '1', 'isongoing' => '1'], DBQueryToRowUncached("SELECT hasstarted, isongoing FROM uo_game WHERE game_id=701"));
        self::assertError(self::clock('start', 'c2'), 409, 'clock_running');
        self::assertError(self::clock('resume', 'c3'), 409, 'clock_not_paused');

        $this->assertSame(200, self::clock('pause', 'c4')['status']);
        self::assertError(self::clock('pause', 'c5'), 409, 'clock_not_running');
        $set = self::clock('set', 'c6', 1260);
        $this->assertSame(['state' => 'paused', 'elapsed' => 1260], $set['json']['data']['clock'], $set['raw']);
        $this->assertSame(['state' => 'paused', 'elapsed' => 1260], self::entryContext()['clock']);
        $resume = self::clock('resume', 'c7');
        $this->assertSame('running', $resume['json']['data']['clock']['state'], $resume['raw']);
        $this->assertGreaterThanOrEqual(1260, $resume['json']['data']['clock']['elapsed']);

        $actions = array_column(DBQueryToArrayUncached("SELECT action FROM uo_scoresheet_history WHERE game=701 AND target='timer' ORDER BY history_id"), 'action');
        $this->assertSame(['start', 'pause', 'update', 'resume'], $actions);

        $reset = self::clock('reset', 'c8');
        $this->assertSame(['state' => 'not_started', 'elapsed' => 0], $reset['json']['data']['clock'], $reset['raw']);
        $this->assertSame($tokenBefore, self::entryContext()['token'], 'the clock is outside the upload token');
    }

    public function testClockChecksTheBodyScoreFinalGameAndHiddenTimes(): void
    {
        self::assertError(self::write('POST', 'clock', ['action' => 'stop'], 'b1'), 400, 'invalid_body');
        self::assertError(self::write('POST', 'clock', ['action' => 'set'], 'b2'), 400, 'invalid_body');
        self::assertError(self::write('POST', 'clock', ['action' => 'start', 'elapsed' => 5], 'b3'), 400, 'invalid_body');

        DBQuery("UPDATE uo_game SET homescore=1, visitorscore=0, hasstarted=1, isongoing=1 WHERE game_id=701");
        self::assertError(self::clock('reset', 'b4'), 409, 'score_not_zero');
        $this->assertSame(['state' => 'stopped', 'elapsed' => 0], self::entryContext()['clock'], 'a game kept without a clock');

        DBQuery("UPDATE uo_game SET isongoing=0 WHERE game_id=701");
        self::assertError(self::clock('start', 'b5'), 409, 'game_final');

        DBQuery("UPDATE uo_game SET homescore=NULL, visitorscore=NULL, hasstarted=0 WHERE game_id=701");
        DBQuery("UPDATE uo_season SET hide_time_on_scoresheet=1 WHERE season_id='HRN2026'");
        self::assertError(self::clock('start', 'b6'), 409, 'times_hidden');
        $this->assertNull(self::entryContext()['clock']);
        $this->assertNull(DBQueryToValueUncached("SELECT timer_start FROM uo_game WHERE game_id=701"));
    }

    public function testOnlyATeamsLastTimeoutOrSpiritStoppageIsDeleted(): void
    {
        foreach ([[100, 'home'], [200, 'home'], [150, 'away']] as $i => [$time, $side]) {
            $this->assertSame(201, self::write('POST', 'timeouts', ['side' => $side, 'time' => $time], "t$i")['status']);
        }
        $this->assertSame(201, self::write('POST', 'spirit-stoppages', ['side' => 'away', 'time' => 300], 's1')['status']);
        $tokenBefore = self::entryContext()['token'];

        self::assertError(self::write('DELETE', 'timeouts/home/1', null, 'd1'), 409, 'timeout_changed');
        self::assertError(self::write('DELETE', 'timeouts/away/2', null, 'd2'), 409, 'timeout_changed');
        $deleted = self::write('DELETE', 'timeouts/home/2', null, 'd3');
        $this->assertSame(200, $deleted['status'], $deleted['raw']);
        $this->assertSame(['side' => 'home', 'num' => 2], $deleted['json']['data']['deleted']);
        $this->assertNotSame($tokenBefore, $deleted['json']['data']['token']);
        $this->assertSame(['home' => [['time' => 100]], 'away' => [['time' => 150]]], self::entryContext()['timeouts']);

        // The next timeout takes the freed number.
        $again = self::write('POST', 'timeouts', ['side' => 'home', 'time' => 250], 't9');
        $this->assertSame(2, $again['json']['data']['timeout']['num'] ?? null, $again['raw']);

        self::assertError(self::write('DELETE', 'spirit-stoppages/home/1', null, 'd4'), 409, 'spirit_stoppage_changed');
        $this->assertSame(200, self::write('DELETE', 'spirit-stoppages/away/1', null, 'd5')['status']);
        $this->assertSame(['home' => [], 'away' => []], self::entryContext()['spirit_stoppages']);

        $removed = DBQueryToArrayUncached("SELECT target, detail FROM uo_scoresheet_history WHERE game=701 AND action='remove' ORDER BY history_id");
        $this->assertSame(['timeout', 'spirit_timeout'], array_column($removed, 'target'));
        $this->assertSame(['num' => 2, 'time' => 200, 'home' => 1], json_decode($removed[0]['detail'], true));
    }

    public function testGoalCorrectionChangesPlayersButNotTheScore(): void
    {
        self::seedGoals();
        $stored = static fn() => DBQueryToRowUncached("SELECT scorer, assist, iscallahan, time, homescore, visitorscore, ishomegoal FROM uo_goal WHERE game=701 AND num=1");
        $before = $stored();

        $fixed = self::write('PATCH', 'goals/1', ['scorer_id' => 802, 'assist_id' => 803], 'g1');
        $this->assertSame(200, $fixed['status'], $fixed['raw']);
        $this->assertSame(
            ['num' => 1, 'side' => 'home', 'scorer_id' => 802, 'assist_id' => 803, 'callahan' => false, 'time' => 60, 'home_score' => 1, 'away_score' => 0],
            $fixed['json']['data']['goal'],
        );
        $this->assertSame(['scorer' => '802', 'assist' => '803'] + $before, $stored());
        $history = DBQueryToRowUncached("SELECT action, detail FROM uo_scoresheet_history WHERE game=701 AND target='goal'");
        $this->assertSame('update', $history['action']);
        $this->assertSame(['num' => 1, 'scorer' => 802, 'assist' => 803, 'score' => '1-0', 'callahan' => 0], json_decode($history['detail'], true));

        // A Callahan drops its assist in the same request; the fields not sent keep their value.
        $callahan = self::write('PATCH', 'goals/1', ['assist_id' => null, 'callahan' => true], 'g2');
        $this->assertSame([802, null, true], [$callahan['json']['data']['goal']['scorer_id'], $callahan['json']['data']['goal']['assist_id'], $callahan['json']['data']['goal']['callahan']], $callahan['raw']);

        $cases = [
            ['other side', ['scorer_id' => 800], 'scorer_id'],
            ['callahan assist', ['assist_id' => 803], 'assist_id'],
        ];
        foreach ($cases as $i => [$label, $body, $path]) {
            $response = self::write('PATCH', 'goals/1', $body, "bad-$i");
            self::assertError($response, 400, 'validation_failed');
            $this->assertSame($path, $response['json']['error']['details'][0]['path'] ?? null, $label . ': ' . $response['raw']);
        }
        self::assertError(self::write('PATCH', 'goals/1', [], 'bad-e'), 400, 'invalid_body');
        self::assertError(self::write('PATCH', 'goals/1', ['side' => 'away'], 'bad-s'), 400, 'invalid_body');
        self::assertError(self::write('PATCH', 'goals/9', ['scorer_id' => 802], 'bad-n'), 404, 'goal_not_found');

        // The result does not change, so a final game can be corrected.
        DBQuery("UPDATE uo_game SET isongoing=0 WHERE game_id=701");
        $final = self::write('PATCH', 'goals/2', ['assist_id' => null], 'g3');
        $this->assertSame(200, $final['status'], $final['raw']);
        $this->assertSame(['homescore' => '1', 'visitorscore' => '1', 'isongoing' => '0'], DBQueryToRowUncached("SELECT homescore, visitorscore, isongoing FROM uo_game WHERE game_id=701"));
    }

    public function testGameNoteNeedsAUserTokenAndIsAttributedToItsUser(): void
    {
        self::assertError(self::write('PUT', 'note', ['text' => 'Rain delay'], 'n1'), 403, 'user_token_required');
        $this->assertSame('', (string) DBQueryToValueUncached("SELECT comment FROM uo_comment WHERE type=4 AND id='701'"));

        $saved = self::write('PUT', 'note', ['text' => '  Rain delay  '], 'n2', self::userAuth());
        $this->assertSame(200, $saved['status'], $saved['raw']);
        $this->assertSame('Rain delay', $saved['json']['data']['note']);
        $this->assertSame('Rain delay', DBQueryToValueUncached("SELECT comment FROM uo_comment WHERE type=4 AND id='701'"));
        $this->assertSame(
            [['type' => 'comment_create', 'user_id' => 'admin']],
            DBQueryToArrayUncached("SELECT type, user_id FROM uo_event_log WHERE category='game' AND source='comments' AND id1='701'"),
        );
        $this->assertSame('admin', DBQueryToValueUncached("SELECT user_id FROM uo_scoresheet_history WHERE game=701 AND target='comment'"));

        $context = self::request('GET', '/api/v1/games/701/entry-context', self::auth(self::TOKEN_USER));
        $this->assertSame('Rain delay', $context['json']['data']['note'], $context['raw']);
        $this->assertNull(self::entryContext()['note'], 'an installation token does not read the note');

        // Replays answer from the receipt; an empty text clears the note.
        $replay = self::write('PUT', 'note', ['text' => '  Rain delay  '], 'n2', self::userAuth());
        $this->assertSame('true', $replay['headers']['idempotent-replay'] ?? null);
        $cleared = self::write('PUT', 'note', ['text' => ''], 'n3', self::userAuth());
        $this->assertNull($cleared['json']['data']['note'], $cleared['raw']);
        $this->assertSame(0, self::dbCount("SELECT COUNT(*) FROM uo_comment WHERE type=4 AND id='701' AND comment<>''"));

        self::assertError(self::write('PUT', 'note', ['text' => str_repeat('x', 4001)], 'n4', self::userAuth()), 400, 'invalid_body');
    }

    public function testEntryContextAnswersNotModifiedUntilSomethingChanges(): void
    {
        $first = self::get('/api/v1/games/701/entry-context');
        $etag = $first['headers']['etag'] ?? '';
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{64}-[0-9a-f]{32}"$/', $etag);

        $same = self::request('GET', '/api/v1/games/701/entry-context', self::auth() + ['If-None-Match' => $etag]);
        $this->assertSame(304, $same['status'], $same['raw']);
        $this->assertSame('', $same['raw']);
        $this->assertSame($etag, $same['headers']['etag'] ?? null);
        $listed = self::request('GET', '/api/v1/games/701/entry-context', self::auth() + ['If-None-Match' => '"other", W/' . $etag]);
        $this->assertSame(304, $listed['status']);

        // A running clock keeps the ETag while it ticks; pausing it changes it.
        $this->assertSame(200, self::clock('start', 'e1')['status']);
        $running = self::get('/api/v1/games/701/entry-context')['headers']['etag'] ?? '';
        $this->assertNotSame($etag, $running);
        sleep(1);
        $this->assertSame(304, self::request('GET', '/api/v1/games/701/entry-context', self::auth() + ['If-None-Match' => $running])['status']);
        $this->assertSame(200, self::clock('pause', 'e2')['status']);
        $paused = self::request('GET', '/api/v1/games/701/entry-context', self::auth() + ['If-None-Match' => $running]);
        $this->assertSame(200, $paused['status']);
        $this->assertSame('paused', $paused['json']['data']['clock']['state']);

        // A cap moves the ETag but not the token, and the upload accepts the ETag as If-Match.
        $this->assertSame(200, self::write('PUT', 'caps/half_cap', ['time' => 900, 'point_cap' => 8], 'e3')['status']);
        $capped = self::request('GET', '/api/v1/games/701/entry-context', self::auth() + ['If-None-Match' => $paused['headers']['etag']]);
        $this->assertSame(200, $capped['status']);
        $this->assertSame($paused['json']['data']['token'], $capped['json']['data']['token']);
        $doc = self::validDoc();
        $upload = self::put($doc, trim($capped['headers']['etag'], '"'), 'e4');
        $this->assertSame(200, $upload['status'], $upload['raw']);
    }

    public function testNewRoutesReplayAndNeedTheirMethods(): void
    {
        self::seedGoals();
        foreach ([
            ['PATCH', 'goals/1', ['assist_id' => null]],
            ['POST', 'clock', ['action' => 'start']],
        ] as $i => [$method, $route, $body]) {
            $first = self::write($method, $route, $body, "r$i");
            $this->assertSame(200, $first['status'], $first['raw']);
            $replay = self::write($method, $route, $body, "r$i");
            $this->assertSame('true', $replay['headers']['idempotent-replay'] ?? null, $route);
            $this->assertSame($first['json'], $replay['json'], $route);
        }
        $this->assertSame(1, self::dbCount("SELECT COUNT(*) FROM uo_scoresheet_history WHERE game=701 AND target='timer'"), 'a replay writes nothing');

        $get = self::write('GET', 'clock', null, 'm1');
        self::assertError($get, 405, 'method_not_allowed');
        $this->assertSame('POST', $get['headers']['allow'] ?? null);
        $post = self::write('POST', 'note', ['text' => 'x'], 'm2', self::userAuth());
        $this->assertSame('PUT', $post['headers']['allow'] ?? null);
        $this->assertSame('DELETE', self::write('GET', 'timeouts/home/1', null, 'm3')['headers']['allow'] ?? null);
        $this->assertSame(404, self::write('DELETE', 'timeouts/both/1', null, 'm4')['status']);
    }
}
