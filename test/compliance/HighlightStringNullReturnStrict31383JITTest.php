<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: highlight_string(..., null) $return under strict_types → TypeError (#31383). */
final class HighlightStringNullReturnStrict31383JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'highlight_string_null_return_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/highlight_string_null_return_strict_jit.phpt',
            'highlight_string_null_return_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
