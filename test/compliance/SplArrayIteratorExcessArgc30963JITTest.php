<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: ArrayIterator / RecursiveArrayIterator residual excess argc (#30963). */
final class SplArrayIteratorExcessArgc30963JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'arrayiterator_excess_argc_30963_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/arrayiterator_excess_argc_30963_jit.phpt',
            'arrayiterator_excess_argc_30963_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
