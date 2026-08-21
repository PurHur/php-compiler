<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: call-arg array literals with false/true must json_encode as bools (#33520 / re-#26367).
 */
final class CallFalsyArrayLiteralAot33520Test extends TestCase
{
    public function testAotMatchesZendForFalsyArrayLiteralSiblingCall(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/differential/cases/z26367_call_falsy_array_literal.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33520_'.getmypid().'.bin';
        $zend = [];
        $zendExit = 0;
        exec('php '.escapeshellarg($src).' 2>&1', $zend, $zendExit);
        self::assertSame(0, $zendExit, 'zend exit');

        $compile = [];
        $compileExit = 0;
        exec(
            'php '.escapeshellarg($root.'/bin/compile.php').' -o '.escapeshellarg($bin)
            .' '.escapeshellarg($src).' 2>&1',
            $compile,
            $compileExit
        );
        self::assertSame(0, $compileExit, "compile failed:\n".implode("\n", $compile));
        self::assertFileExists($bin);

        $aot = [];
        $aotExit = 0;
        exec(escapeshellarg($bin).' 2>&1', $aot, $aotExit);
        @unlink($bin);

        self::assertSame(0, $aotExit, 'aot exit');
        self::assertSame(implode("\n", $zend), implode("\n", $aot));
        self::assertStringContainsString('"k":false', implode("\n", $aot));
        self::assertStringContainsString('"k":true', implode("\n", $aot));
    }
}
