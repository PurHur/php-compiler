<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance for unset() on static properties (#2256).
 */
final class UnsetStaticPropertyTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'unset_static_property.phpt' => self::parsePHPT(
            __DIR__.'/../compliance/cases/language/unset_static_property.phpt',
            'unset_static_property.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
