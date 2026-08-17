<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: copy() named from:/to: arguments (#23347).
 */
final class CopyNamedParams23347JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'copy_named_params_23347_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/copy_named_params_23347_jit.phpt',
            'copy_named_params_23347_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
