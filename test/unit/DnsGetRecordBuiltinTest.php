<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\dns_get_record;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmDns;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for dns_get_record() (#6392). */
final class DnsGetRecordBuiltinTest extends TestCase
{
    public function testFunctionNameRegistered(): void
    {
        $fn = new dns_get_record();
        $this->assertSame('dns_get_record', $fn->getName());
    }

    public function testEmptyHostnameReturnsFalse(): void
    {
        $runtime = new Runtime();
        $fn = new dns_get_record();
        $frame = $fn->getFrame($runtime->vmContext);

        $hostVar = new VMVariable();
        $hostVar->string('');
        $frame->calledArgs = [$hostVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        $this->assertFalse($frame->returnVar->resolveIndirect()->toBool());
    }

    public function testInvalidTypeThrowsValueError(): void
    {
        $runtime = new Runtime();
        $fn = new dns_get_record();
        $frame = $fn->getFrame($runtime->vmContext);

        $hostVar = new VMVariable();
        $hostVar->string('localhost');
        $typeVar = new VMVariable();
        $typeVar->int(0);
        $frame->calledArgs = [$hostVar, $typeVar];
        $frame->returnVar = new VMVariable();

        $this->expectException(\ValueError::class);
        $fn->execute($frame);
    }

    public function testLocalhostAWhenResolverAvailable(): void
    {
        if (false === VmDns::dnsGetRecord('localhost', StdlibConstants::DNS_A)) {
            $this->markTestSkipped('localhost A records unavailable');
        }

        $runtime = new Runtime();
        $fn = new dns_get_record();
        $frame = $fn->getFrame($runtime->vmContext);

        $hostVar = new VMVariable();
        $hostVar->string('localhost');
        $typeVar = new VMVariable();
        $typeVar->int(StdlibConstants::DNS_A);
        $frame->calledArgs = [$hostVar, $typeVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        $ret = $frame->returnVar->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_ARRAY, $ret->type);
        $this->assertGreaterThan(0, $ret->toArray()->getNumElements());
    }

    public function testNumericIpv4LiteralReturnsEmptyArray(): void
    {
        $result = VmDns::dnsGetRecord('127.0.0.1', StdlibConstants::DNS_A);
        $this->assertInstanceOf(\PHPCompiler\VM\HashTable::class, $result);
        $this->assertSame(0, $result->getNumElements());
    }

    public function testInvalidHostnameLabelReturnsFalse(): void
    {
        $this->assertFalse(VmDns::dnsGetRecord('invalid..domain', StdlibConstants::DNS_A));
    }

    public function testNxdomainHostnameReturnsEmptyArray(): void
    {
        $result = VmDns::dnsGetRecord('invalid.invalid', StdlibConstants::DNS_A);
        $this->assertInstanceOf(\PHPCompiler\VM\HashTable::class, $result);
        $this->assertSame(0, $result->getNumElements());
    }
}
