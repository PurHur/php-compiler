<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\password_hash;
use PHPCompiler\ext\standard\password_verify;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtins for password_hash() / password_verify() (#172). */
final class PasswordBuiltinTest extends TestCase
{
    public function testHashAndVerifyBcrypt(): void
    {
        $runtime = new Runtime();
        $pass = new VMVariable();
        $pass->string('secret');
        $algo = new VMVariable();
        $algo->int(1);

        $hashFn = new password_hash();
        $hashFrame = $hashFn->getFrame($runtime->vmContext);
        $hashFrame->calledArgs = [$pass, $algo];
        $hashFrame->returnVar = new VMVariable();
        $hashFn->execute($hashFrame);

        $hash = $hashFrame->returnVar->resolveIndirect();
        $this->assertSame(VMVariable::TYPE_STRING, $hash->type);

        $verifyFn = new password_verify();
        $okFrame = $verifyFn->getFrame($runtime->vmContext);
        $okPass = new VMVariable();
        $okPass->string('secret');
        $okFrame->calledArgs = [$okPass, $hash];
        $okFrame->returnVar = new VMVariable();
        $verifyFn->execute($okFrame);
        $this->assertTrue($okFrame->returnVar->resolveIndirect()->toBool());

        $badFrame = $verifyFn->getFrame($runtime->vmContext);
        $badPass = new VMVariable();
        $badPass->string('wrong');
        $badFrame->calledArgs = [$badPass, $hash];
        $badFrame->returnVar = new VMVariable();
        $verifyFn->execute($badFrame);
        $this->assertFalse($badFrame->returnVar->resolveIndirect()->toBool());
    }
}
