<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** HashTableHelper v1 slice routes DefineRuntime + splits write/read LLVM (#10031). */
final class HashTableHelperShrinkTest extends TestCase
{
    public function testHashTableHelperDelegatesWriteLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableHelper.php');
        $this->assertStringContainsString('HashTableWriteLlvm::setAtStringKey', $source);
        $this->assertStringContainsString('HashTableWriteLlvm::setAtIndex', $source);
        $this->assertLessThan(2400, substr_count($source, "\n"));
    }

    public function testHashTableHelperDelegatesReadLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableHelper.php');
        $this->assertStringContainsString('HashTableReadLlvm::readStringKeyToValueBox', $source);
        $this->assertStringContainsString('HashTableReadLlvm::readIndexedToValueBox', $source);
    }

    public function testDefineRuntimeUsesDefineJitHelperIsDefined(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DefineRuntime.php');
        $this->assertStringContainsString('DefineJitHelper::isDefined', $source);
        $this->assertStringNotContainsString('__hashtable__offsetIsSetStringKey', $source);
    }

    public function testHashTableJitHelperSemanticsOnHost(): void
    {
        $table = \PHPCompiler\ext\standard\DefineJitHelper::createTable();
        $this->assertFalse(\PHPCompiler\ext\standard\DefineJitHelper::isDefined($table, 'FOO'));
    }
}
