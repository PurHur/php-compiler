<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for dirname() wrapper paths (#11026). */
final class DirnameJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dirname_phar_wrapper_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dirname_phar_wrapper_jit.phpt',
            'dirname_phar_wrapper_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
