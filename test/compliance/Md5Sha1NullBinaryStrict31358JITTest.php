<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: md5/sha1(..., null) $binary under strict_types → TypeError (#31358). */
final class Md5Sha1NullBinaryStrict31358JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'md5_sha1_null_binary_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/md5_sha1_null_binary_strict_jit.phpt',
            'md5_sha1_null_binary_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
