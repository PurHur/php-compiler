<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: parent typehint inside a trait binds to the using class parent (#31747). */
final class TraitParentTypehintJITTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
