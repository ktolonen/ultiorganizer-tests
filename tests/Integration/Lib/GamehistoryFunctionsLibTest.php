<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class GamehistoryFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('gamehistory.functions.php', 'database_only');

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
    }

    protected function tearDown(): void
    {
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
}
