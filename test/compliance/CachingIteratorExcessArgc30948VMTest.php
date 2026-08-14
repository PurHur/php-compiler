<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: CachingIterator getFlags/hasNext/getCache excess argc → ArgumentCountError (#30948). */
final class CachingIteratorExcessArgc30948VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'cachingiterator_excess_argc_30948.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/cachingiterator_excess_argc_30948.phpt',
            'cachingiterator_excess_argc_30948.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
