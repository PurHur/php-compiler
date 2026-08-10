<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: self param TypeError names resolved class (#29930).
 *
 * Dedicated provider — same pattern as SelfParamTypeerrorResolvedNameVMTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class SelfParamTypeerrorResolvedNameJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'self_param_typeerror_resolved_name.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/self_param_typeerror_resolved_name.phpt',
            'self_param_typeerror_resolved_name.phpt'
        );
        yield 'parent_param_typeerror_resolved_name.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/parent_param_typeerror_resolved_name.phpt',
            'parent_param_typeerror_resolved_name.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
