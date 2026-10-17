<?php

declare(strict_types=1);

use UltiorganizerHarness\Support\LegacyApp;
use UltiorganizerHarness\Support\ScorekeepingApiTestCase;

/** PUT /api/v1/games/{id}/game-data: preconditions, validation, writes, idempotency and attribution. */
final class ScorekeepingApiUploadTest extends ScorekeepingApiTestCase
{
    private function upload(array $doc, string $key = 'key-1'): array
    {
        return self::put($doc, self::entryContext()['token'], $key);
    }

    /** Counts of everything an upload writes, to prove a refusal wrote nothing. */
    private static function stored(): array
    {
        return [
            'goals' => self::dbCount("SELECT COUNT(*) FROM uo_goal WHERE game=701"),
            'played' => self::dbCount("SELECT COUNT(*) FROM uo_played WHERE game=701"),
            'timeouts' => self::dbCount("SELECT COUNT(*) FROM uo_timeout WHERE game=701"),
            'history' => self::dbCount("SELECT COUNT(*) FROM uo_scoresheet_history WHERE game=701"),
            'receipts' => self::dbCount("SELECT COUNT(*) FROM uo_api_receipt WHERE game=701"),
            'started' => self::dbCount("SELECT hasstarted FROM uo_game WHERE game_id=701"),
        ];
    }

    public function testPreconditions(): void
    {
        $doc = self::validDoc();
        $token = self::entryContext()['token'];
        $before = self::stored();

        self::assertError(self::put($doc, null), 428, 'precondition_required');
        self::assertError(self::put($doc, $token, '', ['Idempotency-Key' => null]), 400, 'idempotency_key_required');
        self::assertError(self::put($doc, $token, str_repeat('k', 65)), 400, 'invalid_idempotency_key');
        self::assertError(self::put($doc, $token, "schl\u{fc}ssel"), 400, 'invalid_idempotency_key');
        self::assertError(self::put($doc, $token, 'key-1', ['Content-Type' => 'text/plain']), 415, 'unsupported_media_type');
        self::assertError(self::put($doc, $token, 'key-1', [], '{not json'), 400, 'invalid_json');
        self::assertError(self::put($doc, $token, 'key-1', [], json_encode($doc + ['padding' => str_repeat('x', 300 * 1024)])), 413, 'payload_too_large');
        self::assertError(self::put($doc, '*'), 409, 'stale_token');
        $this->assertSame($before, self::stored(), 'refusals write nothing');
    }

    public function testStaleTokenIsRefusedWithoutRevealingTheCurrentOne(): void
    {
        $stale = self::entryContext()['token'];
        DBQuery("UPDATE uo_game SET official='Changed elsewhere' WHERE game_id=701");
        $current = self::entryContext()['token'];
        $before = self::stored();

        $response = self::put(self::validDoc(), $stale);

        self::assertError($response, 409, 'stale_token');
        $this->assertStringNotContainsString($current, $response['raw']);
        $this->assertArrayNotHasKey('etag', $response['headers']);
        $this->assertSame($before, self::stored());
        $this->assertSame(200, self::put(self::validDoc(), $current, 'key-2')['status'], 'the refetched token works');
    }

    public function testUnknownMissingAndMistypedFieldsAreRefusedWithPaths(): void
    {
        $doc = self::validDoc();
        $doc['extra'] = 1;
        unset($doc['halftime']);
        $doc['goals'][1]['scorer'] = 800;
        $doc['rosters']['home'][0]['number'] = '7';

        $response = $this->upload($doc);

        self::assertError($response, 400, 'invalid_document');
        $paths = array_column($response['json']['error']['details'], 'message', 'path');
        $this->assertSame('Unknown field.', $paths['extra']);
        $this->assertSame('Required field is missing.', $paths['halftime']);
        $this->assertSame('Unknown field.', $paths['goals[1].scorer']);
        $this->assertArrayHasKey('rosters.home[0].number', $paths);
        $this->assertSame(0, self::stored()['goals']);
    }

