<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\str_word_count;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** VM builtin for str_word_count() (issue #2382). */
final class StrWordCountBuiltinTest extends TestCase
{
    public function testFormatZeroMatchesPhpSubset(): void
    {
        $s = "Hello fri3nd, you are looking good today!";
        $this->assertSame(8, VmString::str_word_count($s));
        $this->assertSame(3, VmString::str_word_count('a b c'));
        $this->assertSame(0, VmString::str_word_count(''));
        $this->assertSame(1, VmString::str_word_count("don't"));
        $this->assertSame(2, VmString::str_word_count('fri3nd'));
    }

    public function testFormatOneReturnsWordList(): void
    {
        $words = VmString::str_word_count('a b c', 1);
        $this->assertSame(['a', 'b', 'c'], $words);
    }

    public function testFormatTwoReturnsPositionMap(): void
    {
        $map = VmString::str_word_count('a b', 2);
        $this->assertSame([0 => 'a', 2 => 'b'], $map);
    }

    public function testBuiltinExecuteFormatZero(): void
    {
        $runtime = new Runtime();
        $fn = new str_word_count();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new \PHPCompiler\VM\Variable();
        $arg->string('one two');
        $frame->calledArgs = [$arg];
        $frame->returnVar = new \PHPCompiler\VM\Variable();
        $fn->execute($frame);
        $this->assertSame(2, $frame->returnVar->resolveIndirect()->toInt());
    }
}
