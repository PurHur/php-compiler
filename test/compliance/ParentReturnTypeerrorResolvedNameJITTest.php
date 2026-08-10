<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: :parent return TypeError names resolved parent class (#29912).
 *
 * Dedicated provider — same pattern as ParentReturnTypeerrorResolvedNameVMTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class ParentReturnTypeerrorResolvedNameJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'parent_return_typeerror_resolved_name.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/parent_return_typeerror_resolved_name.phpt',
            'parent_return_typeerror_resolved_name.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
