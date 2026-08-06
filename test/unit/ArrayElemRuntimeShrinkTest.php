<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayElemJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_first/array_last JIT routes through ArrayElemJitHelper PHP not JitArrayElem LLVM (#15063). */
final class ArrayElemRuntimeShrinkTest extends TestCase
{
    public function testJitArrayElemDelegatesFirstLastToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitArrayElem.php');
        $this->assertStringContainsString('ArrayElemRuntime::first', $source);
        $this->assertStringContainsString('ArrayElemRuntime::last', $source);
        $this->assertStringNotContainsString('elemAtEnd', $source);
        $this->assertStringContainsString('ExceptionBridge::emitTypeErrorAndAbort', $source);
        $this->assertStringNotContainsString('TryCatchHelper', $source);
        $this->assertStringNotContainsString('TypeErrorRaise::emitRaise', $source);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayElemRuntime.php');
        $this->assertStringContainsString('ArrayElemJitHelper', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
    }

    public function testArrayElemJitHelperMatchesVmArraySemantics(): void
    {
        $ht = new HashTable();
        foreach (['a' => 1, 'b' => 2] as $key => $raw) {
            $var = new Variable();
            $var->int($raw);
            $ht->add($key, $var);
        }

        $first = ArrayElemJitHelper::firstArgv($ht);
        $this->assertSame(1, $first->resolveIndirect()->toInt());

        $last = ArrayElemJitHelper::lastArgv($ht);
        $this->assertSame(2, $last->resolveIndirect()->toInt());
    }

    public function testArrayElemJitHelperReturnsNullForEmptyArray(): void
    {
        $ht = new HashTable();
        $first = ArrayElemJitHelper::firstArgv($ht);
        $this->assertSame(Variable::TYPE_NULL, $first->resolveIndirect()->type);

        $last = ArrayElemJitHelper::lastArgv($ht);
        $this->assertSame(Variable::TYPE_NULL, $last->resolveIndirect()->type);
    }

    public function testSpineBundleIncludesArrayElemJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ArrayElemJitHelper.php', $spine);
        $this->assertStringContainsString('ArrayElemRuntime.php', $spine);
    }

    public function testThinAotRoutesThroughHashTableElemLlvm(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayElemRuntime.php');
        $this->assertStringContainsString('HashTableElemLlvm::valueFirst', $runtime);
        $this->assertStringContainsString('isThinStandaloneAotMain', $runtime);
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableElemLlvm.php');
        $this->assertStringContainsString('#27596', $llvm);
    }
}
