<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\gethostbynamel;
use PHPCompiler\ext\standard\VmDns;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for gethostbynamel() (#3707). */
final class GethostbynamelBuiltinTest extends TestCase
{
    public function testVmDnsDoesNotDelegateToHostGethostbynamel(): void
    {
        $src = (string) \file_get_contents(__DIR__.'/../../ext/standard/VmDns.php');
        $this->assertDoesNotMatchRegularExpression('/\\\\gethostbynamel\\s*\\(/', $src);
    }

    public function testPhpcGethostbynamelCRuntimeRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/phpc_gethostbynamel.c');
    }

    public function testLocalhostReturnsIpv4List(): void
    {
        if (false === VmDns::gethostbynamel('localhost')) {
            $this->markTestSkipped('native gethostbynamel(localhost) unavailable');
        }

        $runtime = new Runtime();
        $fn = new gethostbynamel();
        $frame = $fn->getFrame($runtime->vmContext);
        $hostVar = new VMVariable();
        $hostVar->string('localhost');
        $frame->calledArgs = [$hostVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $resolved = $frame->returnVar->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_ARRAY, $resolved->type);
        $ht = $resolved->toArray();
        $first = $ht->find('0');
        $this->assertNotNull($first);
        $this->assertSame(VMVariable::TYPE_STRING, $first->type);
        $this->assertMatchesRegularExpression('/^\d{1,3}(\.\d{1,3}){3}$/', $first->toString());
    }

    public function testLocalhostPreservesDuplicateIpv4Records(): void
    {
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('FFI required for getaddrinfo duplicate-A parity');
        }
        $native = \gethostbynamel('localhost');
        if (false === $native || \count($native) < 2) {
            $this->markTestSkipped('native gethostbynamel(localhost) has fewer than two A records');
        }

        $ips = VmDns::resolveHostnameIpv4List('localhost');
        $this->assertSame(\count($native), \count($ips));
        $this->assertSame($native[0], $ips[0]);
        $this->assertSame($native[1], $ips[1]);
    }

    public function testUnknownHostReturnsFalse(): void
    {
        $runtime = new Runtime();
        $fn = new gethostbynamel();
        $frame = $fn->getFrame($runtime->vmContext);
        $hostVar = new VMVariable();
        $hostVar->string('no-such-phpc-host.invalid.');
        $frame->calledArgs = [$hostVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $resolved = $frame->returnVar->resolveIndirect();
        $this->assertTrue($resolved->toBool() === false);
    }
}
