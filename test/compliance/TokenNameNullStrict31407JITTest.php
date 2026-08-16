<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: token_name(null) under strict_types → TypeError (#31407).
 */
final class TokenNameNullStrict31407JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'token_name_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/token_name_null_strict_jit.phpt',
            'token_name_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
