<?php

declare(strict_types=1);

use UltiorganizerHarness\Support\ScorekeepingApiTestCase;

/**
 * Authentication and coverage matrix of the scorekeeping app API: the app
 * token (Authorization: Bearer only) and the scorekeeping link
 * (X-Scorekeeping-Token only) must both validate, and the link must cover the
 * game now.
 */
final class ScorekeepingApiAuthTest extends ScorekeepingApiTestCase
{
    private const ENTRY = '/api/v1/games/701/entry-context';
    private const DISCOVERY = '/api/v1/scorekeeping/games';

    public function testBothCredentialsOpenTheRoutes(): void
    {
        // Precondition for every refusal below.
        $this->assertSame(200, self::get(self::DISCOVERY)['status']);
        $this->assertSame(200, self::get(self::ENTRY)['status']);
        $this->assertSame(200, self::get(self::ENTRY, self::auth(self::TOKEN_EVENT))['status'], 'event-scoped token of the game event');
    }

    public function testNoAppTokenIsRefused(): void
    {
        foreach ([self::DISCOVERY, self::ENTRY] as $path) {
            self::assertError(self::request('GET', $path, ['X-Scorekeeping-Token' => self::LINK_GAME]), 401, 'missing_token');
        }
    }

    public function testRevokedAndUnknownAppTokensAreRefused(): void
    {
        self::assertError(self::get(self::ENTRY, self::auth(self::TOKEN_REVOKED)), 401, 'invalid_token');
        self::assertError(self::get(self::ENTRY, self::auth('not-a-token')), 401, 'invalid_token');
    }

    public function testAppTokenOfAnotherEventIsRefused(): void
    {
        self::assertError(self::get(self::ENTRY, self::auth(self::TOKEN_OTHER_EVENT)), 403, 'event_scope_mismatch');
        self::assertError(self::get(self::DISCOVERY, self::auth(self::TOKEN_OTHER_EVENT)), 403, 'event_scope_mismatch');
    }

    public function testUserScopedAppTokenIsRefused(): void
    {
        self::assertError(self::get(self::ENTRY, self::auth(self::TOKEN_USER)), 403, 'invalid_scope');
        self::assertError(self::get(self::DISCOVERY, self::auth(self::TOKEN_USER)), 403, 'invalid_scope');
        // The same token reads the public API, so the refusal is about scorekeeping, not the token.
        $this->assertSame(200, self::request('GET', '/api/v1/games?event=HRN2026', ['Authorization' => 'Bearer ' . self::TOKEN_USER])['status']);
    }

    public function testNoLinkIsRefused(): void
    {
        foreach ([self::DISCOVERY, self::ENTRY] as $path) {
            self::assertError(self::get($path, self::auth(self::TOKEN_INSTALL, null)), 401, 'missing_scorekeeping_token');
        }
    }

    public function testUnknownLinkIsRefusedAndLoggedWithoutTheToken(): void
    {
        $unknown = 'dddddddddddddddddddddddddddd0001';
        $before = self::dbCount("SELECT COUNT(*) FROM uo_event_log WHERE category='security' AND description='invalid scorekeeping link'");

        self::assertError(self::get(self::ENTRY, self::auth(self::TOKEN_INSTALL, $unknown)), 401, 'invalid_scorekeeping_token');
        self::assertError(self::get(self::ENTRY, self::auth(self::TOKEN_INSTALL, 'short')), 401, 'invalid_scorekeeping_token');

        $this->assertSame($before + 2, self::dbCount("SELECT COUNT(*) FROM uo_event_log WHERE category='security' AND description='invalid scorekeeping link'"));
        $this->assertSame(0, self::dbCount("SELECT COUNT(*) FROM uo_event_log WHERE CONCAT_WS(' ', id1, id2, description) LIKE '%" . $unknown . "%'"));
    }

    public function testRotatedLinkStopsWorking(): void
    {
        DBQuery("DELETE FROM uo_scorekeeper_token WHERE token_id=920");

        self::assertError(self::get(self::ENTRY), 401, 'invalid_scorekeeping_token');
    }