    public function testFixtureChange(): void
    {
        $doc = self::validDoc();
        [$doc['home_team_id'], $doc['away_team_id']] = [300, 301];
        $before = self::stored();

        self::assertError($this->upload($doc), 409, 'fixture_changed');
        $this->assertSame($before, self::stored());
    }

    public function testForfeitedGameRefusesTheUpload(): void
    {
        DBQuery("UPDATE uo_game SET forfeit=1 WHERE game_id=701");
        $before = self::stored();

        self::assertError($this->upload(self::validDoc()), 409, 'forfeit');
        $this->assertSame($before, self::stored());
    }

    public function testRetainedDefensePlayerMustStayOnTheRoster(): void
    {
        DBQuery("INSERT INTO uo_defense (game, num, author, time, iscallahan, iscaught, ishomedefense) VALUES (701, 1, 802, 70, 0, 1, 1)");
        $doc = self::validDoc();
        $doc['rosters']['home'] = [$doc['rosters']['home'][1]];
        $doc['goals'] = [];
        $doc['result'] = null;
        $before = self::stored();

        $response = $this->upload($doc);

        self::assertError($response, 409, 'retained_defense');
        $this->assertSame([802], $response['json']['error']['details']['player_ids']);
        $this->assertSame($before, self::stored());
        // Contrast: keeping the defender on the roster saves, and the defense row survives.
        $this->assertSame(200, $this->upload(self::validDoc(), 'key-2')['status']);
        $this->assertSame(1, self::dbCount("SELECT COUNT(*) FROM uo_defense WHERE game=701 AND author=802"));
    }

    public function testResultMustMatchTheGoals(): void
    {
        $mismatch = self::validDoc();
        $mismatch['result'] = ['home' => 3, 'away' => 1, 'state' => 'final'];
        $nullResult = self::validDoc();
        $nullResult['result'] = null;
        $before = self::stored();

        $response = $this->upload($mismatch);
        self::assertError($response, 400, 'validation_failed');
        $this->assertSame('result', $response['json']['error']['details'][0]['path']);
        $response = $this->upload($nullResult);
        self::assertError($response, 400, 'validation_failed');
        $this->assertSame('result', $response['json']['error']['details'][0]['path']);
        $this->assertSame($before, self::stored());
        // Contrast: a result without goals is the result-only form and saves.
        $resultOnly = self::validDoc();
        $resultOnly['goals'] = [];
        $this->assertSame(200, $this->upload($resultOnly, 'key-2')['status']);
    }

    public function testPlayersMustBelongToTheirSideAndTheRoster(): void
    {
        $wrongTeam = self::validDoc();
        $wrongTeam['rosters']['home'][1]['player_id'] = 800;
        $offRoster = self::validDoc();
        $offRoster['goals'][1]['assist_id'] = 803;
        $callahanAssist = self::validDoc();
        $callahanAssist['goals'][2]['assist_id'] = 803;
        $before = self::stored();

        $this->assertContains('rosters.home[1].player_id', array_column($this->upload($wrongTeam)['json']['error']['details'], 'path'));
        $this->assertSame('goals[1].assist_id', $this->upload($offRoster)['json']['error']['details'][0]['path']);
        $this->assertSame('goals[2].assist_id', $this->upload($callahanAssist)['json']['error']['details'][0]['path']);
        $this->assertSame($before, self::stored());
    }

    public function testAccreditationIsEnforced(): void
    {
        DBQuery("UPDATE uo_season SET require_accreditation=1 WHERE season_id='HRN2026'");
        DBQuery("UPDATE uo_player SET accredited=0 WHERE player_id=803");
        $before = self::stored();

        $response = $this->upload(self::validDoc());

        self::assertError($response, 400, 'validation_failed');
        $this->assertSame('rosters.home[1].player_id', $response['json']['error']['details'][0]['path']);
        $this->assertSame($before, self::stored());
        // Contrast: accredited again, the same document saves.
        DBQuery("UPDATE uo_player SET accredited=1 WHERE player_id=803");
        $this->assertSame(200, $this->upload(self::validDoc(), 'key-2')['status']);
    }

