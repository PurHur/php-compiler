<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: ob_clean() excess argc → ArgumentCountError (#30525). */
final class ObCleanExcessArgc30525JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_ob_clean_30525_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_ob_clean_30525_jit.phpt',
            'excess_argc_ob_clean_30525_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
