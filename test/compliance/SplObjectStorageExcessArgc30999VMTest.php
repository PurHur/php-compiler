<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: SplObjectStorage residual methods excess argc → ArgumentCountError (#30999). */
final class SplObjectStorageExcessArgc30999VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'splobjectstorage_excess_argc_30999.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/splobjectstorage_excess_argc_30999.phpt',
            'splobjectstorage_excess_argc_30999.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
