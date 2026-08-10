<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT: openssl_* null algo under strict_types TypeError (#29956). */
final class OpensslNullAlgoStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'openssl_null_algo_strict_types_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/openssl/openssl_null_algo_strict_types_jit.phpt',
            'openssl_null_algo_strict_types_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
