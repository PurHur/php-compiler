<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\soundex;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for soundex(). */
final class SoundexBuiltinTest extends TestCase
{
    public function testEuler(): void
    {
        $this->assertSame('E460', $this->runSoundex('Euler'));
    }

    public function testWashington(): void
    {
        $this->assertSame('W252', $this->runSoundex('Washington'));
    }

    public function testEmpty(): void
    {
        $this->assertSame('0000', $this->runSoundex(''));
    }

    public function testNonAlpha(): void
    {
        $this->assertSame('0000', $this->runSoundex('123'));
    }

    private function runSoundex(string $value): string
    {
        $runtime = new Runtime();
        $fn = new soundex();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->string($value);
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $frame->returnVar->toString();
    }
}
