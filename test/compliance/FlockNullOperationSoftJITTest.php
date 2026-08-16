<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: flock(null) soft Deprecated + ValueError (#31462).
 */
final class FlockNullOperationSoftJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'flock_null_operation_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/flock_null_operation_jit.phpt',
            'flock_null_operation_jit.phpt'
        );
        yield 'flock_null_operation_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/flock_null_operation_strict_jit.phpt',
            'flock_null_operation_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
