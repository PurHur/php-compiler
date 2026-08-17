<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: assign undeclared prop with __get and no __set (#31949).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class DynamicPropAssignWithGetNoSet31949JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dynamic_prop_assign_with_get_no_set.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/dynamic_prop_assign_with_get_no_set.phpt',
            'dynamic_prop_assign_with_get_no_set.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
