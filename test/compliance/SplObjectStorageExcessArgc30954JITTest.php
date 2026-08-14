<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: SplObjectStorage attach/contains/detach/setInfo excess argc → ArgumentCountError (#30954).
 *
 * @group llvm
 */
final class SplObjectStorageExcessArgc30954JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'splobjectstorage_excess_argc_30954_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/splobjectstorage_excess_argc_30954.phpt',
            'splobjectstorage_excess_argc_30954_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
