<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance: unset() on untyped declared property → Warning + NULL (#22021).
 */
final class UnsetUntypedDeclaredPropertyTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'unset_untyped_declared_property.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/unset_untyped_declared_property.phpt',
            'unset_untyped_declared_property.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
