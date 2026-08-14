<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance: static:: forbidden in compile-time constants (#31145, Zend/zend_compile.c).
 */
final class StaticScopeCompileTimeConstJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'static_scope_class_const_reject.phpt',
            'static_scope_param_default_reject.phpt',
            'static_scope_property_default_reject.phpt',
            'static_scope_self_parent_const_ok.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/language/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
