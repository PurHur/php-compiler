<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: ctype_* ArgumentCountError wording (#30602). */
final class CtypeExcessArgc30602JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_ctype_30602_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_ctype_30602_jit.phpt',
            'excess_argc_ctype_30602_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
