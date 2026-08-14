<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: strpbrk/strrchr/strtok excess argc → ArgumentCountError (#30703). */
final class StrpbrkStrrchrStrtokExcessArgc30703JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_strpbrk_strrchr_strtok_30703_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_strpbrk_strrchr_strtok_30703_jit.phpt',
            'excess_argc_strpbrk_strrchr_strtok_30703_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
