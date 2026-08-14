<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: SplObjectStorage attach/contains/detach/setInfo excess argc → ArgumentCountError (#30954). */
final class SplObjectStorageExcessArgc30954VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'splobjectstorage_excess_argc_30954.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/splobjectstorage_excess_argc_30954.phpt',
            'splobjectstorage_excess_argc_30954.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
