<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/VMTest.php';

/** VM: self::/static:: non-static from instance binds $this (#28050). */
class SelfStaticInstanceMethodCallVMTest extends VMTest
{
    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/language/self_static_instance_method_call.phpt';
        yield 'self_static_instance_method_call' => self::parsePHPT(
            $path,
            'self_static_instance_method_call.phpt'
        );
    }
}
