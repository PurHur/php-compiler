<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\HashTableJitHelper;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** (array) cast COW duplicate routes through HashTableJitHelper PHP not ArrayBuiltinHelper LLVM (#18451). */
final class HashTableDuplicateRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 920;

    public function testHashTableJitHelperDuplicateCopyMatchesVmHashTable(): void
    {
        $ht = new HashTable();
        foreach ([0 => 1, 1 => 2] as $index => $raw) {
            $var = new Variable();
            $var->int($raw);
            $ht->addIndex($index, $var);
        }
        $strVar = new Variable();
        $strVar->string('v');
        $ht->add('k', $strVar);

        $copy = HashTableJitHelper::duplicateCopy($ht);
        $this->assertNotSame($ht, $copy);
        $this->assertSame(3, $copy->getNumElements());
        $this->assertSame(1, $copy->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame('v', $copy->find('k')?->resolveIndirect()->toString());
    }

    public function testCastArrayUsesHashTableDuplicateRuntimeNotLlvmMonolith(): void
    {
        $native = (string) file_get_contents(__DIR__.'/../../lib/JIT/CastArrayNativeJit.php');
        $valueBox = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CastArrayValueBoxJit.php');
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/HashTableDuplicateRuntime.php');

        $this->assertStringContainsString('HashTableDuplicateRuntime::duplicate', $native);
        $this->assertStringContainsString('HashTableDuplicateRuntime::duplicate', $valueBox);
        $this->assertStringContainsString('HashTableCowLlvm::duplicate', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::duplicateHashtable', $native);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::duplicateHashtable', $valueBox);
    }

    public function testArrayBuiltinHelperDuplicateCopyLlvmRemoved(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        foreach ([
            'function duplicateHashtable(',
            'function copyInto(',
            'function copyReindexableInto(',
            'mergeStringKeysInto',
            'appendValueEntryToPacked',
            'stringPtrIsNumericString',
            'appendListEntryScalars',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $source, $needle);
        }

        $lines = substr_count($source, "\n") + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after duplicateHashtable LLVM deletion (#18451)'
        );
        $this->assertLessThanOrEqual(
            320,
            $lines,
            'ArrayBuiltinHelper.php should shrink materially after duplicate/copy LLVM deletion (#18451)'
        );
    }
}
