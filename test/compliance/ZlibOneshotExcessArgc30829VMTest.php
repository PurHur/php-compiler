<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: zlib one-shot/file helpers ArgumentCountError wording (#30829). */
final class ZlibOneshotExcessArgc30829VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_zlib_oneshot_30829.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_zlib_oneshot_30829.phpt',
            'excess_argc_zlib_oneshot_30829.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
