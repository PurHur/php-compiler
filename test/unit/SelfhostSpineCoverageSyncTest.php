<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * M2 spine inventory coverage drift guard (issues #1945, #1922).
 */
final class SelfhostSpineCoverageSyncTest extends TestCase
{
    public function testCheckerFailsWhenSpineMissingInventoryFile(): void
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir().'/spine-coverage-'.getmypid();
        mkdir($tmp, 0775, true);
        $spine = $tmp.'/main.php';
        file_put_contents($spine, "<?php\nrequire_once __DIR__.'/../../../lib/Block.php';\n");
        $inventoryList = $tmp.'/inventory.txt';
        file_put_contents($inventoryList, "lib/Block.php\nlib/Compiler.php\n");

        $cmd = 'PHP_COMPILER_SPINE_COVERAGE_TEST_SPINE='.escapeshellarg($spine)
            .' PHP_COMPILER_SPINE_COVERAGE_TEST_INVENTORY_FILE='.escapeshellarg($inventoryList)
            .' '.escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg($root.'/script/check-selfhost-spine-coverage-sync.php')
            .' 2>&1';
        exec($cmd, $out, $code);

        @unlink($spine);
        @unlink($inventoryList);
        @rmdir($tmp);

        $this->assertSame(1, $code, implode("\n", $out));
        $joined = implode("\n", $out);
        $this->assertStringContainsString('missing from spine', $joined);
        $this->assertStringContainsString('lib/Compiler.php', $joined);
    }

    public function testCoverageSyncReportsDeferredInventoryGapOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg($root.'/script/check-selfhost-spine-coverage-sync.php')
            .' 2>&1';
        exec($cmd, $out, $code);
        $joined = implode("\n", $out);
        $this->assertSame(1, $code, $joined);
        $this->assertStringContainsString('missing from spine', $joined);
        $this->assertStringContainsString('bin/vm.php', $joined);
    }
}
