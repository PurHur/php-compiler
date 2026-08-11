<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: PHP 8.4 closure TypeError includes {closure:fn():line} (#30076).
 *
 * Dedicated provider — full VMTest discovery currently dies on unrelated
 * --EXTENSIONS-- phpts, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class ClosureTypeerrorLocation84VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'closure_typeerror_location_84.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/closure_typeerror_location_84.phpt',
            'closure_typeerror_location_84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
