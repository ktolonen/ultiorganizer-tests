<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class GamehistoryFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        // pool_stack brings pool/series/season/statistical, which the restore
        // guards in Task 6 (IsPoolLocked, IsSeasonStatsCalculated,
        // isEventReadonly) need. Loading it here keeps Tasks 3-6 on one setUp.
        LegacyApp::loadLibFilesUsingProfile(
            ['user.functions.php', 'gamehistory.functions.php', 'game.functions.php'],
            'pool_stack',
        );
        $_SESSION['userproperties']['userrole']['superadmin'] = true;

        // IsGameHistoryDisabled() caches in a static, so the setting must be
        // written before the first call in this process.
        DBQuery("DELETE FROM uo_setting WHERE name='DisableGameHistory'");
        DBQuery("INSERT INTO uo_setting (name, value) VALUES ('DisableGameHistory', 'false')");

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        $_SESSION['uid'] = 'testuser';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        // The snapshot memo lives in the request-local cache, which PHPUnit
        // does not reset between tests in the same process.
        CacheForgetNamespace('game_history_snapshot');

        // DBQueryToValue/Array/Row persistently cache by literal query string
        // (see GameFunctionsLibTest). GameHistoryCount()/List()/All() build the
        // same literal SQL across tests sharing games 700/701, so an earlier
        // test's cached result otherwise leaks into a later one within the TTL.
        global $serverConf;
        if (!isset($serverConf)) {
            $serverConf = [];
        }
        $serverConf['PersistentCacheEnabled'] = 'false';
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            if (function_exists('CacheForgetPersistent')) {
                CacheForgetPersistent($ns);
            }
        }
    }

    protected function tearDown(): void
    {
        // Task 3 tests mutate game 701, which the baseline fixture leaves unplayed.
        DBQuery("DELETE FROM uo_goal WHERE game=701");
        DBQuery("UPDATE uo_game SET homescore=NULL, visitorscore=NULL, isongoing=0, hasstarted=0 WHERE game_id=701");

        DBQuery("DELETE FROM uo_played WHERE game=701");
        DBQuery("DELETE FROM uo_timeout WHERE game=701");
        DBQuery("DELETE FROM uo_spirit_timeout WHERE game=701");
        DBQuery("DELETE FROM uo_gameevent WHERE game=701");
        DBQuery("UPDATE uo_game SET halftime=35, official=NULL WHERE game_id=701");

        // testGameAddNewPlayerRecordsExactlyOnePlayedAddRow() creates this
        // player; other suites assert exact roster sizes for its team.
        DBQuery("DELETE FROM uo_player WHERE firstname='New' AND lastname='Player'");

        DBQuery("DELETE FROM uo_game_history WHERE game IN (700, 701)");

        // Restore tests mutate the shared fixture game 700.
        DBQuery("DELETE FROM uo_goal WHERE game=700");
        DBQuery("INSERT INTO uo_goal (game, num, assist, scorer, time, homescore, visitorscore, ishomegoal, iscallahan, timestamp) VALUES
            (700, 1, 801, 800, 120, 1, 0, 1, 0, '2026-06-01 10:02:00'),
            (700, 2, 803, 802, 300, 1, 1, 0, 0, '2026-06-01 10:05:00'),
            (700, 3, 800, 801, 480, 2, 1, 1, 1, '2026-06-01 10:08:00'),
            (700, 4, 802, 803, 660, 2, 2, 0, 0, '2026-06-01 10:11:00')");
        DBQuery("UPDATE uo_game SET homescore=15, visitorscore=11, isongoing=0, hasstarted=1 WHERE game_id=700");

        // GameHistoryRestore() rebuilds uo_played via GameRemoveAllPlayers()/
        // GameAddPlayer(), which also rewrites uo_player.num for the restored
        // roster number -- reseed both so a restore test can't leak a changed
        // roster number or a dropped acknowledged flag into a later test.
        DBQuery("DELETE FROM uo_played WHERE game=700");
        DBQuery("INSERT INTO uo_played (player, game, num, accredited, acknowledged, captain) VALUES
            (800, 700, 8, 1, 1, 1),
            (801, 700, 12, 1, 1, 0),
            (802, 700, 7, 1, 1, 1),
            (803, 700, 14, 1, 1, 0)");
        DBQuery("UPDATE uo_player SET num=8 WHERE player_id=800");
        DBQuery("UPDATE uo_player SET num=12 WHERE player_id=801");
        DBQuery("UPDATE uo_player SET num=7 WHERE player_id=802");
        DBQuery("UPDATE uo_player SET num=14 WHERE player_id=803");

        // A restored acknowledged=1 player goes through AcknowledgeUnaccredited(),
        // which logs to uo_accreditationlog; a restore test must not leak rows
        // there either.
        DBQuery("DELETE FROM uo_accreditationlog WHERE game=700");

        // The baseline fixture has no defenses for game 700; a defense
        // round-trip test inserts its own and this clears them, rather than
        // reseeding rows that were never part of the shared fixture.
        DBQuery("DELETE FROM uo_defense WHERE game=700");

        // Same for timeouts: the baseline fixture has none for game 700, and
        // ReservationGroupTimeoutStats() elsewhere asserts that count is 0.
        DBQuery("DELETE FROM uo_timeout WHERE game=700");

        DBQuery("UPDATE uo_game SET forfeit=0 WHERE game_id=700");

        // GameHistoryRestore() now calls GameRemoveAllGameEvents() before
        // replay (see Finding 4 of the whole-branch review), which can clear
        // and replay the offence/cap rows for game 700. Reseed the fixture's
        // exact original rows so GameFunctionsLibTest's turnover and cap
        // tests don't inherit a polluted game 700.
        DBQuery("DELETE FROM uo_gameevent WHERE game=700");
        DBQuery("INSERT INTO uo_gameevent (game, num, time, type, ishome, info) VALUES
            (700, 1, 400, 'half_cap', 0, '9'),
            (700, 2, 900, 'time_cap', 0, '13'),
            (700, 3, 500, 'turnover', 1, NULL)");
        DBQuery("UPDATE uo_game SET halftime=35, official=NULL WHERE game_id=700");

        // GameHistoryRestore() ends by calling GameSetForfeit(), which always
        // recomputes PoolResolvePlayed(200) from the live uo_game rows. Game
        // 701 in this same pool is permanently unplayed (reset above), so
        // that recompute leaves uo_pool.played=0 -- flipping the baseline
        // seed of 1 for every test that runs afterwards in this process,
        // regardless of whether that later test touches game 700 at all.
        // Reseed it so IsPoolLocked()'s warning is deterministic per test.
        DBQuery("UPDATE uo_pool SET played=1 WHERE pool_id=200");

        // V1 restore tests round-trip defenses/timer columns on game 700/701;
        // reseed both back to the fixture's plain values so a later test
        // doesn't inherit a stray defense count or a phantom running clock.
        DBQuery("UPDATE uo_game SET homedefenses=0, visitordefenses=0,
            timer_start=NULL, timer_pause_start=NULL, timer_paused_duration=0
            WHERE game_id IN (700, 701)");

        // V2's comment test writes to uo_comment for game 700/701; comments
        // are otherwise untouched by this suite's fixture.
        DBQuery("DELETE FROM uo_comment WHERE type=" . COMMENT_TYPE_GAME . " AND id IN (700, 701)");

        // V5's accreditation-required-event test flips this per-season flag;
        // a test-level finally already restores it, this is a backstop.
        DBQuery("UPDATE uo_season SET require_accreditation=0 WHERE season_id='HRN2026'");

        unset($_SESSION['uid']);
        LegacyApp::closeDatabaseConnection();
    }

    public function testRecordStoresSessionUserIpAndJsonDetail(): void
    {
        $id = (int) GameHistoryRecord(700, 'result', 'update', ['home' => 15, 'away' => 11]);
        $this->assertGreaterThan(0, $id);

        $row = DBQueryToRow(
            "SELECT game, user_id, ip, target, action, detail, has_snapshot, snapshot
             FROM uo_game_history WHERE history_id=$id",
        );

        $this->assertSame('700', (string) $row['game']);
        $this->assertSame('testuser', $row['user_id']);
        $this->assertSame('203.0.113.7', $row['ip']);
        $this->assertSame('result', $row['target']);
        $this->assertSame('update', $row['action']);
        $this->assertSame(['home' => 15, 'away' => 11], json_decode($row['detail'], true));
        $this->assertSame('0', (string) $row['has_snapshot']);
        $this->assertNull($row['snapshot']);
    }

    public function testRecordFallsBackToUnknownUserWithoutSession(): void
    {
        unset($_SESSION['uid']);
        $id = (int) GameHistoryRecord(700, 'goal', 'add', ['num' => 5]);
        $row = DBQueryToRow("SELECT user_id FROM uo_game_history WHERE history_id=$id");
        $this->assertSame('unknown', $row['user_id']);
    }

    public function testRecordRejectsInvalidGameId(): void
    {
        $this->assertFalse(GameHistoryRecord(0, 'goal', 'add', []));
    }

    public function testRecordIsSuppressedWhileTheSuppressionFlagIsSet(): void
    {
        GameHistorySuppressed(true);
        try {
            $this->assertFalse(GameHistoryRecord(700, 'goal', 'add', ['num' => 1]));
        } finally {
            GameHistorySuppressed(false);
        }
        $count = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_game_history WHERE game=700");
        $this->assertSame(0, $count);
    }

    public function testBuildSnapshotCapturesGameScalarsGoalsAndPlayers(): void
    {
        $snapshot = GameHistoryBuildSnapshot(700);

        $this->assertSame(3, $snapshot['v']);
        $this->assertSame(15, $snapshot['game']['homescore']);
        $this->assertSame(11, $snapshot['game']['visitorscore']);
        $this->assertSame(35, $snapshot['game']['halftime']);

        $this->assertCount(4, $snapshot['goals']);
        $first = $snapshot['goals'][0];
        $this->assertSame(1, $first['num']);
        $this->assertSame(800, $first['scorer']);
        $this->assertSame('Ari Ace', $first['scorer_name']);
        // Jersey number comes from uo_played for this game, not uo_player.
        $this->assertSame(8, $first['scorer_num']);
        $this->assertSame(801, $first['assist']);
        $this->assertSame('Bea Blade', $first['assist_name']);
        $this->assertSame(12, $first['assist_num']);

        // Goal 3 is a callahan by home player 801.
        $this->assertSame(1, $snapshot['goals'][2]['iscallahan']);

        $this->assertCount(4, $snapshot['played']);
        $players = array_column($snapshot['played'], 'name', 'player');
        $this->assertSame('Timo Twist', $players[802]);
    }

    public function testSnapshotIfNeededWritesOneRestorableRowPerGamePerRequest(): void
    {
        $first = (int) GameHistorySnapshotIfNeeded(700);
        $this->assertGreaterThan(0, $first);

        // The second call is memoized: it returns the same id and writes no
        // second row.
        $this->assertSame($first, (int) GameHistorySnapshotIfNeeded(700));

        $rows = DBQueryToArray(
            "SELECT target, action, has_snapshot, snapshot FROM uo_game_history WHERE game=700",
        );
        $this->assertCount(1, $rows);
        $this->assertSame('snapshot', $rows[0]['target']);
        $this->assertSame('capture', $rows[0]['action']);
        $this->assertSame('1', (string) $rows[0]['has_snapshot']);

        $stored = json_decode($rows[0]['snapshot'], true);
        $this->assertSame(15, $stored['game']['homescore']);
        $this->assertCount(4, $stored['goals']);
    }

    public function testIsGameHistoryDisabledReflectsTheSeededSetting(): void
    {
        // IsGameHistoryDisabled() caches in a static for the life of the
        // process, so only the seeded value is observable here. The
        // recording-off path is exercised through GameHistorySuppressed()
        // above; this pins the setting parsing itself.
        $this->assertFalse(IsGameHistoryDisabled());
    }

    public function testGameSetResultRecordsTheFinalScore(): void
    {
        GameSetResult(701, 13, 9, false);

        $row = DBQueryToRow(
            "SELECT target, action, detail FROM uo_game_history
             WHERE game=701 AND target='result' ORDER BY history_id DESC LIMIT 1",
        );

        $this->assertSame('result', $row['target']);
        $this->assertSame('update', $row['action']);
        $detail = json_decode($row['detail'], true);
        $this->assertSame(13, $detail['home']);
        $this->assertSame(9, $detail['away']);
        $this->assertSame('final', $detail['state']);
    }

    public function testGameSetResultAlsoWritesARestorableSnapshot(): void
    {
        GameSetResult(701, 13, 9, false);

        $count = (int) DBQueryToValue(
            "SELECT COUNT(*) FROM uo_game_history WHERE game=701 AND has_snapshot=1",
        );
        $this->assertSame(1, $count);
    }

    public function testGameAddScoreEntryRecordsOneGoalRowPerPoint(): void
    {
        GameAddScoreEntry([
            'game' => 701, 'num' => 1, 'assist' => 802, 'scorer' => 803,
            'time' => 60, 'homescore' => 0, 'visitorscore' => 1,
            'ishomegoal' => 0, 'iscallahan' => 0,
        ]);

        $rows = DBQueryToArray(
            "SELECT action, detail FROM uo_game_history WHERE game=701 AND target='goal'",
        );
        $this->assertCount(1, $rows);
        $this->assertSame('add', $rows[0]['action']);

        $detail = json_decode($rows[0]['detail'], true);
        $this->assertSame(1, $detail['num']);
        $this->assertSame(803, $detail['scorer']);
        $this->assertSame(802, $detail['assist']);
        $this->assertSame('0-1', $detail['score']);
    }

    public function testGameRemoveAllScoresSnapshotsBeforeClearingAndRecordsTheCount(): void
    {
        GameAddScoreEntry([
            'game' => 701, 'num' => 1, 'assist' => 802, 'scorer' => 803,
            'time' => 60, 'homescore' => 0, 'visitorscore' => 1,
            'ishomegoal' => 0, 'iscallahan' => 0,
        ]);

        GameRemoveAllScores(701);

        $snapshot = DBQueryToRow(
            "SELECT snapshot FROM uo_game_history
             WHERE game=701 AND has_snapshot=1 ORDER BY history_id DESC LIMIT 1",
        );
        // The snapshot holds the pre-clear state, so the goal is still in it.
        $stored = json_decode($snapshot['snapshot'], true);
        $this->assertCount(1, $stored['goals']);

        $clear = DBQueryToRow(
            "SELECT action, detail FROM uo_game_history
             WHERE game=701 AND target='goal' AND action='clear' ORDER BY history_id DESC LIMIT 1",
        );
        $this->assertSame(1, json_decode($clear['detail'], true)['removed']);
    }

    public function testBulkRewriteWritesExactlyOneSnapshotPerRequest(): void
    {
        // A desktop save can call more than one destructive helper in the
        // same request. The per-request memo must collapse them to a single
        // restore point: without it, this would find two rows, not one.
        GameRemoveAllScores(701);
        GameRemoveAllDefenses(701);

        $count = (int) DBQueryToValue(
            "SELECT COUNT(*) FROM uo_game_history WHERE game=701 AND has_snapshot=1",
        );
        $this->assertSame(1, $count);
    }

    public function testGameAddPlayerRecordsThePlayerAndJerseyNumber(): void
    {
        GameAddPlayer(701, 800, 8);

        $row = DBQueryToRow(
            "SELECT action, detail FROM uo_game_history
             WHERE game=701 AND target='played' ORDER BY history_id DESC LIMIT 1",
        );
        $this->assertSame('add', $row['action']);
        $detail = json_decode($row['detail'], true);
        $this->assertSame(800, $detail['player']);
        $this->assertSame(8, $detail['num']);
    }

    public function testGameSetPlayerNumberRecordsTheNewNumber(): void
    {
        GameAddPlayer(701, 800, 8);
        GameSetPlayerNumber(701, 800, 21);

        $row = DBQueryToRow(
            "SELECT action, detail FROM uo_game_history
             WHERE game=701 AND target='played' AND action='update' ORDER BY history_id DESC LIMIT 1",
        );
        $detail = json_decode($row['detail'], true);
        $this->assertSame(800, $detail['player']);
        $this->assertSame(21, $detail['num']);
    }

    public function testGameSetHalftimeRecordsTheHalftimeValue(): void
    {
        GameSetHalftime(701, 1800);

        $row = DBQueryToRow(
            "SELECT target, action, detail FROM uo_game_history
             WHERE game=701 AND target='halftime' ORDER BY history_id DESC LIMIT 1",
        );
        $this->assertSame('update', $row['action']);
        $this->assertSame(1800, json_decode($row['detail'], true)['time']);
    }

    public function testGameSetScoreSheetKeeperRecordsTheOfficialName(): void
    {
        GameSetScoreSheetKeeper(701, 'Official 1');

        $row = DBQueryToRow(
            "SELECT target, detail FROM uo_game_history
             WHERE game=701 AND target='official' ORDER BY history_id DESC LIMIT 1",
        );
        $this->assertSame('Official 1', json_decode($row['detail'], true)['name']);
    }

    public function testGameAddTimeoutRecordsTheTimeoutSide(): void
    {
        GameAddTimeout(701, 1, 300, true);

        $row = DBQueryToRow(
            "SELECT target, action, detail FROM uo_game_history
             WHERE game=701 AND target='timeout' ORDER BY history_id DESC LIMIT 1",
        );
        $this->assertSame('add', $row['action']);
        $detail = json_decode($row['detail'], true);
        $this->assertSame(300, $detail['time']);
        $this->assertSame(1, $detail['home']);
    }

    public function testGameAddNewPlayerRecordsExactlyOnePlayedAddRow(): void
    {
        // GameAddNewPlayer() delegates to GameAddPlayer() to add the newly
        // created player to the roster. Without suppressing the delegate's
        // own recording, this would write two rows instead of one.
        GameAddNewPlayer(701, 'New', 'Player', 0, 300, 9);

        $count = (int) DBQueryToValue(
            "SELECT COUNT(*) FROM uo_game_history WHERE game=701 AND target='played' AND action='add'",
        );
        $this->assertSame(1, $count);

        $row = DBQueryToRow(
            "SELECT detail FROM uo_game_history
             WHERE game=701 AND target='played' AND action='add' ORDER BY history_id DESC LIMIT 1",
        );
        $detail = json_decode($row['detail'], true);
        $this->assertSame(1, $detail['created']);
    }

    public function testListReturnsNewestFirstAndOmitsTheSnapshotPayload(): void
    {
        GameHistoryRecord(700, 'result', 'update', ['home' => 1, 'away' => 0, 'state' => 'ongoing']);
        GameHistoryRecord(700, 'goal', 'add', ['num' => 2, 'score' => '1-1']);

        $rows = GameHistoryList(700);

        $this->assertCount(2, $rows);
        $this->assertSame('goal', $rows[0]['target']);
        $this->assertSame('result', $rows[1]['target']);
        $this->assertArrayNotHasKey('snapshot', $rows[0]);
        $this->assertSame('0', (string) $rows[0]['has_snapshot']);
    }

    public function testCountMatchesTheNumberOfRecordedRows(): void
    {
        GameHistoryRecord(700, 'goal', 'add', ['num' => 1]);
        GameHistoryRecord(700, 'goal', 'add', ['num' => 2]);
        $this->assertSame(2, GameHistoryCount(700));
    }

    public function testListDeniesAUserWithoutGameEditRights(): void
    {
        GameHistoryRecord(700, 'goal', 'add', ['num' => 1]);
        $_SESSION['userproperties']['userrole'] = [];
        $_SESSION['uid'] = 'anonymous';
        try {
            $this->assertSame([], GameHistoryList(700));
            $this->assertSame(0, GameHistoryCount(700));
        } finally {
            $_SESSION['uid'] = 'testuser';
            $_SESSION['userproperties']['userrole']['superadmin'] = true;
        }
    }

    public function testEntryReturnsTheDecodedSnapshot(): void
    {
        $id = (int) GameHistorySnapshotIfNeeded(700);
        $entry = GameHistoryEntry($id);

        $this->assertSame('snapshot', $entry['target']);
        $this->assertSame(15, $entry['snapshot']['game']['homescore']);
        $this->assertCount(4, $entry['snapshot']['goals']);
    }

    public function testAllDeniesNonSuperAdmins(): void
    {
        GameHistoryRecord(700, 'goal', 'add', ['num' => 1]);
        $_SESSION['userproperties']['userrole'] = [];
        try {
            $this->assertSame([], GameHistoryAll(['game' => 700]));
            $this->assertSame(0, GameHistoryAllCount(['game' => 700]));
        } finally {
            $_SESSION['userproperties']['userrole']['superadmin'] = true;
        }
    }

    public function testAllFiltersByGame(): void
    {
        GameHistoryRecord(700, 'goal', 'add', ['num' => 1]);
        GameHistoryRecord(701, 'goal', 'add', ['num' => 1]);

        $rows = GameHistoryAll(['game' => 700]);
        $this->assertCount(1, $rows);
        $this->assertSame('700', (string) $rows[0]['game']);
        $this->assertSame(1, GameHistoryAllCount(['game' => 700]));
    }

    public function testFormatDetailRendersEachTargetCompactly(): void
    {
        $this->assertSame(
            // "final" is a fix-round-1 change: the raw state token is now
            // mapped to a localized word (was the bare token 'final').
            'Result 15-11 (Final)',
            GameHistoryFormatDetail([
                'target' => 'result',
                'action' => 'update',
                'detail' => json_encode(['home' => 15, 'away' => 11, 'state' => 'final']),
            ]),
        );

        $this->assertSame(
            'Point 3: 2-1',
            GameHistoryFormatDetail([
                'target' => 'goal',
                'action' => 'add',
                'detail' => json_encode(['num' => 3, 'score' => '2-1']),
            ]),
        );

        $this->assertSame(
            'Points removed: 24',
            GameHistoryFormatDetail([
                'target' => 'goal',
                'action' => 'clear',
                'detail' => json_encode(['removed' => 24]),
            ]),
        );
    }

    public function testFormatDetailLocalizesPreviouslyUnformattedTargetsAndInternalTokens(): void
    {
        $timeout = GameHistoryFormatDetail([
            'target' => 'timeout',
            'action' => 'add',
            'detail' => json_encode(['num' => 2, 'time' => 400, 'home' => 1]),
        ]);
        $this->assertNotSame('timeout', $timeout);
        $this->assertSame('Timeout 2', $timeout);

        $defense = GameHistoryFormatDetail([
            'target' => 'defense',
            'action' => 'clear',
            'detail' => json_encode(['removed' => 3]),
        ]);
        $this->assertNotSame('defense', $defense);
        $this->assertSame('Defences removed: 3', $defense);

        $comment = GameHistoryFormatDetail([
            'target' => 'comment',
            'action' => 'remove',
            'detail' => json_encode(['length' => 0]),
        ]);
        $this->assertNotSame('comment', $comment);
        $this->assertSame('Game note removed', $comment);

        $forfeit = GameHistoryFormatDetail([
            'target' => 'forfeit',
            'action' => 'update',
            'detail' => json_encode(['forfeit' => 'home']),
        ]);
        $this->assertNotSame('forfeit', $forfeit);
        // Not just localized: the raw stored token ("home") must not leak
        // through either, only the full translated forfeit sentence.
        $this->assertSame('Forfeit: Home team forfeited', $forfeit);

        $spiritTimeout = GameHistoryFormatDetail([
            'target' => 'spirit_timeout',
            'action' => 'add',
            'detail' => json_encode(['num' => 1, 'time' => 120, 'home' => 0]),
        ]);
        $this->assertNotSame('spirit_timeout', $spiritTimeout);

        $mediaevent = GameHistoryFormatDetail([
            'target' => 'mediaevent',
            'action' => 'add',
            'detail' => json_encode(['url' => 5]),
        ]);
        $this->assertNotSame('mediaevent', $mediaevent);

        $gameeventStart = GameHistoryFormatDetail([
            'target' => 'gameevent',
            'action' => 'update',
            'detail' => json_encode(['type' => 'start', 'home' => 1]),
        ]);
        $this->assertSame('Starting offence: Home team', $gameeventStart);

        $gameeventCap = GameHistoryFormatDetail([
            'target' => 'gameevent',
            'action' => 'update',
            'detail' => json_encode(['type' => 'half_cap', 'time' => 900]),
        ]);
        $this->assertSame('Halftime cap', $gameeventCap);
    }

    public function testFormatDetailRendersTheAcknowledgedPlayedChange(): void
    {
        $acknowledged = GameHistoryFormatDetail([
            'target' => 'played',
            'action' => 'update',
            'detail' => json_encode(['player' => 800, 'acknowledged' => 1]),
        ]);
        $this->assertSame('Player 800: Acknowledged', $acknowledged);

        $unacknowledged = GameHistoryFormatDetail([
            'target' => 'played',
            'action' => 'update',
            'detail' => json_encode(['player' => 800, 'acknowledged' => 0]),
        ]);
        $this->assertSame('Player 800: Not acknowledged', $unacknowledged);

        // A plain jersey-number change (no 'acknowledged' key) must still
        // fall through to the generic "Player N" rendering.
        $numberChange = GameHistoryFormatDetail([
            'target' => 'played',
            'action' => 'update',
            'detail' => json_encode(['player' => 800, 'num' => 21]),
        ]);
        $this->assertSame('Player 800', $numberChange);
    }

    public function testFormatDetailRendersEachTimerAction(): void
    {
        $this->assertSame('Start game clock', GameHistoryFormatDetail([
            'target' => 'timer', 'action' => 'start', 'detail' => json_encode([]),
        ]));
        $this->assertSame('Pause game clock', GameHistoryFormatDetail([
            'target' => 'timer', 'action' => 'pause', 'detail' => json_encode([]),
        ]));
        $this->assertSame('Resume game clock', GameHistoryFormatDetail([
            'target' => 'timer', 'action' => 'resume', 'detail' => json_encode([]),
        ]));
        $this->assertSame('Reset game clock', GameHistoryFormatDetail([
            'target' => 'timer', 'action' => 'reset', 'detail' => json_encode([]),
        ]));
        $this->assertSame('Set game clock: 125', GameHistoryFormatDetail([
            'target' => 'timer', 'action' => 'update', 'detail' => json_encode(['elapsed' => 125]),
        ]));
    }

    // --- R1: AcknowledgeUnaccredited() / UnAcknowledgeUnaccredited() ---

    public function testAcknowledgeUnaccreditedWritesAPlayedUpdateRowAndASnapshot(): void
    {
        DBQuery("UPDATE uo_played SET acknowledged=0 WHERE game=700 AND player=800");
        DBQuery("DELETE FROM uo_game_history WHERE game=700");

        AcknowledgeUnaccredited(800, 700, 'test-acknowledge');

        $row = DBQueryToRow(
            "SELECT action, detail FROM uo_game_history
             WHERE game=700 AND target='played' AND action='update' ORDER BY history_id DESC LIMIT 1",
        );
        $this->assertNotNull($row, 'AcknowledgeUnaccredited() must write a played/update row');
        $detail = json_decode($row['detail'], true);
        $this->assertSame(800, $detail['player']);
        $this->assertSame(1, $detail['acknowledged']);

        $snapshotCount = (int) DBQueryToValue(
            "SELECT COUNT(*) FROM uo_game_history WHERE game=700 AND has_snapshot=1",
        );
        $this->assertSame(1, $snapshotCount, 'AcknowledgeUnaccredited() must also create a restore point');
    }

    public function testUnAcknowledgeUnaccreditedWritesAPlayedUpdateRowAndASnapshot(): void
    {
        DBQuery("DELETE FROM uo_game_history WHERE game=700");

        UnAcknowledgeUnaccredited(800, 700, 'test-unacknowledge');

        $row = DBQueryToRow(
            "SELECT action, detail FROM uo_game_history
             WHERE game=700 AND target='played' AND action='update' ORDER BY history_id DESC LIMIT 1",
        );
        $this->assertNotNull($row, 'UnAcknowledgeUnaccredited() must write a played/update row');
        $detail = json_decode($row['detail'], true);
        $this->assertSame(800, $detail['player']);
        $this->assertSame(0, $detail['acknowledged']);

        $snapshotCount = (int) DBQueryToValue(
            "SELECT COUNT(*) FROM uo_game_history WHERE game=700 AND has_snapshot=1",
        );
        $this->assertSame(1, $snapshotCount, 'UnAcknowledgeUnaccredited() must also create a restore point');
    }

    public function testRestoreReinstatesAcknowledgedValueToggledThroughUnAcknowledgeUnaccredited(): void
    {
        DBQuery("DELETE FROM uo_game_history WHERE game=700");

        // No manual GameHistorySnapshotIfNeeded() call: the restore point
        // must come from UnAcknowledgeUnaccredited() itself, so this pins
        // both halves of the fix (recording and restorability) together.
        UnAcknowledgeUnaccredited(800, 700, 'test-unacknowledge');
        $this->assertSame(0, (int) DBQueryToValue(
            "SELECT acknowledged FROM uo_played WHERE game=700 AND player=800",
        ));

        $snapshotId = (int) DBQueryToValue(
            "SELECT history_id FROM uo_game_history WHERE game=700 AND has_snapshot=1 ORDER BY history_id DESC LIMIT 1",
        );
        $this->assertGreaterThan(0, $snapshotId, 'UnAcknowledgeUnaccredited() must create a restore point');

        $result = GameHistoryRestore($snapshotId);

        $this->assertTrue($result['restored']);
        $this->assertSame(1, (int) DBQueryToValue(
            "SELECT acknowledged FROM uo_played WHERE game=700 AND player=800",
        ));
    }

    public function testFormatDetailNeverEmitsTheRawResultStateToken(): void
    {
        $ongoing = GameHistoryFormatDetail([
            'target' => 'result',
            'action' => 'update',
            'detail' => json_encode(['home' => 1, 'away' => 0, 'state' => 'ongoing']),
        ]);
        $this->assertStringNotContainsString('ongoing', $ongoing);
        // "Ongoing" (capitalized, translated) is expected; only the raw
        // lowercase internal token is forbidden.
        $this->assertSame('Result 1-0 (Ongoing)', $ongoing);

        $fromGoals = GameHistoryFormatDetail([
            'target' => 'result',
            'action' => 'update',
            'detail' => json_encode(['home' => 5, 'away' => 4, 'state' => 'from_goals']),
        ]);
        $this->assertStringNotContainsString('from_goals', $fromGoals);
        $this->assertSame('Result 5-4 (Recalculated)', $fromGoals);
    }

    public function testGameSetStartingTeamDistinguishesClearedFromAwayStarts(): void
    {
        // Fix round 2 regression: the $home===null (clear) branch used to
        // record the exact same detail as $home===false (away team starts),
        // so the audit trail could not tell the two apart.
        GameSetStartingTeam(701, false);
        $awayRow = DBQueryToRow(
            "SELECT action, detail FROM uo_game_history
             WHERE game=701 AND target='gameevent' ORDER BY history_id DESC LIMIT 1",
        );
        $this->assertSame('update', $awayRow['action']);
        $awayText = GameHistoryFormatDetail([
            'target' => 'gameevent',
            'action' => $awayRow['action'],
            'detail' => $awayRow['detail'],
        ]);

        GameSetStartingTeam(701, null);
        $clearedRow = DBQueryToRow(
            "SELECT action, detail FROM uo_game_history
             WHERE game=701 AND target='gameevent' ORDER BY history_id DESC LIMIT 1",
        );
        $this->assertSame('remove', $clearedRow['action']);
        $clearedText = GameHistoryFormatDetail([
            'target' => 'gameevent',
            'action' => $clearedRow['action'],
            'detail' => $clearedRow['detail'],
        ]);

        $this->assertNotSame($awayText, $clearedText);
        $this->assertSame('Starting offence: Away team', $awayText);
        $this->assertSame('Starting offence removed', $clearedText);
    }

    public function testRestorePutsBackTheGoalSequenceAndResult(): void
    {
        // Capture game 700 as the fixture leaves it, then damage it.
        $snapshotId = (int) GameHistorySnapshotIfNeeded(700);

        GameRemoveAllScores(700);
        GameSetResult(700, 3, 2, false);
        $this->assertSame(0, (int) DBQueryToValue("SELECT COUNT(*) FROM uo_goal WHERE game=700"));

        $result = GameHistoryRestore($snapshotId);

        $this->assertTrue($result['restored']);
        // Fixture pool 200 is played=1 and season HRN2026 has uo_season_stats
        // seeded, so these are non-blocking warnings the operator sees on
        // every restore of this fixture -- see GameHistoryRestore().
        $this->assertSame(['Pool is locked.', 'Event played.'], $result['warnings']);
        $this->assertSame(4, (int) DBQueryToValue("SELECT COUNT(*) FROM uo_goal WHERE game=700"));

        $game = DBQueryToRow(
            "SELECT homescore, visitorscore, hasstarted, isongoing FROM uo_game WHERE game_id=700",
        );
        $this->assertSame('15', (string) $game['homescore']);
        $this->assertSame('11', (string) $game['visitorscore']);
        // The fixture is hasstarted=1. GameSetResult() forces 2, so this pins
        // that the snapshot's own flags win.
        $this->assertSame('1', (string) $game['hasstarted']);
        $this->assertSame('0', (string) $game['isongoing']);

        $goal = DBQueryToRow("SELECT assist, scorer, iscallahan FROM uo_goal WHERE game=700 AND num=3");
        $this->assertSame('800', (string) $goal['assist']);
        $this->assertSame('801', (string) $goal['scorer']);
        $this->assertSame('1', (string) $goal['iscallahan']);
    }

    public function testRestoreWritesOneRestoreRowAndOneSnapshotNotAPerGoalTrail(): void
    {
        $snapshotId = (int) GameHistorySnapshotIfNeeded(700);
        GameRemoveAllScores(700);

        // Delete only the change rows -- the snapshot row is what we restore from.
        DBQuery("DELETE FROM uo_game_history WHERE game=700 AND has_snapshot=0");
        GameHistoryRestore($snapshotId);

        // The replay goes through GameAddScoreEntry() four times; suppression
        // must keep those out of the trail.
        $goalRows = (int) DBQueryToValue(
            "SELECT COUNT(*) FROM uo_game_history WHERE game=700 AND target='goal'",
        );
        $this->assertSame(0, $goalRows);

        $restoreRows = (int) DBQueryToValue(
            "SELECT COUNT(*) FROM uo_game_history WHERE game=700 AND target='restore'",
        );
        $this->assertSame(1, $restoreRows);
    }

    public function testRestoreIsItselfUndoableBecauseItSnapshotsFirst(): void
    {
        $snapshotId = (int) GameHistorySnapshotIfNeeded(700);
        GameRemoveAllScores(700);
        DBQuery("DELETE FROM uo_game_history WHERE game=700 AND has_snapshot=0");

        GameHistoryRestore($snapshotId);

        $snapshots = (int) DBQueryToValue(
            "SELECT COUNT(*) FROM uo_game_history WHERE game=700 AND has_snapshot=1",
        );
        // The original plus the pre-restore capture.
        $this->assertSame(2, $snapshots);
    }

    public function testRestoreRefusesARowWithoutASnapshot(): void
    {
        $id = (int) GameHistoryRecord(700, 'goal', 'add', ['num' => 1]);
        $result = GameHistoryRestore($id);
        $this->assertFalse($result['restored']);
    }

    public function testRestoreDeniesAUserWithoutGameEditRights(): void
    {
        $snapshotId = (int) GameHistorySnapshotIfNeeded(700);
        $_SESSION['userproperties']['userrole'] = [];
        $_SESSION['uid'] = 'anonymous';
        try {
            $result = GameHistoryRestore($snapshotId);
            $this->assertFalse($result['restored']);
        } finally {
            $_SESSION['uid'] = 'testuser';
            $_SESSION['userproperties']['userrole']['superadmin'] = true;
        }
    }

    public function testRestoreDeniesATeamAdminMissingTheAccreditationRightAndLeavesGameUntouched(): void
    {
        // hasEditGameEventsRight() and hasEditGamePlayersRight() check the
        // exact same role set (see lib/user.functions.php) -- no session can
        // pass one and fail the other, so a differential test between those
        // two is not constructible. hasAccredidationRight() is genuinely
        // distinct: a teamadmin passes both rights above, but that right also
        // requires hasEditTeamsRight() (season/series admin) or an explicit
        // accradmin grant, neither implied by teamadmin. Game 700's fixture
        // has acknowledged=1 players on team 300, so this reaches -- and is
        // refused by -- the guard block's accreditation check specifically,
        // not GameHistoryEntry()'s single hasEditGameEventsRight() check.
        $snapshotId = (int) GameHistorySnapshotIfNeeded(700);

        // Damage the game between snapshot and restore attempt, the same way
        // testRestorePutsBackTheGoalSequenceAndResult() does. Without this, a
        // fully successful restore would reproduce byte-identical goals and
        // score, and the "untouched" assertions below would pass under BOTH
        // a refusal and a full rebuild -- proving nothing about a partial one.
        GameRemoveAllScores(700);
        GameSetResult(700, 3, 2, false);
        $this->assertSame(0, (int) DBQueryToValue("SELECT COUNT(*) FROM uo_goal WHERE game=700"));

        // If the accreditation guard is ever removed, this session still
        // clears the earlier two rights checks and reaches
        // AcknowledgeUnaccredited(), which die()s on its own
        // hasAccredidationRight() check -- so a regression here surfaces as a
        // fatal error failing this test, not a clean assertion failure.
        $_SESSION['userproperties']['userrole'] = ['teamadmin' => [300 => true]];
        $_SESSION['uid'] = 'teamadmin300';
        try {
            $result = GameHistoryRestore($snapshotId);
            $this->assertFalse($result['restored']);
        } finally {
            $_SESSION['uid'] = 'testuser';
            $_SESSION['userproperties']['userrole'] = ['superadmin' => true];
        }

        // The damaged state must still stand: no goal was put back and the
        // score is still the damaged 3-2, not the snapshot's 15-11.
        $this->assertSame(0, (int) DBQueryToValue("SELECT COUNT(*) FROM uo_goal WHERE game=700"));
        $game = DBQueryToRow("SELECT homescore, visitorscore FROM uo_game WHERE game_id=700");
        $this->assertSame('3', (string) $game['homescore']);
        $this->assertSame('2', (string) $game['visitorscore']);
    }

    public function testRestoreRestoresTheAcknowledgedFlag(): void
    {
        $snapshotId = (int) GameHistorySnapshotIfNeeded(700);

        // Simulate an admin having unacknowledged the player after the
        // snapshot was taken; GameAddPlayer() would otherwise leave this at
        // its schema default (0) once the roster is rebuilt.
        DBQuery("UPDATE uo_played SET acknowledged=0 WHERE game=700 AND player=800");

        $result = GameHistoryRestore($snapshotId);

        $this->assertTrue($result['restored']);
        $acknowledged = (int) DBQueryToValue(
            "SELECT acknowledged FROM uo_played WHERE game=700 AND player=800",
        );
        $this->assertSame(1, $acknowledged);
    }

    public function testRestoreRoundTripsDefenses(): void
    {
        DBQuery("INSERT INTO uo_defense (game, num, author, time, iscallahan, iscaught, ishomedefense) VALUES
            (700, 1, 800, 200, 0, 1, 1),
            (700, 2, 802, 400, 1, 0, 0)");

        $snapshotId = (int) GameHistorySnapshotIfNeeded(700);

        DBQuery("DELETE FROM uo_defense WHERE game=700");
        $this->assertSame(0, (int) DBQueryToValue("SELECT COUNT(*) FROM uo_defense WHERE game=700"));

        $result = GameHistoryRestore($snapshotId);

        $this->assertTrue($result['restored']);
        $this->assertSame(2, (int) DBQueryToValue("SELECT COUNT(*) FROM uo_defense WHERE game=700"));

        $row = DBQueryToRow(
            "SELECT author, iscallahan, iscaught, ishomedefense FROM uo_defense WHERE game=700 AND num=2",
        );
        $this->assertSame('802', (string) $row['author']);
        $this->assertSame('1', (string) $row['iscallahan']);
        $this->assertSame('0', (string) $row['iscaught']);
        $this->assertSame('0', (string) $row['ishomedefense']);
    }

    public function testRestoreRematchesADeletedPlayerByTeamAndJerseyNumber(): void
    {
        DBQuery("INSERT INTO uo_player (firstname, lastname, team, num, accreditation_id, accredited, reg_id, profile_id)
                 VALUES ('Temp', 'Rematch', 300, 21, NULL, 1, NULL, NULL)");
        $tempPlayerId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        DBQuery(sprintf(
            "INSERT INTO uo_played (player, game, num, accredited, acknowledged, captain) VALUES (%d, 700, 21, 1, 0, 0)",
            $tempPlayerId,
        ));

        $snapshotId = (int) GameHistorySnapshotIfNeeded(700);

        // The snapshotted player id is gone by the time restore runs, but a
        // replacement wearing the same number for the same team has taken
        // their place -- the team+num fallback in GameHistoryRestorePlayers()
        // must find them, since uo_goal's ON DELETE SET NULL on player keys
        // means the id itself cannot always be resolved.
        DBQuery(sprintf("DELETE FROM uo_played WHERE player=%d AND game=700", $tempPlayerId));
        DBQuery(sprintf("DELETE FROM uo_player WHERE player_id=%d", $tempPlayerId));
        DBQuery("INSERT INTO uo_player (firstname, lastname, team, num, accreditation_id, accredited, reg_id, profile_id)
                 VALUES ('Replacement', 'Rematch', 300, 21, NULL, 1, NULL, NULL)");
        $replacementId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");

        try {
            $result = GameHistoryRestore($snapshotId);

            $this->assertTrue($result['restored']);
            // Same non-blocking pool/season warnings as every other restore
            // of this fixture; no player-restore warning, since the rematch
            // by team+num succeeded.
            $this->assertSame(['Pool is locked.', 'Event played.'], $result['warnings']);

            $row = DBQueryToRow(sprintf(
                "SELECT player, num FROM uo_played WHERE game=700 AND player=%d",
                $replacementId,
            ));
            $this->assertSame((string) $replacementId, (string) $row['player']);
            $this->assertSame('21', (string) $row['num']);
        } finally {
            DBQuery(sprintf("DELETE FROM uo_played WHERE player=%d AND game=700", $replacementId));
            DBQuery(sprintf("DELETE FROM uo_player WHERE player_id=%d", $replacementId));
        }
    }

    public function testRestoreCompletesTheFullReplayWhenAScorerCannotBeRematched(): void
    {
        // Unlike testRestoreRematchesADeletedPlayerByTeamAndJerseyNumber(),
        // nobody takes over jersey 77 on team 300 -- this player is
        // genuinely unresolvable, the way CanDeletePlayer() allows once a
        // roster edit has already dropped them from the game's current
        // uo_played row.
        DBQuery("INSERT INTO uo_player (firstname, lastname, team, num, accreditation_id, accredited, reg_id, profile_id)
                 VALUES ('Ghost', 'Scorer', 300, 77, NULL, 1, NULL, NULL)");
        $ghostPlayerId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        DBQuery(sprintf(
            "INSERT INTO uo_played (player, game, num, accredited, acknowledged, captain) VALUES (%d, 700, 77, 1, 0, 0)",
            $ghostPlayerId,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_goal (game, num, assist, scorer, time, homescore, visitorscore, ishomegoal, iscallahan)
             VALUES (700, 5, NULL, %d, 700, 3, 2, 1, 0)",
            $ghostPlayerId,
        ));
        DBQuery("INSERT INTO uo_timeout (game, num, time, ishome) VALUES (700, 1, 90, 1)");

        $snapshotId = (int) GameHistorySnapshotIfNeeded(700);

        DBQuery(sprintf("DELETE FROM uo_played WHERE player=%d AND game=700", $ghostPlayerId));
        DBQuery(sprintf("DELETE FROM uo_player WHERE player_id=%d", $ghostPlayerId));

        // Damage the state a half-rebuild would leave behind, so a crashed
        // replay and a completed one are distinguishable below.
        DBQuery("DELETE FROM uo_timeout WHERE game=700");
        DBQuery("UPDATE uo_game SET forfeit=1 WHERE game_id=700");

        $result = GameHistoryRestore($snapshotId);

        $this->assertTrue($result['restored']);
        $this->assertSame(
            ['Pool is locked.', 'Event played.', 'Player Ghost Scorer could not be restored.'],
            $result['warnings'],
        );

        $goal = DBQueryToRow("SELECT scorer, assist FROM uo_goal WHERE game=700 AND num=5");
        $this->assertNull($goal['scorer']);
        $this->assertNull($goal['assist']);

        // This is what actually pins the bug: an unresolved scorer id that
        // falls through to GameAddScoreEntry() instead of NULL violates
        // uo_goal's FK and aborts the replay before any of this runs, so
        // without these two checks a test could pass on the buggy code path
        // too as long as the crash happened to leave a NULL-looking goal.
        $timeoutCount = (int) DBQueryToValue("SELECT COUNT(*) FROM uo_timeout WHERE game=700");
        $this->assertSame(1, $timeoutCount);
        $forfeit = (int) DBQueryToValue("SELECT forfeit FROM uo_game WHERE game_id=700");
        $this->assertSame(0, $forfeit);
    }

    public function testFormatDetailRendersRoleAssignmentsNotPlayerZero(): void
    {
        GameSetCaptains(700, 300, [800]);
        $captainRow = DBQueryToRow(
            "SELECT action, detail FROM uo_game_history
             WHERE game=700 AND target='played' AND action='update' ORDER BY history_id DESC LIMIT 1",
        );
        $captainText = GameHistoryFormatDetail([
            'target' => 'played',
            'action' => $captainRow['action'],
            'detail' => $captainRow['detail'],
        ]);
        $this->assertNotSame('Player 0', $captainText);
        $this->assertSame('Captain: 1', $captainText);

        GameSetSpiritCaptains(700, 300, [800]);
        $spiritRow = DBQueryToRow(
            "SELECT action, detail FROM uo_game_history
             WHERE game=700 AND target='played' AND action='update' ORDER BY history_id DESC LIMIT 1",
        );
        $spiritText = GameHistoryFormatDetail([
            'target' => 'played',
            'action' => $spiritRow['action'],
            'detail' => $spiritRow['detail'],
        ]);
        $this->assertNotSame('Player 0', $spiritText);
        $this->assertSame('Spirit captain: 1', $spiritText);
    }

    public function testRestoreReproducesTheStartingOffence(): void
    {
        // GameSetStartingTeam() writes the raw uo_gameevent.type column value
        // 'offence', while GameHistoryFormatDetail() renders the same row's
        // audit label as "start" -- two different vocabularies. The replay in
        // GameHistoryRestore() must compare against the real column value,
        // not the label, or the starting offence can never be restored.
        GameSetStartingTeam(700, 1);
        // GameSetStartingTeam() now snapshots itself (Finding 2), which
        // captures state BEFORE its own write and would otherwise win the
        // per-request memo -- force a fresh capture so the snapshot actually
        // includes the offence event just written.
        $snapshotId = (int) GameHistorySnapshotIfNeeded(700, true);

        GameSetStartingTeam(700, null);
        $this->assertSame(
            0,
            (int) DBQueryToValue("SELECT COUNT(*) FROM uo_gameevent WHERE game=700 AND type='offence'"),
        );

        $result = GameHistoryRestore($snapshotId);

        $this->assertTrue($result['restored']);
        $row = DBQueryToRow("SELECT ishome FROM uo_gameevent WHERE game=700 AND type='offence'");
        $this->assertNotNull($row);
        $this->assertSame('1', (string) $row['ishome']);
    }

    public function testKeeperHalftimeAndStartingTeamShareOneSnapshotPerRequest(): void
    {
        // user/addscoresheet.php calls these three mutators, in this order,
        // before the first bulk Remove*() helper. The per-request memo must
        // still collapse all three to a single restore point.
        GameSetScoreSheetKeeper(701, 'Keeper 1');
        GameSetHalftime(701, 1800);
        GameSetStartingTeam(701, 1);

        $count = (int) DBQueryToValue(
            "SELECT COUNT(*) FROM uo_game_history WHERE game=701 AND has_snapshot=1",
        );
        $this->assertSame(1, $count);
    }

    public function testKeeperHalftimeAndStartingTeamChangedInOneSaveAreUndoable(): void
    {
        // Before the fix, only GameRemoveAllTimeouts() -- called after all
        // three of these in user/addscoresheet.php -- ever snapshotted, so a
        // save touching only the scorekeeper name, halftime and starting
        // offence had no restore point at all. Each of the three now
        // snapshots itself before its own write, so the FIRST of them
        // (GameSetScoreSheetKeeper here) is the one that captures the
        // pre-mutation state.
        GameSetScoreSheetKeeper(700, 'Keeper 1');
        GameSetHalftime(700, 1800);
        GameSetStartingTeam(700, 1);

        $snapshotId = (int) DBQueryToValue(
            "SELECT history_id FROM uo_game_history
             WHERE game=700 AND has_snapshot=1 ORDER BY history_id LIMIT 1",
        );
        $this->assertGreaterThan(0, $snapshotId);

        $result = GameHistoryRestore($snapshotId);

        $this->assertTrue($result['restored']);
        $game = DBQueryToRow("SELECT halftime, official FROM uo_game WHERE game_id=700");
        $this->assertSame('35', (string) $game['halftime']);
        $this->assertNull($game['official']);
        $this->assertSame(
            0,
            (int) DBQueryToValue("SELECT COUNT(*) FROM uo_gameevent WHERE game=700 AND type='offence'"),
        );
    }

    public function testRestoreRoundTripsADefenseWithAnUnresolvableAuthor(): void
    {
        // author is nullable, and GameHistoryMapPlayer() returns null for a
        // player restore could not resolve. GameAddDefense() must emit a real
        // SQL NULL instead of letting DBEscapeString(null) turn into '' -> 0,
        // which would violate fk_defense_author and silently drop the row.
        DBQuery("INSERT INTO uo_defense (game, num, author, time, iscallahan, iscaught, ishomedefense)
                 VALUES (700, 1, NULL, 200, 0, 1, 1)");

        $snapshotId = (int) GameHistorySnapshotIfNeeded(700);

        DBQuery("DELETE FROM uo_defense WHERE game=700");

        $result = GameHistoryRestore($snapshotId);

        $this->assertTrue($result['restored']);
        $row = DBQueryToRow(
            "SELECT author, iscaught, ishomedefense FROM uo_defense WHERE game=700 AND num=1",
        );
        $this->assertNotNull($row);
        $this->assertNull($row['author']);
    }

    public function testRestoreRemovesAPostSnapshotCapEventButLeavesMediaRowsAlone(): void
    {
        // uo_gameevent gets no explicit RemoveAll* before replay like goals,
        // defences and timeouts do, and GameSetCapEvent() is upsert-only, so
        // a cap set after the snapshot must be actively removed on restore --
        // not just left unmatched by the replay loop. Media rows must survive
        // untouched: they are guarded by hasAddMediaRight(), not
        // hasEditGameEventsRight(), and are excluded from snapshots.
        DBQuery("INSERT INTO uo_gameevent (game, num, time, type, ishome, info)
                 VALUES (701, 99, 600, 'media', 0, 12345)");

        $snapshotId = (int) GameHistorySnapshotIfNeeded(701);

        GameSetCapEvent(701, 'time_cap', 900, 13);
        $this->assertSame(
            1,
            (int) DBQueryToValue("SELECT COUNT(*) FROM uo_gameevent WHERE game=701 AND type='time_cap'"),
        );

        $result = GameHistoryRestore($snapshotId);

        $this->assertTrue($result['restored']);
        $this->assertSame(
            0,
            (int) DBQueryToValue("SELECT COUNT(*) FROM uo_gameevent WHERE game=701 AND type='time_cap'"),
        );
        $this->assertSame(
            1,
            (int) DBQueryToValue("SELECT COUNT(*) FROM uo_gameevent WHERE game=701 AND type='media'"),
        );
    }

    public function testRestoreLeavesAnEventTypeItCannotReplayUntouched(): void
    {
        // Game 700's fixture carries a 'turnover' uo_gameevent row (see
        // baseline.sql), a type the replay loop has no branch for. The
        // removal-before-replay pass in GameRemoveAllGameEvents() must be
        // scoped to only the types the replay CAN reinstate (offence and cap
        // events) -- a wider delete would silently destroy this row, which
        // the snapshot faithfully captured but restore could never put back.
        $snapshotId = (int) GameHistorySnapshotIfNeeded(700);

        $result = GameHistoryRestore($snapshotId);

        $this->assertTrue($result['restored']);
        $this->assertSame(
            1,
            (int) DBQueryToValue("SELECT COUNT(*) FROM uo_gameevent WHERE game=700 AND type='turnover'"),
        );
    }

    public function testRestorePreservesANullHalftimeInsteadOfZero(): void
    {
        // (int) ($snapshot['game']['halftime'] ?? 0) would turn a faithfully
        // preserved null into a spurious 0, producing a phantom halftime
        // marker -- see GameHalftimeSeconds()'s documented hazard.
        DBQuery("UPDATE uo_game SET halftime=NULL WHERE game_id=701");
        $snapshotId = (int) GameHistorySnapshotIfNeeded(701);

        GameSetHalftime(701, 1800);

        $result = GameHistoryRestore($snapshotId);

        $this->assertTrue($result['restored']);
        $halftime = DBQueryToValue("SELECT halftime FROM uo_game WHERE game_id=701");
        $this->assertNull($halftime);
    }

    public function testToFilterIncludesRowsRecordedOnTheChosenEndDate(): void
    {
        // admin/gamehistory.php feeds a bare YYYY-MM-DD from <input
        // type='date'>, which MySQL widens to 00:00:00 -- a plain `<=` would
        // exclude every row recorded later on the chosen end date.
        $id = (int) GameHistoryRecord(700, 'goal', 'add', ['num' => 1]);
        DBQuery(sprintf(
            "UPDATE uo_game_history SET time='%s 23:00:00' WHERE history_id=%d",
            date('Y-m-d'),
            $id,
        ));

        $rows = GameHistoryAll(['game' => 700, 'to' => date('Y-m-d')]);
        $this->assertCount(1, $rows);
        $this->assertSame(1, GameHistoryAllCount(['game' => 700, 'to' => date('Y-m-d')]));
    }

    public function testRestoreRestoresTheAcknowledgedFlagEvenWhenThePlayerHasChangedTeamsSinceTheSnapshot(): void
    {
        // V5 fix: GameHistoryRestorePlayers() no longer routes an acknowledged
        // roster flag through AcknowledgeUnaccredited(), which used to
        // re-read the player's CURRENT team via PlayerInfo() and die() on a
        // mismatch against the snapshot's team (an earlier fix turned that
        // die() into a warning instead). The row is now written directly into
        // uo_played from the snapshot, so a player moved between teams since
        // the snapshot no longer produces a warning or a dropped
        // acknowledgment -- the up-front hasAccredidationRight() check
        // against the SNAPSHOT's team is the only accreditation check left.
        DBQuery("INSERT INTO uo_player (firstname, lastname, team, num, accreditation_id, accredited, reg_id, profile_id)
                 VALUES ('Mover', 'Player', 300, 50, NULL, 1, NULL, NULL)");
        $playerId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        DBQuery(sprintf(
            "INSERT INTO uo_played (player, game, num, accredited, acknowledged, captain) VALUES (%d, 701, 50, 1, 1, 0)",
            $playerId,
        ));

        $snapshotId = (int) GameHistorySnapshotIfNeeded(701);

        // Move the player to a different team after the snapshot was taken.
        DBQuery(sprintf("UPDATE uo_player SET team=301 WHERE player_id=%d", $playerId));

        try {
            $result = GameHistoryRestore($snapshotId);

            $this->assertTrue($result['restored']);
            foreach ($result['warnings'] as $warning) {
                $this->assertStringNotContainsString('Mover Player', $warning);
            }

            $acknowledged = (int) DBQueryToValue(sprintf(
                "SELECT acknowledged FROM uo_played WHERE game=701 AND player=%d",
                $playerId,
            ));
            $this->assertSame(1, $acknowledged);
        } finally {
            DBQuery(sprintf("DELETE FROM uo_played WHERE player=%d AND game=701", $playerId));
            DBQuery(sprintf("DELETE FROM uo_player WHERE player_id=%d", $playerId));
        }
    }

    public function testRestoreDowngradesAcknowledgedFlagWhenAdminLacksAccreditationRightOnPlayersCurrentTeam(): void
    {
        // C2: the up-front guard in GameHistoryRestore() only rechecks
        // hasAccredidationRight() for the teams recorded in the SNAPSHOT
        // (team 301 here). GameHistoryRestorePlayerRow() must separately
        // recheck the player's CURRENT team before writing acknowledged=1,
        // or an admin holding the right only on the old team could grant an
        // acknowledgment on a team they have no accreditation right over.
        // Game 701 (respteam=301) is used, not 700, so the up-front guard's
        // acknowledged-team set contains only the one team this test
        // controls.
        DBQuery("INSERT INTO uo_player (firstname, lastname, team, num, accreditation_id, accredited, reg_id, profile_id)
                 VALUES ('Downgrade', 'Target', 301, 60, NULL, 1, NULL, NULL)");
        $playerId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        DBQuery(sprintf(
            "INSERT INTO uo_played (player, game, num, accredited, acknowledged, captain) VALUES (%d, 701, 60, 1, 1, 0)",
            $playerId,
        ));

        $snapshotId = (int) GameHistorySnapshotIfNeeded(701);

        // Move the player to a team this admin has no accreditation right on.
        DBQuery(sprintf("UPDATE uo_player SET team=300 WHERE player_id=%d", $playerId));

        // teamadmin[301] clears hasEditGameEventsRight()/hasEditGamePlayersRight()
        // for game 701 (respteam=301) and accradmin[301] clears the up-front
        // guard for the snapshot's team -- but neither grants
        // hasAccredidationRight(300), the player's CURRENT team.
        $_SESSION['userproperties']['userrole'] = ['teamadmin' => [301 => true], 'accradmin' => [301 => true]];
        $_SESSION['uid'] = 'teamadmin301';
        try {
            $result = GameHistoryRestore($snapshotId);

            $this->assertTrue($result['restored'], 'the restore must still complete, not abort');

            $warningsText = implode(' | ', $result['warnings']);
            $this->assertStringContainsString('Downgrade Target', $warningsText);

            $acknowledged = (int) DBQueryToValue(sprintf(
                "SELECT acknowledged FROM uo_played WHERE game=701 AND player=%d",
                $playerId,
            ));
            $this->assertSame(0, $acknowledged);
        } finally {
            $_SESSION['uid'] = 'testuser';
            $_SESSION['userproperties']['userrole'] = ['superadmin' => true];
            DBQuery(sprintf("DELETE FROM uo_played WHERE player=%d AND game=701", $playerId));
            DBQuery(sprintf("DELETE FROM uo_player WHERE player_id=%d", $playerId));
        }
    }

    public function testBuildSnapshotCapturesDefensesAndTimerFields(): void
    {
        DBQuery("UPDATE uo_game SET homedefenses=3, visitordefenses=2,
            timer_start=1000, timer_pause_start=NULL, timer_paused_duration=45
            WHERE game_id=700");

        $snapshot = GameHistoryBuildSnapshot(700);

        $this->assertSame(3, $snapshot['v']);
        $this->assertSame(3, $snapshot['game']['homedefenses']);
        $this->assertSame(2, $snapshot['game']['visitordefenses']);
        $this->assertSame(1000, $snapshot['game']['timer_start']);
        $this->assertNull($snapshot['game']['timer_pause_start']);
        $this->assertSame(45, $snapshot['game']['timer_paused_duration']);

        // v3: timer_elapsed is GameTimerState()'s own elapsed figure at
        // capture time -- not asserted against a literal, since it depends
        // on time() at the moment GameHistoryBuildSnapshot() ran, only that
        // it was captured and matches that formula within a tight window.
        $expectedElapsed = time() - 1000 - 45;
        $this->assertIsInt($snapshot['game']['timer_elapsed']);
        $this->assertEqualsWithDelta($expectedElapsed, $snapshot['game']['timer_elapsed'], 2);
    }

    public function testRestoreRoundTripsDefenseCountsAndTimerColumns(): void
    {
        // Paused fixture: 2100-2000-30=70 is the elapsed game time
        // GameTimerState() reports, and that figure is what a v3 restore
        // must reproduce -- not the literal 2000/2100 epoch, which
        // GameHistoryRestoreResult() now derives fresh from the captured
        // elapsed time instead of replaying verbatim (see C3 in
        // docs/game-history.md). isongoing=1 is needed too: GameTimerState()
        // only reports paused/elapsed for an ongoing game, and the fixture's
        // default isongoing=0 (final result) would mask this regardless of
        // the timer columns.
        DBQuery("UPDATE uo_game SET homedefenses=4, visitordefenses=1, isongoing=1,
            timer_start=2000, timer_pause_start=2100, timer_paused_duration=30
            WHERE game_id=700");

        $snapshotId = (int) GameHistorySnapshotIfNeeded(700);

        // Damage the state the way a later save (GameSetDefenses(), or the
        // timer helpers in lib/game.functions.php) would.
        DBQuery("UPDATE uo_game SET homedefenses=0, visitordefenses=0,
            timer_start=NULL, timer_pause_start=NULL, timer_paused_duration=0
            WHERE game_id=700");

        $result = GameHistoryRestore($snapshotId);

        $this->assertTrue($result['restored']);
        $game = DBQueryToRow(
            "SELECT homedefenses, visitordefenses, timer_start, timer_pause_start, timer_paused_duration
             FROM uo_game WHERE game_id=700",
        );
        $this->assertSame('4', (string) $game['homedefenses']);
        $this->assertSame('1', (string) $game['visitordefenses']);

        $state = GameTimerState(700);
        $this->assertTrue($state['paused'], 'the restored snapshot was captured while paused');
        $this->assertSame(70, $state['elapsed'], 'paused elapsed is frozen at capture time: 2100-2000-30');
        $this->assertSame('0', (string) $game['timer_paused_duration']);
    }

    public function testRestorePreservesNullTimerColumnsInsteadOfZero(): void
    {
        // GameClearResult()/GameSetResult() both always NULL the timer
        // columns as part of their own write; a game whose snapshot recorded
        // no running clock must come back NULL, not a phantom 0 or the
        // damaged value.
        DBQuery("UPDATE uo_game SET timer_start=NULL, timer_pause_start=NULL,
            timer_paused_duration=0 WHERE game_id=701");
        $snapshotId = (int) GameHistorySnapshotIfNeeded(701);

        DBQuery("UPDATE uo_game SET timer_start=9999, timer_pause_start=9999,
            timer_paused_duration=5 WHERE game_id=701");

        $result = GameHistoryRestore($snapshotId);

        $this->assertTrue($result['restored']);
        $game = DBQueryToRow("SELECT timer_start, timer_pause_start FROM uo_game WHERE game_id=701");
        $this->assertNull($game['timer_start']);
        $this->assertNull($game['timer_pause_start']);
    }

    public function testRestoreLeavesDefensesUnchangedForAnOldFormatSnapshotButStillNullsTheTimer(): void
    {
        // A v1 snapshot (pre-V1-fix) never captured these keys at all.
        // Simulate one by stripping them from a freshly built snapshot before
        // storing it, then confirm restore treats their absence as "leave
        // unchanged, don't reset to zero/NULL" for defenses -- nothing in the
        // replay touches uo_game.homedefenses/visitordefenses except the
        // guarded GameSetDefenses() call, which is skipped entirely when the
        // keys are missing.
        //
        // The timer columns are different: GameSetResult()/GameClearResult()
        // -- the ordinary result mutators the replay always calls -- NULL
        // them unconditionally as part of restoring the score, the same as
        // they do on every normal result edit. That NULLing is pre-existing
        // behavior, not something this fix introduces; a v1 snapshot simply
        // has no captured value to write back afterward, so the mutator's
        // side effect is what a v1 restore is left with.
        $snapshot = GameHistoryBuildSnapshot(700);
        $snapshot['v'] = 1;
        unset(
            $snapshot['game']['homedefenses'],
            $snapshot['game']['visitordefenses'],
            $snapshot['game']['timer_start'],
            $snapshot['game']['timer_pause_start'],
            $snapshot['game']['timer_paused_duration'],
            $snapshot['game']['timer_elapsed'],
        );
        $historyId = (int) DBQueryInsert(sprintf(
            "INSERT INTO uo_game_history (game, user_id, ip, source, target, action, has_snapshot, snapshot)
             VALUES (700, 'testuser', '203.0.113.7', 'user', 'snapshot', 'capture', 1, '%s')",
            DBEscapeString(json_encode($snapshot)),
        ));

        DBQuery("UPDATE uo_game SET homedefenses=7, visitordefenses=6,
            timer_start=5000, timer_pause_start=5100, timer_paused_duration=20
            WHERE game_id=700");

        $result = GameHistoryRestore($historyId);

        $this->assertTrue($result['restored']);
        $game = DBQueryToRow(
            "SELECT homedefenses, visitordefenses, timer_start, timer_pause_start, timer_paused_duration
             FROM uo_game WHERE game_id=700",
        );
        $this->assertSame('7', (string) $game['homedefenses']);
        $this->assertSame('6', (string) $game['visitordefenses']);
        $this->assertNull($game['timer_start']);
        $this->assertNull($game['timer_pause_start']);
        $this->assertSame('0', (string) $game['timer_paused_duration']);
    }

    public function testRestoreOfAnOlderSnapshotWithoutTimerElapsedStillReplaysTheEpochVerbatim(): void
    {
        // v1/v2 fallback: a snapshot that predates the C3 fix (or a v2
        // snapshot restored before this branch existed) has no
        // timer_elapsed key, so GameHistoryRestoreResult() must fall back to
        // writing the captured timer_start/timer_pause_start/
        // timer_paused_duration back verbatim -- the same behavior a v2
        // snapshot always had, without a warning or a fatal.
        DBQuery("UPDATE uo_game SET timer_start=2000, timer_pause_start=2100,
            timer_paused_duration=30 WHERE game_id=700");
        $snapshot = GameHistoryBuildSnapshot(700);
        $snapshot['v'] = 2;
        unset($snapshot['game']['timer_elapsed']);
        $historyId = (int) DBQueryInsert(sprintf(
            "INSERT INTO uo_game_history (game, user_id, ip, source, target, action, has_snapshot, snapshot)
             VALUES (700, 'testuser', '203.0.113.7', 'user', 'snapshot', 'capture', 1, '%s')",
            DBEscapeString(json_encode($snapshot)),
        ));

        DBQuery("UPDATE uo_game SET timer_start=NULL, timer_pause_start=NULL,
            timer_paused_duration=0 WHERE game_id=700");

        $result = GameHistoryRestore($historyId);

        $this->assertTrue($result['restored']);
        $game = DBQueryToRow(
            "SELECT timer_start, timer_pause_start, timer_paused_duration FROM uo_game WHERE game_id=700",
        );
        $this->assertSame('2000', (string) $game['timer_start']);
        $this->assertSame('2100', (string) $game['timer_pause_start']);
        $this->assertSame('30', (string) $game['timer_paused_duration']);
    }

    public function testRestoreOfARunningClockReproducesTheElapsedTimeAtCaptureNotTheWallClockDelayUntilRestore(): void
    {
        // C3: timer_start is an absolute Unix epoch (see GameTimerState()),
        // so replaying it verbatim after a delay between capture and
        // restore counts that delay as game time. This simulates the delay
        // without sleeping: capture a REAL snapshot via
        // GameHistorySnapshotIfNeeded() (so GameHistoryBuildSnapshot()
        // actually computes and stores timer_elapsed), then push the STORED
        // snapshot's timer_start back by an hour before restoring -- if the
        // restore replayed that epoch verbatim, the extra hour would show up
        // as elapsed game time.
        DBQuery("UPDATE uo_game SET timer_start=" . (time() - 300) . ",
            timer_pause_start=NULL, timer_paused_duration=0 WHERE game_id=700");
        $snapshotId = (int) GameHistorySnapshotIfNeeded(700);

        $row = DBQueryToRow("SELECT snapshot FROM uo_game_history WHERE history_id=$snapshotId");
        $snapshot = json_decode($row['snapshot'], true);
        $this->assertEqualsWithDelta(300, $snapshot['game']['timer_elapsed'], 2);

        // Simulate an hour passing between capture and restore.
        $snapshot['game']['timer_start'] -= 3600;
        DBQuery(sprintf(
            "UPDATE uo_game_history SET snapshot='%s' WHERE history_id=%d",
            DBEscapeString(json_encode($snapshot)),
            $snapshotId,
        ));

        DBQuery("UPDATE uo_game SET timer_start=NULL, timer_pause_start=NULL,
            timer_paused_duration=0 WHERE game_id=700");

        $result = GameHistoryRestore($snapshotId);

        $this->assertTrue($result['restored']);
        $state = GameTimerState(700);
        $this->assertFalse($state['paused']);
        $this->assertEqualsWithDelta(300, $state['elapsed'], 5, 'a stale hour-old epoch must not be replayed as extra elapsed game time');
    }

    public function testSetGameCommentSnapshotsBeforeOverwritingSoABulkSaveCommentChangeIsUndoable(): void
    {
        // Seed an initial comment as though an earlier save wrote it.
        SetGameComment(COMMENT_TYPE_GAME, 700, 'Original comment');
        DBQuery("DELETE FROM uo_game_history WHERE game=700");
        CacheForgetNamespace('game_history_snapshot');

        // Simulate a desktop bulk save: the comment changes together with
        // another destructive mutator in the same request. SetGameComment()
        // must capture the OLD comment before ApplyCommentChange() overwrites
        // it, or the memoized snapshot (won by whichever mutator runs first)
        // would hold the ALREADY-updated comment instead.
        SetGameComment(COMMENT_TYPE_GAME, 700, 'Updated comment');
        GameSetHalftime(700, 1800);

        $snapshotId = (int) DBQueryToValue(
            "SELECT history_id FROM uo_game_history
             WHERE game=700 AND has_snapshot=1 ORDER BY history_id LIMIT 1",
        );
        $this->assertGreaterThan(0, $snapshotId);

        $entry = GameHistoryEntry($snapshotId);
        $this->assertSame('Original comment', $entry['snapshot']['comment']);

        $result = GameHistoryRestore($snapshotId);
        $this->assertTrue($result['restored']);
        $this->assertSame('Original comment', CommentRaw(COMMENT_TYPE_GAME, 700));
    }

    public function testGameUpdateResultSnapshotsByDefault(): void
    {
        GameUpdateResult(701, 5, 3);

        $count = (int) DBQueryToValue(
            "SELECT COUNT(*) FROM uo_game_history WHERE game=701 AND has_snapshot=1",
        );
        $this->assertSame(1, $count);
    }

    public function testGameUpdateResultSkipsSnapshotWhenToldTo(): void
    {
        // The two per-point callers (mobile/addscoresheet.php,
        // scorekeeper/addscoresheet.php) pass false so a full scoresheet does
        // not produce roughly one snapshot per point.
        GameUpdateResult(701, 1, 0, false);

        $count = (int) DBQueryToValue(
            "SELECT COUNT(*) FROM uo_game_history WHERE game=701 AND has_snapshot=1",
        );
        $this->assertSame(0, $count);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testForceCapturedSnapshotAndRestoreAuditRowSurviveWhileRecordingIsDisabled(): void
    {
        // IsGameHistoryDisabled() caches in a function-static for the process
        // lifetime (see setUp()'s comment above), so the "disabled" branch is
        // only observable in a process of its own -- the setting must be
        // flipped BEFORE the first call in this process, which happens below,
        // not in the shared setUp().
        DBQuery("DELETE FROM uo_setting WHERE name='DisableGameHistory'");
        DBQuery("INSERT INTO uo_setting (name, value) VALUES ('DisableGameHistory', 'true')");
        $this->assertTrue(IsGameHistoryDisabled());

        try {
            // An ordinary (non-forced) snapshot must not be written while disabled.
            $this->assertFalse(GameHistorySnapshotIfNeeded(700));
            $this->assertSame(
                0,
                (int) DBQueryToValue("SELECT COUNT(*) FROM uo_game_history WHERE game=700"),
            );

            // Seed a restorable snapshot by force-capturing, the same as
            // GameHistoryRestore()'s own pre-restore capture.
            $snapshotId = (int) GameHistorySnapshotIfNeeded(700, true);
            $this->assertGreaterThan(0, $snapshotId);

            GameRemoveAllScores(700);
            DBQuery("DELETE FROM uo_game_history WHERE game=700 AND has_snapshot=0");

            $result = GameHistoryRestore($snapshotId);

            $this->assertTrue($result['restored']);
            $this->assertSame(4, (int) DBQueryToValue("SELECT COUNT(*) FROM uo_goal WHERE game=700"));

            // The restore's own pre-restore force-capture (of the damaged state)
            // and its restore audit row must both survive DisableGameHistory=true.
            $snapshots = (int) DBQueryToValue(
                "SELECT COUNT(*) FROM uo_game_history WHERE game=700 AND has_snapshot=1",
            );
            $this->assertSame(2, $snapshots);
            $restoreRows = (int) DBQueryToValue(
                "SELECT COUNT(*) FROM uo_game_history WHERE game=700 AND target='restore'",
            );
            $this->assertSame(1, $restoreRows);
        } finally {
            // This test runs in its own process (see the RunInSeparateProcess
            // attribute above), but the setting row it flips to 'true' is real,
            // shared DB state that outlives the process -- reset it so a test
            // running in a later process doesn't inherit recording disabled.
            DBQuery("DELETE FROM uo_setting WHERE name='DisableGameHistory'");
            DBQuery("INSERT INTO uo_setting (name, value) VALUES ('DisableGameHistory', 'false')");
        }
    }

    public function testRestoreKeepsAnAcknowledgedUnaccreditedPlayerInAnAccreditationRequiredEvent(): void
    {
        // Fixture players are all accredited=1; insert a genuinely
        // unaccredited player who was only allowed onto game 700's roster via
        // the acknowledged-unaccredited exception. Before the V5 fix,
        // rebuilding the roster through GameAddPlayer() during restore would
        // reject this player once GameRemoveAllPlayers() had cleared the
        // roster and destroyed GameAllowsPlayerOnRoster()'s "already on this
        // game's roster" fallback -- dropping them even though the snapshot
        // recorded acknowledged=1.
        DBQuery("INSERT INTO uo_player (firstname, lastname, team, num, accreditation_id, accredited, reg_id, profile_id)
                 VALUES ('Unaccredited', 'Player', 300, 99, NULL, 0, NULL, NULL)");
        $playerId = (int) DBQueryToValue("SELECT LAST_INSERT_ID()");
        DBQuery(sprintf(
            "INSERT INTO uo_played (player, game, num, accredited, acknowledged, captain) VALUES (%d, 700, 99, 0, 1, 0)",
            $playerId,
        ));

        DBQuery("UPDATE uo_season SET require_accreditation=1 WHERE season_id='HRN2026'");

        $snapshotId = (int) GameHistorySnapshotIfNeeded(700);

        // Damage the roster the way a later save would.
        DBQuery(sprintf("DELETE FROM uo_played WHERE game=700 AND player=%d", $playerId));

        try {
            $result = GameHistoryRestore($snapshotId);

            $this->assertTrue($result['restored']);
            foreach ($result['warnings'] as $warning) {
                $this->assertStringNotContainsString('Unaccredited Player', $warning);
            }

            $row = DBQueryToRow(sprintf(
                "SELECT num, accredited, acknowledged FROM uo_played WHERE game=700 AND player=%d",
                $playerId,
            ));
            $this->assertNotNull($row, 'the acknowledged unaccredited player must survive the restore');
            $this->assertSame('99', (string) $row['num']);
            $this->assertSame('0', (string) $row['accredited']);
            $this->assertSame('1', (string) $row['acknowledged']);
        } finally {
            DBQuery("UPDATE uo_season SET require_accreditation=0 WHERE season_id='HRN2026'");
            DBQuery(sprintf("DELETE FROM uo_played WHERE player=%d AND game=700", $playerId));
            DBQuery(sprintf("DELETE FROM uo_player WHERE player_id=%d", $playerId));
        }
    }

    public function testRestoreRestoresCaptainAndSpiritCaptainFlags(): void
    {
        // GameHistoryRestorePlayers() no longer makes a separate
        // GameSetRolePlayers() pass after the roster rewrite (see V5) --
        // captain/spirit_captain correctness now rests entirely on
        // GameHistoryRestorePlayerRow()'s direct INSERT including those two
        // columns. Fixture game 700 already flags players 800 and 802
        // captain=1; add a spirit captain too so both roles are covered.
        GameSetSpiritCaptains(700, 300, [801]);
        // GameSetSpiritCaptains() snapshots itself (via GameSetRolePlayers())
        // BEFORE its own write, which would otherwise win the per-request
        // memo and capture the state prior to the spirit-captain flag being
        // set -- force a fresh capture, same as
        // testRestoreReproducesTheStartingOffence() does for the same reason.
        $snapshotId = (int) GameHistorySnapshotIfNeeded(700, true);

        // Damage every role flag on the game the way GameRemoveAllPlayers()
        // (called at the start of GameHistoryRestorePlayers()) effectively
        // does, and confirm the damage actually took -- otherwise a restore
        // that silently drops the captain/spirit_captain columns from the
        // direct write could pass this test by accident.
        DBQuery("UPDATE uo_played SET captain=0, spirit_captain=0 WHERE game=700");
        $this->assertSame(
            0,
            (int) DBQueryToValue(
                "SELECT COUNT(*) FROM uo_played WHERE game=700 AND (captain=1 OR spirit_captain=1)",
            ),
        );

        $result = GameHistoryRestore($snapshotId);

        $this->assertTrue($result['restored']);

        $captains = DBQueryToArray("SELECT player FROM uo_played WHERE game=700 AND captain=1 ORDER BY player");
        $this->assertSame([800, 802], array_map('intval', array_column($captains, 'player')));

        $spiritCaptain = (int) DBQueryToValue(
            "SELECT player FROM uo_played WHERE game=700 AND spirit_captain=1",
        );
        $this->assertSame(801, $spiritCaptain);
    }
}
