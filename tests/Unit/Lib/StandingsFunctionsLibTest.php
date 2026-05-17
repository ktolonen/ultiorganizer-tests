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

    // --- Score ---

    public function testScoreIsDoubleWinsForAllWins(): void
    {
        $this->assertSame(6, Score(['wins' => 3, 'games' => 3, 'losses' => 0]));
    }

    public function testScoreCountsDrawsAsOnPoint(): void
    {
        // 3 wins (6pts) + 1 draw (1pt) out of 5 games (losses=1)
        $this->assertSame(7, Score(['wins' => 3, 'games' => 5, 'losses' => 1]));
    }

    public function testScoreIsZeroForAllLosses(): void
    {
        $this->assertSame(0, Score(['wins' => 0, 'games' => 3, 'losses' => 3]));
    }

    // --- cmp_score ---

    public function testCmpScoreReturnsNegativeOneWhenFirstHasHigherScore(): void
    {
        $a = ['wins' => 3, 'games' => 3, 'losses' => 0]; // score 6
        $b = ['wins' => 1, 'games' => 3, 'losses' => 2]; // score 2
        $this->assertSame(-1, cmp_score($a, $b));
    }

    public function testCmpScoreReturnsOneWhenSecondHasHigherScore(): void
    {
        $a = ['wins' => 1, 'games' => 3, 'losses' => 2]; // score 2
        $b = ['wins' => 3, 'games' => 3, 'losses' => 0]; // score 6
        $this->assertSame(1, cmp_score($a, $b));
    }

    public function testCmpScoreReturnsZeroForEqualScores(): void
    {
        // score 4 each: wins=2,games=2,losses=0 -> 2*2+0=4
        //               wins=0,games=4,losses=0 -> 0*2+4=4
        $a = ['wins' => 2, 'games' => 2, 'losses' => 0];
        $b = ['wins' => 0, 'games' => 4, 'losses' => 0];
        $this->assertSame(0, cmp_score($a, $b));
    }

    // --- cmp_goalsdiff ---

    public function testCmpGoalsDiffRanksHigherDifferenceFirst(): void
    {
        $a = ['goalsdiff' => 10];
        $b = ['goalsdiff' => 5];
        $this->assertSame(-1, cmp_goalsdiff($a, $b));
        $this->assertSame(1, cmp_goalsdiff($b, $a));
    }

    public function testCmpGoalsDiffReturnsZeroForEqual(): void
    {
        $this->assertSame(0, cmp_goalsdiff(['goalsdiff' => 5], ['goalsdiff' => 5]));
    }

    // --- cmp_goalsmade ---

    public function testCmpGoalsMadeRanksMoreGoalsFirst(): void
    {
        $a = ['goalsmade' => 20];
        $b = ['goalsmade' => 15];
        $this->assertSame(-1, cmp_goalsmade($a, $b));
        $this->assertSame(1, cmp_goalsmade($b, $a));
    }

    // --- IsSameRank ---

    public function testIsSameRankReturnsTrueWhenAllRanksEqual(): void
    {
        $points = [
            ['team' => 1, 'arank' => 1],
            ['team' => 2, 'arank' => 1],
            ['team' => 3, 'arank' => 1],
        ];
        $this->assertTrue(IsSameRank($points));
    }

    public function testIsSameRankReturnsFalseWhenRanksDiffer(): void
    {
        $points = [
            ['team' => 1, 'arank' => 1],
            ['team' => 2, 'arank' => 2],
        ];
        $this->assertFalse(IsSameRank($points));
    }

    public function testIsSameRankReturnsTrueForSingleElement(): void
    {
        $this->assertTrue(IsSameRank([['team' => 1, 'arank' => 1]]));
    }

    // --- SolveStandings ---

    public function testSolveStandingsReturnsEmptyArrayForEmptyInput(): void
    {
        $this->assertSame([], SolveStandings([], 'cmp_score'));
    }

    public function testSolveStandingsRanksTeamsByScore(): void
    {
        $points = [
            ['team' => 1, 'wins' => 3, 'games' => 3, 'losses' => 0, 'arank' => 1], // score 6 -> rank 1
            ['team' => 2, 'wins' => 1, 'games' => 3, 'losses' => 2, 'arank' => 1], // score 2 -> rank 3
            ['team' => 3, 'wins' => 2, 'games' => 3, 'losses' => 1, 'arank' => 1], // score 4 -> rank 2
        ];

        $result = SolveStandings($points, 'cmp_score');

        $byTeam = array_column($result, 'arank', 'team');
        $this->assertSame(1, $byTeam[1]);
        $this->assertSame(2, $byTeam[3]);
        $this->assertSame(3, $byTeam[2]);
    }

    public function testSolveStandingsAssignsSharedRankToTiedTeamsAndSkipsToNext(): void
    {
        // Competition ranking: 1, 1, 3 (not 1, 1, 2)
        $points = [
            ['team' => 1, 'wins' => 2, 'games' => 2, 'losses' => 0, 'arank' => 1], // score 4
            ['team' => 2, 'wins' => 2, 'games' => 2, 'losses' => 0, 'arank' => 1], // score 4 (tied)
            ['team' => 3, 'wins' => 0, 'games' => 2, 'losses' => 2, 'arank' => 1], // score 0 -> rank 3
        ];

        $result = SolveStandings($points, 'cmp_score');

        $byTeam = array_column($result, 'arank', 'team');
        $this->assertSame(1, $byTeam[1]);
        $this->assertSame(1, $byTeam[2]);
        $this->assertSame(3, $byTeam[3]);
    }

    // --- FindSameRank ---

    public function testFindSameRankReturnsTiedTeamsAtGivenOffset(): void
    {
        $points = [
            ['team' => 1, 'arank' => 1],
            ['team' => 2, 'arank' => 2],
            ['team' => 3, 'arank' => 2],
            ['team' => 4, 'arank' => 4],
        ];

        $samerank = FindSameRank($points, 1);

        $this->assertCount(2, $samerank);
        $this->assertSame(2, $samerank[0]['arank']);
        $this->assertSame(2, $samerank[1]['arank']);
    }

    public function testFindSameRankReturnsEmptyWhenNoTies(): void
    {
        $points = [
            ['team' => 1, 'arank' => 1],
            ['team' => 2, 'arank' => 2],
            ['team' => 3, 'arank' => 3],
        ];
        $this->assertSame([], FindSameRank($points, 1));
    }

    // --- UpdateStandings ---

    public function testUpdateStandingsMergesPartialRankUpdatesIntoFullList(): void
    {
        $to = [
            ['team' => 1, 'arank' => 1],
            ['team' => 2, 'arank' => 1],
            ['team' => 3, 'arank' => 3],
        ];
        $from = [
            ['team' => 1, 'arank' => 1],
            ['team' => 2, 'arank' => 2],
        ];

        $result = UpdateStandings($to, $from);

        $byTeam = array_column($result, 'arank', 'team');
        $this->assertSame(1, $byTeam[1]);
        $this->assertSame(2, $byTeam[2]);
        $this->assertSame(3, $byTeam[3]);
    }

    // --- CompareTeamsSwissdraw ---

    public function testCompareTeamsSwissdrawFirstRoundSortsByVictoryPoints(): void
    {
        $a = ['games' => 1, 'vp' => 2, 'margin' => 5, 'score' => 10, 'spirit' => 8];
        $b = ['games' => 1, 'vp' => 1, 'margin' => 3, 'score' => 8,  'spirit' => 7];

        $this->assertSame(-1, CompareTeamsSwissdraw($a, $b));
        $this->assertSame(1,  CompareTeamsSwissdraw($b, $a));
    }

    public function testCompareTeamsSwissdrawFirstRoundTiebreakerByMargin(): void
    {
        $a = ['games' => 1, 'vp' => 2, 'margin' => 8, 'score' => 15, 'spirit' => 9];
        $b = ['games' => 1, 'vp' => 2, 'margin' => 5, 'score' => 10, 'spirit' => 7];

        $this->assertSame(-1, CompareTeamsSwissdraw($a, $b));
    }

    public function testCompareTeamsSwissdrawLaterRoundsSortsByGamesPlayedFirst(): void
    {
        $a = ['games' => 3, 'vp' => 4, 'oppvp' => 6, 'score' => 20, 'spirit' => 8];
        $b = ['games' => 2, 'vp' => 4, 'oppvp' => 6, 'score' => 20, 'spirit' => 8];

        $this->assertSame(-1, CompareTeamsSwissdraw($a, $b));
        $this->assertSame(1,  CompareTeamsSwissdraw($b, $a));
    }

    public function testCompareTeamsSwissdrawReturnsZeroForIdenticalTeams(): void
    {
        $a = ['games' => 1, 'vp' => 2, 'margin' => 5, 'score' => 10, 'spirit' => 8];

        $this->assertSame(0, CompareTeamsSwissdraw($a, $a));
    }

    // --- SolveStandingsAccordingSwissdraw ---

    public function testSolveStandingsAccordingSwissdrawRanksByVictoryPoints(): void
    {
        $points = [
            ['team' => 1, 'games' => 2, 'vp' => 2, 'oppvp' => 3, 'margin' => 10, 'score' => 20, 'spirit' => 8],
            ['team' => 2, 'games' => 2, 'vp' => 4, 'oppvp' => 5, 'margin' => 15, 'score' => 25, 'spirit' => 9],
            ['team' => 3, 'games' => 2, 'vp' => 3, 'oppvp' => 4, 'margin' => 12, 'score' => 22, 'spirit' => 7],
        ];

        $result = SolveStandingsAccordingSwissdraw($points);

        $byTeam = array_column($result, 'arank', 'team');
        $this->assertSame(1, $byTeam[2]); // highest vp
        $this->assertSame(2, $byTeam[3]); // middle vp
        $this->assertSame(3, $byTeam[1]); // lowest vp
    }
}
