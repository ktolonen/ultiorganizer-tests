<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use UltiorganizerHarness\Support\LegacyApp;

final class PluginFunctionsLibTest extends TestCase
{
    private string $pluginRoot;

    protected function setUp(): void
    {
        LegacyApp::resetRequestState();
        LegacyApp::loadLibFileUsingProfile('plugin.functions.php', 'bootstrap_only');

        $this->pluginRoot = sys_get_temp_dir() . '/uo-plugin-test-' . bin2hex(random_bytes(4));
        mkdir($this->pluginRoot . '/plugins', 0777, true);

        $this->writePlugin(
            'scoreboard.php',
            "category = media\ntype = export\nformat = html\ntitle = Scoreboard\ndescription = Public scoreboard\n"
        );
        $this->writePlugin(
            'results.php',
            "category = media\ntype = export\nformat = csv\ntitle = Results\ndescription = CSV export\n"
        );
        $this->writePlugin(
            'broken.php',
            "category = media\ntitle = Missing fields\n"
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->pluginRoot . '/plugins/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->pluginRoot . '/plugins');
        @rmdir($this->pluginRoot);
    }

    public function testGetPluginListFiltersAndSortsReadablePluginMetadata(): void
    {
        global $include_prefix;

        $include_prefix = $this->pluginRoot . '/';

        $plugins = GetPluginList('media', 'export', 'html');

        $this->assertCount(1, $plugins);
        $this->assertSame($this->pluginRoot . '/plugins/scoreboard', $plugins[0]['file']);
        $this->assertSame('Scoreboard', $plugins[0]['title']);
        $this->assertSame('Public scoreboard', $plugins[0]['description']);
    }

    public function testGetPluginListReturnsAlphabeticalMatchesAcrossFiles(): void
    {
        global $include_prefix;

        $this->writePlugin(
            'alpha.php',
            "category = media\ntype = export\nformat = html\ntitle = Alpha\ndescription = First\n"
        );
        $include_prefix = $this->pluginRoot . '/';

        $plugins = GetPluginList('media', 'export', 'html');

        $this->assertSame(
            [
                $this->pluginRoot . '/plugins/alpha',
                $this->pluginRoot . '/plugins/scoreboard',
            ],
            array_column($plugins, 'file')
        );
    }

    private function writePlugin(string $filename, string $iniBlock): void
    {
        file_put_contents(
            $this->pluginRoot . '/plugins/' . $filename,
            "<?php\n<!--\n" . $iniBlock . "-->\n"
        );
    }
}
