<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: hash(..., null) $binary under strict_types → TypeError (#31288).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class HashNullBinaryStrict31288VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'hash_null_binary_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/hash_null_binary_strict.phpt',
            'hash_null_binary_strict.phpt'
        );
        yield 'hash_null_binary_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/hash_null_binary_soft_dep.phpt',
            'hash_null_binary_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
