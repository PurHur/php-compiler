<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SessionEncodeJitHelper;
use PHPCompiler\ext\standard\VmSessionSerializer;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * NestedJIT HashTable::find → toString strlen must match Zend (#21921).
 *
 * Host PHP exercises the SSOT encode path; AOT SessionsWeb covers the LLVM bridge.
 */
final class NestedJitHashTableFindToStringTest extends TestCase
{
    public function testEncodeWireHashTablePreservesStringLength(): void
    {
        $ht = new HashTable();
        $v = new Variable(Variable::TYPE_STRING);
        $v->string('Unique99');
        $ht->add('flash', $v);

        $wire = VmSessionSerializer::encodeWireHashTable($ht);
        $this->assertIsString($wire);
        $this->assertSame('flash|s:8:"Unique99";', $wire);
    }

    public function testSessionEncodeJitHelperRoundTripFlash(): void
    {
        $ht = new HashTable();
        $v = new Variable(Variable::TYPE_STRING);
        $v->string('Unique99');
        $ht->add('flash', $v);

        $wire = SessionEncodeJitHelper::encodeWire($ht);
        $this->assertSame('flash|s:8:"Unique99";', $wire);

        $decoded = SessionEncodeJitHelper::decodeWire($wire);
        $this->assertInstanceOf(HashTable::class, $decoded);
        $found = $decoded->find('flash');
        $this->assertInstanceOf(Variable::class, $found);
        $s = $found->resolveIndirect()->toString();
        $this->assertSame(8, \strlen($s));
        $this->assertSame('Unique99', $s);
    }

    public function testCopyBetweenPointersMasksRefcountedTypeByte(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/JitValueBox.php');
        $this->assertStringContainsString('0x7f', $source);
        $this->assertStringContainsString('TYPE_STRING & 0x7f', $source);
        $this->assertStringContainsString('#21921', $source);
    }

    public function testVariableToStringSeparatesOwnedString(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Call/VariableToString.php');
        $this->assertStringContainsString('__string__separate', $source);
        $this->assertStringContainsString('#21921', $source);
    }
}
