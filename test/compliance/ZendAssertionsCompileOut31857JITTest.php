<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: zend.assertions=-1 compiles assert() out — no side effects (#31857).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class ZendAssertionsCompileOut31857JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'zend_assertions_compile_out_side_effects.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/zend_assertions_compile_out_side_effects.phpt',
            'zend_assertions_compile_out_side_effects.phpt'
        );
        yield 'zend_assertions_side_effects_enabled.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/zend_assertions_side_effects_enabled.phpt',
            'zend_assertions_side_effects_enabled.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
