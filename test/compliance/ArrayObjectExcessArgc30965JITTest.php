<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: ArrayObject remaining method excess argc → ArgumentCountError (#30965).
 *
 * @group llvm
 */
final class ArrayObjectExcessArgc30965JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'arrayobject_excess_argc_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/arrayobject_excess_argc.phpt',
            'arrayobject_excess_argc_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
