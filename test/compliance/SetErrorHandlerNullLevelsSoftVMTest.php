<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: set_error_handler(..., null) soft Deprecated for $error_levels (#31465).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class SetErrorHandlerNullLevelsSoftVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'set_error_handler_null_levels_soft.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/set_error_handler_null_levels_soft.phpt',
            'set_error_handler_null_levels_soft.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
