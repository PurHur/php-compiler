<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for OpenSSL object class finality (ext/openssl/openssl.stub.php; #28370). */
final class OpensslObjectFinalVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'openssl_object_classes_final.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/openssl/openssl_object_classes_final.phpt',
            'openssl_object_classes_final.phpt'
        );
        yield 'openssl_object_classes_extend_final.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/openssl/openssl_object_classes_extend_final.phpt',
            'openssl_object_classes_extend_final.phpt'
        );
    }
}
