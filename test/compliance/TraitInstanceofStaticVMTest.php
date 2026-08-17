<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: instanceof static inside a trait late-binds to $this class (#31746).
 *
 * Dedicated provider — full VMTest discovery currently dies on unrelated
 * --EXTENSIONS-- phpts, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class TraitInstanceofStaticVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'trait_instanceof_static.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/trait_instanceof_static.phpt',
            'trait_instanceof_static.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
