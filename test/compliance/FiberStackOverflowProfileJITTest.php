<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: FiberStackOverflow withheld on 8.2 reference profile (#26741).
 */
final class FiberStackOverflowProfileJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'fiber_stack_overflow_reference_profile.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/fiber_stack_overflow_reference_profile.phpt',
            'fiber_stack_overflow_reference_profile.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
