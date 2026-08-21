<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on serialize ABI shells from Builtin\Type (#33207).
 *
 * NestedJIT/AOT bridge stays StringSerialize / JitSerialize / SerializeNestedJitHelper.
 * Runtime owner declares module-locally (getNamedFunction first) so leftover Type
 * empty decls cannot mint serialize_hashtable.1 (#31894 / #32122).
 */
final class TypeDeadSerializeAbiRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedAbis(): array
    {
        return [
            '__compiler_serialize_hashtable',
            '__compiler_serialize_value',
            '__compiler_serialize_object',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnSerializeAbis(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#33207', $type);
        foreach ($this->droppedAbis() as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/addFunction\(\s*[\'"]'.preg_quote($sym, '/').'[\'"]/',
                $type,
                "Builtin\\Type must not always-declare {$sym} (#33207)"
            );
            $this->assertStringNotContainsString(
                "registerFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-register {$sym} (#33207)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
        // Next leftover sentinel (assert_fail still Type always-on; #33234 trigger_error dropped).
        $this->assertStringContainsString("registerFunction('__compiler_assert_fail'", $type);
        $this->assertStringContainsString('StringSerialize::ensureLinked', $type);
    }

    public function testRuntimeOwnerDeclaresSerializeAbisModuleLocally(): void
    {
        $owner = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSerialize.php');
        $this->assertStringContainsString('#33207', $owner);
        $this->assertStringContainsString('getNamedFunction', $owner);
        $this->assertStringContainsString('__compiler_serialize_hashtable', $owner);
        $this->assertStringContainsString('__compiler_serialize_value', $owner);
        $this->assertStringContainsString('__compiler_serialize_object', $owner);
        $this->assertFileExists(__DIR__.'/../../ext/standard/SerializeNestedJitHelper.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitSerialize.php');
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSerialize.php');
        $this->assertStringContainsString('#33207', $jit);
        $this->assertStringContainsString('StringSerialize::ensureLinked', $jit);
    }

    public function testTypeInitializeStillEnsureLinksStringSerialize(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('StringSerialize::ensureLinked($this->context)', $type);
    }

    public function testNoNewRuntimeCForSerializeAbi(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/serialize.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/serialize.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_serialize.c');
    }
}
