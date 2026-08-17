<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/JITTest.php';

/** JIT: include/require from an instance method inherits class scope (#31913). */
class IncludeInheritsClassScopeJITTest extends JITTest
{
    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/language/include_inherits_class_scope.phpt';
        yield 'include_inherits_class_scope' => self::parsePHPT(
            $path,
            'include_inherits_class_scope.phpt'
        );
    }
}
