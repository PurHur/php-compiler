<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** DefineRuntime must route through DefineJitHelper PHP, not phpc_user_constants LLVM global (#9410). */
final class DefineRuntimeShrinkTest extends TestCase
{
    public function testDefineRuntimeUsesDefineJitHelperNotLlvmUserConstantsGlobal(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DefineRuntime.php');
        $this->assertStringContainsString('DefineJitHelper', $source);
        $this->assertStringNotContainsString("GLOBAL = 'phpc_user_constants'", $source);
        $this->assertStringNotContainsString("addGlobal(\$htPtrTy, 'phpc_user_constants')", $source);
        $this->assertStringNotContainsString('HashTableHelper::alloc', $source);
        $this->assertStringContainsString('createTable', $source);
    }

    public function testJitDefineUsesDefineRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitDefine.php');
        $this->assertStringContainsString('DefineRuntime', $source);
    }

    public function testDefineJitHelperCreatesHashTable(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/DefineJitHelper.php');
        $this->assertStringContainsString('createTable', $source);
        $this->assertStringContainsString('HashTable', $source);
    }

    public function testDefineJitHelperCreateTableOnHost(): void
    {
        $table = \PHPCompiler\ext\standard\DefineJitHelper::createTable();
        $this->assertInstanceOf(\PHPCompiler\VM\HashTable::class, $table);
    }

    public function testDefineJitHelperIsDefinedOnHost(): void
    {
        $table = \PHPCompiler\ext\standard\DefineJitHelper::createTable();
        $this->assertFalse(\PHPCompiler\ext\standard\DefineJitHelper::isDefined($table, 'MISSING'));
    }
}
