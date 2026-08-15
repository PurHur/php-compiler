<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: htmlspecialchars/htmlentities(null $flags) TypeError under strict_types (#31212). */
final class HtmlspecialcharsNullFlagsStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'htmlspecialchars_null_flags_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/htmlspecialchars_null_flags_strict_jit.phpt',
            'htmlspecialchars_null_flags_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
