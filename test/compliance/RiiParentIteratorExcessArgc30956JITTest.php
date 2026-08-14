<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: RecursiveIteratorIterator / ParentIterator excess argc (#30956).
 *
 * @group llvm
 */
final class RiiParentIteratorExcessArgc30956JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'rii_parentiterator_excess_argc_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/rii_parentiterator_excess_argc.phpt',
            'rii_parentiterator_excess_argc_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
