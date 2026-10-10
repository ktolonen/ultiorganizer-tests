<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * The public Score display (scoredisplay/index.php): the page shell and the
 * JSON feeds script/scoredisplay.js polls. The feeds are anonymous.
 *
 * Fixture game 701 is moved to today in the event's timezone and restored
 * afterwards.
 */
final class ScoreDisplayPageTest extends TestCase
{
    private const GAME = 701;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(['user.functions.php', 'game.functions.php'], 'pool_stack');
        self::restore();
        DBQuery("UPDATE uo_game SET time='" . self::today() . " 14:00:00' WHERE game_id=" . self::GAME);
    }

    protected function tearDown(): void
    {
        self::restore();
        LegacyApp::closeDatabaseConnection();
    }

    public function testPageRendersThePickerAndTheBoard(): void
    {
        [$status, $body] = self::get('/scoredisplay/index.php');

        $this->assertSame(200, $status);
        $this->assertStringContainsString("id='sd-picker'", $body);
        $this->assertStringContainsString("id='sd-board'", $body);
        $this->assertStringContainsString('script/scoredisplay.js', $body);
        $this->assertStringContainsString('SCOREDISPLAY_I18N', $body);
    }

    public function testListFeedHasTheUpcomingGameAndNotThePlayedOne(): void
    {
        [$status, $body, $headers] = self::get('/scoredisplay/index.php?json=list');

        $this->assertSame(200, $status);
        $this->assertMatchesRegularExpression('/^Content-Type: application\/json/mi', implode("\n", $headers));
        $this->assertMatchesRegularExpression('/^Cache-Control: no-store/mi', implode("\n", $headers));
        $games = json_decode($body, true)['games'];
        $byId = array_column($games, null, 'id');
        $this->assertArrayHasKey(self::GAME, $byId);
        $this->assertArrayNotHasKey(700, $byId);
        $this->assertSame('Tampere Tempest', $byId[self::GAME]['home']);
        $this->assertSame('Helsinki Heat', $byId[self::GAME]['visitor']);
        $this->assertFalse($byId[self::GAME]['ongoing']);
        $this->assertStringContainsString('Harness Field Complex', $byId[self::GAME]['place']);
    }

    public function testOngoingGameIsFlaggedInTheListFeed(): void
    {
        DBQuery("UPDATE uo_game SET homescore=3, visitorscore=2, isongoing=1, hasstarted=1 WHERE game_id=" . self::GAME);

        [, $body] = self::get('/scoredisplay/index.php?json=list');

        $row = array_column(json_decode($body, true)['games'], null, 'id')[self::GAME];
        $this->assertTrue($row['ongoing']);
        $this->assertSame([3, 2], [$row['homescore'], $row['visitorscore']]);
    }

    public function testGameFeedReturnsTheScoreAndNoClockWithoutALiveClock(): void
    {
        DBQuery("UPDATE uo_game SET homescore=4, visitorscore=1, isongoing=1, hasstarted=1 WHERE game_id=" . self::GAME);

        [$status, $body] = self::get('/scoredisplay/index.php?json=game&game=' . self::GAME);

        $this->assertSame(200, $status);
        $data = json_decode($body, true);
        $this->assertSame(self::GAME, $data['id']);
        $this->assertSame([4, 1], [$data['homescore'], $data['visitorscore']]);
        $this->assertNull($data['clock']);
    }

    public function testGameFeedReportsARunningClock(): void
    {
        DBQuery("UPDATE uo_game SET homescore=0, visitorscore=0, isongoing=1, hasstarted=1, timer_start=" . (time() - 90) . " WHERE game_id=" . self::GAME);

        [, $body] = self::get('/scoredisplay/index.php?json=game&game=' . self::GAME);

        $clock = json_decode($body, true)['clock'];
        $this->assertIsArray($clock);
        $this->assertFalse($clock['paused']);
        $this->assertGreaterThanOrEqual(85, $clock['elapsed']);
    }

    public function testGameFeedHidesTheClockWhenTheEventHidesTime(): void
    {
        DBQuery("UPDATE uo_season SET hide_time_on_scoresheet=1 WHERE season_id='HRN2026'");
        DBQuery("UPDATE uo_game SET isongoing=1, hasstarted=1, timer_start=" . (time() - 90) . " WHERE game_id=" . self::GAME);

        [, $body] = self::get('/scoredisplay/index.php?json=game&game=' . self::GAME);

        $this->assertNull(json_decode($body, true)['clock']);
    }

    public function testUnknownGameIs404(): void
    {
        [$status, $body] = self::get('/scoredisplay/index.php?json=game&game=999999');

        $this->assertSame(404, $status);
        $this->assertSame('not found', json_decode($body, true)['error']);
    }

    public function testHiddenGameIsGoneFromBothFeeds(): void
    {
        DBQuery("UPDATE uo_pool SET visible=0 WHERE pool_id=200");

        [, $list] = self::get('/scoredisplay/index.php?json=list');
        [$status] = self::get('/scoredisplay/index.php?json=game&game=' . self::GAME);

        $this->assertNotContains(self::GAME, array_column(json_decode($list, true)['games'], 'id'));
        $this->assertSame(404, $status);
    }

    public function testEventInMaintenanceIsGoneFromBothFeeds(): void
    {
        DBQuery("UPDATE uo_season SET maintenance_mode=1 WHERE season_id='HRN2026'");

        [, $list] = self::get('/scoredisplay/index.php?json=list');
        [$status] = self::get('/scoredisplay/index.php?json=game&game=' . self::GAME);

        $this->assertNotContains(self::GAME, array_column(json_decode($list, true)['games'], 'id'));
        $this->assertSame(404, $status);
    }

    private static function restore(): void
    {
        DBQuery("UPDATE uo_pool SET visible=1 WHERE pool_id=200");
        DBQuery("UPDATE uo_season SET public_event=1, maintenance_mode=0, hide_time_on_scoresheet=0 WHERE season_id='HRN2026'");
        DBQuery("UPDATE uo_game SET time='2026-06-01 14:00:00', homescore=NULL, visitorscore=NULL, isongoing=0, hasstarted=0, timer_start=NULL, timer_pause_start=NULL, timer_paused_duration=0 WHERE game_id=" . self::GAME);
    }

    private static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Helsinki')))->format('Y-m-d');
    }

    /** @return array{0: int, 1: string, 2: array<int, string>} */
    private static function get(string $path): array
    {
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20]]);
        $body = file_get_contents($baseUrl . $path, false, $context);
        self::assertIsString($body, 'request failed: ' . $path);
        self::assertDoesNotMatchRegularExpression('/(Fatal error|Warning|Notice|Deprecated|Parse error)<\/b>:/', $body);
        $headers = $http_response_header ?? [];
        preg_match('/\s(\d{3})\b/', $headers[0] ?? '', $m);
        return [(int) ($m[1] ?? 0), $body, $headers];
    }
}
