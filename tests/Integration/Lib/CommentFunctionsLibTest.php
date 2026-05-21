<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

// utf8entities() lives in localization.php (not a lib file). Provide a shim so
// CommentMetaHtml() and GameCommentHtml() can be exercised without loading the
// full page-level bootstrap (gettext, locale configuration, etc.).
if (!function_exists('utf8entities')) {
    function utf8entities(string $s): string
    {
        return htmlentities($s, ENT_QUOTES, 'UTF-8');
    }
}

final class CommentFunctionsLibTest extends TestCase
{
    // Fixture constants: game_id=700 (hometeam=300 vs visitorteam=301).
    //
    // NOTE: user.functions.php transitively loads common.functions.php →
    // spirit.functions.php, so hasEditGameEventsRight, HasFullGameSpiritEditRight,
    // CanEditSpiritSubmission, SpiritTeamIdForCommentType etc. are all defined.
    // Permission tests exercise the real permission logic, not the function_exists guards.

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        // user.functions.php chain transitively loads common.functions.php
        // (→ comment.functions.php, spirit.functions.php) and logging.functions.php.
        LegacyApp::loadLibFilesUsingProfile(
            ['user.functions.php', 'comment.functions.php'],
            'database_only'
        );

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        // Non-anonymous uid so isLoggedIn() returns true.
        $_SESSION['uid'] = 'testuser';
        // Super-admin for write paths that need admin rights.
        $_SESSION['userproperties']['userrole']['superadmin'] = true;

