<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\soundex;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for soundex(). */
final class SoundexBuiltinTest extends TestCase
{
    public function testEmptyString(): void
    {
        $this->assertSame('0000', $this->runSoundex(''));
    }

    public function testEuler(): void
    {
        $this->assertSame('E460', $this->runSoundex('Euler'));
    }

    public function testNoLetters(): void
    {
        $this->assertSame('0000', $this->runSoundex('123'));
    }

    public function testLeadingDigitBeforeLetter(): void
    {
        $this->assertSame('A120', $this->runSoundex('1abc'));
    }

    private function runSoundex(string $str): string
    {
        $runtime = new Runtime();
        $fn = new soundex();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->string($str);
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $frame->returnVar->toString();
    }
}
