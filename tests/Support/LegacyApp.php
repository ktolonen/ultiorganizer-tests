<?php

declare(strict_types=1);

namespace UltiorganizerHarness\Support;

final class LegacyApp
{
    private static bool $bootstrapped = false;
    private static bool $databaseLoaded = false;

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
        self::bootstrapEnvironment();
        require_once 'lib/common.functions.php';
    }

    public static function loadCountryFunctions(): void
    {
        self::openDatabaseConnection();
        require_once 'lib/country.functions.php';
    }

    public static function loadSeasonFunctions(): void
    {
        self::openDatabaseConnection();
        require_once 'lib/season.functions.php';
        require_once 'lib/series.functions.php';
        require_once 'lib/statistical.functions.php';
    }

    public static function loadPoolFunctions(): void
    {
        self::openDatabaseConnection();
        require_once 'lib/pool.functions.php';
        require_once 'lib/series.functions.php';
        require_once 'lib/season.functions.php';
        require_once 'lib/statistical.functions.php';
    }

    public static function loadTeamFunctions(): void
    {
        self::openDatabaseConnection();
        require_once 'lib/team.functions.php';
        require_once 'lib/pool.functions.php';
        require_once 'lib/series.functions.php';
        require_once 'lib/season.functions.php';
        require_once 'lib/statistical.functions.php';
    }

    public static function loadConfigurationFunctions(): void
    {
        self::openDatabaseConnection();
        require_once 'lib/configuration.functions.php';
    }

    public static function loadUserFunctions(): void
    {
        self::openDatabaseConnection();
        require_once 'lib/user.functions.php';
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
