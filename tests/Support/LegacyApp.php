<?php

declare(strict_types=1);

namespace UltiorganizerHarness\Support;

final class LegacyApp
{
    private static bool $bootstrapped = false;
    private static bool $databaseLoaded = false;
    private const LIB_LOAD_PROFILES = [
        'bootstrap_only' => [
            'database' => false,
            'dependencies' => [],
        ],
        'common_only' => [
            'database' => false,
            'dependencies' => ['common.functions.php'],
        ],
        'database_only' => [
            'database' => true,
            'dependencies' => [],
        ],
        'database_with_common' => [
            'database' => true,
            'dependencies' => ['common.functions.php'],
        ],
        'season_stack' => [
            'database' => true,
            'dependencies' => ['season.functions.php', 'series.functions.php', 'statistical.functions.php'],
        ],
        'pool_stack' => [
            'database' => true,
            'dependencies' => ['pool.functions.php', 'series.functions.php', 'season.functions.php', 'statistical.functions.php'],
        ],
        'team_stack' => [
            'database' => true,
            'dependencies' => [
                'team.functions.php',
                'pool.functions.php',
                'series.functions.php',
                'season.functions.php',
                'statistical.functions.php',
            ],
        ],
    ];

    public static function bootstrapEnvironment(): void
    {
        if (self::$bootstrapped) {
            return;
        }

        $sutRoot = self::sutRoot();
        if (!is_dir($sutRoot)) {
            throw new \RuntimeException('Missing SUT root: ' . $sutRoot);
        }

        chdir($sutRoot);

        $_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? '127.0.0.1';
        $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/index.php';
        $_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $_SERVER['QUERY_STRING'] = $_SERVER['QUERY_STRING'] ?? '';

        $_GET = $_GET ?? [];
        $_POST = $_POST ?? [];
        $_COOKIE = $_COOKIE ?? [];
        $_REQUEST = $_REQUEST ?? [];

        self::$bootstrapped = true;
    }

    public static function loadCommonFunctions(): void
    {
        self::loadLibFileUsingProfile('common.functions.php', 'bootstrap_only');
    }

    public static function loadCountryFunctions(): void
    {
        self::loadLibFileUsingProfile('country.functions.php', 'database_only');
    }

    public static function loadSeasonFunctions(): void
    {
        self::loadLibFilesUsingProfile([], 'season_stack');
    }

    public static function loadPoolFunctions(): void
    {
        self::loadLibFilesUsingProfile([], 'pool_stack');
    }

    public static function loadTeamFunctions(): void
    {
        self::loadLibFilesUsingProfile([], 'team_stack');
    }

    public static function loadConfigurationFunctions(): void
    {
        self::loadLibFileUsingProfile('configuration.functions.php', 'database_only');
    }

    public static function loadUserFunctions(): void
    {
        self::loadLibFileUsingProfile('user.functions.php', 'database_only');
    }

    public static function loadLibFileUsingProfile(string $libFile, string $profile = 'bootstrap_only'): void
    {
        self::loadLibFilesUsingProfile([$libFile], $profile);
    }

    public static function loadLibFilesUsingProfile(array $libFiles, string $profile = 'bootstrap_only'): void
    {
        self::bootstrapEnvironment();
        $config = self::LIB_LOAD_PROFILES[$profile] ?? null;
        if ($config === null) {
            throw new \InvalidArgumentException('Unknown lib load profile: ' . $profile);
        }

        if (!empty($config['database'])) {
            self::openDatabaseConnection();
        }

        $queue = array_merge($config['dependencies'], $libFiles);
        foreach ($queue as $libFile) {
            self::requireTopLevelLib($libFile);
        }
    }

    public static function requireTopLevelLib(string $libFile): void
    {
        self::bootstrapEnvironment();
        $normalized = trim($libFile, '/');
        if (str_contains($normalized, '/')) {
            throw new \InvalidArgumentException('Expected top-level lib filename, got: ' . $libFile);
        }
        require_once 'lib/' . $normalized;
    }

    public static function openDatabaseConnection(): void
    {
        self::bootstrapEnvironment();
        if (self::$databaseLoaded) {
            return;
        }

        require_once 'lib/database.php';
        require_once 'lib/session.functions.php';

        if (\session_status() !== PHP_SESSION_ACTIVE) {
            \startSecureSession();
        }

        \OpenConnection();
        self::$databaseLoaded = true;
    }

    public static function loginAsAdmin(): void
    {
        self::loadUserFunctions();
        \SetUserSessionData('admin');
    }

    public static function resetRequestState(): void
    {
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_REQUEST = [];
        $_SERVER['QUERY_STRING'] = '';
        $_SERVER['REQUEST_URI'] = '/index.php';
        unset($_SERVER['HTTP_REFERER']);

        if (\session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        }
    }

    public static function closeDatabaseConnection(): void
    {
        if (self::$databaseLoaded && \function_exists('CloseConnection')) {
            \CloseConnection();
        }
        self::$databaseLoaded = false;
    }

    public static function sutRoot(): string
    {
        $sutRoot = getenv('UO_SUT_ROOT');
        if (!$sutRoot) {
            throw new \RuntimeException('UO_SUT_ROOT is not set');
        }
        return rtrim($sutRoot, DIRECTORY_SEPARATOR);
    }

    public static function baseUrl(): string
    {
        $baseUrl = getenv('UO_BASE_URL');
        if (!$baseUrl) {
            throw new \RuntimeException('UO_BASE_URL is not set');
        }
        return rtrim($baseUrl, '/');
    }
}
