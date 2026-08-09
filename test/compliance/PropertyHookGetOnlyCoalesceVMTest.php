<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: virtual get-only / hooked property ?? invokes get (#29266).
 *
 * Dedicated provider — full VMTest discovery is heavy, and path-slash data-set names break --filter.
 */
require_once __DIR__.'/../BaseTest.php';

final class PropertyHookGetOnlyCoalesceVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'property_hook_get_only_coalesce.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/property_hook_get_only_coalesce.phpt',
            'property_hook_get_only_coalesce.phpt'
        );
        yield 'property_hook_coalesce.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/property_hook_coalesce.phpt',
            'property_hook_coalesce.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }
}
