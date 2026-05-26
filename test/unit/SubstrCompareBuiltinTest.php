<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\substr_compare;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for substr_compare(). */
final class SubstrCompareBuiltinTest extends TestCase
{
    public function testDefaultLengthUsesHaystackRemainder(): void
    {
        $this->assertSame(1, $this->runCompare('abcde', 'bc', 1));
    }

    public function testExplicitLengthMatchesPrefix(): void
    {
        $this->assertSame(0, $this->runCompare('abcde', 'bc', 1, 2));
    }

    public function testCaseInsensitiveMatch(): void
    {
        $this->assertSame(0, $this->runCompare('abc', 'ABC', 0, 3, true));
    }

    public function testNegativeOffset(): void
    {
        $this->assertSame(1, $this->runCompare('abc', 'a', -3));
    }

    private function runCompare(
        string $haystack,
        string $needle,
        int $offset,
        ?int $length = null,
        bool $caseInsensitive = false
    ): int {
        $runtime = new Runtime();
        $fn = new substr_compare();
        $frame = $fn->getFrame($runtime->vmContext);
        $args = [new VMVariable(), new VMVariable(), new VMVariable()];
        $args[0]->string($haystack);
        $args[1]->string($needle);
        $args[2]->int($offset);
        if (null !== $length) {
            $lenVar = new VMVariable();
            $lenVar->int($length);
            $args[] = $lenVar;
        }
        if ($caseInsensitive) {
            while (\count($args) < 4) {
                $args[] = new VMVariable();
            }
            $ci = new VMVariable();
            $ci->bool(true);
            $args[] = $ci;
        }
        $frame->calledArgs = $args;
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $frame->returnVar->toInt();
    }
}
