<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DOM create/attribute null TypeError under strict_types (#29985).
 *
 * @group llvm
 * @group jit
 */
final class DomCreateNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_create_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_create_null_strict_jit.phpt',
            'dom_create_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
