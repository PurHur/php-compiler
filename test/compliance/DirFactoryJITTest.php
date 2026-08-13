<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: dir() Directory factory (#30757).
 *
 * @group llvm
 * @group jit
 */
final class DirFactoryJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dir_factory_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dir_factory_jit.phpt',
            'dir_factory_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
