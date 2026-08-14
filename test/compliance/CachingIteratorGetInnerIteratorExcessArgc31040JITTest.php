<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: CachingIterator::getInnerIterator() excess argc → ArgumentCountError (#31040). */
final class CachingIteratorGetInnerIteratorExcessArgc31040JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'cachingiterator_getinneriterator_excess_argc_31040.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/cachingiterator_getinneriterator_excess_argc_31040.phpt',
            'cachingiterator_getinneriterator_excess_argc_31040.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
