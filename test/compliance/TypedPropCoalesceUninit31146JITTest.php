<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: ?? / ??= on uninitialized typed properties is BP_VAR_IS (#31146).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class TypedPropCoalesceUninit31146JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'typed_prop_coalesce_uninit_31146.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/typed_prop_coalesce_uninit_31146.phpt',
            'typed_prop_coalesce_uninit_31146.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
