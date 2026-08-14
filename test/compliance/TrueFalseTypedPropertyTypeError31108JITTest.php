<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: true/false typed property TypeError includes class::$prop (#31108). */
final class TrueFalseTypedPropertyTypeError31108JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'true_false_typed_property_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/true_false_typed_property_typeerror.phpt',
            'true_false_typed_property_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
