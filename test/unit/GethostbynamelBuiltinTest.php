<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\gethostbynamel;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for gethostbynamel() (#3707). */
final class GethostbynamelBuiltinTest extends TestCase
{
    public function testLocalhostReturnsIpv4List(): void
    {
        $zend = @\gethostbynamel('localhost');
        if (false === $zend || !\is_array($zend) || [] === $zend) {
            $this->markTestSkipped('host gethostbynamel(localhost) unavailable');
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
        $this->assertSame($zend[0], $first->toString());
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
