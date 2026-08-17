<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: parent typehint inside a trait binds to the using class parent (#31747).
 *
 * Dedicated provider — full VMTest discovery currently dies on unrelated
 * --EXTENSIONS-- phpts, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class TraitParentTypehintVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'trait_parent_typehint.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/trait_parent_typehint.phpt',
            'trait_parent_typehint.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
