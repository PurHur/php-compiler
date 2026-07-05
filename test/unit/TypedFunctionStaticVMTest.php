<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\CompilerVersion;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for typed function-local static variables (#9998, #16512). */
final class TypedFunctionStaticVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        if (CompilerVersion::supportsTypedFunctionStatic()) {
            yield 'typed_function_static.phpt' => self::parsePHPT(
                __DIR__.'/../compliance/cases/language/typed_function_static.phpt',
                'typed_function_static.phpt'
            );
        } else {
            yield 'typed_function_static_reference_profile.phpt' => self::parsePHPT(
                __DIR__.'/../compliance/cases/language/typed_function_static_reference_profile.phpt',
                'typed_function_static_reference_profile.phpt'
            );
        }
    }
}
