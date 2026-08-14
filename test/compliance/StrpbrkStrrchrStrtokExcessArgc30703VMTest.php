<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: strpbrk/strrchr/strtok excess argc → ArgumentCountError (#30703). */
final class StrpbrkStrrchrStrtokExcessArgc30703VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_strpbrk_strrchr_strtok_30703.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_strpbrk_strrchr_strtok_30703.phpt',
            'excess_argc_strpbrk_strrchr_strtok_30703.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
