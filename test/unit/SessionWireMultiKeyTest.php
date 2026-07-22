<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmSessionSerializer;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * Thin AOT session wire multi-key + int/bool/null (#21922).
 *
 * SSOT encode (host) must emit php-src wire; LLVM save/load mirrors the same shapes.
 */
final class SessionWireMultiKeyTest extends TestCase
{
    public function testEncodeWireEmitsMultiKeyIntBoolNull(): void
    {
        $ht = new HashTable();
        $a = new Variable(Variable::TYPE_STRING);
        $a->string('one');
        $ht->add('a', $a);
        $b = new Variable(Variable::TYPE_STRING);
        $b->string('two');
        $ht->add('b', $b);
        $n = new Variable(Variable::TYPE_INTEGER);
        $n->int(3);
        $ht->add('n', $n);
        $t = new Variable(Variable::TYPE_BOOLEAN);
        $t->bool(true);
        $ht->add('t', $t);
        $z = new Variable(Variable::TYPE_NULL);
        $ht->add('z', $z);

        $wire = VmSessionSerializer::encodeWireHashTable($ht);
        $this->assertIsString($wire);
        $this->assertStringContainsString('a|s:3:"one";', $wire);
        $this->assertStringContainsString('b|s:3:"two";', $wire);
        $this->assertStringContainsString('n|i:3;', $wire);
        $this->assertStringContainsString('t|b:1;', $wire);
        $this->assertStringContainsString('z|N;', $wire);
    }

    public function testLlvmWireSaveLoadMentionsMultiTypeAndLoop(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitSessionStorageKernel.php'
        );
        $this->assertStringContainsString('%.*s|i:%lld;', $source);
        $this->assertStringContainsString('%.*s|b:%d;', $source);
        $this->assertStringContainsString('%.*s|N;', $source);
        $this->assertStringContainsString('ss_wire_load_loop', $source);
        $this->assertStringContainsString('__hashtable__setStringKeyLong', $source);
        $this->assertStringContainsString('__hashtable__setStringKeyBool', $source);
        $this->assertStringContainsString('__hashtable__setStringKeyNull', $source);
        $this->assertStringContainsString('#21922', $source);
    }

    public function testSuperglobalStringKeyReadUsesTypedValueCopy(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/HashTableReadLlvm.php'
        );
        $this->assertStringContainsString('#21948', $source);
        $fnStart = strpos($source, 'function readSuperglobalStringKeyToValueBox');
        $this->assertNotFalse($fnStart);
        $fnEnd = strpos($source, 'function offsetIsSetDim', $fnStart);
        $this->assertNotFalse($fnEnd);
        $fnBody = substr($source, (int) $fnStart, (int) $fnEnd - (int) $fnStart);
        $this->assertStringContainsString('JitValueBox::copyFromPointer', $fnBody);
        $this->assertStringNotContainsString("__value__readString',\n            \$valPtr", $fnBody);
    }

    /** #21947 — dim-write null must commit via setAtStringKey, not orphan value box. */
    public function testAssignOperandCommitsWritableStringKeyBeforeValueBoxWrite(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('#21947', $jit);
        $marker = 'prepareStringKeyWrite / prepareIndexWrite lvalues';
        $pos = strpos($jit, $marker);
        $this->assertNotFalse($pos);
        $snippet = substr($jit, (int) $pos, 700);
        $this->assertStringContainsString('writableStringKey', $snippet);
        $this->assertStringContainsString('HashTableHelper::setAtStringKey', $snippet);
        $this->assertStringContainsString('HashTableHelper::setAtIndex', $snippet);

        $write = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/HashTableWriteLlvm.php'
        );
        $this->assertStringContainsString('setValueBoxAtStringKey', $write);
        $this->assertStringContainsString('__hashtable__setStringKeyNull', $write);
    }
}
