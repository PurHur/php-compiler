<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: rename() named from:/to: arguments (#23348).
 */
final class RenameNamedParams23348JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'rename_named_params_23348_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/rename_named_params_23348_jit.phpt',
            'rename_named_params_23348_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
