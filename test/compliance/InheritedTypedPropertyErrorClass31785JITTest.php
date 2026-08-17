<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: inherited typed property Error cites declaring class (#31785).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class InheritedTypedPropertyErrorClass31785JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'inherited_typed_property_error_class_31785.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/inherited_typed_property_error_class_31785.phpt',
            'inherited_typed_property_error_class_31785.phpt'
        );
        yield 'typed_property_uninit_inherited.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/typed_property_uninit_inherited.phpt',
            'typed_property_uninit_inherited.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
