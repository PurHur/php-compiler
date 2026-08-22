<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT mktime/gmmktime bridge blocks must append on the ABI fn (#33934).
 *
 * @group llvm
 * @group aot
 */
final class Issue33934MktimeAotTest extends TestCase
{
    public function testStringMktimeAppendsBridgeBlocksOnAbiFn(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/StringMktime.php'
        );
        $this->assertStringContainsString('#33934', $source);
        $this->assertStringContainsString("\$fn->appendBasicBlock('mkt_false')", $source);
        $this->assertStringContainsString("\$fn->appendBasicBlock('mkt_ok')", $source);
        $this->assertStringContainsString("\$fn->appendBasicBlock('mkt_done')", $source);
        $this->assertStringNotContainsString("BasicBlockHelper::append(\$context, 'mkt_false')", $source);
    }

    public function testStringGmmktimeAppendsBridgeBlocksOnAbiFn(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/StringGmmktime.php'
        );
        $this->assertStringContainsString('#33934', $source);
        $this->assertStringContainsString("\$fn->appendBasicBlock('gmt_false')", $source);
        $this->assertStringNotContainsString("BasicBlockHelper::append(\$context, 'gmt_false')", $source);
    }

    public function testJitMktimeUsesCivilRuntimePath(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitMktime.php'
        );
        $this->assertStringContainsString('#33934', $source);
        $this->assertStringContainsString('writeLocalCivilTimestamp', $source);
        $this->assertStringContainsString('timestampFromCivilPublic', $source);
    }

    public function testAotMktimeMatchesZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33934_mktime_aot.php';
        $bin = sys_get_temp_dir().'/phpc_mktime_33934_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));

        $zendOut = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $expected = implode("\n", $zendOut)."\n";

        try {
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($expected, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
