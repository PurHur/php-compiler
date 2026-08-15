<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: var_dump prints uninitialized(T) for typed props (#31147, ext/standard/var.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class VarDumpUninitTypedProperty31147JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'var_dump_uninit_typed_property.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/var_dump_uninit_typed_property.phpt',
            'var_dump_uninit_typed_property.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
