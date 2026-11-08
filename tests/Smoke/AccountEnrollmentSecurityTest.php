<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class AccountEnrollmentSecurityTest extends TestCase
{
    private const USER = 'audit-teamadmin';
    private const TOKEN = 'audit-confirm-token';
    private array $season = [];
    private array $cookies = [];

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFilesUsingProfile(['configuration.functions.php'], 'database_with_common');
        $this->season = DBQueryToRowUncached("SELECT enrollopen, event_readonly, public_event FROM uo_season WHERE season_id='HRN2026'");
        DBQuery("INSERT INTO uo_users (userid, password, name, email)
            SELECT '" . self::USER . "', password, 'Audit Team Admin', 'audit@example.com' FROM uo_users WHERE userid='admin'");
        DBQuery("INSERT INTO uo_userproperties (userid, name, value) VALUES ('" . self::USER . "', 'userrole', 'teamadmin:300')");
        DBQuery("INSERT INTO uo_player_profile (profile_id, firstname, lastname, accreditation_id, public)
            VALUES (9850, 'Audit', 'Player', 'LIC123', '')");
        DBQuery("INSERT INTO uo_player (player_id, firstname, lastname, team, num, profile_id, accreditation_id)
            VALUES (9850, 'Audit', 'Player', 300, 42, 9850, 'LIC123')");
        $this->flushCaches();
    }

    protected function tearDown(): void
    {
        DBQuery(sprintf("UPDATE uo_season SET enrollopen=%d, event_readonly=%d, public_event=%d WHERE season_id='HRN2026'",
            $this->season['enrollopen'], $this->season['event_readonly'], $this->season['public_event']));
        DBQuery("DELETE FROM uo_enrolledteam WHERE name='Audit Enrollment'");
        DBQuery("DELETE FROM uo_player WHERE player_id=9850");
        DBQuery("DELETE FROM uo_player_profile WHERE profile_id=9850");
        DBQuery("DELETE FROM uo_registerrequest WHERE userid='audit-confirm'");
        DBQuery("DELETE FROM uo_userproperties WHERE userid IN ('" . self::USER . "', 'audit-confirm')");
        DBQuery("DELETE FROM uo_users WHERE userid IN ('" . self::USER . "', 'audit-confirm')");
        $this->flushCaches();
        LegacyApp::closeDatabaseConnection();
    }

    public function testOnlySuperadminsCanReadAllEventStatistics(): void
    {
        [$status, $body] = $this->request('/index.php?view=admin/seasonstats', [], $this->login('admin'));
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Harness Invitational 2026', $body);

        [$status, $body] = $this->request('/index.php?view=admin/seasonstats', [], $this->login(self::USER));
        $this->assertSame(403, $status);
        $this->assertStringNotContainsString('Harness Invitational 2026', $body);
    }

    /** @return array<array{string}> */
    public static function closedEnrollmentStates(): array
    {
        return [['enrollopen=0'], ['event_readonly=1']];
    }

    #[DataProvider('closedEnrollmentStates')]
    public function testEnrollmentRequiresAnOpenWritableEvent(string $closed): void
    {
        $cookie = $this->login(self::USER);
        DBQuery("UPDATE uo_season SET enrollopen=1, event_readonly=0, public_event=1 WHERE season_id='HRN2026'");
        $this->enroll($cookie);
        $this->assertSame(1, $this->enrollmentCount());
        DBQuery("DELETE FROM uo_enrolledteam WHERE name='Audit Enrollment'");
        DBQuery("UPDATE uo_season SET $closed WHERE season_id='HRN2026'");
        $this->enroll($cookie);
        $this->assertSame(0, $this->enrollmentCount(), $closed);
    }

    public function testEnrollmentCannotTargetAnInaccessibleEventViaAnotherSeason(): void
    {
        DBQuery("DELETE FROM uo_userproperties WHERE userid='" . self::USER . "'");
        $cookie = $this->login(self::USER);
        DBQuery("UPDATE uo_season SET enrollopen=1, event_readonly=0, public_event=1 WHERE season_id='HRN2026'");
        $this->flushCaches();
        $this->enroll($cookie);
        $this->assertSame(1, $this->enrollmentCount());
        DBQuery("DELETE FROM uo_enrolledteam WHERE name='Audit Enrollment'");
        DBQuery("UPDATE uo_season SET public_event=0 WHERE season_id='HRN2026'");
        $this->flushCaches();
        // The URL is public while the target division arrives only in the POST.
        // Use a public event independent of the private target.
        DBQuery("INSERT INTO uo_season (season_id, name, public_event) VALUES ('AUDITPUB', 'Audit Public', 1)");
        try {
            $this->request('/index.php?view=user/enrollteam&season=AUDITPUB', $this->enrollmentFields(), $cookie);
        } finally {
            DBQuery("DELETE FROM uo_season WHERE season_id='AUDITPUB'");
        }
        $this->assertSame(0, $this->enrollmentCount());
    }

    public function testDivisionAdminsCanEnrollTeamsWhenEnrollmentIsClosed(): void
    {
        DBQuery("UPDATE uo_season SET enrollopen=0, event_readonly=0, public_event=1 WHERE season_id='HRN2026'");
        $this->flushCaches();
        DBQuery("UPDATE uo_userproperties SET value='seriesadmin:100' WHERE userid='" . self::USER . "' AND name='userrole'");
        $this->enroll($this->login(self::USER));
        $this->assertSame(1, $this->enrollmentCount());
    }

    public function testRegistrationPostWithATokenCannotBypassDisabledRegistration(): void
    {
        // Empty fields avoid email delivery even in the enabled profile. On the
        // disabled profile the page must redirect before validating those fields.
        [$status, $body] = $this->request('/index.php?view=register&token=not-a-real-token', ['save' => '1', 'UserName' => '', 'Password' => '', 'Password2' => '', 'Name' => '', 'Email' => '']);
        if (IsPublicRegistrationDisabled()) {
            $this->assertSame(302, $status);
            $this->assertStringNotContainsString("name='UserName'", $body);
        } else {
            $this->assertSame(200, $status);
            $this->assertStringContainsString("name='UserName'", $body);
        }
        // A real, already-issued link still confirms in both profiles.
        DBQuery("INSERT INTO uo_registerrequest (userid, password, name, email, token)
            VALUES ('audit-confirm', 'unusedhash', 'Audit Confirm', 'confirm@example.com', '" . self::TOKEN . "')");
        $this->assertSame(1, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_registerrequest WHERE token='" . self::TOKEN . "'"));
        [$status] = $this->request('/index.php?view=register&token=' . self::TOKEN);
        $this->assertSame(200, $status);
        $this->assertSame('Audit Confirm', DBQueryToValueUncached("SELECT name FROM uo_users WHERE userid='audit-confirm'"));
        $this->assertSame(0, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_registerrequest WHERE token='" . self::TOKEN . "'"));
    }

    public function testSlklLicenseIsEscapedForNonSuperadmins(): void
    {
        if (CUSTOMIZATIONS !== 'slkl') {
            $this->markTestSkipped('SLKL license policy is exercised by customization-slkl.');
        }
        $cookie = $this->login(self::USER);
        $marker = '<x-audit>';
        DBQuery("UPDATE uo_player_profile SET accreditation_id='$marker' WHERE profile_id=9850");
        $this->flushCaches();
        [$status, $body] = $this->request('/index.php?view=user/playerprofile&player=9850', [], $cookie);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('&lt;x-audit&gt;', $body);
        $this->assertStringNotContainsString($marker, $body);
    }

    public function testOnlySuperadminsCanChangeTheSlklLicense(): void
    {
        if (CUSTOMIZATIONS !== 'slkl') {
            $this->markTestSkipped('SLKL license policy is exercised by customization-slkl.');
        }
        $cookie = $this->login(self::USER);
        $marker = 'LIC123';
        $this->assertSame($marker, DBQueryToValueUncached("SELECT accreditation_id FROM uo_player_profile WHERE profile_id=9850"));
        $this->saveProfile($cookie, 'FORGED', 'Allowed nickname');
        $this->assertSame($marker, DBQueryToValueUncached("SELECT accreditation_id FROM uo_player_profile WHERE profile_id=9850"));
        $this->assertSame($marker, DBQueryToValueUncached("SELECT accreditation_id FROM uo_player WHERE player_id=9850"));
        $this->assertSame('Allowed nickname', DBQueryToValueUncached("SELECT nickname FROM uo_player_profile WHERE profile_id=9850"));

        $this->saveProfile($this->login('admin'), 'ADMIN42', 'Admin nickname');
        $this->assertSame('ADMIN42', DBQueryToValueUncached("SELECT accreditation_id FROM uo_player_profile WHERE profile_id=9850"));
        $this->assertSame('ADMIN42', DBQueryToValueUncached("SELECT accreditation_id FROM uo_player WHERE player_id=9850"));
    }

    private function saveProfile(string $cookie, string $license, string $nickname): void
    {
        $fields = array_fill_keys(['email', 'gender', 'info', 'national_id', 'birthplace', 'nationality',
            'throwing_hand', 'position', 'story', 'achievements'], '');
        $fields += ['save' => '1', 'backurl' => '?view=frontpage', 'accreditationId' => $license,
            'nickname' => $nickname, 'firstname' => 'Audit', 'lastname' => 'Player', 'num' => '42',
            'birthdate' => '01.01.2000', 'height' => '0', 'weight' => '0'];
        $boundary = 'audit-profile-form';
        $content = '';
        foreach ($fields as $key => $value) {
            $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"$key\"\r\n\r\n$value\r\n";
        }
        $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"picture\"; filename=\"\"\r\nContent-Type: application/octet-stream\r\n\r\n\r\n--$boundary--\r\n";
        [$status] = $this->request('/index.php?view=user/playerprofile&player=9850', [], $cookie, $content, "multipart/form-data; boundary=$boundary");
        $this->assertSame(200, $status);
    }

    private function enrollmentFields(): array
    {
        return ['add' => '1', 'index' => '1', 'series' => '100', 'name1' => 'Audit Enrollment'];
    }

    private function enroll(string $cookie): void
    {
        $this->request('/index.php?view=user/enrollteam&season=HRN2026', $this->enrollmentFields(), $cookie);
    }

    private function enrollmentCount(): int
    {
        return (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_enrolledteam WHERE name='Audit Enrollment' AND userid IN ('" . self::USER . "', 'admin') AND series=100");
    }

    private function login(string $user): string
    {
        if (isset($this->cookies[$user])) {
            return $this->cookies[$user];
        }
        [, , $headers] = $this->request('/index.php?view=frontpage', ['myusername' => $user, 'mypassword' => 'harness-admin']);
        $cookies = [];
        foreach ($headers as $header) {
            if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $m)) {
                $cookies[$m[1]] = $m[1] . '=' . $m[2];
            }
        }
        $this->assertNotEmpty($cookies);
        $cookie = implode('; ', $cookies);
        [$status, $body] = $this->request('/index.php?view=user/userinfo', [], $cookie);
        $this->assertSame(200, $status);
        $this->assertStringContainsString($user === 'admin' ? 'Harness Admin' : 'Audit Team Admin', $body);
        return $this->cookies[$user] = $cookie;
    }

    private function flushCaches(): void
    {
        foreach (['db_query_value', 'db_query_array', 'db_query_row', 'db_query_rowcount'] as $ns) {
            CacheForgetPersistent($ns);
            CacheForgetNamespace($ns);
        }
    }

    private function request(string $path, array $fields = [], string $cookie = '', ?string $content = null, string $type = 'application/x-www-form-urlencoded'): array
    {
        $this->flushCaches();
        $post = $fields !== [] || $content !== null;
        $context = stream_context_create(['http' => [
            'method' => $post ? 'POST' : 'GET',
            'header' => "Cookie: $cookie\r\nContent-Type: $type",
            'content' => $content ?? http_build_query($fields),
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20,
        ]]);
        $body = file_get_contents(LegacyApp::baseUrl() . $path, false, $context);
        $headers = $http_response_header ?? [];
        preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $matches);
        return [(int) ($matches[1] ?? 0), (string) $body, $headers];
    }
}
