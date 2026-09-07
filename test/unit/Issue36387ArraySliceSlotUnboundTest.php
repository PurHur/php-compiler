<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #37207 extract left `$arraySliceSlot` unbound in CompileCallArgSends — PHP Warning
 * then wrong nested-arg probe gating. AOT of md5/str_replace must not warn (#36387).
 *
 * @group llvm
 * @group aot
 */
final class Issue36387ArraySliceSlotUnboundTest extends TestCase
{
    public function testCompileCallArgSendsThreadsArraySliceSlotOutParam(): void
    {
        $hub = (string) file_get_contents(dirname(__DIR__, 2).'/lib/Compiler/Concern/CompileCallArgSends.php');
        $concern = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/Compiler/Concern/CallArgAssignProcOpenTernaryHaystackSliceChainedMergeIifeValueSlots.php'
        );
        $this->assertStringContainsString('$arraySliceSlot = null;', $hub);
        $this->assertStringContainsString('$outerMultiArraySetOpArgWired,', $hub);
        $this->assertStringContainsString('$arraySliceSlot', $hub);
        $this->assertStringContainsString('&$arraySliceSlot', $concern);
        $this->assertStringContainsString('@param-out mixed $arraySliceSlot', $concern);
    }

    public function testSimpleBuiltinAotCompileEmitsNoArraySliceSlotWarning(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36387_array_slice_slot_no_warn.php';
        $bin = sys_get_temp_dir().'/phpc_36387_ass_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -o '
            .escapeshellarg($bin).' '
            .escapeshellarg($src).' 2>&1';
        $cwd = getcwd();
        chdir($root);
        exec($compile, $out, $rc);
        chdir($cwd);
        $text = implode("\n", $out);
        $this->assertSame(0, $rc, $text);
        $this->assertStringNotContainsString('Undefined variable $arraySliceSlot', $text);
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $this->assertStringContainsString('ok', implode("\n", $runOut));
    }
}
