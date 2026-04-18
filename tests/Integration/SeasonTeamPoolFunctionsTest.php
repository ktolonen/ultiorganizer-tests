<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class SeasonTeamPoolFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadTeamFunctions();
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    public function testCurrentSeasonAndSeasonPoolsReadBaselineFixture(): void
    {
        $this->assertSame('HRN2026', CurrentSeason());

        $season = SeasonInfo('HRN2026');
        $pools = SeasonPools('HRN2026', true, true);

        $this->assertSame('Harness Invitational 2026', $season['name']);
        $this->assertCount(1, $pools);
        $this->assertSame(200, (int) $pools[0]['pool_id']);
        $this->assertSame('Pool A', $pools[0]['poolname']);
    }

    public function testTeamAndPoolInfoExposeFixtureData(): void
    {
        $team = TeamInfo(300);
        $pool = PoolInfo(200);

        $this->assertSame('Helsinki Heat', $team['name']);
        $this->assertSame('Open', $team['seriesname']);
        $this->assertSame('HRN2026', $team['season']);
        $this->assertSame('Pool A', $pool['name']);
        $this->assertSame('Open', $pool['seriesname']);
    }

    public function testPoolGamesIncludePlayedAndScheduledFixtureGames(): void
    {
        $games = PoolGames(200);

        $this->assertCount(2, $games);
        $this->assertSame([700, 701], array_map(static fn (array $game): int => (int) $game['game_id'], $games));
    }

    public function testSeriesAggregatesReflectPlayedFixtureGame(): void
    {
        $teams = SeriesTeams(100, true);
        $stats = SeriesTeamStatsPoints(100);

        $this->assertCount(2, $teams);
        $this->assertSame(300, (int) $teams[0]['team_id']);
        $this->assertSame(301, (int) $teams[1]['team_id']);
        $this->assertSame('1', (string) $stats[300]['wins']);
        $this->assertSame('1', (string) $stats[300]['games']);
        $this->assertSame('15', (string) $stats[300]['scores']);
        $this->assertSame('0', (string) $stats[301]['wins']);
        $this->assertSame('11', (string) $stats[301]['scores']);
    }

    public function testCreateOrderingAndFilterAcceptKnownTableAliases(): void
    {
        $ordering = CreateOrdering(['uo_season' => 'season'], ['season.starttime' => 'DESC']);
        $filter = CreateFilter(
            ['uo_season' => 'season'],
            ['field' => 'season.season_id', 'operator' => '=', 'value' => 'HRN2026']
        );

        $this->assertSame('ORDER BY season.starttime DESC', $ordering);
        $this->assertSame("WHERE season.season_id = 'HRN2026'", $filter);
    }
}
