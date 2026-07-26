<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayDiffAssocJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_diff_assoc() NestedJIT via JitVmHelperLink::ensureCompiled (#23498 / peer #23116).
 */
final class ArrayDiffAssocRuntimeShrinkTest extends TestCase
{
    public function testArrayDiffAssocRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayDiffAssocRuntime.php');
        $this->assertStringContainsString('ArrayDiffAssocJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
        $this->assertStringNotContainsString('parseAndCompile', $runtime);
        $this->assertStringNotContainsString('new JIT(', $runtime);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayDiffAssoc', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_diff_assoc.php');
        $this->assertStringContainsString('ArrayDiffAssocRuntime::diffAssoc', $builtin);
        $this->assertStringContainsString("require_once __DIR__.'/VmArrayAssocSetOps.php'", $builtin);
        $this->assertStringContainsString('VmArrayAssocSetOps::guardSetOpOperands', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayDiffAssoc', $builtin);
    }

    public function testArrayDiffAssocLintPassesWithoutTraitUse(): void
    {
        $lint = (string) shell_exec('php bin/lint.php ext/standard/array_diff_assoc.php 2>&1');

        $this->assertStringNotContainsString('Stmt_TraitUse', $lint);
        $this->assertStringNotContainsString('Trait "VmArrayAssocSetOps" not found', $lint);
    }

    public function testArrayDiffAssocAutoloadsHelperBeforeClass(): void
    {
        $this->assertTrue(class_exists(\PHPCompiler\ext\standard\array_diff_assoc::class));
    }

    public function testArrayDiffAssocJitHelperRemovesMatchingPairs(): void
    {
        $first = new HashTable();
        $a = new Variable();
        $a->string('x');
        $first->add('k', $a);
        $b = new Variable();
        $b->string('keep');
        $first->add('z', $b);

        $other = new HashTable();
        $c = new Variable();
        $c->string('x');
        $other->add('k', $c);

        $result = ArrayDiffAssocJitHelper::diffAssocTwo($first, $other);
        $this->assertNull($result->find('k'));
        $this->assertSame('keep', $result->find('z')?->toString());
    }

    public function testArrayDiffAssocJitHelperLooseIntBoolValueCompare(): void
    {
        $first = new HashTable();
        $a = new Variable();
        $a->int(1);
        $first->addIndex(0, $a);

        $other = new HashTable();
        $b = new Variable();
        $b->bool(true);
        $other->addIndex(0, $b);

        $result = ArrayDiffAssocJitHelper::diffAssocTwo($first, $other);
        $this->assertNull($result->findIndex(0));
    }
}
