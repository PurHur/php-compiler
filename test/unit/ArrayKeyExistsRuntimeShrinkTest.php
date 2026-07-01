<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayKeyExistsJitHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\ArrayKeyExistsRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\Runtime;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_key_exists() JIT routes through ArrayKeyExistsJitHelper PHP not inline LLVM (#13735, #14545). */
final class ArrayKeyExistsRuntimeShrinkTest extends TestCase
{
    public function testArrayKeyExistsRuntimeUsesJitHelperNotInlineLlvm(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayKeyExistsRuntime.php');
        $this->assertStringContainsString('ArrayKeyExistsJitHelper', $runtime);
        $this->assertStringContainsString('IS_NATIVE_ARRAY', $runtime);
        $this->assertStringContainsString('nativeArrayKeyExists', $runtime);
        $this->assertStringNotContainsString('standaloneKeyExistsOnHashTable', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertStringNotContainsString('__hashtable__offsetIsSetStringKey', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_key_exists.php');
        $this->assertStringContainsString('ArrayKeyExistsRuntime::keyExists', $builtin);
        $this->assertStringNotContainsString('jitKeyExistsOnHashTable', $builtin);
        $this->assertStringNotContainsString('__hashtable__offsetIsSetStringKey', $builtin);
        $this->assertLessThan(120, \substr_count($builtin, "\n") + 1);
    }

    /**
     * @group aot-lint
     */
    public function testEnsureLinkedDefinesKeyExistsForStandalone(): void
    {
        $runtime = new Runtime(Runtime::MODE_AOT);
        $ctx = new Context($runtime, Builtin::LOAD_TYPE_STANDALONE);
        ArrayKeyExistsRuntime::ensureLinked($ctx);

        $fn = $ctx->lookupFunction('__array_key_exists__has_key');
        $this->assertNotNull($fn);
        $this->assertGreaterThan(0, $fn->countBasicBlocks());
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
