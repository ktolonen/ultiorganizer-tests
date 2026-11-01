<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class ApiFunctionsLibTest extends TestCase
{
    // Fixture constants: token_id=900, token_value='harness-api-token',
    // scope_type='season', scope_id='HRN2026', revoked=0.
    private const FIXTURE_TOKEN_VALUE = 'harness-api-token';
    private const FIXTURE_TOKEN_ID    = 900;
    private const FIXTURE_TOKEN_HASH  = '1a6918a0de39b1d923f2d4b15b44c153f2c4fbbe2bc6e82604c2b3ec37890a68';

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('api.functions.php', 'database_only');
    }

    protected function tearDown(): void
    {
        LegacyApp::closeDatabaseConnection();
    }

    // --- ApiHashToken ---

    public function testApiHashTokenProducesSha256Hex(): void
    {
        $hash = ApiHashToken('sometoken');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function testApiHashTokenMatchesFixtureKnownHash(): void
    {
        $this->assertSame(self::FIXTURE_TOKEN_HASH, ApiHashToken(self::FIXTURE_TOKEN_VALUE));
    }

    public function testApiHashTokenIsDeterministicForSameInput(): void
    {
        $this->assertSame(ApiHashToken('x'), ApiHashToken('x'));
    }

    public function testApiHashTokenDiffersForDifferentInputs(): void
    {
        $this->assertNotSame(ApiHashToken('a'), ApiHashToken('b'));
    }

    public function testApiHashTokenHandlesEmptyString(): void
    {
        $hash = ApiHashToken('');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    // --- ApiTokenLookup ---

    public function testApiTokenLookupReturnsFixtureTokenRow(): void
    {
        $row = ApiTokenLookup(self::FIXTURE_TOKEN_VALUE);
        $this->assertIsArray($row);
        $this->assertSame((string) self::FIXTURE_TOKEN_ID, (string) $row['token_id']);
        $this->assertSame('season', $row['scope_type']);
        $this->assertSame('HRN2026', $row['scope_id']);
        $this->assertSame('0', (string) $row['revoked']);
    }

    public function testApiTokenLookupReturnsNullForUnknownToken(): void
    {
        $this->assertNull(ApiTokenLookup('no-such-token-' . bin2hex(random_bytes(8))));
    }

    public function testApiTokenLookupReturnsNullForRevokedToken(): void
    {
        $created = ApiTokenCreate('revoke-test', 'season', 'HRN2026');
        ApiTokenSetRevoked((int) $created['token_id'], 1);

        $result = ApiTokenLookup($created['token']);

        ApiTokenDelete((int) $created['token_id']);
        $this->assertNull($result);
    }

    // --- ApiUserToken, ApiUserTokenReplace and ApiUserTokenRevoke ---

    private function requirePersonalTokens(): void
    {
        if (!function_exists('ApiUserTokenRevoke')) {
            $this->markTestSkipped('The SUT has no personal app tokens.');
        }
    }

    public function testApiUserTokenIsCreatedReplacedAndRemovedByTheLoggedInUser(): void
    {
        $this->requirePersonalTokens();
        $_SESSION = ['uid' => 'harness_app_user', 'userproperties' => []];
        try {
            $this->assertNull(ApiUserToken('harness_app_user'), 'opening the page creates no token');

            $first = ApiUserTokenReplace('harness_app_user');
            $this->assertIsArray($first);
            $shown = ApiUserToken('harness_app_user');
            $this->assertSame([$first['token_id'], $first['token'], null], [$shown['token_id'], $shown['token'], $shown['last_used']]);
            $row = ApiTokenLookup($first['token']);
            $this->assertSame(['user', 'harness_app_user'], [$row['scope_type'], $row['scope_id']]);

            ApiTokenTouch($first['token_id']);
            $this->assertNotEmpty(ApiUserToken('harness_app_user')['last_used'], 'the page shows when the token was last used');

            $second = ApiUserTokenReplace('harness_app_user');
            $this->assertNotSame($first['token'], $second['token']);
            $this->assertNull(ApiTokenLookup($first['token']), 'replacing revokes the old token');
            $this->assertSame($second['token'], ApiUserToken('harness_app_user')['token']);

            $this->assertTrue(ApiUserTokenRevoke('harness_app_user'));
            $this->assertNull(ApiTokenLookup($second['token']), 'removing revokes the token');
            $this->assertNull(ApiUserToken('harness_app_user'));
            $this->assertSame(2, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_api_token WHERE scope_type='user' AND scope_id='harness_app_user' AND revoked=1"),
                'revoked rows stay so history rows can still name them');
        } finally {
            DBQuery("DELETE FROM uo_api_token WHERE scope_type='user' AND scope_id='harness_app_user'");
        }
    }

    public function testApiUserTokenIsOnlyForThatLoggedInUser(): void
    {
        $this->requirePersonalTokens();
        DBQuery("INSERT INTO uo_api_token (token_hash, token_value, label, scope_type, scope_id)
            VALUES (SHA2('harness-admin-app', 256), 'harness-admin-app', 'Scorekeeping app', 'user', 'admin')");
        try {
            $_SESSION = ['uid' => 'anonymous', 'userproperties' => []];
            $this->assertNull(ApiUserToken('anonymous'));
            $this->assertNull(ApiUserTokenReplace('anonymous'));
            $this->assertFalse(ApiUserTokenRevoke('anonymous'));
            $_SESSION = ['uid' => 'harness_app_user', 'userproperties' => []];
            $this->assertNull(ApiUserToken('admin'));
            $this->assertNull(ApiUserTokenReplace('admin'));
            $this->assertFalse(ApiUserTokenRevoke('admin'));
            $this->assertIsArray(ApiTokenLookup('harness-admin-app'), "another user cannot revoke the admin's token");
            $this->assertSame(1, (int) DBQueryToValueUncached("SELECT COUNT(*) FROM uo_api_token WHERE scope_type='user' AND scope_id IN ('anonymous', 'admin', 'harness_app_user')
                AND label='Scorekeeping app'"));
        } finally {
            DBQuery("DELETE FROM uo_api_token WHERE token_value='harness-admin-app'");
        }
    }

    // --- ApiTokenList ---

    public function testApiTokenListIncludesFixtureToken(): void
    {
        $list = ApiTokenList();
        $this->assertIsArray($list);
        $ids = array_column($list, 'token_id');
        $this->assertContains((string) self::FIXTURE_TOKEN_ID, array_map('strval', $ids));
    }

    public function testApiTokenListReturnsAllColumns(): void
    {
        $list = ApiTokenList();
        $this->assertNotEmpty($list);
        $row = $list[0];
        foreach (['token_id', 'token_value', 'label', 'scope_type', 'scope_id', 'revoked', 'created_at'] as $col) {
            $this->assertArrayHasKey($col, $row);
        }
    }

    // --- ApiTokenCreate and ApiTokenDelete ---

    public function testApiTokenCreateReturnsTokenIdAndValue(): void
    {
        $result = ApiTokenCreate('unit-test', 'season', 'HRN2026');

        $this->assertArrayHasKey('token_id', $result);
        $this->assertArrayHasKey('token', $result);
        $this->assertGreaterThan(0, (int) $result['token_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $result['token']);

        ApiTokenDelete((int) $result['token_id']);
    }

    public function testApiTokenCreateWithNullScopeId(): void
    {
        $result = ApiTokenCreate('null-scope-test', 'global', null);

        $this->assertArrayHasKey('token_id', $result);
        $this->assertGreaterThan(0, (int) $result['token_id']);

        $row = ApiTokenLookup($result['token']);
        $this->assertIsArray($row);
        $this->assertNull($row['scope_id']);

        ApiTokenDelete((int) $result['token_id']);
    }

    public function testApiTokenDeleteRemovesToken(): void
    {
        $created = ApiTokenCreate('delete-test', 'season', 'HRN2026');
        $tokenId = (int) $created['token_id'];

        ApiTokenDelete($tokenId);

        $list = ApiTokenList();
        $ids  = array_map('intval', array_column($list, 'token_id'));
        $this->assertNotContains($tokenId, $ids);
    }

    // --- ApiTokenSetRevoked ---

    public function testApiTokenSetRevokedAndUnrevoked(): void
    {
        $created = ApiTokenCreate('revoke-cycle', 'season', 'HRN2026');
        $tokenId = (int) $created['token_id'];
        $token   = $created['token'];

        ApiTokenSetRevoked($tokenId, 1);
        $this->assertNull(ApiTokenLookup($token));

        ApiTokenSetRevoked($tokenId, 0);
        $this->assertIsArray(ApiTokenLookup($token));

        ApiTokenDelete($tokenId);
    }

    // --- ApiTokenTouch ---

    public function testApiTokenTouchRunsWithoutError(): void
    {
        $created = ApiTokenCreate('touch-test', 'season', 'HRN2026');
        $tokenId = (int) $created['token_id'];

        ApiTokenTouch($tokenId);

        // Token should still be look-up-able after a touch, with its scope unchanged.
        $row = ApiTokenLookup($created['token']);
        $this->assertSame($tokenId, $row['token_id']);
        $this->assertSame('season', $row['scope_type']);
        $this->assertSame('HRN2026', $row['scope_id']);
        $this->assertSame(0, $row['revoked']);

        ApiTokenDelete($tokenId);
    }

    // --- ApiRateLimitCheck ---

    public function testApiRateLimitCheckReturnsExpectedShape(): void
    {
        $rateKey = 'test-' . bin2hex(random_bytes(8));
        $result  = ApiRateLimitCheck($rateKey, 100, 60);

        $this->assertArrayHasKey('allowed', $result);
        $this->assertArrayHasKey('remaining', $result);
        $this->assertArrayHasKey('reset', $result);
        $this->assertTrue($result['allowed']);
        $this->assertGreaterThanOrEqual(0, $result['remaining']);
        $this->assertGreaterThan(0, $result['reset']);
    }

    public function testApiRateLimitCheckAllowsFirstRequestAndDecrementsRemaining(): void
    {
        $rateKey = 'test-' . bin2hex(random_bytes(8));
        $limit   = 5;

        $first  = ApiRateLimitCheck($rateKey, $limit, 60);
        $second = ApiRateLimitCheck($rateKey, $limit, 60);

        $this->assertTrue($first['allowed']);
        $this->assertSame($limit - 1, $first['remaining']);
        $this->assertSame($limit - 2, $second['remaining']);
    }

    public function testApiRateLimitCheckBlocksWhenLimitExceeded(): void
    {
        $rateKey = 'test-' . bin2hex(random_bytes(8));
        $limit   = 2;

        ApiRateLimitCheck($rateKey, $limit, 60);
        ApiRateLimitCheck($rateKey, $limit, 60);
        $third = ApiRateLimitCheck($rateKey, $limit, 60);

        $this->assertFalse($third['allowed']);
        $this->assertSame(0, $third['remaining']);
    }

    public function testApiRateLimitResetIsInTheFuture(): void
    {
        $rateKey = 'test-' . bin2hex(random_bytes(8));
        $before  = time();
        $result  = ApiRateLimitCheck($rateKey, 10, 60);

        $this->assertGreaterThan($before, $result['reset']);
    }
}
