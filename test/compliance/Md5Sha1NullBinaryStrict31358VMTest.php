<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: md5/sha1(..., null) $binary under strict_types → TypeError (#31358).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class Md5Sha1NullBinaryStrict31358VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'md5_sha1_null_binary_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/md5_sha1_null_binary_strict.phpt',
            'md5_sha1_null_binary_strict.phpt'
        );
        yield 'md5_sha1_null_binary_soft_dep.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/md5_sha1_null_binary_soft_dep.phpt',
            'md5_sha1_null_binary_soft_dep.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
