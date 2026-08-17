<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: instanceof static inside a trait late-binds to $this class (#31746). */
final class TraitInstanceofStaticJITTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
