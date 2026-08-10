<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance: openssl_* null algo under strict_types TypeError (#29956). */
final class OpensslNullAlgoStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'openssl_null_algo_strict_types.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/openssl/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
