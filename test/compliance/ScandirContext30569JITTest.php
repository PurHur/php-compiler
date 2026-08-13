<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: scandir() optional context (#30569). */
final class ScandirContext30569JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'scandir_context_30569_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/scandir_context_30569_jit.phpt',
            'scandir_context_30569_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
