<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class SeasonpointsFunctionsLibTest extends TestCase
{
    // Fixture constants: series_id=100 ('Open'), season='HRN2026',
    // teams 300 ('Helsinki Heat') + 301 ('Tampere Tempest').
    // No uo_season_round rows exist in baseline, so each test creates and
    // cleans up its own rounds.

    /** @var int[] round IDs created during a test, deleted in tearDown */
    private array $createdRoundIds = [];

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        // Load user.functions.php first to get isSeasonAdmin, then the target.
        LegacyApp::loadLibFilesUsingProfile(
            ['user.functions.php', 'seasonpoints.functions.php'],
            'database_only'
        );
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
        // Simulate super-admin so write functions proceed without die().
        $_SESSION['userproperties']['userrole']['superadmin'] = true;

        // Disable persistent cache: ConfigurationFunctionsTest (running earlier
        // alphabetically) loads configuration.functions.php and enables the
        // 5-second file cache. Without this, SELECT results from SeasonPointsRounds
        // would be stale after INSERT/DELETE within the same test run.
        global $serverConf;
        if (!isset($serverConf)) {
            $serverConf = [];
        }
        $serverConf['PersistentCacheEnabled'] = 'false';
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRoundIds as $roundId) {
            DeleteSeasonPointsRound($roundId);
        }
        $this->createdRoundIds = [];
        unset($_SESSION['userproperties']);
        LegacyApp::closeDatabaseConnection();
    }

    /**
     * Add a round and register it for cleanup. Returns the new round_id.
     */
    private function addRound(string $season, int $series, int $no, string $name): int
    {
        AddSeasonPointsRound($season, $series, $no, $name);
        // Find it by scanning current rounds for matching name+no.
        $rounds = SeasonPointsRounds($season, $series);
        foreach ($rounds as $r) {
            if ($r['name'] === $name && (int) $r['round_no'] === $no) {
                $id = (int) $r['round_id'];
                $this->createdRoundIds[] = $id;
                return $id;
            }
        }
        throw new \RuntimeException("Could not find round after AddSeasonPointsRound: $name");
    }

    // --- SeasonPointsRounds ---

    public function testSeasonPointsRoundsReturnsEmptyArrayForUnknownSeries(): void
    {
        $rounds = SeasonPointsRounds('HRN2026', 99999);
        $this->assertIsArray($rounds);
        $this->assertCount(0, $rounds);
    }

    public function testSeasonPointsRoundsReturnsAddedRound(): void
    {
        $roundId = $this->addRound('HRN2026', 100, 1, 'Test Round A');

        $rounds = SeasonPointsRounds('HRN2026', 100);

        $names = array_column($rounds, 'name');
        $this->assertContains('Test Round A', $names);
        $this->assertGreaterThan(0, $roundId);
    }

    public function testSeasonPointsRoundsShapeHasExpectedKeys(): void
    {
        $this->addRound('HRN2026', 100, 11, 'Test Round Shape');

        $rounds = SeasonPointsRounds('HRN2026', 100);

        $match = null;
        foreach ($rounds as $r) {
            if ($r['name'] === 'Test Round Shape') {
                $match = $r;
                break;
            }
        }

        $this->assertNotNull($match);
        $this->assertArrayHasKey('round_id', $match);
        $this->assertArrayHasKey('round_no', $match);
        $this->assertArrayHasKey('name', $match);
    }

    // --- SeasonPointsRoundInfo ---

    public function testSeasonPointsRoundInfoReturnsNullForUnknownId(): void
    {
        $this->assertNull(SeasonPointsRoundInfo(99999));
    }

    public function testSeasonPointsRoundInfoReturnsCorrectRow(): void
    {
        $roundId = $this->addRound('HRN2026', 100, 2, 'Test Round B');

        $info = SeasonPointsRoundInfo($roundId);

        $this->assertIsArray($info);
        $this->assertSame('HRN2026', $info['season']);
        $this->assertSame('100', (string) $info['series']);
        $this->assertSame('Test Round B', $info['name']);
        $this->assertSame((string) $roundId, (string) $info['round_id']);
    }

    // --- AddSeasonPointsRound and DeleteSeasonPointsRound ---

    public function testAddSeasonPointsRoundReturnsTrueOnSuccess(): void
    {
        $ok = AddSeasonPointsRound('HRN2026', 100, 3, 'Test Round Add');
        $this->assertTrue($ok);

        // Find and register for cleanup.
        $rounds = SeasonPointsRounds('HRN2026', 100);
        foreach ($rounds as $r) {
            if ($r['name'] === 'Test Round Add') {
                $this->createdRoundIds[] = (int) $r['round_id'];
                break;
            }
        }
    }

    public function testDeleteSeasonPointsRoundReturnsTrueAndRoundIsGone(): void
    {
        $roundId = $this->addRound('HRN2026', 100, 4, 'Test Round Delete');

        $ok = DeleteSeasonPointsRound($roundId);
        $this->assertTrue($ok);

        $this->assertNull(SeasonPointsRoundInfo($roundId));
        // Already deleted; remove from cleanup list.
        $this->createdRoundIds = array_diff($this->createdRoundIds, [$roundId]);
    }

    public function testDeleteSeasonPointsRoundReturnsFalseForUnknownId(): void
    {
        $this->assertFalse(DeleteSeasonPointsRound(99999));
    }

    // --- SeasonPointsRoundPoints ---

    public function testSeasonPointsRoundPointsReturnsEmptyArrayForUnknownRound(): void
    {
        $points = SeasonPointsRoundPoints(99999);
        $this->assertIsArray($points);
        $this->assertCount(0, $points);
    }

    public function testSeasonPointsRoundPointsIndexedByTeamId(): void
    {
        $roundId = $this->addRound('HRN2026', 100, 5, 'Test Round Points');

        $ok = SaveSeasonPointsRoundPoints($roundId, [300 => 5, 301 => 3]);
        $this->assertTrue($ok);

        $points = SeasonPointsRoundPoints($roundId);
        $this->assertSame(5, $points[300]);
        $this->assertSame(3, $points[301]);
    }

    // --- SaveSeasonPointsRoundPoints ---

    public function testSaveSeasonPointsRoundPointsReturnsFalseForUnknownRound(): void
    {
        $this->assertFalse(SaveSeasonPointsRoundPoints(99999, [300 => 10]));
    }

    public function testSaveSeasonPointsRoundPointsUpsertsOnSecondCall(): void
    {
        $roundId = $this->addRound('HRN2026', 100, 6, 'Test Round Upsert');

        SaveSeasonPointsRoundPoints($roundId, [300 => 10]);
        $ok = SaveSeasonPointsRoundPoints($roundId, [300 => 20]);
        $this->assertTrue($ok);

        $points = SeasonPointsRoundPoints($roundId);
        $this->assertSame(20, $points[300]);
    }

    // --- SeasonPointsSeriesTotals ---

    public function testSeasonPointsSeriesTotalsReturnsArrayForSeriesWithNoRounds(): void
    {
        $totals = SeasonPointsSeriesTotals('HRN2026', 99999);
        $this->assertIsArray($totals);
        $this->assertCount(0, $totals);
    }

    public function testSeasonPointsSeriesTotalsSumsAcrossRounds(): void
    {
        $r1 = $this->addRound('HRN2026', 100, 7, 'Test Round Total 1');
        $r2 = $this->addRound('HRN2026', 100, 8, 'Test Round Total 2');

        SaveSeasonPointsRoundPoints($r1, [300 => 6, 301 => 4]);
        SaveSeasonPointsRoundPoints($r2, [300 => 9, 301 => 7]);

        $totals = SeasonPointsSeriesTotals('HRN2026', 100);

        $this->assertSame(15, $totals[300]);
        $this->assertSame(11, $totals[301]);
    }
}
