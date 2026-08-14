<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: SPL iterator wrappers excess argc (#30949). */
final class SplIteratorExcessArgc30949JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_spl_iterator_30949_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_spl_iterator_30949_jit.phpt',
            'excess_argc_spl_iterator_30949_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
