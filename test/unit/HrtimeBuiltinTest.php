<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\hrtime;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for hrtime() (#3195). */
final class HrtimeBuiltinTest extends TestCase
{
    public function testNanosecondsAndPairForms(): void
    {
        $runtime = new Runtime();
        $fn = new hrtime();

        $nsFrame = $fn->getFrame($runtime->vmContext);
        $asNumber = new VMVariable();
        $asNumber->bool(true);
        $nsFrame->calledArgs = [$asNumber];
        $nsFrame->returnVar = new VMVariable();
        $fn->execute($nsFrame);
        $this->assertGreaterThan(0, $nsFrame->returnVar->resolveIndirect()->toFloat());

        $pairFrame = $fn->getFrame($runtime->vmContext);
        $pairFrame->returnVar = new VMVariable();
        $fn->execute($pairFrame);
        $ht = $pairFrame->returnVar->resolveIndirect()->toArray();
        $this->assertSame(2, $ht->getNumElements());
    }
}
