<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\checkdnsrr;
use PHPCompiler\ext\standard\VmDns;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for checkdnsrr() / dns_check_record() (#5983). */
final class CheckdnsrrBuiltinTest extends TestCase
{
    public function testFunctionNamesRegistered(): void
    {
        $check = new checkdnsrr();
        $this->assertSame('checkdnsrr', $check->getName());
        $alias = new checkdnsrr('dns_check_record');
        $this->assertSame('dns_check_record', $alias->getName());
    }

    public function testLocalhostARecordWhenEtcHostsAvailable(): void
    {
        if (!VmDns::checkdnsrr('localhost', 'A')) {
            $this->markTestSkipped('localhost A record unavailable via /etc/hosts');
        }

        $runtime = new Runtime();
        $fn = new checkdnsrr();
        $frame = $fn->getFrame($runtime->vmContext);
        $hostVar = new VMVariable();
        $hostVar->string('localhost');
        $typeVar = new VMVariable();
        $typeVar->string('A');
        $frame->calledArgs = [$hostVar, $typeVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $resolved = $frame->returnVar->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_BOOLEAN, $resolved->type);
        $this->assertTrue($resolved->toBool());
    }

    public function testDnsCheckRecordAliasMatchesCheckdnsrr(): void
    {
        if (!VmDns::checkdnsrr('localhost', 'A')) {
            $this->markTestSkipped('localhost A record unavailable via /etc/hosts');
        }

        $runtime = new Runtime();
        $check = new checkdnsrr();
        $alias = new checkdnsrr('dns_check_record');
        $hostVar = new VMVariable();
        $hostVar->string('localhost');
        $typeVar = new VMVariable();
        $typeVar->string('A');
        $retCheck = new VMVariable();
        $retAlias = new VMVariable();

        $frameCheck = $check->getFrame($runtime->vmContext);
        $frameCheck->calledArgs = [$hostVar, $typeVar];
        $frameCheck->returnVar = $retCheck;
        $check->execute($frameCheck);

        $frameAlias = $alias->getFrame($runtime->vmContext);
        $frameAlias->calledArgs = [$hostVar, $typeVar];
        $frameAlias->returnVar = $retAlias;
        $alias->execute($frameAlias);

        $this->assertSame(
            $retCheck->resolveIndirect()->toBool(),
            $retAlias->resolveIndirect()->toBool()
        );
    }

}
