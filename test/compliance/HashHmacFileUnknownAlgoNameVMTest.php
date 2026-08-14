<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: hash_hmac_file() unknown algo ValueError cites hash_hmac_file() (#30646). */
final class HashHmacFileUnknownAlgoNameVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'hash_hmac_file_unknown_algo_name.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/hash_hmac_file_unknown_algo_name.phpt',
            'hash_hmac_file_unknown_algo_name.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
