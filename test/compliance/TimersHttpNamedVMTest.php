<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for timer/http named parameters (#17092). */
final class TimersHttpNamedVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'timers_http_named_params.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/timers_http_named_params.phpt',
            'timers_http_named_params.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
