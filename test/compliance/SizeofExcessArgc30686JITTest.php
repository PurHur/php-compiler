<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: sizeof() excess argc -> ArgumentCountError cites sizeof() (#30686). */
final class SizeofExcessArgc30686JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_sizeof_30686_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_sizeof_30686_jit.phpt',
            'excess_argc_sizeof_30686_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
