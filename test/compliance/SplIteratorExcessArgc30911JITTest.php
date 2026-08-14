<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: ArrayIterator/SplStack excess argc → ArgumentCountError (#30911).
 *
 * @group llvm
 */
final class SplIteratorExcessArgc30911JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_spl_iterator_30911_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_spl_iterator_30911_jit.phpt',
            'excess_argc_spl_iterator_30911_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
