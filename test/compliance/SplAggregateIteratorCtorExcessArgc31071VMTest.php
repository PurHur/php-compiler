<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: SPL aggregate iterator constructor excess argc (#31071). */
final class SplAggregateIteratorCtorExcessArgc31071VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'spl_aggregate_iterator_ctor_excess_argc_31071.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/spl_aggregate_iterator_ctor_excess_argc_31071.phpt',
            'spl_aggregate_iterator_ctor_excess_argc_31071.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
