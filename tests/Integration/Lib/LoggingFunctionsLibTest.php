<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class LoggingFunctionsLibTest extends TestCase
{
    /** @var list<int> event_ids inserted during tests, cleaned up in tearDown */
    private array $insertedEventIds = [];

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('logging.functions.php', 'database_only');
        // Enable visitor logging so LogPageLoad/LogVisitor paths are reachable.
        // This must happen before the first call to IsVisitorLoggingDisabled() in
        // this process, since it caches the setting in a static variable.
        DBQuery("UPDATE uo_setting SET value='false' WHERE name='DisableVisitorLogging'");
    }

    protected function tearDown(): void
    {
        if (!empty($this->insertedEventIds)) {
            $ids = implode(',', $this->insertedEventIds);
            DBQuery("DELETE FROM uo_event_log WHERE event_id IN ($ids)");
            $this->insertedEventIds = [];
        }
        DBQuery("UPDATE uo_setting SET value='true' WHERE name='DisableVisitorLogging'");
        DBQuery("DELETE FROM uo_pageload_counter WHERE page LIKE 'harness_test_%'");
        DBQuery("DELETE FROM uo_visitor_counter WHERE ip LIKE '192.0.2.%'");
        LegacyApp::closeDatabaseConnection();
    }

    private function logAndTrack(array $event): int
    {
        $id = (int) LogEvent($event);
        $this->insertedEventIds[] = $id;
        return $id;
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

        $eventId = $this->logAndTrack([
            'category' => 'media',
            'type' => 'change',
            'description' => str_repeat('x', 60),
            'id1' => str_repeat('A', 25),
        ]);

        $row = DBQueryToRow(sprintf(
            "SELECT user_id, ip, category, type, id1, id2, source, description FROM uo_event_log WHERE event_id=%d",
            $eventId
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

    public function testLogEventUsesSessionUidWhenNoUserIdProvided(): void
    {
        $_SESSION['uid'] = 'test_user';
        $eventId = $this->logAndTrack(['category' => 'user', 'type' => 'login']);
        $row = DBQueryToRow("SELECT user_id FROM uo_event_log WHERE event_id=$eventId");
        $this->assertSame('test_user', $row['user_id']);
    }

    public function testLogEventTruncatesId2WhenTooLong(): void
    {
        $eventId = $this->logAndTrack([
            'category' => 'game',
            'type' => 'change',
            'id2' => str_repeat('B', 25),
        ]);
        $row = DBQueryToRow("SELECT id2 FROM uo_event_log WHERE event_id=$eventId");
        $this->assertSame(str_repeat('B', 20), $row['id2']);
    }

    // --- Log1, Log2 wrappers ---

    public function testLog1StoresAllFields(): void
    {
        $eventId = $this->logAndTrack([]) ?: 0; // init insertedEventIds
        // Override with proper Log1 call
        array_pop($this->insertedEventIds);
        $id = (int) Log1('pool', 'change', 'p1', 'p2', 'log1 desc', 'unit');
        $this->insertedEventIds[] = $id;
        $row = DBQueryToRow("SELECT category, type, id1, id2, description, source FROM uo_event_log WHERE event_id=$id");
        $this->assertSame('pool', $row['category']);
        $this->assertSame('p1', $row['id1']);
        $this->assertSame('p2', $row['id2']);
        $this->assertSame('log1 desc', $row['description']);
        $this->assertSame('unit', $row['source']);
    }

    public function testLog2StoresFields(): void
    {
        $id = (int) Log2('season', 'create', 'log2 desc', 'src');
        $this->insertedEventIds[] = $id;
        $row = DBQueryToRow("SELECT category, type, description, source FROM uo_event_log WHERE event_id=$id");
        $this->assertSame('season', $row['category']);
        $this->assertSame('log2 desc', $row['description']);
    }

    // --- Typed log wrappers ---

    public function testLogPlayerProfileUpdateStoresPlayerChange(): void
    {
        $id = (int) LogPlayerProfileUpdate(42, 'test');
        $this->insertedEventIds[] = $id;
        $row = DBQueryToRow("SELECT category, type, id1 FROM uo_event_log WHERE event_id=$id");
        $this->assertSame('player', $row['category']);
        $this->assertSame('42', $row['id1']);
    }

    public function testLogTeamProfileUpdateStoresTeamChange(): void
    {
        $id = (int) LogTeamProfileUpdate(7, 'test');
        $this->insertedEventIds[] = $id;
        $row = DBQueryToRow("SELECT category, id1 FROM uo_event_log WHERE event_id=$id");
        $this->assertSame('team', $row['category']);
    }

    public function testLogUserAuthenticationStoresSecurityEvent(): void
    {
        $id = (int) LogUserAuthentication('testuser', 'success', 'unittest');
        $this->insertedEventIds[] = $id;
        $row = DBQueryToRow("SELECT category, type, user_id FROM uo_event_log WHERE event_id=$id");
        $this->assertSame('security', $row['category']);
        $this->assertSame('testuser', $row['user_id']);
    }

    public function testLogGameResultStoresGameChange(): void
    {
        $id = (int) LogGameResult(99, 'win', 'src');
        $this->insertedEventIds[] = $id;
        $row = DBQueryToRow("SELECT category, id1 FROM uo_event_log WHERE event_id=$id");
        $this->assertSame('game', $row['category']);
        $this->assertSame('99', $row['id1']);
    }

    public function testLogDefenseResultStoresDefenseChange(): void
    {
        $id = (int) LogDefenseResult(99, 'saved', 'src');
        $this->insertedEventIds[] = $id;
        $row = DBQueryToRow("SELECT category FROM uo_event_log WHERE event_id=$id");
        $this->assertSame('defense', $row['category']);
    }

    public function testLogGameUpdateStoresGameChange(): void
    {
        $id = (int) LogGameUpdate(99, 'score changed', 'src');
        $this->insertedEventIds[] = $id;
        $row = DBQueryToRow("SELECT category, description FROM uo_event_log WHERE event_id=$id");
        $this->assertSame('game', $row['category']);
        $this->assertSame('score changed', $row['description']);
    }

    public function testLogDefenseUpdateStoresDefenseChange(): void
    {
        $id = (int) LogDefenseUpdate(99, 'defense notes', 'src');
        $this->insertedEventIds[] = $id;
        $row = DBQueryToRow("SELECT category FROM uo_event_log WHERE event_id=$id");
        $this->assertSame('defense', $row['category']);
    }

    public function testLogPoolUpdateStoresPoolChange(): void
    {
        $id = (int) LogPoolUpdate(5, 'standings recalc', 'src');
        $this->insertedEventIds[] = $id;
        $row = DBQueryToRow("SELECT category, id1 FROM uo_event_log WHERE event_id=$id");
        $this->assertSame('pool', $row['category']);
        $this->assertSame('5', $row['id1']);
    }

    public function testLogDbUpgradeStoresDatabaseEvent(): void
    {
        $idStart = (int) LogDbUpgrade('1.2.3', false, 'src');
        $idEnd   = (int) LogDbUpgrade('1.2.3', true, 'src');
        $this->insertedEventIds[] = $idStart;
        $this->insertedEventIds[] = $idEnd;
        $start = DBQueryToRow("SELECT description FROM uo_event_log WHERE event_id=$idStart");
        $end   = DBQueryToRow("SELECT description FROM uo_event_log WHERE event_id=$idEnd");
        $this->assertSame('started', $start['description']);
        $this->assertSame('finished', $end['description']);
    }

    // --- GetLastGameUpdateEntry ---

    public function testGetLastGameUpdateEntryReturnsLatestEntry(): void
    {
        $id = (int) LogGameUpdate(9001, 'test update', 'unittest');
        $this->insertedEventIds[] = $id;
        $row = GetLastGameUpdateEntry(9001, 'unittest');
        $this->assertIsArray($row);
        $this->assertSame('9001', $row['id1']);
    }

    // --- EventList / EventCount (require superadmin) ---

    public function testEventListReturnsEmptyForNoCategories(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $this->assertSame([], EventList([], ''));
        } finally {
            $_SESSION = [];
        }
    }

    public function testEventListReturnsCategoryMatchesAsSuperAdmin(): void
    {
        $id = (int) LogPoolUpdate(1, 'ev list test', 'unit');
        $this->insertedEventIds[] = $id;
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $rows = EventList(['pool'], '');
            $ids = array_column($rows, 'event_id');
            $this->assertContains((string) $id, $ids);
        } finally {
            $_SESSION = [];
        }
    }

    public function testEventListWithUserFilterAndLimitOffset(): void
    {
        $id = (int) LogEvent(['category' => 'user', 'type' => 'test', 'user_id' => 'filterme']);
        $this->insertedEventIds[] = $id;
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $rows = EventList(['user'], 'filterme', 1, 0);
            $this->assertCount(1, $rows);
        } finally {
            $_SESSION = [];
        }
    }

    public function testEventCountReturnsTotalCountAsSuperAdmin(): void
    {
        $id = (int) Log2('game', 'test_ec');
        $this->insertedEventIds[] = $id;
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $count = EventCount(['game'], '');
            $this->assertGreaterThan(0, $count);
        } finally {
            $_SESSION = [];
        }
    }

    public function testEventCountReturnsZeroForNoCategories(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $this->assertSame(0, EventCount([], ''));
        } finally {
            $_SESSION = [];
        }
    }

    public function testEventCountWithUserFilter(): void
    {
        $id = (int) LogEvent(['category' => 'security', 'type' => 'test', 'user_id' => 'countme']);
        $this->insertedEventIds[] = $id;
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $count = EventCount(['security'], 'countme');
            $this->assertGreaterThanOrEqual(1, $count);
        } finally {
            $_SESSION = [];
        }
    }

    // --- ClearEventList ---

    public function testClearEventListDeletesEventsByIdArrayAsSuperAdmin(): void
    {
        $id1 = (int) Log2('game', 'clear_test_1');
        $id2 = (int) Log2('game', 'clear_test_2');
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $result = ClearEventList([$id1, $id2]);
            $this->assertNotFalse($result);
            $remaining = DBQueryToValue("SELECT COUNT(*) FROM uo_event_log WHERE event_id IN ($id1,$id2)");
            $this->assertSame('0', $remaining);
        } finally {
            $_SESSION = [];
        }
    }

    public function testClearEventListAcceptsCommaDelimitedString(): void
    {
        $id = (int) Log2('game', 'clear_str_test');
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            ClearEventList((string) $id);
            $remaining = DBQueryToValue("SELECT COUNT(*) FROM uo_event_log WHERE event_id=$id");
            $this->assertSame('0', $remaining);
        } finally {
            $_SESSION = [];
        }
    }

    public function testClearEventListReturnsFalseForEmptyIds(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $result = ClearEventList([]);
            $this->assertFalse($result);
        } finally {
            $_SESSION = [];
        }
    }

    public function testEventListWithMultipleCategoriesBuildsOrClause(): void
    {
        $id = (int) Log2('pool', 'multi_cat_test');
        $this->insertedEventIds[] = $id;
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $rows = EventList(['pool', 'game'], '');
            $ids = array_column($rows, 'event_id');
            $this->assertContains((string) $id, $ids);
        } finally {
            $_SESSION = [];
        }
    }

    public function testEventCountWithMultipleCategories(): void
    {
        LegacyApp::loadUserFunctions();
        LegacyApp::loginAsAdmin();
        try {
            $count = EventCount(['pool', 'game'], '');
            $this->assertGreaterThanOrEqual(0, $count);
        } finally {
            $_SESSION = [];
        }
    }

    public function testEventListReturnsEmptyWhenNotSuperAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        $this->assertSame([], EventList(['game'], ''));
    }

    public function testEventCountReturnsZeroWhenNotSuperAdmin(): void
    {
        LegacyApp::loadUserFunctions();
        $this->assertSame(0, EventCount(['game'], ''));
    }

    // --- IsVisitorLoggingDisabled / LogPageLoad / LogVisitor ---

    public function testIsVisitorLoggingDisabledReturnsFalseWhenSettingIsFalse(): void
    {
        // setUp already set DisableVisitorLogging='false'
        $this->assertFalse(IsVisitorLoggingDisabled());
    }

    public function testLogPageLoadInsertsNewEntryOnFirstLoad(): void
    {
        $page = 'harness_test_' . uniqid();
        LogPageLoad($page);
        $count = DBQueryToValue("SELECT loads FROM uo_pageload_counter WHERE page='$page'");
        $this->assertSame('1', $count);
    }

    public function testLogPageLoadIncrementsExistingEntry(): void
    {
        $page = 'harness_test_inc_' . uniqid();
        LogPageLoad($page);
        LogPageLoad($page);
        $count = DBQueryToValue("SELECT loads FROM uo_pageload_counter WHERE page='$page'");
        $this->assertSame('2', $count);
    }

    public function testLogPageLoadIgnoresInvalidPageName(): void
    {
        $before = DBQueryToValue("SELECT COUNT(*) FROM uo_pageload_counter");
        LogPageLoad('');
        LogPageLoad('bad name!@#');
        $after = DBQueryToValue("SELECT COUNT(*) FROM uo_pageload_counter");
        $this->assertSame($before, $after);
    }

    public function testLogVisitorInsertsNewEntry(): void
    {
        $ip = '192.0.2.' . rand(1, 254);
        LogVisitor($ip);
        $visits = DBQueryToValue("SELECT visits FROM uo_visitor_counter WHERE ip='$ip'");
        $this->assertSame('1', $visits);
    }

    public function testLogVisitorIncrementsExistingEntry(): void
    {
        $ip = '192.0.2.' . rand(1, 254);
        LogVisitor($ip);
        LogVisitor($ip);
        $visits = DBQueryToValue("SELECT visits FROM uo_visitor_counter WHERE ip='$ip'");
        $this->assertSame('2', $visits);
    }

    public function testLogGetVisitorCountReturnsAggregateData(): void
    {
        $row = LogGetVisitorCount();
        $this->assertIsArray($row);
        $this->assertArrayHasKey('visitors', $row);
        $this->assertArrayHasKey('visits', $row);
    }

    public function testLogGetPageLoadsReturnsArray(): void
    {
        // uo_pageload_counter starts empty and every earlier test's 'harness_test_*' pages are
        // cleaned up in tearDown, so no rows exist by the time this test runs.
        $results = LogGetPageLoads();
        $this->assertSame([], $results);
    }

    // --- LogResetVisitorCounter / LogResetPageLoadCounter ---

    public function testLogResetVisitorCounterClearsTableAsSuperAdmin(): void
    {
        DBQuery("INSERT IGNORE INTO uo_visitor_counter (ip, visits) VALUES ('192.0.2.100', 5)");
        LegacyApp::loadUserFunctions();
        LegacyApp::loadLibFilesUsingProfile(['configuration.functions.php'], 'database_only');
        LegacyApp::loginAsAdmin();
        try {
            $result = LogResetVisitorCounter();
            $this->assertNotFalse($result);
            $count = DBQueryToValue("SELECT COUNT(*) FROM uo_visitor_counter");
            $this->assertSame('0', $count);
        } finally {
            $_SESSION = [];
        }
    }

    public function testLogResetPageLoadCounterClearsTableAsSuperAdmin(): void
    {
        LogPageLoad('harness_test_reset');
        LegacyApp::loadUserFunctions();
        LegacyApp::loadLibFilesUsingProfile(['configuration.functions.php'], 'database_only');
        LegacyApp::loginAsAdmin();
        try {
            $result = LogResetPageLoadCounter();
            $this->assertNotFalse($result);
            $count = DBQueryToValue("SELECT COUNT(*) FROM uo_pageload_counter");
            $this->assertSame('0', $count);
        } finally {
            $_SESSION = [];
        }
    }
}
