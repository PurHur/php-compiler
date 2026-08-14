<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: WeakMap::count() excess argc → ArgumentCountError (#31129).
 *
 * @group llvm
 */
final class WeakMapCountExcessArgc31129JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_weakmap_count_31129_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_weakmap_count_31129_jit.phpt',
            'excess_argc_weakmap_count_31129_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
