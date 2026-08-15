<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: getservbyname/getservbyport ArgumentCountError wording (#30567). */
final class GetservExcessArgc30567JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_getserv_30567_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_getserv_30567_jit.phpt',
            'excess_argc_getserv_30567_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
