<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: trait insteadof parent class is Zend "not a trait" (#32129, zend_inheritance.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class TraitInsteadofParentVMTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
