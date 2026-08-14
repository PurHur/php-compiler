<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: WeakMap ArrayAccess excess argc → ArgumentCountError (#30909).
 *
 * @group llvm
 */
final class WeakMapExcessArgc30909JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_weakmap_30909_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_weakmap_30909_jit.phpt',
            'excess_argc_weakmap_30909_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
