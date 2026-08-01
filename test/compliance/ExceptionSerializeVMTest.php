<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for Exception/Error serialize round-trip (#26673). */
final class ExceptionSerializeVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'exception_serialize_roundtrip.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/exception_serialize_roundtrip.phpt',
            'exception_serialize_roundtrip.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
