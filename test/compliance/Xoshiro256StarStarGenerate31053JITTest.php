<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: Xoshiro256StarStar::generate() 8-byte stream matches Zend (#31053).
 *
 * @group llvm
 * @group jit
 */
final class Xoshiro256StarStarGenerate31053JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'xoshiro256starstar_generate_stream.phpt' => self::parsePHPT(
            __DIR__.'/cases/random/xoshiro256starstar_generate_stream.phpt',
            'xoshiro256starstar_generate_stream.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
