<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DOMDocument::getElementById(null) TypeError under strict_types (#29942).
 *
 * @group llvm
 * @group jit
 */
final class DomGetElementByIdNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_getelementbyid_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_getelementbyid_null_strict_jit.phpt',
            'dom_getelementbyid_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
