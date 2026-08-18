<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: gettext family Zend stub names + named args (#24375).
 */
final class GettextNamedParams24375JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'gettext_named_params_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gettext_named_params_jit.phpt',
            'gettext_named_params_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
