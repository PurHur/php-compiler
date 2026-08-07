<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for HashContext final (ext/hash/hash.stub.php; #28384). */
final class HashContextFinalVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'hash_context_class_final.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/hash/hash_context_class_final.phpt',
            'hash_context_class_final.phpt'
        );
        yield 'hash_context_class_extend_final.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/hash/hash_context_class_extend_final.phpt',
            'hash_context_class_extend_final.phpt'
        );
    }
}
