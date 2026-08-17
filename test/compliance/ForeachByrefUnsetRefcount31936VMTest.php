<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: foreach-by-ref unset($v) clears IS_REFERENCE markers (#31936).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ForeachByrefUnsetRefcount31936VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'foreach_byref_unset_refcount_31936.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/foreach_byref_unset_refcount_31936.phpt',
            'foreach_byref_unset_refcount_31936.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
