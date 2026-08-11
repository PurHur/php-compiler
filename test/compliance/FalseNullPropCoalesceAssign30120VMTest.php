<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: $false|$null->prop ??= assign Error only, no read Warning (#30120).
 */
require_once __DIR__.'/../BaseTest.php';

final class FalseNullPropCoalesceAssign30120VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'false_null_prop_coalesce_assign.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/false_null_prop_coalesce_assign.phpt',
            'false_null_prop_coalesce_assign.phpt'
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
