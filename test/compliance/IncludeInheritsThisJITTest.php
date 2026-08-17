<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/JITTest.php';

/** JIT: include/require from an instance method inherits $this (#31903). */
class IncludeInheritsThisJITTest extends JITTest
{
    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/language/include_inherits_this.phpt';
        yield 'include_inherits_this' => self::parsePHPT(
            $path,
            'include_inherits_this.phpt'
        );
    }
}
