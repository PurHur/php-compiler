<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: duplicate closure use() name is compile-time fatal (#32153).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class ClosureUseDuplicate32153JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'closure_use_duplicate.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/closure_use_duplicate.phpt',
            'closure_use_duplicate.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
