<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: set_error_handler(..., null) soft Deprecated for $error_levels (#31465).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class SetErrorHandlerNullLevelsSoftJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'set_error_handler_null_levels_soft_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/set_error_handler_null_levels_soft_jit.phpt',
            'set_error_handler_null_levels_soft_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
