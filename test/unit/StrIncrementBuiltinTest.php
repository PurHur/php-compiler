<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\str_decrement;
use PHPCompiler\ext\standard\str_increment;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtins for str_increment() / str_decrement() (issue #3102). */
final class StrIncrementBuiltinTest extends TestCase
{
    public function testIncrementNine(): void
    {
        $this->assertSame('10', $this->runIncrement('9'));
    }

    public function testIncrementLetter(): void
    {
        $this->assertSame('B', $this->runIncrement('A'));
    }

    public function testIncrementCarryLower(): void
    {
        $this->assertSame('aa', $this->runIncrement('z'));
    }

    public function testIncrementCarryUpper(): void
    {
        $this->assertSame('AA', $this->runIncrement('Z'));
    }

    public function testIncrementMixed(): void
    {
        $this->assertSame('Ba', $this->runIncrement('Az'));
        $this->assertSame('B0', $this->runIncrement('A9'));
    }

    public function testDecrementTen(): void
    {
        $this->assertSame('9', $this->runDecrement('10'));
    }

    public function testDecrementLetter(): void
    {
        $this->assertSame('A', $this->runDecrement('B'));
    }

    public function testDecrementCarryLower(): void
    {
        $this->assertSame('z', $this->runDecrement('aa'));
    }

    public function testDecrementMixedCarry(): void
    {
        $this->assertSame('9z', $this->runDecrement('10a'));
    }

    public function testIncrementEmptyThrows(): void
    {
        $this->expectException(\ValueError::class);
        $this->runIncrement('');
    }

    public function testIncrementNonAlnumThrows(): void
    {
        $this->expectException(\ValueError::class);
        $this->runIncrement('a-b');
    }

    public function testDecrementZeroPrefixThrows(): void
    {
        $this->expectException(\ValueError::class);
        $this->runDecrement('0');
    }

    public function testDecrementUnderflowThrows(): void
    {
        $this->expectException(\ValueError::class);
        $this->runDecrement('A');
    }

    public function testDecrementSingleLowercaseUnderflowThrows(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('str_decrement(): Argument #1 ($string) "a" is out of decrement range');
        $this->runDecrement('a');
    }

    public function testDecrementEmptyThrows(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('str_decrement(): Argument #1 ($string) must not be empty');
        $this->runDecrement('');
    }

    /** Null soft-coerces then empty ValueError under PROFILE≥8.4 (#26264). */
    public function testNullSoftCoerceThenEmptyValueError(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            foreach ([new str_increment(), new str_decrement()] as $fn) {
                try {
                    $this->runBuiltinNull($fn);
                    $this->fail(get_class($fn).' should throw');
                } catch (\ValueError $e) {
                    $this->assertStringContainsString('must not be empty', $e->getMessage());
                }
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    private function runIncrement(string $value): string
    {
        return $this->runBuiltin(new str_increment(), $value);
    }

    private function runDecrement(string $value): string
    {
        return $this->runBuiltin(new str_decrement(), $value);
    }

    private function runBuiltin(str_increment|str_decrement $fn, string $value): string
    {
        $runtime = new Runtime();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->string($value);
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $frame->returnVar->toString();
    }

    private function runBuiltinNull(str_increment|str_decrement $fn): void
    {
        $runtime = new Runtime();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->null();
        $frame->calledArgs = [$arg];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
    }
}
