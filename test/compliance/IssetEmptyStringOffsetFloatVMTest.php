<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: isset()/empty() string float dim — Implicit conversion Deprecated (#29557).
 *
 * Dedicated provider — full VMTest discovery is heavy, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class IssetEmptyStringOffsetFloatVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'isset_empty_string_offset_float.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/isset_empty_string_offset_float.phpt',
            'isset_empty_string_offset_float.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }
}
