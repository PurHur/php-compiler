<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: mb_strtolower/mb_strtoupper excess argc → ArgumentCountError (#31036). */
final class MbStrtolowerStrtoupperExcessArgc31036JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_strtolower_strtoupper_excess_argc_31036_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_strtolower_strtoupper_excess_argc_31036_jit.phpt',
            'mb_strtolower_strtoupper_excess_argc_31036_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
