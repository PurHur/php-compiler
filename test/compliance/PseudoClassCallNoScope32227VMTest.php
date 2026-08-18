<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: self/parent/static::method in a free function is Zend compile fatal (#32227, zend_compile.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class PseudoClassCallNoScope32227VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'static_call_no_class_scope_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/static_call_no_class_scope_compile_fatal.phpt',
            'static_call_no_class_scope_compile_fatal.phpt'
        );
        yield 'self_call_no_class_scope_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/self_call_no_class_scope_compile_fatal.phpt',
            'self_call_no_class_scope_compile_fatal.phpt'
        );
        yield 'parent_call_no_class_scope_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/parent_call_no_class_scope_compile_fatal.phpt',
            'parent_call_no_class_scope_compile_fatal.phpt'
        );
        yield 'static_class_no_class_scope_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/static_class_no_class_scope_compile_fatal.phpt',
            'static_class_no_class_scope_compile_fatal.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
