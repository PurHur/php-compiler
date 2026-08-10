<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: isset()/empty() string bool/null dim silent; fetch warns (#29558).
 *
 * Dedicated provider — full JITTest discovery is heavy, and path-slash data-set names break --filter.
 *
 * @group llvm
 * @group jit
 */
require_once __DIR__.'/../BaseTest.php';

final class IssetEmptyStringOffsetBoolNullJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'isset_empty_string_offset_bool_null.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/isset_empty_string_offset_bool_null.phpt',
            'isset_empty_string_offset_bool_null.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }
}
