<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\gethostbyaddr;
use PHPCompiler\ext\standard\VmDns;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for gethostbyaddr() (#5854). */
final class GethostbyaddrBuiltinTest extends TestCase
{
    public function testVmDnsDoesNotDelegateToHostGethostbyaddr(): void
    {
        $src = (string) \file_get_contents(__DIR__.'/../../ext/standard/VmDns.php');
        $this->assertDoesNotMatchRegularExpression('/\\\\gethostbyaddr\\s*\\(/', $src);
    }

    public function testLoopbackReturnsHostname(): void
    {
        if (false === VmDns::gethostbyaddr('127.0.0.1')) {
            $this->markTestSkipped('native gethostbyaddr(127.0.0.1) unavailable');
        }

        $runtime = new Runtime();
        $fn = new gethostbyaddr();
        $frame = $fn->getFrame($runtime->vmContext);
        $ipVar = new VMVariable();
        $ipVar->string('127.0.0.1');
        $frame->calledArgs = [$ipVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $resolved = $frame->returnVar->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_STRING, $resolved->type);
        $this->assertNotSame('', $resolved->toString());
    }

    public function testInvalidIpReturnsFalse(): void
    {
        $runtime = new Runtime();
        $fn = new gethostbyaddr();
        $frame = $fn->getFrame($runtime->vmContext);
        $ipVar = new VMVariable();
        $ipVar->string('not-an-ip');
        $frame->calledArgs = [$ipVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $resolved = $frame->returnVar->resolveIndirect();
        $this->assertTrue($resolved->toBool() === false);
    }
}
