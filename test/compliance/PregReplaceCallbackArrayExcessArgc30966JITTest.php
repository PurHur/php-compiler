<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: preg_replace_callback_array() excess argc → ArgumentCountError (#30966). */
final class PregReplaceCallbackArrayExcessArgc30966JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'preg_replace_callback_array_excess_argc_30966_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/pcre/preg_replace_callback_array_excess_argc_30966_jit.phpt',
            'preg_replace_callback_array_excess_argc_30966_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