    public function testLinkOfAnotherGameDoesNotCover(): void
    {
        self::assertError(self::get(self::ENTRY, self::auth(self::TOKEN_INSTALL, self::LINK_OTHER_GAME)), 403, 'game_not_covered');
        self::assertError(self::get(self::ENTRY, self::auth(self::TOKEN_INSTALL, self::LINK_OTHER_FIELD)), 403, 'game_not_covered');
        // Contrast: the field link of the game's own reservation covers it.
        $this->assertSame(200, self::get(self::ENTRY, self::auth(self::TOKEN_INSTALL, self::LINK_FIELD))['status']);
    }

    public function testGameMovedOutOfTheReservationIsNoLongerCovered(): void
    {
        DBQuery("UPDATE uo_game SET reservation=500 WHERE game_id=701");

        self::assertError(self::get(self::ENTRY, self::auth(self::TOKEN_INSTALL, self::LINK_FIELD)), 403, 'game_not_covered');
    }

    public function testLinkOutsideItsDayIsRefusedAndDiscoveryNamesTheDate(): void
    {
        DBQuery("UPDATE uo_game SET time='2026-06-01 14:00:00' WHERE game_id=701");

        self::assertError(self::get(self::ENTRY), 403, 'game_not_covered');
        $discovery = self::get(self::DISCOVERY);
        $this->assertSame(200, $discovery['status']);
        $this->assertSame([], $discovery['json']['data']['games']);
        $this->assertSame('2026-06-01', $discovery['json']['data']['available_from']);
    }

    public function testQueryStringAndLegacyHeadersDoNotCarryTheAppToken(): void
    {
        $link = ['X-Scorekeeping-Token' => self::LINK_GAME];
        self::assertError(self::request('GET', self::ENTRY . '?token=' . self::TOKEN_INSTALL, $link), 401, 'missing_token');
        self::assertError(self::request('GET', self::DISCOVERY . '?token=' . self::TOKEN_INSTALL, $link), 401, 'missing_token');
        self::assertError(self::request('GET', self::ENTRY, $link + ['X-Api-Token' => self::TOKEN_INSTALL]), 401, 'missing_token');
        self::assertError(self::request('GET', self::ENTRY, $link + ['Authorization' => 'Token ' . self::TOKEN_INSTALL]), 401, 'missing_token');
        // The link token is not read from the URL either.
        self::assertError(self::request('GET', self::ENTRY . '?t=' . self::LINK_GAME, ['Authorization' => 'Bearer ' . self::TOKEN_INSTALL]), 401, 'missing_scorekeeping_token');
    }

    public function testPrivateEventsWork(): void
    {
        DBQuery("UPDATE uo_season SET public_event=0, api_public=0 WHERE season_id='HRN2026'");

        $this->assertSame(200, self::get(self::ENTRY)['status']);
        $this->assertCount(1, self::get(self::DISCOVERY)['json']['data']['games']);
        // Contrast: the public API still refuses the private event.
        $public = self::request('GET', '/api/v1/games?event=HRN2026', ['Authorization' => 'Bearer ' . self::TOKEN_INSTALL]);
        $this->assertSame(403, $public['status']);
    }

    public function testMaintenanceAnswersWithoutTouchingTheGame(): void
    {
        DBQuery("UPDATE uo_season SET maintenance_mode=1 WHERE season_id='HRN2026'");

        $this->assertSame(503, self::get(self::ENTRY)['status']);
        $this->assertSame(503, self::get(self::DISCOVERY)['status']);
    }

    public function testWrongMethodsAndPlainGamesRouteAreUnchanged(): void
    {
        self::assertError(self::request('PUT', self::ENTRY, self::auth()), 405, 'method_not_allowed');
        self::assertError(self::request('GET', '/api/v1/games/701/game-data', self::auth()), 405, 'method_not_allowed');
        // The pre-existing games route still answers with the app token alone.
        $games = self::request('GET', '/api/v1/games?event=HRN2026', ['Authorization' => 'Bearer harness-api-token']);
        $this->assertSame(200, $games['status']);
        $this->assertSame('HRN2026', $games['json']['data']['event_id']);
    }

    public function testResponsesAreNeverCacheable(): void
    {
        foreach ([self::get(self::ENTRY), self::get(self::DISCOVERY), self::get(self::ENTRY, self::auth('not-a-token'))] as $response) {
            $this->assertSame('no-store', $response['headers']['cache-control'] ?? null);
        }
    }
}
