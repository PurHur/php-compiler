<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for typed function-local static variables (#10084). */
final class StaticTypedLocalVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        yield 'static_typed_local.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/static_typed_local.phpt',
            'static_typed_local.phpt'
        );
    }
}
