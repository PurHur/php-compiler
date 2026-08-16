<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: FilterIterator/NoRewindIterator excess argc (#31678). */
final class FilterNorewindRewindAce31678JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'spl_filter_norewind_rewind_ace_31678_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/spl_filter_norewind_rewind_ace_31678_jit.phpt',
            'spl_filter_norewind_rewind_ace_31678_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
