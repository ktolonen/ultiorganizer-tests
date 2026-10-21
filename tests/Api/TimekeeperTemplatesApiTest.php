<?php

declare(strict_types=1);

use UltiorganizerHarness\Support\ScorekeepingApiTestCase;

/**
 * GET /api/v1/timekeeper/templates: the Timekeeper templates for the
 * scorekeeping app, with signal texts in the Accept-Language locale.
 *
 * Fixture: template 930 with one signal whose text has a Finnish database
 * translation.
 */
final class TimekeeperTemplatesApiTest extends ScorekeepingApiTestCase
{
    private const TEMPLATE = 930;
    private const SIGNAL_TEXT = 'Harness signal';
    private const SIGNAL_TEXT_FI = 'Harness-merkki';

    protected function setUp(): void
    {
        parent::setUp();
        $paths = self::request('GET', '/api/v1/openapi')['json']['paths'] ?? [];
        if (!isset($paths['/api/v1/timekeeper/templates'])) {
            $this->markTestSkipped('The SUT has no timekeeper templates endpoint.');
        }
        self::removeTemplate();
        DBQuery(sprintf(
            "INSERT INTO uo_timekeeper_template (template_id, name, is_default, is_system, half_time_cap, time_cap)
             VALUES (%d, 'Harness template', 0, 0, 40, 80)",
            self::TEMPLATE,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_timekeeper_template_signal (template_id, action_key, signal_time, signal_text)
             VALUES (%d, 'timeout', 70, '%s')",
            self::TEMPLATE,
            self::SIGNAL_TEXT,
        ));
        DBQuery(sprintf(
            "INSERT INTO uo_translation (translation_key, locale, translation) VALUES ('%s', 'fi_FI_utf8', '%s')",
            self::SIGNAL_TEXT,
            self::SIGNAL_TEXT_FI,
        ));
    }

    protected function tearDown(): void
    {
        self::removeTemplate();
        parent::tearDown();
    }

    private static function removeTemplate(): void
    {
        DBQuery(sprintf("DELETE FROM uo_timekeeper_template_signal WHERE template_id=%d", self::TEMPLATE));
        DBQuery(sprintf("DELETE FROM uo_timekeeper_template WHERE template_id=%d", self::TEMPLATE));
        DBQuery(sprintf("DELETE FROM uo_translation WHERE translation_key='%s'", self::SIGNAL_TEXT));
    }

    private static function templates(?string $language = null, string $token = self::TOKEN_INSTALL): array
    {
        $headers = ['Authorization' => 'Bearer ' . $token];
        if ($language !== null) {
            $headers['Accept-Language'] = $language;
        }
        return self::request('GET', '/api/v1/timekeeper/templates', $headers);
    }

    private static function harnessTemplate(array $response): array
    {
        foreach ($response['json']['data']['templates'] ?? [] as $template) {
            if ($template['id'] === self::TEMPLATE) {
                return $template;
            }
        }
        self::fail('Template ' . self::TEMPLATE . ' is missing: ' . $response['raw']);
    }

    public function testListsTemplatesWithCapsAndSignalsPerAction(): void
    {
        $response = self::templates();

        self::assertSame(200, $response['status'], $response['raw']);
        self::assertStringContainsString('Accept-Language', $response['headers']['vary'] ?? '');
        $data = $response['json']['data'];
        self::assertTrue(array_is_list($data['templates']), 'templates is a list');
        // Without a default row the SUT falls back to the first system template, then the lowest id.
        $default = (int) DBQueryToValueUncached("SELECT template_id FROM uo_timekeeper_template ORDER BY is_default DESC, is_system DESC, template_id LIMIT 1");
        self::assertSame($default, $data['default_template_id']);

        $template = self::harnessTemplate($response);
        self::assertSame('Harness template', $template['name']);
        self::assertSame(['half_time_cap' => 40, 'time_cap' => 80], $template['caps']);
        self::assertSame(
            ['betweenpoints', 'timeout', 'timeoutbeforepull', 'halftime', 'halfstart', 'dispute', 'discretrieval'],
            array_keys($template['signals']),
        );
        self::assertSame([], $template['signals']['betweenpoints']);
        self::assertCount(1, $template['signals']['timeout']);
        self::assertSame(70, $template['signals']['timeout'][0]['time']);
        self::assertSame(self::SIGNAL_TEXT, $template['signals']['timeout'][0]['text']);
    }

    public function testTranslatesTextsByAcceptLanguage(): void
    {
        $unmatched = self::templates('sv-SE');
        self::assertSame(200, $unmatched['status'], $unmatched['raw']);
        self::assertSame(self::SIGNAL_TEXT, self::harnessTemplate($unmatched)['signals']['timeout'][0]['text']);

        // The route offers only the locales gettext can serve, as the language
        // selector does; a runtime without an fi_FI system locale serves English.
        $finnish = self::templates('sv-SE, fi;q=0.8, en;q=0.5');
        self::assertSame(200, $finnish['status'], $finnish['raw']);
        if (($finnish['headers']['content-language'] ?? null) !== 'fi-FI') {
            self::assertSame('en-GB', $finnish['headers']['content-language'] ?? null);
            $this->markTestSkipped('The runtime cannot serve fi_FI.');
        }
        self::assertSame(self::SIGNAL_TEXT_FI, self::harnessTemplate($finnish)['signals']['timeout'][0]['text']);
    }

    public function testAnyValidTokenReadsAndOthersAreRefused(): void
    {
        self::assertSame(200, self::templates(null, self::TOKEN_USER)['status']);
        self::assertSame(200, self::templates(null, self::TOKEN_OTHER_EVENT)['status']);
        self::assertError(self::templates(null, self::TOKEN_REVOKED), 401, 'invalid_token');
        self::assertError(self::request('GET', '/api/v1/timekeeper/templates'), 401, 'missing_token');
        self::assertError(self::request('POST', '/api/v1/timekeeper/templates', self::auth(self::TOKEN_INSTALL, null)), 405, 'method_not_allowed');
        self::assertError(self::request('GET', '/api/v1/timekeeper', self::auth(self::TOKEN_INSTALL, null)), 404, 'not_found');
    }
}
