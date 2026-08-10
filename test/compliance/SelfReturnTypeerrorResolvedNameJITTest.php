<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: :self return TypeError names resolved class (#29911).
 *
 * Dedicated provider — same pattern as SelfReturnTypeerrorResolvedNameVMTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class SelfReturnTypeerrorResolvedNameJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'self_return_typeerror_resolved_name.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/self_return_typeerror_resolved_name.phpt',
            'self_return_typeerror_resolved_name.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
