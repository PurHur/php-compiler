<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: include/require from an instance method inherits class scope (#31913).
 */
require_once __DIR__.'/../BaseTest.php';

final class IncludeInheritsClassScopeVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'include_inherits_class_scope.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/include_inherits_class_scope.phpt',
            'include_inherits_class_scope.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
