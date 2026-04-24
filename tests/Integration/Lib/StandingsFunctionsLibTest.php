<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class StandingsFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('standings.functions.php', 'bootstrap_only');
    }

    public function testScoreAndSolveStandingsRespectTieRules(): void
    {
        $points = [
            ['team' => 301, 'wins' => 1, 'games' => 2, 'losses' => 1, 'arank' => 1],
            ['team' => 300, 'wins' => 2, 'games' => 2, 'losses' => 0, 'arank' => 1],
            ['team' => 302, 'wins' => 1, 'games' => 2, 'losses' => 1, 'arank' => 1],
        ];

        $resolved = SolveStandings($points, 'cmp_score');

        $this->assertSame(4, Score($points[1]));
        $this->assertSame(300, $resolved[0]['team']);
        $this->assertSame(1, $resolved[0]['arank']);
        $this->assertSame(2, $resolved[1]['arank']);
        $this->assertSame(2, $resolved[2]['arank']);
    }

    public function testCompareTeamsSwissdrawPrefersVictoryPointsThenMargin(): void
    {
        $better = ['games' => 1, 'vp' => 2, 'margin' => 4, 'score' => 13, 'spirit' => 10];
        $worse = ['games' => 1, 'vp' => 1, 'margin' => 8, 'score' => 15, 'spirit' => 12];

        $this->assertSame(-1, CompareTeamsSwissdraw($better, $worse));
    }
}
