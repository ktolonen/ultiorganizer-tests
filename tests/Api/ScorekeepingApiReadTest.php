<?php

declare(strict_types=1);

use UltiorganizerHarness\Support\ScorekeepingApiTestCase;

/** Discovery and entry context of the scorekeeping app API. */
final class ScorekeepingApiReadTest extends ScorekeepingApiTestCase
{
    public function testGameLinkDiscoversItsOneGame(): void
    {
        $response = self::get('/api/v1/scorekeeping/games');

        $this->assertSame(200, $response['status'], $response['raw']);
        $data = $response['json']['data'];
        $this->assertSame('HRN2026', $data['event_id']);
        $this->assertNull($data['available_from']);
        $this->assertCount(1, $data['games']);
        $game = $data['games'][0];
        $this->assertSame(701, $game['game_id']);
        $this->assertSame('2', $game['field']);
        $this->assertSame(['team_id' => 301, 'name' => 'Tampere Tempest'], $game['home']);
        $this->assertSame(['team_id' => 300, 'name' => 'Helsinki Heat'], $game['away']);
        $this->assertSame(['pool_id' => 200, 'name' => 'Pool A'], $game['pool']);
        $this->assertSame(['state' => 'not_started', 'home' => null, 'away' => null], $game['result']);
    }

    public function testFieldLinkDiscoversTheReservationsGames(): void
    {
        $fieldOne = self::get('/api/v1/scorekeeping/games', self::auth(self::TOKEN_INSTALL, self::LINK_OTHER_FIELD));
        $fieldTwo = self::get('/api/v1/scorekeeping/games', self::auth(self::TOKEN_INSTALL, self::LINK_FIELD));

        $this->assertSame([700], array_column($fieldOne['json']['data']['games'], 'game_id'));
        $this->assertSame('final', $fieldOne['json']['data']['games'][0]['result']['state']);
        $this->assertSame(15, $fieldOne['json']['data']['games'][0]['result']['home']);
        $this->assertSame([701], array_column($fieldTwo['json']['data']['games'], 'game_id'));
        // A second game in the same reservation joins the field link only.
        DBQuery("UPDATE uo_game SET reservation=501 WHERE game_id=700");
        $both = self::get('/api/v1/scorekeeping/games', self::auth(self::TOKEN_INSTALL, self::LINK_FIELD));
        $this->assertSame([700, 701], array_column($both['json']['data']['games'], 'game_id'));
        $single = self::get('/api/v1/scorekeeping/games', self::auth(self::TOKEN_INSTALL, self::LINK_GAME));
        $this->assertSame([701], array_column($single['json']['data']['games'], 'game_id'));
    }

    public function testEntryContextShape(): void
    {
        $response = self::get('/api/v1/games/701/entry-context');

        $this->assertSame(200, $response['status'], $response['raw']);
        $data = $response['json']['data'];
        $this->assertSame(
            ['game', 'home', 'away', 'result', 'goals', 'timeouts', 'spirit_stoppages', 'metadata', 'rules', 'retained', 'token'],
            array_keys($data),
        );
        $this->assertSame(701, $data['game']['game_id']);
        $this->assertSame('HRN2026', $data['game']['event']['event_id']);
        $this->assertSame(301, $data['home']['team_id']);
        $this->assertSame('Tampere Tempest', $data['home']['name']);
        $this->assertSame(
            [['player_id' => 802, 'name' => 'Timo Twist', 'number' => 7], ['player_id' => 803, 'name' => 'Nia North', 'number' => 14]],
            $data['home']['eligible_players'],
        );
        $this->assertSame([], $data['home']['roster']);
        $this->assertNull($data['result']);
        $this->assertSame([], $data['goals']);
        $this->assertSame(['home' => [], 'away' => []], $data['timeouts']);
        $this->assertFalse($data['rules']['hide_time_on_scoresheet']);
        $this->assertFalse($data['rules']['require_accreditation']);
        $this->assertTrue($data['rules']['spirit_stoppages_enabled']);
        $this->assertSame(1000, $data['rules']['max_game_score']);
        $this->assertSame(['forfeit' => 'none', 'cap_events' => [], 'defenses' => ['home' => 0, 'away' => 0, 'referenced_player_ids' => []]], $data['retained']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $data['token']);
        $this->assertSame('"' . $data['token'] . '"', $response['headers']['etag'] ?? null);
        $this->assertSame($data['token'], self::entryContext()['token'], 'a read changes nothing');
    }

