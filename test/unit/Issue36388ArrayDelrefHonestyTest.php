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

    public function testStringKeyInsertOwnsHeapCopyNotImmortalShare(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString(
            'Own a heap copy of the key. Sharing immortal/static literals via addref',
            $src,
            'strkey insert must separate immortal keys before HT dtor (#36388)'
        );
        $this->assertSame(
            0,
            substr_count($src, 'Share key via addref instead of deep-copying (#36468)'),
            'must not share immortal keys via addref-only (#36388 / re-#36468)'
        );
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

    /**
     * Functional: assoc `$a = ['x' => $i]; unset($a)` must not SIGSEGV or grow (#36388).
     *
     * Immortal string keys shared via addref were freed in __hashtable__dtor (re-#36468).
     *
     * @group llvm
     * @group aot
     */
    public function testAssocShortLivedArrayDeltaZeroUnderAot(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36388_short_lived_array_leak.php';
        $bin = sys_get_temp_dir().'/phpc_36388_assoc_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php').' -o '
            .escapeshellarg($bin).' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        exec($compile.' 2>&1', $out, $rc);
        chdir($cwd);
        $this->assertSame(0, $rc, implode("\n", $out));
        $sig = 0;
        $ok = 0;
        for ($i = 0; $i < 15; ++$i) {
            $runOut = [];
            $runRc = 0;
            exec(escapeshellarg($bin).' 20 2>&1', $runOut, $runRc);
            if (0 === $runRc) {
                ++$ok;
                $this->assertMatchesRegularExpression('/delta=0\b/', $runOut[0] ?? '', $runOut[0] ?? '');
            } else {
                ++$sig;
            }
        }
        @unlink($bin);
        $this->assertSame(0, $sig, "assoc short-lived unset SIGSEGV rate {$sig}/15 (#36388)");
        $this->assertSame(15, $ok);
    }
}
