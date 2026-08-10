<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: hash_hmac_file(null) TypeError under strict_types (#29890, ext/hash/hash.c). */
final class HashHmacFileNullAlgoStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'hash_hmac_file_null_algo_strict_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/hash_hmac_file_null_algo_strict_typeerror.phpt',
            'hash_hmac_file_null_algo_strict_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
