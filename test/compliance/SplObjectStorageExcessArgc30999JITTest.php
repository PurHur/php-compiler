<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: SplObjectStorage residual methods excess argc → ArgumentCountError (#30999).
 *
 * @group llvm
 */
final class SplObjectStorageExcessArgc30999JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'splobjectstorage_excess_argc_30999_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/splobjectstorage_excess_argc_30999.phpt',
            'splobjectstorage_excess_argc_30999_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
