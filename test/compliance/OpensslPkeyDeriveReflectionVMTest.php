<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for openssl_pkey_derive() Reflection stub types (#27685). */
final class OpensslPkeyDeriveReflectionVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'openssl_pkey_derive_reflection.phpt';
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
