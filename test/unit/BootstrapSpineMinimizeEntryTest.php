<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BootstrapSpineMinimizeEntryTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testMinimizedEntryResolvesRequirePathsFromSourceDir(): void
    {
        $in = self::$root.'/test/selfhost/compiler_lib_spine_smoke/main.php';
        $out = self::$root.'/build/spine-minimize-entry-test.php';
        @unlink($out);

        $cmd = sprintf(
            'php %s --in %s --out %s --limit 3 2>&1',
            escapeshellarg(self::$root.'/script/bootstrap-spine-minimize-entry.php'),
            escapeshellarg($in),
            escapeshellarg($out)
        );
        $output = shell_exec($cmd);
        $this->assertIsString($output);
        $this->assertStringContainsString('wrote', $output);
        $this->assertFileExists($out);

        $generated = (string) file_get_contents($out);
        $vm = realpath(self::$root.'/bin/vm.php');
        $this->assertIsString($vm);
        $this->assertStringContainsString("require_once '{$vm}';", $generated);
        $this->assertStringNotContainsString("__DIR__.'/", $generated);

        @unlink($out);
    }
}
