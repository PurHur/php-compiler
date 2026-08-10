<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: abstract modifier on class/interface constant (#30011, zend_compile.c).
 *
 * Dedicated provider — same pattern as AbstractClassConstModifierFatal30011VMTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class AbstractClassConstModifierFatal30011JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'abstract_class_const_modifier_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/abstract_class_const_modifier_fatal.phpt',
            'abstract_class_const_modifier_fatal.phpt'
        );
        yield 'abstract_interface_const_modifier_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/abstract_interface_const_modifier_fatal.phpt',
            'abstract_interface_const_modifier_fatal.phpt'
        );
        yield 'abstract_typed_class_const_modifier_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/abstract_typed_class_const_modifier_fatal.phpt',
            'abstract_typed_class_const_modifier_fatal.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
