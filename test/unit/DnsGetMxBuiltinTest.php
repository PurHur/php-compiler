<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\dns_get_mx;
use PHPCompiler\ext\standard\VmDns;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for dns_get_mx() (#4125). */
final class DnsGetMxBuiltinTest extends TestCase
{
    public function testFunctionNameRegistered(): void
    {
        $fn = new dns_get_mx();
        $this->assertSame('dns_get_mx', $fn->getName());
    }

    public function testEmptyHostnameReturnsFalseAndClearsArrays(): void
    {
        $runtime = new Runtime();
        $fn = new dns_get_mx();
        $frame = $fn->getFrame($runtime->vmContext);

        $hostVar = new VMVariable();
        $hostVar->string('');
        $mxVar = new VMVariable();
        $mxVar->array(new \PHPCompiler\VM\HashTable());
        $weightVar = new VMVariable();
        $weightVar->array(new \PHPCompiler\VM\HashTable());
        $frame->calledArgs = [$hostVar, $mxVar, $weightVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        $this->assertFalse($frame->returnVar->resolveIndirect()->toBool());
        $this->assertSame(0, $mxVar->resolveIndirect()->toArray()->getNumElements());
        $this->assertSame(0, $weightVar->resolveIndirect()->toArray()->getNumElements());
    }

    public function testExampleComMxWhenResolverAvailable(): void
    {
        if (false === VmDns::dnsGetMx('example.com')) {
            $this->markTestSkipped('example.com MX records unavailable');
        }

        $runtime = new Runtime();
        $fn = new dns_get_mx();
        $frame = $fn->getFrame($runtime->vmContext);

        $hostVar = new VMVariable();
        $hostVar->string('example.com');
        $mxVar = new VMVariable();
        $mxVar->array(new \PHPCompiler\VM\HashTable());
        $weightVar = new VMVariable();
        $weightVar->array(new \PHPCompiler\VM\HashTable());
        $frame->calledArgs = [$hostVar, $mxVar, $weightVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        $this->assertTrue($frame->returnVar->resolveIndirect()->toBool());
        $this->assertGreaterThan(0, $mxVar->resolveIndirect()->toArray()->getNumElements());
        $this->assertSame(
            $mxVar->resolveIndirect()->toArray()->getNumElements(),
            $weightVar->resolveIndirect()->toArray()->getNumElements()
        );
    }
}
