<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: filter_list() excess argc → ArgumentCountError (#30675). */
final class FilterListExcessArgc30675VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_filter_list_30675.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_filter_list_30675.phpt',
            'excess_argc_filter_list_30675.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
