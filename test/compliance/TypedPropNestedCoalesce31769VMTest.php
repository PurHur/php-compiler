<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: nested ?? concat/call-arg on uninitialized typed properties is BP_VAR_IS (#31769).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class TypedPropNestedCoalesce31769VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'typed_prop_nested_coalesce_31769.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/typed_prop_nested_coalesce_31769.phpt',
            'typed_prop_nested_coalesce_31769.phpt'
        );
        yield 'typed_prop_coalesce_uninit_31146.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/typed_prop_coalesce_uninit_31146.phpt',
            'typed_prop_coalesce_uninit_31146.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
