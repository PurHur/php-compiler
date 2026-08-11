<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: unset dim on null/false — Zend ZEND_UNSET_DIM (#30099).
 */
require_once __DIR__.'/../BaseTest.php';

final class UnsetDimNullFalse30099VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'unset_dim_null_false_30099.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/unset_dim_null_false_30099.phpt',
            'unset_dim_null_false_30099.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
