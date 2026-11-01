<?php

declare(strict_types=1);

use UltiorganizerHarness\Support\ScorekeepingApiTestCase;

/**
 * PUT and DELETE games/{id}/caps/{half_cap|time_cap}: the halftime cap and
 * time cap writes of the scorekeeping app.
 */
final class ScorekeepingApiCapsTest extends ScorekeepingApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $paths = self::request('GET', '/api/v1/openapi')['json']['paths'] ?? [];
        if (!isset($paths['/api/v1/games/{game_id}/caps/{type}'])) {
            $this->markTestSkipped('The SUT has no cap endpoints.');
        }
    }

    /** @param array<string, mixed>|null $body null sends no body */
    private static function cap(string $method, string $type, ?array $body, string $key): array
    {
        $headers = self::auth() + ['Idempotency-Key' => $key];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        return self::request($method, '/api/v1/games/' . self::GAME . '/caps/' . $type, $headers, $body === null ? null : json_encode($body));
    }

    /** @return array<string, array{time: int, info: int}> */
    private static function storedCaps(): array
    {
        $caps = [];
        foreach (DBQueryToArrayUncached("SELECT type, time, info FROM uo_gameevent WHERE game=701 AND type IN ('half_cap', 'time_cap')") as $row) {
            $caps[$row['type']] = ['time' => (int) $row['time'], 'info' => (int) $row['info']];
        }
        return $caps;
    }

    private static function setScore(int $home, int $away): void
    {
        DBQuery("UPDATE uo_game SET homescore=$home, visitorscore=$away, hasstarted=1, isongoing=1 WHERE game_id=701");
        self::flushQueryCaches();
    }

    public function testSetsACapAndRecordsItForTheApp(): void
    {
        $response = self::cap('PUT', 'half_cap', ['time' => 1500, 'point_cap' => 8], 'cap-1');

        $this->assertSame(200, $response['status'], $response['raw']);
        $data = $response['json']['data'];
        $this->assertSame(['type' => 'half_cap', 'time' => 1500, 'point_cap' => 8], $data['cap']);
        $this->assertSame(['half_cap' => ['time' => 1500, 'info' => 8]], self::storedCaps());
        $this->assertSame('"' . $data['token'] . '"', $response['headers']['etag'] ?? null);

        $context = self::entryContext();
        $this->assertSame($data['token'], $context['token']);
        $this->assertSame([['type' => 'half_cap', 'time' => 1500, 'point_cap' => 8]], $context['retained']['cap_events']);

        $rows = DBQueryToArrayUncached("SELECT source, scorekeeper_token, api_token FROM uo_scoresheet_history WHERE game=701 AND target='gameevent'");
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(['api', '920', '910'], [$row['source'], $row['scorekeeper_token'], $row['api_token']]);
        }
    }

    public function testANewCapMustExceedTheScoreButACorrectionNeedNot(): void
    {
        self::setScore(5, 3);

        $this->assertError(self::cap('PUT', 'time_cap', ['time' => 4000, 'point_cap' => 5], 'cap-low'), 409, 'point_cap_too_low');
        $this->assertSame([], self::storedCaps());

        $this->assertSame(200, self::cap('PUT', 'time_cap', ['time' => 4000, 'point_cap' => 6], 'cap-new')['status']);
        $corrected = self::cap('PUT', 'time_cap', ['time' => 4100, 'point_cap' => 4], 'cap-fix');
        $this->assertSame(200, $corrected['status'], $corrected['raw']);
        $this->assertSame(['time_cap' => ['time' => 4100, 'info' => 4]], self::storedCaps());
    }

    public function testTimeIsBoundByTheEventColumn(): void
    {
        // uo_gameevent.time is smallint: the largest time stores, the next is refused before the write.
        $accepted = self::cap('PUT', 'half_cap', ['time' => 32767, 'point_cap' => 196], 'cap-max');
        $this->assertSame(200, $accepted['status'], $accepted['raw']);
        $this->assertSame(32767, $accepted['json']['data']['cap']['time']);

        $refused = self::cap('PUT', 'time_cap', ['time' => 32768, 'point_cap' => 196], 'cap-over');
        $this->assertError($refused, 400, 'invalid_body');
        $this->assertSame('time', $refused['json']['error']['details'][0]['path'] ?? null, $refused['raw']);
        $this->assertStringContainsString('32767', $refused['json']['error']['details'][0]['message'] ?? '', $refused['raw']);
        $this->assertSame(['half_cap' => ['time' => 32767, 'info' => 196]], self::storedCaps());
    }

    public function testACapCanBeSetOnAFinalGame(): void
    {
        DBQuery("UPDATE uo_game SET homescore=9, visitorscore=7, hasstarted=2, isongoing=0 WHERE game_id=701");
        self::flushQueryCaches();

        $response = self::cap('PUT', 'time_cap', ['time' => 5000, 'point_cap' => 10], 'cap-final');
        $this->assertSame(200, $response['status'], $response['raw']);
        $this->assertSame('final', $response['json']['data']['result']['state']);
    }

    public function testDeleteRemovesTheCapAndIsIdempotent(): void
    {
        $this->assertSame(200, self::cap('PUT', 'half_cap', ['time' => 1500, 'point_cap' => 8], 'cap-1')['status']);
        $this->assertSame(200, self::cap('PUT', 'time_cap', ['time' => 4000, 'point_cap' => 9], 'cap-2')['status']);

        $removed = self::cap('DELETE', 'half_cap', null, 'del-1');
        $this->assertSame(200, $removed['status'], $removed['raw']);
        $this->assertNull($removed['json']['data']['cap']);
        $this->assertSame(['time_cap' => ['time' => 4000, 'info' => 9]], self::storedCaps());

        $replay = self::cap('DELETE', 'half_cap', null, 'del-1');
        $this->assertSame(200, $replay['status']);
        $this->assertSame('true', $replay['headers']['idempotent-replay'] ?? null);

        // The cap type is part of the request path, so the key cannot be reused for the other cap.
        $this->assertError(self::cap('DELETE', 'time_cap', null, 'del-1'), 409, 'idempotency_conflict');
        $this->assertSame(['time_cap' => ['time' => 4000, 'info' => 9]], self::storedCaps());

        $absent = self::cap('DELETE', 'half_cap', null, 'del-2');
        $this->assertSame(200, $absent['status'], $absent['raw']);
    }

    public function testRefusedWhileTheEventHidesTimes(): void
    {
        DBQuery("UPDATE uo_season SET hide_time_on_scoresheet=1 WHERE season_id='HRN2026'");
        self::flushQueryCaches();

        $this->assertError(self::cap('PUT', 'half_cap', ['time' => 1500, 'point_cap' => 8], 'cap-hidden'), 409, 'times_hidden');
        $this->assertError(self::cap('DELETE', 'half_cap', null, 'del-hidden'), 409, 'times_hidden');
        $this->assertSame([], self::storedCaps());
    }

    public function testRefusesBadBodiesTypesAndMethods(): void
    {
        foreach ([
            ['time' => 1500, 'point_cap' => 0],
            ['time' => 1500, 'point_cap' => 256],
            ['time' => -1, 'point_cap' => 8],
            ['time' => 1500],
            ['time' => 1500, 'point_cap' => 8, 'target' => 8],
            ['time' => '1500', 'point_cap' => 8],
        ] as $i => $body) {
            $response = self::cap('PUT', 'half_cap', $body, 'bad-' . $i);
            $this->assertSame(400, $response['status'], json_encode($body) . ': ' . $response['raw']);
        }
        $this->assertSame([], self::storedCaps());

        // Not a cap route: the plain games handler refuses it like any unknown write path.
        $unknown = self::cap('PUT', 'soft_cap', ['time' => 1500, 'point_cap' => 8], 'bad-type');
        $this->assertGreaterThanOrEqual(400, $unknown['status']);
        $this->assertLessThan(500, $unknown['status']);
        $this->assertSame(0, self::dbCount("SELECT COUNT(*) FROM uo_gameevent WHERE game=701"));

        $post = self::cap('POST', 'half_cap', ['time' => 1500, 'point_cap' => 8], 'bad-method');
        $this->assertError($post, 405, 'method_not_allowed');
        $this->assertSame('PUT, DELETE', $post['headers']['allow'] ?? null);
    }
}
