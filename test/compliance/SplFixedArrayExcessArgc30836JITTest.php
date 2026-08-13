<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: SplFixedArray fromArray/toArray/setSize excess argc → ArgumentCountError (#30836).
 *
 * @group llvm
 */
final class SplFixedArrayExcessArgc30836JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_splfixedarray_30836_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_splfixedarray_30836_jit.phpt',
            'excess_argc_splfixedarray_30836_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
