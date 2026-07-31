<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance: unset() on declared public property routes through magic (#25810).
 */
final class UnsetDeclaredPropertyMagicTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'unset_declared_property_magic.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/unset_declared_property_magic.phpt',
            'unset_declared_property_magic.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
