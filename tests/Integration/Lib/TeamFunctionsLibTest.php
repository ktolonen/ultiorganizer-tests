<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class TeamFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('team.functions.php', 'team_stack');
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    public function testTeamNameAndSeasonReadBaselineFixture(): void
    {
        $this->assertSame('Helsinki Heat', TeamName(300));
        $this->assertSame('HRN2026', TeamSeason(300));
    }

    public function testTeamPlayerArrayReturnsOrderedFixturePlayers(): void
    {
        $this->assertSame(
            [
                '800' => 'Ari Ace',
                '801' => 'Bea Blade',
            ],
            TeamPlayerArray(300)
        );
    }
}
