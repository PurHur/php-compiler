<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: RecursiveIteratorIterator/GlobIterator residual excess argc (#31010). */
final class RiiGlobExcessArgc31010JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'spl_rii_glob_excess_argc_31010_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/spl_rii_glob_excess_argc_31010_jit.phpt',
            'spl_rii_glob_excess_argc_31010_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
