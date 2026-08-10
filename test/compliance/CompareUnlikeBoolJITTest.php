<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * Dedicated provider — slash-free data-set name so --filter works (#29629).
 * Avoids the JITTest skip that matches substring "spaceship_array".
 */
final class CompareUnlikeBoolJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'compare_unlike_bool.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/compare_unlike_bool.phpt',
            'compare_unlike_bool.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
