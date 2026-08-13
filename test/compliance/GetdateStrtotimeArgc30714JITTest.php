<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: getdate/strtotime ArgumentCountError wording (#30714). */
final class GetdateStrtotimeArgc30714JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_getdate_strtotime_30714_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_getdate_strtotime_30714_jit.phpt',
            'excess_argc_getdate_strtotime_30714_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