    public function testBoundsFromStorage(): void
    {
        $cases = [
            'jersey number' => [static function (array &$d): void {
                $d['rosters']['home'][0]['number'] = 256;
            }, 'rosters.home[0].number'],
            'goal time' => [static function (array &$d): void {
                $d['goals'][0]['time'] = 32768;
            }, 'goals[0].time'],
            'official' => [static function (array &$d): void {
                $d['official'] = str_repeat('x', 51);
            }, 'official'],
            'too many timeouts' => [static function (array &$d): void {
                $d['timeouts']['home'] = array_fill(0, 20, ['time' => 5]);
            }, 'timeouts.home'],
            'time going backwards' => [static function (array &$d): void {
                $d['goals'][1]['time'] = 10;
            }, 'goals[1].time'],
        ];
        foreach ($cases as $name => [$mutate, $path]) {
            $doc = self::validDoc();
            $mutate($doc);
            $response = $this->upload($doc, 'key-' . md5($name));
            $this->assertSame(400, $response['status'], $name . ': ' . $response['raw']);
            $this->assertSame($path, $response['json']['error']['details'][0]['path'], $name);
        }
        $this->assertSame(0, self::stored()['goals']);
    }

    public function testHiddenGoalTimesAreSynthesizedAndSuppliedOnesRefused(): void
    {
        DBQuery("UPDATE uo_season SET hide_time_on_scoresheet=1 WHERE season_id='HRN2026'");
        $supplied = self::validDoc();
        $hidden = self::validDoc();
        foreach ($hidden['goals'] as &$goal) {
            $goal['time'] = null;
        }
        unset($goal);

        self::assertError($this->upload($supplied), 400, 'validation_failed');
        $this->assertSame(200, $this->upload($hidden, 'key-2')['status']);
        $this->assertSame([1, 2, 3], array_map('intval', array_column(DBQueryToArrayUncached("SELECT time FROM uo_goal WHERE game=701 ORDER BY num"), 'time')));
    }

    public function testUploadReplacesTheSheetAndRecordsAttribution(): void
    {
        $doc = self::validDoc();
        $before = self::entryContext();

        $response = $this->upload($doc);

        $this->assertSame(200, $response['status'], $response['raw']);
        $token = $response['json']['data']['token'];
        $this->assertNotSame($before['token'], $token);
        $this->assertSame('"' . $token . '"', $response['headers']['etag'] ?? null);
        $this->assertArrayNotHasKey('idempotent-replay', $response['headers']);
        $this->assertSame(['roster_home' => 2, 'roster_away' => 2, 'goals' => 3, 'timeouts' => 1, 'spirit_stoppages' => 1], $response['json']['data']['counts']);

        $after = self::entryContext();
        $this->assertSame($token, $after['token'], 'the response token is what the next read shows');
        $this->assertSame(['home' => 2, 'away' => 1, 'state' => 'ongoing'], $after['result']);
        $this->assertSame(
            [[1, 'home', 803, 802, false, 60, 1, 0], [2, 'away', 800, 801, false, 120, 1, 1], [3, 'home', 802, null, true, 180, 2, 1]],
            array_map(static fn(array $g) => [$g['num'], $g['side'], $g['scorer_id'], $g['assist_id'], $g['callahan'], $g['time'], $g['home_score'], $g['away_score']], $after['goals']),
        );
        $this->assertSame([['player_id' => 802, 'number' => 7, 'captain' => true, 'spirit_captain' => false], ['player_id' => 803, 'number' => 14, 'captain' => false, 'spirit_captain' => false]], $after['home']['roster']);
        $this->assertSame([['player_id' => 800, 'number' => 8, 'captain' => false, 'spirit_captain' => false], ['player_id' => 801, 'number' => 12, 'captain' => false, 'spirit_captain' => true]], $after['away']['roster']);
        $this->assertSame(['home' => [['time' => 300]], 'away' => []], $after['timeouts']);
        $this->assertSame(['home' => [['time' => 400]], 'away' => []], $after['spirit_stoppages']);
        $this->assertSame(['starting_offence' => 'home', 'halftime' => 1800, 'official' => 'Official One'], $after['metadata']);

        // Every history row is the app's: anonymous, source api, the link and the app token.
        $rows = DBQueryToArrayUncached("SELECT user_id, source, scorekeeper_token, api_token, has_snapshot FROM uo_scoresheet_history WHERE game=701");
        $this->assertNotSame([], $rows);
        foreach ($rows as $row) {
            $this->assertSame(['anonymous', 'api', '920', '910'], [$row['user_id'], $row['source'], $row['scorekeeper_token'], $row['api_token']]);
        }
        $this->assertSame(1, self::dbCount("SELECT COUNT(*) FROM uo_scoresheet_history WHERE game=701 AND has_snapshot=1"));
        $this->assertSame(1, self::dbCount("SELECT COUNT(*) FROM uo_api_receipt WHERE game=701 AND api_token=910 AND scorekeeper_token=920 AND idempotency_key='key-1' AND status=200"));
        $this->assertSame(0, self::dbCount("SELECT COUNT(*) FROM uo_comment WHERE type=4 AND id='701'"), 'the upload never writes the game note');
    }

