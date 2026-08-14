<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: mbstring/iconv excess argc → at-most ArgumentCountError (#30891). */
final class MbIconvExcessArgc30891JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_mb_iconv_30891_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_mb_iconv_30891_jit.phpt',
            'excess_argc_mb_iconv_30891_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
