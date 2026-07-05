<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** HashTableHelper v1/v2 slices route DefineRuntime + splits write/read LLVM (#10031, #16390). */
final class HashTableHelperShrinkTest extends TestCase
{
    public function testHashTableHelperDelegatesWriteLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableHelper.php');
        $this->assertStringContainsString('HashTableWriteLlvm::setAtStringKey', $source);
        $this->assertStringContainsString('HashTableWriteLlvm::setAtIndex', $source);
        $this->assertLessThan(1900, substr_count($source, "\n"));
    }

    public function testHashTableHelperDelegatesReadLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableHelper.php');
        $this->assertStringContainsString('HashTableReadLlvm::readStringKeyToValueBox', $source);
        $this->assertStringContainsString('HashTableReadLlvm::readIndexedToValueBox', $source);
    }

    public function testHashTableHelperDelegatesValueBoxDimReadLlvm(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableHelper.php');
        $this->assertStringContainsString('HashTableReadLlvm::offsetIsSetValueBoxKey', $helper);
        $this->assertStringContainsString('HashTableReadLlvm::readValueBoxKeyToValueBox', $helper);
        $this->assertStringNotContainsString('function offsetIsSetValueBoxKey', $helper);
        $this->assertStringNotContainsString('function readValueBoxKeyToValueBox', $helper);

        $readLlvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableReadLlvm.php');
        $this->assertStringContainsString('public static function offsetIsSetValueBoxKey', $readLlvm);
        $this->assertStringContainsString('public static function readValueBoxKeyToValueBox', $readLlvm);
    }

    public function testDefineRuntimeUsesLlvmOffsetIsSetStringKey(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DefineRuntime.php');
        $this->assertStringContainsString('__hashtable__offsetIsSetStringKey', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope', $source);
    }

    public function testHashTableJitHelperSemanticsOnHost(): void
    {
        $table = \PHPCompiler\ext\standard\DefineJitHelper::createTable();
        $this->assertFalse(\PHPCompiler\ext\standard\DefineJitHelper::isDefined($table, 'FOO'));
    }
}
