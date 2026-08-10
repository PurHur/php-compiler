<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for natsort(). */
final class NatsortJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'natsort_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/natsort_jit.phpt',
            'natsort_jit.phpt'
        );
        yield 'natsort_preserve_keys_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/natsort_preserve_keys_jit.phpt',
            'natsort_preserve_keys_jit.phpt'
        );
        yield 'natsort_null_elements_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/natsort_null_elements_jit.phpt',
            'natsort_null_elements_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
