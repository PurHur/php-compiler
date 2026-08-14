<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: date static excess argc ArgumentCountError (#30898). */
final class DateStaticExcessArgc30898VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_date_static_30898.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_date_static_30898.phpt',
            'excess_argc_date_static_30898.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
