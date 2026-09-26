<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

if (!function_exists('utf8entities')) {
    function utf8entities(mixed $s): string
    {
        return htmlentities((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * A read-only event locks its game notes.
 *
 * hasEditGameEventsRight() refuses every non-superadmin once the event is
 * read-only. CanManageGameComment() additionally lets a note's original author
 * edit or delete it after losing that right, and that path never looks at the
 * read-only flag. The test writes a note as the responsible team's admin, locks
 * the event, and tries to change the note as the same user.
 */
final class GameCommentReadonlyTest extends TestCase
{
    private const GAME = 700;
    private const AUTHOR = 'harness-note-author';

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(['user.functions.php', 'comment.functions.php', 'game.functions.php'], 'pool_stack');
        global $serverConf;
        $serverConf['PersistentCacheEnabled'] = 'false';
        self::cleanUp();
        // Game 700's responsible team is 300.
        $_SESSION['uid'] = self::AUTHOR;
        $_SESSION['userproperties']['userrole'] = ['teamadmin' => [300 => 1]];
    }

    protected function tearDown(): void
    {
        DBQuery("UPDATE uo_season SET event_readonly=0 WHERE season_id='HRN2026'");
        self::clearCaches();
        self::cleanUp();
        unset($_SESSION['uid'], $_SESSION['userproperties']);
        LegacyApp::closeDatabaseConnection();
    }

    public function testAuthorCanChangeTheNoteWhileTheEventIsOpen(): void
    {
        $this->assertTrue(SetGameComment(COMMENT_TYPE_GAME, self::GAME, 'First note'));
        $this->assertTrue(SetGameComment(COMMENT_TYPE_GAME, self::GAME, 'Edited note'));
        $this->assertSame('Edited note', CommentRaw(COMMENT_TYPE_GAME, self::GAME));
    }

    public function testAuthorCannotChangeTheNoteOnceTheEventIsReadonly(): void
    {
        $this->assertTrue(SetGameComment(COMMENT_TYPE_GAME, self::GAME, 'First note'));
        self::lockEvent();

        // Precondition: the lock took effect for this user's game rights.
        $this->assertFalse(hasEditGameEventsRight(self::GAME));

        $this->assertFalse(SetGameComment(COMMENT_TYPE_GAME, self::GAME, 'Edited after lock'));
        $this->assertSame('First note', CommentRaw(COMMENT_TYPE_GAME, self::GAME));
    }

    public function testAuthorCannotDeleteTheNoteOnceTheEventIsReadonly(): void
    {
        $this->assertTrue(SetGameComment(COMMENT_TYPE_GAME, self::GAME, 'First note'));
        self::lockEvent();

        $this->assertFalse(SetGameComment(COMMENT_TYPE_GAME, self::GAME, '', true));
        $this->assertSame('First note', CommentRaw(COMMENT_TYPE_GAME, self::GAME));
    }

    private static function lockEvent(): void
    {
        DBQuery("UPDATE uo_season SET event_readonly=1 WHERE season_id='HRN2026'");
        self::clearCaches();
    }

    private static function clearCaches(): void
    {
        ClearSeasonRuntimeCache();
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            CacheForgetNamespace($ns);
        }
    }

    private static function cleanUp(): void
    {
        DBQuery(sprintf("DELETE FROM uo_comment WHERE type=%d AND id='%d'", COMMENT_TYPE_GAME, self::GAME));
        DBQuery(sprintf(
            "DELETE FROM uo_event_log WHERE category='game' AND source='comments' AND id1='%d'",
            self::GAME,
        ));
        DBQuery(sprintf("DELETE FROM uo_scoresheet_history WHERE game=%d", self::GAME));
    }
}
