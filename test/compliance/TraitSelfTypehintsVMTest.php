<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: self param/return types inside a trait bind to the using class (#31744).
 *
 * Dedicated provider — full VMTest discovery currently dies on unrelated
 * --EXTENSIONS-- phpts, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class TraitSelfTypehintsVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'trait_self_typehints.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/trait_self_typehints.phpt',
            'trait_self_typehints.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
