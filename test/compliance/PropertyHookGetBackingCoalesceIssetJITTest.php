<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: get-hook ?? / isset / empty on uninitialized same-name backing (#29688).
 */
require_once __DIR__.'/../BaseTest.php';

final class PropertyHookGetBackingCoalesceIssetJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'property_hook_get_backing_coalesce_isset.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/property_hook_get_backing_coalesce_isset.phpt',
            'property_hook_get_backing_coalesce_isset.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }
}
