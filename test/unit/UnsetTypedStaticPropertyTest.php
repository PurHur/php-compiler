<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance for unset() on typed static properties (#6648).
 */
final class UnsetTypedStaticPropertyTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'unset_typed_static_property.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/unset_typed_static_property.phpt',
            'unset_typed_static_property.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
