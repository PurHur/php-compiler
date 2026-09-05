<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Owning `__string__*` FUNCCALL temps must free under thin AOT (#36388).
 *
 * @group llvm
 * @group aot
 */
final class Issue36388StringCallResultFreeTest extends TestCase
{
    public function testStrRepeatLocalUnsetFreesUnderAot(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36388_str_repeat_local_free.php';
        $bin = sys_get_temp_dir().'/phpc_36388_srlf_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -o '
            .escapeshellarg($bin).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        exec($compile.' 2>&1', $out, $rc);
        chdir($cwd);
        $this->assertSame(0, $rc, implode("\n", $out));
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $text = implode("\n", $runOut);
        $this->assertStringContainsString('local d1=', $text);
        $this->assertMatchesRegularExpression('/local d1=\d+ left=0 freed=y/', $text);
        $this->assertMatchesRegularExpression('/concat d1=\d+ left=0 freed=y/', $text);
    }

    public function testTypedStringReturnUnsetFreesUnderAot(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36388_typed_string_return_free.php';
        $bin = sys_get_temp_dir().'/phpc_36388_tsrf_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -o '
            .escapeshellarg($bin).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        exec($compile.' 2>&1', $out, $rc);
        chdir($cwd);
        $this->assertSame(0, $rc, implode("\n", $out));
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $text = implode("\n", $runOut);
        $this->assertStringContainsString('unset_ok', $text);
        $this->assertStringContainsString('floor_ok', $text);
    }

    public function testAssignOperandMovesEphemeralStringCallTemps(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Concern/AssignOperand.php');
        $this->assertStringContainsString('skipAddrefForStringMove', $src);
        $this->assertStringContainsString('moveEphemeralString', $src);
    }

    public function testCallResultPromotesOwningStringToKindVariable(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Concern/CallResultOperandAssign.php'
        );
        $this->assertStringContainsString('callResultOwnsFreshString', $src);
        $this->assertStringContainsString('ephemeralStringTemp = true', $src);
    }
}
