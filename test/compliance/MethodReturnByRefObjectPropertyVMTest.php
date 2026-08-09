<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: by-ref method return of $this->prop binds live storage (#29456). */
final class MethodReturnByRefObjectPropertyVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'method_return_by_ref_object_property.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/method_return_by_ref_object_property.phpt',
            'method_return_by_ref_object_property.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
