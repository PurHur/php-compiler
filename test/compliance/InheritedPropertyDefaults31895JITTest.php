<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: inherited property defaults on subclass new (#31895).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class InheritedPropertyDefaults31895JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'inherited_property_defaults_31895.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/inherited_property_defaults_31895.phpt',
            'inherited_property_defaults_31895.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
