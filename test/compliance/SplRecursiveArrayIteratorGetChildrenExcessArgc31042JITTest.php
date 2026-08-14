<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: RecursiveArrayIterator::getChildren excess argc (#31042). */
final class SplRecursiveArrayIteratorGetChildrenExcessArgc31042JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'recursive_arrayiterator_getchildren_excess_argc_31042_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/recursive_arrayiterator_getchildren_excess_argc_31042_jit.phpt',
            'recursive_arrayiterator_getchildren_excess_argc_31042_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
