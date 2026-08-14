<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: MultipleIterator method excess argc → ArgumentCountError (#30947).
 *
 * @group llvm
 */
final class MultipleIteratorExcessArgc30947JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'multipleiterator_excess_argc_30947_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/multipleiterator_excess_argc_30947.phpt',
            'multipleiterator_excess_argc_30947_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
