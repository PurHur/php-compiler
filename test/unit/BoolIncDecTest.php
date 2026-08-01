<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM ++/-- on bool operands (issue #7058, re-#4727) + PROFILE=8.4 no-effect warnings (#26378). */
final class BoolIncDecTest extends TestCase
{
    private ?string $savedProfile = null;

    protected function setUp(): void
    {
        $raw = getenv('PHP_COMPILER_PROFILE');
        $this->savedProfile = false === $raw ? null : $raw;
    }

    protected function tearDown(): void
    {
        if (null === $this->savedProfile) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->savedProfile);
        }
    }

    public function testIncrementTrueIsNoOp(): void
    {
        $v = new VMVariable();
        $v->bool(true);
        $v->applyIncrement();
        $this->assertSame(VMVariable::TYPE_BOOLEAN, $v->type);
        $this->assertTrue($v->toBool());
    }

    public function testIncrementFalseIsNoOp(): void
    {
        $v = new VMVariable();
        $v->bool(false);
        $v->applyIncrement();
        $this->assertSame(VMVariable::TYPE_BOOLEAN, $v->type);
        $this->assertFalse($v->toBool());
    }

    public function testDecrementTrueIsNoOp(): void
    {
        $v = new VMVariable();
        $v->bool(true);
        $v->applyDecrement();
        $this->assertSame(VMVariable::TYPE_BOOLEAN, $v->type);
        $this->assertTrue($v->toBool());
    }

    public function testDecrementFalseIsNoOp(): void
    {
        $v = new VMVariable();
        $v->bool(false);
        $v->applyDecrement();
        $this->assertSame(VMVariable::TYPE_BOOLEAN, $v->type);
        $this->assertFalse($v->toBool());
    }

    public function testIncDecNoEffectWarningMessage(): void
    {
        $this->assertSame(
            'Increment on type bool has no effect, this will change in the next major version of PHP',
            VMVariable::incDecNoEffectWarningMessage('Increment', 'bool')
        );
        $this->assertSame(
            'Decrement on type null has no effect, this will change in the next major version of PHP',
            VMVariable::incDecNoEffectWarningMessage('Decrement', 'null')
        );
    }

    public function testSupportsIncDecNoEffectWarningProfile84(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $this->assertTrue(CompilerVersion::supportsIncDecNoEffectWarning());
        putenv('PHP_COMPILER_PROFILE');
        $this->assertFalse(CompilerVersion::supportsIncDecNoEffectWarning());
    }
}
