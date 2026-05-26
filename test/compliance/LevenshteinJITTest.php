<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for levenshtein(). */
final class LevenshteinJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'levenshtein_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/levenshtein_jit.phpt',
            'levenshtein_jit.phpt'
        );
    }
}
