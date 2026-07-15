<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayReplaceKeyJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_replace_key() JIT routes through ArrayReplaceKeyJitHelper PHP (#12488, #14531). */
final class ArrayReplaceKeyRuntimeShrinkTest extends TestCase
{
    public function testArrayReplaceKeyRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayReplaceKeyRuntime.php');
        $this->assertStringContainsString('ArrayReplaceKeyJitHelper', $runtime);
        $this->assertStringContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayReplaceKey', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function arrayReplaceKey(', $helper);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_replace_key.php');
        $this->assertStringContainsString('ArrayReplaceKeyRuntime::replaceKey', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayReplaceKey', $builtin);
    }

    public function testArrayReplaceKeyJitHelperMatchesReplaceKeyCopySemantics(): void
    {
        $base = new HashTable();
        $a = new Variable();
        $a->string('old');
        $base->add('x', $a);
        $b = new Variable();
        $b->string('keep');
        $base->add('y', $b);

        $repl = new HashTable();
        $n = new Variable();
        $n->string('new');
        $repl->add('x', $n);
        $z = new Variable();
        $z->string('ignored');
        $repl->add('z', $z);

        $out = ArrayReplaceKeyJitHelper::replaceKeyCopy($base, $repl);
        $this->assertSame('new', $out->find('x')?->resolveIndirect()->toString());
        $this->assertSame('keep', $out->find('y')?->resolveIndirect()->toString());
        $this->assertNull($out->find('z'));
    }
}
