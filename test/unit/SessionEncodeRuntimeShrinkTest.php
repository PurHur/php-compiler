<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SessionEncodeJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** session_encode/decode JIT routes through SessionEncodeJitHelper PHP not LLVM wire (#9440). */
final class SessionEncodeRuntimeShrinkTest extends TestCase
{
    public function testSessionEncodeRuntimeUsesJitHelperNotLlvmWireLoop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SessionEncodeRuntime.php');
        $this->assertStringContainsString('SessionEncodeJitHelper', $source);
        $this->assertStringContainsString('implementEncodeWireBridge', $source);
        $this->assertStringNotContainsString('emitEncodeWire', $source);
        $this->assertStringNotContainsString('emitDecodeWire', $source);
        $this->assertStringNotContainsString('__phpc_unser_parse_item', $source);
        $this->assertStringNotContainsString('__compiler_serialize_value', $source);
        $this->assertLessThan(320, \substr_count($source, "\n") + 1);
    }

    public function testSessionEncodeJitHelperRoundTripMatchesVmSessionSerializer(): void
    {
        $ht = new HashTable();
        $k = new Variable(Variable::TYPE_STRING);
        $k->string('flash');
        $v = new Variable(Variable::TYPE_STRING);
        $v->string('Saved');
        $ht->add('flash', $v);

        $wire = SessionEncodeJitHelper::encodeWire($ht);
        $this->assertIsString($wire);
        $this->assertStringContainsString('flash|', $wire);

        $decoded = SessionEncodeJitHelper::decodeWire($wire);
        $this->assertInstanceOf(HashTable::class, $decoded);
        $found = null;
        foreach ($decoded->iterateKeyed(true) as [$key, $value]) {
            if (Variable::TYPE_STRING === $key->type && 'flash' === $key->toString()) {
                $found = $value;
                break;
            }
        }
        $this->assertInstanceOf(Variable::class, $found);
        $this->assertSame('Saved', $found->resolveIndirect()->toString());
    }
}
