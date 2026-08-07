<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance for Throwable ctor message/code coercion (#18189, #28797, Zend/zend_exceptions.c).
 */
final class ExceptionCtorVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'exception_ctor_string.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/exception_ctor_string.phpt',
            'exception_ctor_string.phpt'
        );
        yield 'exception_ctor_string_coerce.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/exception_ctor_string_coerce.phpt',
            'exception_ctor_string_coerce.phpt'
        );
        yield 'exception_ctor_enum_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/exception_ctor_enum_typeerror.phpt',
            'exception_ctor_enum_typeerror.phpt'
        );
        yield 'exception_ctor_code_coerce.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/exception_ctor_code_coerce.phpt',
            'exception_ctor_code_coerce.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
