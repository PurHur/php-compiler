<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: &$obj->typed on uninitialized non-nullable property throws Error (#31771).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class UninitTypedPropertyByref31771JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'uninit_typed_property_byref.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/uninit_typed_property_byref.phpt',
            'uninit_typed_property_byref.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
