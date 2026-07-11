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
}
