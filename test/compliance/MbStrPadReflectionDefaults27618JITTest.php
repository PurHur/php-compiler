<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: mb_str_pad Reflection types + defaults (#27618, re-#23805, mbstring.stub.php). */
final class MbStrPadReflectionDefaults27618JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'mb_str_pad_reflection_defaults_forward84.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/mb_str_pad_reflection_defaults_forward84.phpt',
            'mb_str_pad_reflection_defaults_forward84.phpt'
        );
        yield 'named_args_mb_str_pad_lcfirst_ucfirst.phpt' => self::parsePHPT(
            __DIR__.'/cases/mbstring/named_args_mb_str_pad_lcfirst_ucfirst.phpt',
            'named_args_mb_str_pad_lcfirst_ucfirst.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
