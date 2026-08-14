<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: CachingIterator getFlags/hasNext/getCache excess argc → ArgumentCountError (#30948).
 *
 * @group llvm
 */
final class CachingIteratorExcessArgc30948JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'cachingiterator_excess_argc_30948_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/cachingiterator_excess_argc_30948.phpt',
            'cachingiterator_excess_argc_30948_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
