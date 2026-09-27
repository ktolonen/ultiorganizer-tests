<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Request parameters echoed back into login-gated pages.
 *
 * The pages below copy a raw request parameter (an event id, pool id, game
 * id, grouping name, ...) into a form action, a link or the <title>, so a
 * crafted link runs script in the session of the logged-in admin who opens
 * it. admin/seasonadmin.php, which takes the same event parameter, escapes
 * it. Logged in as the fixture superadmin; only the injected marker is
 * asserted, so the test is locale-independent.
 */
final class ReflectedParameterEscapingLoggedInTest extends TestCase
{
    private const PAYLOAD = "x'\"><x-harness-reflected>";

    private static ?string $cookie = null;

    /** @return array<string, array{0: string, 1: array<string, string>}> */
    public static function requests(): array
    {
        return [
            // Contrast: the same event parameter, escaped.
            'admin/seasonadmin season (escaped)' => ['admin/seasonadmin', ['season' => self::PAYLOAD]],
            'user/addextraemail user' => ['user/addextraemail', ['user' => self::PAYLOAD]],
            'user/respgames group' => ['user/respgames', ['season' => 'HRN2026', 'series' => '100', 'group' => self::PAYLOAD]],
            'user/respgames hidden' => ['user/respgames', ['season' => 'HRN2026', 'series' => '100', 'hidden' => self::PAYLOAD]],
            'user/respgames season' => ['user/respgames', ['series' => '100', 'season' => self::PAYLOAD]],
            'user/respgames series' => ['user/respgames', ['season' => 'HRN2026', 'series' => self::PAYLOAD]],
            'user/teamplayers game' => ['user/teamplayers', ['team' => '300', 'game' => self::PAYLOAD]],
            'admin/accreditation season' => ['admin/accreditation', ['season' => self::PAYLOAD]],
            'admin/addlocations season' => ['admin/addlocations', ['location' => '400', 'season' => self::PAYLOAD]],
            'admin/addreservation reservation' => ['admin/addreservation', ['season' => 'HRN2026', 'reservation' => self::PAYLOAD]],
            'admin/addreservation season' => ['admin/addreservation', ['reservation' => '500', 'season' => self::PAYLOAD]],
            'admin/addseasonlinks season' => ['admin/addseasonlinks', ['season' => self::PAYLOAD]],
            'admin/addseasons season' => ['admin/addseasons', ['season' => self::PAYLOAD]],
            'admin/addseasonseries season' => ['admin/addseasonseries', ['series' => '100', 'season' => self::PAYLOAD]],
            'admin/addseasonusers season' => ['admin/addseasonusers', ['season' => self::PAYLOAD]],
            'admin/dbequalize filter' => ['admin/dbequalize', ['filter' => self::PAYLOAD]],
            'admin/editgame game' => ['admin/editgame', ['season' => 'HRN2026', 'game' => self::PAYLOAD]],
            'admin/editgame season' => ['admin/editgame', ['game' => '700', 'season' => self::PAYLOAD]],
            'admin/editstanding pool' => ['admin/editstanding', ['season' => 'HRN2026', 'team' => '300', 'pool' => self::PAYLOAD]],
            'admin/editstanding season' => ['admin/editstanding', ['pool' => '200', 'team' => '300', 'season' => self::PAYLOAD]],
            'admin/editstanding team' => ['admin/editstanding', ['pool' => '200', 'season' => 'HRN2026', 'team' => self::PAYLOAD]],
            'admin/locations season' => ['admin/locations', ['season' => self::PAYLOAD]],
            'admin/poolgames pool' => ['admin/poolgames', ['season' => 'HRN2026', 'pool' => self::PAYLOAD]],
            'admin/poolgames season' => ['admin/poolgames', ['pool' => '200', 'season' => self::PAYLOAD]],
            'admin/poolmoves season' => ['admin/poolmoves', ['pool' => '200', 'series' => '100', 'season' => self::PAYLOAD]],
            'admin/seasongames group' => ['admin/seasongames', ['pool' => '200', 'season' => 'HRN2026', 'group' => self::PAYLOAD]],
            'admin/seasongames pool' => ['admin/seasongames', ['season' => 'HRN2026', 'pool' => self::PAYLOAD]],
            'admin/seasonmoves order' => ['admin/seasonmoves', ['season' => 'HRN2026', 'series' => '100', 'order' => self::PAYLOAD]],
            'admin/seasonmoves season' => ['admin/seasonmoves', ['series' => '100', 'season' => self::PAYLOAD]],
            'admin/seasonmoves series' => ['admin/seasonmoves', ['season' => 'HRN2026', 'series' => self::PAYLOAD]],
            'admin/seasonseries season' => ['admin/seasonseries', ['season' => self::PAYLOAD]],
            'admin/seriesgames season' => ['admin/seriesgames', ['series' => '100', 'season' => self::PAYLOAD]],
            'admin/serieteams season' => ['admin/serieteams', ['pool' => '200', 'series' => '100', 'season' => self::PAYLOAD]],
            'admin/stats season' => ['admin/stats', ['season' => self::PAYLOAD]],
        ];
    }

    #[DataProvider('requests')]
    public function testParameterIsNotReflectedAsMarkup(string $view, array $params): void
    {
        [$status, $body] = self::request(
            '/index.php?' . http_build_query(['view' => $view] + $params),
            'GET',
            ['Cookie: ' . self::sessionCookie()],
        );

        // A fix may render the page or refuse the malformed id; either way no server error.
        $this->assertMatchesRegularExpression('/ [1-4]\d\d /', $status);
        $this->assertStringNotContainsString('<x-harness-reflected>', $body);
    }

    private static function sessionCookie(): string
    {
        if (self::$cookie !== null) {
            return self::$cookie;
        }
        [$status, , $headers] = self::request(
            '/index.php?view=frontpage',
            'POST',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['myusername' => 'admin', 'mypassword' => 'harness-admin']),
        );
        $cookies = [];
        foreach ($headers as $header) {
            if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $m)) {
                $cookies[$m[1]] = $m[1] . '=' . $m[2];
            }
        }
        self::assertNotEmpty($cookies, 'login set no cookie: ' . $status);
        self::$cookie = implode('; ', $cookies);
        return self::$cookie;
    }

    /** @return array{0: string, 1: string, 2: array<int, string>} */
    private static function request(string $path, string $method, array $headers, string $content = ''): array
    {
        $baseUrl = getenv('UO_BASE_URL') ?: 'http://127.0.0.1';
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $content,
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 20,
            ],
        ]);
        $body = file_get_contents($baseUrl . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        return [$responseHeaders[0] ?? '', (string) $body, $responseHeaders];
    }
}
