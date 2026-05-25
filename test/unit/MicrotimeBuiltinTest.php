<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\microtime;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for microtime() (#2186). */
final class MicrotimeBuiltinTest extends TestCase
{
    public function testStringAndFloatForms(): void
    {
        $runtime = new Runtime();
        $fn = new microtime();

        $stringFrame = $fn->getFrame($runtime->vmContext);
        $stringFrame->returnVar = new VMVariable();
        $fn->execute($stringFrame);
        $s = $stringFrame->returnVar->resolveIndirect()->toString();
        $parts = explode(' ', $s);
        $this->assertCount(2, $parts);
        $this->assertTrue(is_numeric($parts[0]));
        $this->assertTrue(is_numeric($parts[1]));

        $floatFrame = $fn->getFrame($runtime->vmContext);
        $asFloat = new VMVariable();
        $asFloat->bool(true);
        $floatFrame->calledArgs = [$asFloat];
        $floatFrame->returnVar = new VMVariable();
        $fn->execute($floatFrame);
        $this->assertGreaterThan(946684800.0, $floatFrame->returnVar->resolveIndirect()->toFloat());
    }
}
