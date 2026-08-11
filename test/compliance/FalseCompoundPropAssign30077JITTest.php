<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: compound assign on false skips read Warning (#30077, zend_vm_def.h).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class FalseCompoundPropAssign30077JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'false_compound_prop_assign.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/false_compound_prop_assign.phpt',
            'false_compound_prop_assign.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
