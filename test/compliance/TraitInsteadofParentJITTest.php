<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: trait insteadof parent class is Zend "not a trait" (#32129, zend_inheritance.c). */
final class TraitInsteadofParentJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'trait_insteadof_parent.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/trait_insteadof_parent.phpt',
            'trait_insteadof_parent.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
