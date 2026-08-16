<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: array_slice/chunk/reverse(..., null) $preserve_keys — soft DEP + strict TypeError (#31442).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ArrayPreserveKeysNull31442VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_preserve_keys_null_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_preserve_keys_null_soft_dep.phpt',
            'array_preserve_keys_null_soft_dep.phpt'
        );
        yield 'array_preserve_keys_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_preserve_keys_null_strict.phpt',
            'array_preserve_keys_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
