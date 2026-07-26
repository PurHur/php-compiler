<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** NestedJIT HashTable::duplicate / unionCopy proxies (#23548). */
final class NestedVmHashTableDuplicateTest extends TestCase
{
    public function testDuplicateAndUnionCopyAreNestedHashTableMethods(): void
    {
        $this->assertTrue(
            \PHPCompiler\JIT\NestedVmHashTableMethodLlvm::isNestedHashTableMethod('duplicate')
        );
        $this->assertTrue(
            \PHPCompiler\JIT\NestedVmHashTableMethodLlvm::isNestedHashTableMethod('unioncopy')
        );
    }

    public function testNestedHandlersDoNotBridgeThroughDuplicateRuntime(): void
    {
        $dup = (string) file_get_contents(__DIR__.'/../../lib/JIT/Call/HashTableDuplicate.php');
        $union = (string) file_get_contents(__DIR__.'/../../lib/JIT/Call/HashTableUnionCopy.php');
        $cow = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableCowLlvm.php');

        $this->assertStringContainsString('HashTableCowLlvm::duplicate', $dup);
        $this->assertStringContainsString('HashTableCowLlvm::union', $union);
        $this->assertStringNotContainsString('HashTableDuplicateRuntime::', $dup);
        $this->assertStringNotContainsString('HashTableUnionRuntime::', $union);
        $this->assertStringContainsString('zend_array_dup', $cow);
    }

    public function testHashTableJitHelperStillDelegatesToVmDuplicate(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/VM/HashTableJitHelper.php');
        $this->assertStringContainsString('return $ht->duplicate();', $helper);
        $this->assertStringContainsString('return $left->unionCopy($right);', $helper);
    }
}