    public function testSecondUploadReplacesWhatTheFirstWrote(): void
    {
        $first = $this->upload(self::validDoc());
        $doc = self::validDoc();
        $doc['goals'] = [$doc['goals'][1]];
        $doc['result'] = ['home' => 0, 'away' => 1, 'state' => 'ongoing'];
        $doc['rosters']['home'] = [$doc['rosters']['home'][1]];
        $doc['timeouts'] = ['home' => [], 'away' => []];
        $doc['starting_offence'] = null;

        $second = self::put($doc, $first['json']['data']['token'], 'key-2');

        $this->assertSame(200, $second['status'], $second['raw']);
        $after = self::entryContext();
        $this->assertCount(1, $after['goals']);
        $this->assertSame([803], array_column($after['home']['roster'], 'player_id'));
        $this->assertSame(['home' => [], 'away' => []], $after['timeouts']);
        $this->assertNull($after['metadata']['starting_offence']);
        $this->assertSame(1, self::dbCount("SELECT COUNT(*) FROM uo_goal WHERE game=701"));
        $this->assertSame(3, self::dbCount("SELECT COUNT(*) FROM uo_played WHERE game=701"));
    }

    public function testFinalUploadFinalizesAndClearingResetsTheGame(): void
    {
        $doc = self::validDoc();
        $doc['result']['state'] = 'final';
        $final = $this->upload($doc);

        $this->assertSame(200, $final['status'], $final['raw']);
        $this->assertSame(['home' => 2, 'away' => 1, 'state' => 'final'], self::entryContext()['result']);

        $clear = self::validDoc();
        $clear['goals'] = [];
        $clear['result'] = null;
        $cleared = self::put($clear, $final['json']['data']['token'], 'key-2');

        $this->assertSame(200, $cleared['status'], $cleared['raw']);
        $this->assertNull(self::entryContext()['result']);
    }

    public function testFinalToOngoingRecomputesTheCachedStandings(): void
    {
        // Result-only 20-0 for the home team: it leads the pool once final, and stops leading once ongoing.
        $doc = self::validDoc();
        $doc['goals'] = [];
        $doc['result'] = ['home' => 20, 'away' => 0, 'state' => 'final'];
        $rank = static fn(int $team) => (int) DBQueryToValueUncached("SELECT activerank FROM uo_team_pool WHERE team=$team AND pool=200");
        $final = $this->upload($doc);
        $this->assertSame(200, $final['status'], $final['raw']);
        $this->assertSame([1, 2], [$rank(301), $rank(300)], 'the final game counts');

        $doc['result']['state'] = 'ongoing';
        $ongoing = self::put($doc, $final['json']['data']['token'], 'key-2');

        $this->assertSame(200, $ongoing['status'], $ongoing['raw']);
        $this->assertSame([1, 2], [$rank(300), $rank(301)], 'an ongoing game no longer counts');
    }

