<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: object vs string/int/bool === / !== (#32523 leftover of #32515).
 *
 * @group llvm
 */
final class ObjectStringIdentical32523JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'object_string_identical.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/object_string_identical.phpt',
            'object_string_identical.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
