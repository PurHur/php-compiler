<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: isEqualNode / compareDocumentPosition variable-null (#33733).
 *
 * @group llvm
 * @group aot
 */
final class DomLivingNull33733AotTest extends TestCase
{
    public function testIsEqualAndCompareNullMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33733_dom_living_null_aot.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_dom_null_33733_'.getmypid();
        $env = 'PHP_COMPILER_PROFILE=8.4 ';
        $compile = $env.escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $cout, $crc);
        $this->assertSame(0, $crc, "AOT compile failed:\n".implode("\n", $cout));
        $this->assertFileExists($bin);

        try {
            $aot = [];
            exec(escapeshellarg($bin).' 2>&1', $aot, $arc);
            // Host Zend is 8.2 without living DOM APIs; assert AOT against Zend 8.4 table (#33733).
            $this->assertSame(0, $arc, "AOT run rc=$arc out=".implode("\n", $aot));
            $this->assertSame("0\n0\nTE", rtrim(implode("\n", $aot)));
        } finally {
            @unlink($bin);
        }
    }
}