    public function testReplayReturnsTheStoredAnswerAndWritesNothing(): void
    {
        $doc = self::validDoc();
        $token = self::entryContext()['token'];
        $first = self::put($doc, $token);
        $stored = self::stored();
        // The game moves on: a replay must not care about the token or the new content.
        DBQuery("UPDATE uo_game SET official='Moved on' WHERE game_id=701");

        $replay = self::put($doc, $token);

        $this->assertSame(200, $replay['status'], $replay['raw']);
        $this->assertSame('true', $replay['headers']['idempotent-replay'] ?? null);
        $this->assertSame($first['json'], $replay['json']);
        $this->assertSame('"' . $first['json']['data']['token'] . '"', $replay['headers']['etag'] ?? null);
        $this->assertSame($stored, self::stored());
        $this->assertSame('Moved on', DBQueryToValueUncached("SELECT official FROM uo_game WHERE game_id=701"));
    }

    public function testSameKeyWithAnotherBodyIsAConflict(): void
    {
        $token = self::entryContext()['token'];
        $first = self::put(self::validDoc(), $token);
        $other = self::validDoc();
        $other['official'] = 'Somebody Else';
        $stored = self::stored();

        self::assertError(self::put($other, $first['json']['data']['token']), 409, 'idempotency_conflict');
        $this->assertSame($stored, self::stored());
        $this->assertSame('Official One', DBQueryToValueUncached("SELECT official FROM uo_game WHERE game_id=701"));
        // Another key is a new request.
        $this->assertSame(200, self::put($other, $first['json']['data']['token'], 'key-2')['status']);
    }

    public function testUploadIsRestorableWithHistoryDisabled(): void
    {
        DBQuery("INSERT INTO uo_setting (name, value) VALUES ('DisableScoresheetHistory', 'true')");
        DBQuery("UPDATE uo_game SET official='Before' WHERE game_id=701");
        $this->assertSame(200, $this->upload(self::validDoc())['status']);

        $rows = DBQueryToArrayUncached("SELECT history_id, has_snapshot FROM uo_scoresheet_history WHERE game=701");
        $this->assertCount(1, $rows, 'only the forced snapshot is recorded while history is disabled');
        $this->assertSame('1', $rows[0]['has_snapshot']);
        $this->assertSame(3, self::dbCount("SELECT COUNT(*) FROM uo_goal WHERE game=701"));

        DBQuery("DELETE FROM uo_setting WHERE name='DisableScoresheetHistory'");
        LegacyApp::loadLibFilesUsingProfile(['user.functions.php', 'scoresheethistory.functions.php', 'game.functions.php', 'standings.functions.php'], 'team_stack');
        $_SESSION = ['uid' => 'testuser', 'userproperties' => ['userrole' => ['superadmin' => true]]];
        $restored = ScoresheetHistoryRestore((int) $rows[0]['history_id']);

        $this->assertTrue($restored['restored'], json_encode($restored));
        $this->assertSame(0, self::dbCount("SELECT COUNT(*) FROM uo_goal WHERE game=701"));
        $this->assertSame(0, self::dbCount("SELECT COUNT(*) FROM uo_played WHERE game=701"));
        $this->assertSame('Before', DBQueryToValueUncached("SELECT official FROM uo_game WHERE game_id=701"));
    }

