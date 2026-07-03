<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class CommentFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('comment.functions.php', 'bootstrap_only');
    }

    // --- CommentNormalize ---

    public function testCommentNormalizeTrimsWhitespace(): void
    {
        $this->assertSame('hello', CommentNormalize('  hello  '));
    }

    public function testCommentNormalizeTruncatesToMaxLength(): void
    {
        $long = str_repeat('x', COMMENT_MAX_LENGTH + 100);
        $result = CommentNormalize($long);
        $this->assertSame(COMMENT_MAX_LENGTH, strlen($result));
    }

    public function testCommentNormalizeReturnsEmptyStringForBlankInput(): void
    {
        $this->assertSame('', CommentNormalize(''));
        $this->assertSame('', CommentNormalize('   '));
    }

    // --- CommentKeyForType ---

    public function testCommentKeyForTypeReturnsGameForGameType(): void
    {
        $this->assertSame('game', CommentKeyForType(COMMENT_TYPE_GAME));
    }

    public function testCommentKeyForTypeReturnsSpiritHomeForSpiritHome(): void
    {
        $this->assertSame('spirit_home', CommentKeyForType(COMMENT_TYPE_SPIRIT_HOME));
    }

    public function testCommentKeyForTypeReturnsSpiritVisitorForSpiritVisitor(): void
    {
        $this->assertSame('spirit_visitor', CommentKeyForType(COMMENT_TYPE_SPIRIT_VISITOR));
    }

    public function testCommentKeyForTypeReturnsCommentForOtherTypes(): void
    {
        $this->assertSame('comment', CommentKeyForType(COMMENT_TYPE_SEASON));
        $this->assertSame('comment', CommentKeyForType(COMMENT_TYPE_SERIES));
        $this->assertSame('comment', CommentKeyForType(COMMENT_TYPE_POOL));
    }

    // --- CommentLabelForType ---

    public function testCommentLabelForTypeReturnsLabelForGameType(): void
    {
        $this->assertSame('Game note', CommentLabelForType(COMMENT_TYPE_GAME));
    }

    public function testCommentLabelForTypeReturnsSpiritNoteForSpiritHome(): void
    {
        $this->assertSame('Spirit note (home)', CommentLabelForType(COMMENT_TYPE_SPIRIT_HOME));
    }

    public function testCommentLabelForTypeReturnsSpiritNoteForSpiritVisitor(): void
    {
        $this->assertSame('Spirit note (visitor)', CommentLabelForType(COMMENT_TYPE_SPIRIT_VISITOR));
    }

    public function testCommentLabelForTypeReturnsCommentForOtherTypes(): void
    {
        $this->assertSame('Comment', CommentLabelForType(COMMENT_TYPE_SEASON));
    }

    // --- Constants ---

    public function testCommentTypeConstantsHaveExpectedValues(): void
    {
        $this->assertSame(1, COMMENT_TYPE_SEASON);
        $this->assertSame(2, COMMENT_TYPE_SERIES);
        $this->assertSame(3, COMMENT_TYPE_POOL);
        $this->assertSame(4, COMMENT_TYPE_GAME);
        $this->assertSame(5, COMMENT_TYPE_SPIRIT_HOME);
        $this->assertSame(6, COMMENT_TYPE_SPIRIT_VISITOR);
        $this->assertSame(4000, COMMENT_MAX_LENGTH);
    }

    // --- SpiritCommentTypeForTeam ---

    public function testSpiritCommentTypeForTeamReturnsHomeTypeForHomeTeam(): void
    {
        $game = ['hometeam' => 10, 'visitorteam' => 20];
        $this->assertSame(COMMENT_TYPE_SPIRIT_HOME, SpiritCommentTypeForTeam($game, 10));
    }

    public function testSpiritCommentTypeForTeamReturnsVisitorTypeForVisitorTeam(): void
    {
        $game = ['hometeam' => 10, 'visitorteam' => 20];
        $this->assertSame(COMMENT_TYPE_SPIRIT_VISITOR, SpiritCommentTypeForTeam($game, 20));
    }

    public function testSpiritCommentTypeForTeamReturnsZeroForUnknownTeam(): void
    {
        $game = ['hometeam' => 10, 'visitorteam' => 20];
        $this->assertSame(0, SpiritCommentTypeForTeam($game, 99));
    }

    public function testSpiritCommentTypeForTeamReturnsZeroForMissingKeys(): void
    {
        $this->assertSame(0, SpiritCommentTypeForTeam([], 10));
    }

    // bootstrap_only profile: hasEditGameEventsRight, isLoggedIn, hasEditPlayersRight,
    // HasFullGameSpiritEditRight, Log1 are all undefined → function_exists guards fire.

    public function testCanCreateGameCommentReturnsFalseWhenRequiredFunctionsUndefined(): void
    {
        $this->assertFalse(CanCreateGameComment(700));
    }

    public function testCanCreateSpiritCommentReturnsFalseWhenRequiredFunctionsUndefined(): void
    {
        $gameResult = ['hometeam' => 300, 'visitorteam' => 301, 'game_id' => 700];
        $this->assertFalse(CanCreateSpiritComment($gameResult, 300));
    }

    public function testCanManageGameCommentReturnsFalseWhenRequiredFunctionsUndefined(): void
    {
        $this->assertFalse(CanManageGameComment(700, COMMENT_TYPE_GAME));
    }

    public function testCanManageSpiritCommentReturnsFalseWhenRequiredFunctionsUndefined(): void
    {
        $this->assertFalse(CanManageSpiritComment(700, COMMENT_TYPE_SPIRIT_HOME));
    }

    public function testLogGameCommentEventDoesNotCrashWhenLog1Undefined(): void
    {
        if (function_exists('Log1')) {
            // Log1 was defined by a prior test (auth.guard loads user.functions.php
            // transitively). The unit-bootstrap path is already covered in the
            // integration suite; skip rather than crash on DBEscapeString.
            $this->markTestSkipped('Log1 already defined by prior test in this process');
        }
        LogGameCommentEvent(700, COMMENT_TYPE_GAME, 'comment_create');
        $this->assertTrue(true);
    }
}
