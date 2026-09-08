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
            .escapeshellarg($root.'/bin/compile.php').' --no-cache -o '
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
            .escapeshellarg($root.'/bin/compile.php').' --no-cache -o '
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
        $this->assertStringContainsString("'sprintf' => true", $src);
        $this->assertStringContainsString("'vsprintf' => true", $src);
        $this->assertStringContainsString("'substr' => true", $src);
        $this->assertStringContainsString("'trim' => true", $src);
        $this->assertStringContainsString("'strtoupper' => true", $src);
        $this->assertStringContainsString("'strrev' => true", $src);
        $this->assertStringContainsString("'str_rot13' => true", $src);
        $this->assertStringContainsString("'ucfirst' => true", $src);
        $this->assertStringContainsString("'basename' => true", $src);
        $this->assertStringContainsString("'dirname' => true", $src);
        $this->assertStringContainsString("'number_format' => true", $src);
        $this->assertStringContainsString("'str_replace' => true", $src);
        $this->assertStringContainsString("'str_ireplace' => true", $src);
        $this->assertStringContainsString("'str_pad' => true", $src);
        $this->assertStringContainsString("'md5' => true", $src);
        $concat = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Concern/CompileConcat.php'
        );
        $this->assertStringContainsString('tryDeadInPlaceConcatAppend', $concat);
        $helper = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Concern/CompileObjectPropertyConcatPowAndFlatten.php'
        );
        $this->assertStringContainsString('tryDeadInPlaceConcatAppend', $helper);
        $fold = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitStrReplace.php'
        );
        $this->assertStringContainsString('tryFoldLiteralReplace', $fold);
        $this->assertStringContainsString('releaseEphemeralArgAfterCopy', (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/JitStringBuiltinArg.php'
        ));
        $this->assertStringContainsString('releaseEphemeralArgAfterCopy', (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/strrev.php'
        ));
        $this->assertStringContainsString('reverseBytesInPlace', (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/strrev.php'
        ));
        $this->assertStringContainsString('transformRot13InPlace', (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/str_rot13.php'
        ));
        $this->assertStringContainsString('releaseEphemeralArgAfterCopy', (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/ucfirst.php'
        ));
        $this->assertStringContainsString('releaseEphemeralArgAfterCopy', (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/basename.php'
        ));
        $this->assertStringContainsString('releaseEphemeralArgAfterCopy', (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/dirname.php'
        ));
        $this->assertStringContainsString('releaseEphemeralArgAfterCopy', (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/str_replace.php'
        ));
        $helper = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/StrReplaceJitHelper.php'
        );
        $this->assertStringContainsString('Always allocate', $helper);
        $this->assertStringContainsString('releaseEphemeralArgAfterCopy', (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitNumberFormat.php'
        ));
        $this->assertStringContainsString('phpc_str_pad_r1', (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/StrPadRuntime.php'
        ));
        $this->assertStringContainsString('StringStrPad::invoke', (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/str_pad.php'
        ));
        $this->assertStringContainsString('phpc_md5_r1', (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/Md5Runtime.php'
        ));
        $this->assertStringContainsString('StringMd5::invoke', (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitMd5.php'
        ));
        $this->assertStringContainsString('phpc_sha1_r1', (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/Sha1Runtime.php'
        ));
        $this->assertStringContainsString('StringSha1::invoke', (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitSha1.php'
        ));
    }

    public function testSprintfCompileTimeFormatUsesModuleCStringNotHeapInit(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/JitSprintf.php');
        $this->assertStringContainsString('pointerFromStringConstant($fmtForSnprintf)', $src);
        $this->assertStringContainsString('toDelref', $src);
        $this->assertStringContainsString('refcount->delref($tmpStr)', $src);
    }

    public function testSprintfLocalUnsetFreesUnderAot(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36388_sprintf_local_free.php';
        $bin = sys_get_temp_dir().'/phpc_36388_splf_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' --no-cache -o '
            .escapeshellarg($bin).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        exec($compile.' 2>&1', $out, $rc);
        chdir($cwd);
        $this->assertSame(0, $rc, implode("\n", $out));
        exec(escapeshellarg($bin).' 2000 2>&1', $runOut, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $text = implode("\n", $runOut);
        $this->assertStringContainsString('sprintf_local delta=', $text);
        $this->assertStringNotContainsString('LEAK', $text);
        $this->assertStringContainsString(' ok', $text);
    }

    public function testSprintfKeyUnsetFreesUnderAot(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36388_sprintf_key_leak.php';
        $bin = sys_get_temp_dir().'/phpc_36388_spkl_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' --no-cache -o '
            .escapeshellarg($bin).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        exec($compile.' 2>&1', $out, $rc);
        chdir($cwd);
        $this->assertSame(0, $rc, implode("\n", $out));
        exec(escapeshellarg($bin).' 2000 2>&1', $runOut, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $text = implode("\n", $runOut);
        $this->assertStringContainsString('sprintf_key delta=', $text);
        $this->assertStringNotContainsString('LEAK', $text);
        $this->assertStringContainsString(' ok', $text);
    }

    /**
     * @dataProvider providingStringBuiltinLocalFreeRepros
     */
    public function testStringBuiltinLocalUnsetFreesUnderAot(string $reproRelative, string $needle): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/'.$reproRelative;
        $bin = sys_get_temp_dir().'/phpc_36388_sbf_'.getmypid().'_'.md5($reproRelative);
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' --no-cache -o '
            .escapeshellarg($bin).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        exec($compile.' 2>&1', $out, $rc);
        chdir($cwd);
        $this->assertSame(0, $rc, implode("\n", $out));
        exec(escapeshellarg($bin).' 2000 2>&1', $runOut, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $text = implode("\n", $runOut);
        $this->assertStringContainsString($needle, $text);
        $this->assertStringNotContainsString('LEAK', $text);
        $this->assertStringContainsString(' ok', $text);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function providingStringBuiltinLocalFreeRepros(): array
    {
        return [
            'substr' => ['test/repro/issue_36388_substr_local_free.php', 'substr_local delta='],
            'trim' => ['test/repro/issue_36388_trim_local_free.php', 'trim_local delta='],
            'strtoupper' => ['test/repro/issue_36388_strtoupper_local_free.php', 'strtoupper_local delta='],
            'substr_lit' => ['test/repro/issue_36388_substr_lit_free.php', 'substr_lit delta='],
            'trim_lit' => ['test/repro/issue_36388_trim_lit_free.php', 'trim_lit delta='],
            'strtoupper_lit' => ['test/repro/issue_36388_strtoupper_lit_free.php', 'strtoupper_lit delta='],
            'strrev' => ['test/repro/issue_36388_strrev_local_free.php', 'strrev_local delta='],
            'strrev_lit' => ['test/repro/issue_36388_strrev_lit_free.php', 'strrev_lit delta='],
            'str_rot13' => ['test/repro/issue_36388_str_rot13_local_free.php', 'str_rot13_local delta='],
            'str_rot13_lit' => ['test/repro/issue_36388_str_rot13_lit_free.php', 'str_rot13_lit delta='],
            'ucfirst' => ['test/repro/issue_36388_ucfirst_local_free.php', 'ucfirst_local delta='],
            'ucfirst_lit' => ['test/repro/issue_36388_ucfirst_lit_free.php', 'ucfirst_lit delta='],
            'lcfirst' => ['test/repro/issue_36388_lcfirst_local_free.php', 'lcfirst_local delta='],
            'basename' => ['test/repro/issue_36388_basename_local_free.php', 'basename_local delta='],
            'basename_lit' => ['test/repro/issue_36388_basename_lit_free.php', 'basename_lit delta='],
            'number_format' => ['test/repro/issue_36388_number_format_local_free.php', 'number_format_local delta='],
            'number_format_lit' => ['test/repro/issue_36388_number_format_lit_free.php', 'number_format_lit delta='],
            'str_replace_hit' => ['test/repro/issue_36388_str_replace_hit_free.php', 'str_replace_hit delta='],
            'str_replace_miss' => ['test/repro/issue_36388_str_replace_miss_free.php', 'str_replace_miss delta='],
            'str_replace_local' => ['test/repro/issue_36388_str_replace_local_free.php', 'str_replace_local delta='],
            'str_pad_local' => ['test/repro/issue_36388_str_pad_local_free.php', 'str_pad_local delta='],
            'str_pad_lit' => ['test/repro/issue_36388_str_pad_lit_free.php', 'str_pad_lit delta='],
            'str_pad_sides' => ['test/repro/issue_36388_str_pad_sides_free.php', 'str_pad_sides delta='],
            'dead_inplace_concat' => ['test/repro/issue_36388_dead_inplace_concat_free.php', 'dead_inplace_concat delta='],
        ];
    }
}
