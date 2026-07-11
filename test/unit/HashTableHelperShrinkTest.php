<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** HashTableHelper v1/v2/v3/v4/v5 slices route DefineRuntime + splits read/write LLVM (#10031, #17809). */
final class HashTableHelperShrinkTest extends TestCase
{
    private const HELPER_MAX_LINES = 1350;

    public function testHashTableHelperDelegatesWriteLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableHelper.php');
        $this->assertStringContainsString('HashTableWriteLlvm::setAtStringKey', $source);
        $this->assertStringContainsString('HashTableWriteLlvm::setAtIndex', $source);
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
        $this->assertStringContainsString('HashTableReadLlvm::offsetIsSetDim', $helper);
        $this->assertStringContainsString('HashTableReadLlvm::readDimToValueBox', $helper);
        $this->assertStringNotContainsString('function offsetIsSetValueBoxKey', $helper);
        $this->assertStringNotContainsString('function readValueBoxKeyToValueBox', $helper);

        $readLlvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableReadLlvm.php');
        $this->assertStringContainsString('public static function offsetIsSetValueBoxKey', $readLlvm);
        $this->assertStringContainsString('public static function readValueBoxKeyToValueBox', $readLlvm);
        $this->assertStringContainsString('self::offsetIsSetValueBoxKey', $readLlvm);
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

    public function testHashTableHelperDelegatesSuperglobalReadToReadLlvm(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableHelper.php');
        $this->assertStringContainsString(
            'HashTableReadLlvm::readSuperglobalStringKeyToValueBox',
            $helper
        );
        $this->assertStringNotContainsString('sg_sk_has_', $helper);
        $this->assertStringNotContainsString('__hashtable__peekStringKeyValue', $helper);

        $read = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableReadLlvm.php');
        $this->assertStringContainsString('readSuperglobalStringKeyToValueBox', $read);
        $this->assertStringContainsString('__hashtable__peekStringKeyValue', $read);
    }

    public function testHashTableHelperDelegatesValueBoxUnsetToWriteLlvm(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableHelper.php');
        $this->assertStringContainsString('HashTableWriteLlvm::offsetUnset', $helper);
        $this->assertStringNotContainsString('ht_unset_vk_str', $helper);

        $write = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableWriteLlvm.php');
        $this->assertStringContainsString('unsetValueBoxKey', $write);
        $this->assertStringContainsString('ht_unset_vk_str', $write);
        $this->assertStringContainsString('self::unsetValueBoxKey', $write);
    }

    public function testHashTableHelperDelegatesDimIssetReadUnsetToReadWriteLlvm(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableHelper.php');
        $this->assertStringContainsString('HashTableReadLlvm::offsetIsSetDim', $helper);
        $this->assertStringContainsString('HashTableReadLlvm::readDimToValueBox', $helper);
        $this->assertStringContainsString('HashTableWriteLlvm::offsetUnset', $helper);
        $this->assertStringContainsString('HashTableWriteLlvm::writableObjectKeyValueBox', $helper);
        $this->assertStringNotContainsString('Illegal offset type in isset or empty', $helper);
        $this->assertStringNotContainsString('Illegal offset type in unset', $helper);

        $read = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableReadLlvm.php');
        $this->assertStringContainsString('public static function offsetIsSetDim', $read);
        $this->assertStringContainsString('public static function readDimToValueBox', $read);

        $write = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableWriteLlvm.php');
        $this->assertStringContainsString('public static function offsetUnset', $write);
        $this->assertStringContainsString('public static function writableObjectKeyValueBox', $write);
    }

    public function testHashTableHelperLineBudgetAfterV4Slice(): void
    {
        $lines = substr_count(
            (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableHelper.php'),
            "\n"
        ) + 1;
        $this->assertLessThanOrEqual(
            self::HELPER_MAX_LINES,
            $lines,
            'HashTableHelper.php should shrink as LLVM moves to Read/WriteLlvm (#10031 v4, #17809 v5)'
        );
    }

    public function testHashTableHelperDelegatesWritePathLlvmV5(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableHelper.php');
        $this->assertStringContainsString('HashTableWriteLlvm::addElement', $helper);
        $this->assertStringContainsString('HashTableWriteLlvm::setValueBoxKey', $helper);
        $this->assertStringContainsString('HashTableWriteLlvm::setAtObjectKey', $helper);
        $this->assertStringContainsString('HashTableWriteLlvm::setAtKeyCoercingNumericString', $helper);
        $this->assertStringNotContainsString('function setAtKeyCoercingNumericStringBody', $helper);
        $this->assertStringNotContainsString('function setValueBoxAtObjectKey', $helper);

        $write = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableWriteLlvm.php');
        $this->assertStringContainsString('public static function addElement', $write);
        $this->assertStringContainsString('public static function setValueBoxKey', $write);
        $this->assertStringContainsString('public static function setAtObjectKey', $write);
        $this->assertStringContainsString('public static function setAtKeyCoercingNumericString', $write);
    }
}