    public function testInjectedFailureRollsBackDataHistoryAndReceiptTogether(): void
    {
        $this->injectTrigger("CREATE TRIGGER skapi_fail BEFORE INSERT ON uo_api_receipt FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected failure'");
        $token = self::entryContext()['token'];
        $before = self::stored();

        $response = self::put(self::validDoc(), $token);

        self::assertError($response, 500, 'internal_error');
        $this->assertSame($before, self::stored(), 'goals, roster, history and receipt all rolled back');
        $this->assertSame(0, self::dbCount("SELECT COUNT(*) FROM uo_scoresheet_history WHERE game=701 AND has_snapshot=1"));
        $this->assertSame($token, self::entryContext()['token']);
        // Contrast: without the failure the same request saves.
        DBQuery("DROP TRIGGER skapi_fail");
        $this->assertSame(200, self::put(self::validDoc(), $token)['status']);
        $this->assertSame(3, self::dbCount("SELECT COUNT(*) FROM uo_goal WHERE game=701"));
    }

    public function testDeadlockIsRetriedAndWritesOneSnapshot(): void
    {
        DBQuery("CREATE TABLE skapi_flag (x INT) ENGINE=MyISAM");
        $this->injectTrigger("CREATE TRIGGER skapi_once BEFORE INSERT ON uo_api_receipt FOR EACH ROW
            BEGIN
                IF (SELECT COUNT(*) FROM skapi_flag) = 0 THEN
                    INSERT INTO skapi_flag VALUES (1);
                    SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO=1213, MESSAGE_TEXT='injected deadlock';
                END IF;
            END");

        $response = $this->upload(self::validDoc());

        $this->assertSame(200, $response['status'], $response['raw']);
        $this->assertSame(1, self::dbCount("SELECT COUNT(*) FROM skapi_flag"), 'the first attempt failed');
        $this->assertSame(1, self::dbCount("SELECT COUNT(*) FROM uo_scoresheet_history WHERE game=701 AND has_snapshot=1"));
        $this->assertSame(3, self::dbCount("SELECT COUNT(*) FROM uo_goal WHERE game=701"));
        $this->assertSame(1, self::dbCount("SELECT COUNT(*) FROM uo_api_receipt WHERE game=701"));
        $this->assertSame(2, self::dbCount("SELECT COUNT(*) FROM uo_played WHERE game=701 AND player IN (802, 803)"));
    }

    public function testPersistentDeadlockEndsInBusyWithRetryAfterAndNoWrites(): void
    {
        $this->injectTrigger("CREATE TRIGGER skapi_fail BEFORE INSERT ON uo_api_receipt FOR EACH ROW
            SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO=1213, MESSAGE_TEXT='injected deadlock'");
        $token = self::entryContext()['token'];
        $before = self::stored();

        $response = self::put(self::validDoc(), $token);

        self::assertError($response, 503, 'busy');
        $this->assertSame('1', $response['headers']['retry-after'] ?? null);
        $this->assertSame($before, self::stored());
    }

    public function testUploadNeedsALinkThatCoversTheGame(): void
    {
        $token = self::entryContext()['token'];
        $before = self::stored();

        self::assertError(self::put(self::validDoc(), $token, 'key-1', ['X-Scorekeeping-Token' => self::LINK_OTHER_GAME]), 403, 'game_not_covered');
        self::assertError(self::put(self::validDoc(), $token, 'key-1', ['X-Scorekeeping-Token' => null]), 401, 'missing_scorekeeping_token');
        self::assertError(self::put(self::validDoc(), $token, 'key-1', ['Authorization' => 'Bearer ' . self::TOKEN_USER]), 403, 'invalid_scope');
        self::assertError(self::put(self::validDoc(), $token, 'key-1', ['Authorization' => 'Bearer ' . self::TOKEN_OTHER_EVENT]), 403, 'event_scope_mismatch');
        $this->assertSame($before, self::stored());
        // Contrast: the field link of the game's reservation may upload.
        $this->assertSame(200, self::put(self::validDoc(), $token, 'key-1', ['X-Scorekeeping-Token' => self::LINK_FIELD])['status']);
    }

    public function testRightsAreRecheckedUnderTheLock(): void
    {
        DBQuery("UPDATE uo_season SET event_readonly=1 WHERE season_id='HRN2026'");
        $token = self::entryContext()['token'];

        self::assertError(self::put(self::validDoc(), $token), 403, 'event_readonly');
        $this->assertSame(0, self::stored()['goals']);
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
