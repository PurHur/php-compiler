<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayKeyExistsJitHelper;
use PHPCompiler\JIT\Builtin\ArrayKeyExistsRuntime;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_key_exists() JIT lowers in LLVM via ArrayKeyExistsRuntime (#13735, #9331). */
final class ArrayKeyExistsRuntimeShrinkTest extends TestCase
{
    public function testArrayKeyExistsRuntimeUsesLlvmLoweringNotPhpHelperBridge(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayKeyExistsRuntime.php');
        $this->assertStringContainsString('hashtableKeyExistsStringKey', $runtime);
        $this->assertStringContainsString('hashtableKeyExistsValueBoxKey', $runtime);
        $this->assertStringContainsString('IS_NATIVE_ARRAY', $runtime);
        $this->assertStringContainsString('nativeArrayKeyExists', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_key_exists.php');
        $this->assertStringContainsString('ArrayKeyExistsRuntime::keyExists', $builtin);
        $this->assertLessThan(120, \substr_count($builtin, "\n") + 1);
    }

    public function testArrayKeyExistsJitHelperNullKeyCoercesToEmptyString(): void
    {
        $table = new HashTable();
        $v = new Variable();
        $v->string('x');
        $table->add('', $v);

        $key = new Variable();
        $key->null();

        $this->assertTrue(ArrayKeyExistsJitHelper::keyExists($key, $table));
    }

    public function testArrayKeyExistsJitHelperIntegerKey(): void
    {
        $table = new HashTable();
        $v = new Variable();
        $v->string('val');
        $table->addIndex(2, $v);

        $key = new Variable();
        $key->int(2);

        $this->assertTrue(ArrayKeyExistsJitHelper::keyExists($key, $table));

        $missing = new Variable();
        $missing->int(9);
        $this->assertFalse(ArrayKeyExistsJitHelper::keyExists($missing, $table));
    }
}
