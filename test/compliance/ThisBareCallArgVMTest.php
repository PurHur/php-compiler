<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/VMTest.php';

/** VM: bare $this as call arg is object (#28049). */
class ThisBareCallArgVMTest extends VMTest
{
    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/language/this_bare_call_arg.phpt';
        yield 'this_bare_call_arg' => self::parsePHPT(
            $path,
            'this_bare_call_arg.phpt'
        );
    }
}
