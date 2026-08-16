<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: define(..., null) case_insensitive → TypeError under strict_types (#31406). */
final class DefineNullCaseInsensitive31406JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'define_null_case_insensitive_31406.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/define_null_case_insensitive_31406.phpt',
            'define_null_case_insensitive_31406.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
