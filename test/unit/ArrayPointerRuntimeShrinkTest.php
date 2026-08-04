<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayPointerJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array pointer JIT/AOT uses call-site {@see \PHPCompiler\JIT\HashTablePointerLlvm}
 * (peer ArrayPop / #27484); VM SSOT stays ArrayPointerJitHelper / HashTable.
 */
final class ArrayPointerRuntimeShrinkTest extends TestCase
{
    private const JIT_ARRAY_POINTER_MAX_LINES = 80;

    public function testJitArrayPointerDelegatesToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitArrayPointer.php');
        $this->assertStringContainsString('ArrayPointerRuntime::key', $source);
        $this->assertStringContainsString('ArrayPointerRuntime::next', $source);
        $this->assertStringNotContainsString('emitPackedNext', $source);
        $this->assertStringNotContainsString('emitStringKey', $source);
        $this->assertStringNotContainsString('branchPackedVsString', $source);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayPointerRuntime.php');
        $this->assertStringContainsString('HashTablePointerLlvm', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);

        $loc = substr_count($source, "\n") + 1;
        $this->assertLessThan(
            self::JIT_ARRAY_POINTER_MAX_LINES,
            $loc,
            'JitArrayPointer.php must stay a thin delegate'
        );
    }

    public function testArrayPointerJitHelperMatchesHashTableSemantics(): void
    {
        $ht = new HashTable();
        foreach (['a' => 1, 'b' => 2, 'c' => 3] as $key => $raw) {
            $var = new Variable();
            $var->int($raw);
            $ht->add($key, $var);
        }

        $key = ArrayPointerJitHelper::keyArgv($ht);
        $this->assertSame('a', $key->resolveIndirect()->toString());

        $current = ArrayPointerJitHelper::currentArgv($ht);
        $this->assertSame(1, $current->resolveIndirect()->toInt());

        $next = ArrayPointerJitHelper::nextArgv($ht);
        $this->assertSame(2, $next->resolveIndirect()->toInt());

        $end = ArrayPointerJitHelper::endArgv($ht);
        $this->assertSame(3, $end->resolveIndirect()->toInt());

        $reset = ArrayPointerJitHelper::resetArgv($ht);
        $this->assertSame(1, $reset->resolveIndirect()->toInt());

        $prev = ArrayPointerJitHelper::prevArgv($ht);
        $this->assertFalse($prev->resolveIndirect()->toBool());
    }

    public function testArrayPointerJitHelperEmptyArrayReturnsNullKeyAndFalseCurrent(): void
    {
        $ht = new HashTable();

        $key = ArrayPointerJitHelper::keyArgv($ht);
        $this->assertSame(Variable::TYPE_NULL, $key->resolveIndirect()->type);

        $current = ArrayPointerJitHelper::currentArgv($ht);
        $this->assertFalse($current->resolveIndirect()->toBool());
    }
}
