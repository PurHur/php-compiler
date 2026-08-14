<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: MultipleIterator method excess argc → ArgumentCountError (#30947). */
final class MultipleIteratorExcessArgc30947VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'multipleiterator_excess_argc_30947.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/multipleiterator_excess_argc_30947.phpt',
            'multipleiterator_excess_argc_30947.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
