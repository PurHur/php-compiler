<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: filter_id() excess argc → ArgumentCountError (#30594). */
final class FilterIdExcessArgc30594VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_filter_id_30594.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_filter_id_30594.phpt',
            'excess_argc_filter_id_30594.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
