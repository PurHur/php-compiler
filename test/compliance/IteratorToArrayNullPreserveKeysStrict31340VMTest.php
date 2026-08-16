<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: iterator_to_array(..., null) $preserve_keys under strict_types → TypeError (#31340).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class IteratorToArrayNullPreserveKeysStrict31340VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'iterator_to_array_null_preserve_keys_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/iterator_to_array_null_preserve_keys_strict.phpt',
            'iterator_to_array_null_preserve_keys_strict.phpt'
        );
        yield 'iterator_to_array_null_preserve_keys_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/iterator_to_array_null_preserve_keys_soft_dep.phpt',
            'iterator_to_array_null_preserve_keys_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
