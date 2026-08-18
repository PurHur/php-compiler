<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: (int)/(float) inline call-arg is sent (#32293).
 *
 * @group llvm
 */
final class CastIntFloatCallArg32293JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'cast_int_float_inline_call_arg.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/cast_int_float_inline_call_arg.phpt',
            'cast_int_float_inline_call_arg.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
