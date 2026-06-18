<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for enum case instance method dispatch (#9658, Zend/zend_enum.c). */
final class EnumCaseMethodDispatchTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'enum_case_method_dispatch.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_case_method_dispatch.phpt',
            'enum_case_method_dispatch.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
