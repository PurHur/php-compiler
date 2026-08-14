<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: ArrayObject ArrayAccess excess argc → ArgumentCountError (#31001).
 *
 * @group llvm
 */
final class ArrayObjectOffsetExcessArgc31001JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'arrayobject_offset_excess_argc_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/arrayobject_offset_excess_argc.phpt',
            'arrayobject_offset_excess_argc_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
