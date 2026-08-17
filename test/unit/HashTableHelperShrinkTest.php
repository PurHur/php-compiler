<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** HashTableHelper v1–v8 slices route DefineRuntime + splits read/write/spread/ensure/init LLVM (#10031, #18942). */
final class HashTableHelperShrinkTest extends TestCase
{
    private const HELPER_MAX_LINES = 550;

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

    /**
     * Indexed/string-key reads must use the shared typed copy — a long-only fallback
     * misreads null/bool/double slots as int(0) (#24232).
     */
    public function testReadToValueBoxUsesSharedTypedCopy(): void
    {
        $read = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableReadLlvm.php');
        $this->assertMatchesRegularExpression(
            '/function readIndexedToValueBox\b[\s\S]*?JitValueBox::copyFromPointer/',
            $read
        );
        $this->assertMatchesRegularExpression(
            '/function readStringKeyToValueBox\b[\s\S]*?JitValueBox::copyFromPointer/',
            $read
        );
        // Guard: do not reintroduce a long-only fallthrough in either reader.
        $this->assertDoesNotMatchRegularExpression(
            '/function readIndexedToValueBox\b[\s\S]*?__value__writeLong[\s\S]*?function readStringKeyToValueBox\b/',
            $read
        );
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
            'HashTableHelper.php should shrink as LLVM moves to Read/WriteLlvm (#10031 v4–v8, #18942)'
        );
    }

    public function testHashTableHelperDelegatesEnsureAndMaterializeLlvmV7(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableHelper.php');
        $this->assertStringContainsString('HashTableReadLlvm::ensureHashtablePointer', $helper);
        $this->assertStringContainsString('HashTableWriteLlvm::materializeNativeArrayForCall', $helper);
        $this->assertStringContainsString('HashTableWriteLlvm::writableStringKeyValueBox', $helper);
        $this->assertStringContainsString('HashTableWriteLlvm::prepareStringKeyWrite', $helper);
        $this->assertStringContainsString('HashTableWriteLlvm::storeHashtableInArrayVariable', $helper);
        $this->assertStringNotContainsString('ht_ensure_box_init', $helper);
        $this->assertStringNotContainsString('native_ht_head', $helper);
        $this->assertStringNotContainsString('ht_sk_write_create', $helper);

        $read = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableReadLlvm.php');
        $this->assertStringContainsString('public static function ensureHashtablePointer', $read);
        $this->assertStringContainsString('ht_ensure_box_init', $read);

        $write = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableWriteLlvm.php');
        $this->assertStringContainsString('public static function materializeNativeArrayForCall', $write);
        $this->assertStringContainsString('public static function writableStringKeyValueBox', $write);
        $this->assertStringContainsString('public static function prepareStringKeyWrite', $write);
        $this->assertStringContainsString('public static function storeHashtableInArrayVariable', $write);
        $this->assertStringContainsString('native_ht_head', $write);
    }

    public function testHashTableHelperDelegatesSpreadAndRangeLlvmV6(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableHelper.php');
        $this->assertStringContainsString('HashTableWriteLlvm::spreadAddElement', $helper);
        $this->assertStringContainsString('HashTableWriteLlvm::spreadInto', $helper);
        $this->assertStringContainsString('HashTableWriteLlvm::reserveAppendSlot', $helper);
        $this->assertStringNotContainsString('buildIntegerRange', $helper);
        $this->assertStringNotContainsString('function buildArrayFill', $helper);
        $this->assertStringNotContainsString('function spreadPackedInto', $helper);
        $this->assertStringNotContainsString('ht_spread_add_str_', $helper);

        $write = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableWriteLlvm.php');
        $this->assertStringContainsString('public static function spreadAddElement', $write);
        $this->assertStringContainsString('public static function spreadInto', $write);
        $this->assertStringContainsString('public static function reserveAppendSlot', $write);
        $this->assertStringNotContainsString('buildIntegerRange', $write);
        $this->assertStringContainsString('private static function spreadPackedInto', $write);
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

    public function testHashTableHelperDelegatesInitAndMaterializeLlvmV8(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableHelper.php');
        $this->assertStringContainsString('HashTableWriteLlvm::initArray', $helper);
        $this->assertStringContainsString('HashTableWriteLlvm::variableFromVmHashTable', $helper);
        $this->assertStringContainsString('HashTableWriteLlvm::packVariables', $helper);
        $this->assertStringContainsString('HashTableWriteLlvm::objectPointerAsStringKey', $helper);
        $this->assertStringContainsString('HashTableReadLlvm::loadHashtablePointer', $helper);
        $this->assertStringContainsString('HashTableReadLlvm::listEntryPointer', $helper);
        $this->assertStringContainsString('HashTableReadLlvm::readStringAt', $helper);
        $this->assertStringNotContainsString('function ensureHashtableInitLvalueSlot', $helper);
        $this->assertStringNotContainsString('ht_ensure_box_init', $helper);
        $this->assertStringNotContainsString('spl_key_buf', $helper);

        $write = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableWriteLlvm.php');
        $this->assertStringContainsString('public static function initArray', $write);
        $this->assertStringContainsString('public static function variableFromVmHashTable', $write);
        $this->assertStringContainsString('public static function packVariables', $write);

        $read = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableReadLlvm.php');
        $this->assertStringContainsString('public static function loadHashtablePointer', $read);
        $this->assertStringContainsString('public static function listEntryPointer', $read);
        $this->assertStringContainsString('public static function forEachStringKeyNode', $read);
        $this->assertStringContainsString('public static function forEachIndexedStringAt', $read);
    }

    public function testHashTableHelperDelegatesCallArgAndCoerceLlvmV9(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableHelper.php');
        $this->assertStringContainsString('HashTableWriteLlvm::mergeCallArgEntries', $helper);
        $this->assertStringContainsString('HashTableWriteLlvm::coerceToPackedHashtable', $helper);
        $this->assertStringContainsString('HashTableWriteLlvm::boxedArrayFromHashtable', $helper);
        $this->assertStringContainsString('HashTableWriteLlvm::alloc', $helper);
        $this->assertStringContainsString('HashTableReadLlvm::emitIllegalOffsetType', $helper);
        $this->assertStringNotContainsString('CallUnpackRuntime::ensureLinked', $helper);
        $this->assertStringNotContainsString('TypeErrorRaise::emitRaise', $helper);

        $write = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableWriteLlvm.php');
        $this->assertStringContainsString('public static function mergeCallArgEntries', $write);
        $this->assertStringContainsString('public static function coerceToPackedHashtable', $write);
        $this->assertStringContainsString('public static function boxedArrayFromHashtable', $write);

        $read = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableReadLlvm.php');
        $this->assertStringContainsString('public static function emitIllegalOffsetType', $read);
        $this->assertStringContainsString('public static function illegalOffsetMessageForJitKey', $read);
    }

    public function testHashTableHelperDelegatesForeachByRefLlvmV10(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableHelper.php');
        $this->assertStringContainsString('HashTableWriteLlvm::assignForeachByRefWritable', $helper);
        $this->assertStringNotContainsString('foreachByRefPackedArm', $helper);

        $write = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableWriteLlvm.php');
        $this->assertStringContainsString('public static function assignForeachByRefWritable', $write);
        $this->assertStringContainsString('self::setAtIndex', $write);
    }
}