    public function testEntryContextHasNoGameNotesContactDataOrHistory(): void
    {
        DBQuery("INSERT INTO uo_comment (type, id, comment) VALUES (4, '701', 'secret-game-note-text')");
        DBQuery("INSERT INTO uo_scoresheet_history (game, user_id, source, target, action, detail) VALUES (701, 'someone', 'user', 'official', 'update', '{\"name\":\"history-marker\"}')");

        $response = self::get('/api/v1/games/701/entry-context');

        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString('secret-game-note-text', $response['raw']);
        $this->assertStringNotContainsString('history-marker', $response['raw']);
        foreach (['comment', 'email', 'history', 'notes', 'profile', 'birthdate'] as $word) {
            $this->assertStringNotContainsString('"' . $word, $response['raw'], $word);
        }
    }

    public function testEntryContextReportsStoredStateAndRetainedData(): void
    {
        DBQuery("INSERT INTO uo_played (player, game, num, accredited, captain) VALUES (802, 701, 7, 1, 1)");
        DBQuery("INSERT INTO uo_goal (game, num, assist, scorer, time, homescore, visitorscore, ishomegoal, iscallahan) VALUES (701, 1, NULL, 802, 55, 1, 0, 1, 1)");
        DBQuery("INSERT INTO uo_defense (game, num, author, time, iscallahan, iscaught, ishomedefense) VALUES (701, 1, 802, 70, 0, 1, 1)");
        DBQuery("INSERT INTO uo_gameevent (game, num, time, type, ishome, info) VALUES (701, 1, 900, 'half_cap', 0, '8')");
        DBQuery("UPDATE uo_game SET homescore=1, visitorscore=0, hasstarted=1, isongoing=1, homedefenses=1 WHERE game_id=701");

        $data = self::entryContext();

        $this->assertSame([['player_id' => 802, 'number' => 7, 'captain' => true, 'spirit_captain' => false]], $data['home']['roster']);
        $this->assertSame(
            [['num' => 1, 'side' => 'home', 'scorer_id' => 802, 'assist_id' => null, 'callahan' => true, 'time' => 55, 'home_score' => 1, 'away_score' => 0]],
            $data['goals'],
        );
        $this->assertSame(['home' => 1, 'away' => 0, 'state' => 'ongoing'], $data['result']);
        $this->assertSame([['type' => 'half_cap', 'time' => 900, 'point_cap' => 8]], $data['retained']['cap_events']);
        $this->assertSame([802], $data['retained']['defenses']['referenced_player_ids']);
        $this->assertSame(1, $data['retained']['defenses']['home']);
    }

    public function testHiddenTimeEventsReturnNullGoalTimes(): void
    {
        DBQuery("UPDATE uo_season SET hide_time_on_scoresheet=1 WHERE season_id='HRN2026'");
        DBQuery("INSERT INTO uo_goal (game, num, assist, scorer, time, homescore, visitorscore, ishomegoal, iscallahan) VALUES (701, 1, NULL, 802, 1, 1, 0, 1, 1)");

        $data = self::entryContext();

        $this->assertTrue($data['rules']['hide_time_on_scoresheet']);
        $this->assertNull($data['goals'][0]['time']);
    }

    public function testTokenIgnoresTheClockButFollowsTheContent(): void
    {
        $before = self::entryContext()['token'];

        DBQuery("UPDATE uo_game SET timer_start=UNIX_TIMESTAMP() - 120 WHERE game_id=701");
        $this->assertSame($before, self::entryContext()['token'], 'a running clock does not move the token');

        DBQuery("UPDATE uo_game SET official='Someone' WHERE game_id=701");
        $this->assertNotSame($before, self::entryContext()['token'], 'content does');
    }

    public function testEntryContextReadsWhileAWriterHoldsTheGameLock(): void
    {
        $writer = new \mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_DATABASE);
        $writer->query("START TRANSACTION");
        $writer->query("SELECT game_id FROM uo_game WHERE game_id=701 FOR UPDATE");
        try {
            $started = microtime(true);
            $response = self::get('/api/v1/games/701/entry-context');
            $elapsed = microtime(true) - $started;
        } finally {
            $writer->query("ROLLBACK");
            $writer->close();
        }

        $this->assertSame(200, $response['status'], $response['raw']);
        $this->assertLessThan(3.0, $elapsed, 'a read takes no lock and does not wait for the writer');
    }
}
