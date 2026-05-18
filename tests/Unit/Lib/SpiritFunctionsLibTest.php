<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class SpiritFunctionsLibTest extends TestCase
{
    /** @var array Shared category fixture: two active categories (index 1 & 2) plus one header (index 0) */
    private array $categories;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('spirit.functions.php', 'bootstrap_only');

        $this->categories = [
            ['category_id' => 1, 'index' => 1, 'min' => 0, 'max' => 4, 'factor' => 1],
            ['category_id' => 2, 'index' => 2, 'min' => 0, 'max' => 4, 'factor' => 2],
            ['category_id' => 9, 'index' => 0, 'min' => 0, 'max' => 0, 'factor' => 0], // header row, excluded
        ];
    }

    // --- SpiritOrderedCategories ---

    public function testSpiritOrderedCategoriesExcludesHeaderRows(): void
    {
        $result = SpiritOrderedCategories($this->categories);
        $this->assertCount(2, $result);
        foreach ($result as $cat) {
            $this->assertGreaterThan(0, (int) $cat['index']);
        }
    }

    public function testSpiritOrderedCategoriesSortsByIndex(): void
    {
        $unordered = [
            ['category_id' => 3, 'index' => 2, 'min' => 0, 'max' => 4, 'factor' => 1],
            ['category_id' => 1, 'index' => 1, 'min' => 0, 'max' => 4, 'factor' => 1],
        ];
        $result = SpiritOrderedCategories($unordered);
        $this->assertSame(1, (int) $result[0]['index']);
        $this->assertSame(2, (int) $result[1]['index']);
    }

    public function testSpiritOrderedCategoriesReturnsEmptyForAllHeaderRows(): void
    {
        $this->assertSame([], SpiritOrderedCategories([
            ['category_id' => 9, 'index' => 0, 'min' => 0, 'max' => 0, 'factor' => 0],
        ]));
    }

    // --- SpiritDefaultPoints ---

    public function testSpiritDefaultPointsComputesFloorMidpointPerCategory(): void
    {
        // For each active category: floor((min + max) / 2) = floor((0+4)/2) = 2
        $result = SpiritDefaultPoints($this->categories);
        $this->assertSame([1 => 2, 2 => 2], $result);
    }

    public function testSpiritDefaultPointsExcludesHeaderRows(): void
    {
        $result = SpiritDefaultPoints($this->categories);
        $this->assertArrayNotHasKey(9, $result);
    }

    // --- SpiritTotal ---

    public function testSpiritTotalSumsPointsTimesFactors(): void
    {
        // cat1: 3 * factor(1) = 3; cat2: 2 * factor(2) = 4 → total = 7
        $points = [1 => 3, 2 => 2];
        $this->assertSame(7, SpiritTotal($points, $this->categories));
    }

    public function testSpiritTotalReturnsNullWhenAnyCategoryIsMissing(): void
    {
        // Only category 1 is set; category 2 is missing → null
        $this->assertNull(SpiritTotal([1 => 3], $this->categories));
    }

    public function testSpiritTotalIgnoresHeaderCategories(): void
    {
        // Providing a value for the header category (index=0) must not affect total
        $points = [1 => 2, 2 => 2, 9 => 99];
        $this->assertSame(6, SpiritTotal($points, $this->categories)); // 2*1 + 2*2 = 6
    }

    // --- SpiritPointsSummary ---

    public function testSpiritPointsSummaryFormatsSpaceSeparatedWithTotal(): void
    {
        // cat1=3, cat2=2 → "3 2 (7)"  (total = 3*1 + 2*2 = 7)
        $result = SpiritPointsSummary([1 => 3, 2 => 2], $this->categories);
        $this->assertSame('3 2 (7)', $result);
    }

    public function testSpiritPointsSummaryReturnsEmptyForEmptyPoints(): void
    {
        $this->assertSame('', SpiritPointsSummary([], $this->categories));
    }

    public function testSpiritPointsSummaryReturnsEmptyWhenCategoryMissing(): void
    {
        // Category 2 not present → SpiritTotal returns null but loop exits early with ''
        $result = SpiritPointsSummary([1 => 3], $this->categories);
        $this->assertSame('', $result);
    }

    // --- SpiritTokenRatedTeamId ---

    public function testSpiritTokenRatedTeamIdReturnsVisitorWhenTokenIsHome(): void
    {
        $game = ['hometeam' => 10, 'visitorteam' => 20];
        $this->assertSame(20, SpiritTokenRatedTeamId($game, 10));
    }

    public function testSpiritTokenRatedTeamIdReturnsHomeWhenTokenIsVisitor(): void
    {
        $game = ['hometeam' => 10, 'visitorteam' => 20];
        $this->assertSame(10, SpiritTokenRatedTeamId($game, 20));
    }

    public function testSpiritTokenRatedTeamIdReturnsZeroForUnknownToken(): void
    {
        $game = ['hometeam' => 10, 'visitorteam' => 20];
        $this->assertSame(0, SpiritTokenRatedTeamId($game, 99));
    }

    // --- SpiritkeeperGameScoreLabel ---

    public function testSpiritkeeperGameScoreLabelFormatsHomeAndVisitorScore(): void
    {
        $game = ['homescore' => 13, 'visitorscore' => 7];
        $this->assertSame('13 - 7', SpiritkeeperGameScoreLabel($game));
    }

    public function testSpiritkeeperGameScoreLabelReturnsQuestionMarksWhenScoresNull(): void
    {
        $game = ['homescore' => null, 'visitorscore' => null];
        $this->assertSame('? - ?', SpiritkeeperGameScoreLabel($game));
    }

    public function testSpiritkeeperGameScoreLabelReturnsQuestionMarksWhenScoresEmpty(): void
    {
        $game = ['homescore' => '', 'visitorscore' => ''];
        $this->assertSame('? - ?', SpiritkeeperGameScoreLabel($game));
    }

    // --- SpiritkeeperTeamGamesUrl ---

    public function testSpiritkeeperTeamGamesUrlBuildsDefaultUrl(): void
    {
        $this->assertSame('?view=teamgames&team=5', SpiritkeeperTeamGamesUrl(5));
    }

    public function testSpiritkeeperTeamGamesUrlIncludesSeasonWhenProvided(): void
    {
        $url = SpiritkeeperTeamGamesUrl(5, 'summer2024');
        $this->assertStringContainsString('season=summer2024', $url);
        $this->assertStringContainsString('team=5', $url);
    }

    public function testSpiritkeeperTeamGamesUrlPrependsBasePathWhenProvided(): void
    {
        $url = SpiritkeeperTeamGamesUrl(5, '', './spiritkeeper/');
        $this->assertStringStartsWith('./spiritkeeper/', $url);
    }

    // --- SpiritValidateSubmittedPoints ---

    public function testSpiritValidateSubmittedPointsReturnsIntArrayForValidInput(): void
    {
        $result = SpiritValidateSubmittedPoints(['1' => '3', '2' => '2'], $this->categories);
        $this->assertSame([1 => 3, 2 => 2], $result);
    }

    public function testSpiritValidateSubmittedPointsReturnsFalseWhenValueExceedsMax(): void
    {
        $this->assertFalse(SpiritValidateSubmittedPoints(['1' => '5', '2' => '2'], $this->categories));
    }

    public function testSpiritValidateSubmittedPointsReturnsFalseWhenCategoryMissing(): void
    {
        $this->assertFalse(SpiritValidateSubmittedPoints(['1' => '3'], $this->categories));
    }

    public function testSpiritValidateSubmittedPointsReturnsFalseForNonIntegerValue(): void
    {
        $this->assertFalse(SpiritValidateSubmittedPoints(['1' => 'abc', '2' => '2'], $this->categories));
    }

    public function testSpiritValidateSubmittedPointsReturnsFalseWhenNoRequiredCategories(): void
    {
        // All header rows → required = 0 → false
        $headerOnly = [['category_id' => 9, 'index' => 0, 'min' => 0, 'max' => 0, 'factor' => 0]];
        $this->assertFalse(SpiritValidateSubmittedPoints(['9' => '0'], $headerOnly));
    }
}
