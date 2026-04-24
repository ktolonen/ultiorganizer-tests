<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class LoggingFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('logging.functions.php', 'database_only');
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    public function testEventCategoriesReturnsStableCategoryList(): void
    {
        $this->assertSame(
            ['security', 'user', 'enrolment', 'club', 'team', 'player', 'season', 'series', 'pool', 'game', 'media'],
            EventCategories()
        );
    }

    public function testLogEventStoresTrimmedDefaultsInEventLog(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $eventId = LogEvent([
            'category' => 'media',
            'type' => 'change',
            'description' => str_repeat('x', 60),
            'id1' => str_repeat('A', 25),
        ]);

        $row = DBQueryToRow(sprintf(
            "SELECT user_id, ip, category, type, id1, id2, source, description FROM uo_event_log WHERE event_id=%d",
            (int) $eventId
        ));

        $this->assertSame('unknown', $row['user_id']);
        $this->assertSame('203.0.113.7', $row['ip']);
        $this->assertSame('media', $row['category']);
        $this->assertSame('change', $row['type']);
        $this->assertSame(str_repeat('A', 20), $row['id1']);
        $this->assertSame('', $row['id2']);
        $this->assertSame('', $row['source']);
        $this->assertSame(str_repeat('x', 50), $row['description']);
    }
}
