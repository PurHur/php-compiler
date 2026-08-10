<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: get-hook Error getTrace uses $prop::get (#29689, zend_property_hooks.c).
 *
 * Slash-free data-set name so --filter works (path-style JITTest names break the regex).
 */
final class PropertyHookGetErrorTraceZendNameJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'property_hook_get_error_trace_zend_name_jit.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/language/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
