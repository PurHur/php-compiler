<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: ArrayObject/SplFileInfo/DirectoryIterator excess argc → ArgumentCountError (#30837).
 *
 * @group llvm
 */
final class SplMethodsExcessArgc30837JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_spl_methods_30837_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_spl_methods_30837_jit.phpt',
            'excess_argc_spl_methods_30837_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
