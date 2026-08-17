<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/JITTest.php';

/** JIT: eval() from an instance method inherits $this (#31902). */
class EvalInheritsThisJITTest extends JITTest
{
    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/language/eval_inherits_this.phpt';
        yield 'eval_inherits_this' => self::parsePHPT(
            $path,
            'eval_inherits_this.phpt'
        );
    }
}
