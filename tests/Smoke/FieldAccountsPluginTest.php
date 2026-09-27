<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

/**
 * plugins/add_field_accounts.php creates one scorekeeping account per field
 * of an event's reservations, named after the field. Every query it runs
 * escapes the name except the existence check, which interpolates it raw, so
 * a field name with an apostrophe aborts the run. The test gives one fixture
 * field an apostrophe and runs the plugin as the superadmin.
 */
final class FieldAccountsPluginTest extends TestCase
{
    private const FIELD = "O'Hare";

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile([], 'database_with_common');
        self::cleanUp();
        DBQuery(sprintf("UPDATE uo_reservation SET fieldname='%s' WHERE id=501", DBEscapeString(self::FIELD)));
    }

    protected function tearDown(): void
    {
        DBQuery("UPDATE uo_reservation SET fieldname='2' WHERE id=501");
        self::cleanUp();
        LegacyApp::closeDatabaseConnection();
    }

    public function testCreatesAnAccountForEveryField(): void
    {
        self::runPlugin();

        // Field "1" of reservation 500 becomes "field1".
        $this->assertSame('1', self::accountCount('field1'));
        $this->assertSame('1', self::accountCount(self::FIELD));
    }

    private static function accountCount(string $userid): string
    {
        return (string) DBQueryToValueUncached(sprintf(
            "SELECT COUNT(*) FROM uo_users WHERE userid='%s'",
            DBEscapeString($userid),
        ));
    }

    private static function runPlugin(): void
    {
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $login = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query(['myusername' => 'admin', 'mypassword' => 'harness-admin']),
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 20,
        ]]);
        file_get_contents($baseUrl . '/index.php?view=frontpage', false, $login);
        $cookies = [];
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $m)) {
                $cookies[$m[1]] = $m[1] . '=' . $m[2];
            }
        }
        self::assertNotEmpty($cookies, 'login set no cookie');

        $run = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Cookie: " . implode('; ', $cookies) . "\r\nContent-Type: application/x-www-form-urlencoded",
            'content' => http_build_query(['create' => '1', 'season' => 'HRN2026']),
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 20,
        ]]);
        file_get_contents($baseUrl . '/index.php?view=plugins/add_field_accounts', false, $run);
    }

    private static function cleanUp(): void
    {
        foreach (['field1', self::FIELD] as $userid) {
            $u = DBEscapeString($userid);
            DBQuery("DELETE FROM uo_userproperties WHERE userid='$u'");
            DBQuery("DELETE FROM uo_users WHERE userid='$u'");
        }
    }
}
