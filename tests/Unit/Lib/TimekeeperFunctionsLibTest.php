<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * Tests for lib/timekeeper.functions.php.
 *
 * Die-path gaps (cannot be tested in-process per docs/lib-test-deep-coverage.md):
 *   - TimekeeperRequireSuperAdmin: non-superadmin branch calls die()
 *   - DeleteTimekeeperTemplate: is_default guard calls die()
 *   - SetTimekeeperTemplate: not-found guard calls die()
 *   - SetDefaultTimekeeperTemplate: not-found guard calls die()
 */
final class TimekeeperFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('timekeeper.functions.php', 'database_only');
        LegacyApp::loginAsAdmin();
        $_SESSION['userproperties']['locale'] = 'en_US';
        self::flushQueryCaches();
    }

    protected function tearDown(): void
    {
        self::flushQueryCaches();
        $_SESSION = [];
        LegacyApp::closeDatabaseConnection();
    }

    private static function flushQueryCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            if (function_exists('CacheForgetPersistent')) {
                CacheForgetPersistent($ns);
            }
            if (function_exists('CacheForgetNamespace')) {
                CacheForgetNamespace($ns);
            }
        }
    }

    // ===== Pure function tests (no DB) =====

    public function testTimekeeperActionDefinitionsReturnsAllExpectedKeys(): void
    {
        $defs = TimekeeperActionDefinitions();
        $this->assertArrayHasKey('betweenpoints', $defs);
        $this->assertArrayHasKey('timeout', $defs);
        $this->assertArrayHasKey('halftime', $defs);
        $this->assertArrayHasKey('halfstart', $defs);
        $this->assertArrayHasKey('dispute', $defs);
        $this->assertArrayHasKey('discretrieval', $defs);
        $this->assertCount(6, $defs);
    }

    public function testTimekeeperActionSignalGroupsIncludesTimeoutBeforePull(): void
    {
        $groups = TimekeeperActionSignalGroups();
        $this->assertArrayHasKey('timeoutbeforepull', $groups);
        $this->assertCount(7, $groups);
    }

    public function testTimekeeperTemplateCapFieldsReturnsTwoCapFields(): void
    {
        $fields = TimekeeperTemplateCapFields();
        $this->assertArrayHasKey('half_time_cap', $fields);
        $this->assertArrayHasKey('time_cap', $fields);
        $this->assertCount(2, $fields);
    }

    public function testTimekeeperTemplateCapDefaultsReturnsIntegerDefaults(): void
    {
        $defaults = TimekeeperTemplateCapDefaults();
        $this->assertSame(55, $defaults['half_time_cap']);
        $this->assertSame(100, $defaults['time_cap']);
    }

    public function testTimekeeperTemplateCapsFromRowUsesDefaultForAbsentKey(): void
    {
        $caps = TimekeeperTemplateCapsFromRow([]);
        $this->assertSame(55, $caps['half_time_cap']);
        $this->assertSame(100, $caps['time_cap']);
    }

    public function testTimekeeperTemplateCapsFromRowCastsRowValueToInt(): void
    {
        $caps = TimekeeperTemplateCapsFromRow(['half_time_cap' => '45', 'time_cap' => '80']);
        $this->assertSame(45, $caps['half_time_cap']);
        $this->assertSame(80, $caps['time_cap']);
    }

    public function testTimekeeperNormalizeTemplateCapsAcceptsNormalValues(): void
    {
        $caps = TimekeeperNormalizeTemplateCaps(['half_time_cap' => 50, 'time_cap' => 95]);
        $this->assertSame(50, $caps['half_time_cap']);
        $this->assertSame(95, $caps['time_cap']);
    }

    public function testTimekeeperNormalizeTemplateCapsDefaultsWhenKeyMissing(): void
    {
        $caps = TimekeeperNormalizeTemplateCaps([]);
        $this->assertSame(55, $caps['half_time_cap']);
        $this->assertSame(100, $caps['time_cap']);
    }

    public function testTimekeeperNormalizeTemplateCapsClampsBelowZero(): void
    {
        $caps = TimekeeperNormalizeTemplateCaps(['half_time_cap' => -10, 'time_cap' => -5]);
        $this->assertSame(0, $caps['half_time_cap']);
        $this->assertSame(0, $caps['time_cap']);
    }

    public function testTimekeeperNormalizeTemplateSignalsFiltersRowsMissingRequiredKeys(): void
    {
        $result = TimekeeperNormalizeTemplateSignals([
            ['action_key' => 'betweenpoints', 'signal_time' => 10],
            [],
        ]);
        $this->assertCount(0, $result);
    }

    public function testTimekeeperNormalizeTemplateSignalsSkipsUnknownActionKey(): void
    {
        $result = TimekeeperNormalizeTemplateSignals([
            ['action_key' => 'not_a_real_action', 'signal_time' => 10, 'signal_text' => 'Ready'],
        ]);
        $this->assertCount(0, $result);
    }

    public function testTimekeeperNormalizeTemplateSignalsSkipsBlankSignalText(): void
    {
        $result = TimekeeperNormalizeTemplateSignals([
            ['action_key' => 'betweenpoints', 'signal_time' => 10, 'signal_text' => '   '],
        ]);
        $this->assertCount(0, $result);
    }

    public function testTimekeeperNormalizeTemplateSignalsNormalizesValidRow(): void
    {
        $result = TimekeeperNormalizeTemplateSignals([
            ['action_key' => 'betweenpoints', 'signal_time' => '10', 'signal_text' => ' Ready '],
        ]);
        $this->assertCount(1, $result);
        $this->assertSame('betweenpoints', $result[0]['action_key']);
        $this->assertSame(10, $result[0]['signal_time']);
        $this->assertSame('Ready', $result[0]['signal_text']);
    }

    // ===== DB-backed tests =====

    public function testTimekeeperRequireSuperAdminDoesNotDieForSuperAdmin(): void
    {
        TimekeeperRequireSuperAdmin('edit');
        $this->assertTrue(true);
    }

    public function testTimekeeperTemplateInfoReturnsNullForMissingId(): void
    {
        $this->assertNull(TimekeeperTemplateInfo(999999));
    }

    public function testTimekeeperDefaultTemplateIdReturnsZeroWhenTableIsEmpty(): void
    {
        DBQuery("DELETE FROM uo_timekeeper_template_signal");
        DBQuery("DELETE FROM uo_timekeeper_template");
        self::flushQueryCaches();
        $this->assertSame(0, TimekeeperDefaultTemplateId());
    }

    public function testAddTimekeeperTemplateInsertsRecordAndReturnsId(): void
    {
        $templateId = null;
        try {
            $templateId = AddTimekeeperTemplate(
                ['name' => 'Test Template', 'half_time_cap' => 45, 'time_cap' => 90],
                []
            );
            $this->assertGreaterThan(0, $templateId);
            self::flushQueryCaches();
            $info = TimekeeperTemplateInfo($templateId);
            $this->assertIsArray($info);
            $this->assertSame('Test Template', $info['name']);
            $this->assertSame(45, $info['half_time_cap']);
            $this->assertSame(90, $info['time_cap']);
        } finally {
            if ($templateId) {
                DBQuery("DELETE FROM uo_timekeeper_template_signal WHERE template_id=$templateId");
                DBQuery("DELETE FROM uo_timekeeper_template WHERE template_id=$templateId");
                self::flushQueryCaches();
            }
        }
    }

    public function testTimekeeperTemplateRowsIncludesInsertedTemplate(): void
    {
        $templateId = null;
        try {
            $templateId = AddTimekeeperTemplate(['name' => 'Rows Test', 'half_time_cap' => 55, 'time_cap' => 100], []);
            self::flushQueryCaches();
            $rows = TimekeeperTemplateRows();
            $names = array_column($rows, 'name');
            $this->assertContains('Rows Test', $names);
        } finally {
            if ($templateId) {
                DBQuery("DELETE FROM uo_timekeeper_template_signal WHERE template_id=$templateId");
                DBQuery("DELETE FROM uo_timekeeper_template WHERE template_id=$templateId");
                self::flushQueryCaches();
            }
        }
    }

    public function testTimekeeperDefaultTemplateIdFindsDefaultTemplate(): void
    {
        $templateId = null;
        try {
            $templateId = (int) DBQueryInsert(
                "INSERT INTO uo_timekeeper_template (name, is_default, is_system, half_time_cap, time_cap) VALUES ('Default Test', 1, 0, 55, 100)"
            );
            self::flushQueryCaches();
            $this->assertSame($templateId, TimekeeperDefaultTemplateId());
        } finally {
            if ($templateId) {
                DBQuery("DELETE FROM uo_timekeeper_template WHERE template_id=$templateId");
                self::flushQueryCaches();
            }
        }
    }

    public function testTimekeeperTemplateSignalRowsReturnsInsertedSignals(): void
    {
        $templateId = null;
        try {
            $templateId = AddTimekeeperTemplate(
                ['name' => 'Signal Test', 'half_time_cap' => 55, 'time_cap' => 100],
                [
                    ['action_key' => 'betweenpoints', 'signal_time' => 30, 'signal_text' => 'Ready'],
                    ['action_key' => 'timeout', 'signal_time' => 60, 'signal_text' => 'Stop'],
                ]
            );
            self::flushQueryCaches();
            $signals = TimekeeperTemplateSignalRows($templateId);
            $this->assertCount(2, $signals);
            $actionKeys = array_column($signals, 'action_key');
            $this->assertContains('betweenpoints', $actionKeys);
            $this->assertContains('timeout', $actionKeys);
        } finally {
            if ($templateId) {
                DBQuery("DELETE FROM uo_timekeeper_template_signal WHERE template_id=$templateId");
                DBQuery("DELETE FROM uo_timekeeper_template WHERE template_id=$templateId");
                self::flushQueryCaches();
            }
        }
    }

    public function testTimekeeperTemplateSignalsByActionGroupsSignalsByActionKey(): void
    {
        $templateId = null;
        try {
            $templateId = AddTimekeeperTemplate(
                ['name' => 'Group Test', 'half_time_cap' => 55, 'time_cap' => 100],
                [
                    ['action_key' => 'betweenpoints', 'signal_time' => 30, 'signal_text' => 'Ready'],
                ]
            );
            self::flushQueryCaches();
            $grouped = TimekeeperTemplateSignalsByAction($templateId);
            $this->assertArrayHasKey('betweenpoints', $grouped);
            $this->assertCount(1, $grouped['betweenpoints']);
            $this->assertSame(30, $grouped['betweenpoints'][0]['time']);
        } finally {
            if ($templateId) {
                DBQuery("DELETE FROM uo_timekeeper_template_signal WHERE template_id=$templateId");
                DBQuery("DELETE FROM uo_timekeeper_template WHERE template_id=$templateId");
                self::flushQueryCaches();
            }
        }
    }

    public function testTimekeeperTemplatesForClientReturnsExpectedStructure(): void
    {
        $templateId = null;
        try {
            $templateId = AddTimekeeperTemplate(
                ['name' => 'Client Test', 'half_time_cap' => 55, 'time_cap' => 100],
                []
            );
            self::flushQueryCaches();
            $result = TimekeeperTemplatesForClient();
            $this->assertArrayHasKey('defaultTemplateId', $result);
            $this->assertArrayHasKey('templates', $result);
            $this->assertArrayHasKey($templateId, $result['templates']);
            $template = $result['templates'][$templateId];
            $this->assertSame('Client Test', $template['name']);
            $this->assertArrayHasKey('caps', $template);
            $this->assertArrayHasKey('signals', $template);
        } finally {
            if ($templateId) {
                DBQuery("DELETE FROM uo_timekeeper_template_signal WHERE template_id=$templateId");
                DBQuery("DELETE FROM uo_timekeeper_template WHERE template_id=$templateId");
                self::flushQueryCaches();
            }
        }
    }

    public function testSetTimekeeperTemplateUpdatesNameAndCaps(): void
    {
        $templateId = null;
        try {
            $templateId = AddTimekeeperTemplate(
                ['name' => 'Old Name', 'half_time_cap' => 55, 'time_cap' => 100],
                []
            );
            self::flushQueryCaches();
            SetTimekeeperTemplate($templateId, ['name' => 'New Name', 'half_time_cap' => 50, 'time_cap' => 90], []);
            self::flushQueryCaches();
            $info = TimekeeperTemplateInfo($templateId);
            $this->assertSame('New Name', $info['name']);
            $this->assertSame(50, $info['half_time_cap']);
            $this->assertSame(90, $info['time_cap']);
        } finally {
            if ($templateId) {
                DBQuery("DELETE FROM uo_timekeeper_template_signal WHERE template_id=$templateId");
                DBQuery("DELETE FROM uo_timekeeper_template WHERE template_id=$templateId");
                self::flushQueryCaches();
            }
        }
    }

    public function testSetTimekeeperTemplateSignalsReplacesExistingSignals(): void
    {
        $templateId = null;
        try {
            $templateId = AddTimekeeperTemplate(
                ['name' => 'Replace Signal Test', 'half_time_cap' => 55, 'time_cap' => 100],
                [['action_key' => 'betweenpoints', 'signal_time' => 10, 'signal_text' => 'Old']]
            );
            self::flushQueryCaches();
            SetTimekeeperTemplateSignals(
                $templateId,
                [['action_key' => 'timeout', 'signal_time' => 20, 'signal_text' => 'New']]
            );
            self::flushQueryCaches();
            $signals = TimekeeperTemplateSignalRows($templateId);
            $this->assertCount(1, $signals);
            $this->assertSame('timeout', $signals[0]['action_key']);
            $this->assertSame('New', $signals[0]['signal_text']);
        } finally {
            if ($templateId) {
                DBQuery("DELETE FROM uo_timekeeper_template_signal WHERE template_id=$templateId");
                DBQuery("DELETE FROM uo_timekeeper_template WHERE template_id=$templateId");
                self::flushQueryCaches();
            }
        }
    }

    public function testSetDefaultTimekeeperTemplateSetsIsDefaultFlag(): void
    {
        $templateId = null;
        try {
            $templateId = AddTimekeeperTemplate(
                ['name' => 'Default Candidate', 'half_time_cap' => 55, 'time_cap' => 100],
                []
            );
            self::flushQueryCaches();
            SetDefaultTimekeeperTemplate($templateId);
            self::flushQueryCaches();
            $info = TimekeeperTemplateInfo($templateId);
            $this->assertSame(1, $info['is_default']);
            $this->assertSame($templateId, TimekeeperDefaultTemplateId());
        } finally {
            if ($templateId) {
                DBQuery("UPDATE uo_timekeeper_template SET is_default=0 WHERE template_id=$templateId");
                DBQuery("DELETE FROM uo_timekeeper_template_signal WHERE template_id=$templateId");
                DBQuery("DELETE FROM uo_timekeeper_template WHERE template_id=$templateId");
                self::flushQueryCaches();
            }
        }
    }

    public function testDeleteTimekeeperTemplateRemovesRecord(): void
    {
        $templateId = null;
        try {
            $templateId = AddTimekeeperTemplate(
                ['name' => 'To Delete', 'half_time_cap' => 55, 'time_cap' => 100],
                []
            );
            self::flushQueryCaches();
            DeleteTimekeeperTemplate($templateId);
            self::flushQueryCaches();
            $this->assertNull(TimekeeperTemplateInfo($templateId));
            $templateId = null;
        } finally {
            if ($templateId) {
                DBQuery("DELETE FROM uo_timekeeper_template_signal WHERE template_id=$templateId");
                DBQuery("DELETE FROM uo_timekeeper_template WHERE template_id=$templateId");
                self::flushQueryCaches();
            }
        }
    }
}
