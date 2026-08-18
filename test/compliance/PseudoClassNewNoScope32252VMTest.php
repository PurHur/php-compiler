<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: new self/parent/static in a free function is Zend compile fatal (#32252, zend_compile.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class PseudoClassNewNoScope32252VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'new_static_no_class_scope_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/new_static_no_class_scope_compile_fatal.phpt',
            'new_static_no_class_scope_compile_fatal.phpt'
        );
        yield 'new_self_no_class_scope_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/new_self_no_class_scope_compile_fatal.phpt',
            'new_self_no_class_scope_compile_fatal.phpt'
        );
        yield 'new_parent_no_class_scope_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/new_parent_no_class_scope_compile_fatal.phpt',
            'new_parent_no_class_scope_compile_fatal.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
