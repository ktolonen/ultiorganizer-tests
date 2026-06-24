<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class VersionFunctionsLibTest extends TestCase
{
    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('version.functions.php', 'database_only');
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    // --- ReadVersionMetadata ---

    public function testReadVersionMetadataReturnsTrimmedStringFromFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'uo_vm_');
        file_put_contents($tmp, '<?php return "  2.5.1  ";');
        $result = ReadVersionMetadata($tmp);
        unlink($tmp);
        $this->assertSame('2.5.1', $result);
    }

    public function testReadVersionMetadataReturnsEmptyStringForMissingFile(): void
    {
        $this->assertSame('', ReadVersionMetadata('/tmp/ultiorg_nonexistent_abc123.php'));
    }

    public function testReadVersionMetadataFallsBackToConstantWhenFileReturnsNonString(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'uo_vm_');
        file_put_contents($tmp, '<?php return 0;');
        define('TEST_RVM_CONST_FALLBACK', '3.7.2');
        $result = ReadVersionMetadata($tmp, 'TEST_RVM_CONST_FALLBACK');
        unlink($tmp);
        $this->assertSame('3.7.2', $result);
    }

    public function testReadVersionMetadataReturnsEmptyWhenFileReturnsNonStringAndConstantUndefined(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'uo_vm_');
        file_put_contents($tmp, '<?php return 0;');
        $result = ReadVersionMetadata($tmp, 'TEST_RVM_CONST_UNDEFINED_ZZZ');
        unlink($tmp);
        $this->assertSame('', $result);
    }

    public function testReadVersionMetadataReturnsEmptyWhenFileReturnsEmptyString(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'uo_vm_');
        file_put_contents($tmp, '<?php return "   ";');
        $result = ReadVersionMetadata($tmp);
        unlink($tmp);
        $this->assertSame('', $result);
    }

    // --- GetUltiorganizerVersionInfo ---

    public function testGetUltiorganizerVersionInfoReturnsFallbackStructureWhenNoVersionFile(): void
    {
        // Without $include_prefix pointing to SUT, version.php is not found in CWD — falls back to '0.0'
        $result = GetUltiorganizerVersionInfo();
        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('major', $result);
        $this->assertArrayHasKey('minor', $result);
        $this->assertSame('0.0', $result['version']);
        $this->assertSame(0, $result['major']);
        $this->assertSame(0, $result['minor']);
    }

    // --- GetCustomizationId ---

    public function testGetCustomizationIdReturnsDefaultWhenConstantNotDefined(): void
    {
        $this->assertSame('default', GetCustomizationId());
    }

    // --- GetCustomizationVersionInfo ---

    public function testGetCustomizationVersionInfoReturnsDefaultStructureWithNoFiles(): void
    {
        $result = GetCustomizationVersionInfo();
        $this->assertSame('default', $result['id']);
        $this->assertSame('0.0', $result['version']);
    }

    // --- GetDatabaseVersionInfo ---

    public function testGetDatabaseVersionInfoReturnsIntegerVersion(): void
    {
        $result = GetDatabaseVersionInfo();
        $this->assertArrayHasKey('version', $result);
        $this->assertIsInt($result['version']);
        $this->assertGreaterThan(0, $result['version']);
    }
}
