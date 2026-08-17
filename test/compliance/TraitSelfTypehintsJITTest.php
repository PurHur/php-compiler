<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: self param/return types inside a trait bind to the using class (#31744). */
final class TraitSelfTypehintsJITTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
