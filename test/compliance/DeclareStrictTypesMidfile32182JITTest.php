<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: mid-file declare(strict_types=1) is Zend compile fatal (#32182, zend_compile.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 *
 * @group llvm
 */
final class DeclareStrictTypesMidfile32182JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'declare_strict_types_midfile.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/declare_strict_types_midfile.phpt',
            'declare_strict_types_midfile.phpt'
        );
        yield 'declare_strict_types_nested.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/declare_strict_types_nested.phpt',
            'declare_strict_types_nested.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
