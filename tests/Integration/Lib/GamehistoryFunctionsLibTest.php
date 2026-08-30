<?php

declare(strict_types=1);

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

        $this->assertSame(1, $snapshot['v']);
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
}
