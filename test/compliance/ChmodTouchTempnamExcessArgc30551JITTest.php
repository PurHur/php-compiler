<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: chmod/touch/tempnam excess argc → ArgumentCountError (#30551). */
final class ChmodTouchTempnamExcessArgc30551JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_chmod_touch_tempnam_30551_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_chmod_touch_tempnam_30551_jit.phpt',
            'excess_argc_chmod_touch_tempnam_30551_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
