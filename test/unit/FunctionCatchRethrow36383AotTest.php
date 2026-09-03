<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: throw from a function catch must not silently return 0 (#36383).
 *
 * @group llvm
 */
final class FunctionCatchRethrow36383AotTest extends TestCase
{
    public function testFunctionCatchRethrowExits255(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/fn_catch_rethrow_36383.php';
        $this->assertFileExists($src);
        $bin = sys_get_temp_dir().'/phpc_e04_'.getmypid();
        @unlink($bin);
        $compile = 'cd '.escapeshellarg($root).' && php bin/compile.php -o '.escapeshellarg($bin)
            .' '.escapeshellarg($src).' 2>&1';
        exec($compile, $clog, $crc);
        $this->assertSame(0, $crc, implode("\n", $clog));
        $this->assertFileExists($bin);
        $lines = [];
        exec(escapeshellarg($bin).' 2>/dev/null', $lines, $rc);
        @unlink($bin);
        $this->assertSame(255, $rc);
    }
}
