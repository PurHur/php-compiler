<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\JitUnserialize;
use PHPCompiler\ext\standard\serialize;
use PHPCompiler\ext\standard\unserialize;
use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\ext\standard\VmUnserializeFormat;
use PHPUnit\Framework\TestCase;

/** VM unserialize without host \\unserialize() delegation (#8191, pairs #5280 VmSerializeFormat). */
final class VmUnserializeRuntimeShrinkTest extends TestCase
{
    public function testVmSerializeDoesNotReferenceHostUnserialize(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSerialize.php');
        $this->assertStringContainsString('VmUnserializeFormat::decodePayload', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\unserialize\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\serialize\\s*\\(/', $source);
    }

    public function testBuiltinSourcesDoNotReferenceHostUnserialize(): void
    {
        foreach ([unserialize::class, serialize::class, JitUnserialize::class] as $class) {
            $ref = new \ReflectionClass($class);
            $source = (string) file_get_contents($ref->getFileName());
            $this->assertDoesNotMatchRegularExpression('/@\\\\unserialize\\s*\\(/', $source, $class);
        }
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/UnserializeJitHelper.php');
        $this->assertStringContainsString('VmUnserializeFormat::decodeToVariableWithContext', $helper);
    }

    public function testDecodeScalarsAndArrays(): void
    {
        $this->assertNull(VmUnserializeFormat::decodePayload('N;'));
        $this->assertTrue(VmUnserializeFormat::decodePayload('b:1;'));
        $this->assertFalse(VmUnserializeFormat::decodePayload('b:0;'));
        $this->assertSame(42, VmUnserializeFormat::decodePayload('i:42;'));
        $this->assertSame('hello', VmUnserializeFormat::decodePayload('s:5:"hello";'));
        $decoded = VmUnserializeFormat::decodePayload('a:3:{s:2:"ok";b:1;s:1:"n";i:1;s:3:"msg";s:2:"hi";}');
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['ok']);
        $this->assertSame(1, $decoded['n']);
        $this->assertSame('hi', $decoded['msg']);
    }

    public function testMaxDepthMatchesZend(): void
    {
        $nested = 'a:1:{i:0;a:1:{i:0;a:1:{i:0;i:1;}}}';
        $this->assertFalse(VmUnserializeFormat::decodePayload($nested, ['max_depth' => 2]));
        $this->assertSame(2, VmUnserializeFormat::lastMaxDepthExceeded());
        $ok = VmUnserializeFormat::decodePayload($nested, ['max_depth' => 4]);
        $this->assertNull(VmUnserializeFormat::lastMaxDepthExceeded());
        $this->assertIsArray($ok);
        $this->assertSame(1, $ok[0][0][0]);
    }

    /** Issue #13777 — max_depth Notice offset matches Zend (end of exceeded nesting, not early abort). */
    public function testMaxDepthNoticeOffsetMatchesZend(): void
    {
        $nested = 'a:1:{i:0;a:1:{i:0;a:1:{i:0;i:1;}}}';
        $this->assertFalse(VmUnserializeFormat::decodePayload($nested, ['max_depth' => 1]));
        $this->assertSame(14, VmUnserializeFormat::lastErrorOffset());
        $this->assertSame(34, VmUnserializeFormat::lastPayloadLength());
    }

    public function testRoundTripViaVmSerializeExported(): void
    {
        $payload = VmSerialize::serializeExported(['ok' => true, 'n' => 1, 'msg' => 'hi']);
        $decoded = VmUnserializeFormat::decodePayload($payload);
        $this->assertSame(['ok' => true, 'n' => 1, 'msg' => 'hi'], $decoded);
    }

    public function testDecodeRReferenceMarker(): void
    {
        $decoded = VmUnserializeFormat::decodePayload('a:2:{i:0;i:1;i:1;R:2;}');
        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded[0]);
        $this->assertSame(1, $decoded[1]);
        $decoded[0] = 5;
        $this->assertSame(5, $decoded[1]);
    }

    public function testDecodeToVariableRReferenceMarker(): void
    {
        $var = VmUnserializeFormat::decodeToVariable('a:2:{i:0;i:1;i:1;R:2;}');
        $this->assertInstanceOf(\PHPCompiler\VM\Variable::class, $var);
        $ht = $var->toArray();
        $s0 = $ht->findIndex(0);
        $s1 = $ht->findIndex(1);
        $this->assertNotNull($s0);
        $this->assertNotNull($s1);
        $t0 = $s0->directIndirectTarget();
        $t1 = $s1->directIndirectTarget();
        $this->assertNotNull($t0);
        $this->assertSame($t0, $t1);
    }
}
