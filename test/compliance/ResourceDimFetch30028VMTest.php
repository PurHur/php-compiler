<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: resource as array subject — Warning/null read, scalar Error write, soft isset/empty (#30028).
 */
require_once __DIR__.'/../BaseTest.php';

final class ResourceDimFetch30028VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'resource_dim_fetch.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/resource_dim_fetch.phpt',
            'resource_dim_fetch.phpt'
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
