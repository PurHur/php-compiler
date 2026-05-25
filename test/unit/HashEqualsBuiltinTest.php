<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\hash_equals;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for hash_equals() (#2179). */
final class HashEqualsBuiltinTest extends TestCase
{
    public function testEqualAndUnequalStrings(): void
    {
        $runtime = new Runtime();
        $fn = new hash_equals();

        $matchFrame = $fn->getFrame($runtime->vmContext);
        $known = new VMVariable();
        $known->string('515aae133b435d4000956731f68ae5cf5eb85d4f0dc6a546d2bfcd3595ec1ae1');
        $user = new VMVariable();
        $user->string('515aae133b435d4000956731f68ae5cf5eb85d4f0dc6a546d2bfcd3595ec1ae1');
        $matchFrame->calledArgs = [$known, $user];
        $matchFrame->returnVar = new VMVariable();
        $fn->execute($matchFrame);
        $this->assertTrue($matchFrame->returnVar->resolveIndirect()->toBool());

        $badFrame = $fn->getFrame($runtime->vmContext);
        $badUser = new VMVariable();
        $badUser->string('wrong');
        $badFrame->calledArgs = [$known, $badUser];
        $badFrame->returnVar = new VMVariable();
        $fn->execute($badFrame);
        $this->assertFalse($badFrame->returnVar->resolveIndirect()->toBool());

        $lenFrame = $fn->getFrame($runtime->vmContext);
        $short = new VMVariable();
        $short->string('abc');
        $lenFrame->calledArgs = [$known, $short];
        $lenFrame->returnVar = new VMVariable();
        $fn->execute($lenFrame);
        $this->assertFalse($lenFrame->returnVar->resolveIndirect()->toBool());
    }
}