        global $serverConf;
        if (!isset($serverConf)) {
            $serverConf = [];
        }
        $serverConf['PersistentCacheEnabled'] = 'false';
    }

    protected function tearDown(): void
    {
        // Clean up any test comments we inserted.
        DBQuery("DELETE FROM uo_comment WHERE type IN (4,5) AND id IN (700, 701)");
        unset($_SESSION['uid'], $_SESSION['userproperties']);
        LegacyApp::closeDatabaseConnection();
    }

    // --- CommentNormalize ---

    public function testCommentNormalizeTrimsWhitespace(): void
    {
        $this->assertSame('hello', CommentNormalize('  hello  '));
    }

    public function testCommentNormalizeTruncatesOverlongString(): void
    {
        $long = str_repeat('x', COMMENT_MAX_LENGTH + 10);
        $this->assertSame(COMMENT_MAX_LENGTH, strlen(CommentNormalize($long)));
    }

    public function testCommentNormalizeHandlesEmptyString(): void
    {
        $this->assertSame('', CommentNormalize(''));
    }

    // --- CommentKeyForType ---

    public function testCommentKeyForTypeGame(): void
    {
        $this->assertSame('game', CommentKeyForType(COMMENT_TYPE_GAME));
    }

    public function testCommentKeyForTypeSpiritHome(): void
    {
        $this->assertSame('spirit_home', CommentKeyForType(COMMENT_TYPE_SPIRIT_HOME));
    }

    public function testCommentKeyForTypeSpiritVisitor(): void
    {
        $this->assertSame('spirit_visitor', CommentKeyForType(COMMENT_TYPE_SPIRIT_VISITOR));
    }

    public function testCommentKeyForTypeDefaultComment(): void
    {
        $this->assertSame('comment', CommentKeyForType(COMMENT_TYPE_SEASON));
    }

    // --- CommentLabelForType ---

    public function testCommentLabelForTypeGame(): void
    {
        $this->assertSame('Game note', CommentLabelForType(COMMENT_TYPE_GAME));
    }

    public function testCommentLabelForTypeSpiritHome(): void
    {
        $this->assertSame('Spirit note (home)', CommentLabelForType(COMMENT_TYPE_SPIRIT_HOME));
    }

    public function testCommentLabelForTypeSpiritVisitor(): void
    {
        $this->assertSame('Spirit note (visitor)', CommentLabelForType(COMMENT_TYPE_SPIRIT_VISITOR));
    }

    public function testCommentLabelForTypeDefaultComment(): void
    {
        $this->assertSame('Comment', CommentLabelForType(COMMENT_TYPE_SEASON));
    }

    // --- SpiritCommentTypeForTeam ---

    public function testSpiritCommentTypeForTeamReturnsSpiritHomeForHomeTeam(): void
    {
        $gameResult = ['hometeam' => 300, 'visitorteam' => 301];
        $this->assertSame(COMMENT_TYPE_SPIRIT_HOME, SpiritCommentTypeForTeam($gameResult, 300));
    }

    public function testSpiritCommentTypeForTeamReturnsSpiritVisitorForVisitorTeam(): void
    {
        $gameResult = ['hometeam' => 300, 'visitorteam' => 301];
        $this->assertSame(COMMENT_TYPE_SPIRIT_VISITOR, SpiritCommentTypeForTeam($gameResult, 301));
    }

    public function testSpiritCommentTypeForTeamReturnsZeroForUnknownTeam(): void
    {
        $gameResult = ['hometeam' => 300, 'visitorteam' => 301];
        $this->assertSame(0, SpiritCommentTypeForTeam($gameResult, 999));
    }

    public function testSpiritCommentTypeForTeamReturnsZeroWhenFieldsMissing(): void
    {
        $this->assertSame(0, SpiritCommentTypeForTeam([], 300));
    }

    // --- CommentRaw ---

    public function testCommentRawReturnsEmptyStringWhenNoComment(): void
    {
        $this->assertSame('', CommentRaw(COMMENT_TYPE_GAME, 99999));
    }

    public function testCommentRawReturnsStoredComment(): void
    {
        SetComment(COMMENT_TYPE_GAME, 700, 'Test game comment');
        $this->assertSame('Test game comment', CommentRaw(COMMENT_TYPE_GAME, 700));
    }

    // --- GameCommentMeta ---

    public function testGameCommentMetaReturnsEmptyMetaWhenNoEvents(): void
    {
        $meta = GameCommentMeta(99999, COMMENT_TYPE_GAME);
        $this->assertIsArray($meta);
        $this->assertSame('', $meta['created_by']);
        $this->assertSame('', $meta['created_at']);
        $this->assertSame('', $meta['updated_by']);
        $this->assertSame('', $meta['updated_at']);
    }

    // --- CommentMetaHtml ---

    public function testCommentMetaHtmlReturnsEmptyWhenNoData(): void
    {
        $meta = ['created_by' => '', 'created_at' => '', 'updated_by' => '', 'updated_at' => ''];
        $this->assertSame('', CommentMetaHtml($meta));
    }

    public function testCommentMetaHtmlIncludesCreatedInfo(): void
    {
        $meta = ['created_by' => 'alice', 'created_at' => '2026-01-01 12:00:00', 'updated_by' => '', 'updated_at' => ''];
        $html = CommentMetaHtml($meta);
        $this->assertStringContainsString('alice', $html);
        $this->assertStringContainsString('commentmeta', $html);
    }

    public function testCommentMetaHtmlIncludesUpdatedInfo(): void
    {
        $meta = ['created_by' => 'alice', 'created_at' => '2026-01-01', 'updated_by' => 'bob', 'updated_at' => '2026-01-02'];
        $html = CommentMetaHtml($meta);
        $this->assertStringContainsString('bob', $html);
    }

    // --- GameCommentHtml ---

    public function testGameCommentHtmlReturnsEmptyWhenNoComment(): void
    {
        $this->assertSame('', GameCommentHtml(99999, COMMENT_TYPE_GAME));
    }

    public function testGameCommentHtmlReturnsHtmlWhenCommentExists(): void
    {
        SetComment(COMMENT_TYPE_GAME, 700, 'Test HTML comment');
        $html = GameCommentHtml(700, COMMENT_TYPE_GAME);
        $this->assertStringContainsString('comment', $html);
        $this->assertStringContainsString('Test HTML comment', $html);
    }

    // --- CanCreateGameComment ---
    // hasEditGameEventsRight is in user.functions.php (always loaded), so the
    // function_exists guard never fires. hasEditGameEventsRight calls GameRespTeam
    // (game.functions.php, not loaded here), so only the short-circuit false path
    // (uid='anonymous') is safe to exercise.

    public function testCanCreateGameCommentReturnsFalseWhenNotLoggedIn(): void
    {
        $_SESSION['uid'] = 'anonymous';
        $this->assertFalse(CanCreateGameComment(700));
    }

    // --- CanCreateSpiritComment ---
    // spirit.functions.php (via common.functions.php) defines CanEditSpiritSubmission,
    // so the fallback branch (lines 213-224) is unreachable. For superadmin,
    // HasFullGameSpiritEditRight returns true via hasSpiritEditRight without calling
    // GameSeries (game.functions.php), making the superadmin path safe to test.

    public function testCanCreateSpiritCommentReturnsFalseWhenNotLoggedIn(): void
    {
        $_SESSION['uid'] = 'anonymous';
        $gameResult = ['hometeam' => 300, 'visitorteam' => 301, 'game_id' => 700];
        $this->assertFalse(CanCreateSpiritComment($gameResult, 300));
    }

    public function testCanCreateSpiritCommentReturnsTrueForSuperAdmin(): void
    {
        // Logged in + superadmin → CanEditSpiritSubmission → HasFullGameSpiritEditRight
        // → hasSpiritEditRight (superadmin) → true, without calling GameSeries.
        $gameResult = ['hometeam' => 300, 'visitorteam' => 301, 'game_id' => 700];
        $this->assertTrue(CanCreateSpiritComment($gameResult, 300));
    }

    // --- CanManageGameComment ---
    // hasEditGameEventsRight always calls GameRespTeam (game.functions.php), so only
    // the not-logged-in short-circuit path is safe.

    public function testCanManageGameCommentReturnsFalseWhenNotLoggedIn(): void
    {
        $_SESSION['uid'] = 'anonymous';
        $this->assertFalse(CanManageGameComment(700, COMMENT_TYPE_GAME));
    }

    // --- CanManageSpiritComment ---
    // For superadmin, HasFullGameSpiritEditRight returns true via hasSpiritEditRight
    // (which checks isSuperAdmin) without calling GameSeries, making it safe.

    public function testCanManageSpiritCommentReturnsFalseWhenNotLoggedIn(): void
    {
        $_SESSION['uid'] = 'anonymous';
        $this->assertFalse(CanManageSpiritComment(700, COMMENT_TYPE_SPIRIT_HOME));
    }

    public function testCanManageSpiritCommentReturnsTrueForSuperAdmin(): void
    {
        // HasFullGameSpiritEditRight(700): superadmin → hasSpiritEditRight → true.
        $this->assertTrue(CanManageSpiritComment(700, COMMENT_TYPE_SPIRIT_HOME));
    }

    // --- LogGameCommentEvent ---

    public function testLogGameCommentEventRunsWithoutErrorWhenLog1Defined(): void
    {
        // Log1 IS defined (from logging.functions.php, loaded via user.functions.php).
        LogGameCommentEvent(700, COMMENT_TYPE_GAME, 'comment_create');
        $this->assertTrue(true);
    }

    // --- CommentRequestedChange ---

    public function testCommentRequestedChangeNoopWhenNoCommentAndNoExisting(): void
    {
        $change = CommentRequestedChange(COMMENT_TYPE_GAME, 99999, '');
        $this->assertSame('noop', $change['action']);
    }

    public function testCommentRequestedChangeDeleteWhenCommentExistsAndDeleteTrue(): void
    {
        SetComment(COMMENT_TYPE_GAME, 700, 'Existing comment');
        $change = CommentRequestedChange(COMMENT_TYPE_GAME, 700, 'anything', true);
        $this->assertSame('delete', $change['action']);
    }

    public function testCommentRequestedChangeDeleteWhenEmptyCommentAndExisting(): void
    {
        SetComment(COMMENT_TYPE_GAME, 700, 'Some comment');
        $change = CommentRequestedChange(COMMENT_TYPE_GAME, 700, '');
        $this->assertSame('delete', $change['action']);
    }

    public function testCommentRequestedChangeCreateWhenNoExistingAndNewComment(): void
    {
        DBQuery("DELETE FROM uo_comment WHERE type=4 AND id=700");
        $change = CommentRequestedChange(COMMENT_TYPE_GAME, 700, 'New comment');
        $this->assertSame('create', $change['action']);
        $this->assertSame('New comment', $change['comment']);
    }

    public function testCommentRequestedChangeUpdateWhenExistingDiffers(): void
    {
        SetComment(COMMENT_TYPE_GAME, 700, 'Original comment');
        $change = CommentRequestedChange(COMMENT_TYPE_GAME, 700, 'Updated comment');
        $this->assertSame('update', $change['action']);
    }

    public function testCommentRequestedChangeNoopWhenCommentSameAsExisting(): void
    {
        SetComment(COMMENT_TYPE_GAME, 700, 'Same comment');
        $change = CommentRequestedChange(COMMENT_TYPE_GAME, 700, 'Same comment');
        $this->assertSame('noop', $change['action']);
    }

    // --- ApplyCommentChange ---

    public function testApplyCommentChangeNoopReturnsTrueWithoutSideEffects(): void
    {
        SetComment(COMMENT_TYPE_GAME, 700, 'Noop test');
        $result = ApplyCommentChange(COMMENT_TYPE_GAME, 700, ['action' => 'noop', 'comment' => 'Noop test']);
        $this->assertTrue($result);
        $this->assertSame('Noop test', CommentRaw(COMMENT_TYPE_GAME, 700));
    }

    public function testApplyCommentChangeCreateSetsComment(): void
    {
        DBQuery("DELETE FROM uo_comment WHERE type=4 AND id=701");
        $result = ApplyCommentChange(COMMENT_TYPE_GAME, 701, ['action' => 'create', 'comment' => 'Created comment']);
        $this->assertTrue($result);
        $this->assertSame('Created comment', CommentRaw(COMMENT_TYPE_GAME, 701));
    }

    public function testApplyCommentChangeUpdateSetsComment(): void
    {
        SetComment(COMMENT_TYPE_GAME, 700, 'Before update');
        $result = ApplyCommentChange(COMMENT_TYPE_GAME, 700, ['action' => 'update', 'comment' => 'After update']);
        $this->assertTrue($result);
        $this->assertSame('After update', CommentRaw(COMMENT_TYPE_GAME, 700));
    }

    public function testApplyCommentChangeDeleteClearsComment(): void
    {
        SetComment(COMMENT_TYPE_GAME, 700, 'To be deleted');
        $result = ApplyCommentChange(COMMENT_TYPE_GAME, 700, ['action' => 'delete', 'comment' => '']);
        $this->assertTrue($result);
        $this->assertSame('', CommentRaw(COMMENT_TYPE_GAME, 700));
    }

    // --- SetGameComment ---

    public function testSetGameCommentNoopReturnsTrueWithoutPermissionCheck(): void
    {
        // "noop" action bypasses permission checks.
        SetComment(COMMENT_TYPE_GAME, 700, 'Existing same');
        $result = SetGameComment(COMMENT_TYPE_GAME, 700, 'Existing same');
        $this->assertTrue($result);
    }

    public function testSetGameCommentReturnsFalseForCreateWhenNotLoggedIn(): void
    {
        DBQuery("DELETE FROM uo_comment WHERE type=4 AND id=701");
        $_SESSION['uid'] = 'anonymous';
        $result = SetGameComment(COMMENT_TYPE_GAME, 701, 'New comment');
        $this->assertFalse($result);
    }

    public function testSetGameCommentReturnsFalseForUpdateWhenNotLoggedIn(): void
    {
        SetComment(COMMENT_TYPE_GAME, 700, 'Existing comment');
        $_SESSION['uid'] = 'anonymous';
        $result = SetGameComment(COMMENT_TYPE_GAME, 700, 'Updated comment');
        $this->assertFalse($result);
    }

    // --- SetSpiritComment ---

    public function testSetSpiritCommentReturnsFalseForInvalidTeam(): void
    {
        $gameResult = ['hometeam' => 300, 'visitorteam' => 301, 'game_id' => 700];
        // teamId=999 is neither home nor visitor -> SpiritCommentTypeForTeam returns 0.
        $result = SetSpiritComment($gameResult, 999, 'Spirit note');
        $this->assertFalse($result);
    }

    public function testSetSpiritCommentReturnsFalseWhenGameResultMissingFields(): void
    {
        $result = SetSpiritComment([], 300, 'Spirit note');
        $this->assertFalse($result);
    }

    public function testSetSpiritCommentReturnsFalseForCreateWhenNotLoggedIn(): void
    {
        $gameResult = ['hometeam' => 300, 'visitorteam' => 301, 'game_id' => 700];
        DBQuery("DELETE FROM uo_comment WHERE type=5 AND id=700");
        $_SESSION['uid'] = 'anonymous';
        $result = SetSpiritComment($gameResult, 300, 'New spirit note');
        $this->assertFalse($result);
    }

    public function testSetSpiritCommentReturnsFalseForUpdateWhenNotLoggedIn(): void
    {
        $gameResult = ['hometeam' => 300, 'visitorteam' => 301, 'game_id' => 700];
        SetComment(COMMENT_TYPE_SPIRIT_HOME, 700, 'Existing spirit');
        $_SESSION['uid'] = 'anonymous';
        $result = SetSpiritComment($gameResult, 300, 'Updated spirit');
        $this->assertFalse($result);
    }

    public function testSetSpiritCommentNoopReturnsTrueForSuperAdmin(): void
    {
        $gameResult = ['hometeam' => 300, 'visitorteam' => 301, 'game_id' => 700];
        SetComment(COMMENT_TYPE_SPIRIT_HOME, 700, 'Same spirit');
        $result = SetSpiritComment($gameResult, 300, 'Same spirit');
        $this->assertTrue($result);
        $this->assertSame('Same spirit', CommentRaw(COMMENT_TYPE_SPIRIT_HOME, 700));
    }
}
