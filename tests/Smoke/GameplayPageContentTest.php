<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Content contract for the public gameplay replay page.
 *
 * PublicPagesSmokeTest only checks that configured pages return 200 without a
 * PHP diagnostic; this file asserts what the replay actually renders. It lives
 * in the Smoke suite because it is the same HTTP-driven, read-only shape.
 *
 * Unlike the in-process PHPUnit suites, an HTTP request runs the full app with
 * gettext bound, so cap labels are translated in the fi_FI config-overrides
 * case. Everything asserted here is therefore locale-independent: the cap
 * targets, the cap times, and the div classing. The English label text itself
 * is pinned by GameFunctionsLibTest and the RSS export contract, both of which
 * only run under the baseline profile.
 */
final class GameplayPageContentTest extends TestCase
{
    // Fixture game 700: home Helsinki Heat (300) 15 - 11 Tampere Tempest (301),
    // hasstarted=1, four goals at 120/300/480/660 (2.00/5.00/8.00/11.00), plus
    // two cap events - half_cap target 9 at t=400 (6.40, between goals 2 and 3)
    // and time_cap target 13 at t=900 (15.00, after the last goal). The cap
    // times are the only 6.40/15.00 stamps on the page.

    public function testGameplayPageRendersBothCapEventsWithTargetAndTime(): void
    {
        $body = self::fetchGameplayBody();

        // Caps render their own time, ahead of the point cap they set, so the
        // 6.40/15.00 stamp precedes the 9/13 target inside the div. Every other
        // event type still gets its time appended by the page after the label.
        //
        // Between-goals branch: the 6.40 stamp, then target 9.
        $this->assertMatchesRegularExpression(
            '/<div>[^<]*6\.40[^<]*\b9\b[^<]*<\/div>/',
            $body,
            'expected the half cap (target 9) to render at 6.40 between goals',
        );
        // After-last-goal branch: the 15.00 stamp, then target 13.
        $this->assertMatchesRegularExpression(
            '/<div>[^<]*15\.00[^<]*\b13\b[^<]*<\/div>/',
            $body,
            'expected the time cap (target 13) to render at 15.00 after the last goal',
        );
    }

    public function testGameplayPageRendersCapEventsWithoutTeamAttribution(): void
    {
        $body = self::fetchGameplayBody();

        // Caps belong to neither team, so their div carries no home/guest class.
        // The contrast that makes this meaningful: the fixture's home turnover at
        // t=500 (8.20) renders through the same loop and does get a team class,
        // so the absence above is the cap rule and not a page that never classes
        // anything.
        $this->assertMatchesRegularExpression("/<div class='home'>[^<]*8\.20/", $body);
        $this->assertDoesNotMatchRegularExpression(
            "/<div class='(home|guest)'>[^<]*(6\.40|15\.00)/",
            $body,
            'cap events must not be attributed to the home or away team',
        );
    }

    private static function fetchGameplayBody(): string
    {
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create([
            'http' => [
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ]);

        $response = file_get_contents($baseUrl . '/index.php?view=gameplay&game=700', false, $context);
        $headers = $http_response_header ?? [];
        $statusLine = $headers[0] ?? '';

        self::assertIsString($response, 'gameplay page request failed: ' . $statusLine);
        self::assertStringContainsString(' 200 ', $statusLine, 'unexpected status: ' . $statusLine);

        return $response;
    }
}
