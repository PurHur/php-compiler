<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Source guards for #36388 short-lived array free under thin AOT.
 */
final class Issue36388ArrayDelrefHonestyTest extends TestCase
{
    public function testDelrefDeferUsesExactObjectTypeNotMaskedArrayBit(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/Refcount.php');
        $this->assertStringContainsString(
            '$deferObjectDestroy = $this->context->builder->bitwiseAnd($deferDestroy, $isObjectExact)',
            $src,
            '{main} must not defer __hashtable__dtor via TYPE_MASKED_ARRAY object bit (#36388)'
        );
        $this->assertStringContainsString(
            'TYPE_INFO_TYPE_OBJECT',
            $src
        );
    }

    public function testValueBoxHashtableAssignDoesNotDoubleAddref(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/JitValueBox.php');
        $this->assertStringContainsString(
            'Do NOT addref here — __value__writeHashtable already retains',
            $src,
            'assignToPointer must not addref before writeHashtable (#36388 / re-#36252)'
        );
        $this->assertStringContainsString(
            'Release the temp so the value-box is the sole owner (#36388)',
            $src,
            'script-global assignToPointer must move ephemeral INIT_ARRAY temps (#36388)'
        );
    }

    public function testInitArrayEphemeralMoveFlagPresent(): void
    {
        $var = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Variable.php');
        $ht = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/HashTableWriteLlvm.php');
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('ephemeralArrayTemp', $var);
        $this->assertStringContainsString('ephemeralArrayTemp = true', $ht);
        $this->assertStringContainsString('skipAddrefForHashtableMove', $jit);
    }

    public function testNativePackedValueBoxElementSkipsHeapPromote(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/HashTableWriteLlvm.php');
        $this->assertStringContainsString(
            'Prefer storing via `__value__read*` into the native slot',
            $src,
            'TYPE_VALUE into int[] must not heap-promote (#36388 packed leak)'
        );
        $this->assertStringContainsString(
            'delref → sole owner (#36388)',
            $src,
            'promoteNativeArrayVariableToHashtable must balance writeHashtable retain (#36388)'
        );
        $usort = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/UsortRuntime.php');
        $this->assertStringContainsString(
            'Do not storeHashtableInArrayVariable back into a',
            $usort,
            'in-place usort must not writeHashtable the same HT (#36388 / re-#36484)'
        );
    }

    /**
     * Functional: packed `$a = [$i]; unset($a)` must not grow usage (#36388).
     *
     * @group llvm
     * @group aot
     */
    public function testPackedShortLivedArrayDeltaZeroUnderAot(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36388_packed_array_leak.php';
        $bin = sys_get_temp_dir().'/phpc_36388_packed_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -o '
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
        $line = $runOut[0] ?? '';
        $this->assertMatchesRegularExpression('/delta=0\b/', $line, $line);
    }
}
