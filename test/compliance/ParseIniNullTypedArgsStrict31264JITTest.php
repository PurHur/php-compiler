<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: parse_ini_string/file null typed args under strict_types → TypeError (#31264). */
final class ParseIniNullTypedArgsStrict31264JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'parse_ini_null_typed_args_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/parse_ini_null_typed_args_strict_jit.phpt',
            'parse_ini_null_typed_args_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
